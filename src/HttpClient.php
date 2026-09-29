<?php

declare(strict_types=1);

namespace PhpSoftBox\Http\Client;

use InvalidArgumentException;
use PhpSoftBox\Http\Client\Exception\HttpClientException;
use PhpSoftBox\Http\Client\Exception\NetworkException;
use PhpSoftBox\Http\Client\Exception\RequestException;
use PhpSoftBox\Http\Message\RequestFactory;
use PhpSoftBox\Http\Message\ResponseFactory;
use PhpSoftBox\Http\Message\StreamFactory;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

use function array_filter;
use function array_key_exists;
use function array_shift;
use function array_values;
use function count;
use function curl_close;
use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function explode;
use function in_array;
use function is_array;
use function is_int;
use function is_string;
use function preg_match;
use function preg_split;
use function sprintf;
use function strtoupper;
use function substr;
use function trim;

use const CURLE_UNSUPPORTED_PROTOCOL;
use const CURLE_URL_MALFORMAT;
use const CURLINFO_HEADER_SIZE;
use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_CONNECTTIMEOUT;
use const CURLOPT_CONNECTTIMEOUT_MS;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_HEADER;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_NOBODY;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_PROTOCOLS;
use const CURLOPT_PROTOCOLS_STR;
use const CURLOPT_REDIR_PROTOCOLS;
use const CURLOPT_REDIR_PROTOCOLS_STR;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_SSL_VERIFYHOST;
use const CURLOPT_SSL_VERIFYPEER;
use const CURLOPT_TIMEOUT;
use const CURLOPT_TIMEOUT_MS;
use const CURLOPT_URL;
use const CURLPROTO_HTTP;
use const CURLPROTO_HTTPS;
use const PHP_VERSION_ID;

final readonly class HttpClient implements ClientInterface
{
    /**
     * Таймаут установки соединения по умолчанию, мс. Переопределяется `CURLOPT_CONNECTTIMEOUT(_MS)` в options.
     */
    public const int DEFAULT_CONNECT_TIMEOUT_MS = 10_000;

    /**
     * Таймаут всего запроса по умолчанию, мс. Переопределяется `CURLOPT_TIMEOUT(_MS)` в options.
     */
    public const int DEFAULT_TIMEOUT_MS = 30_000;

    /**
     * Опции, которые клиент выставляет сам: без них он не разберёт ответ или потеряет ограничение протоколов.
     */
    private const array PROTECTED_OPTIONS = [
        CURLOPT_URL,
        CURLOPT_CUSTOMREQUEST,
        CURLOPT_NOBODY,
        CURLOPT_RETURNTRANSFER,
        CURLOPT_HEADER,
        CURLOPT_HTTPHEADER,
        CURLOPT_POSTFIELDS,
        CURLOPT_PROTOCOLS,
        CURLOPT_PROTOCOLS_STR,
        CURLOPT_REDIR_PROTOCOLS,
        CURLOPT_REDIR_PROTOCOLS_STR,
    ];

    private RequestFactoryInterface $requestFactory;

    /**
     * @param array<int, mixed> $options Дополнительные `CURLOPT_*`. Опции из PROTECTED_OPTIONS запрещены.
     *
     * @throws InvalidArgumentException Если options содержат опцию, которую клиент выставляет сам.
     */
    public function __construct(
        private ResponseFactoryInterface $responseFactory = new ResponseFactory(),
        private StreamFactoryInterface $streamFactory = new StreamFactory(),
        private array $options = [],
        ?RequestFactoryInterface $requestFactory = null,
    ) {
        foreach ($options as $key => $value) {
            if (is_int($key) && in_array($key, self::PROTECTED_OPTIONS, true)) {
                throw new InvalidArgumentException(sprintf(
                    'cURL option %d is managed by HttpClient and cannot be overridden.',
                    $key,
                ));
            }
        }

        $this->requestFactory = $requestFactory ?? new RequestFactory();
    }

    public function get(string $url, array $headers = []): ResponseInterface
    {
        return $this->request('GET', $url, '', $headers);
    }

    public function post(string $url, string $body = '', array $headers = []): ResponseInterface
    {
        return $this->request('POST', $url, $body, $headers);
    }

    public function put(string $url, string $body = '', array $headers = []): ResponseInterface
    {
        return $this->request('PUT', $url, $body, $headers);
    }

    public function patch(string $url, string $body = '', array $headers = []): ResponseInterface
    {
        return $this->request('PATCH', $url, $body, $headers);
    }

    public function delete(string $url, string $body = '', array $headers = []): ResponseInterface
    {
        return $this->request('DELETE', $url, $body, $headers);
    }

    public function head(string $url, array $headers = []): ResponseInterface
    {
        return $this->request('HEAD', $url, '', $headers);
    }

    public function options(string $url, string $body = '', array $headers = []): ResponseInterface
    {
        return $this->request('OPTIONS', $url, $body, $headers);
    }

    public function withoutSslVerification(): self
    {
        return $this->withOptions([
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
        ]);
    }

    public function request(string $method, string $url, string $body = '', array $headers = []): ResponseInterface
    {
        $request = $this->requestFactory->createRequest($method, $url);

        foreach ($headers as $name => $value) {
            if (!is_string($name) || $name === '') {
                continue;
            }

            if (is_array($value)) {
                $request = $request->withHeader($name, $value);
                continue;
            }

            $request = $request->withHeader($name, (string) $value);
        }

        if ($body !== '') {
            $request = $request->withBody($this->streamFactory->createStream($body));
        }

        return $this->sendRequest($request);
    }

    public function sendRequest(RequestInterface $request): ResponseInterface
    {
        $handle = curl_init();
        if ($handle === false) {
            throw new HttpClientException('Failed to initialize HTTP client.');
        }

        $headers = $this->formatHeaders($request);
        $body    = (string) $request->getBody();

        $options = [
            CURLOPT_FOLLOWLOCATION    => false,
            CURLOPT_CONNECTTIMEOUT_MS => self::DEFAULT_CONNECT_TIMEOUT_MS,
            CURLOPT_TIMEOUT_MS        => self::DEFAULT_TIMEOUT_MS,
        ];

        foreach ($this->options as $key => $value) {
            if (is_int($key)) {
                $options[$key] = $value;
            }
        }

        // Секундный и миллисекундный таймауты в cURL — одна настройка: при значении в секундах из options дефолт убираем.
        if (array_key_exists(CURLOPT_CONNECTTIMEOUT, $this->options) && !array_key_exists(CURLOPT_CONNECTTIMEOUT_MS, $this->options)) {
            unset($options[CURLOPT_CONNECTTIMEOUT_MS]);
        }

        if (array_key_exists(CURLOPT_TIMEOUT, $this->options) && !array_key_exists(CURLOPT_TIMEOUT_MS, $this->options)) {
            unset($options[CURLOPT_TIMEOUT_MS]);
        }

        $options[CURLOPT_URL]             = (string) $request->getUri();
        $options[CURLOPT_RETURNTRANSFER]  = true;
        $options[CURLOPT_HEADER]          = true;
        $options[CURLOPT_HTTPHEADER]      = $headers;
        $options[CURLOPT_PROTOCOLS]       = CURLPROTO_HTTP | CURLPROTO_HTTPS;
        $options[CURLOPT_REDIR_PROTOCOLS] = CURLPROTO_HTTP | CURLPROTO_HTTPS;

        if (strtoupper($request->getMethod()) === 'HEAD') {
            // Без NOBODY cURL ждёт тело длиной Content-Length, которого в ответе на HEAD нет.
            $options[CURLOPT_NOBODY] = true;
        } else {
            $options[CURLOPT_CUSTOMREQUEST] = $request->getMethod();
        }

        if ($body !== '') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        curl_setopt_array($handle, $options);

        $raw = curl_exec($handle);
        if (!is_string($raw)) {
            $message = curl_error($handle);
            $code    = curl_errno($handle);
            $this->closeHandle($handle);

            $message = $message !== '' ? $message : 'HTTP request failed.';

            // Неподдерживаемый протокол и некорректный URL — ошибка самого запроса, а не сети.
            if ($code === CURLE_UNSUPPORTED_PROTOCOL || $code === CURLE_URL_MALFORMAT) {
                throw new RequestException($message, $request, $code);
            }

            throw new NetworkException($message, $request, $code);
        }

        $status     = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $this->closeHandle($handle);

        $headerRaw = substr($raw, 0, $headerSize);
        $bodyRaw   = substr($raw, $headerSize);

        [$statusLine, $headerLines]       = $this->lastHeaderBlock($headerRaw);
        [$protocolVersion, $reasonPhrase] = $this->parseStatusLine($statusLine);

        $response = $this->responseFactory->createResponse($status, $reasonPhrase);
        if ($protocolVersion !== null) {
            $response = $response->withProtocolVersion($protocolVersion);
        }

        foreach ($this->parseHeaders($headerLines) as $name => $values) {
            foreach ($values as $value) {
                $response = $response->withAddedHeader($name, $value);
            }
        }

        return $response->withBody($this->streamFactory->createStream($bodyRaw));
    }

    /**
     * @throws RequestException Если имя или значение заголовка содержит перевод строки или NUL.
     *
     * @return string[]
     */
    private function formatHeaders(RequestInterface $request): array
    {
        $result = [];

        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $line = $name . ': ' . $value;

                // Запрос другой реализации PSR-7 может не проверять заголовки: CR/LF внедрили бы свой заголовок.
                if (preg_match('/[\r\n\0]/', $line) === 1) {
                    throw new RequestException(sprintf('Header "%s" contains a line break or NUL byte.', $name), $request);
                }

                $result[] = $line;
            }
        }

        return $result;
    }

    /**
     * Возвращает status line и строки заголовков последнего блока: перед ним могут быть `100 Continue`, ответ прокси
     * на CONNECT или промежуточные redirect.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function lastHeaderBlock(string $raw): array
    {
        $blocks = preg_split('/\r\n\r\n/', trim($raw));
        $block  = $blocks !== false && $blocks !== [] ? $blocks[count($blocks) - 1] : '';

        $lines = array_values(array_filter(explode("\r\n", $block), static fn (string $line): bool => $line !== ''));
        if ($lines === []) {
            return ['', []];
        }

        $statusLine = (string) array_shift($lines);

        return [$statusLine, $lines];
    }

    /**
     * @return array{0: string|null, 1: string} Версия протокола (null, если строка не разобрана) и reason phrase.
     */
    private function parseStatusLine(string $statusLine): array
    {
        if (preg_match('#^HTTP/(\d(?:\.\d)?)\s+\d{3}(?:\s+(.*))?$#', $statusLine, $matches) !== 1) {
            return [null, ''];
        }

        return [$matches[1], trim($matches[2] ?? '')];
    }

    /**
     * @param list<string> $lines
     * @return array<string, string[]>
     */
    private function parseHeaders(array $lines): array
    {
        $headers = [];
        foreach ($lines as $line) {
            $parts = explode(':', $line, 2);
            if (count($parts) !== 2) {
                continue;
            }

            $name  = trim($parts[0]);
            $value = trim($parts[1]);
            if ($name === '') {
                continue;
            }

            $headers[$name][] = $value;
        }

        return $headers;
    }

    private function closeHandle(mixed $handle): void
    {
        if (PHP_VERSION_ID < 80500) {
            curl_close($handle);
        }
    }

    /**
     * @param array<int, mixed> $options
     */
    private function withOptions(array $options): self
    {
        $merged = $this->options;
        foreach ($options as $key => $value) {
            if (is_int($key)) {
                $merged[$key] = $value;
            }
        }

        return new self($this->responseFactory, $this->streamFactory, $merged, $this->requestFactory);
    }
}
