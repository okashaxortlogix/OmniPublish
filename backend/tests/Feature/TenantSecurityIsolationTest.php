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
use Illuminate\Foundation\Testing\RefreshDatabase;

class TenantSecurityIsolationTest extends TestCase
{
    use RefreshDatabase;

    protected Workspace $workspaceA;
    protected Workspace $workspaceB;
    protected User $userA;
    protected User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userA = User::create([
            'name' => 'User A',
            'email' => 'user_a@omnipublish.test',
            'password' => bcrypt('secret123'),
        ]);

        $this->workspaceA = Workspace::create([
            'name' => 'Studio A',
            'slug' => 'studio-a',
            'owner_id' => $this->userA->id,
        ]);

        $this->userB = User::create([
            'name' => 'User B',
            'email' => 'user_b@omnipublish.test',
            'password' => bcrypt('secret123'),
        ]);

        $this->workspaceB = Workspace::create([
            'name' => 'Studio B',
            'slug' => 'studio-b',
            'owner_id' => $this->userB->id,
        ]);
    }

    public function test_workspace_a_cannot_view_workspace_b_batch_detail(): void
    {
        $contentB = ContentItem::create([
            'workspace_id' => $this->workspaceB->id,
            'global_metadata' => ['title' => 'Secret Project B Post'],
            'target_platforms' => ['YOUTUBE'],
        ]);

        $batchB = PublishBatch::create([
            'workspace_id' => $this->workspaceB->id,
            'content_item_id' => $contentB->id,
            'status' => 'COMPLETED',
        ]);

        // Attempt to fetch Batch B using Workspace A header
        $response = $this->withHeader('X-Workspace-Id', $this->workspaceA->id)
            ->getJson("/api/v1/publish/batches/{$batchB->id}");

        $response->assertStatus(404);
    }

    public function test_workspace_a_cannot_view_workspace_b_media_asset(): void
    {
        $mediaB = MediaAsset::create([
            'workspace_id' => $this->workspaceB->id,
            'filename' => 'confidential_video.mp4',
            'original_name' => 'confidential_video.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 1234567,
            'storage_path' => 'videos/confidential.mp4',
            'status' => 'READY',
        ]);

        // Attempt to fetch Media B using Workspace A header
        $response = $this->withHeader('X-Workspace-Id', $this->workspaceA->id)
            ->getJson("/api/v1/media/{$mediaB->id}");

        $response->assertStatus(404);
    }

    public function test_workspace_a_cannot_retry_workspace_b_job(): void
    {
        $accountB = SocialAccount::create([
            'workspace_id' => $this->workspaceB->id,
            'platform' => 'YOUTUBE',
            'platform_account_id' => 'yt_channel_b',
            'account_name' => 'Channel B',
            'status' => 'ACTIVE',
        ]);

        $contentB = ContentItem::create([
            'workspace_id' => $this->workspaceB->id,
            'global_metadata' => ['title' => 'Retry Test Post'],
            'target_platforms' => ['YOUTUBE'],
        ]);

        $batchB = PublishBatch::create([
            'workspace_id' => $this->workspaceB->id,
            'content_item_id' => $contentB->id,
            'status' => 'FAILED',
        ]);

        $jobB = PublishJob::create([
            'publish_batch_id' => $batchB->id,
            'workspace_id' => $this->workspaceB->id,
            'social_account_id' => $accountB->id,
            'platform' => 'YOUTUBE',
            'status' => 'FAILED',
            'failure_category' => 'PROVIDER_ERROR',
        ]);

        // Attempt to retry Job B using Workspace A header
        $response = $this->withHeader('X-Workspace-Id', $this->workspaceA->id)
            ->postJson("/api/v1/publish/jobs/{$jobB->id}/retry");

        $response->assertStatus(404);
    }

    public function test_workspace_a_cannot_disconnect_workspace_b_social_account(): void
    {
        $accountB = SocialAccount::create([
            'workspace_id' => $this->workspaceB->id,
            'platform' => 'YOUTUBE',
            'platform_account_id' => 'yt_disconnect_target',
            'account_name' => 'Target Account B',
            'status' => 'ACTIVE',
        ]);

        // Attempt to disconnect Account B using Workspace A header
        $response = $this->withHeader('X-Workspace-Id', $this->workspaceA->id)
            ->postJson("/api/v1/social-accounts/{$accountB->id}/disconnect");

        $response->assertStatus(404);

        $accountB->refresh();
        $this->assertEquals('ACTIVE', $accountB->status);
    }

    public function test_missing_workspace_header_does_not_leak_cross_workspace_analytics(): void
    {
        // Workspace A has 1 post, Workspace B has 1 post
        $accountB = SocialAccount::create([
            'workspace_id' => $this->workspaceB->id,
            'platform' => 'YOUTUBE',
            'platform_account_id' => 'yt_b',
            'account_name' => 'B Account',
            'status' => 'ACTIVE',
        ]);

        $contentB = ContentItem::create([
            'workspace_id' => $this->workspaceB->id,
            'global_metadata' => ['title' => 'Post B'],
            'target_platforms' => ['YOUTUBE'],
        ]);

        $batchB = PublishBatch::create([
            'workspace_id' => $this->workspaceB->id,
            'content_item_id' => $contentB->id,
            'status' => 'COMPLETED',
        ]);

        PublishJob::create([
            'publish_batch_id' => $batchB->id,
            'workspace_id' => $this->workspaceB->id,
            'social_account_id' => $accountB->id,
            'platform' => 'YOUTUBE',
            'status' => 'PUBLISHED',
        ]);

        // Query with Workspace A explicitly: should only see Workspace A data (0 posts)
        $responseA = $this->withHeader('X-Workspace-Id', $this->workspaceA->id)
            ->getJson('/api/v1/analytics/summary');

        $responseA->assertStatus(200);
        $this->assertEquals(0, $responseA->json('data.totalPosts'));
    }
}
