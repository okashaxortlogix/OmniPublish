<?php

namespace Tests\Feature;

use App\Events\InstagramAccountValidated;
use App\Events\InstagramAuthRequired;
use App\Events\InstagramMediaContainerCreated;
use App\Events\InstagramMediaProcessingStarted;
use App\Events\InstagramPublishingCompleted;
use App\Events\InstagramPublishingFailed;
use App\Events\InstagramPublishingStarted;
use App\Jobs\PublishDestinationJob;
use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Models\PublishBatch;
use App\Models\PublishJob;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Platforms\Adapters\InstagramPublisher;
use App\Platforms\Profiles\InstagramMediaProfile;
use App\Platforms\Services\PlatformManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\MediaFixtureGenerator;
use Tests\TestCase;

class InstagramPublishingTest extends TestCase
{
    use RefreshDatabase;

    protected Workspace $workspaceA;
    protected Workspace $workspaceB;
    protected SocialAccount $instagramAccountA;
    protected SocialAccount $instagramAccountB;
    protected SocialAccount $youtubeAccountA;
    protected SocialAccount $tiktokAccountA;
    protected MediaAsset $mediaVideoA;
    protected MediaAsset $mediaImageA;

    protected function setUp(): void
    {
        parent::setUp();

        $userA = \App\Models\User::create([
            'name' => 'Meta Creator A',
            'email' => 'meta_creator_a_' . uniqid() . '@test.com',
            'password' => bcrypt('secret123'),
        ]);

        $userB = \App\Models\User::create([
            'name' => 'Meta Creator B',
            'email' => 'meta_creator_b_' . uniqid() . '@test.com',
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

        $this->instagramAccountA = SocialAccount::create([
            'workspace_id'           => $this->workspaceA->id,
            'platform'               => 'INSTAGRAM',
            'platform_account_id'    => '17841400000000001',
            'account_name'           => 'Instagram Creator A',
            'account_handle'         => '@creator_a',
            'status'                 => 'ACTIVE',
            'scopes'                 => ['instagram_content_publish', 'instagram_basic', 'pages_show_list'],
            'encrypted_access_token' => 'valid_ig_access_token_a',
            'token_expires_at'       => now()->addDays(60),
            'metadata'               => ['account_type' => 'BUSINESS'],
        ]);

        $this->instagramAccountB = SocialAccount::create([
            'workspace_id'           => $this->workspaceB->id,
            'platform'               => 'INSTAGRAM',
            'platform_account_id'    => '17841400000000002',
            'account_name'           => 'Instagram Creator B',
            'account_handle'         => '@creator_b',
            'status'                 => 'ACTIVE',
            'scopes'                 => ['instagram_content_publish', 'instagram_basic'],
            'encrypted_access_token' => 'valid_ig_access_token_b',
            'token_expires_at'       => now()->addDays(60),
            'metadata'               => ['account_type' => 'BUSINESS'],
        ]);

        $this->youtubeAccountA = SocialAccount::create([
            'workspace_id'           => $this->workspaceA->id,
            'platform'               => 'YOUTUBE',
            'platform_account_id'    => 'UC_yt_channel_a',
            'account_name'           => 'YouTube Channel A',
            'status'                 => 'ACTIVE',
            'scopes'                 => ['https://www.googleapis.com/auth/youtube.upload'],
            'encrypted_access_token' => 'valid_yt_token_a',
            'token_expires_at'       => now()->addHour(),
        ]);

        $this->tiktokAccountA = SocialAccount::create([
            'workspace_id'           => $this->workspaceA->id,
            'platform'               => 'TIKTOK',
            'platform_account_id'    => 'tt_open_id_a',
            'account_name'           => 'TikTok Account A',
            'account_handle'         => '@tiktok_a',
            'status'                 => 'ACTIVE',
            'scopes'                 => ['video.publish', 'user.info.basic'],
            'encrypted_access_token' => 'valid_tt_token_a',
            'token_expires_at'       => now()->addHour(),
        ]);

        // Generate synthetic test video (duration 4 seconds, size 720x1280 vertical Reel)
        $fixturePath = storage_path('app/temp/ig_test_fixture.mp4');
        MediaFixtureGenerator::createValidVideo($fixturePath, 4, 720, 1280);
        $fixtureBytes = file_get_contents($fixturePath);

        $disk = config('filesystems.default', 'public');
        Storage::disk($disk)->put('videos/test_instagram.mp4', $fixtureBytes);

        $this->mediaVideoA = MediaAsset::create([
            'workspace_id'     => $this->workspaceA->id,
            'filename'         => 'test_instagram.mp4',
            'original_name'    => 'test_instagram.mp4',
            'mime_type'        => 'video/mp4',
            'size_bytes'       => strlen($fixtureBytes),
            'storage_path'     => 'videos/test_instagram.mp4',
            'url'              => 'https://omnipublish-media.s3.amazonaws.com/videos/test_instagram.mp4',
            'status'           => 'READY',
            'duration_seconds' => 4.0,
            'width'            => 720,
            'height'           => 1280,
        ]);

        // Generate synthetic test image (valid JPEG bytes without relying on GD extension)
        $imageBytes = base64_decode('/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=');

        Storage::disk($disk)->put('images/test_instagram.jpg', $imageBytes);

        $this->mediaImageA = MediaAsset::create([
            'workspace_id'     => $this->workspaceA->id,
            'filename'         => 'test_instagram.jpg',
            'original_name'    => 'test_instagram.jpg',
            'mime_type'        => 'image/jpeg',
            'size_bytes'       => strlen($imageBytes),
            'storage_path'     => 'images/test_instagram.jpg',
            'url'              => 'https://omnipublish-media.s3.amazonaws.com/images/test_instagram.jpg',
            'status'           => 'READY',
            'duration_seconds' => 0.0,
            'width'            => 1080,
            'height'           => 1080,
        ]);
    }

    protected function createPublishJob(array $overrides = [], ?MediaAsset $media = null): PublishJob
    {
        $targetMedia = $media ?? $this->mediaVideoA;

        $content = ContentItem::create([
            'workspace_id'       => $this->workspaceA->id,
            'media_asset_id'     => $targetMedia->id,
            'global_metadata'    => [
                'title'       => 'Mastering Creative Production',
                'description' => 'Behind the scenes of our creative studio workflow! #creators #production',
                'caption'     => 'Behind the scenes of our creative studio workflow! #creators #production',
                'hashtags'    => ['creators', 'production'],
            ],
            'platform_overrides' => array_merge([
                'instagram' => [
                    'caption'     => 'Exclusive Reel! Behind the scenes of our studio workflow! #creators',
                    'shareToFeed' => true,
                ],
            ], $overrides),
            'target_platforms'   => ['INSTAGRAM'],
            'status'             => 'SCHEDULED',
        ]);

        $batch = PublishBatch::create([
            'workspace_id'     => $this->workspaceA->id,
            'content_item_id'  => $content->id,
            'target_platforms' => ['INSTAGRAM'],
            'status'           => 'QUEUED',
        ]);

        return PublishJob::create([
            'publish_batch_id'  => $batch->id,
            'workspace_id'      => $this->workspaceA->id,
            'social_account_id' => $this->instagramAccountA->id,
            'platform'          => 'INSTAGRAM',
            'status'            => 'QUEUED',
            'attempts_count'    => 0,
            'max_attempts'      => 3,
        ]);
    }

    // ==========================================
    // 1. HAPPY PATH: REELS PUBLISHING FLOW
    // ==========================================

    public function test_instagram_reels_publishing_happy_path(): void
    {
        Event::fake([
            InstagramPublishingStarted::class,
            InstagramAccountValidated::class,
            InstagramMediaContainerCreated::class,
            InstagramPublishingCompleted::class,
        ]);

        $job = $this->createPublishJob();

        Http::fake([
            // 1. Account eligibility verification
            'https://graph.facebook.com/v21.0/17841400000000001?*' => Http::response([
                'account_type' => 'BUSINESS',
                'username'     => 'creator_a',
                'name'         => 'Instagram Creator A',
                'id'           => '17841400000000001',
            ], 200),

            // 2. Container creation
            'https://graph.facebook.com/v21.0/17841400000000001/media' => Http::response([
                'id' => '17999888777666555',
            ], 200),

            // 3. Container status check (transcoding finished)
            'https://graph.facebook.com/v21.0/17999888777666555*' => Http::response([
                'status_code' => 'FINISHED',
                'status'      => 'READY',
                'id'          => '17999888777666555',
            ], 200),

            // 4. Media publish
            'https://graph.facebook.com/v21.0/17841400000000001/media_publish' => Http::response([
                'id' => '18123456789012345',
            ], 200),

            // 5. Permlink fetch
            'https://graph.facebook.com/v21.0/18123456789012345*' => Http::response([
                'id'         => '18123456789012345',
                'permalink'  => 'https://www.instagram.com/reel/C89abcXYZ/',
                'media_type' => 'REELS',
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('PUBLISHED', $job->status);
        $this->assertEquals('18123456789012345', $job->remote_post_id);
        $this->assertEquals('https://www.instagram.com/reel/C89abcXYZ/', $job->remote_post_url);
        $this->assertNull($job->upload_session_url);

        Event::assertDispatched(InstagramPublishingStarted::class);
        Event::assertDispatched(InstagramAccountValidated::class);
        Event::assertDispatched(InstagramMediaContainerCreated::class);
        Event::assertDispatched(InstagramPublishingCompleted::class);
    }

    // ==========================================
    // 2. HAPPY PATH: IMAGE PUBLISHING FLOW
    // ==========================================

    public function test_instagram_image_publishing_happy_path(): void
    {
        Event::fake([
            InstagramPublishingStarted::class,
            InstagramPublishingCompleted::class,
        ]);

        $job = $this->createPublishJob([], $this->mediaImageA);

        Http::fake([
            'https://graph.facebook.com/v21.0/17841400000000001?*' => Http::response([
                'account_type' => 'BUSINESS',
                'username'     => 'creator_a',
            ], 200),

            // Image Container creation (passes image_url)
            'https://graph.facebook.com/v21.0/17841400000000001/media' => function ($request) {
                $this->assertArrayHasKey('image_url', $request->data());
                return Http::response(['id' => '17999888777111222'], 200);
            },

            'https://graph.facebook.com/v21.0/17999888777111222*' => Http::response([
                'status_code' => 'FINISHED',
            ], 200),

            'https://graph.facebook.com/v21.0/17841400000000001/media_publish' => Http::response([
                'id' => '18999888777333444',
            ], 200),

            'https://graph.facebook.com/v21.0/18999888777333444*' => Http::response([
                'id'        => '18999888777333444',
                'permalink' => 'https://www.instagram.com/p/C99abcImage/',
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('PUBLISHED', $job->status);
        $this->assertEquals('18999888777333444', $job->remote_post_id);
        $this->assertEquals('https://www.instagram.com/p/C99abcImage/', $job->remote_post_url);

        Event::assertDispatched(InstagramPublishingStarted::class, function ($e) {
            return $e->mediaType === 'IMAGE';
        });
        Event::assertDispatched(InstagramPublishingCompleted::class);
    }

    // ==========================================
    // 3. ASYNC TRANSCODING IN_PROGRESS & POLLING
    // ==========================================

    public function test_instagram_stays_in_processing_when_transcoding_in_progress(): void
    {
        Queue::fake([PublishDestinationJob::class]);
        Event::fake([InstagramMediaProcessingStarted::class]);

        $job = $this->createPublishJob();

        Http::fake([
            'https://graph.facebook.com/v21.0/17841400000000001?*' => Http::response([
                'account_type' => 'BUSINESS',
            ], 200),

            'https://graph.facebook.com/v21.0/17841400000000001/media' => Http::response([
                'id' => '17999888777666999',
            ], 200),

            // Container still transcoding
            'https://graph.facebook.com/v21.0/17999888777666999*' => Http::response([
                'status_code' => 'IN_PROGRESS',
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('PROCESSING', $job->status);
        $this->assertEquals('17999888777666999', $job->upload_session_url);
        $this->assertEquals('17999888777666999', $job->remote_post_id);

        Event::assertDispatched(InstagramMediaProcessingStarted::class);

        Queue::assertPushed(PublishDestinationJob::class, function ($pushedJob) use ($job) {
            return $pushedJob->publishJobId === $job->id;
        });
    }

    public function test_instagram_delayed_poll_transitions_to_published_when_finished(): void
    {
        $job = $this->createPublishJob();
        $job->update([
            'status'             => 'PROCESSING',
            'upload_session_url' => '17999888777666999',
            'remote_post_id'     => '17999888777666999',
        ]);

        Http::fake([
            // Container check returns FINISHED
            'https://graph.facebook.com/v21.0/17999888777666999*' => Http::response([
                'status_code' => 'FINISHED',
            ], 200),

            // Publish media
            'https://graph.facebook.com/v21.0/17841400000000001/media_publish' => Http::response([
                'id' => '18555666777888999',
            ], 200),

            // Permlink
            'https://graph.facebook.com/v21.0/18555666777888999*' => Http::response([
                'id'        => '18555666777888999',
                'permalink' => 'https://www.instagram.com/reel/C99FinalReel/',
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('PUBLISHED', $job->status);
        $this->assertEquals('18555666777888999', $job->remote_post_id);
        $this->assertEquals('https://www.instagram.com/reel/C99FinalReel/', $job->remote_post_url);
    }

    public function test_instagram_transcoding_error_transitions_to_failed(): void
    {
        $job = $this->createPublishJob();
        $job->update([
            'status'             => 'PROCESSING',
            'upload_session_url' => '17999888777666999',
            'remote_post_id'     => '17999888777666999',
        ]);

        Http::fake([
            'https://graph.facebook.com/v21.0/17999888777666999*' => Http::response([
                'status_code' => 'ERROR',
                'status'      => 'Video corrupted or invalid audio track.',
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('FAILED', $job->status);
        $this->assertEquals('MEDIA_PROCESSING_FAILED', $job->failure_category);
        $this->assertStringContainsString('Video corrupted', $job->user_facing_error_message);
    }

    // ==========================================
    // 4. ACCOUNT ELIGIBILITY & PERMISSIONS
    // ==========================================

    public function test_instagram_rejects_personal_account_with_account_not_eligible(): void
    {
        $job = $this->createPublishJob();

        Http::fake([
            'https://graph.facebook.com/v21.0/17841400000000001?*' => Http::response([
                'account_type' => 'PERSONAL',
                'username'     => 'personal_user',
                'id'           => '17841400000000001',
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('FAILED', $job->status);
        $this->assertEquals('ACCOUNT_NOT_ELIGIBLE', $job->failure_category);
        $this->assertStringContainsString('Professional (Business or Creator)', $job->user_facing_error_message);
    }

    public function test_instagram_missing_content_publish_scope_transitions_to_auth_required(): void
    {
        $this->instagramAccountA->update(['scopes' => ['instagram_basic']]);

        $job = $this->createPublishJob();
        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('AUTH_REQUIRED', $job->status);
        $this->assertEquals('AUTH_REQUIRED', $job->failure_category);
        $this->assertStringContainsString('instagram_content_publish', $job->user_facing_error_message);
    }

    public function test_instagram_inactive_account_fails_with_auth_required(): void
    {
        $this->instagramAccountA->update(['status' => 'INACTIVE']);

        $job = $this->createPublishJob();
        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('AUTH_REQUIRED', $job->status);
        $this->assertEquals('AUTH_REQUIRED', $job->failure_category);
        $this->assertStringContainsString('Reconnect required', $job->user_facing_error_message);
    }

    // ==========================================
    // 5. TOKEN REFRESH & CONCURRENCY
    // ==========================================

    public function test_instagram_expired_token_auto_refreshes_before_publish(): void
    {
        config(['services.facebook.client_id' => 'meta_app_123']);
        config(['services.facebook.client_secret' => 'meta_secret_456']);

        $this->instagramAccountA->update([
            'encrypted_access_token' => 'expired_token',
            'token_expires_at'       => now()->subMinutes(10),
        ]);

        $job = $this->createPublishJob();

        Http::fake([
            // 1. Token Refresh
            'https://graph.facebook.com/v21.0/oauth/access_token*' => Http::response([
                'access_token' => 'fresh_refreshed_meta_token',
                'expires_in'   => 5184000, // 60 days
            ], 200),

            // 2. Account Check
            'https://graph.facebook.com/v21.0/17841400000000001?*' => Http::response([
                'account_type' => 'BUSINESS',
            ], 200),

            // 3. Media container
            'https://graph.facebook.com/v21.0/17841400000000001/media' => function ($req) {
                $this->assertEquals('fresh_refreshed_meta_token', $req->data()['access_token']);
                return Http::response(['id' => '17999888777000111'], 200);
            },

            'https://graph.facebook.com/v21.0/17999888777000111*' => Http::response([
                'status_code' => 'FINISHED',
            ], 200),

            'https://graph.facebook.com/v21.0/17841400000000001/media_publish' => Http::response([
                'id' => '18999888777000222',
            ], 200),

            'https://graph.facebook.com/v21.0/18999888777000222*' => Http::response([
                'id'        => '18999888777000222',
                'permalink' => 'https://www.instagram.com/reel/C89Refreshed/',
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();
        $this->assertEquals('PUBLISHED', $job->status);

        $this->instagramAccountA->refresh();
        $this->assertEquals('fresh_refreshed_meta_token', $this->instagramAccountA->encrypted_access_token);
    }

    public function test_instagram_token_refresh_concurrency_lock_prevents_duplicate_refresh(): void
    {
        config(['services.facebook.client_id' => 'meta_app_123']);
        config(['services.facebook.client_secret' => 'meta_secret_456']);

        $lockKey = "token_refresh_lock_{$this->instagramAccountA->id}";
        $lock = Cache::lock($lockKey, 20);
        $this->assertTrue($lock->acquire());

        $this->instagramAccountA->update([
            'encrypted_access_token' => 'parallel_token',
            'token_expires_at'       => now()->subMinutes(5),
        ]);

        // Second worker attempts refresh while lock is held
        $tokenService = app(\App\Services\TokenRefreshService::class);
        $resolvedToken = $tokenService->refreshIfExpired($this->instagramAccountA);

        $this->assertEquals('parallel_token', $resolvedToken);

        $lock->release();
    }

    public function test_instagram_revoked_token_transitions_to_auth_required(): void
    {
        $job = $this->createPublishJob();

        Http::fake([
            'https://graph.facebook.com/v21.0/17841400000000001?*' => Http::response([
                'error' => [
                    'message' => 'Error validating access token: Session has been invalidated.',
                    'type'    => 'OAuthException',
                    'code'    => 190,
                    'error_subcode' => 463,
                ],
            ], 401),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('AUTH_REQUIRED', $job->status);
        $this->assertEquals('AUTH_REQUIRED', $job->failure_category);
        $this->assertStringContainsString('Instagram authorization expired', $job->user_facing_error_message);
    }

    // ==========================================
    // 6. MEDIA CONSTRAINTS & VALIDATION
    // ==========================================

    public function test_instagram_video_duration_under_3_seconds_fails(): void
    {
        $shortMedia = MediaAsset::create([
            'workspace_id'     => $this->workspaceA->id,
            'filename'         => 'short_video.mp4',
            'original_name'    => 'short_video.mp4',
            'mime_type'        => 'video/mp4',
            'size_bytes'       => 50000,
            'storage_path'     => 'videos/short_video.mp4',
            'status'           => 'READY',
            'duration_seconds' => 2.0,
            'width'            => 720,
            'height'           => 1280,
        ]);

        $job = $this->createPublishJob([], $shortMedia);
        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();
        $this->assertEquals('FAILED', $job->status);
        $this->assertEquals('INVALID_MEDIA', $job->failure_category);
        $this->assertStringContainsString('shorter than Instagram minimum', $job->user_facing_error_message);
    }

    public function test_instagram_video_duration_exceeding_15_minutes_fails(): void
    {
        $longMedia = MediaAsset::create([
            'workspace_id'     => $this->workspaceA->id,
            'filename'         => 'long_video.mp4',
            'original_name'    => 'long_video.mp4',
            'mime_type'        => 'video/mp4',
            'size_bytes'       => 50000,
            'storage_path'     => 'videos/long_video.mp4',
            'status'           => 'READY',
            'duration_seconds' => 950.0,
            'width'            => 720,
            'height'           => 1280,
        ]);

        $job = $this->createPublishJob([], $longMedia);
        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();
        $this->assertEquals('FAILED', $job->status);
        $this->assertEquals('INVALID_MEDIA', $job->failure_category);
        $this->assertStringContainsString('exceeds Instagram Reels maximum', $job->user_facing_error_message);
    }

    public function test_instagram_oversized_image_fails_validation(): void
    {
        $largeImage = MediaAsset::create([
            'workspace_id'     => $this->workspaceA->id,
            'filename'         => 'too_big.jpg',
            'original_name'    => 'too_big.jpg',
            'mime_type'        => 'image/jpeg',
            'size_bytes'       => 9000000, // 9 MB (limit is 8MB)
            'storage_path'     => 'images/too_big.jpg',
            'status'           => 'READY',
            'duration_seconds' => 0.0,
            'width'            => 1080,
            'height'           => 1080,
        ]);

        $job = $this->createPublishJob([], $largeImage);
        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();
        $this->assertEquals('FAILED', $job->status);
        $this->assertEquals('INVALID_MEDIA', $job->failure_category);
        $this->assertStringContainsString('exceeds Instagram maximum limit of 8 MB', $job->user_facing_error_message);
    }

    public function test_instagram_video_exceeding_1gb_fails_validation(): void
    {
        $oversizedVideo = MediaAsset::create([
            'workspace_id'     => $this->workspaceA->id,
            'filename'         => 'oversized.mp4',
            'original_name'    => 'oversized.mp4',
            'mime_type'        => 'video/mp4',
            'size_bytes'       => 1073741825, // 1GB + 1 byte
            'storage_path'     => 'videos/oversized.mp4',
            'status'           => 'READY',
            'duration_seconds' => 60.0,
            'width'            => 720,
            'height'           => 1280,
        ]);

        $issues = InstagramMediaProfile::validate($oversizedVideo, 'REELS');
        $this->assertNotEmpty($issues);
        $this->assertEquals('size_bytes', $issues[0]['field']);
    }

    // ==========================================
    // 7. RETRY & RATE LIMITS
    // ==========================================

    public function test_instagram_transient_500_error_is_classified_as_retryable_remote_server_error(): void
    {
        $job = $this->createPublishJob();

        Http::fake([
            'https://graph.facebook.com/v21.0/17841400000000001?*' => Http::response([
                'account_type' => 'BUSINESS',
            ], 200),
            'https://graph.facebook.com/v21.0/17841400000000001/media' => Http::response('Internal Server Error', 500),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('RETRYING', $job->status);
        $this->assertEquals('REMOTE_SERVER_ERROR', $job->failure_category);
        $this->assertEquals(1, $job->attempts_count);
    }

    public function test_instagram_rate_limit_error_is_classified_as_retryable_rate_limited(): void
    {
        $job = $this->createPublishJob();

        Http::fake([
            'https://graph.facebook.com/v21.0/17841400000000001?*' => Http::response([
                'account_type' => 'BUSINESS',
            ], 200),
            'https://graph.facebook.com/v21.0/17841400000000001/media' => Http::response([
                'error' => [
                    'message' => 'User request limit reached',
                    'code'    => 17,
                ],
            ], 429),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('RETRYING', $job->status);
        $this->assertEquals('RATE_LIMITED', $job->failure_category);
    }

    public function test_instagram_container_expired_is_retryable_and_clears_container_id(): void
    {
        $job = $this->createPublishJob();
        $job->update([
            'status'             => 'PROCESSING',
            'upload_session_url' => '17999888777expired',
            'remote_post_id'     => '17999888777expired',
        ]);

        Http::fake([
            'https://graph.facebook.com/v21.0/17999888777expired*' => Http::response([
                'status_code' => 'EXPIRED',
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('RETRYING', $job->status);
        $this->assertEquals('CONTAINER_EXPIRED', $job->failure_category);
        $this->assertNull($job->upload_session_url);
        $this->assertNull($job->remote_post_id);
    }

    // ==========================================
    // 8. IDEMPOTENCY & CRASH RECOVERY
    // ==========================================

    public function test_instagram_idempotency_prevents_duplicate_post_if_already_published(): void
    {
        $job = $this->createPublishJob();
        $job->update([
            'status'          => 'PUBLISHED',
            'remote_post_id'  => '18123456789099999',
            'remote_post_url' => 'https://www.instagram.com/reel/C89AlreadyPub/',
        ]);

        Http::fake();

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();
        $this->assertEquals('PUBLISHED', $job->status);
        $this->assertEquals('18123456789099999', $job->remote_post_id);

        Http::assertNothingSent();
    }

    public function test_instagram_worker_restart_reconciles_existing_container(): void
    {
        $job = $this->createPublishJob();
        $job->update([
            'status'             => 'PROCESSING',
            'upload_session_url' => '17999888777666555',
            'remote_post_id'     => '17999888777666555',
        ]);

        Http::fake([
            // Container finished transcoding
            'https://graph.facebook.com/v21.0/17999888777666555*' => Http::response([
                'status_code' => 'FINISHED',
            ], 200),

            'https://graph.facebook.com/v21.0/17841400000000001/media_publish' => Http::response([
                'id' => '18123456789055555',
            ], 200),

            'https://graph.facebook.com/v21.0/18123456789055555*' => Http::response([
                'id'        => '18123456789055555',
                'permalink' => 'https://www.instagram.com/reel/C89Reconciled/',
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();
        $this->assertEquals('PUBLISHED', $job->status);
        $this->assertEquals('18123456789055555', $job->remote_post_id);

        // Verify media container creation was NOT re-called
        Http::assertNotSent(function ($request) {
            return $request->url() === 'https://graph.facebook.com/v21.0/17841400000000001/media';
        });
    }

    public function test_instagram_duplicate_queue_jobs_prevented_by_distributed_lock(): void
    {
        $job = $this->createPublishJob();
        $lockKey = "publish_lock_job_{$job->id}";

        $lock = Cache::lock($lockKey, 300);
        $this->assertTrue($lock->acquire());

        Http::fake();

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();
        // Since lock was held by another worker, job status remains untouched
        $this->assertEquals('QUEUED', $job->status);

        Http::assertNothingSent();
        $lock->release();
    }

    // ==========================================
    // 9. MULTI-TENANCY & ZERO-TRUST ISOLATION
    // ==========================================

    public function test_instagram_cross_workspace_publishing_prevented_by_tenant_isolation(): void
    {
        // Job belongs to Workspace A, but targets Account B belonging to Workspace B
        $job = $this->createPublishJob();
        $job->update([
            'social_account_id' => $this->instagramAccountB->id,
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('FAILED', $job->status);
        $this->assertEquals('AUTHORIZATION_ERROR', $job->failure_category);
        $this->assertStringContainsString('Tenant violation', $job->user_facing_error_message);
    }

    public function test_instagram_cross_workspace_media_prevented_by_tenant_isolation(): void
    {
        // Media belongs to Workspace B
        $mediaB = MediaAsset::create([
            'workspace_id'     => $this->workspaceB->id,
            'filename'         => 'foreign.mp4',
            'original_name'    => 'foreign.mp4',
            'mime_type'        => 'video/mp4',
            'size_bytes'       => 50000,
            'storage_path'     => 'videos/foreign.mp4',
            'status'           => 'READY',
            'duration_seconds' => 4.0,
            'width'            => 720,
            'height'           => 1280,
        ]);

        $job = $this->createPublishJob([], $mediaB);
        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(PlatformManager::class));

        $job->refresh();

        $this->assertEquals('FAILED', $job->status);
        $this->assertEquals('AUTHORIZATION_ERROR', $job->failure_category);
        $this->assertStringContainsString('Media asset does not belong to job workspace', $job->user_facing_error_message);
    }

    // ==========================================
    // 10. PLATFORM ISOLATION: MULTI-PLATFORM
    // ==========================================

    public function test_instagram_failure_isolation_does_not_affect_youtube_or_tiktok(): void
    {
        $content = ContentItem::create([
            'workspace_id'     => $this->workspaceA->id,
            'media_asset_id'   => $this->mediaVideoA->id,
            'global_metadata'  => ['title' => 'Multi-Platform Broadcast'],
            'target_platforms' => ['INSTAGRAM', 'YOUTUBE'],
            'status'           => 'SCHEDULED',
        ]);

        $batch = PublishBatch::create([
            'workspace_id'     => $this->workspaceA->id,
            'content_item_id'  => $content->id,
            'target_platforms' => ['INSTAGRAM', 'YOUTUBE'],
            'status'           => 'QUEUED',
        ]);

        // Instagram Job (will fail with expired token)
        $igJob = PublishJob::create([
            'publish_batch_id'  => $batch->id,
            'workspace_id'      => $this->workspaceA->id,
            'social_account_id' => $this->instagramAccountA->id,
            'platform'          => 'INSTAGRAM',
            'status'            => 'QUEUED',
            'attempts_count'    => 0,
            'max_attempts'      => 3,
        ]);

        // YouTube Job (will succeed)
        $ytJob = PublishJob::create([
            'publish_batch_id'  => $batch->id,
            'workspace_id'      => $this->workspaceA->id,
            'social_account_id' => $this->youtubeAccountA->id,
            'platform'          => 'YOUTUBE',
            'status'            => 'QUEUED',
            'attempts_count'    => 0,
            'max_attempts'      => 3,
        ]);

        Http::fake([
            // Instagram fails with 401
            'https://graph.facebook.com/v21.0/17841400000000001?*' => Http::response([
                'error' => ['message' => 'Token Expired', 'code' => 190],
            ], 401),

            // YouTube succeeds
            'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable*' => Http::response('', 200, [
                'Location' => 'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=yt_sess_batch',
            ]),
            'https://www.googleapis.com/upload/youtube/v3/videos?upload_id=yt_sess_batch*' => Http::response([
                'id' => 'yt_batch_success_video',
            ], 200),
            'https://www.googleapis.com/youtube/v3/videos?*id=yt_batch_success_video*' => Http::response([
                'items' => [[
                    'id' => 'yt_batch_success_video',
                    'status' => ['uploadStatus' => 'uploaded'],
                    'processingDetails' => ['processingStatus' => 'succeeded'],
                ]],
            ], 200),
        ]);

        // Execute Instagram job
        (new PublishDestinationJob($igJob->id))->handle(app(PlatformManager::class));
        // Execute YouTube job
        (new PublishDestinationJob($ytJob->id))->handle(app(PlatformManager::class));

        $igJob->refresh();
        $ytJob->refresh();

        // Instagram failed
        $this->assertEquals('AUTH_REQUIRED', $igJob->status);
        // YouTube succeeded
        $this->assertEquals('PUBLISHED', $ytJob->status);
        $this->assertEquals('yt_batch_success_video', $ytJob->remote_post_id);
    }

    public function test_youtube_failure_isolation_does_not_affect_instagram(): void
    {
        $content = ContentItem::create([
            'workspace_id'     => $this->workspaceA->id,
            'media_asset_id'   => $this->mediaVideoA->id,
            'global_metadata'  => ['title' => 'Multi-Platform Broadcast 2'],
            'target_platforms' => ['YOUTUBE', 'INSTAGRAM'],
            'status'           => 'SCHEDULED',
        ]);

        $batch = PublishBatch::create([
            'workspace_id'     => $this->workspaceA->id,
            'content_item_id'  => $content->id,
            'target_platforms' => ['YOUTUBE', 'INSTAGRAM'],
            'status'           => 'QUEUED',
        ]);

        $ytJob = PublishJob::create([
            'publish_batch_id'  => $batch->id,
            'workspace_id'      => $this->workspaceA->id,
            'social_account_id' => $this->youtubeAccountA->id,
            'platform'          => 'YOUTUBE',
            'status'            => 'QUEUED',
            'attempts_count'    => 0,
            'max_attempts'      => 3,
        ]);

        $igJob = PublishJob::create([
            'publish_batch_id'  => $batch->id,
            'workspace_id'      => $this->workspaceA->id,
            'social_account_id' => $this->instagramAccountA->id,
            'platform'          => 'INSTAGRAM',
            'status'            => 'QUEUED',
            'attempts_count'    => 0,
            'max_attempts'      => 3,
        ]);

        Http::fake([
            // YouTube quota exceeded
            'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable*' => Http::response([
                'error' => [
                    'errors'  => [['reason' => 'quotaExceeded']],
                    'message' => 'Quota exceeded',
                ],
            ], 403),

            // Instagram succeeds
            'https://graph.facebook.com/v21.0/17841400000000001?*' => Http::response([
                'account_type' => 'BUSINESS',
            ], 200),
            'https://graph.facebook.com/v21.0/17841400000000001/media' => Http::response(['id' => '17999888777000999'], 200),
            'https://graph.facebook.com/v21.0/17999888777000999*' => Http::response(['status_code' => 'FINISHED'], 200),
            'https://graph.facebook.com/v21.0/17841400000000001/media_publish' => Http::response(['id' => '18999888777000999'], 200),
            'https://graph.facebook.com/v21.0/18999888777000999*' => Http::response(['id' => '18999888777000999', 'permalink' => 'https://instagram.com/reel/C89Ok/'], 200),
        ]);

        // Execute YouTube
        (new PublishDestinationJob($ytJob->id))->handle(app(PlatformManager::class));
        // Execute Instagram
        (new PublishDestinationJob($igJob->id))->handle(app(PlatformManager::class));

        $ytJob->refresh();
        $igJob->refresh();

        $this->assertEquals('RETRYING', $ytJob->status);
        $this->assertEquals('QUOTA_EXCEEDED', $ytJob->failure_category);

        $this->assertEquals('PUBLISHED', $igJob->status);
        $this->assertEquals('18999888777000999', $igJob->remote_post_id);
    }

    // ==========================================
    // 11. METADATA VALIDATION
    // ==========================================

    // ==========================================
    // 12. COMPLIANCE & RE-AUDIT TESTS
    // ==========================================

    public function test_instagram_publishing_limit_quota_reached_triggers_rate_limit(): void
    {
        Queue::fake();

        $job = $this->createPublishJob();

        Http::fake([
            'https://graph.facebook.com/v21.0/17841400000000001?*' => Http::response([
                'id'           => '17841400000000001',
                'account_type' => 'BUSINESS',
            ], 200),
            'https://graph.facebook.com/v21.0/17841400000000001/content_publishing_limit*' => Http::response([
                'data' => [
                    [
                        'config' => [
                            'quota_total'    => 100,
                            'quota_duration' => 86400,
                        ],
                        'quota_usage' => 100,
                    ],
                ],
            ], 200),
        ]);

        (new PublishDestinationJob($job->id))->handle(app(PlatformManager::class));

        $job->refresh();
        $this->assertEquals('RETRYING', $job->status);
        $this->assertEquals('RATE_LIMITED', $job->failure_category);
        $this->assertStringContainsString('quota reached', $job->user_facing_error_message);
    }

    public function test_instagram_configurable_api_version_is_honored(): void
    {
        config(['services.facebook.graph_api_version' => 'v22.0']);

        $publisher = new InstagramPublisher();
        $this->assertEquals('https://graph.facebook.com/v22.0', $publisher->getGraphBaseUrl());

        Http::fake([
            'https://graph.facebook.com/v22.0/17841400000000001?*' => Http::response([
                'id'           => '17841400000000001',
                'account_type' => 'BUSINESS',
            ], 200),
            'https://graph.facebook.com/v22.0/17841400000000001/content_publishing_limit*' => Http::response([
                'data' => [
                    ['config' => ['quota_total' => 100], 'quota_usage' => 5],
                ],
            ], 200),
            'https://graph.facebook.com/v22.0/17841400000000001/media' => Http::response(['id' => '17999888777v22'], 200),
            'https://graph.facebook.com/v22.0/17999888777v22*' => Http::response([
                'id'          => '17999888777v22',
                'status_code' => 'FINISHED',
            ], 200),
            'https://graph.facebook.com/v22.0/17841400000000001/media_publish' => Http::response(['id' => '18123456789v22'], 200),
            'https://graph.facebook.com/v22.0/18123456789v22*' => Http::response([
                'id'        => '18123456789v22',
                'permalink' => 'https://www.instagram.com/reel/v22reel/',
            ], 200),
        ]);

        $job = $this->createPublishJob();

        (new PublishDestinationJob($job->id))->handle(app(PlatformManager::class));

        $job->refresh();
        $this->assertEquals('PUBLISHED', $job->status);
        $this->assertEquals('18123456789v22', $job->remote_post_id);

        // Reset config
        config(['services.facebook.graph_api_version' => 'v21.0']);
    }

    public function test_instagram_reconciles_already_published_media_error_without_failing(): void
    {
        $job = $this->createPublishJob();
        $job->update([
            'status'             => 'PROCESSING',
            'upload_session_url' => '17999888777already',
            'remote_post_id'     => '17999888777already',
        ]);

        Http::fake([
            'https://graph.facebook.com/v21.0/17841400000000001?*' => Http::response([
                'id'           => '17841400000000001',
                'account_type' => 'BUSINESS',
            ], 200),
            'https://graph.facebook.com/v21.0/17841400000000001/content_publishing_limit*' => Http::response([
                'data' => [
                    ['config' => ['quota_total' => 100], 'quota_usage' => 5],
                ],
            ], 200),
            'https://graph.facebook.com/v21.0/17999888777already*' => Http::response([
                'id'          => '17999888777already',
                'status_code' => 'FINISHED',
            ], 200),
            'https://graph.facebook.com/v21.0/17841400000000001/media_publish' => Http::response([
                'error' => [
                    'message'       => 'Media is already published',
                    'code'          => 9007,
                    'error_subcode' => 2207027,
                ],
            ], 400),
            'https://graph.facebook.com/v21.0/17999888777already' => Http::response([
                'id'        => '17999888777already',
                'permalink' => 'https://www.instagram.com/reel/reconciled/',
            ], 200),
        ]);

        (new PublishDestinationJob($job->id))->handle(app(PlatformManager::class));

        $job->refresh();
        $this->assertEquals('PUBLISHED', $job->status);
        $this->assertEquals('17999888777already', $job->remote_post_id);
    }

    // ==========================================
    // 13. MEDIA URL ACCESSIBILITY & CRASH RECOVERY SCENARIOS
    // ==========================================

    public function test_media_url_accessibility_generates_temporary_url_with_expiration(): void
    {
        $storageService = app(\App\Services\MediaStorageService::class);
        $tempUrl = $storageService->getTemporaryUrl('workspaces/test/videos/video.mp4', now()->addHours(4));

        $this->assertNotEmpty($tempUrl);
        $this->assertStringContainsString('video.mp4', $tempUrl);
        $this->assertFalse(str_contains($tempUrl, 'bearer'));
        $this->assertFalse(str_contains($tempUrl, 'cookie'));
    }

    public function test_media_url_accessibility_empty_path_returns_empty_string(): void
    {
        $storageService = app(\App\Services\MediaStorageService::class);
        $this->assertEquals('', $storageService->getTemporaryUrl('', now()->addHours(4)));
    }

    public function test_media_url_accessibility_blocks_cross_tenant_media(): void
    {
        $job = $this->createPublishJob();
        // Attach media from workspace B to workspace A job
        $mediaB = MediaAsset::create([
            'workspace_id'    => $this->workspaceB->id,
            'user_id'         => $this->instagramAccountB->user_id ?? \App\Models\User::first()->id,
            'filename'        => 'foreign_reel.mp4',
            'original_name'   => 'foreign_reel.mp4',
            'storage_path'    => 'workspaces/' . $this->workspaceB->id . '/videos/foreign.mp4',
            'mime_type'       => 'video/mp4',
            'size_bytes'      => 5000000,
            'duration_seconds'=> 15.0,
            'width'           => 1080,
            'height'          => 1920,
            'status'          => 'READY',
        ]);

        $job->update(['media_asset_id' => $mediaB->id]);

        $this->expectException(\App\Platforms\Exceptions\InstagramPublishException::class);
        $this->expectExceptionMessage('Tenant violation: Media asset does not belong to job workspace.');

        $publisher = new InstagramPublisher();
        $publisher->publish(new \App\Platforms\Contracts\PublishContext(
            job: $job,
            account: $this->instagramAccountA,
            media: $mediaB,
            globalMetadata: ['title' => 'Cross Tenant Test'],
            platformOverrides: [],
            decryptedAccessToken: 'test_token'
        ));
    }

    public function test_media_url_accessibility_unready_media_fails_preflight(): void
    {
        $job = $this->createPublishJob();
        $unreadyMedia = MediaAsset::create([
            'workspace_id'    => $this->workspaceA->id,
            'user_id'         => \App\Models\User::first()->id,
            'filename'        => 'unprocessed.mp4',
            'original_name'   => 'unprocessed.mp4',
            'storage_path'    => 'workspaces/' . $this->workspaceA->id . '/videos/unprocessed.mp4',
            'mime_type'       => 'video/mp4',
            'size_bytes'      => 5000000,
            'duration_seconds'=> 15.0,
            'status'          => 'PROCESSING',
        ]);

        $job->update(['media_asset_id' => $unreadyMedia->id]);

        $this->expectException(\App\Platforms\Exceptions\InstagramPublishException::class);
        $this->expectExceptionMessage('must be processed and READY before publishing');

        $publisher = new InstagramPublisher();
        $publisher->publish(new \App\Platforms\Contracts\PublishContext(
            job: $job,
            account: $this->instagramAccountA,
            media: $unreadyMedia,
            globalMetadata: ['title' => 'Unready Media Test'],
            platformOverrides: [],
            decryptedAccessToken: 'test_token'
        ));
    }

    public function test_instagram_worker_crash_before_media_publish_resumes_from_finished_container(): void
    {
        $job = $this->createPublishJob();
        $job->update([
            'status'             => 'PROCESSING',
            'upload_session_url' => '17999888777crashed_before_publish',
            'remote_post_id'     => '17999888777crashed_before_publish',
        ]);

        Http::fake([
            'https://graph.facebook.com/v21.0/17999888777crashed_before_publish*' => Http::response([
                'id'          => '17999888777crashed_before_publish',
                'status_code' => 'FINISHED',
            ], 200),
            'https://graph.facebook.com/v21.0/17841400000000001/media_publish' => Http::response([
                'id' => '18123456789resumed_id',
            ], 200),
            'https://graph.facebook.com/v21.0/18123456789resumed_id*' => Http::response([
                'id'        => '18123456789resumed_id',
                'permalink' => 'https://www.instagram.com/reel/resumed/',
            ], 200),
        ]);

        (new PublishDestinationJob($job->id))->handle(app(PlatformManager::class));

        $job->refresh();
        $this->assertEquals('PUBLISHED', $job->status);
        $this->assertEquals('18123456789resumed_id', $job->remote_post_id);
    }

    public function test_instagram_caption_validation_enforces_2200_character_limit(): void
    {
        $publisher = new InstagramPublisher();

        // 1. Caption over 2,200 characters
        $issues = $publisher->validate(['caption' => str_repeat('A', 2201)], []);
        $this->assertNotEmpty($issues);
        $this->assertEquals('caption', $issues[0]['field']);

        // 2. Hashtags over 30
        $issues = $publisher->validate([], [], ['hashtags' => array_fill(0, 31, 'tag')]);
        $this->assertNotEmpty($issues);
        $this->assertEquals('hashtags', $issues[0]['field']);

        // 3. Valid caption & hashtags
        $issues = $publisher->validate(['caption' => 'Valid Instagram Reel caption! #creators'], []);
        $this->assertEmpty($issues);
    }
}
