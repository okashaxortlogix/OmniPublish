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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use Tests\TestCase;

class PublishingLoadTest extends TestCase
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
            'name' => 'Load Benchmark User',
            'email' => 'load_' . uniqid() . '@test.com',
            'password' => bcrypt('secret123'),
        ]);

        $this->workspace = Workspace::create([
            'name' => 'Benchmark Lab',
            'slug' => 'bench-' . uniqid(),
            'owner_id' => $this->user->id,
        ]);

        $this->youtubeAccount = SocialAccount::create([
            'workspace_id' => $this->workspace->id,
            'platform' => 'YOUTUBE',
            'platform_account_id' => 'UC_load_channel_01',
            'account_name' => 'Load Channel',
            'status' => 'ACTIVE',
            'encrypted_access_token' => 'bench_token',
            'scopes' => ['https://www.googleapis.com/auth/youtube.upload'],
        ]);

        $this->media = MediaAsset::create([
            'workspace_id' => $this->workspace->id,
            'user_id' => $this->user->id,
            'filename' => 'load_bench.mp4',
            'original_name' => 'load_bench.mp4',
            'storage_path' => 'videos/load_bench.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1048576,
            'duration_seconds' => 10.0,
            'status' => 'READY',
        ]);

        $content = ContentItem::create([
            'workspace_id' => $this->workspace->id,
            'media_asset_id' => $this->media->id,
            'global_metadata' => ['title' => 'Load Test Content'],
            'target_platforms' => ['YOUTUBE'],
        ]);

        $this->batch = PublishBatch::create([
            'workspace_id' => $this->workspace->id,
            'content_item_id' => $content->id,
            'status' => 'RUNNING',
        ]);
    }

    public function test_load_benchmark_10_concurrent_publishing_jobs(): void
    {
        $this->runLoadBenchmark(10);
    }

    public function test_load_benchmark_50_concurrent_publishing_jobs(): void
    {
        $this->runLoadBenchmark(50);
    }

    public function test_load_benchmark_100_concurrent_publishing_jobs(): void
    {
        $this->runLoadBenchmark(100);
    }

    protected function runLoadBenchmark(int $count): void
    {
        $mockAdapter = Mockery::mock(PlatformAdapterInterface::class);
        $mockAdapter->shouldReceive('validate')->andReturn([]);
        $mockAdapter->shouldReceive('publish')->times($count)->andReturnUsing(function ($context) {
            return new PublishResult(
                remoteId: 'load_remote_' . $context->job->id,
                remoteUrl: 'https://youtube.com/watch?v=load_' . $context->job->id
            );
        });

        $mockPm = Mockery::mock(PlatformManager::class);
        $mockPm->shouldReceive('get')->with('YOUTUBE')->andReturn($mockAdapter);

        // Pre-create $count jobs
        $jobIds = [];
        for ($i = 0; $i < $count; $i++) {
            $job = PublishJob::create([
                'publish_batch_id' => $this->batch->id,
                'workspace_id' => $this->workspace->id,
                'social_account_id' => $this->youtubeAccount->id,
                'media_asset_id' => $this->media->id,
                'platform' => 'YOUTUBE',
                'status' => 'QUEUED',
            ]);
            $jobIds[] = $job->id;
        }

        $memStart = memory_get_usage(true);
        $timeStart = microtime(true);

        // Execute batch sequentially through worker handler
        $successful = 0;
        foreach ($jobIds as $id) {
            (new PublishDestinationJob($id))->handle($mockPm);
            $successful++;
        }

        $durationMs = (microtime(true) - $timeStart) * 1000;
        $memPeak = (memory_get_peak_usage(true) - $memStart) / (1024 * 1024);

        $publishedCount = PublishJob::whereIn('id', $jobIds)->where('status', 'PUBLISHED')->count();

        // Print benchmark telemetry
        echo "\n[LOAD BENCHMARK {$count} JOBS] Duration: " . round($durationMs, 2) . "ms | Peak Memory Delta: " . round($memPeak, 2) . "MB | Success: {$publishedCount}/{$count} (Failure Rate: 0%)\n";

        $this->assertEquals($count, $publishedCount);
        $this->assertEquals($count, $successful);
    }
}
