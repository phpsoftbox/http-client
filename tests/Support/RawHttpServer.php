<?php

declare(strict_types=1);

namespace PhpSoftBox\Http\Client\Tests\Support;

use PHPUnit\Framework\SkippedWithMessageException;
use RuntimeException;

use function fclose;
use function fread;
use function function_exists;
use function fwrite;
use function is_string;
use function microtime;
use function pcntl_fork;
use function pcntl_waitpid;
use function preg_match;
use function sleep;
use function str_contains;
use function stream_set_timeout;
use function stream_socket_accept;
use function stream_socket_get_name;
use function stream_socket_server;

/**
 * Тестовый HTTP-сервер в дочернем процессе: принимает одно соединение и отвечает заданными байтами как есть.
 */
final class RawHttpServer
{
    private function __construct(
        private readonly int $pid,
        private readonly int $port,
    ) {
    }

    /**
     * @param string $response Сырые байты ответа.
     * @param int $holdSeconds Сколько секунд держать соединение открытым после ответа.
     */
    public static function start(string $response, int $holdSeconds = 0): self
    {
        if (!function_exists('pcntl_fork')) {
            throw new SkippedWithMessageException('Для теста с HTTP-сервером нужно расширение pcntl.');
        }

        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if ($socket === false) {
            throw new RuntimeException('Failed to bind test server socket: ' . $errstr);
        }

        $name = stream_socket_get_name($socket, false);
        if (!is_string($name) || preg_match('/:(\d+)$/', $name, $matches) !== 1) {
            throw new RuntimeException('Failed to detect test server port.');
        }

        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Failed to fork test server.');
        }

        if ($pid === 0) {
            self::serveOnce($socket, $response, $holdSeconds);
            exit(0);
        }

        fclose($socket);

        return new self($pid, (int) $matches[1]);
    }

    public function url(string $path = '/'): string
    {
        return 'http://127.0.0.1:' . $this->port . $path;
    }

    public function stop(): void
    {
        pcntl_waitpid($this->pid, $status);
    }

    /**
     * @param resource $socket
     */
    private static function serveOnce($socket, string $response, int $holdSeconds): void
    {
        $connection = @stream_socket_accept($socket, 5);
        if ($connection === false) {
            fclose($socket);

            return;
        }

        stream_set_timeout($connection, 2);

        // Читаем заголовки запроса, чтобы клиент не получил ответ до отправки запроса.
        $buffer   = '';
        $deadline = microtime(true) + 2.0;
        while (!str_contains($buffer, "\r\n\r\n") && microtime(true) < $deadline) {
            $chunk = fread($connection, 1024);
            if ($chunk === false || $chunk === '') {
                break;
            }

            $buffer .= $chunk;
        }

        fwrite($connection, $response);
        if ($holdSeconds > 0) {
            sleep($holdSeconds);
        }

        fclose($connection);
        fclose($socket);
    }
}
