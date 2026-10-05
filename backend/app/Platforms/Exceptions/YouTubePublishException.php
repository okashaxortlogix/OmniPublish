<?php

namespace App\Platforms\Exceptions;

use RuntimeException;

class YouTubePublishException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly string $failureCategory = 'PROVIDER_ERROR',
        public readonly bool $isRetryable = false,
        public readonly ?int $httpStatus = null,
        public readonly ?string $providerErrorCode = null,
        ?\Throwable $previous = null
    ) {
        parent::__construct($message, 0, $previous);
    }
}
