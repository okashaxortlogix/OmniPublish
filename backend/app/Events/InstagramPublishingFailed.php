<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InstagramPublishingFailed
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public string $publishJobId,
        public string $workspaceId,
        public string $failureCategory,
        public string $userFacingErrorMessage,
        public bool $isRetryable = false
    ) {}
}
