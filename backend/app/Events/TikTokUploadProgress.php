<?php

namespace App\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TikTokUploadProgress
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly string $publishJobId,
        public readonly string $workspaceId,
        public readonly int $bytesUploaded,
        public readonly int $totalBytes,
        public readonly float $percentage
    ) {}
}
