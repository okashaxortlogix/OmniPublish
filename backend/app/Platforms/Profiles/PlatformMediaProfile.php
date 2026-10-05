<?php

namespace App\Platforms\Profiles;

class PlatformMediaProfile
{
    public function __construct(
        public readonly string $platform,
        public readonly ?string $preferredAspectRatio = null, // e.g. '9:16', '16:9'
        public readonly ?int $targetWidth = null,
        public readonly ?int $targetHeight = null,
        public readonly ?int $maxDurationSeconds = null,
        public readonly ?int $maxFileSizeBytes = null,
        public readonly array $allowedVideoCodecs = ['h264', 'hevc'],
        public readonly array $allowedAudioCodecs = ['aac', 'mp3'],
        public readonly bool $generateDerivative = false,
        public readonly array $transcodingParams = []
    ) {}

    /**
     * Determine whether the given media asset requires platform adaptation.
     */
    public function requiresAdaptation(array $probedMetadata): bool
    {
        if (!$this->generateDerivative) {
            return false;
        }

        if ($this->preferredAspectRatio && ($probedMetadata['aspect_ratio'] ?? '') !== $this->preferredAspectRatio) {
            return true;
        }

        if ($this->targetWidth && ($probedMetadata['width'] ?? 0) !== $this->targetWidth) {
            return true;
        }

        if ($this->targetHeight && ($probedMetadata['height'] ?? 0) !== $this->targetHeight) {
            return true;
        }

        return false;
    }
}
