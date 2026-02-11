# Http Client

## About

`phpsoftbox/http-client` — базовая реализация PSR-18 клиента, построенная на cURL.
Компонент принимает PSR-17 фабрики ответа и стрима и возвращает `ResponseInterface`.

Ключевые особенности:

- минимальная обвязка вокруг cURL без внешних зависимостей;
- поддержка любых методов и заголовков;
- возможность передать дополнительные `CURLOPT_*` через конструктор;
- исключения PSR-18 при сетевых ошибках.

## Quick Start

```php
use PhpSoftBox\Http\Client\HttpClient;
use PhpSoftBox\Http\Message\RequestFactory;
use PhpSoftBox\Http\Message\ResponseFactory;
use PhpSoftBox\Http\Message\StreamFactory;

$client = new HttpClient(
    new ResponseFactory(),
    new StreamFactory(),
    [
        CURLOPT_CONNECTTIMEOUT_MS => 1000,
        CURLOPT_TIMEOUT_MS => 5000,
    ],
);

$response = $client->post(
    'https://api.example.com/v1/ping',
    '{"ping":true}',
    ['Content-Type' => 'application/json'],
);

$status = $response->getStatusCode();
$body = (string) $response->getBody();

// Если нужно отключить проверку SSL:
$insecureClient = $client->withoutSslVerification();
```

## Ошибки

- `NetworkException` — сетевые ошибки (PSR-18 `NetworkExceptionInterface`).
- `HttpClientException` — ошибки инициализации клиента.

## Интеграции

Клиент можно напрямую передавать в компоненты, ожидающие PSR-18:

```php
$telegram = new \PhpSoftBox\Telegram\Api\TelegramClient(
    token: $_ENV['TELEGRAM_BOT_TOKEN'],
    httpClient: $client,
    requestFactory: new RequestFactory(),
    streamFactory: new StreamFactory(),
);
```

## Требования

- PHP 8.4+
- расширение `ext-curl`
