<?php

namespace Tests\Feature;

use Tests\TestCase;
use Tests\Fixtures\MediaFixtureGenerator;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\MediaAsset;
use App\Models\MediaDerivative;
use App\Jobs\ProcessMediaAssetJob;
use App\Services\Contracts\FFmpegServiceInterface;
use App\Platforms\Profiles\PlatformProfileManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MediaProcessingPipelineTest extends TestCase
{
    use RefreshDatabase;

    protected User $userA;
    protected User $userB;
    protected Workspace $workspaceA;
    protected Workspace $workspaceB;
    protected string $fixtureVideoPath;

    protected function setUp(): void
    {
        parent::setUp();
        $disk = config('filesystems.default', 'public');
        Storage::fake($disk);

        $this->userA = User::create([
            'name' => 'Alice Producer',
            'email' => 'alice.prod@omnipublish.test',
            'password' => bcrypt('password123'),
        ]);

        $this->userB = User::create([
            'name' => 'Bob Producer',
            'email' => 'bob.prod@omnipublish.test',
            'password' => bcrypt('password123'),
        ]);

        $this->workspaceA = Workspace::create([
            'name' => 'Studio Alpha',
            'slug' => 'studio-alpha',
            'owner_id' => $this->userA->id,
        ]);

        $this->workspaceB = Workspace::create([
            'name' => 'Studio Beta',
            'slug' => 'studio-beta',
            'owner_id' => $this->userB->id,
        ]);

        // Create a real video fixture in system temp dir
        $this->fixtureVideoPath = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'omnipublish_test_fixture.mp4';
        MediaFixtureGenerator::createValidVideo($this->fixtureVideoPath, 2, 640, 360, 15);
    }

    /**
     * Test 1: Full end-to-end processing pipeline on a valid video
     */
    public function test_process_media_job_extracts_metadata_and_generates_thumbnail(): void
    {
        $disk = config('filesystems.default', 'public');
        $storagePath = "workspaces/{$this->workspaceA->id}/media/" . Str::uuid() . "/master.mp4";
        Storage::disk($disk)->put($storagePath, file_get_contents($this->fixtureVideoPath));

        $media = MediaAsset::create([
            'workspace_id' => $this->workspaceA->id,
            'filename' => 'master.mp4',
            'original_name' => 'master.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => filesize($this->fixtureVideoPath),
            'storage_path' => $storagePath,
            'status' => 'QUEUED',
            'processing_stage' => 'QUEUED',
        ]);

        $job = new ProcessMediaAssetJob($media->id);
        $job->handle(
            app(FFmpegServiceInterface::class),
            app(PlatformProfileManager::class)
        );

        $media->refresh();

        // Verifications
        $this->assertEquals('READY', $media->status);
        $this->assertEquals('READY', $media->processing_stage);
        $this->assertNotNull($media->duration_seconds);
        $this->assertGreaterThanOrEqual(1, $media->duration_seconds);
        $this->assertEquals(640, $media->width);
        $this->assertEquals(360, $media->height);
        $this->assertEquals('16:9', $media->aspect_ratio);
        $this->assertEquals('h264', $media->video_codec);
        $this->assertNotNull($media->thumbnail_path);

        // Verify physical thumbnail presence in object storage
        $this->assertTrue(Storage::disk($disk)->exists($media->thumbnail_path));

        // Verify derivative record created
        $derivative = MediaDerivative::where('media_asset_id', $media->id)
            ->where('derivative_type', 'THUMBNAIL')
            ->first();

        $this->assertNotNull($derivative);
        $this->assertEquals('READY', $derivative->status);
        $this->assertEquals('image/jpeg', $derivative->mime_type);
    }

    /**
     * Test 2: State Machine validates transitions and blocks invalid ones
     */
    public function test_state_machine_blocks_invalid_transitions(): void
    {
        $media = MediaAsset::create([
            'workspace_id' => $this->workspaceA->id,
            'filename' => 'state.mp4',
            'original_name' => 'state.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1024,
            'storage_path' => 'dummy.mp4',
            'status' => 'READY',
        ]);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid media state transition from [READY] to [PROCESSING].');

        // Cannot accidentally transition directly from READY to PROCESSING
        $media->transitionTo('PROCESSING');
    }

    /**
     * Test 3: Empty file is rejected and marked FAILED
     */
    public function test_empty_file_fails_processing(): void
    {
        $disk = config('filesystems.default', 'public');
        $storagePath = "workspaces/{$this->workspaceA->id}/media/" . Str::uuid() . "/empty.mp4";
        Storage::disk($disk)->put($storagePath, "");

        $media = MediaAsset::create([
            'workspace_id' => $this->workspaceA->id,
            'filename' => 'empty.mp4',
            'original_name' => 'empty.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 0,
            'storage_path' => $storagePath,
            'status' => 'QUEUED',
        ]);

        $job = new ProcessMediaAssetJob($media->id);
        $job->handle(
            app(FFmpegServiceInterface::class),
            app(PlatformProfileManager::class)
        );

        $media->refresh();
        $this->assertEquals('FAILED', $media->status);
        $this->assertEquals('FAILED', $media->processing_stage);
        $this->assertStringContainsString('empty', strtolower($media->error_message));
    }

    /**
     * Test 4: Corrupt media is rejected cleanly without crashing the worker
     */
    public function test_corrupt_media_fails_processing_cleanly(): void
    {
        $disk = config('filesystems.default', 'public');
        $storagePath = "workspaces/{$this->workspaceA->id}/media/" . Str::uuid() . "/corrupt.mp4";
        Storage::disk($disk)->put($storagePath, "ftypmp42corrupted_random_header_payload_bytes_without_video_stream");

        $media = MediaAsset::create([
            'workspace_id' => $this->workspaceA->id,
            'filename' => 'corrupt.mp4',
            'original_name' => 'corrupt.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 100,
            'storage_path' => $storagePath,
            'status' => 'QUEUED',
        ]);

        $job = new ProcessMediaAssetJob($media->id);
        $job->handle(
            app(FFmpegServiceInterface::class),
            app(PlatformProfileManager::class)
        );

        $media->refresh();
        $this->assertEquals('FAILED', $media->status);
        $this->assertNotNull($media->error_message);
    }

    /**
     * Test 5: Missing source storage object is caught safely
     */
    public function test_missing_storage_source_fails_safely(): void
    {
        $media = MediaAsset::create([
            'workspace_id' => $this->workspaceA->id,
            'filename' => 'ghost.mp4',
            'original_name' => 'ghost.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 100,
            'storage_path' => 'non_existent_path.mp4',
            'status' => 'QUEUED',
        ]);

        $job = new ProcessMediaAssetJob($media->id);
        $job->handle(
            app(FFmpegServiceInterface::class),
            app(PlatformProfileManager::class)
        );

        $media->refresh();
        $this->assertEquals('FAILED', $media->status);
        $this->assertStringContainsString('Source storage object not found', $media->error_message);
    }

    /**
     * Test 6: Duplicate job dispatch idempotency
     */
    public function test_duplicate_processing_dispatch_is_idempotent(): void
    {
        $disk = config('filesystems.default', 'public');
        $storagePath = "workspaces/{$this->workspaceA->id}/media/" . Str::uuid() . "/idempotent.mp4";
        Storage::disk($disk)->put($storagePath, file_get_contents($this->fixtureVideoPath));

        $media = MediaAsset::create([
            'workspace_id' => $this->workspaceA->id,
            'filename' => 'idempotent.mp4',
            'original_name' => 'idempotent.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => filesize($this->fixtureVideoPath),
            'storage_path' => $storagePath,
            'status' => 'READY', // Already completed
        ]);

        $job = new ProcessMediaAssetJob($media->id);
        $job->handle(
            app(FFmpegServiceInterface::class),
            app(PlatformProfileManager::class)
        );

        // Verify status remains READY and no duplicate thumbnail derivative created
        $media->refresh();
        $this->assertEquals('READY', $media->status);
        $this->assertEquals(0, MediaDerivative::where('media_asset_id', $media->id)->count());
    }

    /**
     * Test 7: Reprocess endpoint re-queues failed media
     */
    public function test_reprocess_endpoint_requeues_failed_media(): void
    {
        Queue::fake();

        $media = MediaAsset::create([
            'workspace_id' => $this->workspaceA->id,
            'filename' => 'failed.mp4',
            'original_name' => 'failed.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1024,
            'storage_path' => 'path.mp4',
            'status' => 'FAILED',
            'error_message' => 'Previous failure',
        ]);

        $response = $this->withHeader('X-Workspace-Id', $this->workspaceA->id)
            ->postJson("/api/v1/media/{$media->id}/reprocess");

        $response->assertStatus(200);
        $this->assertEquals('QUEUED', $response->json('data.status'));

        $media->refresh();
        $this->assertEquals('QUEUED', $media->status);

        Queue::assertPushed(ProcessMediaAssetJob::class);
    }

    /**
     * Test 8: Tenant Security — Workspace B cannot reprocess Workspace A's media
     */
    public function test_workspace_b_cannot_reprocess_workspace_a_media(): void
    {
        $media = MediaAsset::create([
            'workspace_id' => $this->workspaceA->id,
            'filename' => 'alice.mp4',
            'original_name' => 'alice.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1024,
            'storage_path' => 'path.mp4',
            'status' => 'FAILED',
        ]);

        $response = $this->withHeader('X-Workspace-Id', $this->workspaceB->id)
            ->postJson("/api/v1/media/{$media->id}/reprocess");

        $response->assertStatus(403);
    }

    /**
     * Test 9: Portrait video metadata extraction
     */
    public function test_portrait_video_metadata_probed_correctly(): void
    {
        $portraitFixture = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'omnipublish_portrait_fixture.mp4';
        MediaFixtureGenerator::createPortraitVideo($portraitFixture);

        $disk = config('filesystems.default', 'public');
        $storagePath = "workspaces/{$this->workspaceA->id}/media/" . Str::uuid() . "/portrait.mp4";
        Storage::disk($disk)->put($storagePath, file_get_contents($portraitFixture));

        $media = MediaAsset::create([
            'workspace_id' => $this->workspaceA->id,
            'filename' => 'portrait.mp4',
            'original_name' => 'portrait.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => filesize($portraitFixture),
            'storage_path' => $storagePath,
            'status' => 'QUEUED',
        ]);

        (new ProcessMediaAssetJob($media->id))->handle(
            app(FFmpegServiceInterface::class),
            app(PlatformProfileManager::class)
        );

        $media->refresh();
        $this->assertEquals('READY', $media->status);
        $this->assertEquals(360, $media->width);
        $this->assertEquals(640, $media->height);
        $this->assertEquals('9:16', $media->aspect_ratio);
    }
}
