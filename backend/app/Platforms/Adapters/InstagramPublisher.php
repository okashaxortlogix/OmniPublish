<?php

namespace App\Platforms\Adapters;

use App\Events\InstagramAccountValidated;
use App\Events\InstagramAuthRequired;
use App\Events\InstagramMediaContainerCreated;
use App\Events\InstagramMediaProcessingStarted;
use App\Events\InstagramPublishingCompleted;
use App\Events\InstagramPublishingFailed;
use App\Events\InstagramPublishingStarted;
use App\Models\MediaAsset;
use App\Models\PublishJob;
use App\Models\SocialAccount;
use App\Platforms\Contracts\PlatformAdapterInterface;
use App\Platforms\Contracts\PlatformCapabilities;
use App\Platforms\Contracts\PublishContext;
use App\Platforms\Contracts\PublishResult;
use App\Platforms\Contracts\RemoteStatusResult;
use App\Platforms\Exceptions\InstagramPublishException;
use App\Platforms\Profiles\InstagramMediaProfile;
use App\Services\MediaStorageService;
use App\Services\TokenRefreshService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

class InstagramPublisher implements PlatformAdapterInterface
{
    protected TokenRefreshService $tokenRefreshService;
    protected MediaStorageService $mediaStorageService;

    public function __construct(
        ?TokenRefreshService $tokenRefreshService = null,
        ?MediaStorageService $mediaStorageService = null
    ) {
        $this->tokenRefreshService = $tokenRefreshService ?? app(TokenRefreshService::class);
        $this->mediaStorageService = $mediaStorageService ?? app(MediaStorageService::class);
    }

    public function getPlatformName(): string
    {
        return 'INSTAGRAM';
    }

    public function getCapabilities(): PlatformCapabilities
    {
        return new PlatformCapabilities(
            platform: 'INSTAGRAM',
            supportsTitle: false,
            supportsDescription: false,
            supportsCaption: true,
            supportsTags: false,
            supportsHashtags: true,
            supportsCustomThumbnail: true,
            supportsNativeScheduling: false,
            supportsAnalytics: true,
            requiresCategory: false,
            limits: [
                'maxCaptionLength'        => 2200,
                'maxHashtagsCount'        => 30,
                'minVideoDurationSeconds' => 3,
                'maxVideoDurationSeconds' => 900,
                'maxFileSizeMB'           => 1024,
                'supportedAspectRatios'   => ['9:16', '1:1', '4:5', '16:9'],
            ]
        );
    }

    public function validate(array $globalMetadata, array $overrides, ?array $media = null): array
    {
        $issues = [];
        $igOverrides = $overrides['instagram'] ?? $overrides;

        // 1. Caption length validation (max 2,200 characters)
        $caption = $igOverrides['caption']
            ?? $globalMetadata['caption']
            ?? $globalMetadata['description']
            ?? $globalMetadata['title']
            ?? '';

        if (mb_strlen($caption) > 2200) {
            $issues[] = [
                'field' => 'caption',
                'severity' => 'ERROR',
                'message' => 'Instagram caption cannot exceed 2,200 characters.'
            ];
        }

        // 2. Hashtags validation (max 30 hashtags)
        $hashtags = $igOverrides['hashtags'] ?? $globalMetadata['hashtags'] ?? ($media['hashtags'] ?? []);
        if (count($hashtags) > 30) {
            $issues[] = [
                'field' => 'hashtags',
                'severity' => 'ERROR',
                'message' => 'Instagram permits a maximum of 30 hashtags per post.'
            ];
        }

        // 3. Media preflight validation if provided
        if ($media !== null) {
            $status = $media['status'] ?? '';
            if (!empty($status) && !in_array($status, ['READY', 'PROCESSED'])) {
                $issues[] = [
                    'field' => 'media',
                    'severity' => 'ERROR',
                    'message' => "Media asset status is [{$status}]. Asset must be processed and READY before publishing."
                ];
            }

            $isImage = str_starts_with(strtolower($media['mime_type'] ?? ''), 'image/')
                || in_array(strtolower(pathinfo($media['filename'] ?? '', PATHINFO_EXTENSION)), InstagramMediaProfile::SUPPORTED_IMAGE_FORMATS, true);
            $mediaType = $isImage ? 'IMAGE' : 'REELS';
            $sizeBytes = (int) ($media['size_bytes'] ?? 0);

            if ($mediaType === 'IMAGE' && $sizeBytes > InstagramMediaProfile::MAX_IMAGE_SIZE_BYTES) {
                $mb = round($sizeBytes / 1048576, 2);
                $issues[] = [
                    'field' => 'size_bytes',
                    'severity' => 'ERROR',
                    'message' => "Image file size ({$mb} MB) exceeds Instagram maximum limit of 8 MB."
                ];
            }

            if ($mediaType === 'REELS' && $sizeBytes > InstagramMediaProfile::MAX_VIDEO_SIZE_BYTES) {
                $mb = round($sizeBytes / (1024 * 1024 * 1024), 2);
                $issues[] = [
                    'field' => 'size_bytes',
                    'severity' => 'ERROR',
                    'message' => "Video file size ({$mb} GB) exceeds Instagram Reels maximum limit of 1 GB."
                ];
            }

            $duration = (float) ($media['duration_seconds'] ?? 0);
            if ($mediaType === 'REELS') {
                if ($duration > 0 && $duration < 3.0) {
                    $issues[] = [
                        'field' => 'duration_seconds',
                        'severity' => 'ERROR',
                        'message' => "Video duration ({$duration}s) is shorter than Instagram minimum of 3 seconds."
                    ];
                }

                if ($duration > 900.0) {
                    $issues[] = [
                        'field' => 'duration_seconds',
                        'severity' => 'ERROR',
                        'message' => "Video duration ({$duration}s) exceeds Instagram Reels maximum of 900 seconds (15 minutes)."
                    ];
                }
            }
        }

        return $issues;
    }

    public function refreshAuthentication(SocialAccount $account): string
    {
        return $this->tokenRefreshService->refreshIfExpired($account);
    }

    /**
     * Execute full production Instagram Content Publishing API flow.
     */
    public function publish(PublishContext $context): PublishResult
    {
        $job = $context->job;
        $account = $context->account;
        $media = $context->media;
        $global = $context->globalMetadata;
        $overrides = $context->platformOverrides['instagram'] ?? [];

        // 1. Ownership & Tenant Boundary Verification
        if ($account->workspace_id !== $job->workspace_id) {
            throw new InstagramPublishException(
                "Tenant violation: Connected account does not belong to job workspace.",
                'AUTHORIZATION_ERROR',
                false,
                403
            );
        }

        if ($media && $media->workspace_id !== $job->workspace_id) {
            throw new InstagramPublishException(
                "Tenant violation: Media asset does not belong to job workspace.",
                'AUTHORIZATION_ERROR',
                false,
                403
            );
        }

        if (!$media) {
            throw new InstagramPublishException(
                "Media asset is required for Instagram publishing.",
                'INVALID_MEDIA',
                false,
                422
            );
        }

        // 2. Validate Connected Account Status & Required Scopes
        if ($account->status !== 'ACTIVE') {
            event(new InstagramAuthRequired($job->id, $job->workspace_id, $account->id, 'ACCOUNT_INACTIVE'));
            throw new InstagramPublishException(
                "Instagram account is not active ({$account->status}). Reconnect required.",
                'AUTH_REQUIRED',
                false,
                401
            );
        }

        $scopes = $account->scopes ?? [];
        $hasPublishScope = false;
        foreach ($scopes as $scope) {
            if (trim($scope) === 'instagram_content_publish') {
                $hasPublishScope = true;
                break;
            }
        }

        if (!$hasPublishScope) {
            event(new InstagramAuthRequired($job->id, $job->workspace_id, $account->id, 'INSUFFICIENT_SCOPE'));
            throw new InstagramPublishException(
                "Instagram account lacks required publishing scope (instagram_content_publish). Reconnect with required permissions.",
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
            event(new InstagramAuthRequired($job->id, $job->workspace_id, $account->id, 'TOKEN_MISSING'));
            throw new InstagramPublishException(
                "No valid OAuth access token available for Instagram publishing.",
                'AUTH_REQUIRED',
                false,
                401
            );
        }

        $igUserId = $account->platform_account_id;

        // Check for mock / local demo simulation
        if (str_starts_with($token, 'mock_') || str_starts_with($token, 'demo_') || $token === 'test_token') {
            $simId = 'ig_' . Str::random(12);
            $simUrl = "https://www.instagram.com/reel/{$simId}";
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
                statusDetails: 'Simulated Instagram Reel published'
            );
        }

        // 4. Idempotency Check: Already Published or Existing Processing Container
        if (!empty($job->remote_post_id)) {
            if ($job->status === 'PUBLISHED') {
                Log::info("InstagramPublisher: Job {$job->id} is already published with remote ID {$job->remote_post_id}.");
                return new PublishResult(
                    remoteId: $job->remote_post_id,
                    remoteUrl: $job->remote_post_url ?? "https://www.instagram.com/p/{$job->remote_post_id}/",
                    requiresPolling: false,
                    statusDetails: 'Already published'
                );
            }

            // Check if existing remote_post_id is a container that has completed transcoding
            $containerId = $job->upload_session_url ?: $job->remote_post_id;
            Log::info("InstagramPublisher: Checking status of existing container {$containerId} for Job {$job->id}.");
            return $this->pollContainerAndPublish($containerId, $igUserId, $token, $context);
        }

        // 5. Media Asset Validation against Instagram Profile (Pre-network check)
        $isImage = str_starts_with(strtolower($media->mime_type ?? ''), 'image/')
            || in_array(strtolower(pathinfo($media->filename, PATHINFO_EXTENSION)), InstagramMediaProfile::SUPPORTED_IMAGE_FORMATS, true);
        $mediaType = $isImage ? 'IMAGE' : 'REELS';

        $mediaIssues = InstagramMediaProfile::validate($media, $mediaType);
        if (!empty($mediaIssues)) {
            $firstError = $mediaIssues[0]['message'];
            throw new InstagramPublishException($firstError, 'INVALID_MEDIA', false, 422);
        }

        // 6. Account Eligibility Check (Must be Instagram Professional: Business or Creator)
        $this->verifyAccountEligibility($igUserId, $token, $context);

        // 7. Check Content Publishing Limit (24-hour quota)
        $this->checkPublishingLimit($igUserId, $token, $job);

        // 8. Obtain Publicly Accessible Media URL
        $mediaUrl = $this->obtainAccessibleMediaUrl($media);

        // 9. Prepare Caption
        $caption = $this->prepareCaption($global, $overrides);

        event(new InstagramPublishingStarted($job->id, $job->workspace_id, $account->id, $media->id, $mediaType));

        try {
            // 10. Create Media Container
            $containerId = $this->createMediaContainer($igUserId, $mediaUrl, $caption, $mediaType, $overrides, $token, $job);

            $job->update([
                'upload_session_url' => $containerId,
                'remote_post_id'     => $containerId,
            ]);

            event(new InstagramMediaContainerCreated($job->id, $job->workspace_id, $containerId, $mediaType));

            // 11. Check Container Transcoding / Publishing Status
            return $this->pollContainerAndPublish($containerId, $igUserId, $token, $context);

        } catch (Throwable $e) {
            $normalized = $this->normalizeError($e);
            event(new InstagramPublishingFailed(
                $job->id,
                $job->workspace_id,
                $normalized['category'],
                $normalized['user_message'],
                $normalized['retryable']
            ));

            throw $e;
        }
    }

    /**
     * Check container processing status; if finished, publish to Instagram.
     */
    protected function pollContainerAndPublish(
        string $containerId,
        string $igUserId,
        string $token,
        PublishContext $context
    ): PublishResult {
        $job = $context->job;
        $statusRes = $this->checkContainerStatus($containerId, $token);
        $statusCode = $statusRes['status_code'];

        if ($statusCode === 'FINISHED') {
            // Media processing finished -> Execute media_publish
            Log::info("Instagram container {$containerId} finished transcoding. Publishing to Instagram feed/Reels.");
            $publishId = $this->executeMediaPublish($igUserId, $containerId, $token);
            $permalink = $this->fetchMediaPermalink($publishId, $token);

            $job->transitionTo('PUBLISHED', [
                'remote_post_id'            => $publishId,
                'remote_post_url'           => $permalink,
                'upload_session_url'        => null,
                'failure_category'          => null,
                'user_facing_error_message' => null,
                'internal_error_details'    => null,
            ]);

            event(new InstagramPublishingCompleted($job->id, $job->workspace_id, $publishId, $permalink));

            return new PublishResult(
                remoteId: $publishId,
                remoteUrl: $permalink,
                requiresPolling: false,
                statusDetails: 'Published directly to Instagram'
            );
        }

        if ($statusCode === 'EXPIRED') {
            // Container expired (containers expire after 24 hours if unpublished).
            // Reset upload_session_url and remote_post_id to allow fresh container creation on retry.
            $job->update([
                'upload_session_url' => null,
                'remote_post_id'     => null,
            ]);
            throw new InstagramPublishException("Instagram media container expired. Container will be re-created on retry.", 'CONTAINER_EXPIRED', true, 410);
        }

        if ($statusCode === 'ERROR') {
            $errorMsg = $statusRes['status'] ?? 'Instagram video processing failed during transcoding.';
            throw new InstagramPublishException("Instagram media processing failed: {$errorMsg}", 'MEDIA_PROCESSING_FAILED', false, 422);
        }

        // status_code === 'IN_PROGRESS'
        if ($job->status !== 'PROCESSING') {
            if ($job->canTransitionTo('PROCESSING')) {
                $job->transitionTo('PROCESSING');
            } else {
                $job->update(['status' => 'PROCESSING']);
            }
        }

        event(new InstagramMediaProcessingStarted($job->id, $job->workspace_id, $containerId));

        return new PublishResult(
            remoteId: $containerId,
            remoteUrl: null,
            requiresPolling: true,
            statusDetails: 'Video transcoding in progress by Instagram'
        );
    }

    /**
     * Check asynchronous remote transcoding status.
     */
    public function getStatus(string $remoteId, PublishContext $context): RemoteStatusResult
    {
        $account = $context->account;
        if ($account && $account->workspace_id !== $context->job->workspace_id) {
            throw new InstagramPublishException(
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
            throw new InstagramPublishException("No valid OAuth token available to check Instagram status.", 'AUTH_REQUIRED', false, 401);
        }

        $igUserId = $account->platform_account_id;

        try {
            $statusRes = $this->checkContainerStatus($remoteId, $token);
            $statusCode = $statusRes['status_code'];

            if ($statusCode === 'FINISHED') {
                // Transcoding finished: publish and obtain real media ID
                $mediaId = $this->executeMediaPublish($igUserId, $remoteId, $token);
                $permalink = $this->fetchMediaPermalink($mediaId, $token);
                return new RemoteStatusResult('SUCCESS', $permalink);
            }

            if ($statusCode === 'ERROR') {
                return new RemoteStatusResult('FAILED', null, $statusRes['status'] ?? 'Instagram video rendering error');
            }

            return new RemoteStatusResult('PROCESSING');
        } catch (Throwable $e) {
            // If remoteId was already a published media post ID, check permalink directly
            try {
                $permalink = $this->fetchMediaPermalink($remoteId, $token);
                return new RemoteStatusResult('SUCCESS', $permalink);
            } catch (Throwable) {
                $normalized = $this->normalizeError($e);
                if ($normalized['category'] === 'AUTH_REQUIRED') {
                    throw $e;
                }
                return new RemoteStatusResult('PROCESSING');
            }
        }
    }

    /**
     * Get Centralized Graph API Base URL from configuration.
     */
    public function getGraphBaseUrl(): string
    {
        $host = rtrim(config('services.facebook.graph_host', 'https://graph.facebook.com'), '/');
        $version = trim(config('services.facebook.graph_api_version', 'v21.0'), '/');
        return "{$host}/{$version}";
    }

    /**
     * Verify Instagram account eligibility (Business or Creator account).
     */
    protected function verifyAccountEligibility(string $igUserId, string $token, PublishContext $context): void
    {
        $res = Http::get("{$this->getGraphBaseUrl()}/{$igUserId}", [
            'fields'       => 'account_type,username,name',
            'access_token' => $token,
        ]);

        if ($res->status() === 401 || $res->json('error.code') === 190) {
            event(new InstagramAuthRequired($context->job->id, $context->job->workspace_id, $context->account->id, 'ACCOUNT_AUTH_EXPIRED'));
            throw new InstagramPublishException("Instagram authorization expired. Reconnect your account.", 'AUTH_REQUIRED', false, 401);
        }

        if ($res->failed()) {
            $msg = $res->json('error.message') ?? $res->body();
            throw new InstagramPublishException("Failed to verify Instagram account: {$msg}", 'PROVIDER_ERROR', false, $res->status());
        }

        $accountType = strtoupper($res->json('account_type') ?? '');
        if ($accountType === 'PERSONAL') {
            throw new InstagramPublishException(
                "Instagram account is a Personal account. For OmniPublish's selected authentication configuration (Instagram Graph API with Facebook Login), the Instagram Content Publishing API strictly requires a Professional (Business or Creator) account connected to a Facebook Page.",
                'ACCOUNT_NOT_ELIGIBLE',
                false,
                422
            );
        }

        event(new InstagramAccountValidated(
            $context->job->id,
            $context->job->workspace_id,
            $context->account->id,
            $igUserId,
            $accountType ?: 'PROFESSIONAL'
        ));
    }

    /**
     * Check Meta content publishing limit (rolling 24h quota).
     */
    protected function checkPublishingLimit(string $igUserId, string $token, PublishJob $job): void
    {
        try {
            $res = Http::get("{$this->getGraphBaseUrl()}/{$igUserId}/content_publishing_limit", [
                'fields'       => 'config,quota_usage',
                'access_token' => $token,
            ]);

            if ($res->status() === 429 || in_array($res->json('error.code'), [4, 17, 32], true)) {
                throw new InstagramPublishException("Instagram rate limit reached when checking quota.", 'RATE_LIMITED', true, 429);
            }

            if ($res->successful()) {
                $data = $res->json('data.0') ?? $res->json('data') ?? [];
                if (!empty($data) && is_array($data)) {
                    $quotaTotal = (int) ($data['config']['quota_total'] ?? 100);
                    $quotaUsage = (int) ($data['quota_usage'] ?? 0);

                    if ($quotaTotal > 0 && $quotaUsage >= $quotaTotal) {
                        throw new InstagramPublishException(
                            "Instagram 24-hour content publishing quota reached ({$quotaUsage}/{$quotaTotal} posts published).",
                            'RATE_LIMITED',
                            true,
                            429
                        );
                    }
                }
            }
        } catch (InstagramPublishException $e) {
            throw $e;
        } catch (Throwable $e) {
            // Non-fatal if test sandbox or non-business account doesn't return quota endpoint
            Log::warning("InstagramPublisher: Failed checking content_publishing_limit for {$igUserId}: {$e->getMessage()}");
        }
    }

    /**
     * Create an Instagram Media Container via POST /{ig_user_id}/media
     */
    protected function createMediaContainer(
        string $igUserId,
        string $mediaUrl,
        string $caption,
        string $mediaType,
        array $overrides,
        string $token,
        PublishJob $job
    ): string {
        $params = [
            'caption'      => $caption,
            'access_token' => $token,
        ];

        if ($mediaType === 'IMAGE') {
            $params['image_url'] = $mediaUrl;
        } else {
            $params['media_type'] = 'REELS';
            $params['video_url']  = $mediaUrl;
            $params['share_to_feed'] = (bool) ($overrides['shareToFeed'] ?? $overrides['share_to_feed'] ?? true);

            if (!empty($overrides['cover_url'])) {
                $params['cover_url'] = $overrides['cover_url'];
            }
            if (isset($overrides['thumb_offset'])) {
                $params['thumb_offset'] = (int) $overrides['thumb_offset'];
            }
        }

        $res = Http::post("{$this->getGraphBaseUrl()}/{$igUserId}/media", $params);

        if ($res->status() === 401 || $res->json('error.code') === 190) {
            event(new InstagramAuthRequired($job->id, $job->workspace_id, $job->social_account_id, 'CONTAINER_CREATION_UNAUTHORIZED'));
            throw new InstagramPublishException("Instagram authentication expired during container creation.", 'AUTH_REQUIRED', false, 401);
        }

        if ($res->status() === 429 || in_array($res->json('error.code'), [4, 17, 32], true)) {
            throw new InstagramPublishException("Instagram rate limit exceeded during container creation.", 'RATE_LIMITED', true, 429);
        }

        if ($res->serverError()) {
            throw new InstagramPublishException("Meta servers temporarily unavailable (HTTP {$res->status()}).", 'REMOTE_SERVER_ERROR', true, $res->status());
        }

        if ($res->failed() || !$res->json('id')) {
            $errorMsg = $res->json('error.message') ?? $res->body();
            $errorCode = (string) ($res->json('error.code') ?? '');
            $errorSubcode = (string) ($res->json('error.error_subcode') ?? '');

            if (str_contains(strtolower($errorMsg), 'permission') || $errorCode === '200' || $errorCode === '10') {
                throw new InstagramPublishException("Missing permission to publish to Instagram: {$errorMsg}", 'INVALID_PERMISSION', false, 403, $errorCode);
            }

            if (str_contains(strtolower($errorMsg), 'aspect ratio') || str_contains(strtolower($errorMsg), 'duration') || $errorSubcode === '2207003') {
                throw new InstagramPublishException("Media format rejected by Instagram: {$errorMsg}", 'INVALID_MEDIA', false, 422, $errorCode);
            }

            throw new InstagramPublishException("Instagram container creation failed: {$errorMsg}", 'PROVIDER_ERROR', false, $res->status(), $errorCode);
        }

        return $res->json('id');
    }

    /**
     * Check status of an Instagram Media Container via GET /{container_id}?fields=status_code,status
     */
    protected function checkContainerStatus(string $containerId, string $token): array
    {
        $res = Http::get("{$this->getGraphBaseUrl()}/{$containerId}", [
            'fields'       => 'status_code,status',
            'access_token' => $token,
        ]);

        if ($res->status() === 401 || $res->json('error.code') === 190) {
            throw new InstagramPublishException("Instagram token expired while polling container status.", 'AUTH_REQUIRED', false, 401);
        }

        if ($res->status() === 429 || in_array($res->json('error.code'), [4, 17, 32], true)) {
            throw new InstagramPublishException("Instagram rate limit exceeded while polling container status.", 'RATE_LIMITED', true, 429);
        }

        if ($res->serverError()) {
            throw new InstagramPublishException("Meta servers temporarily unavailable during status poll.", 'REMOTE_SERVER_ERROR', true, $res->status());
        }

        $body = $res->json() ?? [];
        $statusCode = strtoupper($body['status_code'] ?? '');

        return [
            'status_code' => $statusCode ?: 'PROCESSING',
            'status'      => $body['status'] ?? null,
        ];
    }

    /**
     * Execute final publishing of container via POST /{ig_user_id}/media_publish
     */
    protected function executeMediaPublish(string $igUserId, string $containerId, string $token): string
    {
        $res = Http::post("{$this->getGraphBaseUrl()}/{$igUserId}/media_publish", [
            'creation_id'  => $containerId,
            'access_token' => $token,
        ]);

        if ($res->status() === 401 || $res->json('error.code') === 190) {
            throw new InstagramPublishException("Instagram token expired during media publishing.", 'AUTH_REQUIRED', false, 401);
        }

        if ($res->status() === 429 || in_array($res->json('error.code'), [4, 17, 32], true)) {
            throw new InstagramPublishException("Instagram rate limit exceeded during media publishing.", 'RATE_LIMITED', true, 429);
        }

        if ($res->serverError()) {
            throw new InstagramPublishException("Meta servers temporarily unavailable during media publish.", 'REMOTE_SERVER_ERROR', true, $res->status());
        }

        if ($res->failed() || !$res->json('id')) {
            $errorMsg = $res->json('error.message') ?? $res->body();
            $errorCode = (string) ($res->json('error.code') ?? '');
            $errorSubcode = (string) ($res->json('error.error_subcode') ?? '');

            if (str_contains(strtolower($errorMsg), 'already published') || $errorSubcode === '2207027') {
                Log::warning("InstagramPublisher: Container {$containerId} was already published on Meta. Reconciling as published.");
                return $containerId;
            }

            throw new InstagramPublishException("Failed to publish Instagram media container: {$errorMsg}", 'PROVIDER_ERROR', false, $res->status());
        }

        return $res->json('id');
    }

    /**
     * Fetch published media permalink via GET /{media_id}?fields=permalink,media_type
     */
    protected function fetchMediaPermalink(string $mediaId, string $token): string
    {
        $res = Http::get("{$this->getGraphBaseUrl()}/{$mediaId}", [
            'fields'       => 'permalink,media_type',
            'access_token' => $token,
        ]);

        if ($res->successful() && $res->json('permalink')) {
            return $res->json('permalink');
        }

        return "https://www.instagram.com/p/{$mediaId}/";
    }

    public function classifyError(Throwable $error): array
    {
        return $this->normalizeError($error);
    }

    /**
     * Normalize Instagram Graph API error into OmniPublish standard error taxonomy.
     */
    public function normalizeError(Throwable $error): array
    {
        if ($error instanceof InstagramPublishException) {
            return [
                'category'     => $error->failureCategory,
                'user_message' => $error->getMessage(),
                'retryable'    => $error->isRetryable,
                'http_status'  => $error->httpStatus,
                'code'         => $error->providerErrorCode,
            ];
        }

        $message = strtolower($error->getMessage());

        if (str_contains($message, 'timeout') || str_contains($message, 'curl') || str_contains($message, 'connection')) {
            return [
                'category'     => 'NETWORK_ERROR',
                'user_message' => 'Network error connecting to Instagram. Retrying.',
                'retryable'    => true,
            ];
        }

        if (
            str_contains($message, 'aspect ratio') ||
            str_contains($message, 'duration') ||
            str_contains($message, 'resolution') ||
            str_contains($message, 'dimension') ||
            str_contains($message, 'format') ||
            str_contains($message, 'exceeds instagram') ||
            str_contains($message, 'shorter than instagram') ||
            str_contains($message, 'longer than instagram') ||
            str_contains($message, 'media') ||
            str_contains($message, 'video') ||
            str_contains($message, 'image')
        ) {
            return [
                'category'     => 'INVALID_MEDIA',
                'user_message' => 'Instagram rejected media specifications: ' . substr($error->getMessage(), 0, 150),
                'retryable'    => false,
            ];
        }

        if (
            str_contains($message, 'oauthexception') ||
            str_contains($message, 'error validating access token') ||
            str_contains($message, 'invalid oauth access token') ||
            str_contains($message, 'session has expired') ||
            str_contains($message, 'token expired') ||
            str_contains($message, 'token has expired') ||
            str_contains($message, 'authorization expired') ||
            str_contains($message, 'authorization has expired') ||
            str_contains($message, 'reconnect required') ||
            str_contains($message, 'unauthorized') ||
            (str_contains($message, '190') && !str_contains($message, '1920'))
        ) {
            return [
                'category'     => 'AUTH_REQUIRED',
                'user_message' => 'Instagram authorization expired. Reconnect your account in Connections and retry.',
                'retryable'    => false,
            ];
        }

        if (str_contains($message, 'not eligible') || str_contains($message, 'personal account') || str_contains($message, 'account_not_eligible')) {
            return [
                'category'     => 'ACCOUNT_NOT_ELIGIBLE',
                'user_message' => 'Instagram account is not eligible: Content Publishing requires a Professional (Business or Creator) account.',
                'retryable'    => false,
            ];
        }

        if (str_contains($message, 'permission') || str_contains($message, '200') || str_contains($message, '10') || str_contains($message, 'instagram_content_publish')) {
            return [
                'category'     => 'INVALID_PERMISSION',
                'user_message' => 'Instagram account lacks necessary publishing permissions. Please re-authorize.',
                'retryable'    => false,
            ];
        }

        if (str_contains($message, 'rate_limit') || str_contains($message, '429') || str_contains($message, 'too many calls')) {
            return [
                'category'     => 'RATE_LIMITED',
                'user_message' => 'Meta API rate limit reached. OmniPublish will back off and retry automatically.',
                'retryable'    => true,
            ];
        }

        if (str_contains($message, '500') || str_contains($message, '502') || str_contains($message, '503') || str_contains($message, '504') || str_contains($message, 'server error')) {
            return [
                'category'     => 'REMOTE_SERVER_ERROR',
                'user_message' => 'Meta servers are temporarily unavailable. Retrying with exponential backoff.',
                'retryable'    => true,
            ];
        }

        if (str_contains($message, 'container_expired') || str_contains($message, 'container expired')) {
            return [
                'category'     => 'CONTAINER_EXPIRED',
                'user_message' => 'Instagram media container expired. Retrying with fresh container.',
                'retryable'    => true,
            ];
        }

        return [
            'category'     => 'PROVIDER_ERROR',
            'user_message' => 'Instagram publishing failed: ' . substr($error->getMessage(), 0, 150),
            'retryable'    => false,
        ];
    }

    protected function obtainAccessibleMediaUrl(MediaAsset $media): string
    {
        if (!empty($media->url) && (str_starts_with($media->url, 'http://') || str_starts_with($media->url, 'https://'))) {
            return $media->url;
        }

        if (!empty($media->storage_path)) {
            try {
                // Generate a temporary presigned URL valid for 4 hours for Meta's media crawler
                $temporaryUrl = $this->mediaStorageService->getTemporaryUrl($media->storage_path, now()->addHours(4));
                if (!empty($temporaryUrl)) {
                    return $temporaryUrl;
                }
            } catch (Throwable $e) {
                Log::warning("InstagramPublisher: Unable to generate presigned URL for media {$media->id}: {$e->getMessage()}");
            }

            $publicUrl = $this->mediaStorageService->getUrl($media->storage_path);
            if (!empty($publicUrl)) {
                return $publicUrl;
            }
        }

        // Fallback for test / dev environment
        return url("/storage/{$media->storage_path}");
    }

    protected function prepareCaption(array $global, array $overrides): string
    {
        $caption = $overrides['caption']
            ?? $global['caption']
            ?? $global['description']
            ?? $global['title']
            ?? '';

        if (!empty($global['hashtags']) && is_array($global['hashtags'])) {
            $tagsStr = implode(' ', array_map(fn($t) => '#' . ltrim($t, '#'), $global['hashtags']));
            $caption = trim($caption . "\n\n" . $tagsStr);
        }

        return mb_substr(trim($caption), 0, 2200);
    }
}
