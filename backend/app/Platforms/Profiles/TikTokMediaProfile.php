<?php

namespace App\Platforms\Profiles;

use App\Models\MediaAsset;
use App\Platforms\Exceptions\TikTokPublishException;

class TikTokMediaProfile
{
    public const SUPPORTED_CONTAINERS = ['mp4', 'webm', 'mov'];
    public const SUPPORTED_MIME_TYPES = ['video/mp4', 'video/webm', 'video/quicktime'];
    public const SUPPORTED_VIDEO_CODECS = ['h264', 'avc1', 'hevc', 'h265', 'hvc1', 'vp8', 'vp9'];
    public const SUPPORTED_AUDIO_CODECS = ['aac', 'mp3', 'opus'];

    public const MIN_DURATION_SECONDS = 3.0;
    public const DEFAULT_MAX_DURATION_SECONDS = 600.0; // 10 minutes

    public const MIN_FILE_SIZE_BYTES = 1000;
    public const MAX_FILE_SIZE_BYTES = 4294967296; // 4 GiB (4,294,967,296 bytes) per TikTok Content Posting API specifications

    public const MIN_DIMENSION = 360;
    public const MAX_DIMENSION = 4096;

    /**
     * Validate a MediaAsset against TikTok Direct Post specifications.
     *
     * @param MediaAsset $media
     * @param int|null $creatorMaxDurationSec Maximum duration allowed by creator profile
     * @return array List of validation issues
     */
    public static function validate(MediaAsset $media, ?int $creatorMaxDurationSec = null): array
    {
        $issues = [];

        // 1. Ready state check
        if ($media->status !== 'READY' && $media->status !== 'PROCESSED') {
            $issues[] = [
                'field' => 'status',
                'severity' => 'ERROR',
                'message' => "Media asset status is [{$media->status}]. Asset must be processed and READY before publishing.",
            ];
        }

        // 2. File size checks
        if ($media->size_bytes < self::MIN_FILE_SIZE_BYTES) {
            $issues[] = [
                'field' => 'size_bytes',
                'severity' => 'ERROR',
                'message' => "Media file is too small ({$media->size_bytes} bytes). Minimum size is " . self::MIN_FILE_SIZE_BYTES . " bytes.",
            ];
        }

        if ($media->size_bytes > self::MAX_FILE_SIZE_BYTES) {
            $issues[] = [
                'field' => 'size_bytes',
                'severity' => 'ERROR',
                'message' => "Media file size (" . round($media->size_bytes / 1048576, 2) . " MB) exceeds TikTok maximum limit of 1 GB.",
            ];
        }

        // 3. MIME type / format checks
        if ($media->mime_type && !in_array(strtolower($media->mime_type), self::SUPPORTED_MIME_TYPES, true)) {
            $ext = strtolower(pathinfo($media->filename, PATHINFO_EXTENSION));
            if (!in_array($ext, self::SUPPORTED_CONTAINERS, true)) {
                $issues[] = [
                    'field' => 'mime_type',
                    'severity' => 'ERROR',
                    'message' => "Unsupported media format [{$media->mime_type}]. TikTok requires MP4, WebM, or MOV.",
                ];
            }
        }

        // 4. Duration checks
        $duration = (float) ($media->duration_seconds ?? 0);
        if ($duration < self::MIN_DURATION_SECONDS) {
            $issues[] = [
                'field' => 'duration_seconds',
                'severity' => 'ERROR',
                'message' => "Video duration ({$duration}s) is shorter than TikTok minimum requirement of " . self::MIN_DURATION_SECONDS . " seconds.",
            ];
        }

        $maxDuration = $creatorMaxDurationSec ?: self::DEFAULT_MAX_DURATION_SECONDS;
        if ($duration > $maxDuration) {
            $issues[] = [
                'field' => 'duration_seconds',
                'severity' => 'ERROR',
                'message' => "Video duration ({$duration}s) exceeds maximum allowed duration of {$maxDuration} seconds.",
            ];
        }

        // 5. Dimension checks
        if ($media->width && $media->height) {
            if ($media->width < self::MIN_DIMENSION || $media->height < self::MIN_DIMENSION) {
                $issues[] = [
                    'field' => 'dimensions',
                    'severity' => 'ERROR',
                    'message' => "Video dimensions ({$media->width}x{$media->height}) are below TikTok minimum resolution of " . self::MIN_DIMENSION . "px.",
                ];
            }

            if ($media->width > self::MAX_DIMENSION || $media->height > self::MAX_DIMENSION) {
                $issues[] = [
                    'field' => 'dimensions',
                    'severity' => 'ERROR',
                    'message' => "Video dimensions ({$media->width}x{$media->height}) exceed TikTok maximum resolution of " . self::MAX_DIMENSION . "px.",
                ];
            }
        }

        // 6. Frame rate checks (23 - 60 FPS per TikTok Media Transfer specifications)
        $fps = (float) ($media->custom_properties['fps'] ?? $media->custom_properties['framerate'] ?? 0);
        if ($fps > 0 && ($fps < 23.0 || $fps > 60.0)) {
            $issues[] = [
                'field' => 'fps',
                'severity' => 'ERROR',
                'message' => "Video frame rate ({$fps} fps) is outside TikTok supported range of 23 to 60 fps.",
            ];
        }

        return $issues;
    }
}
