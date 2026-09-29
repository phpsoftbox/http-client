<?php

declare(strict_types=1);

namespace PhpSoftBox\Http\Client\Tests;

use InvalidArgumentException;
use PhpSoftBox\Http\Client\Exception\RequestException;
use PhpSoftBox\Http\Client\HttpClient;
use PhpSoftBox\Http\Client\Tests\Support\RawHttpServer;
use PhpSoftBox\Http\Message\ResponseFactory;
use PhpSoftBox\Http\Message\StreamFactory;
use PhpSoftBox\Http\Message\Uri;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

use const CURLOPT_CONNECTTIMEOUT_MS;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_TIMEOUT_MS;

#[CoversClass(HttpClient::class)]
#[CoversMethod(HttpClient::class, '__construct')]
#[CoversMethod(HttpClient::class, 'post')]
#[CoversMethod(HttpClient::class, 'get')]
#[CoversMethod(HttpClient::class, 'sendRequest')]
final class HttpClientDefaultsTest extends TestCase
{
    /**
     * Проверим пример из README: клиент без RequestFactory выполняет post(), фабрика запроса создаётся по умолчанию.
     *
     * @see HttpClient::__construct()
     * @see HttpClient::post()
     */
    #[Test]
    public function readmeExampleWorksWithoutRequestFactory(): void
    {
        $server = RawHttpServer::start("HTTP/1.1 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");

        try {
            $client = new HttpClient(
                new ResponseFactory(),
                new StreamFactory(),
                [
                    CURLOPT_CONNECTTIMEOUT_MS => 1000,
                    CURLOPT_TIMEOUT_MS        => 5000,
                ],
            );

            $response = $client->post($server->url('/v1/ping'), '{"ping":true}', ['Content-Type' => 'application/json']);
        } finally {
            $server->stop();
        }

        self::assertSame('ok', (string) $response->getBody());
    }

    /**
     * Проверим, что опцию, без которой клиент не разберёт ответ, нельзя переопределить через options.
     *
     * @see HttpClient::__construct()
     */
    #[Test]
    public function protectedOptionIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new HttpClient(options: [CURLOPT_RETURNTRANSFER => false]);
    }

    /**
     * Проверим, что URL со схемой file:// не выполняется: разрешены только http и https.
     *
     * @see HttpClient::get()
     */
    #[Test]
    public function fileSchemeIsRejected(): void
    {
        $this->expectException(RequestException::class);

        new HttpClient()->get('file:///etc/passwd');
    }

    /**
     * Проверим, что redirect на file:// не выполняется даже при включённом CURLOPT_FOLLOWLOCATION.
     *
     * @see HttpClient::get()
     */
    #[Test]
    public function redirectToFileSchemeIsNotFollowed(): void
    {
        $server = RawHttpServer::start(
            "HTTP/1.1 302 Found\r\nLocation: file:///etc/passwd\r\nContent-Length: 0\r\nConnection: close\r\n\r\n",
        );

        $this->expectException(RequestException::class);

        try {
            new HttpClient(options: [CURLOPT_FOLLOWLOCATION => true])->get($server->url());
        } finally {
            $server->stop();
        }
    }

    /**
     * Проверим, что заголовок с переводом строки из сторонней реализации PSR-7 не уходит в cURL.
     *
     * @see HttpClient::sendRequest()
     */
    #[Test]
    public function headerWithLineBreakIsRejected(): void
    {
        $request = $this->createStub(RequestInterface::class);
        $request->method('getUri')->willReturn(new Uri('http://127.0.0.1:1/'));
        $request->method('getMethod')->willReturn('GET');
        $request->method('getHeaders')->willReturn(['X-A' => ["a\r\nX-Injected: 1"]]);

        $this->expectException(RequestException::class);

        new HttpClient()->sendRequest($request);
    }
}
