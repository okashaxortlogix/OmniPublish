<?php

namespace App\Jobs;

use App\Models\MediaAsset;
use App\Models\MediaDerivative;
use App\Models\AuditEvent;
use App\Services\Contracts\FFmpegServiceInterface;
use App\Platforms\Profiles\PlatformProfileManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class ProcessMediaAssetJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $backoff = 10;
    public int $timeout = 300;

    public function __construct(
        public readonly string $mediaAssetId
    ) {
        $this->onQueue('media');
    }

    public function handle(
        FFmpegServiceInterface $ffmpegService,
        PlatformProfileManager $profileManager
    ): void {
        $media = MediaAsset::find($this->mediaAssetId);

        if (!$media) {
            Log::error("ProcessMediaAssetJob: MediaAsset not found [{$this->mediaAssetId}]");
            return;
        }

        // Idempotency: Distributed processing lock (360s TTL aligns with 300s timeout + safety)
        $lockKey = "process_media_lock_{$media->id}";
        $lock = Cache::lock($lockKey, 360);

        if (!$lock->get()) {
            Log::warning("ProcessMediaAssetJob: Asset {$media->id} is currently being processed by another worker. Skipping.");
            return;
        }

        $scratchDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'omnipublish_proc_' . $media->id . '_' . Str::random(8);

        try {
            // Idempotency: if already ready, do not re-process
            if ($media->status === 'READY') {
                Log::info("ProcessMediaAssetJob: Asset {$media->id} is already in READY status. Skipping.");
                return;
            }

            Log::info("ProcessMediaAssetJob: Starting processing for asset {$media->id}");
            $media->transitionTo('PROCESSING', 'ANALYZING');

            $disk = config('filesystems.default', 'public');
            $storage = Storage::disk($disk);

            // Step 1: Verify source object existence on storage disk
            if (!$storage->exists($media->storage_path)) {
                throw new \InvalidArgumentException("Source storage object not found: {$media->storage_path}");
            }

            // Step 2: Stream source object into isolated local scratch directory
            if (!is_dir($scratchDir)) {
                mkdir($scratchDir, 0755, true);
            }

            $extension = pathinfo($media->storage_path, PATHINFO_EXTENSION) ?: 'mp4';
            $localSourcePath = $scratchDir . DIRECTORY_SEPARATOR . 'source.' . $extension;

            $sourceStream = $storage->readStream($media->storage_path);
            $localStream = fopen($localSourcePath, 'wb');
            stream_copy_to_stream($sourceStream, $localStream);
            fclose($localStream);
            if (is_resource($sourceStream)) {
                fclose($sourceStream);
            }

            if (!file_exists($localSourcePath) || filesize($localSourcePath) === 0) {
                throw new \InvalidArgumentException("Downloaded source payload is empty.");
            }

            // Step 3: Run ffprobe metadata inspection
            $probe = $ffmpegService->probe($localSourcePath);

            // Step 4: Validate resource limits
            $maxDuration = (int) env('MEDIA_MAX_DURATION_SECONDS', 43200);
            if ($probe['duration'] > $maxDuration) {
                throw new \InvalidArgumentException("Video duration ({$probe['duration']}s) exceeds maximum allowed limit ({$maxDuration}s).");
            }

            $maxWidth = (int) env('MEDIA_MAX_WIDTH', 4096);
            $maxHeight = (int) env('MEDIA_MAX_HEIGHT', 4096);
            if ($probe['width'] > $maxWidth || $probe['height'] > $maxHeight) {
                throw new \InvalidArgumentException("Video dimensions ({$probe['width']}x{$probe['height']}) exceed maximum allowed limit ({$maxWidth}x{$maxHeight}).");
            }

            // Persist authoritative probed stream metadata
            $media->update([
                'duration_seconds' => (int) round($probe['duration']),
                'width' => $probe['width'],
                'height' => $probe['height'],
                'aspect_ratio' => $probe['aspect_ratio'],
                'codec' => strtoupper($probe['video_codec']) . ($probe['audio_codec'] ? ' / ' . strtoupper($probe['audio_codec']) : ''),
                'video_codec' => $probe['video_codec'],
                'audio_codec' => $probe['audio_codec'],
                'bitrate' => $probe['bitrate'],
                'frame_rate' => $probe['frame_rate'],
                'pixel_format' => $probe['pixel_format'],
                'audio_channels' => $probe['audio_channels'],
                'audio_sample_rate' => $probe['audio_sample_rate'],
                'container_format' => $probe['container_format'],
                'size_bytes' => $probe['size_bytes'],
            ]);

            // Step 5: Thumbnail Extraction
            $media->transitionTo('PROCESSING', 'GENERATING_THUMBNAIL');
            $localThumbPath = $scratchDir . DIRECTORY_SEPARATOR . 'thumb.jpg';
            $thumbMetadata = $ffmpegService->extractThumbnail($localSourcePath, $localThumbPath, 1.0, 1280);

            $thumbStorageKey = "workspaces/{$media->workspace_id}/media/{$media->id}/thumbnail.jpg";
            $storage->put($thumbStorageKey, file_get_contents($localThumbPath));

            // Persist Thumbnail Derivative
            MediaDerivative::updateOrCreate(
                [
                    'media_asset_id' => $media->id,
                    'derivative_type' => 'THUMBNAIL',
                ],
                [
                    'workspace_id' => $media->workspace_id,
                    'storage_path' => $thumbStorageKey,
                    'mime_type' => 'image/jpeg',
                    'size_bytes' => $thumbMetadata['size_bytes'],
                    'width' => $thumbMetadata['width'],
                    'height' => $thumbMetadata['height'],
                    'status' => 'READY',
                ]
            );

            $media->update([
                'thumbnail_path' => $thumbStorageKey,
            ]);

            // Step 6: Platform Derivatives (optional per profile requirement)
            $media->transitionTo('PROCESSING', 'CREATING_DERIVATIVES');

            // Step 7: Transition to READY
            $media->transitionTo('READY', 'READY');

            AuditEvent::create([
                'workspace_id' => $media->workspace_id,
                'action' => 'MEDIA_PROCESSED',
                'resource_type' => 'MediaAsset',
                'resource_id' => $media->id,
                'metadata' => [
                    'duration' => $probe['duration'],
                    'resolution' => "{$probe['width']}x{$probe['height']}",
                    'codec' => $probe['video_codec'],
                ],
            ]);

            Log::info("ProcessMediaAssetJob: Asset {$media->id} successfully processed and marked READY.");

        } catch (Throwable $e) {
            Log::error("ProcessMediaAssetJob: Processing failed for asset {$media->id}: " . $e->getMessage(), [
                'exception' => $e,
            ]);

            try {
                $media->transitionTo('FAILED', 'FAILED', $e->getMessage());
            } catch (Throwable $transitionError) {
                $media->update([
                    'status' => 'FAILED',
                    'processing_stage' => 'FAILED',
                    'error_message' => $e->getMessage(),
                ]);
            }

            AuditEvent::create([
                'workspace_id' => $media->workspace_id,
                'action' => 'MEDIA_PROCESSING_FAILED',
                'resource_type' => 'MediaAsset',
                'resource_id' => $media->id,
                'metadata' => [
                    'error' => $e->getMessage(),
                ],
            ]);

        } finally {
            // Clean up temporary scratch directory
            $this->cleanupDirectory($scratchDir);
            $lock->release();
        }
    }

    /**
     * Recursively remove scratch directory.
     */
    protected function cleanupDirectory(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . DIRECTORY_SEPARATOR . $item;
            if (is_dir($path)) {
                $this->cleanupDirectory($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
