<?php

namespace Tests\Fixtures;

use Symfony\Component\Process\Process;

class MediaFixtureGenerator
{
    protected static ?string $ffmpegPath = null;

    public static function getFFmpegPath(): string
    {
        if (self::$ffmpegPath) {
            return self::$ffmpegPath;
        }

        $configured = env('FFMPEG_PATH');
        if (!empty($configured) && file_exists($configured)) {
            return self::$ffmpegPath = $configured;
        }

        $wingetBin = 'C:\\Users\\Muhammad Talha\\AppData\\Local\\Microsoft\\WinGet\\Packages\\Gyan.FFmpeg.Essentials_Microsoft.Winget.Source_8wekyb3d8bbwe\\ffmpeg-9.0.1-essentials_build\\bin\\ffmpeg.exe';
        if (file_exists($wingetBin)) {
            return self::$ffmpegPath = $wingetBin;
        }

        return self::$ffmpegPath = 'ffmpeg';
    }

    public static function createValidVideo(
        string $outputPath,
        int $durationSeconds = 2,
        int $width = 640,
        int $height = 360,
        int $fps = 15
    ): string {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (file_exists($outputPath) && filesize($outputPath) > 0) {
            return $outputPath;
        }

        $process = new Process([
            self::getFFmpegPath(),
            '-v', 'error',
            '-f', 'lavfi',
            '-i', "testsrc=duration={$durationSeconds}:size={$width}x{$height}:rate={$fps}",
            '-f', 'lavfi',
            '-i', "sine=frequency=1000:duration={$durationSeconds}",
            '-c:v', 'libx264',
            '-preset', 'ultrafast',
            '-pix_fmt', 'yuv420p',
            '-c:a', 'aac',
            '-b:a', '64k',
            '-movflags', '+faststart',
            '-y',
            $outputPath,
        ]);

        $process->setTimeout(30);
        $process->run();

        if (!$process->isSuccessful() || !file_exists($outputPath) || filesize($outputPath) === 0) {
            throw new \RuntimeException("Failed to generate test media fixture: " . $process->getErrorOutput());
        }

        return $outputPath;
    }

    public static function createLandscapeVideo(string $outputPath): string
    {
        return self::createValidVideo($outputPath, 2, 640, 360);
    }

    public static function createPortraitVideo(string $outputPath): string
    {
        return self::createValidVideo($outputPath, 2, 360, 640);
    }

    public static function createCorruptFile(string $outputPath): string
    {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($outputPath, "ftypmp42\x00\x00\x00\x00corrupt_non_video_header_payload_garbage_data");
        return $outputPath;
    }

    public static function createEmptyFile(string $outputPath): string
    {
        $dir = dirname($outputPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents($outputPath, "");
        return $outputPath;
    }
}
