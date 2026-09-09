<?php

declare(strict_types=1);

namespace ContextEngine;

use RuntimeException;
use Throwable;

/** Machine-readable failure; still compatible with existing RuntimeException catches. */
class ContextEngineException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $errorCode,
        public readonly ?int $statusCode = null,
        public readonly ?string $responseBody = null,
        public readonly array $responseHeaders = [],
        public readonly ?int $curlErrorCode = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $statusCode ?? 0, $previous);
    }
}
