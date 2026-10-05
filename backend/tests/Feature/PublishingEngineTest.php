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

class PublishingEngineTest extends TestCase
{
    use RefreshDatabase;

    protected Workspace $workspace;
    protected User $user;
    protected SocialAccount $youtubeAccount;
    protected SocialAccount $tiktokAccount;
    protected SocialAccount $instagramAccount;
    protected MediaAsset $media;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::create([
            'name' => 'Test Creator',
            'email' => 'creator@omnipublish.test',
            'password' => bcrypt('password123'),
        ]);

        $this->workspace = Workspace::create([
            'name' => 'Test Studio',
            'slug' => 'test-studio',
            'owner_id' => $this->user->id,
        ]);

        $this->youtubeAccount = SocialAccount::create([
            'workspace_id' => $this->workspace->id,
            'platform' => 'YOUTUBE',
            'platform_account_id' => 'yt_channel_123',
            'account_name' => 'Test YouTube Channel',
            'status' => 'ACTIVE',
            'scopes' => ['youtube.upload'],
            'encrypted_access_token' => 'mock_token',
        ]);

        $this->tiktokAccount = SocialAccount::create([
            'workspace_id' => $this->workspace->id,
            'platform' => 'TIKTOK',
            'platform_account_id' => 'tt_user_456',
            'account_name' => 'Test TikTok Profile',
            'status' => 'ACTIVE',
            'scopes' => ['video.publish'],
            'encrypted_access_token' => 'mock_token',
        ]);

        $this->instagramAccount = SocialAccount::create([
            'workspace_id' => $this->workspace->id,
            'platform' => 'INSTAGRAM',
            'platform_account_id' => 'ig_user_789',
            'account_name' => 'Test Instagram Profile',
            'status' => 'ACTIVE',
            'scopes' => ['instagram_content_publish'],
            'encrypted_access_token' => 'mock_token',
        ]);

        $this->media = MediaAsset::create([
            'workspace_id' => $this->workspace->id,
            'filename' => 'test_video.mp4',
            'original_name' => 'Test Video.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 15000000,
            'storage_path' => 'videos/test.mp4',
            'duration_seconds' => 45,
            'width' => 1080,
            'height' => 1920,
            'aspect_ratio' => '9:16',
            'codec' => 'H.264 / AAC',
            'status' => 'READY',
        ]);
    }

    public function test_health_checks_return_up_and_ready(): void
    {
        // Root probes (ingress/ALB)
        $this->getJson('/health')->assertStatus(200)->assertJson(['status' => 'READY']);
        $this->getJson('/health/live')->assertStatus(200)->assertJson(['status' => 'UP']);
        $this->getJson('/health/ready')->assertStatus(200)->assertJson(['status' => 'READY']);

        // API v1 probes
        $this->getJson('/api/v1/health')->assertStatus(200)->assertJson(['status' => 'READY']);
        $response = $this->getJson('/api/v1/health/live');
        $response->assertStatus(200)->assertJson(['status' => 'UP']);

        $readyResponse = $this->getJson('/api/v1/health/ready');
        $readyResponse->assertStatus(200)->assertJson(['status' => 'READY']);
    }

    public function test_version_endpoint_returns_safe_release_identity(): void
    {
        $response = $this->getJson('/version');
        $response->assertStatus(200)
            ->assertJsonStructure([
                'application',
                'version',
                'environment',
                'commit_sha',
                'timestamp',
            ]);

        $apiResponse = $this->getJson('/api/v1/version');
        $apiResponse->assertStatus(200)
            ->assertJsonPath('application', config('app.name', 'OmniPublish'));
    }



    public function test_health_ready_degraded_when_database_fails_without_leaking_secrets(): void
    {
        \Illuminate\Support\Facades\DB::shouldReceive('connection->getPdo')
            ->andThrow(new \Exception('Connection refused: user=postgres host=10.0.0.1 password=supersecret'));

        $response = $this->getJson('/health/ready');
        $response->assertStatus(503)
            ->assertJson([
                'status' => 'DEGRADED',
                'checks' => [
                    'database' => 'UNHEALTHY',
                ],
            ]);

        // Verify zero secret leakage in response body
        $content = $response->getContent();
        $this->assertStringNotContainsString('supersecret', $content);
        $this->assertStringNotContainsString('10.0.0.1', $content);
        $this->assertStringNotContainsString('password', $content);
    }



    public function test_social_accounts_can_be_retrieved(): void
    {
        $response = $this->withHeader('X-Workspace-Id', $this->workspace->id)
            ->getJson('/api/v1/social-accounts');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $accounts = $response->json('data.accounts');
        $this->assertCount(3, $accounts);
    }

    public function test_preflight_validation_catches_invalid_title(): void
    {
        $response = $this->postJson('/api/v1/publish/validate', [
            'globalMetadata' => [
                'title' => '', // Empty title should trigger error for YouTube
                'description' => 'Some description',
            ],
            'targetPlatforms' => ['YOUTUBE'],
        ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.isValid', false);
    }

    public function test_publish_batch_is_created_with_independent_destination_jobs(): void
    {
        $response = $this->postJson('/api/v1/publish/batches', [
            'workspaceId' => $this->workspace->id,
            'mediaAssetId' => $this->media->id,
            'globalMetadata' => [
                'title' => 'Master Autonomous Testing Video',
                'description' => 'Comprehensive test of distributed queue execution',
                'hashtags' => ['test', 'automation'],
                'visibility' => 'PUBLIC',
            ],
            'platformOverrides' => [
                'youtube' => ['madeForKids' => false, 'privacyStatus' => 'public'],
                'tiktok' => ['privacyLevel' => 'PUBLIC_TO_EVERYONE'],
                'instagram' => ['shareToFeed' => true],
            ],
            'targetPlatforms' => ['YOUTUBE', 'TIKTOK', 'INSTAGRAM'],
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $batchId = $response->json('data.id');
        $batch = PublishBatch::with('publishJobs')->findOrFail($batchId);

        $this->assertEquals($this->workspace->id, $batch->workspace_id);
        $this->assertCount(3, $batch->publishJobs);

        // Verify independent platform jobs exist
        $platforms = $batch->publishJobs->pluck('platform')->toArray();
        $this->assertContains('YOUTUBE', $platforms);
        $this->assertContains('TIKTOK', $platforms);
        $this->assertContains('INSTAGRAM', $platforms);
    }

    public function test_single_failed_destination_can_be_retried_independently(): void
    {
        $content = ContentItem::create([
            'workspace_id' => $this->workspace->id,
            'media_asset_id' => $this->media->id,
            'global_metadata' => ['title' => 'Sample Post'],
            'target_platforms' => ['INSTAGRAM'],
        ]);

        $batch = PublishBatch::create([
            'workspace_id' => $this->workspace->id,
            'content_item_id' => $content->id,
            'status' => 'PARTIAL_SUCCESS',
        ]);

        $job = PublishJob::create([
            'publish_batch_id' => $batch->id,
            'workspace_id' => $this->workspace->id,
            'social_account_id' => $this->instagramAccount->id,
            'platform' => 'INSTAGRAM',
            'status' => 'AUTH_REQUIRED',
            'attempts_count' => 1,
            'max_attempts' => 3,
            'failure_category' => 'AUTH_REQUIRED',
            'user_facing_error_message' => 'Instagram token expired',
        ]);

        \Illuminate\Support\Facades\Http::fake([
            'https://graph.facebook.com/v21.0/ig_user_789?*' => \Illuminate\Support\Facades\Http::response([
                'account_type' => 'BUSINESS',
            ], 200),
            'https://graph.facebook.com/v21.0/ig_user_789/media' => \Illuminate\Support\Facades\Http::response([
                'id' => 'container_retry_123',
            ], 200),
            'https://graph.facebook.com/v21.0/container_retry_123*' => \Illuminate\Support\Facades\Http::response([
                'status_code' => 'FINISHED',
            ], 200),
            'https://graph.facebook.com/v21.0/ig_user_789/media_publish' => \Illuminate\Support\Facades\Http::response([
                'id' => 'post_retry_published_999',
            ], 200),
            'https://graph.facebook.com/v21.0/post_retry_published_999*' => \Illuminate\Support\Facades\Http::response([
                'id' => 'post_retry_published_999',
                'permalink' => 'https://www.instagram.com/reel/retry999/',
            ], 200),
        ]);

        $response = $this->postJson("/api/v1/publish/jobs/{$job->id}/retry");

        $response->assertStatus(200)
            ->assertJsonPath('success', true);

        $job->refresh();
        $this->assertEquals('PUBLISHED', $job->status);
        $this->assertNull($job->failure_category);
        $this->assertNotNull($job->remote_post_id);
    }

    public function test_tenant_isolation_prevents_access_to_foreign_workspace_resources(): void
    {
        $otherWorkspace = Workspace::create([
            'name' => 'Foreign Studio',
            'slug' => 'foreign-studio',
            'owner_id' => $this->user->id,
        ]);

        $foreignContent = ContentItem::create([
            'workspace_id' => $otherWorkspace->id,
            'media_asset_id' => null,
            'global_metadata' => ['title' => 'Secret Foreign Post'],
            'target_platforms' => ['YOUTUBE'],
        ]);

        $foreignBatch = PublishBatch::create([
            'workspace_id' => $otherWorkspace->id,
            'content_item_id' => $foreignContent->id,
            'status' => 'COMPLETED',
        ]);

        // Query with Workspace A header
        $response = $this->withHeader('X-Workspace-Id', $this->workspace->id)
            ->getJson('/api/v1/publish/batches');

        $response->assertStatus(200);
        $batches = $response->json('data.data');

        // Verify foreignBatch is NOT visible in Workspace A
        $batchIds = array_column($batches, 'id');
        $this->assertNotContains($foreignBatch->id, $batchIds);
    }
}
