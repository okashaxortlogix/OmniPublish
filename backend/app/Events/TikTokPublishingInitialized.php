<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TikTokPublishingInitialized
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $publishJobId,
        public readonly string $workspaceId,
        public readonly string $publishId,
        public readonly string $uploadUrl
    ) {}
}
