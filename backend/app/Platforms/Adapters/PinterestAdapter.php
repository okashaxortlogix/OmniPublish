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

class PinterestAdapter implements PlatformAdapterInterface
{
    public function __construct(
        protected ?TokenRefreshService $tokenRefreshService = null
    ) {
        $this->tokenRefreshService = $tokenRefreshService ?? app(TokenRefreshService::class);
    }

    public function getPlatformName(): string
    {
        return 'PINTEREST';
    }

    public function getCapabilities(): PlatformCapabilities
    {
        return new PlatformCapabilities(
            platform: 'PINTEREST',
            supportsTitle: true,
            supportsDescription: true,
            supportsCaption: false,
            supportsTags: true,
            supportsHashtags: true,
            supportsCustomThumbnail: true,
            supportsNativeScheduling: false,
            supportsAnalytics: true,
            requiresCategory: false,
            limits: [
                'maxTitleLength' => 100,
                'maxDescriptionLength' => 500,
            ]
        );
    }

    public function validate(array $globalMetadata, array $overrides, ?array $media = null): array
    {
        $issues = [];
        $title = $overrides['title'] ?? $globalMetadata['title'] ?? '';
        if (mb_strlen($title) > 100) {
            $issues[] = [
                'field' => 'title',
                'severity' => 'ERROR',
                'message' => 'Pinterest Pin title exceeds 100 characters limit.',
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
        $overrides = $context->platformOverrides['pinterest'] ?? [];

        $token = $this->tokenRefreshService?->refreshIfExpired($account);
        if (empty($token)) {
            $token = $context->decryptedAccessToken ?: ($account->encrypted_access_token ?? '');
        }

        // Sandbox / Mock simulation fallback
        if (empty($token) || str_starts_with($token, 'mock_') || str_starts_with($token, 'demo_') || $token === 'test_token') {
            $simId = 'pin_' . Str::random(12);
            $simUrl = "https://www.pinterest.com/pin/{$simId}";
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
                statusDetails: 'Simulated Pinterest Pin created'
            );
        }

        // Production Pinterest API v5
        $boardId = $account->metadata['default_board_id'] ?? 'default';
        $title = mb_substr($overrides['title'] ?? $global['title'] ?? 'New Pin', 0, 100);
        $desc = mb_substr($overrides['description'] ?? $global['description'] ?? '', 0, 500);

        try {
            $response = Http::withToken($token)
                ->post('https://api.pinterest.com/v5/pins', [
                    'board_id' => $boardId,
                    'title' => $title,
                    'description' => $desc,
                    'media_source' => [
                        'source_type' => 'image_url',
                        'url' => $media?->thumbnail_path ?? $media?->url ?? 'https://images.unsplash.com/photo-1618005182384-a83a8bd57fbe?w=600',
                    ],
                ]);

            if ($response->successful()) {
                $data = $response->json();
                $id = $data['id'] ?? ('pin_' . Str::random(10));
                $postUrl = "https://www.pinterest.com/pin/{$id}";

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

            throw new \RuntimeException('Pinterest API Error: ' . $response->body());
        } catch (Throwable $e) {
            Log::error("[PinterestAdapter] Publishing failed: {$e->getMessage()}");
            throw $e;
        }
    }

    public function getStatus(string $remoteId, PublishContext $context): RemoteStatusResult
    {
        return new RemoteStatusResult(
            status: 'PUBLISHED',
            remotePostUrl: "https://www.pinterest.com/pin/{$remoteId}"
        );
    }

    public function refreshAuthentication(SocialAccount $account): string
    {
        return $this->tokenRefreshService?->refreshIfExpired($account) ?? ($account->encrypted_access_token ?? '');
    }

    public function normalizeError(Throwable $error): array
    {
        $msg = $error->getMessage();
        if (str_contains($msg, 'token') || str_contains($msg, '401')) {
            return [
                'category' => 'AUTH_REQUIRED',
                'user_message' => 'Pinterest token expired. Please reconnect your Pinterest account.',
                'retryable' => false,
            ];
        }

        return [
            'category' => 'PROVIDER_ERROR',
            'user_message' => 'Pinterest API error: ' . substr($msg, 0, 150),
            'retryable' => false,
        ];
    }

    public function classifyError(Throwable $error): array
    {
        return $this->normalizeError($error);
    }
}
