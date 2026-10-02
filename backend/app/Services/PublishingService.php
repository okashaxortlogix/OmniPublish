<?php

namespace App\Services;

use App\Models\ContentItem;
use App\Models\PublishBatch;
use App\Models\PublishJob;
use App\Models\SocialAccount;
use App\Models\AuditEvent;
use App\Jobs\PublishBatchJob;
use App\Jobs\PublishDestinationJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Carbon\Carbon;

class PublishingService
{
    /**
     * Create and initiate a multi-platform publication batch.
     */
    public function createBatch(
        string $workspaceId,
        ?int $userId,
        ?string $mediaAssetId,
        array $globalMetadata,
        array $platformOverrides,
        array $targetPlatforms,
        ?string $scheduledFor = null
    ): PublishBatch {
        if (empty($targetPlatforms)) {
            throw ValidationException::withMessages(['targetPlatforms' => 'At least one target platform must be selected.']);
        }

        // Verify connected accounts for the requested platforms
        $accounts = SocialAccount::where('workspace_id', $workspaceId)
            ->whereIn('platform', $targetPlatforms)
            ->where('status', 'ACTIVE')
            ->get()
            ->keyBy('platform');

        $missingPlatforms = array_diff($targetPlatforms, $accounts->keys()->toArray());
        if (!empty($missingPlatforms)) {
            throw ValidationException::withMessages([
                'targetPlatforms' => 'Missing active connected account for: ' . implode(', ', $missingPlatforms)
            ]);
        }

        return DB::transaction(function () use (
            $workspaceId,
            $userId,
            $mediaAssetId,
            $globalMetadata,
            $platformOverrides,
            $targetPlatforms,
            $accounts,
            $scheduledFor
        ) {
            // 1. Create Content Item
            $contentItem = ContentItem::create([
                'workspace_id' => $workspaceId,
                'media_asset_id' => $mediaAssetId,
                'global_metadata' => $globalMetadata,
                'platform_overrides' => $platformOverrides,
                'target_platforms' => $targetPlatforms,
            ]);

            // 2. Create Publish Batch
            $isScheduled = !empty($scheduledFor) && Carbon::parse($scheduledFor)->isFuture();
            $batch = PublishBatch::create([
                'workspace_id' => $workspaceId,
                'content_item_id' => $contentItem->id,
                'status' => $isScheduled ? 'QUEUED' : 'RUNNING',
                'scheduled_for' => $isScheduled ? Carbon::parse($scheduledFor) : null,
            ]);

            // 3. Create independent Publish Jobs per destination
            foreach ($targetPlatforms as $platform) {
                $account = $accounts[$platform];
                $job = PublishJob::create([
                    'publish_batch_id' => $batch->id,
                    'workspace_id' => $workspaceId,
                    'social_account_id' => $account->id,
                    'platform' => $platform,
                    'status' => 'QUEUED',
                    'attempts_count' => 0,
                    'max_attempts' => 3,
                ]);

                if (!$isScheduled) {
                    PublishDestinationJob::dispatch($job->id);
                }
            }

            // 4. Log Audit Event
            AuditEvent::create([
                'workspace_id' => $workspaceId,
                'user_id' => $userId,
                'action' => $isScheduled ? 'SCHEDULE_CREATED' : 'PUBLISH_REQUESTED',
                'resource_type' => 'PublishBatch',
                'resource_id' => $batch->id,
                'metadata' => [
                    'platforms' => $targetPlatforms,
                    'scheduled_for' => $scheduledFor,
                ],
            ]);

            // If scheduled, delayed dispatch
            if ($isScheduled) {
                PublishBatchJob::dispatch($batch->id)->delay(Carbon::parse($scheduledFor));
            }

            return $batch->load(['publishJobs.socialAccount', 'contentItem.mediaAsset']);
        });
    }

    /**
     * Retry a single failed destination job independently.
     */
    public function retryJob(string $workspaceId, string $jobId, ?int $userId = null): PublishJob
    {
        $job = PublishJob::where('workspace_id', $workspaceId)->findOrFail($jobId);

        if ($job->status === 'PUBLISHED') {
            throw new \DomainException("Job is already successfully published. Duplicate publication prevented.");
        }

        $job->update([
            'status' => 'QUEUED',
            'failure_category' => null,
            'user_facing_error_message' => null,
            'internal_error_details' => null,
        ]);

        $batch = $job->publishBatch;
        $batch->update(['status' => 'RUNNING']);

        AuditEvent::create([
            'workspace_id' => $workspaceId,
            'user_id' => $userId,
            'action' => 'PUBLISH_JOB_RETRIED',
            'resource_type' => 'PublishJob',
            'resource_id' => $job->id,
            'metadata' => ['platform' => $job->platform],
        ]);

        PublishDestinationJob::dispatch($job->id);

        return $job->fresh();
    }
}
