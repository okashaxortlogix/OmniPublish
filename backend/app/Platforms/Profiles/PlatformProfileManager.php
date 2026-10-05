<?php

namespace App\Platforms\Profiles;

class PlatformProfileManager
{
    /**
     * @var array<string, PlatformMediaProfile>
     */
    protected array $profiles = [];

    public function __construct()
    {
        $this->registerDefaultProfiles();
    }

    protected function registerDefaultProfiles(): void
    {
        // Architecture baseline profiles — limits to be populated from official docs in platform phases
        $this->profiles['YOUTUBE'] = new PlatformMediaProfile(
            platform: 'YOUTUBE',
            preferredAspectRatio: '16:9',
            targetWidth: 1920,
            targetHeight: 1080,
            maxDurationSeconds: 43200, // 12 hours
            maxFileSizeBytes: 137438953472, // 128 GB
            allowedVideoCodecs: ['h264', 'hevc', 'vp9', 'av1'],
            allowedAudioCodecs: ['aac', 'opus', 'mp3'],
            generateDerivative: false // YouTube handles server-side multi-rendition transcoding
        );

        $this->profiles['TIKTOK'] = new PlatformMediaProfile(
            platform: 'TIKTOK',
            preferredAspectRatio: '9:16',
            targetWidth: 1080,
            targetHeight: 1920,
            maxDurationSeconds: 600, // 10 minutes
            maxFileSizeBytes: 1073741824, // 1 GB
            allowedVideoCodecs: ['h264', 'hevc'],
            allowedAudioCodecs: ['aac'],
            generateDerivative: true,
            transcodingParams: [
                'target_width' => 1080,
                'target_height' => 1920,
                'crf' => '23',
            ]
        );

        $this->profiles['INSTAGRAM'] = new PlatformMediaProfile(
            platform: 'INSTAGRAM',
            preferredAspectRatio: '9:16',
            targetWidth: 1080,
            targetHeight: 1920,
            maxDurationSeconds: 900, // 15 minutes for Reels
            maxFileSizeBytes: 1073741824,
            allowedVideoCodecs: ['h264'],
            allowedAudioCodecs: ['aac'],
            generateDerivative: true,
            transcodingParams: [
                'target_width' => 1080,
                'target_height' => 1920,
                'crf' => '23',
            ]
        );
    }

    public function getProfile(string $platform): ?PlatformMediaProfile
    {
        $key = strtoupper($platform);
        return $this->profiles[$key] ?? null;
    }

    public function registerProfile(PlatformMediaProfile $profile): void
    {
        $this->profiles[strtoupper($profile->platform)] = $profile;
    }

    /**
     * @return array<string, PlatformMediaProfile>
     */
    public function getAllProfiles(): array
    {
        return $this->profiles;
    }
}
