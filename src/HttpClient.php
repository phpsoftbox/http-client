<?php

declare(strict_types=1);

namespace PhpSoftBox\Http\Client;

use PhpSoftBox\Http\Client\Exception\HttpClientException;
use PhpSoftBox\Http\Client\Exception\NetworkException;
use Psr\Http\Client\ClientInterface;
use Psr\Http\Message\RequestFactoryInterface;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamFactoryInterface;

use function array_filter;
use function array_shift;
use function count;
use function curl_close;
use function curl_errno;
use function curl_error;
use function curl_exec;
use function curl_getinfo;
use function curl_init;
use function curl_setopt_array;
use function explode;
use function is_array;
use function is_int;
use function is_string;
use function preg_split;
use function substr;
use function trim;

use const CURLINFO_HEADER_SIZE;
use const CURLINFO_RESPONSE_CODE;
use const CURLOPT_CUSTOMREQUEST;
use const CURLOPT_FOLLOWLOCATION;
use const CURLOPT_HEADER;
use const CURLOPT_HTTPHEADER;
use const CURLOPT_POSTFIELDS;
use const CURLOPT_RETURNTRANSFER;
use const CURLOPT_SSL_VERIFYHOST;
use const CURLOPT_SSL_VERIFYPEER;
use const CURLOPT_URL;
use const PHP_VERSION_ID;

final readonly class HttpClient implements ClientInterface
{
    /**
     * @param array<int, mixed> $options
     */
    public function __construct(
        private ResponseFactoryInterface $responseFactory,
        private StreamFactoryInterface $streamFactory,
        private array $options = [],
        private ?RequestFactoryInterface $requestFactory = null,
    ) {
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
        if ($this->requestFactory === null) {
            throw new HttpClientException('RequestFactoryInterface is required for shortcut methods.');
        }

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
            CURLOPT_URL            => (string) $request->getUri(),
            CURLOPT_CUSTOMREQUEST  => $request->getMethod(),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER         => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_FOLLOWLOCATION => false,
        ];

        if ($body !== '') {
            $options[CURLOPT_POSTFIELDS] = $body;
        }

        foreach ($this->options as $key => $value) {
            if (is_int($key)) {
                $options[$key] = $value;
            }
        }

        curl_setopt_array($handle, $options);

        $raw = curl_exec($handle);
        if ($raw === false) {
            $message = curl_error($handle);
            $code    = curl_errno($handle);
            $this->closeHandle($handle);

            throw new NetworkException(
                $message !== '' ? $message : 'HTTP request failed.',
                $request,
                $code,
            );
        }

        $status     = (int) curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($handle, CURLINFO_HEADER_SIZE);
        $this->closeHandle($handle);

        $headerRaw = substr($raw, 0, $headerSize);
        $bodyRaw   = (string) substr($raw, $headerSize);

        $response = $this->responseFactory->createResponse($status);
        foreach ($this->parseHeaders($headerRaw) as $name => $values) {
            foreach ($values as $value) {
                $response = $response->withAddedHeader($name, $value);
            }
        }

        return $response->withBody($this->streamFactory->createStream($bodyRaw));
    }

    /**
     * @return string[]
     */
    private function formatHeaders(RequestInterface $request): array
    {
        $result = [];

        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $result[] = $name . ': ' . $value;
            }
        }

        return $result;
    }

    /**
     * @return array<string, string[]>
     */
    private function parseHeaders(string $raw): array
    {
        $blocks = preg_split('/\\r\\n\\r\\n/', trim($raw));
        $block  = $blocks !== false && $blocks !== [] ? $blocks[count($blocks) - 1] : '';

        $lines = array_filter(explode("\r\n", $block), static fn (string $line): bool => $line !== '');
        if ($lines === []) {
            return [];
        }

        array_shift($lines); // status line

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
