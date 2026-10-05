<?php

namespace App\Events;

use App\Models\PublishJob;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class YouTubePublishingStarted
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $publishJobId,
        public readonly string $workspaceId,
        public readonly string $socialAccountId,
        public readonly ?string $mediaAssetId,
        public readonly int $totalBytes
    ) {}
}
