<?php

namespace App\Platforms\Contracts;

interface PlatformAdapterInterface extends PlatformPublisherInterface
{
    /**
     * Get platform identifier (e.g., YOUTUBE, TIKTOK, INSTAGRAM)
     */
    public function getPlatformName(): string;

    /**
     * Return declared official capabilities and limit constraints
     */
    public function getCapabilities(): PlatformCapabilities;

    /**
     * Validate content against platform rules before dispatch
     */
    public function validate(array $globalMetadata, array $overrides, ?array $media = null): array;

    /**
     * Execute remote publishing flow to the platform API
     */
    public function publish(PublishContext $context): PublishResult;

    /**
     * Check asynchronous remote transcoding/moderation status
     */
    public function getStatus(string $remoteId, PublishContext $context): RemoteStatusResult;

    /**
     * Classify raw platform error to standardized FailureCategory
     */
    public function classifyError(\Throwable $error): array;
}
