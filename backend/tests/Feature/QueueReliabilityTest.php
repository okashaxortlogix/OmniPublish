<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Workspace;
use App\Models\SocialAccount;
use App\Models\MediaAsset;
use App\Models\ContentItem;
use App\Models\PublishBatch;
use App\Models\PublishJob;
use App\Jobs\PublishDestinationJob;
use App\Platforms\Contracts\PlatformAdapterInterface;
use App\Platforms\Contracts\PublishResult;
use App\Platforms\Services\PlatformManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;

class QueueReliabilityTest extends TestCase
{
    use RefreshDatabase;

    protected Workspace $workspace;
    protected User $user;
    protected SocialAccount $youtubeAccount;
    protected SocialAccount $tiktokAccount;
    protected SocialAccount $instagramAccount;
    protected MediaAsset $media;
    protected ContentItem $content;
    protected PublishBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Queue Tester',
            'email' => 'queue@omnipublish.test',
            'password' => bcrypt('password123'),
        ]);

        $this->workspace = Workspace::create([
            'name' => 'Queue Studio',
            'slug' => 'queue-studio',
            'owner_id' => $this->user->id,
        ]);

        $this->youtubeAccount = SocialAccount::create([
            'workspace_id' => $this->workspace->id,
            'platform' => 'YOUTUBE',
            'platform_account_id' => 'yt_123',
            'account_name' => 'YT Channel',
            'status' => 'ACTIVE',
            'scopes' => ['youtube.upload'],
            'encrypted_access_token' => 'mock_token',
        ]);

        $this->tiktokAccount = SocialAccount::create([
            'workspace_id' => $this->workspace->id,
            'platform' => 'TIKTOK',
            'platform_account_id' => 'tt_456',
            'account_name' => 'TT Profile',
            'status' => 'ACTIVE',
            'scopes' => ['video.publish'],
            'encrypted_access_token' => 'mock_token',
        ]);

        $this->instagramAccount = SocialAccount::create([
            'workspace_id' => $this->workspace->id,
            'platform' => 'INSTAGRAM',
            'platform_account_id' => 'ig_789',
            'account_name' => 'IG Profile',
            'status' => 'ACTIVE',
            'scopes' => ['instagram_content_publish'],
            'encrypted_access_token' => 'mock_token',
        ]);

        $this->media = MediaAsset::create([
            'workspace_id' => $this->workspace->id,
            'filename' => 'vid.mp4',
            'original_name' => 'vid.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 10000000,
            'storage_path' => 'videos/vid.mp4',
            'status' => 'READY',
        ]);

        $this->content = ContentItem::create([
            'workspace_id' => $this->workspace->id,
            'media_asset_id' => $this->media->id,
            'global_metadata' => ['title' => 'Reliability Video', 'description' => 'Test Queue'],
            'target_platforms' => ['YOUTUBE', 'TIKTOK', 'INSTAGRAM'],
        ]);

        $this->batch = PublishBatch::create([
            'workspace_id' => $this->workspace->id,
            'content_item_id' => $this->content->id,
            'status' => 'QUEUED',
        ]);
    }

    /**
     * Test A — Duplicate dispatch idempotency
     */
    public function test_duplicate_dispatch_does_not_republish(): void
    {
        $jobRecord = PublishJob::create([
            'publish_batch_id' => $this->batch->id,
            'workspace_id' => $this->workspace->id,
            'social_account_id' => $this->youtubeAccount->id,
            'platform' => 'YOUTUBE',
            'status' => 'QUEUED',
        ]);

        $mockAdapter = Mockery::mock(PlatformAdapterInterface::class);
        $mockAdapter->shouldReceive('validate')->andReturn([]);
        // publish should only be called ONCE despite two dispatches
        $mockAdapter->shouldReceive('publish')->once()->andReturn(new PublishResult(
            remoteId: 'yt_post_unique_999',
            remoteUrl: 'https://youtube.com/watch?v=yt_post_unique_999'
        ));

        $mockPlatformManager = Mockery::mock(PlatformManager::class);
        $mockPlatformManager->shouldReceive('get')->with('YOUTUBE')->andReturn($mockAdapter);

        // First dispatch
        $jobInstance = new PublishDestinationJob($jobRecord->id);
        $jobInstance->handle($mockPlatformManager);

        $jobRecord->refresh();
        $this->assertEquals('PUBLISHED', $jobRecord->status);
        $this->assertEquals('yt_post_unique_999', $jobRecord->remote_post_id);

        // Second duplicate dispatch
        $secondJobInstance = new PublishDestinationJob($jobRecord->id);
        $secondJobInstance->handle($mockPlatformManager);

        // Verifications: status remains published, no duplicate publish call occurred
        $jobRecord->refresh();
        $this->assertEquals('PUBLISHED', $jobRecord->status);
        $this->assertEquals('yt_post_unique_999', $jobRecord->remote_post_id);
    }

    /**
     * Test B — Worker crash simulation and recovery
     */
    public function test_worker_crash_and_scheduler_reap(): void
    {
        // Simulate a job interrupted midway during upload
        $stalledJob = PublishJob::create([
            'publish_batch_id' => $this->batch->id,
            'workspace_id' => $this->workspace->id,
            'social_account_id' => $this->youtubeAccount->id,
            'platform' => 'YOUTUBE',
            'status' => 'UPLOADING',
            'attempts_count' => 1,
            'max_attempts' => 3,
            'last_attempt_at' => now()->subMinutes(20), // 20 minutes ago
        ]);

        Queue::fake();

        // Run artisan reap command
        $this->artisan('publishing:reap-stalled')
            ->expectsOutputToContain('Reaped and recovered 1 stalled jobs.')
            ->assertExitCode(0);

        $stalledJob->refresh();
        $this->assertEquals('RETRYING', $stalledJob->status);

        // Verify that the job was queued for re-execution
        Queue::assertPushed(PublishDestinationJob::class);
    }

    /**
     * Test C — External API timeout handling
     */
    public function test_external_api_timeout_handling(): void
    {
        $jobRecord = PublishJob::create([
            'publish_batch_id' => $this->batch->id,
            'workspace_id' => $this->workspace->id,
            'social_account_id' => $this->tiktokAccount->id,
            'platform' => 'TIKTOK',
            'status' => 'QUEUED',
            'max_attempts' => 1, // Non-retryable in this test to inspect terminal failure
        ]);

        $mockAdapter = Mockery::mock(PlatformAdapterInterface::class);
        $mockAdapter->shouldReceive('validate')->andReturn([]);
        $mockAdapter->shouldReceive('publish')->andThrow(new \Exception('Connection to platform timed out after 30000ms'));
        $mockAdapter->shouldReceive('classifyError')->andReturn([
            'category' => 'RATE_LIMITED',
            'retryable' => false,
            'user_message' => 'Platform request timed out.',
        ]);

        $mockPlatformManager = Mockery::mock(PlatformManager::class);
        $mockPlatformManager->shouldReceive('get')->with('TIKTOK')->andReturn($mockAdapter);

        $jobInstance = new PublishDestinationJob($jobRecord->id);
        $jobInstance->handle($mockPlatformManager);

        $jobRecord->refresh();
        $this->assertEquals('FAILED', $jobRecord->status);
        $this->assertEquals('RATE_LIMITED', $jobRecord->failure_category);
        $this->assertStringContainsString('Platform request timed out', $jobRecord->user_facing_error_message);
    }

    /**
     * Test D — Multi-platform failure isolation
     * YouTube = Success, TikTok = Failure, Instagram = Success
     */
    public function test_multi_platform_publish_failure_isolation(): void
    {
        $ytJob = PublishJob::create([
            'publish_batch_id' => $this->batch->id,
            'workspace_id' => $this->workspace->id,
            'social_account_id' => $this->youtubeAccount->id,
            'platform' => 'YOUTUBE',
            'status' => 'QUEUED',
        ]);

        $ttJob = PublishJob::create([
            'publish_batch_id' => $this->batch->id,
            'workspace_id' => $this->workspace->id,
            'social_account_id' => $this->tiktokAccount->id,
            'platform' => 'TIKTOK',
            'status' => 'QUEUED',
            'max_attempts' => 1,
        ]);

        $igJob = PublishJob::create([
            'publish_batch_id' => $this->batch->id,
            'workspace_id' => $this->workspace->id,
            'social_account_id' => $this->instagramAccount->id,
            'platform' => 'INSTAGRAM',
            'status' => 'QUEUED',
        ]);

        // Mock YouTube Adapter (Success)
        $ytAdapter = Mockery::mock(PlatformAdapterInterface::class);
        $ytAdapter->shouldReceive('validate')->andReturn([]);
        $ytAdapter->shouldReceive('publish')->andReturn(new PublishResult(remoteId: 'yt_success_id', remoteUrl: 'https://youtube.com/1'));

        // Mock TikTok Adapter (Failure)
        $ttAdapter = Mockery::mock(PlatformAdapterInterface::class);
        $ttAdapter->shouldReceive('validate')->andReturn([]);
        $ttAdapter->shouldReceive('publish')->andThrow(new \Exception('TikTok API rejected video duration'));
        $ttAdapter->shouldReceive('classifyError')->andReturn([
            'category' => 'INVALID_MEDIA',
            'retryable' => false,
            'user_message' => 'Video duration invalid for TikTok.',
        ]);

        // Mock Instagram Adapter (Success)
        $igAdapter = Mockery::mock(PlatformAdapterInterface::class);
        $igAdapter->shouldReceive('validate')->andReturn([]);
        $igAdapter->shouldReceive('publish')->andReturn(new PublishResult(remoteId: 'ig_success_id', remoteUrl: 'https://instagram.com/p/1'));

        $mockPlatformManager = Mockery::mock(PlatformManager::class);
        $mockPlatformManager->shouldReceive('get')->with('YOUTUBE')->andReturn($ytAdapter);
        $mockPlatformManager->shouldReceive('get')->with('TIKTOK')->andReturn($ttAdapter);
        $mockPlatformManager->shouldReceive('get')->with('INSTAGRAM')->andReturn($igAdapter);

        // Execute YouTube Job
        (new PublishDestinationJob($ytJob->id))->handle($mockPlatformManager);

        // Execute TikTok Job
        (new PublishDestinationJob($ttJob->id))->handle($mockPlatformManager);

        // Execute Instagram Job
        (new PublishDestinationJob($igJob->id))->handle($mockPlatformManager);

        $ytJob->refresh();
        $ttJob->refresh();
        $igJob->refresh();
        $this->batch->refresh();

        // Verifications
        $this->assertEquals('PUBLISHED', $ytJob->status);
        $this->assertEquals('FAILED', $ttJob->status);
        $this->assertEquals('PUBLISHED', $igJob->status);

        // The batch must NOT be marked completely FAILED or completely COMPLETED
        $this->assertEquals('PARTIAL_SUCCESS', $this->batch->status);
    }

    /**
     * Test E — Retry only failed platform without re-executing successful platforms
     */
    public function test_retry_only_failed_platform_job(): void
    {
        $ytJob = PublishJob::create([
            'publish_batch_id' => $this->batch->id,
            'workspace_id' => $this->workspace->id,
            'social_account_id' => $this->youtubeAccount->id,
            'platform' => 'YOUTUBE',
            'status' => 'PUBLISHED',
            'remote_post_id' => 'yt_existing_id',
        ]);

        $ttJob = PublishJob::create([
            'publish_batch_id' => $this->batch->id,
            'workspace_id' => $this->workspace->id,
            'social_account_id' => $this->tiktokAccount->id,
            'platform' => 'TIKTOK',
            'status' => 'FAILED',
            'attempts_count' => 1,
            'max_attempts' => 3,
        ]);

        $this->batch->update(['status' => 'PARTIAL_SUCCESS']);

        // Mock TikTok succeeding on retry
        $ttAdapter = Mockery::mock(PlatformAdapterInterface::class);
        $ttAdapter->shouldReceive('validate')->andReturn([]);
        $ttAdapter->shouldReceive('publish')->once()->andReturn(new PublishResult(remoteId: 'tt_retried_id', remoteUrl: 'https://tiktok.com/2'));

        // YouTube should NEVER be called
        $ytAdapter = Mockery::mock(PlatformAdapterInterface::class);
        $ytAdapter->shouldNotReceive('publish');

        $mockPlatformManager = Mockery::mock(PlatformManager::class);
        $mockPlatformManager->shouldReceive('get')->with('TIKTOK')->andReturn($ttAdapter);
        $mockPlatformManager->shouldReceive('get')->with('YOUTUBE')->andReturn($ytAdapter);

        // Dispatch retry for TikTok job only
        (new PublishDestinationJob($ttJob->id))->handle($mockPlatformManager);

        $ytJob->refresh();
        $ttJob->refresh();
        $this->batch->refresh();

        $this->assertEquals('PUBLISHED', $ytJob->status);
        $this->assertEquals('yt_existing_id', $ytJob->remote_post_id);
        $this->assertEquals('PUBLISHED', $ttJob->status);
        $this->assertEquals('tt_retried_id', $ttJob->remote_post_id);

        // Entire batch is now fully COMPLETED
        $this->assertEquals('COMPLETED', $this->batch->status);
    }
}
