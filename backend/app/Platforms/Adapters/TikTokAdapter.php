<?php

namespace App\Platforms\Adapters;

use App\Platforms\Contracts\PlatformAdapterInterface;
use App\Platforms\Contracts\PlatformCapabilities;
use App\Platforms\Contracts\PublishContext;
use App\Platforms\Contracts\PublishResult;
use App\Platforms\Contracts\RemoteStatusResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TikTokAdapter implements PlatformAdapterInterface
{
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
                'maxFileSizeMB' => 1000,
                'supportedAspectRatios' => ['9:16', '1:1', '16:9'],
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
                'message' => 'TikTok caption exceeds maximum allowed length of 2,200 characters.'
            ];
        }

        $hashtags = $globalMetadata['hashtags'] ?? [];
        if (count($hashtags) > 30) {
            $issues[] = [
                'field' => 'hashtags',
                'severity' => 'ERROR',
                'message' => 'TikTok permits a maximum of 30 hashtags.'
            ];
        }

        return $issues;
    }

    public function publish(PublishContext $context): PublishResult
    {
        $token = $context->decryptedAccessToken;
        $global = $context->globalMetadata;
        $overrides = $context->platformOverrides['tiktok'] ?? [];

        $caption = $global['caption'] ?? $global['description'] ?? $global['title'] ?? '';
        if (!empty($global['hashtags'])) {
            $tagsStr = implode(' ', array_map(fn($t) => '#' . ltrim($t, '#'), $global['hashtags']));
            $caption = trim($caption . ' ' . $tagsStr);
        }

        Log::info("Publishing to TikTok via Content Posting API v2", [
            'job_id' => $context->job->id,
            'caption_length' => mb_strlen($caption),
        ]);

        if (str_starts_with($token, 'mock_') || app()->environment('testing')) {
            $mockPublishId = 'tt_pub_' . substr(md5($context->job->id), 0, 16);
            return new PublishResult(
                remoteId: $mockPublishId,
                remoteUrl: "https://www.tiktok.com/@creator/video/" . substr(crc32($mockPublishId), 0, 10),
                requiresPolling: true,
                statusDetails: 'Submitted to TikTok. Auditing video content.'
            );
        }

        $response = Http::withToken($token)
            ->post('https://open.tiktokapis.com/v2/post/publish/video/init/', [
                'post_info' => [
                    'title' => $caption,
                    'privacy_level' => $overrides['privacyLevel'] ?? 'PUBLIC_TO_EVERYONE',
                    'disable_comment' => $overrides['disableComment'] ?? false,
                    'disable_duet' => $overrides['disableDuet'] ?? false,
                    'disable_stitch' => $overrides['disableStitch'] ?? false,
                ],
                'source_info' => [
                    'source' => 'PULL_FROM_URL',
                    'video_url' => $context->media->url,
                ]
            ]);

        if ($response->failed() || ($response->json('error.code') ?? 'ok') !== 'ok') {
            throw new \RuntimeException("TikTok API Error: " . $response->body());
        }

        $publishId = $response->json('data.publish_id');

        return new PublishResult(
            remoteId: $publishId,
            requiresPolling: true,
            statusDetails: 'Submitted to TikTok. Processing upload and moderation.'
        );
    }

    public function getStatus(string $remoteId, PublishContext $context): RemoteStatusResult
    {
        if (str_starts_with($context->decryptedAccessToken, 'mock_') || app()->environment('testing')) {
            return new RemoteStatusResult(
                status: 'SUCCESS',
                remoteUrl: "https://www.tiktok.com/@creator/video/73928172910"
            );
        }

        $response = Http::withToken($context->decryptedAccessToken)
            ->post('https://open.tiktokapis.com/v2/post/publish/status/fetch/', [
                'publish_id' => $remoteId
            ]);

        $status = $response->json('data.status');

        return match ($status) {
            'SUCCESS' => new RemoteStatusResult('SUCCESS', "https://www.tiktok.com/@creator/video/{$remoteId}"),
            'FAILED' => new RemoteStatusResult('FAILED', null, $response->json('data.fail_reason') ?? 'TikTok rejected media'),
            default => new RemoteStatusResult('PROCESSING'),
        };
    }

    public function classifyError(\Throwable $error): array
    {
        $message = strtolower($error->getMessage());

        if (str_contains($message, 'token') || str_contains($message, 'unauthorized') || str_contains($message, 'invalid_token')) {
            return [
                'category' => 'AUTH_REQUIRED',
                'user_message' => 'TikTok authorization expired. Reconnect your TikTok account to proceed.',
                'retryable' => false,
            ];
        }

        if (str_contains($message, 'spam_risk') || str_contains($message, 'sensitive')) {
            return [
                'category' => 'INVALID_METADATA',
                'user_message' => 'TikTok flagged the caption or tags as sensitive. Please modify the text and retry.',
                'retryable' => false,
            ];
        }

        return [
            'category' => 'PROVIDER_ERROR',
            'user_message' => 'TikTok posting failed: ' . substr($error->getMessage(), 0, 150),
            'retryable' => false,
        ];
    }
}
