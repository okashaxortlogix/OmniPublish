<?php

namespace Tests\Feature;

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
use App\Platforms\Services\PlatformManager;
use App\Services\PublishingEngine;
use App\Services\PublishingRetryPolicy;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class PublishingFailureInjectionTest extends TestCase
{
    use RefreshDatabase;

    protected Workspace $workspace;
    protected User $user;
    protected SocialAccount $youtubeAccount;
    protected MediaAsset $media;
    protected PublishBatch $batch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Adversarial Tester',
            'email' => 'adversarial_' . uniqid() . '@test.com',
            'password' => bcrypt('secret123'),
        ]);

        $this->workspace = Workspace::create([
            'name' => 'Adversarial Testing Lab',
            'slug' => 'lab-' . uniqid(),
            'owner_id' => $this->user->id,
        ]);

        $this->youtubeAccount = SocialAccount::create([
            'workspace_id' => $this->workspace->id,
            'platform' => 'YOUTUBE',
            'platform_account_id' => 'UC_adversarial_01',
            'account_name' => 'Lab Channel',
            'status' => 'ACTIVE',
            'encrypted_access_token' => 'token_xyz',
            'scopes' => ['https://www.googleapis.com/auth/youtube.upload'],
        ]);

        $this->media = MediaAsset::create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
            'filename' => 'adversarial.mp4',
            'original_name' => 'adversarial.mp4',
            'storage_path' => 'videos/adversarial.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 2048576,
            'duration_seconds' => 30.0,
            'status' => 'READY',
        ]);

        $content = ContentItem::create([
            'workspace_id' => $this->workspace->id,
            'media_asset_id' => $this->media->id,
            'global_metadata' => ['title' => 'Adversarial Test Run'],
            'target_platforms' => ['YOUTUBE'],
        ]);

        $this->batch = PublishBatch::create([
            'workspace_id' => $this->workspace->id,
            'content_item_id' => $content->id,
            'status' => 'RUNNING',
        ]);
    }

    public function test_injection_provider_http_429_triggers_rate_limit_backoff(): void
    {
        $job = PublishJob::create([
            'publish_batch_id' => $this->batch->id,
            'workspace_id' => $this->workspace->id,
            'social_account_id' => $this->youtubeAccount->id,
            'platform' => 'YOUTUBE',
            'status' => 'QUEUED',
        ]);

        $mockAdapter = Mockery::mock(PlatformAdapterInterface::class);
        $mockAdapter->shouldReceive('validate')->andReturn([]);
        $mockAdapter->shouldReceive('publish')->andThrow(new \Exception('Quota limit reached: HTTP 429 Too Many Requests'));
        $mockAdapter->shouldReceive('classifyError')->andReturn([
            'category' => 'RATE_LIMITED',
            'user_message' => 'Publishing temporarily rate limited by provider.',
            'retryable' => true,
        ]);

        $mockPm = Mockery::mock(PlatformManager::class);
        $mockPm->shouldReceive('get')->with('YOUTUBE')->andReturn($mockAdapter);

        (new PublishDestinationJob($job->id))->handle($mockPm);

        $job->refresh();
        $this->assertEquals('RETRYING', $job->status);
        $this->assertEquals('RATE_LIMITED', $job->failure_category);
        $this->assertNotNull($job->retry_at);
        $this->assertGreaterThan(now()->timestamp, $job->retry_at->timestamp);
    }

    public function test_injection_provider_http_5xx_triggers_server_unavailable_retry(): void
    {
        $job = PublishJob::create([
            'publish_batch_id' => $this->batch->id,
            'workspace_id' => $this->workspace->id,
            'social_account_id' => $this->youtubeAccount->id,
            'platform' => 'YOUTUBE',
            'status' => 'QUEUED',
        ]);

        $mockAdapter = Mockery::mock(PlatformAdapterInterface::class);
        $mockAdapter->shouldReceive('validate')->andReturn([]);
        $mockAdapter->shouldReceive('publish')->andThrow(new \Exception('HTTP 503 Service Unavailable'));
        $mockAdapter->shouldReceive('classifyError')->andReturn([
            'category' => 'PROVIDER_UNAVAILABLE',
            'user_message' => 'Provider API service is temporarily unavailable.',
            'retryable' => true,
        ]);

        $mockPm = Mockery::mock(PlatformManager::class);
        $mockPm->shouldReceive('get')->with('YOUTUBE')->andReturn($mockAdapter);

        (new PublishDestinationJob($job->id))->handle($mockPm);

        $job->refresh();
        $this->assertEquals('RETRYING', $job->status);
        $this->assertEquals('PROVIDER_UNAVAILABLE', $job->failure_category);
    }

    public function test_injection_expired_oauth_token_refuses_infinite_retry(): void
    {
        $job = PublishJob::create([
            'publish_batch_id' => $this->batch->id,
            'workspace_id' => $this->workspace->id,
            'social_account_id' => $this->youtubeAccount->id,
            'platform' => 'YOUTUBE',
            'status' => 'QUEUED',
        ]);

        $mockAdapter = Mockery::mock(PlatformAdapterInterface::class);
        $mockAdapter->shouldReceive('validate')->andReturn([]);
        $mockAdapter->shouldReceive('publish')->andThrow(new \Exception('Invalid Credentials: Token Expired or Revoked'));
        $mockAdapter->shouldReceive('classifyError')->andReturn([
            'category' => 'AUTH_REQUIRED',
            'user_message' => 'Account authentication expired or invalid. Please reconnect.',
            'retryable' => false,
        ]);

        $mockPm = Mockery::mock(PlatformManager::class);
        $mockPm->shouldReceive('get')->with('YOUTUBE')->andReturn($mockAdapter);

        (new PublishDestinationJob($job->id))->handle($mockPm);

        $job->refresh();
        // Crucial guarantee: AUTH_REQUIRED must NEVER enter infinite retry loop
        $this->assertEquals('AUTH_REQUIRED', $job->status);
        $this->assertEquals('AUTH_REQUIRED', $job->failure_category);
        $this->assertNull($job->retry_at);
    }

    public function test_injection_corrupt_media_permanently_fails_without_retrying(): void
    {
        $job = PublishJob::create([
            'publish_batch_id' => $this->batch->id,
            'workspace_id' => $this->workspace->id,
            'social_account_id' => $this->youtubeAccount->id,
            'platform' => 'YOUTUBE',
            'status' => 'QUEUED',
        ]);

        $mockAdapter = Mockery::mock(PlatformAdapterInterface::class);
        $mockAdapter->shouldReceive('validate')->andReturn([
            ['severity' => 'ERROR', 'message' => 'Corrupt or unreadable video moov atom header.']
        ]);

        $mockPm = Mockery::mock(PlatformManager::class);
        $mockPm->shouldReceive('get')->with('YOUTUBE')->andReturn($mockAdapter);

        (new PublishDestinationJob($job->id))->handle($mockPm);

        $job->refresh();
        $this->assertEquals('FAILED', $job->status);
        $this->assertNull($job->retry_at);
        $this->assertEquals(1, $job->attempts_count);
    }

    public function test_injection_deleted_media_fails_safely(): void
    {
        $job = PublishJob::create([
            'publish_batch_id' => $this->batch->id,
            'workspace_id' => $this->workspace->id,
            'social_account_id' => $this->youtubeAccount->id,
            'media_asset_id' => $this->media->id,
            'platform' => 'YOUTUBE',
            'status' => 'QUEUED',
        ]);

        // Media is deleted concurrently before worker runs
        $this->media->delete();

        $mockAdapter = Mockery::mock(PlatformAdapterInterface::class);
        $mockAdapter->shouldReceive('validate')->andReturn([
            ['severity' => 'ERROR', 'message' => 'Media asset not found or was deleted.']
        ]);

        $mockPm = Mockery::mock(PlatformManager::class);
        $mockPm->shouldReceive('get')->with('YOUTUBE')->andReturn($mockAdapter);

        (new PublishDestinationJob($job->id))->handle($mockPm);

        $job->refresh();
        $this->assertEquals('FAILED', $job->status);
    }

    public function test_injection_scheduler_running_twice_prevents_duplicate_dispatch(): void
    {
        $job = PublishJob::create([
            'publish_batch_id' => $this->batch->id,
            'workspace_id' => $this->workspace->id,
            'social_account_id' => $this->youtubeAccount->id,
            'platform' => 'YOUTUBE',
            'status' => 'SCHEDULED',
            'scheduled_at' => now()->subMinutes(1),
        ]);

        Queue::fake();

        $engine = app(PublishingEngine::class);

        // Instance 1 dispatches
        $dispatched1 = $engine->dispatchScheduledJobs();
        $this->assertEquals(1, $dispatched1);

        // Instance 2 runs concurrently immediately after
        $dispatched2 = $engine->dispatchScheduledJobs();
        $this->assertEquals(0, $dispatched2);

        $job->refresh();
        $this->assertEquals('QUEUED', $job->status);
        Queue::assertPushed(PublishDestinationJob::class, 1);
    }

    public function test_injection_app_crash_during_publish_resumes_without_duplicate(): void
    {
        // Job was marked PROCESSING with a confirmed remote post ID
        $job = PublishJob::create([
            'publish_batch_id' => $this->batch->id,
            'workspace_id' => $this->workspace->id,
            'social_account_id' => $this->youtubeAccount->id,
            'platform' => 'YOUTUBE',
            'status' => 'PROCESSING',
            'remote_post_id' => 'yt_crashed_during_proc_vid',
        ]);

        $mockAdapter = Mockery::mock(PlatformAdapterInterface::class);
        // Adapter should NOT re-initiate publish, but rather check status
        $mockAdapter->shouldNotReceive('publish');
        $mockAdapter->shouldReceive('getStatus')->with('yt_crashed_during_proc_vid', Mockery::any())
            ->andReturn(new \App\Platforms\Contracts\RemoteStatusResult(
                status: 'PUBLISHED',
                remoteUrl: 'https://youtube.com/watch?v=yt_crashed_during_proc_vid'
            ));

        $mockPm = Mockery::mock(PlatformManager::class);
        $mockPm->shouldReceive('get')->with('YOUTUBE')->andReturn($mockAdapter);

        $engine = new PublishingEngine(
            $mockPm,
            app(PublishingRetryPolicy::class),
            app(\App\Services\TokenRefreshService::class)
        );

        $engine->reconcileJob($job);

        $job->refresh();
        $this->assertEquals('PUBLISHED', $job->status);
        $this->assertEquals('https://youtube.com/watch?v=yt_crashed_during_proc_vid', $job->remote_post_url);
    }
}
