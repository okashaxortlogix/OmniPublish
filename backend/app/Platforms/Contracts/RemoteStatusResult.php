<?php

namespace App\Platforms\Contracts;

class RemoteStatusResult
{
    public function __construct(
        public readonly string $status, // PENDING, PROCESSING, SUCCESS, FAILED
        public readonly ?string $remoteUrl = null,
        public readonly ?string $errorMessage = null,
        public readonly ?string $errorDetails = null
    ) {}
}
