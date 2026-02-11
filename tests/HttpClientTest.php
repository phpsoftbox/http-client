<?php

declare(strict_types=1);

namespace PhpSoftBox\Http\Client\Tests;

use PhpSoftBox\Http\Client\Exception\NetworkException;
use PhpSoftBox\Http\Client\HttpClient;
use PhpSoftBox\Http\Message\RequestFactory;
use PhpSoftBox\Http\Message\ResponseFactory;
use PhpSoftBox\Http\Message\StreamFactory;
use PHPUnit\Framework\TestCase;

use function array_filter;
use function array_key_exists;
use function array_shift;
use function count;
use function explode;
use function fclose;
use function fread;
use function function_exists;
use function fwrite;
use function is_string;
use function json_decode;
use function json_encode;
use function microtime;
use function pcntl_fork;
use function pcntl_waitpid;
use function preg_match;
use function str_contains;
use function stream_get_meta_data;
use function stream_set_timeout;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_server;
use function strlen;
use function trim;
use function usleep;

use const CURLOPT_CONNECTTIMEOUT_MS;
use const JSON_THROW_ON_ERROR;

final class HttpClientTest extends TestCase
{
    public function testSendRequestReturnsResponse(): void
    {
        $server = $this->startServer();
        try {
            $client = new HttpClient(
                new ResponseFactory(),
                new StreamFactory(),
                [],
                new RequestFactory(),
            );

            $response = $client->post($server['url'], 'payload', [
                'Content-Type' => 'text/plain',
                'X-Trace'      => 'test-1',
            ]);

            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame('ok', $response->getHeaderLine('X-Test'));

            $payload = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
            $this->assertSame('POST', $payload['method']);
            $this->assertSame('/?ping=1', $payload['uri']);
            $this->assertSame('payload', $payload['body']);
            $this->assertTrue(array_key_exists('X-Trace', $payload['headers']));
        } finally {
            $this->stopServer($server);
        }
    }

    public function testSendRequestThrowsNetworkException(): void
    {
        $client = new HttpClient(new ResponseFactory(), new StreamFactory(), [
            CURLOPT_CONNECTTIMEOUT_MS => 200,
        ], new RequestFactory());

        $this->expectException(NetworkException::class);
        $client->get('http://127.0.0.1:1/');
    }

    /**
     * @return array{pid: int, url: string}
     */
    private function startServer(): array
    {
        if (!function_exists('pcntl_fork')) {
            $this->markTestSkipped('pcntl extension is required for HttpClient integration test.');
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            $this->fail('Failed to bind test server socket: ' . $errstr);
        }

        $name = stream_socket_get_name($socket, false);

        if (!is_string($name) || !preg_match('/:(\d+)$/', $name, $matches)) {
            $this->fail('Failed to detect server port.');
        }

        $port = (int) $matches[1];

        $pid = pcntl_fork();
        if ($pid === -1) {
            fclose($socket);
            $this->fail('Failed to fork test server.');
        }

        if ($pid === 0) {
            $this->serveOnce($socket);
            exit(0);
        }

        fclose($socket);

        usleep(100000);

        return [
            'pid' => $pid,
            'url' => 'http://127.0.0.1:' . $port . '/?ping=1',
        ];
    }

    /**
     * @param array{pid: int, url: string} $server
     */
    private function stopServer(array $server): void
    {
        if (function_exists('pcntl_waitpid')) {
            pcntl_waitpid($server['pid'], $status);
        }
    }

    private function serveOnce($socket): void
    {
        $conn = @stream_socket_accept($socket, 5);
        if ($conn === false) {
            fclose($socket);

            return;
        }

        stream_set_timeout($conn, 5);
        $raw = $this->readRequest($conn);

        $payload = json_encode([
            'method'  => $raw['method'],
            'uri'     => $raw['uri'],
            'body'    => $raw['body'],
            'headers' => $raw['headers'],
        ], JSON_THROW_ON_ERROR);

        $response = "HTTP/1.1 200 OK\r\n";
        $response .= "Content-Type: application/json\r\n";
        $response .= "X-Test: ok\r\n";
        $response .= 'Content-Length: ' . strlen($payload) . "\r\n";
        $response .= "Connection: close\r\n\r\n";
        $response .= $payload;

        fwrite($conn, $response);
        fclose($conn);
        fclose($socket);
    }

    /**
     * @return array{method: string, uri: string, headers: array<string, string[]>, body: string}
     */
    private function readRequest($conn): array
    {
        $buffer   = '';
        $deadline = microtime(true) + 2.0;

        while (!str_contains($buffer, "\r\n\r\n") && microtime(true) < $deadline) {
            $chunk = fread($conn, 1024);
            if ($chunk === '' || $chunk === false) {
                $meta = stream_get_meta_data($conn);
                if (!empty($meta['timed_out'])) {
                    break;
                }
                usleep(10000);
                continue;
            }
            $buffer .= $chunk;
            if (strlen($buffer) > 16384) {
                break;
            }
        }

        $parts       = explode("\r\n\r\n", $buffer, 2);
        $headerBlock = $parts[0] ?? '';
        $body        = $parts[1] ?? '';

        $lines       = array_filter(explode("\r\n", $headerBlock), static fn (string $line): bool => $line !== '');
        $requestLine = array_shift($lines);
        $method      = '';
        $uri         = '';
        if (is_string($requestLine)) {
            $requestParts = explode(' ', $requestLine, 3);
            $method       = $requestParts[0] ?? '';
            $uri          = $requestParts[1] ?? '';
        }

        $headers = [];
        foreach ($lines as $line) {
            $pair = explode(':', $line, 2);
            if (count($pair) !== 2) {
                continue;
            }
            $name  = trim($pair[0]);
            $value = trim($pair[1]);
            if ($name === '') {
                continue;
            }
            $headers[$name][] = $value;
        }

        $contentLength = isset($headers['Content-Length'][0]) ? (int) $headers['Content-Length'][0] : 0;
        $remaining     = $contentLength - strlen($body);
        while ($remaining > 0) {
            $chunk = fread($conn, $remaining);
            if ($chunk === '' || $chunk === false) {
                break;
            }
            $body .= $chunk;
            $remaining -= strlen($chunk);
        }

        return [
            'method'  => $method,
            'uri'     => $uri,
            'headers' => $headers,
            'body'    => $body,
        ];
    }
}
