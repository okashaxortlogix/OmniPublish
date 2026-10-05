<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Models\MediaAsset;
use App\Models\SocialAccount;
use App\Models\PublishBatch;
use App\Models\PublishJob;
use App\Models\ContentItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class MediaStorageSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected User $userA;
    protected User $userB;
    protected Workspace $workspaceA;
    protected Workspace $workspaceB;
    protected MediaAsset $mediaA;

    protected function setUp(): void
    {
        parent::setUp();
        $disk = config('filesystems.default', 'public');
        Storage::fake($disk);
        Storage::fake('public');

        $this->userA = User::create([
            'name' => 'Alice Tenant',
            'email' => 'alice@omnipublish.test',
            'password' => bcrypt('password123'),
        ]);

        $this->userB = User::create([
            'name' => 'Bob Tenant',
            'email' => 'bob@omnipublish.test',
            'password' => bcrypt('password123'),
        ]);

        $this->workspaceA = Workspace::create([
            'name' => 'Workspace A',
            'slug' => 'workspace-a',
            'owner_id' => $this->userA->id,
        ]);

        WorkspaceMember::create([
            'workspace_id' => $this->workspaceA->id,
            'user_id' => $this->userA->id,
            'role' => 'OWNER',
        ]);

        $this->workspaceB = Workspace::create([
            'name' => 'Workspace B',
            'slug' => 'workspace-b',
            'owner_id' => $this->userB->id,
        ]);

        WorkspaceMember::create([
            'workspace_id' => $this->workspaceB->id,
            'user_id' => $this->userB->id,
            'role' => 'OWNER',
        ]);

        $this->mediaA = MediaAsset::create([
            'workspace_id' => $this->workspaceA->id,
            'filename' => 'alice_secret.mp4',
            'original_name' => 'alice_secret.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 5000000,
            'storage_path' => 'workspaces/' . $this->workspaceA->id . '/media/' . Str::uuid() . '/video.mp4',
            'status' => 'READY',
        ]);
    }

    /**
     * Test 1: Storage Isolation — Workspace B cannot view Workspace A's media
     */
    public function test_workspace_b_cannot_view_workspace_a_media(): void
    {
        $response = $this->withHeader('X-Workspace-Id', $this->workspaceB->id)
            ->getJson("/api/v1/media/{$this->mediaA->id}");

        // Scoped query throws 404 ModelNotFoundException when querying across foreign tenant
        $response->assertStatus(404);
    }

    /**
     * Test 2: Upload Authorization Isolation — Workspace B cannot request upload auth for Workspace A
     */
    public function test_workspace_b_cannot_authorize_upload_for_workspace_a(): void
    {
        // When Workspace B explicitly sends another workspace ID in header or payload
        $response = $this->withHeader('X-Workspace-Id', $this->workspaceB->id)
            ->postJson('/api/v1/media/upload-authorization', [
                'workspaceId' => $this->workspaceA->id,
                'filename' => 'malicious.mp4',
                'mime_type' => 'video/mp4',
                'size_bytes' => 1024000,
            ]);

        // The authorization endpoint uses resolved header workspace
        $data = $response->json('data');
        if ($response->status() === 201) {
            // Must belong to Workspace B, NOT Workspace A
            $this->assertEquals($this->workspaceB->id, MediaAsset::find($data['media_id'])->workspace_id);
        }
    }

    /**
     * Test 3: Object Confirmation Isolation — Workspace B cannot confirm Workspace A's media
     */
    public function test_workspace_b_cannot_confirm_workspace_a_uploaded_media(): void
    {
        $pendingMediaA = MediaAsset::create([
            'workspace_id' => $this->workspaceA->id,
            'filename' => 'pending.mp4',
            'original_name' => 'pending.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 2048,
            'storage_path' => 'workspaces/' . $this->workspaceA->id . '/media/' . Str::uuid() . '/pending.mp4',
            'status' => 'UPLOAD_AUTHORIZED',
        ]);

        $response = $this->withHeader('X-Workspace-Id', $this->workspaceB->id)
            ->postJson('/api/v1/media/confirm-upload', [
                'media_id' => $pendingMediaA->id,
            ]);

        $response->assertStatus(403)
            ->assertJson([
                'success' => false,
                'message' => 'Unauthorized: Media asset belongs to a different workspace.',
            ]);
    }

    /**
     * Test 4: Media Deletion Isolation — Workspace B cannot delete Workspace A's media
     */
    public function test_workspace_b_cannot_delete_workspace_a_media(): void
    {
        $response = $this->withHeader('X-Workspace-Id', $this->workspaceB->id)
            ->deleteJson("/api/v1/media/{$this->mediaA->id}");

        $response->assertStatus(403);
        $this->assertDatabaseHas('media_assets', ['id' => $this->mediaA->id]);
    }

    /**
     * Test 5: Retry Isolation — Workspace B cannot retry Workspace A's failed publishing job
     */
    public function test_workspace_b_cannot_retry_workspace_a_publish_job(): void
    {
        $accountA = SocialAccount::create([
            'workspace_id' => $this->workspaceA->id,
            'platform' => 'YOUTUBE',
            'platform_account_id' => 'yt_alice',
            'account_name' => 'Alice YT',
            'status' => 'ACTIVE',
        ]);

        $contentA = ContentItem::create([
            'workspace_id' => $this->workspaceA->id,
            'media_asset_id' => $this->mediaA->id,
            'global_metadata' => ['title' => 'Secret'],
            'target_platforms' => ['YOUTUBE'],
        ]);

        $batchA = PublishBatch::create([
            'workspace_id' => $this->workspaceA->id,
            'content_item_id' => $contentA->id,
            'status' => 'FAILED',
        ]);

        $jobA = PublishJob::create([
            'publish_batch_id' => $batchA->id,
            'workspace_id' => $this->workspaceA->id,
            'social_account_id' => $accountA->id,
            'platform' => 'YOUTUBE',
            'status' => 'FAILED',
        ]);

        $response = $this->withHeader('X-Workspace-Id', $this->workspaceB->id)
            ->postJson("/api/v1/publish/jobs/{$jobA->id}/retry");

        $response->assertStatus(404);
    }

    /**
     * Test 6: Zero-byte or missing object fails confirmation
     */
    public function test_missing_storage_object_fails_confirmation(): void
    {
        $media = MediaAsset::create([
            'workspace_id' => $this->workspaceA->id,
            'filename' => 'unuploaded.mp4',
            'original_name' => 'unuploaded.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1024,
            'storage_path' => 'workspaces/' . $this->workspaceA->id . '/media/' . Str::uuid() . '/unuploaded.mp4',
            'status' => 'UPLOAD_AUTHORIZED',
        ]);

        // Client attempts confirmation without having uploaded the object to storage
        $response = $this->withHeader('X-Workspace-Id', $this->workspaceA->id)
            ->postJson('/api/v1/media/confirm-upload', [
                'media_id' => $media->id,
            ]);

        $response->assertStatus(422);

        $media->refresh();
        $this->assertEquals('FAILED', $media->status);
        $this->assertStringContainsString('Storage object not found', $media->error_message);
    }

    /**
     * Test 7: Successful direct upload confirmation flow
     */
    public function test_valid_storage_object_confirmation_transitions_to_ready(): void
    {
        \Illuminate\Support\Facades\Queue::fake([\App\Jobs\ProcessMediaAssetJob::class]);

        // 1. Authorize upload
        $authResponse = $this->withHeader('X-Workspace-Id', $this->workspaceA->id)
            ->postJson('/api/v1/media/upload-authorization', [
                'filename' => 'valid_video.mp4',
                'mime_type' => 'video/mp4',
                'size_bytes' => 10240,
            ]);

        $authResponse->assertStatus(201);
        $mediaId = $authResponse->json('data.media_id');
        $storagePath = $authResponse->json('data.storage_path');

        // 2. Simulate client uploading payload to storage
        $disk = config('filesystems.default', 'public');
        Storage::disk($disk)->put($storagePath, str_repeat('X', 10240));

        // 3. Confirm upload
        $confirmResponse = $this->withHeader('X-Workspace-Id', $this->workspaceA->id)
            ->postJson('/api/v1/media/confirm-upload', [
                'media_id' => $mediaId,
            ]);

        $confirmResponse->assertStatus(200);
        $this->assertEquals('QUEUED', $confirmResponse->json('data.status'));
        \Illuminate\Support\Facades\Queue::assertPushed(\App\Jobs\ProcessMediaAssetJob::class);
    }
}
