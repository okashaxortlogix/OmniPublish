<?php

namespace App\Platforms\Contracts;

use App\Models\SocialAccount;

interface PlatformPublisherInterface
{
    /**
     * Get platform identifier (e.g., YOUTUBE, TIKTOK, INSTAGRAM)
     */
    public function getPlatformName(): string;

    /**
     * Validate content and media against platform requirements before dispatch
     */
    public function validate(array $globalMetadata, array $overrides, ?array $media = null): array;

    /**
     * Execute platform publishing flow to the remote platform API
     */
    public function publish(PublishContext $context): PublishResult;

    /**
     * Refresh OAuth authentication credentials for the given account if needed
     */
    public function refreshAuthentication(SocialAccount $account): string;

    /**
     * Check asynchronous remote transcoding/moderation status
     */
    public function getStatus(string $remoteId, PublishContext $context): RemoteStatusResult;

    /**
     * Normalize raw provider error into standardized FailureCategory and safe user message
     */
    public function normalizeError(\Throwable $error): array;
}
