<?php

namespace Tests\Feature;

use App\Events\Publishing\PublishingJobCompleted;
use App\Events\Publishing\PublishingJobFailed;
use App\Events\Publishing\PublishingJobQueued;
use App\Events\Publishing\PublishingJobStarted;
use App\Events\Publishing\PublishingJobValidating;
use App\Jobs\PublishDestinationJob;
use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Models\PublishBatch;
use App\Models\PublishJob;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\Workspace;
use App\Platforms\Contracts\PlatformAdapterInterface;
use App\Platforms\Contracts\PublishResult;
use App\Platforms\Contracts\RemoteStatusResult;
use App\Platforms\Services\PlatformManager;
use App\Services\PublishingEngine;
use App\Services\PublishingRetryPolicy;
use App\Services\PublishingService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class PublishingOrchestrationTest extends TestCase
{
    use RefreshDatabase;

    protected Workspace $workspaceA;
    protected Workspace $workspaceB;
    protected User $userA;
    protected User $userB;
    protected SocialAccount $youtubeAccountA;
    protected SocialAccount $tiktokAccountA;
    protected SocialAccount $instagramAccountA;
    protected MediaAsset $mediaVideoA;
    protected PublishBatch $batchA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::create([
            'name' => 'Creator One',
            'email' => 'creator1_' . uniqid() . '@omnipublish.test',
            'password' => bcrypt('password123'),
        ]);

        $this->userB = User::create([
            'name' => 'Creator Two',
            'email' => 'creator2_' . uniqid() . '@omnipublish.test',
            'password' => bcrypt('password123'),
        ]);

        $this->workspaceA = Workspace::create([
            'name' => 'Studio Workspace A',
            'slug' => 'studio-a-' . uniqid(),
            'owner_id' => $this->userA->id,
        ]);

        $this->workspaceB = Workspace::create([
            'name' => 'Studio Workspace B',
            'slug' => 'studio-b-' . uniqid(),
            'owner_id' => $this->userB->id,
        ]);

        $this->youtubeAccountA = SocialAccount::create([
            'workspace_id' => $this->workspaceA->id,
            'platform' => 'YOUTUBE',
            'platform_account_id' => 'UC_test_yt_01',
            'account_name' => 'Studio Channel YT',
            'status' => 'ACTIVE',
            'encrypted_access_token' => 'yt_access_token_123',
            'scopes' => ['https://www.googleapis.com/auth/youtube.upload'],
        ]);

        $this->tiktokAccountA = SocialAccount::create([
            'workspace_id' => $this->workspaceA->id,
            'platform' => 'TIKTOK',
            'platform_account_id' => 'open_id_tt_01',
            'account_name' => 'Studio Channel TikTok',
            'status' => 'ACTIVE',
            'encrypted_access_token' => 'tt_access_token_123',
            'scopes' => ['video.upload', 'video.publish'],
        ]);

        $this->instagramAccountA = SocialAccount::create([
            'workspace_id' => $this->workspaceA->id,
            'platform' => 'INSTAGRAM',
            'platform_account_id' => '17841400000000001',
            'account_name' => 'Studio Channel IG',
            'status' => 'ACTIVE',
            'encrypted_access_token' => 'ig_access_token_123',
            'scopes' => ['instagram_content_publish'],
        ]);

        $this->mediaVideoA = MediaAsset::create([
            'workspace_id' => $this->workspaceA->id,
            'user_id' => $this->userA->id,
            'filename' => 'orchestration_test.mp4',
            'original_name' => 'orchestration_test.mp4',
            'storage_path' => 'workspaces/' . $this->workspaceA->id . '/videos/test.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1048576,
            'duration_seconds' => 15.0,
            'status' => 'READY',
        ]);

        $content = ContentItem::create([
            'workspace_id' => $this->workspaceA->id,
            'media_asset_id' => $this->mediaVideoA->id,
            'global_metadata' => ['title' => 'Unified Orchestration Launch'],
            'target_platforms' => ['YOUTUBE', 'TIKTOK', 'INSTAGRAM'],
        ]);

        $this->batchA = PublishBatch::create([
            'workspace_id' => $this->workspaceA->id,
            'content_item_id' => $content->id,
            'status' => 'RUNNING',
        ]);
    }

    // =========================================================================
    // 1. CANONICAL STATE MACHINE TESTS
    // =========================================================================

    public function test_terminal_state_published_is_immutable(): void
    {
        $job = PublishJob::create([
            'publish_batch_id' => $this->batchA->id,
            'workspace_id' => $this->workspaceA->id,
            'social_account_id' => $this->youtubeAccountA->id,
            'platform' => 'YOUTUBE',
            'status' => 'PUBLISHED',
            'remote_post_id' => 'yt_immutable_123',
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Invalid publishing job state transition from [PUBLISHED] to [QUEUED]');

        $job->transitionTo('QUEUED');
    }

    public function test_optimistic_published_transition_without_remote_post_id_throws(): void
    {
        $job = PublishJob::create([
            'publish_batch_id' => $this->batchA->id,
            'workspace_id' => $this->workspaceA->id,
            'social_account_id' => $this->youtubeAccountA->id,
            'platform' => 'YOUTUBE',
            'status' => 'VALIDATING',
        ]);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Illegal optimistic transition: State PUBLISHED requires a confirmed remote_post_id.');

        $job->transitionTo('PUBLISHED');
    }

    public function test_failed_transition_automatically_normalizes_failure_category(): void
    {
        $job = PublishJob::create([
            'publish_batch_id' => $this->batchA->id,
            'workspace_id' => $this->workspaceA->id,
            'social_account_id' => $this->youtubeAccountA->id,
            'platform' => 'YOUTUBE',
            'status' => 'VALIDATING',
        ]);

        $job->transitionTo('FAILED', ['failure_category' => 'INVALID_ARGUMENT']);

        $job->refresh();
        $this->assertEquals('FAILED', $job->status);
        $this->assertEquals('INVALID_ARGUMENT', $job->failure_category);
        $this->assertNotNull($job->completed_at);
    }

    public function test_active_transitions_update_heartbeat_and_started_at(): void
    {
        $job = PublishJob::create([
            'publish_batch_id' => $this->batchA->id,
            'workspace_id' => $this->workspaceA->id,
            'social_account_id' => $this->youtubeAccountA->id,
            'platform' => 'YOUTUBE',
            'status' => 'QUEUED',
        ]);

        $this->assertNull($job->started_at);
        $this->assertNull($job->last_heartbeat_at);

        $job->transitionTo('VALIDATING');
        $this->assertNotNull($job->started_at);
        $this->assertNotNull($job->last_heartbeat_at);

        $firstHeartbeat = $job->last_heartbeat_at;
        $job->transitionTo('UPLOADING');
        $this->assertNotNull($job->last_heartbeat_at);
        $this->assertTrue($job->last_heartbeat_at->gte($firstHeartbeat));
    }

    // =========================================================================
    // 2. RETRY POLICY & FAILURE CLASSIFICATION TESTS
    // =========================================================================

    public function test_retry_policy_distinguishes_transient_vs_permanent_failures(): void
    {
        $policy = new PublishingRetryPolicy();

        // Transient / retryable
        $this->assertTrue($policy->isRetryable('RATE_LIMITED', 1));
        $this->assertTrue($policy->isRetryable('NETWORK', 2));
        $this->assertTrue($policy->isRetryable('TIMEOUT', 1));
        $this->assertTrue($policy->isRetryable('PROVIDER_UNAVAILABLE', 3));

        // Permanent / non-retryable
        $this->assertFalse($policy->isRetryable('AUTHENTICATION', 1));
        $this->assertFalse($policy->isRetryable('AUTHORIZATION', 1));
        $this->assertFalse($policy->isRetryable('VALIDATION', 1));
        $this->assertFalse($policy->isRetryable('MEDIA_INVALID', 1));
        $this->assertFalse($policy->isRetryable('QUOTA_EXCEEDED', 1));
        $this->assertFalse($policy->isRetryable('DUPLICATE', 1));
        $this->assertFalse($policy->isRetryable('PERMANENT_PROVIDER_ERROR', 1));
    }

    public function test_retry_policy_calculates_exponential_backoff_with_jitter(): void
    {
        $policy = new PublishingRetryPolicy();

        $attempt1 = $policy->calculateBackoffSeconds('RATE_LIMITED', 1);
        $attempt2 = $policy->calculateBackoffSeconds('RATE_LIMITED', 2);
        $attempt3 = $policy->calculateBackoffSeconds('RATE_LIMITED', 3);

        // RATE_LIMITED base backoff is 60s, 120s, 240s
        $this->assertGreaterThanOrEqual(60, $attempt1);
        $this->assertGreaterThanOrEqual(120, $attempt2);
        $this->assertGreaterThanOrEqual(240, $attempt3);
        $this->assertGreaterThan($attempt1, $attempt2);
        $this->assertGreaterThan($attempt2, $attempt3);
    }

    // =========================================================================
    // 3. SCHEDULING ENGINE TESTS
    // =========================================================================

    public function test_scheduled_job_creation_persists_future_utc_timestamp(): void
    {
        $futureUtc = now()->addHours(3)->toIso8601String();

        $service = app(PublishingService::class);
        $batch = $service->createBatch(
            workspaceId: $this->workspaceA->id,
            userId: $this->userA->id,
            mediaAssetId: $this->mediaVideoA->id,
            globalMetadata: ['title' => 'Scheduled Broadcast'],
            platformOverrides: [],
            targetPlatforms: ['YOUTUBE'],
            scheduledFor: $futureUtc
        );

        $this->assertEquals('QUEUED', $batch->status);
        $this->assertNotNull($batch->scheduled_for);

        $job = $batch->publishJobs->first();
        $this->assertEquals('SCHEDULED', $job->status);
        $this->assertNotNull($job->scheduled_at);
        $this->assertEquals($this->mediaVideoA->id, $job->media_asset_id);
    }

    public function test_scheduler_dispatches_due_jobs_with_distributed_lock(): void
    {
        $pastUtc = now()->subMinutes(5);

        $job = PublishJob::create([
            'publish_batch_id' => $this->batchA->id,
            'workspace_id' => $this->workspaceA->id,
            'social_account_id' => $this->youtubeAccountA->id,
            'platform' => 'YOUTUBE',
            'status' => 'SCHEDULED',
            'scheduled_at' => $pastUtc,
        ]);

        Queue::fake();

        $engine = app(PublishingEngine::class);
        $dispatched = $engine->dispatchScheduledJobs();

        $this->assertEquals(1, $dispatched);
        $job->refresh();
        $this->assertEquals('QUEUED', $job->status);
        Queue::assertPushed(PublishDestinationJob::class);
    }

    // =========================================================================
    // 4. RECONCILIATION & STALLED JOB RECOVERY
    // =========================================================================

    public function test_reconciliation_command_transitions_completed_remote_container(): void
    {
        $job = PublishJob::create([
            'publish_batch_id' => $this->batchA->id,
            'workspace_id' => $this->workspaceA->id,
            'social_account_id' => $this->instagramAccountA->id,
            'platform' => 'INSTAGRAM',
            'status' => 'PROCESSING',
            'remote_post_id' => 'ig_reconcile_container_99',
        ]);

        $mockAdapter = Mockery::mock(PlatformAdapterInterface::class);
        $mockAdapter->shouldReceive('getStatus')->with('ig_reconcile_container_99', Mockery::any())
            ->andReturn(new RemoteStatusResult(
                status: 'PUBLISHED',
                remoteUrl: 'https://instagram.com/reel/reconciled99'
            ));

        $mockPm = Mockery::mock(PlatformManager::class);
        $mockPm->shouldReceive('get')->with('INSTAGRAM')->andReturn($mockAdapter);

        $engine = new PublishingEngine(
            $mockPm,
            app(PublishingRetryPolicy::class),
            app(\App\Services\TokenRefreshService::class)
        );

        $engine->reconcileJob($job);

        $job->refresh();
        $this->assertEquals('PUBLISHED', $job->status);
        $this->assertEquals('https://instagram.com/reel/reconciled99', $job->remote_post_url);
    }

    public function test_stalled_job_reaping_recovers_crashed_worker(): void
    {
        $job = PublishJob::create([
            'publish_batch_id' => $this->batchA->id,
            'workspace_id' => $this->workspaceA->id,
            'social_account_id' => $this->youtubeAccountA->id,
            'platform' => 'YOUTUBE',
            'status' => 'UPLOADING',
            'attempts_count' => 1,
            'max_attempts' => 3,
            'last_heartbeat_at' => now()->subMinutes(25),
        ]);

        Queue::fake();

        $engine = app(PublishingEngine::class);
        $reaped = $engine->reapStalledJobs(15);

        $this->assertEquals(1, $reaped);
        $job->refresh();
        $this->assertEquals('RETRYING', $job->status);
        $this->assertEquals('TIMEOUT', $job->failure_category);
        Queue::assertPushed(PublishDestinationJob::class);
    }

    // =========================================================================
    // 5. IDEMPOTENCY & CRASH SCENARIOS
    // =========================================================================

    public function test_concurrent_workers_prevent_duplicate_execution(): void
    {
        $job = PublishJob::create([
            'publish_batch_id' => $this->batchA->id,
            'workspace_id' => $this->workspaceA->id,
            'social_account_id' => $this->youtubeAccountA->id,
            'platform' => 'YOUTUBE',
            'status' => 'QUEUED',
        ]);

        // Worker 1 holds lock
        $lockKey = "publish_lock_job_{$job->id}";
        $lock = Cache::lock($lockKey, 300);
        $this->assertTrue($lock->acquire());

        $mockAdapter = Mockery::mock(PlatformAdapterInterface::class);
        $mockAdapter->shouldNotReceive('publish');

        $mockPm = Mockery::mock(PlatformManager::class);
        $mockPm->shouldReceive('get')->with('YOUTUBE')->andReturn($mockAdapter);

        // Worker 2 attempts execution
        (new PublishDestinationJob($job->id))->handle($mockPm);

        $job->refresh();
        $this->assertEquals('QUEUED', $job->status);

        $lock->release();
    }

    // =========================================================================
    // 6. MULTI-PLATFORM PROVIDER ISOLATION
    // =========================================================================

    public function test_isolated_platform_failure_does_not_halt_other_destinations(): void
    {
        $service = app(PublishingService::class);
        $batch = $service->createBatch(
            workspaceId: $this->workspaceA->id,
            userId: $this->userA->id,
            mediaAssetId: $this->mediaVideoA->id,
            globalMetadata: ['title' => 'Multi Platform Test'],
            platformOverrides: [],
            targetPlatforms: ['YOUTUBE', 'TIKTOK', 'INSTAGRAM']
        );

        $this->assertEquals(3, $batch->publishJobs()->count());

        $ytJob = $batch->publishJobs()->where('platform', 'YOUTUBE')->first();
        $ttJob = $batch->publishJobs()->where('platform', 'TIKTOK')->first();
        $igJob = $batch->publishJobs()->where('platform', 'INSTAGRAM')->first();

        // 1. YouTube succeeds
        $ytAdapter = Mockery::mock(PlatformAdapterInterface::class);
        $ytAdapter->shouldReceive('validate')->andReturn([]);
        $ytAdapter->shouldReceive('publish')->andReturn(new PublishResult(remoteId: 'yt_ok_1', remoteUrl: 'https://yt.com/1'));

        // 2. TikTok fails permanently
        $ttAdapter = Mockery::mock(PlatformAdapterInterface::class);
        $ttAdapter->shouldReceive('validate')->andReturn([]);
        $ttAdapter->shouldReceive('publish')->andThrow(new \Exception('Unrecoverable TikTok moderation block'));
        $ttAdapter->shouldReceive('classifyError')->andReturn([
            'category' => 'PERMANENT_PROVIDER_ERROR',
            'user_message' => 'Moderation rejected',
            'retryable' => false,
        ]);

        // 3. Instagram succeeds
        $igAdapter = Mockery::mock(PlatformAdapterInterface::class);
        $igAdapter->shouldReceive('validate')->andReturn([]);
        $igAdapter->shouldReceive('publish')->andReturn(new PublishResult(remoteId: 'ig_ok_1', remoteUrl: 'https://ig.com/1'));

        $mockPm = Mockery::mock(PlatformManager::class);
        $mockPm->shouldReceive('get')->with('YOUTUBE')->andReturn($ytAdapter);
        $mockPm->shouldReceive('get')->with('TIKTOK')->andReturn($ttAdapter);
        $mockPm->shouldReceive('get')->with('INSTAGRAM')->andReturn($igAdapter);

        // Execute independently
        (new PublishDestinationJob($ytJob->id))->handle($mockPm);
        (new PublishDestinationJob($ttJob->id))->handle($mockPm);
        (new PublishDestinationJob($igJob->id))->handle($mockPm);

        $ytJob->refresh();
        $ttJob->refresh();
        $igJob->refresh();
        $batch->refresh();

        $this->assertEquals('PUBLISHED', $ytJob->status);
        $this->assertEquals('FAILED', $ttJob->status);
        $this->assertEquals('PUBLISHED', $igJob->status);
        $this->assertEquals('PARTIAL_SUCCESS', $batch->status);
    }

    // =========================================================================
    // 7. STANDARDIZED CANONICAL EVENT TESTS
    // =========================================================================

    public function test_standardized_publishing_events_dispatched_with_correlation_id(): void
    {
        Event::fake([
            PublishingJobQueued::class,
            PublishingJobValidating::class,
            PublishingJobStarted::class,
            PublishingJobCompleted::class,
        ]);

        $job = PublishJob::create([
            'publish_batch_id' => $this->batchA->id,
            'workspace_id' => $this->workspaceA->id,
            'social_account_id' => $this->youtubeAccountA->id,
            'platform' => 'YOUTUBE',
            'status' => 'QUEUED',
        ]);

        $this->assertNotEmpty($job->correlation_id);

        $mockAdapter = Mockery::mock(PlatformAdapterInterface::class);
        $mockAdapter->shouldReceive('validate')->andReturn([]);
        $mockAdapter->shouldReceive('publish')->andReturn(new PublishResult(remoteId: 'yt_event_ok', remoteUrl: 'https://yt.com/ev'));

        $mockPm = Mockery::mock(PlatformManager::class);
        $mockPm->shouldReceive('get')->with('YOUTUBE')->andReturn($mockAdapter);

        (new PublishDestinationJob($job->id))->handle($mockPm);

        Event::assertDispatched(PublishingJobValidating::class, function ($event) use ($job) {
            return $event->jobId === $job->id && $event->workspaceId === $this->workspaceA->id && $event->correlationId === $job->correlation_id;
        });

        Event::assertDispatched(PublishingJobStarted::class, function ($event) use ($job) {
            return $event->platform === 'YOUTUBE';
        });

        Event::assertDispatched(PublishingJobCompleted::class, function ($event) use ($job) {
            return $event->jobId === $job->id;
        });
    }

    // =========================================================================
    // 8. API SECURITY & IDOR RESTRICTIONS
    // =========================================================================

    public function test_workspace_isolation_prevents_unauthorized_job_cancellation(): void
    {
        $jobA = PublishJob::create([
            'publish_batch_id' => $this->batchA->id,
            'workspace_id' => $this->workspaceA->id,
            'social_account_id' => $this->youtubeAccountA->id,
            'platform' => 'YOUTUBE',
            'status' => 'QUEUED',
        ]);

        // Workspace B attempts to cancel Workspace A's job
        $response = $this->withHeaders(['X-Workspace-Id' => $this->workspaceB->id])
            ->postJson("/api/v1/publish/jobs/{$jobA->id}/cancel");

        $response->assertStatus(404);
        $jobA->refresh();
        $this->assertEquals('QUEUED', $jobA->status);
    }
}
