<?php

namespace App\Services;

use App\Jobs\PublishDestinationJob;
use App\Models\PublishAttempt;
use App\Models\PublishBatch;
use App\Models\PublishJob;
use App\Platforms\Contracts\PublishContext;
use App\Platforms\Services\PlatformManager;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

class PublishingEngine
{
    public function __construct(
        protected PlatformManager $platformManager,
        protected PublishingRetryPolicy $retryPolicy,
        protected TokenRefreshService $tokenRefreshService
    ) {}

    /**
     * Core orchestrator method: Executes a single publishing job with full idempotency,
     * distributed locking, state transitions, and error handling.
     */
    public function execute(string $jobId, ?PlatformManager $platformManager = null): void
    {
        $pm = $platformManager ?? $this->platformManager;

        $job = PublishJob::with(['publishBatch.contentItem.mediaAsset', 'socialAccount', 'workspace'])
            ->find($jobId);

        if (!$job) {
            Log::error("[PublishingEngine] PublishJob not found: {$jobId}");
            return;
        }

        $correlationId = $job->correlation_id ?? (string) \Illuminate\Support\Str::uuid();

        // 1. Idempotency: Acquire distributed lock per destination job (660 seconds)
        $lockKey = "publish_lock_job_{$job->id}";
        $lock = Cache::lock($lockKey, 660);

        if (!$lock->get()) {
            Log::warning("[PublishingEngine][{$correlationId}] PublishJob {$job->id} is currently locked by another worker. Skipping duplicate invocation.");
            return;
        }

        try {
            // 2. Strict terminal state guard: Prevent re-publishing already published posts
            if ($job->status === PublishJob::STATUS_PUBLISHED) {
                Log::info("[PublishingEngine][{$correlationId}] PublishJob {$job->id} already confirmed published with remote ID {$job->remote_post_id}. Skipping execution.");
                return;
            }

            // 3. Re-check if job is cancelled
            if ($job->status === PublishJob::STATUS_CANCELLED) {
                Log::info("[PublishingEngine][{$correlationId}] PublishJob {$job->id} was cancelled. Skipping execution.");
                return;
            }

            // 4. Update attempt counter and begin validation
            $attemptNumber = $job->attempts_count + 1;
            if ($job->status !== PublishJob::STATUS_PROCESSING && $job->canTransitionTo(PublishJob::STATUS_VALIDATING)) {
                $job->transitionTo(PublishJob::STATUS_VALIDATING, [
                    'attempts_count' => $attemptNumber,
                    'last_attempt_at' => now(),
                ]);
            } else {
                $job->update([
                    'attempts_count' => $attemptNumber,
                    'last_attempt_at' => now(),
                ]);
            }

            $attempt = PublishAttempt::create([
                'publish_job_id' => $job->id,
                'attempt_number' => $attemptNumber,
                'status' => 'RUNNING',
                'started_at' => now(),
            ]);

            $account = $job->socialAccount;
            $batch = $job->publishBatch;
            $content = $batch?->contentItem;
            $media = $job->mediaAsset ?? $content?->mediaAsset;

            // 5. Platform Adapter Resolution
            $adapter = $pm->get($job->platform);

            // 6. Refresh OAuth Token if necessary
            $accessToken = $account ? $this->tokenRefreshService->refreshIfExpired($account) : '';

            // 7. Prepare PublishContext
            $globalMetadata = $content?->global_metadata ?? [];
            $platformOverrides = $job->destination_config ?? $content?->platform_overrides ?? [];

            $context = new PublishContext(
                job: $job,
                account: $account,
                media: $media,
                globalMetadata: $globalMetadata,
                platformOverrides: $platformOverrides,
                decryptedAccessToken: $accessToken ?: ($account->encrypted_access_token ?? '')
            );

            // 8. Preflight Platform Validation
            $validationIssues = $adapter->validate(
                $globalMetadata,
                $platformOverrides,
                $media ? $media->toArray() : null
            );

            $job->update(['validation_errors' => $validationIssues]);

            foreach ($validationIssues as $issue) {
                if (($issue['severity'] ?? '') === 'ERROR') {
                    throw new \InvalidArgumentException($issue['message'] ?? 'Platform validation failed.');
                }
            }

            // 9. Transition to UPLOADING if not already PROCESSING
            if ($job->status !== PublishJob::STATUS_PROCESSING && $job->canTransitionTo(PublishJob::STATUS_UPLOADING)) {
                $job->transitionTo(PublishJob::STATUS_UPLOADING);
            }

            // 10. Execute Publish via Platform Adapter
            $result = $adapter->publish($context);

            // 12. Handle Polling vs Immediate Success
            if ($result->requiresPolling) {
                // Remote provider is asynchronously processing/transcoding
                if ($job->status !== PublishJob::STATUS_PROCESSING) {
                    $job->transitionTo(PublishJob::STATUS_PROCESSING, [
                        'remote_post_id' => $result->remoteId,
                        'remote_post_url' => $result->remoteUrl,
                    ]);
                }

                $attempt->update([
                    'status' => 'PROCESSING',
                    'finished_at' => null,
                ]);

                Log::info("[PublishingEngine][{$correlationId}] Job {$job->id} uploaded to {$job->platform}; status check scheduled.", [
                    'remote_id' => $result->remoteId,
                ]);

                // Dispatch delayed status reconciliation check
                PublishDestinationJob::dispatch($job->id)->delay(now()->addSeconds(10));
                return;
            }

            // 13. Immediate Terminal Success: Transition to PUBLISHED
            $job->transitionTo(PublishJob::STATUS_PUBLISHED, [
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

            Log::info("[PublishingEngine][{$correlationId}] PublishJob {$job->id} successfully published to {$job->platform}: {$result->remoteId}");

        } catch (Throwable $e) {
            $this->handleFailure($job, $attempt ?? null, $e, $correlationId, $pm);
        } finally {
            $lock->release();
            if ($job->publish_batch_id) {
                $this->updateBatchStatus($job->publish_batch_id);
            }
        }
    }

    /**
     * Handle job execution failure, classify error, update state, and trigger retry if eligible.
     */
    protected function handleFailure(PublishJob $job, ?PublishAttempt $attempt, Throwable $e, string $correlationId, ?PlatformManager $platformManager = null): void
    {
        $pm = $platformManager ?? $this->platformManager;

        Log::error("[PublishingEngine][{$correlationId}] PublishJob {$job->id} failed: {$e->getMessage()}", [
            'platform' => $job->platform,
            'exception' => get_class($e),
            'line' => $e->getLine(),
        ]);

        if (str_contains($e->getMessage(), 'Tenant violation')) {
            $category = 'AUTHORIZATION_ERROR';
            $userMessage = $e->getMessage();
            $isRetryable = false;
        } else {
            try {
                $adapter = $pm->get($job->platform);
                $classified = method_exists($adapter, 'classifyError')
                    ? $adapter->classifyError($e)
                    : $adapter->normalizeError($e);
                $category = $classified['category'] ?? PublishingRetryPolicy::CATEGORY_UNKNOWN;
                $userMessage = $classified['user_message'] ?? $this->retryPolicy->getUserMessage($category);
                $isRetryable = $classified['retryable'] ?? $this->retryPolicy->isRetryable($category, $job->attempts_count);
            } catch (Throwable) {
                $category = PublishingRetryPolicy::CATEGORY_UNKNOWN;
                $userMessage = $this->retryPolicy->getUserMessage($category);
                $isRetryable = false;
            }
        }

        if ($attempt) {
            $attempt->update([
                'status' => 'FAILED',
                'error_category' => $category,
                'error_message' => $userMessage,
                'finished_at' => now(),
            ]);
        }

        // Check if Authentication Required
        if ($category === 'AUTH_REQUIRED' || $category === PublishingRetryPolicy::CATEGORY_AUTHENTICATION) {
            $job->transitionTo(PublishJob::STATUS_AUTH_REQUIRED, [
                'failure_category' => $category,
                'user_facing_error_message' => $userMessage,
                'internal_error_details' => substr($e->getMessage(), 0, 500),
            ]);
            return;
        }

        if ($isRetryable && $job->attempts_count < $job->max_attempts) {
            $canonicalCategory = PublishingRetryPolicy::normalizeCategory($category);
            $nextRetry = $this->retryPolicy->calculateNextRetry($canonicalCategory, $job->attempts_count) ?: now()->addSeconds(10);
            $backoffSeconds = max(5, $nextRetry->diffInSeconds(now()));

            $job->transitionTo(PublishJob::STATUS_RETRYING, [
                'failure_category' => $category,
                'user_facing_error_message' => $userMessage,
                'internal_error_details' => substr($e->getMessage(), 0, 500),
                'retry_at' => $nextRetry,
            ]);

            Log::info("[PublishingEngine][{$correlationId}] Scheduling retry #{$job->attempts_count} for job {$job->id} in {$backoffSeconds}s.");
            PublishDestinationJob::dispatch($job->id)->delay($nextRetry);
        } else {
            $job->transitionTo(PublishJob::STATUS_FAILED, [
                'failure_category' => $category,
                'user_facing_error_message' => $userMessage,
                'internal_error_details' => substr($e->getMessage(), 0, 500),
                'retry_at' => null,
            ]);
        }
    }

    /**
     * Reconcile an individual job (e.g., stuck in PROCESSING or elapsed retry_at).
     */
    public function reconcileJob(PublishJob $job): void
    {
        $correlationId = $job->correlation_id ?? (string) \Illuminate\Support\Str::uuid();
        $lockKey = "reconcile_lock_job_{$job->id}";
        $lock = Cache::lock($lockKey, 60);

        if (!$lock->get()) {
            return;
        }

        try {
            // If already published or cancelled, nothing to reconcile
            if ($job->isTerminal()) {
                return;
            }

            // Case A: Job is in PROCESSING with a remote ID
            if ($job->status === PublishJob::STATUS_PROCESSING && !empty($job->remote_post_id)) {
                $adapter = $this->platformManager->get($job->platform);
                $account = $job->socialAccount;
                $context = new PublishContext(
                    job: $job,
                    account: $account,
                    media: $job->mediaAsset,
                    globalMetadata: [],
                    platformOverrides: [],
                    decryptedAccessToken: $account?->encrypted_access_token ?? ''
                );

                $statusResult = $adapter->getStatus($job->remote_post_id, $context);

                if ($statusResult->status === 'PUBLISHED') {
                    $job->transitionTo(PublishJob::STATUS_PUBLISHED, [
                        'remote_post_id' => $job->remote_post_id,
                        'remote_post_url' => $statusResult->remoteUrl ?? $job->remote_post_url,
                    ]);
                    Log::info("[PublishingEngine][{$correlationId}] Reconciled job {$job->id} to PUBLISHED.");
                } elseif ($statusResult->status === 'FAILED') {
                    $job->transitionTo(PublishJob::STATUS_FAILED, [
                        'failure_category' => PublishingRetryPolicy::CATEGORY_PERMANENT_PROVIDER_ERROR,
                        'user_facing_error_message' => $statusResult->errorMessage ?? 'Processing failed on remote platform.',
                    ]);
                    Log::warning("[PublishingEngine][{$correlationId}] Reconciled job {$job->id} to FAILED: {$statusResult->errorMessage}");
                } else {
                    // Still processing; refresh heartbeat
                    $job->heartbeat();
                }
            }

            // Case B: Job in RETRYING and retry_at has elapsed
            if ($job->status === PublishJob::STATUS_RETRYING && $job->retry_at && $job->retry_at->isPast()) {
                Log::info("[PublishingEngine][{$correlationId}] Reconciled RETRYING job {$job->id} with elapsed retry_at. Redispatching.");
                $job->transitionTo(PublishJob::STATUS_QUEUED);
                PublishDestinationJob::dispatch($job->id);
            }

        } catch (Throwable $e) {
            Log::error("[PublishingEngine][{$correlationId}] Reconciliation error for job {$job->id}: {$e->getMessage()}");
        } finally {
            $lock->release();
            if ($job->publish_batch_id) {
                $this->updateBatchStatus($job->publish_batch_id);
            }
        }
    }

    /**
     * Reap stalled jobs whose heartbeat has expired while in active state.
     */
    public function reapStalledJobs(int $staleMinutes = 15): int
    {
        $cutoff = now()->subMinutes($staleMinutes);

        $stalledJobs = PublishJob::whereIn('status', [
            PublishJob::STATUS_VALIDATING,
            PublishJob::STATUS_UPLOADING,
            PublishJob::STATUS_PROCESSING,
        ])
            ->where(function ($query) use ($cutoff) {
                $query->where('last_heartbeat_at', '<', $cutoff)
                    ->orWhere(function ($q) use ($cutoff) {
                        $q->whereNull('last_heartbeat_at')
                          ->where('updated_at', '<', $cutoff);
                    });
            })
            ->get();

        $reaped = 0;
        foreach ($stalledJobs as $job) {
            $lock = Cache::lock("publish_lock_job_{$job->id}", 10);
            if ($lock->get()) {
                try {
                    Log::warning("[PublishingEngine] Reaping stalled job {$job->id} in status {$job->status}. Re-queuing or failing based on attempts.");
                    if ($job->attempts_count < $job->max_attempts) {
                        $job->transitionTo(PublishJob::STATUS_RETRYING, [
                            'failure_category' => PublishingRetryPolicy::CATEGORY_TIMEOUT,
                            'user_facing_error_message' => 'Publishing worker timed out or stalled. Automatically re-attempting.',
                            'retry_at' => now()->addSeconds(15),
                        ]);
                        PublishDestinationJob::dispatch($job->id)->delay(now()->addSeconds(15));
                    } else {
                        $job->transitionTo(PublishJob::STATUS_FAILED, [
                            'failure_category' => PublishingRetryPolicy::CATEGORY_TIMEOUT,
                            'user_facing_error_message' => 'Publishing process stalled and exceeded maximum execution timeout.',
                        ]);
                    }
                    $reaped++;
                } finally {
                    $lock->release();
                }
            }
        }

        return $reaped;
    }

    /**
     * Dispatch due scheduled jobs with distributed lock to prevent duplicate dispatch.
     */
    public function dispatchScheduledJobs(): int
    {
        $lock = Cache::lock('scheduler_dispatch_lock', 50);
        if (!$lock->get()) {
            return 0;
        }

        $dispatched = 0;
        try {
            $dueJobs = PublishJob::where('status', PublishJob::STATUS_SCHEDULED)
                ->where('scheduled_at', '<=', now())
                ->get();

            foreach ($dueJobs as $job) {
                $job->transitionTo(PublishJob::STATUS_QUEUED);
                PublishDestinationJob::dispatch($job->id);
                $dispatched++;
            }
        } finally {
            $lock->release();
        }

        return $dispatched;
    }

    /**
     * Synchronize parent batch status based on aggregate destination job statuses.
     */
    public function updateBatchStatus(string $batchId): void
    {
        Cache::lock("batch_status_lock_{$batchId}", 10)->block(5, function () use ($batchId) {
            $batch = PublishBatch::with('publishJobs')->find($batchId);
            if (!$batch) return;

            $jobs = $batch->publishJobs;
            $total = $jobs->count();
            if ($total === 0) return;

            $published = $jobs->where('status', PublishJob::STATUS_PUBLISHED)->count();
            $failed = $jobs->whereIn('status', [PublishJob::STATUS_FAILED, PublishJob::STATUS_AUTH_REQUIRED])->count();
            $running = $jobs->whereIn('status', [
                PublishJob::STATUS_VALIDATING,
                PublishJob::STATUS_UPLOADING,
                PublishJob::STATUS_PROCESSING,
                PublishJob::STATUS_RETRYING,
                PublishJob::STATUS_QUEUED
            ])->count();

            if ($running > 0) {
                $batch->update(['status' => 'RUNNING']);
            } elseif ($published === $total) {
                $batch->update(['status' => 'COMPLETED']);
            } elseif ($published > 0 && $failed > 0) {
                $batch->update(['status' => 'PARTIAL_SUCCESS']);
            } elseif ($failed === $total) {
                $batch->update(['status' => 'FAILED']);
            }
        });
    }
}
