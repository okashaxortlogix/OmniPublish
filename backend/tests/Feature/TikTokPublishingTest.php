<?php

namespace Tests\Feature;

use App\Events\TikTokAuthRequired;
use App\Events\TikTokCreatorInfoFetched;
use App\Events\TikTokPublishingCompleted;
use App\Events\TikTokPublishingFailed;
use App\Events\TikTokPublishingInitialized;
use App\Events\TikTokPublishingStarted;
use App\Events\TikTokUploadProgress;
use App\Events\TikTokUploadStarted;
use App\Jobs\PublishDestinationJob;
use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Models\PublishBatch;
use App\Models\PublishJob;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Platforms\Adapters\TikTokPublisher;
use App\Platforms\Profiles\TikTokMediaProfile;
use App\Platforms\Services\PlatformManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\MediaFixtureGenerator;
use Tests\TestCase;

class TikTokPublishingTest extends TestCase
{
    use RefreshDatabase;

    protected Workspace $workspaceA;
    protected Workspace $workspaceB;
    protected SocialAccount $tiktokAccountA;
    protected SocialAccount $tiktokAccountB;
    protected SocialAccount $youtubeAccountA;
    protected MediaAsset $mediaA;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default', 'public'));

        $userA = \App\Models\User::create([
            'name' => 'TikTok Creator A',
            'email' => 'tt_creator_a_' . uniqid() . '@test.com',
            'password' => bcrypt('secret123'),
        ]);

        $userB = \App\Models\User::create([
            'name' => 'TikTok Creator B',
            'email' => 'tt_creator_b_' . uniqid() . '@test.com',
            'password' => bcrypt('secret123'),
        ]);

        $this->workspaceA = Workspace::create([
            'name' => 'Studio Workspace A',
            'slug' => 'studio-a-' . uniqid(),
            'owner_id' => $userA->id,
        ]);

        $this->workspaceB = Workspace::create([
            'name' => 'Studio Workspace B',
            'slug' => 'studio-b-' . uniqid(),
            'owner_id' => $userB->id,
        ]);

        $this->tiktokAccountA = SocialAccount::create([
            'workspace_id' => $this->workspaceA->id,
            'platform' => 'TIKTOK',
            'platform_account_id' => 'tt_open_id_a',
            'account_name' => 'TikTok Creator A',
            'account_handle' => '@creator_a',
            'status' => 'ACTIVE',
            'scopes' => ['video.publish', 'user.info.basic'],
            'encrypted_access_token' => 'valid_tt_access_token_a',
            'encrypted_refresh_token' => 'valid_tt_refresh_token_a',
            'token_expires_at' => now()->addHour(),
        ]);

        $this->tiktokAccountB = SocialAccount::create([
            'workspace_id' => $this->workspaceB->id,
            'platform' => 'TIKTOK',
            'platform_account_id' => 'tt_open_id_b',
            'account_name' => 'TikTok Creator B',
            'account_handle' => '@creator_b',
            'status' => 'ACTIVE',
            'scopes' => ['video.publish'],
            'encrypted_access_token' => 'valid_tt_access_token_b',
            'encrypted_refresh_token' => 'valid_tt_refresh_token_b',
            'token_expires_at' => now()->addHour(),
        ]);

        $this->youtubeAccountA = SocialAccount::create([
            'workspace_id' => $this->workspaceA->id,
            'platform' => 'YOUTUBE',
            'platform_account_id' => 'UC_channel_a',
            'account_name' => 'YouTube Channel A',
            'status' => 'ACTIVE',
            'scopes' => ['https://www.googleapis.com/auth/youtube.upload'],
            'encrypted_access_token' => 'valid_yt_access_token_a',
            'encrypted_refresh_token' => 'valid_yt_refresh_token_a',
            'token_expires_at' => now()->addHour(),
        ]);

        // Generate synthetic test video (duration 4 seconds, size 720x1280)
        $fixturePath = storage_path('app/temp/tt_test_fixture.mp4');
        MediaFixtureGenerator::createValidVideo($fixturePath, 4, 720, 1280);
        $fixtureBytes = file_get_contents($fixturePath);

        $disk = config('filesystems.default', 'public');
        Storage::disk($disk)->put('videos/test_tiktok.mp4', $fixtureBytes);

        $this->mediaA = MediaAsset::create([
            'workspace_id' => $this->workspaceA->id,
            'filename' => 'test_tiktok.mp4',
            'original_name' => 'test_tiktok.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => strlen($fixtureBytes),
            'storage_path' => 'videos/test_tiktok.mp4',
            'status' => 'READY',
            'duration_seconds' => 4.0,
            'width' => 720,
            'height' => 1280,
        ]);
    }

    protected function createPublishJob(array $overrides = []): PublishJob
    {
        $content = ContentItem::create([
            'workspace_id' => $this->workspaceA->id,
            'media_asset_id' => $this->mediaA->id,
            'global_metadata' => [
                'title' => 'Viral TikTok Direct Post',
                'description' => 'Scaling multi-platform video publishing.',
                'hashtags' => ['omnipublish', 'tech', 'saas'],
            ],
            'platform_overrides' => [
                'tiktok' => [
                    'caption' => 'Official OmniPublish Launch Video! #viral #tech',
                    'privacy_level' => 'PUBLIC_TO_EVERYONE',
                    'disable_comment' => false,
                    'disable_duet' => false,
                    'disable_stitch' => false,
                ],
            ],
            'target_platforms' => ['TIKTOK'],
        ]);

        $batch = PublishBatch::create([
            'workspace_id' => $this->workspaceA->id,
            'content_item_id' => $content->id,
            'status' => 'RUNNING',
        ]);

        return PublishJob::create(array_merge([
            'publish_batch_id' => $batch->id,
            'workspace_id' => $this->workspaceA->id,
            'social_account_id' => $this->tiktokAccountA->id,
            'platform' => 'TIKTOK',
            'status' => 'QUEUED',
            'attempts_count' => 0,
            'max_attempts' => 3,
        ], $overrides));
    }

    public function test_tiktok_direct_post_happy_path_initializes_streams_and_publishes(): void
    {
        Event::fake([
            TikTokPublishingStarted::class,
            TikTokCreatorInfoFetched::class,
            TikTokPublishingInitialized::class,
            TikTokUploadStarted::class,
            TikTokUploadProgress::class,
            TikTokPublishingCompleted::class,
        ]);

        $job = $this->createPublishJob();
        $uploadUrl = 'https://open-upload.tiktokapis.com/video/?upload_id=tt_sess_xyz123';

        Http::fake([
            // 1. Creator Info Query
            'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response([
                'data' => [
                    'creator_avatar_url' => 'https://tiktok.com/avatar.jpg',
                    'creator_username' => 'creator_a',
                    'creator_nickname' => 'TikTok Creator A',
                    'privacy_level_options' => ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'SELF_ONLY'],
                    'comment_disabled' => false,
                    'duet_disabled' => false,
                    'stitch_disabled' => false,
                    'max_video_post_duration_sec' => 600,
                ],
                'error' => ['code' => 'ok', 'message' => ''],
            ], 200),

            // 2. Direct Post Init
            'https://open.tiktokapis.com/v2/post/publish/video/init/' => Http::response([
                'data' => [
                    'publish_id' => 'v_pub_success_999',
                    'upload_url' => $uploadUrl,
                ],
                'error' => ['code' => 'ok', 'message' => ''],
            ], 200),

            // 3. Chunk Upload PUT
            'https://open-upload.tiktokapis.com/video/?upload_id=tt_sess_xyz123*' => Http::response('', 200),

            // 4. Processing Status Check
            'https://open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response([
                'data' => [
                    'status' => 'PUBLISH_COMPLETE',
                    'publicaly_available_post_id' => ['71234567890'],
                ],
                'error' => ['code' => 'ok', 'message' => ''],
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('PUBLISHED', $job->status);
        $this->assertEquals('v_pub_success_999', $job->remote_post_id);
        $this->assertEquals('https://www.tiktok.com/@creator_a/video/71234567890', $job->remote_post_url);
        $this->assertNull($job->upload_session_url);
        $this->assertEquals($this->mediaA->size_bytes, $job->bytes_uploaded);

        Event::assertDispatched(TikTokPublishingStarted::class);
        Event::assertDispatched(TikTokCreatorInfoFetched::class);
        Event::assertDispatched(TikTokPublishingInitialized::class);
        Event::assertDispatched(TikTokUploadStarted::class);
        Event::assertDispatched(TikTokUploadProgress::class);
        Event::assertDispatched(TikTokPublishingCompleted::class);
    }

    public function test_tiktok_publishing_stays_in_processing_when_moderation_pending(): void
    {
        Queue::fake([PublishDestinationJob::class]);

        $job = $this->createPublishJob();
        $uploadUrl = 'https://open-upload.tiktokapis.com/video/?upload_id=tt_sess_proc_456';

        Http::fake([
            'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response([
                'data' => [
                    'privacy_level_options' => ['PUBLIC_TO_EVERYONE'],
                    'max_video_post_duration_sec' => 600,
                ],
                'error' => ['code' => 'ok'],
            ], 200),
            'https://open.tiktokapis.com/v2/post/publish/video/init/' => Http::response([
                'data' => [
                    'publish_id' => 'v_pub_processing_123',
                    'upload_url' => $uploadUrl,
                ],
                'error' => ['code' => 'ok'],
            ], 200),
            $uploadUrl . '*' => Http::response('', 200),
            'https://open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response([
                'data' => [
                    'status' => 'PROCESSING_UPLOAD',
                ],
                'error' => ['code' => 'ok'],
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('PROCESSING', $job->status);
        $this->assertEquals('v_pub_processing_123', $job->remote_post_id);

        Queue::assertPushed(PublishDestinationJob::class, function ($pushedJob) use ($job) {
            return $pushedJob->publishJobId === $job->id;
        });
    }

    public function test_tiktok_processing_succeeds_and_marks_published(): void
    {
        Event::fake([TikTokPublishingCompleted::class]);

        $job = $this->createPublishJob([
            'status' => 'PROCESSING',
            'remote_post_id' => 'v_pub_proc_success',
            'remote_post_url' => 'https://www.tiktok.com/@creator_a/video/v_pub_proc_success',
        ]);

        Http::fake([
            'https://open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response([
                'data' => [
                    'status' => 'PUBLISH_COMPLETE',
                    'publicaly_available_post_id' => ['7999888777'],
                ],
                'error' => ['code' => 'ok'],
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('PUBLISHED', $job->status);
        $this->assertEquals('https://www.tiktok.com/@creator_a/video/7999888777', $job->remote_post_url);
        Event::assertDispatched(TikTokPublishingCompleted::class);
    }

    public function test_tiktok_processing_fails_and_marks_failed(): void
    {
        $job = $this->createPublishJob([
            'status' => 'PROCESSING',
            'remote_post_id' => 'v_pub_proc_failed',
            'remote_post_url' => 'https://www.tiktok.com/@creator_a/video/v_pub_proc_failed',
        ]);

        Http::fake([
            'https://open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response([
                'data' => [
                    'status' => 'FAILED',
                    'fail_reason' => 'Video violates TikTok community safety policy',
                ],
                'error' => ['code' => 'ok'],
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('FAILED', $job->status);
        $this->assertEquals('PROVIDER_ERROR', $job->failure_category);
        $this->assertStringContainsString('community safety', strtolower($job->user_facing_error_message));
    }

    public function test_tiktok_expired_access_token_auto_refreshes_before_publish(): void
    {
        config(['services.tiktok.client_key' => 'mock_tt_key']);
        config(['services.tiktok.client_secret' => 'mock_tt_secret']);

        // Expire token
        $this->tiktokAccountA->update([
            'token_expires_at' => now()->subMinutes(15),
            'encrypted_access_token' => 'expired_tt_token',
        ]);

        $job = $this->createPublishJob();
        $uploadUrl = 'https://open-upload.tiktokapis.com/video/?upload_id=tt_refresh_sess';

        Http::fake([
            // 1. TikTok Token Refresh
            'https://open.tiktokapis.com/v2/oauth/token/' => Http::response([
                'data' => [
                    'access_token' => 'freshly_refreshed_tt_token',
                    'refresh_token' => 'new_refresh_token_tt',
                    'expires_in' => 86400,
                ],
            ], 200),

            // 2. Creator Info with fresh token
            'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => function (\Illuminate\Http\Client\Request $request) {
                $this->assertEquals('Bearer freshly_refreshed_tt_token', $request->header('Authorization')[0]);
                return Http::response([
                    'data' => [
                        'privacy_level_options' => ['PUBLIC_TO_EVERYONE'],
                        'max_video_post_duration_sec' => 600,
                    ],
                    'error' => ['code' => 'ok'],
                ], 200);
            },

            // 3. Init with fresh token
            'https://open.tiktokapis.com/v2/post/publish/video/init/' => function (\Illuminate\Http\Client\Request $request) use ($uploadUrl) {
                $this->assertEquals('Bearer freshly_refreshed_tt_token', $request->header('Authorization')[0]);
                return Http::response([
                    'data' => [
                        'publish_id' => 'v_pub_refreshed_111',
                        'upload_url' => $uploadUrl,
                    ],
                    'error' => ['code' => 'ok'],
                ], 200);
            },

            $uploadUrl . '*' => Http::response('', 200),
            'https://open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response([
                'data' => ['status' => 'PUBLISH_COMPLETE'],
                'error' => ['code' => 'ok'],
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();
        $this->assertEquals('PUBLISHED', $job->status);

        $this->tiktokAccountA->refresh();
        $this->assertEquals('freshly_refreshed_tt_token', $this->tiktokAccountA->encrypted_access_token);
        $this->assertEquals('new_refresh_token_tt', $this->tiktokAccountA->encrypted_refresh_token);
    }

    public function test_tiktok_token_refresh_concurrency_lock_prevents_duplicate_refresh(): void
    {
        config(['services.tiktok.client_key' => 'mock_tt_key']);
        config(['services.tiktok.client_secret' => 'mock_tt_secret']);

        $this->tiktokAccountA->update([
            'token_expires_at' => now()->subMinutes(15),
            'encrypted_access_token' => 'expired_tt_token',
        ]);

        Http::fake([
            'https://open.tiktokapis.com/v2/oauth/token/' => Http::response([
                'data' => [
                    'access_token' => 'single_refreshed_tt_token',
                    'refresh_token' => 'single_refresh_token',
                    'expires_in' => 86400,
                ],
            ], 200),
        ]);

        $service = app(\App\Services\TokenRefreshService::class);

        // Worker 1 refreshes
        $token1 = $service->refreshIfExpired($this->tiktokAccountA);
        // Worker 2 and 3 re-read without calling endpoint again
        $token2 = $service->refreshIfExpired($this->tiktokAccountA);
        $token3 = $service->refreshIfExpired($this->tiktokAccountA);

        $this->assertEquals('single_refreshed_tt_token', $token1);
        $this->assertEquals('single_refreshed_tt_token', $token2);
        $this->assertEquals('single_refreshed_tt_token', $token3);

        // Exactly 1 network request was dispatched to TikTok oauth endpoint
        Http::assertSentCount(1);
    }

    public function test_tiktok_revoked_refresh_token_transitions_to_auth_required(): void
    {
        Event::fake([TikTokAuthRequired::class]);

        config(['services.tiktok.client_key' => 'mock_tt_key']);
        config(['services.tiktok.client_secret' => 'mock_tt_secret']);

        $this->tiktokAccountA->update([
            'token_expires_at' => now()->subMinutes(15),
            'encrypted_access_token' => 'expired_tt_token',
        ]);

        $job = $this->createPublishJob();

        Http::fake([
            'https://open.tiktokapis.com/v2/oauth/token/' => Http::response([
                'error' => 'invalid_grant',
                'message' => 'Refresh token has been revoked by user.',
            ], 400),
            'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response([
                'error' => ['code' => 'access_token_invalid', 'message' => 'Token invalid'],
            ], 401),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('AUTH_REQUIRED', $job->status);
        $this->assertEquals('AUTH_REQUIRED', $job->failure_category);
        Event::assertDispatched(TikTokAuthRequired::class);
    }

    public function test_tiktok_missing_required_scope_fails_with_auth_required(): void
    {
        Event::fake([TikTokAuthRequired::class]);

        $this->tiktokAccountA->update([
            'scopes' => ['user.info.basic'], // missing video.publish
        ]);

        $job = $this->createPublishJob();

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('AUTH_REQUIRED', $job->status);
        $this->assertEquals('AUTH_REQUIRED', $job->failure_category);
        $this->assertStringContainsString('scope', strtolower($job->user_facing_error_message));
        Event::assertDispatched(TikTokAuthRequired::class);
    }

    public function test_tiktok_unsupported_privacy_setting_rejected_against_creator_capabilities(): void
    {
        $job = $this->createPublishJob();
        // Request PUBLIC_TO_EVERYONE
        $job->publishBatch->contentItem->update([
            'platform_overrides' => [
                'tiktok' => ['privacy_level' => 'PUBLIC_TO_EVERYONE'],
            ],
        ]);

        Http::fake([
            // Creator only allows MUTUAL_FOLLOW_FRIENDS and SELF_ONLY
            'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response([
                'data' => [
                    'privacy_level_options' => ['MUTUAL_FOLLOW_FRIENDS', 'SELF_ONLY'],
                    'max_video_post_duration_sec' => 600,
                ],
                'error' => ['code' => 'ok'],
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('FAILED', $job->status);
        $this->assertEquals('CREATOR_CAPABILITY_ERROR', $job->failure_category);
        $this->assertStringContainsString('privacy level', strtolower($job->user_facing_error_message));
    }

    public function test_tiktok_disabled_comments_rejected_when_user_requests_comments(): void
    {
        $job = $this->createPublishJob();
        $job->publishBatch->contentItem->update([
            'platform_overrides' => [
                'tiktok' => [
                    'privacy_level' => 'PUBLIC_TO_EVERYONE',
                    'disable_comment' => false, // User requested comments enabled
                ],
            ],
        ]);

        Http::fake([
            'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response([
                'data' => [
                    'privacy_level_options' => ['PUBLIC_TO_EVERYONE'],
                    'comment_disabled' => true, // Globally disabled by creator!
                    'max_video_post_duration_sec' => 600,
                ],
                'error' => ['code' => 'ok'],
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('FAILED', $job->status);
        $this->assertEquals('CREATOR_CAPABILITY_ERROR', $job->failure_category);
        $this->assertStringContainsString('comments', strtolower($job->user_facing_error_message));
    }

    public function test_tiktok_disabled_duet_rejected_when_user_requests_duet(): void
    {
        $job = $this->createPublishJob();
        $job->publishBatch->contentItem->update([
            'platform_overrides' => [
                'tiktok' => [
                    'privacy_level' => 'PUBLIC_TO_EVERYONE',
                    'disable_duet' => false,
                ],
            ],
        ]);

        Http::fake([
            'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response([
                'data' => [
                    'privacy_level_options' => ['PUBLIC_TO_EVERYONE'],
                    'duet_disabled' => true,
                    'max_video_post_duration_sec' => 600,
                ],
                'error' => ['code' => 'ok'],
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('FAILED', $job->status);
        $this->assertEquals('CREATOR_CAPABILITY_ERROR', $job->failure_category);
        $this->assertStringContainsString('duets', strtolower($job->user_facing_error_message));
    }

    public function test_tiktok_disabled_stitch_rejected_when_user_requests_stitch(): void
    {
        $job = $this->createPublishJob();
        $job->publishBatch->contentItem->update([
            'platform_overrides' => [
                'tiktok' => [
                    'privacy_level' => 'PUBLIC_TO_EVERYONE',
                    'disable_stitch' => false,
                ],
            ],
        ]);

        Http::fake([
            'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response([
                'data' => [
                    'privacy_level_options' => ['PUBLIC_TO_EVERYONE'],
                    'stitch_disabled' => true,
                    'max_video_post_duration_sec' => 600,
                ],
                'error' => ['code' => 'ok'],
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('FAILED', $job->status);
        $this->assertEquals('CREATOR_CAPABILITY_ERROR', $job->failure_category);
        $this->assertStringContainsString('stitches', strtolower($job->user_facing_error_message));
    }

    public function test_tiktok_video_duration_exceeding_creator_limit_rejected(): void
    {
        $this->mediaA->update(['duration_seconds' => 120.0]);
        $job = $this->createPublishJob();

        Http::fake([
            'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response([
                'data' => [
                    'privacy_level_options' => ['PUBLIC_TO_EVERYONE'],
                    'max_video_post_duration_sec' => 60, // Creator only allowed 60s
                ],
                'error' => ['code' => 'ok'],
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('FAILED', $job->status);
        $this->assertEquals('INVALID_MEDIA', $job->failure_category);
        $this->assertStringContainsString('duration', strtolower($job->user_facing_error_message));
    }

    public function test_tiktok_media_under_minimum_duration_fails_with_invalid_media(): void
    {
        $this->mediaA->update(['duration_seconds' => 1.5]); // Under 3.0s!
        $job = $this->createPublishJob();

        Http::fake([
            'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response([
                'data' => [
                    'privacy_level_options' => ['PUBLIC_TO_EVERYONE'],
                    'max_video_post_duration_sec' => 600,
                ],
                'error' => ['code' => 'ok'],
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('FAILED', $job->status);
        $this->assertEquals('INVALID_MEDIA', $job->failure_category);
        $this->assertStringContainsString('3 seconds', $job->user_facing_error_message);
    }

    public function test_tiktok_media_empty_file_fails_with_invalid_media(): void
    {
        $emptyPath = 'videos/empty_tt.mp4';
        Storage::disk(config('filesystems.default', 'public'))->put($emptyPath, '');

        $emptyMedia = MediaAsset::create([
            'workspace_id' => $this->workspaceA->id,
            'filename' => 'empty_tt.mp4',
            'original_name' => 'empty_tt.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 0,
            'storage_path' => $emptyPath,
            'status' => 'READY',
            'duration_seconds' => 5.0,
        ]);

        $job = $this->createPublishJob();
        $job->publishBatch->contentItem->update(['media_asset_id' => $emptyMedia->id]);

        Http::fake([
            'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response([
                'data' => [
                    'privacy_level_options' => ['PUBLIC_TO_EVERYONE'],
                    'max_video_post_duration_sec' => 600,
                ],
                'error' => ['code' => 'ok'],
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('FAILED', $job->status);
        $this->assertEquals('INVALID_MEDIA', $job->failure_category);
    }

    public function test_tiktok_transient_500_error_is_classified_as_retryable_remote_server_error(): void
    {
        $job = $this->createPublishJob();

        Http::fake([
            'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response('Server Error', 503),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('RETRYING', $job->status);
        $this->assertEquals('REMOTE_SERVER_ERROR', $job->failure_category);
    }

    public function test_tiktok_rate_limit_error_is_classified_as_retryable_rate_limited(): void
    {
        $job = $this->createPublishJob();

        Http::fake([
            'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response([
                'error' => ['code' => 'rate_limit_exceeded', 'message' => 'Rate limit exceeded'],
            ], 429),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('RETRYING', $job->status);
        $this->assertEquals('RATE_LIMITED', $job->failure_category);
        $this->assertStringContainsString('rate limit', strtolower($job->user_facing_error_message));
    }

    public function test_tiktok_idempotency_prevents_duplicate_post_if_already_published(): void
    {
        $job = $this->createPublishJob([
            'status' => 'PUBLISHED',
            'remote_post_id' => 'v_pub_already_done',
            'remote_post_url' => 'https://www.tiktok.com/@creator/video/12345678',
        ]);

        Http::fake();

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        Http::assertNothingSent();

        $job->refresh();
        $this->assertEquals('PUBLISHED', $job->status);
    }

    public function test_tiktok_worker_restarts_while_processing_queries_status_without_reuploading(): void
    {
        $job = $this->createPublishJob([
            'status' => 'PROCESSING',
            'remote_post_id' => 'v_pub_restarted_job',
            'remote_post_url' => 'https://www.tiktok.com/@creator_a/video/v_pub_restarted_job',
        ]);

        Http::fake([
            'https://open.tiktokapis.com/v2/post/publish/video/init/' => Http::response('Should not be called', 500),
            'https://open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response([
                'data' => [
                    'status' => 'PUBLISH_COMPLETE',
                    'publicaly_available_post_id' => ['9876543210'],
                ],
                'error' => ['code' => 'ok'],
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('PUBLISHED', $job->status);
        $this->assertEquals('https://www.tiktok.com/@creator_a/video/9876543210', $job->remote_post_url);

        // Assert init endpoint was NEVER called on worker restart
        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return !str_contains($request->url(), '/video/init/');
        });
    }

    public function test_tiktok_duplicate_queue_jobs_prevented_by_distributed_lock(): void
    {
        $job = $this->createPublishJob([
            'status' => 'PROCESSING',
            'remote_post_id' => 'v_pub_lock_test',
        ]);

        $lock = Cache::lock("publish_lock_job_{$job->id}", 60);
        $lock->acquire();

        Http::fake();

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        Http::assertNothingSent();

        $job->refresh();
        $this->assertEquals('PROCESSING', $job->status);

        $lock->release();
    }

    public function test_tiktok_cross_workspace_publishing_prevented_by_tenant_isolation(): void
    {
        // Job in Workspace B attempting to use Workspace A's account and media
        $content = ContentItem::create([
            'workspace_id' => $this->workspaceB->id,
            'media_asset_id' => $this->mediaA->id,
            'global_metadata' => ['title' => 'Exploit attempt'],
            'target_platforms' => ['TIKTOK'],
        ]);

        $batch = PublishBatch::create([
            'workspace_id' => $this->workspaceB->id,
            'content_item_id' => $content->id,
            'status' => 'RUNNING',
        ]);

        $job = PublishJob::create([
            'publish_batch_id' => $batch->id,
            'workspace_id' => $this->workspaceB->id,
            'social_account_id' => $this->tiktokAccountA->id, // Foreign account!
            'platform' => 'TIKTOK',
            'status' => 'QUEUED',
            'attempts_count' => 0,
            'max_attempts' => 3,
        ]);

        Http::fake();

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('FAILED', $job->status);
        $this->assertEquals('AUTHORIZATION_ERROR', $job->failure_category);
        $this->assertStringContainsString('Tenant violation', $job->internal_error_details);

        Http::assertNothingSent();
    }

    public function test_tiktok_cross_workspace_status_check_prevented_by_tenant_isolation(): void
    {
        $job = $this->createPublishJob([
            'status' => 'PROCESSING',
            'remote_post_id' => 'v_pub_foreign_test',
        ]);

        $publisher = new TikTokPublisher();
        $foreignContext = new \App\Platforms\Contracts\PublishContext(
            job: $job,
            account: $this->tiktokAccountB, // Foreign account!
            media: $this->mediaA,
            globalMetadata: [],
            platformOverrides: [],
            decryptedAccessToken: 'foreign_token'
        );

        $this->expectException(\App\Platforms\Exceptions\TikTokPublishException::class);
        $this->expectExceptionMessage('Tenant violation');

        $publisher->getStatus('v_pub_foreign_test', $foreignContext);
    }

    public function test_tiktok_failure_isolation_does_not_affect_youtube(): void
    {
        // Batch with YouTube and TikTok jobs
        $content = ContentItem::create([
            'workspace_id' => $this->workspaceA->id,
            'media_asset_id' => $this->mediaA->id,
            'global_metadata' => ['title' => 'Cross-Platform Batch'],
            'target_platforms' => ['YOUTUBE', 'TIKTOK'],
        ]);

        $batch = PublishBatch::create([
            'workspace_id' => $this->workspaceA->id,
            'content_item_id' => $content->id,
            'status' => 'RUNNING',
        ]);

        $ytJob = PublishJob::create([
            'publish_batch_id' => $batch->id,
            'workspace_id' => $this->workspaceA->id,
            'social_account_id' => $this->youtubeAccountA->id,
            'platform' => 'YOUTUBE',
            'status' => 'QUEUED',
            'attempts_count' => 0,
            'max_attempts' => 3,
        ]);

        $ttJob = PublishJob::create([
            'publish_batch_id' => $batch->id,
            'workspace_id' => $this->workspaceA->id,
            'social_account_id' => $this->tiktokAccountA->id,
            'platform' => 'TIKTOK',
            'status' => 'QUEUED',
            'attempts_count' => 0,
            'max_attempts' => 3,
        ]);

        $ytSession = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=yt_batch_sess';

        Http::fake([
            // YouTube succeeds
            'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status' => Http::response('', 200, ['Location' => $ytSession]),
            $ytSession . '*' => Http::response(['id' => 'yt_batch_success_vid', 'status' => ['uploadStatus' => 'uploaded']], 200),
            'https://www.googleapis.com/youtube/v3/videos*' => Http::response(['items' => [['id' => 'yt_batch_success_vid', 'status' => ['uploadStatus' => 'processed']]]], 200),

            // TikTok fails at creator info
            'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response([
                'data' => [
                    'privacy_level_options' => ['SELF_ONLY'], // rejects public
                ],
                'error' => ['code' => 'ok'],
            ], 200),
        ]);

        // Run YouTube Job
        (new PublishDestinationJob($ytJob->id))->handle(app(PlatformManager::class));
        // Run TikTok Job
        (new PublishDestinationJob($ttJob->id))->handle(app(PlatformManager::class));

        $ytJob->refresh();
        $ttJob->refresh();
        $batch->refresh();

        // YouTube MUST be PUBLISHED
        $this->assertEquals('PUBLISHED', $ytJob->status);
        $this->assertEquals('yt_batch_success_vid', $ytJob->remote_post_id);

        // TikTok MUST be FAILED
        $this->assertEquals('FAILED', $ttJob->status);
        $this->assertEquals('CREATOR_CAPABILITY_ERROR', $ttJob->failure_category);

        // Batch status is PARTIAL_SUCCESS (not FAILED!)
        $this->assertEquals('PARTIAL_SUCCESS', $batch->status);
    }

    public function test_youtube_failure_isolation_does_not_affect_tiktok(): void
    {
        $content = ContentItem::create([
            'workspace_id' => $this->workspaceA->id,
            'media_asset_id' => $this->mediaA->id,
            'global_metadata' => ['title' => 'Cross-Platform Batch 2'],
            'target_platforms' => ['YOUTUBE', 'TIKTOK'],
        ]);

        $batch = PublishBatch::create([
            'workspace_id' => $this->workspaceA->id,
            'content_item_id' => $content->id,
            'status' => 'RUNNING',
        ]);

        $ytJob = PublishJob::create([
            'publish_batch_id' => $batch->id,
            'workspace_id' => $this->workspaceA->id,
            'social_account_id' => $this->youtubeAccountA->id,
            'platform' => 'YOUTUBE',
            'status' => 'QUEUED',
            'attempts_count' => 0,
            'max_attempts' => 3,
        ]);

        $ttJob = PublishJob::create([
            'publish_batch_id' => $batch->id,
            'workspace_id' => $this->workspaceA->id,
            'social_account_id' => $this->tiktokAccountA->id,
            'platform' => 'TIKTOK',
            'status' => 'QUEUED',
            'attempts_count' => 0,
            'max_attempts' => 3,
        ]);

        $ttUploadUrl = 'https://open-upload.tiktokapis.com/video/?upload_id=tt_batch_sess_2';

        Http::fake([
            // YouTube fails (quota exceeded)
            'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status' => Http::response([
                'error' => ['code' => 403, 'errors' => [['reason' => 'quotaExceeded']]],
            ], 403),

            // TikTok succeeds
            'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response([
                'data' => [
                    'privacy_level_options' => ['PUBLIC_TO_EVERYONE'],
                    'max_video_post_duration_sec' => 600,
                ],
                'error' => ['code' => 'ok'],
            ], 200),
            'https://open.tiktokapis.com/v2/post/publish/video/init/' => Http::response([
                'data' => [
                    'publish_id' => 'v_pub_tt_batch_success',
                    'upload_url' => $ttUploadUrl,
                ],
                'error' => ['code' => 'ok'],
            ], 200),
            $ttUploadUrl . '*' => Http::response('', 200),
            'https://open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response([
                'data' => ['status' => 'PUBLISH_COMPLETE', 'publicaly_available_post_id' => ['987111222']],
                'error' => ['code' => 'ok'],
            ], 200),
        ]);

        // Run YouTube Job
        (new PublishDestinationJob($ytJob->id))->handle(app(PlatformManager::class));
        // Run TikTok Job
        (new PublishDestinationJob($ttJob->id))->handle(app(PlatformManager::class));

        $ytJob->refresh();
        $ttJob->refresh();
        $batch->refresh();

        // YouTube is RETRYING
        $this->assertEquals('RETRYING', $ytJob->status);
        $this->assertEquals('QUOTA_EXCEEDED', $ytJob->failure_category);

        // TikTok is PUBLISHED
        $this->assertEquals('PUBLISHED', $ttJob->status);
        $this->assertEquals('v_pub_tt_batch_success', $ttJob->remote_post_id);
    }

    public function test_tiktok_caption_validation_enforces_2200_character_limit(): void
    {
        $publisher = new TikTokPublisher();

        // 1. Caption over 2,200 characters
        $issues = $publisher->validate(['caption' => str_repeat('A', 2201)], []);
        $this->assertNotEmpty($issues);
        $this->assertEquals('caption', $issues[0]['field']);

        // 2. Hashtags over 30
        $issues = $publisher->validate([], [], ['hashtags' => array_fill(0, 31, 'tag')]);
        $this->assertNotEmpty($issues);

        // 3. Valid caption & hashtags
        $issues = $publisher->validate(['caption' => 'Valid TikTok video caption! #cool'], []);
        $this->assertEmpty($issues);
    }

    public function test_tiktok_sensitive_content_or_spam_risk_error_mapped_to_invalid_metadata(): void
    {
        $job = $this->createPublishJob();

        Http::fake([
            'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response([
                'data' => [
                    'privacy_level_options' => ['PUBLIC_TO_EVERYONE'],
                    'max_video_post_duration_sec' => 600,
                ],
                'error' => ['code' => 'ok'],
            ], 200),
            'https://open.tiktokapis.com/v2/post/publish/video/init/' => Http::response([
                'error' => [
                    'code' => 'spam_risk_detected',
                    'message' => 'Spam risk detected in post caption.',
                ],
            ], 422),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('FAILED', $job->status);
        $this->assertEquals('INVALID_METADATA', $job->failure_category);
        $this->assertStringContainsString('spam risk', strtolower($job->user_facing_error_message));
    }

    public function test_tiktok_media_profile_supports_vp8_and_4gb_file_limit(): void
    {
        // 1. VP8 codec is supported
        $this->assertTrue(in_array('vp8', TikTokMediaProfile::SUPPORTED_VIDEO_CODECS, true));

        // 2. 4 GiB max file size
        $this->assertEquals(4294967296, TikTokMediaProfile::MAX_FILE_SIZE_BYTES);

        // 3. Media with 4GB size passes size check
        $media4GB = new MediaAsset([
            'status' => 'READY',
            'size_bytes' => 4294967200,
            'filename' => 'valid_large.mp4',
            'mime_type' => 'video/mp4',
            'duration_seconds' => 120,
            'width' => 1080,
            'height' => 1920,
        ]);
        $issues = TikTokMediaProfile::validate($media4GB);
        $this->assertEmpty($issues);

        // 4. Media exceeding 4GB fails
        $mediaOver4GB = new MediaAsset([
            'status' => 'READY',
            'size_bytes' => 4294967297,
            'filename' => 'too_large.mp4',
            'mime_type' => 'video/mp4',
            'duration_seconds' => 120,
            'width' => 1080,
            'height' => 1920,
        ]);
        $issuesOver = TikTokMediaProfile::validate($mediaOver4GB);
        $this->assertNotEmpty($issuesOver);
        $this->assertEquals('size_bytes', $issuesOver[0]['field']);
    }

    public function test_tiktok_strict_scope_validation_rejects_account_with_only_video_upload(): void
    {
        // Account has video.upload but lacks video.publish
        $this->tiktokAccountA->update(['scopes' => ['video.upload', 'user.info.basic']]);

        $job = $this->createPublishJob();
        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();
        $this->assertEquals('AUTH_REQUIRED', $job->status);
        $this->assertEquals('AUTH_REQUIRED', $job->failure_category);
        $this->assertStringContainsString('video.publish', $job->user_facing_error_message);
    }

    public function test_tiktok_upload_session_expired_resets_session_url(): void
    {
        $job = $this->createPublishJob();
        $job->update([
            'upload_session_url' => 'https://open-upload.tiktokapis.com/video/?upload_id=stale_sess_123',
            'remote_post_id' => 'v_pub_stale_123',
            'status' => 'UPLOADING',
        ]);

        Http::fake([
            'https://open.tiktokapis.com/v2/post/publish/creator_info/query/' => Http::response([
                'data' => [
                    'privacy_level_options' => ['PUBLIC_TO_EVERYONE'],
                    'max_video_post_duration_sec' => 600,
                ],
                'error' => ['code' => 'ok'],
            ], 200),
            'https://open-upload.tiktokapis.com/video/?upload_id=stale_sess_123*' => Http::response('Upload task not found', 404),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();
        // Since UPLOAD_SESSION_EXPIRED is retryable, it transitions to RETRYING and nullifies upload_session_url
        $this->assertEquals('RETRYING', $job->status);
        $this->assertEquals('UPLOAD_SESSION_EXPIRED', $job->failure_category);
        $this->assertNull($job->upload_session_url);
    }

    public function test_tiktok_unaudited_client_restricted_maps_to_clear_app_audit_message(): void
    {
        $job = $this->createPublishJob();
        $job->update([
            'remote_post_id' => 'v_pub_unaudited_456',
            'status' => 'PROCESSING',
        ]);

        Http::fake([
            'https://open.tiktokapis.com/v2/post/publish/status/fetch/' => Http::response([
                'data' => [
                    'status' => 'FAILED',
                    'fail_reason' => 'unaudited_client_restricted_to_private',
                ],
                'error' => ['code' => 'ok'],
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();
        $this->assertEquals('FAILED', $job->status);
        $this->assertEquals('PROVIDER_ERROR', $job->failure_category);
        $this->assertStringContainsString('audit required', strtolower($job->user_facing_error_message));
    }

    public function test_tiktok_chunk_size_calculation_boundaries(): void
    {
        // 1. Files < 5MB (e.g. 1MB) uploaded as single chunk equal to file size
        $oneMB = 1024 * 1024;
        $params1MB = TikTokPublisher::calculateChunkParameters($oneMB);
        $this->assertEquals($oneMB, $params1MB['chunk_size']);
        $this->assertEquals(1, $params1MB['total_chunk_count']);

        // 2. Exactly 5MB uploaded as single chunk
        $fiveMB = 5 * 1024 * 1024;
        $params5MB = TikTokPublisher::calculateChunkParameters($fiveMB);
        $this->assertEquals($fiveMB, $params5MB['chunk_size']);
        $this->assertEquals(1, $params5MB['total_chunk_count']);

        // 3. Exactly 64MB uploaded as single chunk
        $sixtyFourMB = 64 * 1024 * 1024;
        $params64MB = TikTokPublisher::calculateChunkParameters($sixtyFourMB);
        $this->assertEquals($sixtyFourMB, $params64MB['chunk_size']);
        $this->assertEquals(1, $params64MB['total_chunk_count']);

        // 4. > 64MB (e.g. 100MB) chunked into multiple chunks between 5MB and 64MB
        $hundredMB = 100 * 1024 * 1024;
        $params100MB = TikTokPublisher::calculateChunkParameters($hundredMB);
        $this->assertGreaterThanOrEqual(5 * 1024 * 1024, $params100MB['chunk_size']);
        $this->assertLessThanOrEqual(64 * 1024 * 1024, $params100MB['chunk_size']);
        $this->assertGreaterThan(1, $params100MB['total_chunk_count']);
        $this->assertLessThanOrEqual(1000, $params100MB['total_chunk_count']);

        // 5. 4GB file must not exceed 1000 chunks limit
        $fourGB = 4294967296;
        $params4GB = TikTokPublisher::calculateChunkParameters($fourGB);
        $this->assertLessThanOrEqual(1000, $params4GB['total_chunk_count']);
        $this->assertGreaterThanOrEqual(5 * 1024 * 1024, $params4GB['chunk_size']);
        $this->assertLessThanOrEqual(64 * 1024 * 1024, $params4GB['chunk_size']);
    }
}
