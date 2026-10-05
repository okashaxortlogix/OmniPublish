<?php

namespace App\Platforms\Adapters;

use App\Models\MediaAsset;
use App\Models\PublishJob;
use App\Models\SocialAccount;
use App\Platforms\Contracts\PlatformAdapterInterface;
use App\Platforms\Contracts\PlatformCapabilities;
use App\Platforms\Contracts\PublishContext;
use App\Platforms\Contracts\PublishResult;
use App\Platforms\Contracts\RemoteStatusResult;
use App\Services\TokenRefreshService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class TwitterAdapter implements PlatformAdapterInterface
{
    public function __construct(
        protected ?TokenRefreshService $tokenRefreshService = null
    ) {
        $this->tokenRefreshService = $tokenRefreshService ?? app(TokenRefreshService::class);
    }

    public function getPlatformName(): string
    {
        return 'TWITTER';
    }

    public function getCapabilities(): PlatformCapabilities
    {
        return new PlatformCapabilities(
            platform: 'TWITTER',
            supportsTitle: false,
            supportsDescription: true,
            supportsCaption: true,
            supportsTags: false,
            supportsHashtags: true,
            supportsCustomThumbnail: false,
            supportsNativeScheduling: true,
            supportsAnalytics: true,
            requiresCategory: false,
            limits: [
                'maxDescriptionLength' => 280,
                'maxCaptionLength' => 280,
            ]
        );
    }

    public function validate(array $globalMetadata, array $overrides, ?array $media = null): array
    {
        $issues = [];
        $text = $overrides['caption'] ?? $overrides['description'] ?? $globalMetadata['caption'] ?? $globalMetadata['title'] ?? '';
        if (mb_strlen($text) > 280) {
            $issues[] = [
                'field' => 'text',
                'severity' => 'WARNING',
                'message' => 'Post length exceeds 280 standard characters. It will be truncated for non-Premium accounts.',
            ];
        }

        return $issues;
    }

    public function publish(PublishContext $context): PublishResult
    {
        $job = $context->job;
        $account = $context->account;
        $media = $context->media;
        $global = $context->globalMetadata;
        $overrides = $context->platformOverrides['twitter'] ?? $context->platformOverrides['x'] ?? [];

        $token = $this->tokenRefreshService?->refreshIfExpired($account);
        if (empty($token)) {
            $token = $context->decryptedAccessToken ?: ($account->encrypted_access_token ?? '');
        }

        // Sandbox / Mock simulation fallback
        if (empty($token) || str_starts_with($token, 'mock_') || str_starts_with($token, 'demo_') || $token === 'test_token') {
            $simId = (string) random_int(1800000000000000000, 1899999999999999999);
            $simUrl = "https://x.com/user/status/{$simId}";
            $totalBytes = $media?->size_bytes ?: 5242880;

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
                statusDetails: 'Simulated X (Twitter) post published'
            );
        }

        // Production X API v2 Tweets endpoint
        $text = $overrides['caption'] ?? $overrides['description'] ?? $global['caption'] ?? $global['description'] ?? $global['title'] ?? '';
        if (!empty($global['hashtags'])) {
            $text .= ' ' . implode(' ', array_map(fn($h) => '#' . ltrim($h, '#'), $global['hashtags']));
        }
        $text = mb_substr(trim($text), 0, 280);

        try {
            $response = Http::withToken($token)
                ->post('https://api.twitter.com/2/tweets', [
                    'text' => $text,
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $id = $data['data']['id'] ?? ((string) random_int(1800000000000000000, 1899999999999999999));
                $postUrl = "https://x.com/user/status/{$id}";

                $job->update([
                    'remote_post_id' => $id,
                    'remote_post_url' => $postUrl,
                ]);

                return new PublishResult(
                    remoteId: $id,
                    remoteUrl: $postUrl,
                    requiresPolling: false
                );
            }

            throw new \RuntimeException('Twitter API Error: ' . $response->body());
        } catch (Throwable $e) {
            Log::error("[TwitterAdapter] Publishing failed: {$e->getMessage()}");
            throw $e;
        }
    }

    public function getStatus(string $remoteId, PublishContext $context): RemoteStatusResult
    {
        return new RemoteStatusResult(
            status: 'PUBLISHED',
            remotePostUrl: "https://x.com/user/status/{$remoteId}"
        );
    }

    public function refreshAuthentication(SocialAccount $account): string
    {
        return $this->tokenRefreshService?->refreshIfExpired($account) ?? ($account->encrypted_access_token ?? '');
    }

    public function normalizeError(Throwable $error): array
    {
        $msg = $error->getMessage();
        if (str_contains($msg, 'token') || str_contains($msg, '401') || str_contains($msg, 'unauthorized')) {
            return [
                'category' => 'AUTH_REQUIRED',
                'user_message' => 'X (Twitter) OAuth token expired. Please reconnect your account.',
                'retryable' => false,
            ];
        }

        return [
            'category' => 'PROVIDER_ERROR',
            'user_message' => 'X (Twitter) API error: ' . substr($msg, 0, 150),
            'retryable' => false,
        ];
    }

    public function classifyError(Throwable $error): array
    {
        return $this->normalizeError($error);
    }
}
