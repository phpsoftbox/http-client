<?php

declare(strict_types=1);

namespace PhpSoftBox\Http\Client\Exception;

use Psr\Http\Client\ClientExceptionInterface;
use RuntimeException;

class HttpClientException extends RuntimeException implements ClientExceptionInterface
{
}
