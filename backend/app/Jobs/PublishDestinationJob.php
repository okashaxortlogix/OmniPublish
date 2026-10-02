<?php

namespace App\Jobs;

use App\Models\PublishJob;
use App\Models\PublishAttempt;
use App\Models\PublishBatch;
use App\Platforms\Contracts\PublishContext;
use App\Platforms\Services\PlatformManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublishDestinationJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        public readonly string $publishJobId
    ) {}

    public function handle(PlatformManager $platformManager): void
    {
        $job = PublishJob::with(['publishBatch.contentItem.mediaAsset', 'socialAccount', 'workspace'])->find($this->publishJobId);

        if (!$job) {
            Log::error("PublishJob not found: {$this->publishJobId}");
            return;
        }

        // Idempotency: Distributed lock per job
        $lockKey = "publish_lock_job_{$job->id}";
        $lock = Cache::lock($lockKey, 120);

        if (!$lock->get()) {
            Log::warning("PublishJob {$job->id} is already in progress by another worker. Skipping duplicate.");
            return;
        }

        try {
            // Check current status
            if ($job->status === 'PUBLISHED') {
                Log::info("PublishJob {$job->id} already published with remote ID {$job->remote_post_id}. Skipping.");
                $lock->release();
                return;
            }

            // Record attempt start
            $attemptNumber = $job->attempts_count + 1;
            $job->update([
                'status' => 'UPLOADING',
                'attempts_count' => $attemptNumber,
                'last_attempt_at' => now(),
            ]);

            $attempt = PublishAttempt::create([
                'publish_job_id' => $job->id,
                'attempt_number' => $attemptNumber,
                'status' => 'RUNNING',
                'started_at' => now(),
            ]);

            $account = $job->socialAccount;
            $batch = $job->publishBatch;
            $content = $batch->contentItem;
            $media = $content->mediaAsset;

            $adapter = $platformManager->get($job->platform);

            // Context
            $context = new PublishContext(
                job: $job,
                account: $account,
                media: $media,
                globalMetadata: $content->global_metadata ?? [],
                platformOverrides: $content->platform_overrides ?? [],
                decryptedAccessToken: $account->encrypted_access_token ?? 'mock_token'
            );

            // Preflight validate
            $validationIssues = $adapter->validate(
                $content->global_metadata ?? [],
                $content->platform_overrides ?? [],
                $media ? $media->toArray() : null
            );

            $hasBlockingError = false;
            foreach ($validationIssues as $issue) {
                if ($issue['severity'] === 'ERROR') {
                    $hasBlockingError = true;
                    throw new \InvalidArgumentException($issue['message']);
                }
            }

            // Execute publish
            $job->update(['status' => 'PROCESSING']);
            $result = $adapter->publish($context);

            // Persist remote ID immediately upon return
            $job->update([
                'status' => 'PUBLISHED',
                'remote_post_id' => $result->remoteId,
                'remote_post_url' => $result->remoteUrl,
                'failure_category' => null,
                'user_facing_error_message' => null,
                'internal_error_details' => null,
            ]);

            $attempt->update([
                'status' => 'SUCCESS',
                'finished_at' => now(),
            ]);

            Log::info("PublishJob {$job->id} successfully published to {$job->platform}: {$result->remoteId}");

        } catch (Throwable $e) {
            Log::error("PublishJob {$job->id} failed: " . $e->getMessage(), [
                'exception' => $e,
            ]);

            $adapter = $platformManager->get($job->platform);
            $classified = $adapter->classifyError($e);

            $job->update([
                'status' => $classified['category'] === 'AUTH_REQUIRED' ? 'AUTH_REQUIRED' : 'FAILED',
                'failure_category' => $classified['category'],
                'user_facing_error_message' => $classified['user_message'],
                'internal_error_details' => $e->getMessage(),
            ]);

            if (isset($attempt)) {
                $attempt->update([
                    'status' => 'FAILED',
                    'error_category' => $classified['category'],
                    'error_message' => $classified['user_message'],
                    'finished_at' => now(),
                ]);
            }

            // Check if transient error and under max attempts
            if ($classified['retryable'] && $job->attempts_count < $job->max_attempts) {
                $job->update(['status' => 'RETRYING']);
                $delaySeconds = (int) (pow(2, $job->attempts_count) * 5 + rand(1, 5));
                PublishDestinationJob::dispatch($job->id)->delay(now()->addSeconds($delaySeconds));
            }

        } finally {
            $lock->release();
            $this->updateBatchStatus($job->publish_batch_id);
        }
    }

    protected function updateBatchStatus(string $batchId): void
    {
        $batch = PublishBatch::with('publishJobs')->find($batchId);
        if (!$batch) return;

        $jobs = $batch->publishJobs;
        $total = $jobs->count();
        $published = $jobs->where('status', 'PUBLISHED')->count();
        $failed = $jobs->whereIn('status', ['FAILED', 'AUTH_REQUIRED'])->count();
        $running = $jobs->whereIn('status', ['UPLOADING', 'PROCESSING', 'RETRYING', 'QUEUED'])->count();

        if ($running > 0) {
            $batch->update(['status' => 'RUNNING']);
        } elseif ($published === $total) {
            $batch->update(['status' => 'COMPLETED']);
        } elseif ($published > 0 && $failed > 0) {
            $batch->update(['status' => 'PARTIAL_SUCCESS']);
        } elseif ($failed === $total) {
            $batch->update(['status' => 'FAILED']);
        }
    }
}
