<?php

namespace App\Platforms\Exceptions;

use RuntimeException;
use Throwable;

class InstagramPublishException extends RuntimeException
{
    public string $failureCategory;
    public bool $isRetryable;
    public int $httpStatus;
    public ?string $providerErrorCode;

    public function __construct(
        string $message,
        string $failureCategory = 'PROVIDER_ERROR',
        bool $isRetryable = false,
        int $httpStatus = 400,
        ?string $providerErrorCode = null,
        ?Throwable $previous = null
    ) {
        parent::__construct($message, $httpStatus, $previous);
        $this->failureCategory = $failureCategory;
        $this->isRetryable = $isRetryable;
        $this->httpStatus = $httpStatus;
        $this->providerErrorCode = $providerErrorCode;
    }
}
