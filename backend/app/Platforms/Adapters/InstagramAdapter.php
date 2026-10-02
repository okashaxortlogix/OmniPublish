<?php

namespace App\Platforms\Adapters;

use App\Platforms\Contracts\PlatformAdapterInterface;
use App\Platforms\Contracts\PlatformCapabilities;
use App\Platforms\Contracts\PublishContext;
use App\Platforms\Contracts\PublishResult;
use App\Platforms\Contracts\RemoteStatusResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class InstagramAdapter implements PlatformAdapterInterface
{
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
                'maxCaptionLength' => 2200,
                'maxHashtagsCount' => 30,
                'maxVideoDurationSeconds' => 900,
                'minVideoDurationSeconds' => 3,
                'maxFileSizeMB' => 1000,
                'supportedAspectRatios' => ['9:16', '1:1', '4:5'],
            ]
        );
    }

    public function validate(array $globalMetadata, array $overrides, ?array $media = null): array
    {
        $issues = [];
        $caption = $globalMetadata['caption'] ?? $globalMetadata['description'] ?? $globalMetadata['title'] ?? '';

        if (mb_strlen($caption) > 2200) {
            $issues[] = [
                'field' => 'caption',
                'severity' => 'ERROR',
                'message' => 'Instagram caption cannot exceed 2,200 characters.'
            ];
        }

        $hashtags = $globalMetadata['hashtags'] ?? [];
        if (count($hashtags) > 30) {
            $issues[] = [
                'field' => 'hashtags',
                'severity' => 'ERROR',
                'message' => 'Instagram permits a maximum of 30 hashtags per post.'
            ];
        }

        return $issues;
    }

    public function publish(PublishContext $context): PublishResult
    {
        $token = $context->decryptedAccessToken;
        $global = $context->globalMetadata;
        $overrides = $context->platformOverrides['instagram'] ?? [];
        $igUserId = $context->account->platform_account_id;

        $caption = $global['caption'] ?? $global['description'] ?? $global['title'] ?? '';
        if (!empty($global['hashtags'])) {
            $tagsStr = implode(' ', array_map(fn($t) => '#' . ltrim($t, '#'), $global['hashtags']));
            $caption = trim($caption . "\n\n" . $tagsStr);
        }

        Log::info("Initiating Instagram Container Flow for Reels", [
            'job_id' => $context->job->id,
            'ig_user_id' => $igUserId,
        ]);

        if (str_starts_with($token, 'mock_') || app()->environment('testing', 'local')) {
            $mockMediaId = '179' . substr(crc32($context->job->id), 0, 14);
            return new PublishResult(
                remoteId: $mockMediaId,
                remoteUrl: "https://www.instagram.com/reel/C89" . substr(md5($mockMediaId), 0, 8) . "/",
                requiresPolling: false,
                statusDetails: 'Published Reel directly to Instagram'
            );
        }

        // Step 1: Create Container
        $createRes = Http::post("https://graph.facebook.com/v21.0/{$igUserId}/media", [
            'media_type' => 'REELS',
            'video_url' => $context->media->url,
            'caption' => $caption,
            'share_to_feed' => $overrides['shareToFeed'] ?? true,
            'access_token' => $token,
        ]);

        if ($createRes->failed()) {
            throw new \RuntimeException("Instagram Container Creation Failed: " . $createRes->body());
        }

        $containerId = $createRes->json('id');

        // Step 2: In real production with async video transcoding, return container ID to poll,
        // or trigger immediate publish if container is ready
        $publishRes = Http::post("https://graph.facebook.com/v21.0/{$igUserId}/media_publish", [
            'creation_id' => $containerId,
            'access_token' => $token,
        ]);

        if ($publishRes->failed()) {
            return new PublishResult(
                remoteId: $containerId,
                requiresPolling: true,
                statusDetails: 'Video transcoding in progress by Instagram'
            );
        }

        $mediaId = $publishRes->json('id');

        return new PublishResult(
            remoteId: $mediaId,
            remoteUrl: "https://www.instagram.com/p/{$mediaId}/",
            requiresPolling: false
        );
    }

    public function getStatus(string $remoteId, PublishContext $context): RemoteStatusResult
    {
        if (str_starts_with($context->decryptedAccessToken, 'mock_') || app()->environment('testing', 'local')) {
            return new RemoteStatusResult(
                status: 'SUCCESS',
                remoteUrl: "https://www.instagram.com/reel/C89aBcDeFgH/"
            );
        }

        $res = Http::get("https://graph.facebook.com/v21.0/{$remoteId}", [
            'fields' => 'status_code,status',
            'access_token' => $context->decryptedAccessToken,
        ]);

        $code = $res->json('status_code');

        return match ($code) {
            'FINISHED' => new RemoteStatusResult('SUCCESS', "https://www.instagram.com/p/{$remoteId}/"),
            'ERROR' => new RemoteStatusResult('FAILED', null, 'Instagram video rendering error'),
            default => new RemoteStatusResult('PROCESSING'),
        };
    }

    public function classifyError(\Throwable $error): array
    {
        $message = strtolower($error->getMessage());

        if (
            str_contains($message, 'token') ||
            str_contains($message, '190') ||
            str_contains($message, 'oauthexception') ||
            str_contains($message, 'expired')
        ) {
            return [
                'category' => 'AUTH_REQUIRED',
                'user_message' => 'Instagram authorization has expired. Reconnect your account in Connections and retry.',
                'retryable' => false,
            ];
        }

        if (str_contains($message, 'aspect ratio') || str_contains($message, 'duration')) {
            return [
                'category' => 'INVALID_MEDIA',
                'user_message' => 'Instagram Reel format error: Video must be vertical 9:16 and under 15 minutes.',
                'retryable' => false,
            ];
        }

        return [
            'category' => 'PROVIDER_ERROR',
            'user_message' => 'Instagram publishing failed: ' . substr($error->getMessage(), 0, 150),
            'retryable' => false,
        ];
    }
}
