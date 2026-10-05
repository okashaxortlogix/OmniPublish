<?php

namespace App\Jobs;

use App\Models\PublishBatch;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class PublishBatchJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(
        public readonly string $publishBatchId
    ) {}

    public function handle(): void
    {
        $batch = PublishBatch::with('publishJobs')->find($this->publishBatchId);

        if (!$batch) {
            Log::error("PublishBatch not found: {$this->publishBatchId}");
            return;
        }

        if ($batch->scheduled_for && $batch->scheduled_for->isFuture()) {
            Log::info("PublishBatch {$this->publishBatchId} is scheduled for future ({$batch->scheduled_for}). Waiting for scheduled time.");
            return;
        }

        $batch->update(['status' => 'RUNNING']);

        foreach ($batch->publishJobs as $job) {
            if ($job->status !== 'PUBLISHED') {
                PublishDestinationJob::dispatch($job->id);
            }
        }
    }
}
