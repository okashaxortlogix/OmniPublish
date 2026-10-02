<?php

namespace App\Platforms\Contracts;

class PlatformCapabilities
{
    public function __construct(
        public readonly string $platform,
        public readonly bool $supportsTitle,
        public readonly bool $supportsDescription,
        public readonly bool $supportsCaption,
        public readonly bool $supportsTags,
        public readonly bool $supportsHashtags,
        public readonly bool $supportsCustomThumbnail,
        public readonly bool $supportsNativeScheduling,
        public readonly bool $supportsAnalytics,
        public readonly bool $requiresCategory,
        public readonly array $limits
    ) {}

    public function toArray(): array
    {
        return [
            'platform' => $this->platform,
            'supportsTitle' => $this->supportsTitle,
            'supportsDescription' => $this->supportsDescription,
            'supportsCaption' => $this->supportsCaption,
            'supportsTags' => $this->supportsTags,
            'supportsHashtags' => $this->supportsHashtags,
            'supportsCustomThumbnail' => $this->supportsCustomThumbnail,
            'supportsNativeScheduling' => $this->supportsNativeScheduling,
            'supportsAnalytics' => $this->supportsAnalytics,
            'requiresCategory' => $this->requiresCategory,
            'limits' => $this->limits,
        ];
    }
}
