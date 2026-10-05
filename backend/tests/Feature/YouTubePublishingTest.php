<?php

namespace Tests\Feature;

use App\Events\YouTubeAuthRequired;
use App\Events\YouTubePublishingCompleted;
use App\Events\YouTubePublishingStarted;
use App\Events\YouTubeUploadProgress;
use App\Jobs\PublishDestinationJob;
use App\Models\ContentItem;
use App\Models\MediaAsset;
use App\Models\PublishBatch;
use App\Models\PublishJob;
use App\Models\SocialAccount;
use App\Models\Workspace;
use App\Platforms\Adapters\YouTubePublisher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\MediaFixtureGenerator;
use Tests\TestCase;

class YouTubePublishingTest extends TestCase
{
    use RefreshDatabase;

    protected Workspace $workspaceA;
    protected Workspace $workspaceB;
    protected SocialAccount $youtubeAccountA;
    protected SocialAccount $youtubeAccountB;
    protected MediaAsset $mediaA;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake(config('filesystems.default', 'public'));

        $userA = \App\Models\User::create([
            'name' => 'Creator A',
            'email' => 'creator_a_' . uniqid() . '@test.com',
            'password' => bcrypt('secret123'),
        ]);

        $userB = \App\Models\User::create([
            'name' => 'Creator B',
            'email' => 'creator_b_' . uniqid() . '@test.com',
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

        $this->youtubeAccountA = SocialAccount::create([
            'workspace_id' => $this->workspaceA->id,
            'platform' => 'YOUTUBE',
            'platform_account_id' => 'UC_workspace_a_channel',
            'account_name' => 'Channel A',
            'status' => 'ACTIVE',
            'scopes' => ['https://www.googleapis.com/auth/youtube.upload', 'https://www.googleapis.com/auth/youtube.readonly'],
            'encrypted_access_token' => 'valid_access_token_a',
            'encrypted_refresh_token' => 'valid_refresh_token_a',
            'token_expires_at' => now()->addHour(),
        ]);

        $this->youtubeAccountB = SocialAccount::create([
            'workspace_id' => $this->workspaceB->id,
            'platform' => 'YOUTUBE',
            'platform_account_id' => 'UC_workspace_b_channel',
            'account_name' => 'Channel B',
            'status' => 'ACTIVE',
            'scopes' => ['https://www.googleapis.com/auth/youtube.upload'],
            'encrypted_access_token' => 'valid_access_token_b',
            'encrypted_refresh_token' => 'valid_refresh_token_b',
            'token_expires_at' => now()->addHour(),
        ]);

        // Generate synthetic test video
        $fixturePath = storage_path('app/temp/yt_test_fixture.mp4');
        MediaFixtureGenerator::createValidVideo($fixturePath, 1, 320, 240);
        $fixtureBytes = file_get_contents($fixturePath);

        $disk = config('filesystems.default', 'public');
        Storage::disk($disk)->put('videos/test_yt.mp4', $fixtureBytes);

        $this->mediaA = MediaAsset::create([
            'workspace_id' => $this->workspaceA->id,
            'filename' => 'test_yt.mp4',
            'original_name' => 'test_yt.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => strlen($fixtureBytes),
            'storage_path' => 'videos/test_yt.mp4',
            'status' => 'READY',
            'duration_seconds' => 1.0,
            'width' => 320,
            'height' => 240,
        ]);
    }

    /**
     * Helper to create a PublishJob for testing
     */
    protected function createPublishJob(array $overrides = []): PublishJob
    {
        $content = ContentItem::create([
            'workspace_id' => $this->workspaceA->id,
            'media_asset_id' => $this->mediaA->id,
            'global_metadata' => [
                'title' => 'Official OmniPublish Launch Video',
                'description' => 'Scaling video publishing pipelines to multiple social networks.',
                'tags' => ['saas', 'omnipublish', 'tech'],
            ],
            'platform_overrides' => [
                'youtube' => [
                    'title' => 'Official OmniPublish Launch Video (YT)',
                    'privacyStatus' => 'public',
                    'categoryId' => '28',
                    'madeForKids' => false,
                ],
            ],
            'target_platforms' => ['YOUTUBE'],
        ]);

        $batch = PublishBatch::create([
            'workspace_id' => $this->workspaceA->id,
            'content_item_id' => $content->id,
            'status' => 'RUNNING',
        ]);

        return PublishJob::create(array_merge([
            'publish_batch_id' => $batch->id,
            'workspace_id' => $this->workspaceA->id,
            'social_account_id' => $this->youtubeAccountA->id,
            'platform' => 'YOUTUBE',
            'status' => 'QUEUED',
            'attempts_count' => 0,
            'max_attempts' => 3,
        ], $overrides));
    }

    public function test_resumable_upload_happy_path_initiates_streams_chunks_and_publishes(): void
    {
        Event::fake([
            YouTubePublishingStarted::class,
            YouTubeUploadProgress::class,
            YouTubePublishingCompleted::class,
        ]);

        $job = $this->createPublishJob();
        $sessionUrl = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=yt_sess_abc123';

        Http::fake([
            // 1. Resumable initiation POST
            'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status' => Http::response(
                '',
                200,
                ['Location' => $sessionUrl]
            ),
            // 2. Chunk upload PUT
            'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=yt_sess_abc123*' => Http::response([
                'id' => 'dQw4w9WgXcQ',
                'snippet' => ['title' => 'Official OmniPublish Launch Video (YT)'],
                'status' => ['uploadStatus' => 'uploaded'],
            ], 200),
            // 3. Processing status check
            'https://www.googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [
                    [
                        'id' => 'dQw4w9WgXcQ',
                        'status' => ['uploadStatus' => 'processed'],
                        'processingDetails' => ['processingStatus' => 'succeeded'],
                    ],
                ],
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(\App\Platforms\Services\PlatformManager::class));

        $job->refresh();

        $this->assertEquals('PUBLISHED', $job->status);
        $this->assertEquals('dQw4w9WgXcQ', $job->remote_post_id);
        $this->assertEquals('https://www.youtube.com/watch?v=dQw4w9WgXcQ', $job->remote_post_url);
        $this->assertNull($job->upload_session_url);
        $this->assertEquals($this->mediaA->size_bytes, $job->bytes_uploaded);

        Event::assertDispatched(YouTubePublishingStarted::class);
        Event::assertDispatched(YouTubeUploadProgress::class);
        Event::assertDispatched(YouTubePublishingCompleted::class);
    }

    public function test_resumes_interrupted_upload_from_last_acknowledged_byte(): void
    {
        // Configure small 256 KiB chunk size for multi-chunk test
        config(['services.youtube.chunk_size' => 262144]);

        $sessionUrl = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=active_session_456';
        $job = $this->createPublishJob([
            'upload_session_url' => $sessionUrl,
            'bytes_uploaded' => 1000,
            'total_bytes' => $this->mediaA->size_bytes,
        ]);

        Http::fake([
            // 1. Status query (PUT Content-Length: 0) returns 308 with Range
            $sessionUrl => function (\Illuminate\Http\Client\Request $request) use ($sessionUrl) {
                // If query status request
                if ($request->header('Content-Length')[0] === '0' || empty($request->body())) {
                    return Http::response('', 308, ['Range' => 'bytes=0-999']);
                }

                // Chunk upload finishes
                return Http::response([
                    'id' => 'yt_resumed_video_777',
                    'snippet' => ['title' => 'Resumed Video'],
                ], 200);
            },
            // 2. Processing status check
            'https://www.googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [
                    [
                        'id' => 'yt_resumed_video_777',
                        'status' => ['uploadStatus' => 'processed'],
                        'processingDetails' => ['processingStatus' => 'succeeded'],
                    ],
                ],
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(\App\Platforms\Services\PlatformManager::class));

        $job->refresh();

        $this->assertEquals('PUBLISHED', $job->status);
        $this->assertEquals('yt_resumed_video_777', $job->remote_post_id);
        $this->assertEquals('https://www.youtube.com/watch?v=yt_resumed_video_777', $job->remote_post_url);
    }

    public function test_query_status_detects_already_completed_upload_session(): void
    {
        $sessionUrl = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=completed_session_888';
        $job = $this->createPublishJob([
            'upload_session_url' => $sessionUrl,
            'bytes_uploaded' => 500,
            'total_bytes' => $this->mediaA->size_bytes,
        ]);

        Http::fake([
            // Google reports 200 OK directly on status check (upload completed right before crash)
            $sessionUrl => Http::response([
                'id' => 'yt_already_finished_video',
            ], 200),
            // Processing status check
            'https://www.googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [
                    [
                        'id' => 'yt_already_finished_video',
                        'status' => ['uploadStatus' => 'processed'],
                        'processingDetails' => ['processingStatus' => 'succeeded'],
                    ],
                ],
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(\App\Platforms\Services\PlatformManager::class));

        $job->refresh();

        $this->assertEquals('PUBLISHED', $job->status);
        $this->assertEquals('yt_already_finished_video', $job->remote_post_id);
        $this->assertEquals('https://www.youtube.com/watch?v=yt_already_finished_video', $job->remote_post_url);
    }

    public function test_expired_access_token_auto_refreshes_before_upload(): void
    {
        config(['services.google.client_id' => 'mock_client_id']);
        config(['services.google.client_secret' => 'mock_client_secret']);

        // Expire token
        $this->youtubeAccountA->update([
            'token_expires_at' => now()->subMinutes(30),
            'encrypted_access_token' => 'expired_token',
        ]);

        $job = $this->createPublishJob();
        $sessionUrl = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=yt_refresh_sess';

        Http::fake([
            // 1. Google Token Refresh
            'https://oauth2.googleapis.com/token' => Http::response([
                'access_token' => 'freshly_refreshed_google_token',
                'expires_in' => 3600,
            ], 200),

            // 2. Resumable initiation with new token
            'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status' => function (\Illuminate\Http\Client\Request $request) use ($sessionUrl) {
                $this->assertEquals('Bearer freshly_refreshed_google_token', $request->header('Authorization')[0]);
                return Http::response('', 200, ['Location' => $sessionUrl]);
            },

            // 3. Chunk upload
            $sessionUrl . '*' => Http::response(['id' => 'yt_refreshed_vid'], 200),

            // 4. Processing status check
            'https://www.googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [
                    [
                        'id' => 'yt_refreshed_vid',
                        'status' => ['uploadStatus' => 'processed'],
                        'processingDetails' => ['processingStatus' => 'succeeded'],
                    ],
                ],
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(\App\Platforms\Services\PlatformManager::class));

        $job->refresh();
        $this->assertEquals('PUBLISHED', $job->status);
        $this->assertEquals('yt_refreshed_vid', $job->remote_post_id);

        $this->youtubeAccountA->refresh();
        $this->assertEquals('freshly_refreshed_google_token', $this->youtubeAccountA->encrypted_access_token);
    }

    public function test_revoked_refresh_token_transitions_to_auth_required(): void
    {
        Event::fake([YouTubeAuthRequired::class]);

        config(['services.google.client_id' => 'mock_client_id']);
        config(['services.google.client_secret' => 'mock_client_secret']);

        $this->youtubeAccountA->update([
            'token_expires_at' => now()->subMinutes(30),
            'encrypted_access_token' => 'expired_token',
        ]);

        $job = $this->createPublishJob();

        Http::fake([
            // Google Token Refresh returns invalid_grant
            'https://oauth2.googleapis.com/token' => Http::response([
                'error' => 'invalid_grant',
                'error_description' => 'Token has been expired or revoked.',
            ], 400),
            'https://www.googleapis.com/upload/youtube/v3/videos*' => Http::response([
                'error' => ['code' => 401, 'message' => 'Invalid Credentials'],
            ], 401),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(\App\Platforms\Services\PlatformManager::class));

        $job->refresh();

        $this->assertEquals('AUTH_REQUIRED', $job->status);
        $this->assertEquals('AUTH_REQUIRED', $job->failure_category);
        $this->assertStringContainsString('reconnect', strtolower($job->user_facing_error_message));

        Event::assertDispatched(YouTubeAuthRequired::class);
    }

    public function test_missing_upload_scope_fails_with_auth_required(): void
    {
        Event::fake([YouTubeAuthRequired::class]);

        $this->youtubeAccountA->update([
            'scopes' => ['https://www.googleapis.com/auth/youtube.readonly'],
        ]);

        $job = $this->createPublishJob();

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(\App\Platforms\Services\PlatformManager::class));

        $job->refresh();

        $this->assertEquals('AUTH_REQUIRED', $job->status);
        $this->assertEquals('AUTH_REQUIRED', $job->failure_category);
        $this->assertStringContainsString('scope', strtolower($job->user_facing_error_message));

        Event::assertDispatched(YouTubeAuthRequired::class);
    }

    public function test_transient_500_error_is_classified_as_retryable_remote_server_error(): void
    {
        $job = $this->createPublishJob();
        $sessionUrl = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=yt_fail_sess';

        Http::fake([
            'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status' => Http::response(
                '',
                200,
                ['Location' => $sessionUrl]
            ),
            $sessionUrl . '*' => Http::response('Internal Server Error', 500),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(\App\Platforms\Services\PlatformManager::class));

        $job->refresh();

        $this->assertEquals('RETRYING', $job->status);
        $this->assertEquals('REMOTE_SERVER_ERROR', $job->failure_category);
        $this->assertEquals(1, $job->attempts_count);
    }

    public function test_quota_exceeded_error_classified_as_quota_exceeded(): void
    {
        $job = $this->createPublishJob();

        Http::fake([
            'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status' => Http::response([
                'error' => [
                    'code' => 403,
                    'message' => 'The request cannot be completed because you have exceeded your quota.',
                    'errors' => [
                        [
                            'domain' => 'youtube.quota',
                            'reason' => 'quotaExceeded',
                            'message' => 'The request cannot be completed because you have exceeded your quota.',
                        ],
                    ],
                ],
            ], 403),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(\App\Platforms\Services\PlatformManager::class));

        $job->refresh();

        $this->assertEquals('RETRYING', $job->status);
        $this->assertEquals('QUOTA_EXCEEDED', $job->failure_category);
        $this->assertStringContainsString('quota', strtolower($job->user_facing_error_message));
    }

    public function test_idempotency_prevents_duplicate_remote_upload_if_already_published(): void
    {
        $job = $this->createPublishJob([
            'status' => 'PUBLISHED',
            'remote_post_id' => 'yt_existing_video_id',
            'remote_post_url' => 'https://www.youtube.com/watch?v=yt_existing_video_id',
        ]);

        // No HTTP requests should be sent to Google
        Http::fake();

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(\App\Platforms\Services\PlatformManager::class));

        Http::assertNothingSent();

        $job->refresh();
        $this->assertEquals('PUBLISHED', $job->status);
        $this->assertEquals('yt_existing_video_id', $job->remote_post_id);
    }

    public function test_cross_workspace_publishing_prevented_by_tenant_isolation(): void
    {
        // Create job in Workspace B attempting to use Workspace A's media and account
        $content = ContentItem::create([
            'workspace_id' => $this->workspaceB->id,
            'media_asset_id' => $this->mediaA->id, // Belongs to workspace A!
            'global_metadata' => ['title' => 'Cross-Workspace Exploit Test'],
            'target_platforms' => ['YOUTUBE'],
        ]);

        $batch = PublishBatch::create([
            'workspace_id' => $this->workspaceB->id,
            'content_item_id' => $content->id,
            'status' => 'RUNNING',
        ]);

        $job = PublishJob::create([
            'publish_batch_id' => $batch->id,
            'workspace_id' => $this->workspaceB->id,
            'social_account_id' => $this->youtubeAccountA->id, // Belongs to workspace A!
            'platform' => 'YOUTUBE',
            'status' => 'QUEUED',
            'attempts_count' => 0,
            'max_attempts' => 3,
        ]);

        Http::fake();

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(\App\Platforms\Services\PlatformManager::class));

        $job->refresh();

        $this->assertEquals('FAILED', $job->status);
        $this->assertEquals('AUTHORIZATION_ERROR', $job->failure_category);
        $this->assertStringContainsString('Tenant violation', $job->internal_error_details);

        Http::assertNothingSent();
    }

    public function test_empty_media_file_fails_with_invalid_media(): void
    {
        $emptyPath = 'videos/empty.mp4';
        Storage::disk(config('filesystems.default', 'public'))->put($emptyPath, '');

        $emptyMedia = MediaAsset::create([
            'workspace_id' => $this->workspaceA->id,
            'filename' => 'empty.mp4',
            'original_name' => 'empty.mp4',
            'mime_type' => 'video/mp4',
            'size_bytes' => 0,
            'storage_path' => $emptyPath,
            'status' => 'READY',
        ]);

        $job = $this->createPublishJob();
        $job->publishBatch->contentItem->update(['media_asset_id' => $emptyMedia->id]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(\App\Platforms\Services\PlatformManager::class));

        $job->refresh();

        $this->assertEquals('FAILED', $job->status);
        $this->assertEquals('INVALID_MEDIA', $job->failure_category);
    }

    public function test_metadata_validation_catches_invalid_title_and_description(): void
    {
        $publisher = new YouTubePublisher();

        // 1. Empty title
        $issues = $publisher->validate(['title' => ''], []);
        $this->assertNotEmpty($issues);
        $this->assertEquals('title', $issues[0]['field']);

        // 2. Title exceeding 100 characters
        $issues = $publisher->validate(['title' => str_repeat('A', 101)], []);
        $this->assertNotEmpty($issues);
        $this->assertEquals('title', $issues[0]['field']);

        // 3. Description exceeding 5000 characters
        $issues = $publisher->validate(['title' => 'Valid Title', 'description' => str_repeat('B', 5001)], []);
        $this->assertNotEmpty($issues);
        $this->assertEquals('description', $issues[0]['field']);

        // 4. Valid title & description
        $issues = $publisher->validate([
            'title' => 'Clean & Concise YouTube Title',
            'description' => 'A valid and informative description.',
        ], []);
        $this->assertEmpty($issues);
    }

    public function test_upload_succeeds_but_youtube_is_still_processing(): void
    {
        Queue::fake([PublishDestinationJob::class]);

        $job = $this->createPublishJob();
        $sessionUrl = 'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=yt_still_proc_sess';

        Http::fake([
            'https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status' => Http::response(
                '', 200, ['Location' => $sessionUrl]
            ),
            $sessionUrl . '*' => Http::response([
                'id' => 'yt_processing_123',
                'snippet' => ['title' => 'Processing Video'],
                'status' => ['uploadStatus' => 'uploaded'],
            ], 200),
            'https://www.googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [
                    [
                        'id' => 'yt_processing_123',
                        'status' => ['uploadStatus' => 'uploaded'],
                        'processingDetails' => ['processingStatus' => 'processing'],
                    ],
                ],
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(\App\Platforms\Services\PlatformManager::class));

        $job->refresh();

        $this->assertEquals('PROCESSING', $job->status);
        $this->assertEquals('yt_processing_123', $job->remote_post_id);
        $this->assertEquals('https://www.youtube.com/watch?v=yt_processing_123', $job->remote_post_url);

        Queue::assertPushed(PublishDestinationJob::class, function ($pushedJob) use ($job) {
            return $pushedJob->publishJobId === $job->id;
        });
    }

    public function test_youtube_processing_succeeds_and_marks_published(): void
    {
        Event::fake([YouTubePublishingCompleted::class]);

        $job = $this->createPublishJob([
            'status' => 'PROCESSING',
            'remote_post_id' => 'yt_proc_success_vid',
            'remote_post_url' => 'https://www.youtube.com/watch?v=yt_proc_success_vid',
        ]);

        Http::fake([
            'https://www.googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [
                    [
                        'id' => 'yt_proc_success_vid',
                        'status' => ['uploadStatus' => 'processed'],
                        'processingDetails' => ['processingStatus' => 'succeeded'],
                    ],
                ],
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(\App\Platforms\Services\PlatformManager::class));

        $job->refresh();

        $this->assertEquals('PUBLISHED', $job->status);
        $this->assertEquals('yt_proc_success_vid', $job->remote_post_id);
        Event::assertDispatched(YouTubePublishingCompleted::class);
    }

    public function test_youtube_processing_fails_and_marks_failed(): void
    {
        $job = $this->createPublishJob([
            'status' => 'PROCESSING',
            'remote_post_id' => 'yt_proc_failed_vid',
            'remote_post_url' => 'https://www.youtube.com/watch?v=yt_proc_failed_vid',
        ]);

        Http::fake([
            'https://www.googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [
                    [
                        'id' => 'yt_proc_failed_vid',
                        'status' => [
                            'uploadStatus' => 'rejected',
                            'rejectionReason' => 'duplicate',
                        ],
                        'processingDetails' => [
                            'processingStatus' => 'failed',
                        ],
                    ],
                ],
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(\App\Platforms\Services\PlatformManager::class));

        $job->refresh();

        $this->assertEquals('FAILED', $job->status);
        $this->assertEquals('PROVIDER_ERROR', $job->failure_category);
        $this->assertStringContainsString('duplicate', strtolower($job->user_facing_error_message));
    }

    public function test_processing_status_request_temporarily_fails_and_retries(): void
    {
        $job = $this->createPublishJob([
            'status' => 'PROCESSING',
            'remote_post_id' => 'yt_proc_transient_vid',
            'remote_post_url' => 'https://www.youtube.com/watch?v=yt_proc_transient_vid',
        ]);

        Http::fake([
            'https://www.googleapis.com/youtube/v3/videos*' => Http::response('Service Unavailable', 503),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(\App\Platforms\Services\PlatformManager::class));

        $job->refresh();

        $this->assertEquals('RETRYING', $job->status);
        $this->assertEquals('REMOTE_SERVER_ERROR', $job->failure_category);
        $this->assertEquals('yt_proc_transient_vid', $job->remote_post_id);
    }

    public function test_processing_status_request_returns_authentication_error(): void
    {
        Event::fake([YouTubeAuthRequired::class]);

        $job = $this->createPublishJob([
            'status' => 'PROCESSING',
            'remote_post_id' => 'yt_proc_auth_err_vid',
            'remote_post_url' => 'https://www.youtube.com/watch?v=yt_proc_auth_err_vid',
        ]);

        Http::fake([
            'https://www.googleapis.com/youtube/v3/videos*' => Http::response([
                'error' => ['code' => 401, 'message' => 'Invalid Credentials'],
            ], 401),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(\App\Platforms\Services\PlatformManager::class));

        $job->refresh();

        $this->assertEquals('AUTH_REQUIRED', $job->status);
        $this->assertEquals('AUTH_REQUIRED', $job->failure_category);
        Event::assertDispatched(YouTubeAuthRequired::class);
    }

    public function test_worker_restarts_while_video_is_processing_queries_status_without_reuploading(): void
    {
        // Job was saved in PROCESSING state before worker crashed
        $job = $this->createPublishJob([
            'status' => 'PROCESSING',
            'remote_post_id' => 'yt_restarted_worker_vid',
            'remote_post_url' => 'https://www.youtube.com/watch?v=yt_restarted_worker_vid',
            'upload_session_url' => null,
            'bytes_uploaded' => $this->mediaA->size_bytes,
        ]);

        Http::fake([
            // Resumable upload URLs must NOT be called
            'https://www.googleapis.com/upload/youtube/v3/videos*' => Http::response('Should not be called', 500),
            // Only videos.list is queried
            'https://www.googleapis.com/youtube/v3/videos*' => Http::response([
                'items' => [
                    [
                        'id' => 'yt_restarted_worker_vid',
                        'status' => ['uploadStatus' => 'processed'],
                        'processingDetails' => ['processingStatus' => 'succeeded'],
                    ],
                ],
            ], 200),
        ]);

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(\App\Platforms\Services\PlatformManager::class));

        $job->refresh();

        $this->assertEquals('PUBLISHED', $job->status);
        $this->assertEquals('yt_restarted_worker_vid', $job->remote_post_id);

        Http::assertSent(function (\Illuminate\Http\Client\Request $request) {
            return !str_contains($request->url(), '/upload/youtube/v3/videos');
        });
    }

    public function test_duplicate_processing_status_jobs_do_not_create_duplicate_work(): void
    {
        $job = $this->createPublishJob([
            'status' => 'PROCESSING',
            'remote_post_id' => 'yt_lock_vid',
            'remote_post_url' => 'https://www.youtube.com/watch?v=yt_lock_vid',
        ]);

        // Simulate concurrent worker holding the job lock
        $lock = Cache::lock("publish_lock_job_{$job->id}", 60);
        $lock->acquire();

        Http::fake();

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(\App\Platforms\Services\PlatformManager::class));

        // Since lock was held by another worker, no HTTP calls should have been made
        Http::assertNothingSent();

        $job->refresh();
        $this->assertEquals('PROCESSING', $job->status);

        $lock->release();
    }

    public function test_video_already_marked_published_is_not_processed_again_unnecessarily(): void
    {
        $job = $this->createPublishJob([
            'status' => 'PUBLISHED',
            'remote_post_id' => 'yt_already_done_vid',
            'remote_post_url' => 'https://www.youtube.com/watch?v=yt_already_done_vid',
        ]);

        Http::fake();

        $jobHandler = new PublishDestinationJob($job->id);
        $jobHandler->handle(app(\App\Platforms\Services\PlatformManager::class));

        Http::assertNothingSent();

        $job->refresh();
        $this->assertEquals('PUBLISHED', $job->status);
    }

    public function test_tenant_isolation_prevents_checking_status_with_foreign_workspace_account(): void
    {
        $job = $this->createPublishJob([
            'status' => 'PROCESSING',
            'remote_post_id' => 'yt_foreign_vid',
            'remote_post_url' => 'https://www.youtube.com/watch?v=yt_foreign_vid',
        ]);

        $publisher = new YouTubePublisher();
        $foreignContext = new \App\Platforms\Contracts\PublishContext(
            job: $job,
            account: $this->youtubeAccountB, // Belongs to workspace B!
            media: $this->mediaA,
            globalMetadata: [],
            platformOverrides: [],
            decryptedAccessToken: 'foreign_token'
        );

        $this->expectException(\App\Platforms\Exceptions\YouTubePublishException::class);
        $this->expectExceptionMessage('Tenant violation');

        $publisher->getStatus('yt_foreign_vid', $foreignContext);
    }
}
