<?php

namespace App\Platforms\Profiles;

use App\Models\MediaAsset;

class InstagramMediaProfile
{
    // Video / Reels specifications
    public const SUPPORTED_VIDEO_CONTAINERS = ['mp4', 'mov'];
    public const SUPPORTED_VIDEO_MIME_TYPES = ['video/mp4', 'video/quicktime'];
    public const SUPPORTED_VIDEO_CODECS = ['h264', 'avc1'];
    public const SUPPORTED_AUDIO_CODECS = ['aac'];

    public const MIN_VIDEO_DURATION_SECONDS = 3.0;
    public const MAX_VIDEO_DURATION_SECONDS = 900.0; // 15 minutes for Reels

    public const MIN_VIDEO_SIZE_BYTES = 1000;
    public const MAX_VIDEO_SIZE_BYTES = 1073741824; // 1 GiB

    public const MIN_VIDEO_DIMENSION = 540;
    public const MAX_VIDEO_DIMENSION = 1920;

    // Image specifications
    public const SUPPORTED_IMAGE_FORMATS = ['jpg', 'jpeg', 'png'];
    public const SUPPORTED_IMAGE_MIME_TYPES = ['image/jpeg', 'image/png'];
    public const MAX_IMAGE_SIZE_BYTES = 8388608; // 8 MiB
    public const MIN_IMAGE_DIMENSION = 320;
    public const MAX_IMAGE_DIMENSION = 1440;

    /**
     * Validate a MediaAsset against Meta / Instagram Graph API specifications.
     *
     * @param MediaAsset $media
     * @param string $mediaType 'REELS' or 'IMAGE'
     * @return array List of validation issues
     */
    public static function validate(MediaAsset $media, string $mediaType = 'REELS'): array
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

        $isImage = str_starts_with(strtolower($media->mime_type ?? ''), 'image/')
            || in_array(strtolower(pathinfo($media->filename, PATHINFO_EXTENSION)), self::SUPPORTED_IMAGE_FORMATS, true)
            || strtoupper($mediaType) === 'IMAGE';

        if ($isImage) {
            return self::validateImage($media, $issues);
        }

        return self::validateVideo($media, $issues);
    }

    protected static function validateImage(MediaAsset $media, array $issues): array
    {
        // 1. Image file size
        if ($media->size_bytes > self::MAX_IMAGE_SIZE_BYTES) {
            $issues[] = [
                'field' => 'size_bytes',
                'severity' => 'ERROR',
                'message' => "Image file size (" . round($media->size_bytes / 1048576, 2) . " MB) exceeds Instagram maximum limit of 8 MB.",
            ];
        }

        // 2. Image format / mime type
        $ext = strtolower(pathinfo($media->filename, PATHINFO_EXTENSION));
        if (!in_array($ext, self::SUPPORTED_IMAGE_FORMATS, true) &&
            !in_array(strtolower($media->mime_type ?? ''), self::SUPPORTED_IMAGE_MIME_TYPES, true)) {
            $issues[] = [
                'field' => 'mime_type',
                'severity' => 'ERROR',
                'message' => "Unsupported image format [{$media->mime_type}]. Instagram requires JPEG or PNG.",
            ];
        }

        // 3. Dimensions
        if ($media->width && $media->height) {
            if ($media->width < self::MIN_IMAGE_DIMENSION || $media->height < self::MIN_IMAGE_DIMENSION) {
                $issues[] = [
                    'field' => 'dimensions',
                    'severity' => 'ERROR',
                    'message' => "Image dimensions ({$media->width}x{$media->height}) are below Instagram minimum resolution of " . self::MIN_IMAGE_DIMENSION . "px.",
                ];
            }

            // Aspect ratio check: between 4:5 (0.8) and 1.91:1 (1.91)
            $ratio = $media->width / $media->height;
            if ($ratio < 0.79 || $ratio > 1.92) {
                $issues[] = [
                    'field' => 'aspect_ratio',
                    'severity' => 'ERROR',
                    'message' => "Image aspect ratio (" . round($ratio, 2) . ") is outside Instagram supported range of 4:5 (0.8) to 1.91:1 (1.91).",
                ];
            }
        }

        return $issues;
    }

    protected static function validateVideo(MediaAsset $media, array $issues): array
    {
        // 1. Video file size
        if ($media->size_bytes < self::MIN_VIDEO_SIZE_BYTES) {
            $issues[] = [
                'field' => 'size_bytes',
                'severity' => 'ERROR',
                'message' => "Video file is too small ({$media->size_bytes} bytes). Minimum size is " . self::MIN_VIDEO_SIZE_BYTES . " bytes.",
            ];
        }

        if ($media->size_bytes > self::MAX_VIDEO_SIZE_BYTES) {
            $issues[] = [
                'field' => 'size_bytes',
                'severity' => 'ERROR',
                'message' => "Video file size (" . round($media->size_bytes / 1048576, 2) . " MB) exceeds Instagram maximum limit of 1 GB.",
            ];
        }

        // 2. Video container / MIME type
        $ext = strtolower(pathinfo($media->filename, PATHINFO_EXTENSION));
        if (!in_array($ext, self::SUPPORTED_VIDEO_CONTAINERS, true) &&
            !in_array(strtolower($media->mime_type ?? ''), self::SUPPORTED_VIDEO_MIME_TYPES, true)) {
            $issues[] = [
                'field' => 'mime_type',
                'severity' => 'ERROR',
                'message' => "Unsupported video format [{$media->mime_type}]. Instagram requires MP4 or MOV.",
            ];
        }

        // 3. Duration
        $duration = (float) ($media->duration_seconds ?? 0);
        if ($duration < self::MIN_VIDEO_DURATION_SECONDS) {
            $issues[] = [
                'field' => 'duration_seconds',
                'severity' => 'ERROR',
                'message' => "Video duration ({$duration}s) is shorter than Instagram minimum requirement of " . self::MIN_VIDEO_DURATION_SECONDS . " seconds.",
            ];
        }

        if ($duration > self::MAX_VIDEO_DURATION_SECONDS) {
            $issues[] = [
                'field' => 'duration_seconds',
                'severity' => 'ERROR',
                'message' => "Video duration ({$duration}s) exceeds Instagram Reels maximum allowed duration of " . self::MAX_VIDEO_DURATION_SECONDS . " seconds (15 minutes).",
            ];
        }

        // 4. Dimensions & Aspect ratio
        if ($media->width && $media->height) {
            if ($media->width < self::MIN_VIDEO_DIMENSION || $media->height < self::MIN_VIDEO_DIMENSION) {
                $issues[] = [
                    'field' => 'dimensions',
                    'severity' => 'ERROR',
                    'message' => "Video dimensions ({$media->width}x{$media->height}) are below Instagram minimum resolution of " . self::MIN_VIDEO_DIMENSION . "px.",
                ];
            }

            if ($media->width > self::MAX_VIDEO_DIMENSION || $media->height > self::MAX_VIDEO_DIMENSION) {
                $issues[] = [
                    'field' => 'dimensions',
                    'severity' => 'ERROR',
                    'message' => "Video dimensions ({$media->width}x{$media->height}) exceed Instagram maximum resolution of " . self::MAX_VIDEO_DIMENSION . "px.",
                ];
            }

            // Aspect ratio check for Reels: standard 9:16 (0.5625) or between 4:5 (0.8) and 16:9 (1.777)
            $ratio = $media->width / $media->height;
            if ($ratio < 0.55 || $ratio > 1.78) {
                $issues[] = [
                    'field' => 'aspect_ratio',
                    'severity' => 'ERROR',
                    'message' => "Video aspect ratio (" . round($ratio, 2) . ") is outside Instagram supported range for video/Reels (9:16 to 16:9).",
                ];
            }
        }

        // 5. Frame rate (23 to 60 FPS)
        $fps = (float) ($media->custom_properties['fps'] ?? $media->custom_properties['framerate'] ?? 0);
        if ($fps > 0 && ($fps < 23.0 || $fps > 60.0)) {
            $issues[] = [
                'field' => 'fps',
                'severity' => 'ERROR',
                'message' => "Video frame rate ({$fps} fps) is outside Instagram supported range of 23 to 60 fps.",
            ];
        }

        return $issues;
    }
}
