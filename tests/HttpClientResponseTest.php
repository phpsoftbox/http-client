<?php

declare(strict_types=1);

namespace PhpSoftBox\Http\Client\Tests;

use PhpSoftBox\Http\Client\HttpClient;
use PhpSoftBox\Http\Client\Tests\Support\RawHttpServer;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\CoversMethod;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;

use function microtime;

use const CURLOPT_TIMEOUT_MS;

#[CoversClass(HttpClient::class)]
#[CoversMethod(HttpClient::class, 'sendRequest')]
#[CoversMethod(HttpClient::class, 'head')]
final class HttpClientResponseTest extends TestCase
{
    /**
     * Проверим, что HEAD не ждёт тело длиной Content-Length: ответ приходит сразу, а не по таймауту.
     *
     * @see HttpClient::head()
     * @see HttpClient::sendRequest()
     */
    #[Test]
    public function headDoesNotWaitForBody(): void
    {
        $server = RawHttpServer::start("HTTP/1.1 200 OK\r\nContent-Length: 100\r\n\r\n", holdSeconds: 2);

        try {
            $startedAt = microtime(true);
            $response  = new HttpClient(options: [CURLOPT_TIMEOUT_MS => 1_500])->head($server->url());
            $elapsed   = microtime(true) - $startedAt;
        } finally {
            $server->stop();
        }

        self::assertSame(200, $response->getStatusCode());
        self::assertLessThan(1.0, $elapsed);
    }

    /**
     * Проверим, что версия протокола из status line ответа переносится в response.
     *
     * @see HttpClient::sendRequest()
     */
    #[Test]
    public function protocolVersionIsPreserved(): void
    {
        $response = $this->fetch("HTTP/1.0 200 OK\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");

        self::assertSame('1.0', $response->getProtocolVersion());
    }

    /**
     * Проверим, что нестандартная reason phrase из status line ответа сохраняется.
     *
     * @see HttpClient::sendRequest()
     */
    #[Test]
    public function reasonPhraseIsPreserved(): void
    {
        $response = $this->fetch("HTTP/1.1 200 All Good\r\nContent-Length: 2\r\nConnection: close\r\n\r\nok");

        self::assertSame('All Good', $response->getReasonPhrase());
    }

    private function fetch(string $rawResponse): ResponseInterface
    {
        $server = RawHttpServer::start($rawResponse);

        try {
            return new HttpClient()->get($server->url());
        } finally {
            $server->stop();
        }
    }
}
