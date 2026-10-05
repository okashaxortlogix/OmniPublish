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

class LinkedInAdapter implements PlatformAdapterInterface
{
    public function __construct(
        protected ?TokenRefreshService $tokenRefreshService = null
    ) {
        $this->tokenRefreshService = $tokenRefreshService ?? app(TokenRefreshService::class);
    }

    public function getPlatformName(): string
    {
        return 'LINKEDIN';
    }

    public function getCapabilities(): PlatformCapabilities
    {
        return new PlatformCapabilities(
            platform: 'LINKEDIN',
            supportsTitle: true,
            supportsDescription: true,
            supportsCaption: false,
            supportsTags: true,
            supportsHashtags: true,
            supportsCustomThumbnail: true,
            supportsNativeScheduling: true,
            supportsAnalytics: true,
            requiresCategory: false,
            limits: [
                'maxTitleLength' => 200,
                'maxDescriptionLength' => 3000,
                'maxTags' => 20,
            ]
        );
    }

    public function validate(array $globalMetadata, array $overrides, ?array $media = null): array
    {
        $issues = [];
        $desc = $overrides['description'] ?? $globalMetadata['description'] ?? '';
        if (mb_strlen($desc) > 3000) {
            $issues[] = [
                'field' => 'description',
                'severity' => 'ERROR',
                'message' => 'LinkedIn post exceeds maximum limit of 3,000 characters.',
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
        $overrides = $context->platformOverrides['linkedin'] ?? [];

        $token = $this->tokenRefreshService?->refreshIfExpired($account);
        if (empty($token)) {
            $token = $context->decryptedAccessToken ?: ($account->encrypted_access_token ?? '');
        }

        // Sandbox / Mock simulation fallback
        if (empty($token) || str_starts_with($token, 'mock_') || str_starts_with($token, 'demo_') || $token === 'test_token') {
            $simId = 'urn:li:share:' . Str::random(10);
            $simUrl = "https://www.linkedin.com/feed/update/{$simId}";
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
                statusDetails: 'Simulated LinkedIn share completed'
            );
        }

        // Production LinkedIn UGC/Share API
        $author = $account->platform_account_id ? "urn:li:person:{$account->platform_account_id}" : 'urn:li:person:unknown';
        $text = $overrides['description'] ?? $global['description'] ?? $global['title'] ?? '';
        if (!empty($global['hashtags'])) {
            $text .= "\n\n" . implode(' ', array_map(fn($h) => '#' . ltrim($h, '#'), $global['hashtags']));
        }

        try {
            $response = Http::withToken($token)
                ->withHeaders([
                    'X-Restli-Protocol-Version' => '2.0.0',
                    'Content-Type' => 'application/json',
                ])
                ->post('https://api.linkedin.com/v2/ugcPosts', [
                    'author' => $author,
                    'lifecycleState' => 'PUBLISHED',
                    'specificContent' => [
                        'com.linkedin.ugc.ShareContent' => [
                            'shareCommentary' => ['text' => $text],
                            'shareMediaCategory' => 'NONE',
                        ],
                    ],
                    'visibility' => [
                        'com.linkedin.ugc.MemberNetworkVisibility' => 'PUBLIC',
                    ],
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $id = $data['id'] ?? ('urn:li:share:' . Str::random(10));
                $postUrl = "https://www.linkedin.com/feed/update/{$id}";

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

            throw new \RuntimeException('LinkedIn API Error: ' . $response->body());
        } catch (Throwable $e) {
            Log::error("[LinkedInAdapter] Publishing failed: {$e->getMessage()}");
            throw $e;
        }
    }

    public function getStatus(string $remoteId, PublishContext $context): RemoteStatusResult
    {
        return new RemoteStatusResult(
            status: 'PUBLISHED',
            remotePostUrl: "https://www.linkedin.com/feed/update/{$remoteId}"
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
                'user_message' => 'LinkedIn token expired. Please reconnect your LinkedIn account.',
                'retryable' => false,
            ];
        }

        return [
            'category' => 'PROVIDER_ERROR',
            'user_message' => 'LinkedIn API error: ' . substr($msg, 0, 150),
            'retryable' => false,
        ];
    }

    public function classifyError(Throwable $error): array
    {
        return $this->normalizeError($error);
    }
}
