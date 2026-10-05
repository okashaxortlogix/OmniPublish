<?php

namespace App\Services;

use App\Services\Contracts\FFmpegServiceInterface;
use Symfony\Component\Process\Process;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

class FFmpegService implements FFmpegServiceInterface
{
    protected string $ffmpegPath;
    protected string $ffprobePath;

    public function __construct(?string $ffmpegPath = null, ?string $ffprobePath = null)
    {
        $this->ffmpegPath = $ffmpegPath ?? $this->resolveBinary('ffmpeg');
        $this->ffprobePath = $ffprobePath ?? $this->resolveBinary('ffprobe');
    }

    /**
     * Locate binary path with fallback search.
     */
    protected function resolveBinary(string $binaryName): string
    {
        $configured = env(strtoupper($binaryName) . '_PATH');
        if (!empty($configured) && (file_exists($configured) || is_executable($configured))) {
            return $configured;
        }

        // Check Windows Winget install location
        $wingetBinDir = 'C:\\Users\\Muhammad Talha\\AppData\\Local\\Microsoft\\WinGet\\Packages\\Gyan.FFmpeg.Essentials_Microsoft.Winget.Source_8wekyb3d8bbwe\\ffmpeg-9.0.1-essentials_build\\bin\\';
        $exeName = PHP_OS_FAMILY === 'Windows' ? "{$binaryName}.exe" : $binaryName;
        if (file_exists($wingetBinDir . $exeName)) {
            return $wingetBinDir . $exeName;
        }

        // Check standard unix locations
        foreach (['/usr/bin/', '/usr/local/bin/'] as $dir) {
            if (file_exists($dir . $binaryName)) {
                return $dir . $binaryName;
            }
        }

        // Fallback to system command name
        return $binaryName;
    }

    /**
     * {@inheritdoc}
     */
    public function probe(string $sourcePath): array
    {
        if (!file_exists($sourcePath)) {
            throw new InvalidArgumentException("Source file does not exist: {$sourcePath}");
        }

        $fileSize = filesize($sourcePath);
        if ($fileSize <= 0) {
            throw new InvalidArgumentException("Media file is empty (0 bytes).");
        }

        $process = new Process([
            $this->ffprobePath,
            '-v', 'quiet',
            '-print_format', 'json',
            '-show_format',
            '-show_streams',
            $sourcePath,
        ]);

        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful()) {
            throw new InvalidArgumentException(
                "Failed to parse media container. The file may be corrupt or an unsupported format."
            );
        }

        $data = json_decode($process->getOutput(), true);
        if (!is_array($data) || empty($data['streams'])) {
            throw new InvalidArgumentException("Invalid media structure: no valid streams detected.");
        }

        $videoStream = null;
        $audioStream = null;

        foreach ($data['streams'] as $stream) {
            if (($stream['codec_type'] ?? '') === 'video' && $videoStream === null) {
                $videoStream = $stream;
            } elseif (($stream['codec_type'] ?? '') === 'audio' && $audioStream === null) {
                $audioStream = $stream;
            }
        }

        if (!$videoStream) {
            throw new InvalidArgumentException("Media file does not contain a valid video stream.");
        }

        $format = $data['format'] ?? [];
        $width = (int) ($videoStream['width'] ?? 0);
        $height = (int) ($videoStream['height'] ?? 0);

        if ($width <= 0 || $height <= 0) {
            throw new InvalidArgumentException("Invalid video stream dimensions: {$width}x{$height}.");
        }

        $duration = (float) ($format['duration'] ?? $videoStream['duration'] ?? 0.0);
        $bitrate = (int) ($format['bit_rate'] ?? $videoStream['bit_rate'] ?? 0);
        $frameRate = $videoStream['r_frame_rate'] ?? $videoStream['avg_frame_rate'] ?? '30/1';
        $videoCodec = strtolower($videoStream['codec_name'] ?? 'unknown');
        $pixelFormat = $videoStream['pix_fmt'] ?? null;
        $containerFormat = $format['format_name'] ?? 'mp4';

        // Aspect ratio calculation
        $aspectRatio = '16:9';
        if ($width > 0 && $height > 0) {
            $ratio = $width / $height;
            if (abs($ratio - (9 / 16)) < 0.05) {
                $aspectRatio = '9:16';
            } elseif (abs($ratio - 1.0) < 0.05) {
                $aspectRatio = '1:1';
            } elseif (abs($ratio - (4 / 5)) < 0.05) {
                $aspectRatio = '4:5';
            } else {
                $aspectRatio = '16:9';
            }
        }

        return [
            'duration' => $duration,
            'width' => $width,
            'height' => $height,
            'aspect_ratio' => $aspectRatio,
            'video_codec' => $videoCodec,
            'audio_codec' => $audioStream ? strtolower($audioStream['codec_name'] ?? '') : null,
            'bitrate' => $bitrate,
            'frame_rate' => $frameRate,
            'pixel_format' => $pixelFormat,
            'audio_channels' => isset($audioStream['channels']) ? (int) $audioStream['channels'] : null,
            'audio_sample_rate' => isset($audioStream['sample_rate']) ? (int) $audioStream['sample_rate'] : null,
            'container_format' => $containerFormat,
            'size_bytes' => $fileSize,
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function extractThumbnail(
        string $sourcePath,
        string $targetThumbnailPath,
        float $timestamp = 1.0,
        int $maxWidth = 1280
    ): array {
        $probe = $this->probe($sourcePath);
        $duration = $probe['duration'];

        // Safe frame selection: clamp timestamp if video is shorter than requested time
        $safeTimestamp = max(0.1, min($timestamp, $duration > 0.5 ? $duration / 2 : 0.1));

        $dir = dirname($targetThumbnailPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $process = new Process([
            $this->ffmpegPath,
            '-v', 'error',
            '-ss', (string) round($safeTimestamp, 2),
            '-i', $sourcePath,
            '-vframes', '1',
            '-q:v', '2',
            '-vf', "scale=min(iw\\,{$maxWidth}):-2",
            '-y',
            $targetThumbnailPath,
        ]);

        $process->setTimeout(60);
        $process->run();

        if (!$process->isSuccessful() || !file_exists($targetThumbnailPath) || filesize($targetThumbnailPath) === 0) {
            throw new RuntimeException("FFmpeg failed to extract thumbnail: " . $process->getErrorOutput());
        }

        $imgSize = @getimagesize($targetThumbnailPath);

        return [
            'path' => $targetThumbnailPath,
            'width' => $imgSize ? $imgSize[0] : 1280,
            'height' => $imgSize ? $imgSize[1] : 720,
            'size_bytes' => filesize($targetThumbnailPath),
            'mime_type' => 'image/jpeg',
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function generateNormalizedMaster(string $sourcePath, string $targetMasterPath): array
    {
        $dir = dirname($targetMasterPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $process = new Process([
            $this->ffmpegPath,
            '-v', 'error',
            '-i', $sourcePath,
            '-c:v', 'libx264',
            '-preset', 'fast',
            '-crf', '22',
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac',
            '-b:a', '192k',
            '-movflags', '+faststart',
            '-y',
            $targetMasterPath,
        ]);

        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful() || !file_exists($targetMasterPath) || filesize($targetMasterPath) === 0) {
            throw new RuntimeException("FFmpeg master normalization failed: " . $process->getErrorOutput());
        }

        $probe = $this->probe($targetMasterPath);

        return [
            'path' => $targetMasterPath,
            'size_bytes' => filesize($targetMasterPath),
            'mime_type' => 'video/mp4',
            'duration' => $probe['duration'],
            'width' => $probe['width'],
            'height' => $probe['height'],
            'bitrate' => $probe['bitrate'],
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function generateDerivative(
        string $sourcePath,
        string $targetPath,
        array $profileConfig
    ): array {
        $dir = dirname($targetPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        $width = (int) ($profileConfig['target_width'] ?? 1080);
        $height = (int) ($profileConfig['target_height'] ?? 1920);
        $crf = (string) ($profileConfig['crf'] ?? '23');

        $args = [
            $this->ffmpegPath,
            '-v', 'error',
            '-i', $sourcePath,
            '-vf', "scale={$width}:{$height}:force_original_aspect_ratio=decrease,pad={$width}:{$height}:(ow-iw)/2:(oh-ih)/2",
            '-c:v', 'libx264',
            '-preset', 'fast',
            '-crf', $crf,
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac',
            '-b:a', '128k',
            '-movflags', '+faststart',
            '-y',
            $targetPath,
        ];

        $process = new Process($args);
        $process->setTimeout(300);
        $process->run();

        if (!$process->isSuccessful() || !file_exists($targetPath) || filesize($targetPath) === 0) {
            throw new RuntimeException("FFmpeg platform derivative generation failed: " . $process->getErrorOutput());
        }

        $probe = $this->probe($targetPath);

        return [
            'path' => $targetPath,
            'size_bytes' => filesize($targetPath),
            'mime_type' => 'video/mp4',
            'duration' => $probe['duration'],
            'width' => $probe['width'],
            'height' => $probe['height'],
            'bitrate' => $probe['bitrate'],
        ];
    }
}
