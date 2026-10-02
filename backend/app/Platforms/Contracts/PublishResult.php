<?php

namespace App\Platforms\Contracts;

class PublishResult
{
    public function __construct(
        public readonly string $remoteId,
        public readonly ?string $remoteUrl = null,
        public readonly bool $requiresPolling = false,
        public readonly ?string $statusDetails = null
    ) {}
}
