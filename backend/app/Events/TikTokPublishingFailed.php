<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TikTokPublishingFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $publishJobId,
        public readonly string $workspaceId,
        public readonly string $failureCategory,
        public readonly string $errorMessage,
        public readonly bool $isRetryable
    ) {}
}
