<?php

namespace App\Platforms\Adapters;

use App\Events\TikTokAuthRequired;
use App\Events\TikTokCreatorInfoFetched;
use App\Events\TikTokPublishingCompleted;
use App\Events\TikTokPublishingFailed;
use App\Events\TikTokPublishingInitialized;
use App\Events\TikTokPublishingStarted;
use App\Events\TikTokUploadProgress;
use App\Events\TikTokUploadStarted;
use App\Models\MediaAsset;
use App\Models\PublishJob;
use App\Models\SocialAccount;
use App\Platforms\Contracts\PlatformAdapterInterface;
use App\Platforms\Contracts\PlatformCapabilities;
use App\Platforms\Contracts\PublishContext;
use App\Platforms\Contracts\PublishResult;
use App\Platforms\Contracts\RemoteStatusResult;
use App\Platforms\Exceptions\TikTokPublishException;
use App\Platforms\Profiles\TikTokMediaProfile;
use App\Services\TokenRefreshService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class TikTokPublisher implements PlatformAdapterInterface
{
    /**
     * Default chunk size: 10 MiB (10,485,760 bytes).
     * TikTok requires chunks between 5 MiB and 64 MiB.
     */
    public const DEFAULT_CHUNK_SIZE = 10485760;
    public const MIN_CHUNK_SIZE = 5242880;     // 5 MiB
    public const MAX_CHUNK_SIZE = 67108864;    // 64 MiB

    public function __construct(
        protected ?TokenRefreshService $tokenRefreshService = null
    ) {
        $this->tokenRefreshService = $tokenRefreshService ?? app(TokenRefreshService::class);
    }

    public function getPlatformName(): string
    {
        return 'TIKTOK';
    }

    public function getCapabilities(): PlatformCapabilities
    {
        return new PlatformCapabilities(
            platform: 'TIKTOK',
            supportsTitle: false,
            supportsDescription: false,
            supportsCaption: true,
            supportsTags: false,
            supportsHashtags: true,
            supportsCustomThumbnail: false,
            supportsNativeScheduling: false,
            supportsAnalytics: true,
            requiresCategory: false,
            limits: [
                'maxCaptionLength' => 2200,
                'maxHashtagsCount' => 30,
                'maxVideoDurationSeconds' => 600,
                'minVideoDurationSeconds' => 3,
                'maxFileSizeMB' => 1024,
                'supportedAspectRatios' => ['9:16', '1:1', '16:9'],
            ]
        );
    }

    public function validate(array $globalMetadata, array $overrides, ?array $media = null): array
    {
        $issues = [];
        $tiktokOverrides = $overrides['tiktok'] ?? [];

        $caption = $tiktokOverrides['caption']
            ?? $tiktokOverrides['title']
            ?? $globalMetadata['caption']
            ?? $globalMetadata['description']
            ?? $globalMetadata['title']
            ?? '';

        if (mb_strlen($caption) > 2200) {
            $issues[] = [
                'field' => 'caption',
                'severity' => 'ERROR',
                'message' => 'TikTok caption exceeds maximum allowed length of 2,200 characters.'
            ];
        }

        $hashtags = $tiktokOverrides['hashtags']
            ?? $overrides['hashtags']
            ?? $globalMetadata['hashtags']
            ?? ($media['hashtags'] ?? []);
        if (count($hashtags) > 30) {
            $issues[] = [
                'field' => 'hashtags',
                'severity' => 'ERROR',
                'message' => 'TikTok permits a maximum of 30 hashtags.'
            ];
        }

        if ($media !== null) {
            $status = $media['status'] ?? '';
            if (!empty($status) && !in_array($status, ['READY', 'PROCESSED'])) {
                $issues[] = [
                    'field' => 'media',
                    'severity' => 'ERROR',
                    'message' => "Media asset status is [{$status}]. Asset must be processed and READY before publishing."
                ];
            }

            $duration = (float) ($media['duration_seconds'] ?? 0);
            if ($duration > 0 && $duration < 3.0) {
                $issues[] = [
                    'field' => 'duration_seconds',
                    'severity' => 'ERROR',
                    'message' => 'TikTok video duration must be at least 3 seconds.'
                ];
            }
        }

        return $issues;
    }

    public function refreshAuthentication(SocialAccount $account): string
    {
        return $this->tokenRefreshService->refreshIfExpired($account);
    }

    /**
     * Execute full production Direct Post to TikTok Content Posting API v2.
     */
    public function publish(PublishContext $context): PublishResult
    {
        $job = $context->job;
        $account = $context->account;
        $media = $context->media;
        $global = $context->globalMetadata;
        $overrides = $context->platformOverrides['tiktok'] ?? [];

        // 1. Ownership & Tenant Boundary Verification
        if ($account->workspace_id !== $job->workspace_id) {
            throw new TikTokPublishException(
                "Tenant violation: Connected TikTok account does not belong to job workspace.",
                'AUTHORIZATION_ERROR',
                false,
                403
            );
        }

        if ($media && $media->workspace_id !== $job->workspace_id) {
            throw new TikTokPublishException(
                "Tenant violation: Media asset does not belong to job workspace.",
                'AUTHORIZATION_ERROR',
                false,
                403
            );
        }

        if (!$media) {
            throw new TikTokPublishException(
                "Media asset is required for TikTok video publishing.",
                'INVALID_MEDIA',
                false,
                422
            );
        }

        // 2. Validate Connected Account Status & Required Scopes
        if ($account->status !== 'ACTIVE') {
            event(new TikTokAuthRequired($job->id, $job->workspace_id, $account->id, 'ACCOUNT_INACTIVE'));
            throw new TikTokPublishException(
                "TikTok account is not active ({$account->status}). Reconnect required.",
                'AUTH_REQUIRED',
                false,
                401
            );
        }

        $scopes = $account->scopes ?? [];
        $hasPublishScope = false;
        foreach ($scopes as $scope) {
            if (trim($scope) === 'video.publish') {
                $hasPublishScope = true;
                break;
            }
        }

        if (!$hasPublishScope) {
            event(new TikTokAuthRequired($job->id, $job->workspace_id, $account->id, 'INSUFFICIENT_SCOPE'));
            throw new TikTokPublishException(
                "TikTok account lacks required direct publishing scope (video.publish). Reconnect with required permissions.",
                'AUTH_REQUIRED',
                false,
                403
            );
        }

        // 3. Resolve & Refresh Access Token
        $token = $this->tokenRefreshService->refreshIfExpired($account);
        if (empty($token)) {
            $token = $context->decryptedAccessToken ?: ($account->encrypted_access_token ?? '');
        }

        if (empty($token)) {
            event(new TikTokAuthRequired($job->id, $job->workspace_id, $account->id, 'TOKEN_MISSING'));
            throw new TikTokPublishException(
                "No valid OAuth access token available for TikTok publishing.",
                'AUTH_REQUIRED',
                false,
                401
            );
        }

        // Check for mock / local demo simulation
        if (str_starts_with($token, 'mock_') || str_starts_with($token, 'demo_') || $token === 'test_token') {
            $simId = 'tt_' . Str::random(12);
            $simUrl = "https://www.tiktok.com/@creator/video/{$simId}";
            $totalBytes = $media?->size_bytes ?: 10485760;

            if ($job->status !== 'UPLOADING') {
                $job->transitionTo('UPLOADING', ['total_bytes' => $totalBytes, 'bytes_uploaded' => $totalBytes]);
            }

            $job->update([
                'remote_post_id' => $simId,
                'remote_post_url' => $simUrl,
                'upload_session_url' => null,
                'total_bytes' => $totalBytes,
                'bytes_uploaded' => $totalBytes,
            ]);

            return new PublishResult(
                remoteId: $simId,
                remoteUrl: $simUrl,
                requiresPolling: false,
                statusDetails: 'Simulated TikTok video published'
            );
        }

        // 4. Idempotency Check: Already Published or Remote Post Exists and upload is complete
        if (!empty($job->remote_post_id) && empty($job->upload_session_url) && $job->status !== 'UPLOADING') {
            if ($job->status === 'PUBLISHED') {
                Log::info("TikTokPublisher: Job {$job->id} is already published with remote ID {$job->remote_post_id}.");
                return new PublishResult(
                    remoteId: $job->remote_post_id,
                    remoteUrl: $job->remote_post_url ?? "https://www.tiktok.com/@creator/video/{$job->remote_post_id}",
                    requiresPolling: false,
                    statusDetails: 'Already published'
                );
            }

            Log::info("TikTokPublisher: Job {$job->id} already has remote ID {$job->remote_post_id} (status: {$job->status}). Checking remote processing status without re-uploading.");
            return $this->checkRemoteProcessingStatus(
                $job->remote_post_id,
                $job->remote_post_url ?? "https://www.tiktok.com/@creator/video/{$job->remote_post_id}",
                $context
            );
        }

        // 5. Creator Information Query & Creator Capabilities Enforcement (Authoritative)
        $creatorInfo = $this->fetchCreatorInfo($token, $context);
        $this->enforceCreatorCapabilities($creatorInfo, $overrides, $media);

        // 6. Media Asset Validation against TikTok Profile
        $mediaIssues = TikTokMediaProfile::validate($media, $creatorInfo['max_video_post_duration_sec'] ?? null);
        if (!empty($mediaIssues)) {
            $firstError = $mediaIssues[0]['message'];
            throw new TikTokPublishException($firstError, 'INVALID_MEDIA', false, 422);
        }

        // 7. Ephemeral Scratch Preparation
        $scratchDir = storage_path('app/temp/tt_pub_' . $job->id . '_' . Str::random(8));
        $localFilePath = null;

        try {
            $localFilePath = $this->obtainLocalMediaFile($media, $scratchDir);
            $totalBytes = filesize($localFilePath);

            if ($totalBytes === 0) {
                throw new TikTokPublishException(
                    "Media file is empty (0 bytes). Cannot upload to TikTok.",
                    'INVALID_MEDIA',
                    false,
                    422
                );
            }

            // 8. Check for Active Upload Session or Initialize Direct Post
            $uploadUrl = $job->upload_session_url;
            $publishId = $job->remote_post_id;

            if (!$uploadUrl || !$publishId) {
                // Determine chunk size per TikTok Content Posting API specification:
                $chunkParams = self::calculateChunkParameters($totalBytes);
                $chunkSize = $chunkParams['chunk_size'];
                $totalChunkCount = $chunkParams['total_chunk_count'];

                $postInfo = $this->preparePostInfo($global, $overrides, $creatorInfo);

                $initResult = $this->initializeDirectPost(
                    $token,
                    $totalBytes,
                    $chunkSize,
                    $totalChunkCount,
                    $postInfo,
                    $job
                );

                $publishId = $initResult['publish_id'];
                $uploadUrl = $initResult['upload_url'];

                $job->update([
                    'upload_session_url' => $uploadUrl,
                    'remote_post_id'     => $publishId,
                    'total_bytes'        => $totalBytes,
                    'bytes_uploaded'     => 0,
                ]);

                event(new TikTokPublishingStarted($job->id, $job->workspace_id, $account->id, $media->id, $totalBytes));
                event(new TikTokPublishingInitialized($job->id, $job->workspace_id, $publishId, $uploadUrl));
            }

            // 9. Stream Binary Chunks to TikTok
            if ($job->status !== 'UPLOADING') {
                $job->transitionTo('UPLOADING');
            }

            event(new TikTokUploadStarted($job->id, $job->workspace_id, $totalBytes, (int) ceil($totalBytes / self::DEFAULT_CHUNK_SIZE)));

            $this->uploadChunks($uploadUrl, $localFilePath, $totalBytes, $token, $job);

            // 10. Transition to PROCESSING and Check Processing Status
            $job->update([
                'upload_session_url' => null, // upload complete
                'bytes_uploaded'     => $totalBytes,
            ]);

            if ($job->canTransitionTo('PROCESSING')) {
                $job->transitionTo('PROCESSING');
            }

            $creatorUsername = $creatorInfo['creator_username'] ?? 'creator';
            $defaultVideoUrl = "https://www.tiktok.com/@{$creatorUsername}/video/{$publishId}";

            return $this->checkRemoteProcessingStatus($publishId, $defaultVideoUrl, $context, $creatorUsername);

        } catch (Throwable $e) {
            $normalized = $this->normalizeError($e);
            event(new TikTokPublishingFailed(
                $job->id,
                $job->workspace_id,
                $normalized['category'],
                $normalized['user_message'],
                $normalized['retryable']
            ));

            throw $e;
        } finally {
            // Guaranteed scratch cleanup
            if ($localFilePath && file_exists($localFilePath)) {
                @unlink($localFilePath);
            }
            if (is_dir($scratchDir)) {
                @rmdir($scratchDir);
            }
        }
    }

    /**
     * Fetch creator information from TikTok Content Posting API.
     */
    public function fetchCreatorInfo(string $token, PublishContext $context): array
    {
        $res = Http::withToken($token)
            ->withHeaders(['Content-Type' => 'application/json; charset=UTF-8'])
            ->post('https://open.tiktokapis.com/v2/post/publish/creator_info/query/', (object) []);

        if ($res->status() === 401) {
            event(new TikTokAuthRequired($context->job->id, $context->job->workspace_id, $context->account->id, 'CREATOR_INFO_UNAUTHORIZED'));
            throw new TikTokPublishException(
                "TikTok authentication expired while querying creator capabilities. Reconnect required.",
                'AUTH_REQUIRED',
                false,
                401
            );
        }

        if ($res->status() === 429) {
            throw new TikTokPublishException(
                "TikTok rate limit encountered while querying creator info.",
                'RATE_LIMITED',
                true,
                429
            );
        }

        if ($res->serverError()) {
            throw new TikTokPublishException(
                "TikTok servers temporarily unavailable (HTTP {$res->status()}).",
                'REMOTE_SERVER_ERROR',
                true,
                $res->status()
            );
        }

        $body = $res->json() ?? [];
        $errorCode = $body['error']['code'] ?? 'unknown';
        if ($errorCode !== 'ok' && $res->failed()) {
            throw new TikTokPublishException(
                "Failed to fetch TikTok creator info: " . ($body['error']['message'] ?? $res->body()),
                'CREATOR_CAPABILITY_ERROR',
                false,
                $res->status()
            );
        }

        $data = $body['data'] ?? [];
        $capabilities = [
            'creator_avatar_url'          => $data['creator_avatar_url'] ?? null,
            'creator_username'            => $data['creator_username'] ?? 'creator',
            'creator_nickname'            => $data['creator_nickname'] ?? '',
            'privacy_level_options'       => $data['privacy_level_options'] ?? ['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'SELF_ONLY'],
            'comment_disabled'            => (bool) ($data['comment_disabled'] ?? false),
            'duet_disabled'                => (bool) ($data['duet_disabled'] ?? false),
            'stitch_disabled'              => (bool) ($data['stitch_disabled'] ?? false),
            'max_video_post_duration_sec' => (int) ($data['max_video_post_duration_sec'] ?? 600),
        ];

        event(new TikTokCreatorInfoFetched(
            $context->job->id,
            $context->job->workspace_id,
            $context->account->id,
            $capabilities
        ));

        return $capabilities;
    }

    /**
     * Enforce creator capabilities against requested publishing settings.
     */
    protected function enforceCreatorCapabilities(array $creatorInfo, array $overrides, MediaAsset $media): void
    {
        // 1. Validate Privacy Level Option
        $requestedPrivacy = $overrides['privacy_level']
            ?? $overrides['privacyLevel']
            ?? 'PUBLIC_TO_EVERYONE';

        $allowedPrivacyOptions = $creatorInfo['privacy_level_options'] ?? [];
        if (!empty($allowedPrivacyOptions) && !in_array($requestedPrivacy, $allowedPrivacyOptions, true)) {
            $optionsList = implode(', ', $allowedPrivacyOptions);
            throw new TikTokPublishException(
                "Requested privacy level [{$requestedPrivacy}] is not supported by this TikTok creator account. Allowed: [{$optionsList}].",
                'CREATOR_CAPABILITY_ERROR',
                false,
                422
            );
        }

        // 2. Validate Comments
        $disableComments = (bool) ($overrides['disable_comment'] ?? $overrides['disableComment'] ?? false);
        if (!$disableComments && ($creatorInfo['comment_disabled'] ?? false)) {
            throw new TikTokPublishException(
                "Comments are globally disabled on this TikTok creator account. Set disable_comment to true.",
                'CREATOR_CAPABILITY_ERROR',
                false,
                422
            );
        }

        // 3. Validate Duets
        $disableDuet = (bool) ($overrides['disable_duet'] ?? $overrides['disableDuet'] ?? false);
        if (!$disableDuet && ($creatorInfo['duet_disabled'] ?? false)) {
            throw new TikTokPublishException(
                "Duets are globally disabled on this TikTok creator account. Set disable_duet to true.",
                'CREATOR_CAPABILITY_ERROR',
                false,
                422
            );
        }

        // 4. Validate Stitches
        $disableStitch = (bool) ($overrides['disable_stitch'] ?? $overrides['disableStitch'] ?? false);
        if (!$disableStitch && ($creatorInfo['stitch_disabled'] ?? false)) {
            throw new TikTokPublishException(
                "Stitches are globally disabled on this TikTok creator account. Set disable_stitch to true.",
                'CREATOR_CAPABILITY_ERROR',
                false,
                422
            );
        }

        // 5. Validate Duration vs Creator Limit
        $maxSec = (int) ($creatorInfo['max_video_post_duration_sec'] ?? 600);
        if (($media->duration_seconds ?? 0) > $maxSec) {
            throw new TikTokPublishException(
                "Video duration ({$media->duration_seconds}s) exceeds creator account maximum of {$maxSec} seconds.",
                'INVALID_MEDIA',
                false,
                422
            );
        }
    }

    /**
     * Calculate chunk parameters per TikTok Content Posting API specifications:
     * - Files <= 64 MB can be uploaded in a single chunk
     * - Files > 64 MB: chunk size between 5 MB and 64 MB, total_chunk_count <= 1000
     */
    public static function calculateChunkParameters(int $totalBytes): array
    {
        if ($totalBytes <= self::MAX_CHUNK_SIZE) {
            return [
                'chunk_size'        => $totalBytes,
                'total_chunk_count' => 1,
            ];
        }

        $minChunkFor1000 = (int) ceil($totalBytes / 1000);
        $chunkSize = min(self::MAX_CHUNK_SIZE, max(self::MIN_CHUNK_SIZE, max(self::DEFAULT_CHUNK_SIZE, $minChunkFor1000)));
        $totalChunkCount = (int) ceil($totalBytes / $chunkSize);

        return [
            'chunk_size'        => $chunkSize,
            'total_chunk_count' => $totalChunkCount,
        ];
    }

    /**
     * Initialize Direct Post via POST /v2/post/publish/video/init/
     */
    protected function initializeDirectPost(
        string $token,
        int $totalBytes,
        int $chunkSize,
        int $totalChunkCount,
        array $postInfo,
        PublishJob $job
    ): array {
        $endpoint = 'https://open.tiktokapis.com/v2/post/publish/video/init/';

        $payload = [
            'post_info'   => $postInfo,
            'source_info' => [
                'source'            => 'FILE_UPLOAD',
                'video_size'        => $totalBytes,
                'chunk_size'        => $chunkSize,
                'total_chunk_count' => $totalChunkCount,
            ],
        ];

        $res = Http::withToken($token)
            ->withHeaders(['Content-Type' => 'application/json; charset=UTF-8'])
            ->post($endpoint, $payload);

        if ($res->status() === 401) {
            event(new TikTokAuthRequired($job->id, $job->workspace_id, $job->social_account_id, 'INIT_UNAUTHORIZED'));
            throw new TikTokPublishException("TikTok authorization expired during video initialization.", 'AUTH_REQUIRED', false, 401);
        }

        if ($res->status() === 429) {
            throw new TikTokPublishException("TikTok API rate limit exceeded during video initialization.", 'RATE_LIMITED', true, 429);
        }

        if ($res->serverError()) {
            throw new TikTokPublishException("TikTok servers temporarily unavailable during initialization (HTTP {$res->status()}).", 'REMOTE_SERVER_ERROR', true, $res->status());
        }

        $body = $res->json() ?? [];
        $errorCode = $body['error']['code'] ?? 'unknown';

        if ($res->failed() || ($errorCode !== 'ok' && $errorCode !== '')) {
            $msg = $body['error']['message'] ?? $res->body();
            $rawMsg = strtolower($msg . ' ' . $errorCode);
            if (str_contains($rawMsg, 'spam')) {
                throw new TikTokPublishException("TikTok rejected video initialization due to spam risk detection: {$msg}", 'INVALID_METADATA', false, 422);
            }
            if (str_contains($rawMsg, 'title_sensitive') || str_contains($rawMsg, 'sensitive')) {
                throw new TikTokPublishException("TikTok flagged the caption as sensitive: {$msg}", 'INVALID_METADATA', false, 422);
            }
            throw new TikTokPublishException("TikTok video initialization failed: {$msg}", 'PROVIDER_ERROR', false, $res->status());
        }

        $publishId = $body['data']['publish_id'] ?? null;
        $uploadUrl = $body['data']['upload_url'] ?? null;

        if (!$publishId || !$uploadUrl) {
            throw new TikTokPublishException("TikTok video initialization did not return a valid publish_id or upload_url.", 'PROVIDER_ERROR', false);
        }

        return [
            'publish_id' => $publishId,
            'upload_url' => $uploadUrl,
        ];
    }

    /**
     * Stream binary chunks to the TikTok upload URL via PUT.
     */
    protected function uploadChunks(
        string $uploadUrl,
        string $filePath,
        int $totalBytes,
        string $token,
        PublishJob $job
    ): void {
        $chunkSize = $totalBytes <= self::MAX_CHUNK_SIZE
            ? $totalBytes
            : self::DEFAULT_CHUNK_SIZE;

        $handle = fopen($filePath, 'rb');
        if (!$handle) {
            throw new TikTokPublishException("Cannot open local media file for chunked reading: {$filePath}", 'INVALID_MEDIA', false);
        }

        $offset = 0;

        try {
            while ($offset < $totalBytes) {
                $bytesToRead = min($chunkSize, $totalBytes - $offset);
                fseek($handle, $offset);
                $chunkData = fread($handle, $bytesToRead);

                if ($chunkData === false || strlen($chunkData) === 0) {
                    throw new TikTokPublishException("Failed to read chunk data from source file at offset {$offset}.", 'INVALID_MEDIA', false);
                }

                $chunkLen = strlen($chunkData);
                $endByte = $offset + $chunkLen - 1;

                $res = Http::withHeaders([
                    'Content-Range'  => "bytes {$offset}-{$endByte}/{$totalBytes}",
                    'Content-Type'   => 'video/mp4',
                    'Content-Length' => (string) $chunkLen,
                ])->withBody($chunkData, 'video/mp4')->put($uploadUrl);

                $statusCode = $res->status();

                if ($statusCode === 200 || $statusCode === 201) {
                    $offset += $chunkLen;
                    $job->update(['bytes_uploaded' => $offset]);

                    $percentage = round(($offset / $totalBytes) * 100, 2);
                    event(new TikTokUploadProgress($job->id, $job->workspace_id, $offset, $totalBytes, $percentage));
                    continue;
                }

                if ($statusCode === 401) {
                    $token = $this->tokenRefreshService->refreshIfExpired($job->socialAccount);
                    if (!$token) {
                        throw new TikTokPublishException("TikTok authentication expired mid-upload and could not be refreshed.", 'AUTH_REQUIRED', false, 401);
                    }
                    continue;
                }

                if ($statusCode === 429) {
                    throw new TikTokPublishException("TikTok rate limit exceeded during chunk upload.", 'RATE_LIMITED', true, 429);
                }

                if ($statusCode === 403 || $statusCode === 404) {
                    $job->update(['upload_session_url' => null]);
                    throw new TikTokPublishException(
                        "TikTok upload session expired or invalid (HTTP {$statusCode}). Upload session reset for re-initialization.",
                        'UPLOAD_SESSION_EXPIRED',
                        true,
                        $statusCode
                    );
                }

                if ($statusCode >= 500) {
                    throw new TikTokPublishException("TikTok server error during chunk upload (HTTP {$statusCode}).", 'REMOTE_SERVER_ERROR', true, $statusCode);
                }

                throw new TikTokPublishException(
                    "Unexpected response code during chunk upload: {$statusCode} - " . $res->body(),
                    'PROVIDER_ERROR',
                    false,
                    $statusCode
                );
            }
        } finally {
            if (is_resource($handle)) {
                fclose($handle);
            }
        }
    }

    /**
     * Check TikTok remote processing status and transition job.
     */
    protected function checkRemoteProcessingStatus(
        string $publishId,
        string $videoUrl,
        PublishContext $context,
        string $creatorUsername = 'creator'
    ): PublishResult {
        $job = $context->job;
        $statusResult = $this->getStatus($publishId, $context);

        if ($statusResult->status === 'SUCCESS') {
            $remoteUrl = $statusResult->remoteUrl ?? "https://www.tiktok.com/@{$creatorUsername}/video/{$publishId}";

            if ($job->canTransitionTo('PUBLISHED')) {
                $job->transitionTo('PUBLISHED', [
                    'remote_post_id'            => $publishId,
                    'remote_post_url'           => $remoteUrl,
                    'upload_session_url'        => null,
                    'bytes_uploaded'            => $job->total_bytes ?: $job->bytes_uploaded,
                    'failure_category'          => null,
                    'user_facing_error_message' => null,
                    'internal_error_details'    => null,
                ]);
            } else {
                $job->update([
                    'status'                    => 'PUBLISHED',
                    'remote_post_id'            => $publishId,
                    'remote_post_url'           => $remoteUrl,
                    'upload_session_url'        => null,
                    'bytes_uploaded'            => $job->total_bytes ?: $job->bytes_uploaded,
                    'failure_category'          => null,
                    'user_facing_error_message' => null,
                    'internal_error_details'    => null,
                ]);
            }

            event(new TikTokPublishingCompleted($job->id, $job->workspace_id, $publishId, $remoteUrl));

            return new PublishResult(
                remoteId: $publishId,
                remoteUrl: $remoteUrl,
                requiresPolling: false,
                statusDetails: 'TikTok video processed successfully and published'
            );
        }

        if ($statusResult->status === 'FAILED') {
            throw new TikTokPublishException(
                $statusResult->errorMessage ?? 'TikTok video processing failed or was rejected.',
                'PROVIDER_ERROR',
                false,
                422
            );
        }

        // status === 'PROCESSING'
        return new PublishResult(
            remoteId: $publishId,
            remoteUrl: $videoUrl,
            requiresPolling: true,
            statusDetails: 'Video uploaded to TikTok; moderation and transcoding in progress'
        );
    }

    public function getStatus(string $remoteId, PublishContext $context): RemoteStatusResult
    {
        $account = $context->account;
        if ($account && $account->workspace_id !== $context->job->workspace_id) {
            throw new TikTokPublishException(
                "Tenant violation: Connected account does not belong to job workspace.",
                'AUTHORIZATION_ERROR',
                false,
                403
            );
        }

        $token = $account ? $this->tokenRefreshService->refreshIfExpired($account) : '';
        if (empty($token)) {
            $token = $context->decryptedAccessToken ?: ($account?->encrypted_access_token ?? '');
        }

        if (empty($token)) {
            if ($account) {
                event(new TikTokAuthRequired($context->job->id, $context->job->workspace_id, $account->id, 'TOKEN_MISSING_FOR_STATUS'));
            }
            throw new TikTokPublishException(
                "No valid OAuth access token available to check video processing status.",
                'AUTH_REQUIRED',
                false,
                401
            );
        }

        $res = Http::withToken($token)
            ->withHeaders(['Content-Type' => 'application/json; charset=UTF-8'])
            ->post('https://open.tiktokapis.com/v2/post/publish/status/fetch/', [
                'publish_id' => $remoteId,
            ]);

        if ($res->status() === 401) {
            if ($account) {
                event(new TikTokAuthRequired($context->job->id, $context->job->workspace_id, $account->id, 'TOKEN_EXPIRED_DURING_STATUS_POLL'));
            }
            throw new TikTokPublishException(
                "TikTok authentication expired while checking video processing status. Reconnect required.",
                'AUTH_REQUIRED',
                false,
                401
            );
        }

        if ($res->status() === 429) {
            throw new TikTokPublishException(
                "TikTok rate limit encountered during status check.",
                'RATE_LIMITED',
                true,
                429
            );
        }

        if ($res->serverError()) {
            throw new TikTokPublishException(
                "TikTok servers temporarily unavailable during processing status check (HTTP {$res->status()}).",
                'REMOTE_SERVER_ERROR',
                true,
                $res->status()
            );
        }

        $body = $res->json() ?? [];
        $data = $body['data'] ?? [];
        $status = $data['status'] ?? null;
        $failReason = $data['fail_reason'] ?? null;

        $creatorHandle = $account?->account_handle ? ltrim($account->account_handle, '@') : 'creator';

        if ($status === 'PUBLISH_COMPLETE' || $status === 'SUCCESS') {
            $postId = $data['publicly_available_post_id'][0] ?? $data['publicaly_available_post_id'][0] ?? $remoteId;
            $videoUrl = "https://www.tiktok.com/@{$creatorHandle}/video/{$postId}";
            return new RemoteStatusResult('SUCCESS', $videoUrl);
        }

        if ($status === 'FAILED') {
            $isUnaudited = str_contains(strtolower($failReason ?? ''), 'unaudited');
            $errMsg = $isUnaudited
                ? "TikTok developer app audit required: Content restricted to private viewing until TikTok application review is complete."
                : ("TikTok publishing failed: " . ($failReason ?: 'Content rejected by moderation'));

            return new RemoteStatusResult(
                status: 'FAILED',
                remoteUrl: null,
                errorMessage: $errMsg
            );
        }

        // Still PROCESSING_UPLOAD or PROCESSING_DOWNLOAD
        return new RemoteStatusResult('PROCESSING');
    }

    public function normalizeError(Throwable $error): array
    {
        if ($error instanceof TikTokPublishException) {
            return [
                'category'     => $error->failureCategory,
                'user_message' => $error->getMessage(),
                'retryable'    => $error->isRetryable,
                'http_status'  => $error->httpStatus,
                'code'         => $error->providerErrorCode,
            ];
        }

        $message = strtolower($error->getMessage());

        if (str_contains($message, 'token') || str_contains($message, 'unauthorized') || str_contains($message, '401') || str_contains($message, 'invalid_token')) {
            return [
                'category'     => 'AUTH_REQUIRED',
                'user_message' => 'TikTok authorization expired. Reconnect your TikTok account to proceed.',
                'retryable'    => false,
            ];
        }

        if (str_contains($message, 'rate_limit') || str_contains($message, 'rate limit') || str_contains($message, '429')) {
            return [
                'category'     => 'RATE_LIMITED',
                'user_message' => 'TikTok rate limit encountered. OmniPublish will back off and retry.',
                'retryable'    => true,
            ];
        }

        if (str_contains($message, 'timeout') || str_contains($message, 'curl') || str_contains($message, 'connection')) {
            return [
                'category'     => 'NETWORK_ERROR',
                'user_message' => 'Network error connecting to TikTok. Resuming upload on retry.',
                'retryable'    => true,
            ];
        }

        if (str_contains($message, 'upload_session_expired') || str_contains($message, 'upload session expired')) {
            return [
                'category'     => 'UPLOAD_SESSION_EXPIRED',
                'user_message' => 'TikTok upload session expired. OmniPublish will re-initialize and retry upload.',
                'retryable'    => true,
            ];
        }

        if (str_contains($message, '500') || str_contains($message, '502') || str_contains($message, '503') || str_contains($message, '504')) {
            return [
                'category'     => 'REMOTE_SERVER_ERROR',
                'user_message' => 'TikTok servers are temporarily unavailable. Retrying with exponential backoff.',
                'retryable'    => true,
            ];
        }

        if (str_contains($message, 'privacy') || str_contains($message, 'comment') || str_contains($message, 'duet') || str_contains($message, 'stitch') || str_contains($message, 'capability')) {
            return [
                'category'     => 'CREATOR_CAPABILITY_ERROR',
                'user_message' => 'TikTok rejected requested settings: ' . substr($error->getMessage(), 0, 150),
                'retryable'    => false,
            ];
        }

        if (str_contains($message, 'duration') || str_contains($message, 'codec') || str_contains($message, 'media') || str_contains($message, 'dimension')) {
            return [
                'category'     => 'INVALID_MEDIA',
                'user_message' => 'TikTok rejected media format: ' . substr($error->getMessage(), 0, 150),
                'retryable'    => false,
            ];
        }

        if (str_contains($message, 'spam') || str_contains($message, 'sensitive') || str_contains($message, 'caption') || str_contains($message, 'title')) {
            return [
                'category'     => 'INVALID_METADATA',
                'user_message' => 'TikTok rejected video caption: ' . substr($error->getMessage(), 0, 150),
                'retryable'    => false,
            ];
        }

        return [
            'category'     => 'PROVIDER_ERROR',
            'user_message' => 'TikTok publishing failed: ' . substr($error->getMessage(), 0, 150),
            'retryable'    => false,
        ];
    }

    public function classifyError(Throwable $error): array
    {
        return $this->normalizeError($error);
    }

    protected function preparePostInfo(array $global, array $overrides, array $creatorInfo): array
    {
        $caption = $overrides['caption']
            ?? $overrides['title']
            ?? $global['caption']
            ?? $global['description']
            ?? $global['title']
            ?? '';

        if (!empty($global['hashtags']) && is_array($global['hashtags'])) {
            $tagsStr = implode(' ', array_map(fn($t) => '#' . ltrim($t, '#'), $global['hashtags']));
            $caption = trim($caption . ' ' . $tagsStr);
        }

        $caption = mb_substr(trim($caption), 0, 2200);

        $privacy = $overrides['privacy_level']
            ?? $overrides['privacyLevel']
            ?? 'PUBLIC_TO_EVERYONE';

        return [
            'title'                    => $caption,
            'privacy_level'            => $privacy,
            'disable_comment'          => (bool) ($overrides['disable_comment'] ?? $overrides['disableComment'] ?? false),
            'disable_duet'             => (bool) ($overrides['disable_duet'] ?? $overrides['disableDuet'] ?? false),
            'disable_stitch'           => (bool) ($overrides['disable_stitch'] ?? $overrides['disableStitch'] ?? false),
            'video_cover_timestamp_ms' => (int) ($overrides['video_cover_timestamp_ms'] ?? 1000),
            'brand_content_toggle'     => (bool) ($overrides['brand_content_toggle'] ?? false),
            'brand_organic_toggle'     => (bool) ($overrides['brand_organic_toggle'] ?? false),
        ];
    }

    protected function obtainLocalMediaFile(MediaAsset $media, string $scratchDir): string
    {
        if (!is_dir($scratchDir)) {
            mkdir($scratchDir, 0755, true);
        }

        $ext = pathinfo($media->storage_path, PATHINFO_EXTENSION) ?: 'mp4';
        $localPath = $scratchDir . DIRECTORY_SEPARATOR . 'tiktok_upload_source.' . $ext;

        $disk = config('filesystems.default', 'public');
        $storage = Storage::disk($disk);

        if (!$storage->exists($media->storage_path)) {
            throw new TikTokPublishException("Source media asset not found in storage: {$media->storage_path}", 'INVALID_MEDIA', false, 404);
        }

        $sourceStream = $storage->readStream($media->storage_path);
        if (!$sourceStream) {
            throw new TikTokPublishException("Failed to open source storage stream for {$media->storage_path}", 'INVALID_MEDIA', false);
        }

        $destStream = fopen($localPath, 'wb');
        stream_copy_to_stream($sourceStream, $destStream);
        fclose($destStream);
        if (is_resource($sourceStream)) {
            fclose($sourceStream);
        }

        return $localPath;
    }
}
