<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TikTokPublishingCompleted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $publishJobId,
        public readonly string $workspaceId,
        public readonly string $remotePostId,
        public readonly ?string $remotePostUrl = null
    ) {}
}
