# Http Client

## About

`phpsoftbox/http-client` — базовая реализация PSR-18 клиента, построенная на cURL.
Компонент принимает PSR-17 фабрики ответа, стрима и запроса и возвращает `ResponseInterface`.
Все фабрики необязательны: по умолчанию используются фабрики `phpsoftbox/http-message`.

Ключевые особенности:

- минимальная обвязка вокруг cURL;
- поддержка любых методов и заголовков;
- таймауты по умолчанию и ограничение протоколов до `http`/`https`;
- возможность передать дополнительные `CURLOPT_*` через конструктор;
- исключения PSR-18 при сетевых ошибках и некорректных запросах.

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

Без аргументов клиент тоже работоспособен: `new HttpClient()->get('https://example.com')`.

## Таймауты

По умолчанию действуют таймауты `HttpClient::DEFAULT_CONNECT_TIMEOUT_MS` (10 секунд на соединение) и
`HttpClient::DEFAULT_TIMEOUT_MS` (30 секунд на весь запрос), чтобы воркер не зависал на медленном сервере. Их
переопределяют `CURLOPT_CONNECTTIMEOUT(_MS)` и `CURLOPT_TIMEOUT(_MS)` в options; значение `0` снимает ограничение.

## Безопасность

- Разрешены только протоколы `http` и `https`, в том числе при переходе по redirect (`CURLOPT_FOLLOWLOCATION`
  по умолчанию выключен). URL `file://`, `gopher://` и т. п. дают `RequestException`.
- Заголовок с CR, LF или NUL (возможен у запроса другой реализации PSR-7) не отправляется: `RequestException`.
- Опции, которыми управляет клиент, в options запрещены — конструктор бросает `InvalidArgumentException`:
  `CURLOPT_URL`, `CURLOPT_CUSTOMREQUEST`, `CURLOPT_NOBODY`, `CURLOPT_RETURNTRANSFER`, `CURLOPT_HEADER`,
  `CURLOPT_HTTPHEADER`, `CURLOPT_POSTFIELDS`, `CURLOPT_PROTOCOLS(_STR)`, `CURLOPT_REDIR_PROTOCOLS(_STR)`.

## Ответ

- Версия протокола и reason phrase берутся из status line ответа (`HTTP/1.0 200 All Good` → `1.0`, `All Good`).
  При промежуточных ответах (`100 Continue`, redirect) используется последний.
- `HEAD` выполняется без ожидания тела (`CURLOPT_NOBODY`): заголовки, включая `Content-Length`, возвращаются как есть.

## Ошибки

- `NetworkException` — сетевые ошибки и таймауты (PSR-18 `NetworkExceptionInterface`).
- `RequestException` — некорректный запрос: неподдерживаемый протокол, некорректный URL, заголовок с переводом
  строки (PSR-18 `RequestExceptionInterface`).
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

- PHP 8.5+
- расширение `ext-curl`
- `phpsoftbox/http-message` (устанавливается как зависимость)
