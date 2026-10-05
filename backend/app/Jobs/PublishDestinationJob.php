<?php

namespace App\Jobs;

use App\Platforms\Services\PlatformManager;
use App\Services\PublishingEngine;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class PublishDestinationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;
    public int $timeout = 600;

    public function __construct(
        public readonly string $publishJobId
    ) {
        $this->onQueue('publishing');
    }

    public function handle(PlatformManager $platformManager, ?PublishingEngine $publishingEngine = null): void
    {
        $engine = $publishingEngine ?? app(PublishingEngine::class);
        $engine->execute($this->publishJobId, $platformManager);
    }
}
