<?php

namespace App\Services\Contracts;

interface FFmpegServiceInterface
{
    /**
     * Inspect media file using ffprobe and extract normalized technical metadata.
     *
     * @param string $sourcePath
     * @return array{
     *     duration: float,
     *     width: int,
     *     height: int,
     *     aspect_ratio: string,
     *     video_codec: string,
     *     audio_codec: ?string,
     *     bitrate: int,
     *     frame_rate: string,
     *     pixel_format: ?string,
     *     audio_channels: ?int,
     *     audio_sample_rate: ?int,
     *     container_format: string,
     *     size_bytes: int
     * }
     */
    public function probe(string $sourcePath): array;

    /**
     * Extract a representative frame as a JPEG thumbnail.
     *
     * @param string $sourcePath
     * @param string $targetThumbnailPath
     * @param float $timestamp
     * @param int $maxWidth
     * @return array{
     *     path: string,
     *     width: int,
     *     height: int,
     *     size_bytes: int,
     *     mime_type: string
     * }
     */
    public function extractThumbnail(
        string $sourcePath,
        string $targetThumbnailPath,
        float $timestamp = 1.0,
        int $maxWidth = 1280
    ): array;

    /**
     * Create a normalized master MP4 (H.264 / AAC / faststart) from source media.
     *
     * @param string $sourcePath
     * @param string $targetMasterPath
     * @return array{
     *     path: string,
     *     size_bytes: int,
     *     mime_type: string,
     *     duration: float,
     *     width: int,
     *     height: int,
     *     bitrate: int
     * }
     */
    public function generateNormalizedMaster(string $sourcePath, string $targetMasterPath): array;

    /**
     * Generate a platform-specific derivative adhering to profile constraints.
     *
     * @param string $sourcePath
     * @param string $targetPath
     * @param array $profileConfig
     * @return array{
     *     path: string,
     *     size_bytes: int,
     *     mime_type: string,
     *     duration: float,
     *     width: int,
     *     height: int,
     *     bitrate: int
     * }
     */
    public function generateDerivative(
        string $sourcePath,
        string $targetPath,
        array $profileConfig
    ): array;
}
