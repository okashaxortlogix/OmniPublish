# OmniPublish — Media Worker & FFmpeg Architecture

**Document Reference:** `docs/infrastructure/MEDIA_WORKER_ARCHITECTURE.md`  
**System Version:** OmniPublish Phase 3GA  
**Target Container:** `docker/Dockerfile` (target: `media-worker`)  
**Standard:** Critical Honesty Principle (Zero Unbounded Worker Memory Leaks)

---

## 1. Operational Worker Model

The media processing pipeline is responsible for technical metadata inspection, frame extraction, master normalization, and platform-specific video adaptation.

Because video transcoding is CPU- and disk-intensive, media processing jobs are strictly isolated from interactive web requests and network publishing workers:
* **Dedicated Queue:** `media`
* **Worker Execution Command:**
  ```bash
  php artisan queue:work redis \
    --queue=media \
    --tries=3 \
    --timeout=600 \
    --sleep=3 \
    --memory=1024
  ```
* **Job Class:** `App\Jobs\ProcessMediaAssetJob`
* **Concurrency Model:** 1 job per worker process. Horizontal scaling is achieved by spawning additional worker tasks/containers rather than running multi-threaded PHP within a single process.

---

## 2. Resource Sizing & Ephemeral Scratch Storage

### 2.1 Compute & Memory Allocation (ECS Fargate Task)
* **vCPU:** 2.0 vCPU per task. (H.264 transcoding with `libx264 -preset fast` scales effectively across 2 cores).
* **RAM:** 4096 MB allocated to container (PHP process memory capped at 1024 MB via `--memory=1024`).
* **Ephemeral Scratch Disk:** 30 GB NVMe SSD attached per Fargate task.

### 2.2 Preventing Memory Exhaustion on Large Videos
A frequent failure mode in video processing is loading entire video payloads into PHP memory (`file_get_contents`). OmniPublish explicitly eliminates this vulnerability:
1. **Stream Copy:** When downloading from S3, `ProcessMediaAssetJob` utilizes stream-to-stream piping:
   ```php
   $sourceStream = $storage->readStream($media->storage_path);
   $localStream = fopen($localSourcePath, 'wb');
   stream_copy_to_stream($sourceStream, $localStream);
   fclose($localStream);
   fclose($sourceStream);
   ```
   PHP memory consumption during download is $< 10\text{ MB}$, regardless of whether the video is 50 MB or 2 GB.
2. **Process Delegation:** Transcoding and probing are delegated directly to FFmpeg/FFprobe binaries running as external OS processes via Symfony `Process`. Video data stays on disk and within FFmpeg's optimized C buffers, never entering PHP userland memory.

---

## 3. Temporary Scratch Lifecycle & Cleanup Guarantees

Every processing job executes within an isolated, randomized ephemeral scratch directory:

```text
/tmp/omnipublish_proc_{media_id}_{random_8_chars}/
  ├── source.mp4         (Streamed from S3)
  ├── thumb.jpg          (Extracted JPEG frame)
  └── normalized.mp4     (Transcoded master if profile requires)
```

### Guaranteed Cleanup in `finally` Block:
Regardless of whether processing succeeds, fails validation, times out, or encounters a corrupt codec exception, the scratch directory is recursively unlinked in the job's `finally` block:

```php
try {
    // 1. Download source
    // 2. FFprobe inspection
    // 3. Thumbnail extraction
    // 4. S3 upload of thumbnail & derivatives
    // 5. Transition to READY
} catch (Throwable $e) {
    // Transition to FAILED, record audit event
} finally {
    $this->cleanupDirectory($scratchDir);
    $lock->release();
}
```

This guarantees zero disk exhaustion across thousands of consecutive worker job runs.

---

## 4. Execution Security & Command Hardening

To prevent command injection via malicious video filenames or user metadata:
1. **Zero Shell String Concatenation:** `shell_exec()` and `system()` are strictly forbidden.
2. **Validated Argument Arrays:** All FFmpeg invocations use Symfony's `Process` passing argument arrays:
   ```php
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
   ```
3. **Hard Bounds Enforcement:**
   * Maximum duration: 43,200 seconds (12 hours) — checked via probe before any transcode.
   * Maximum resolution: 4096x4096 (4K UHD) — rejected if exceeded.

---

## 5. Worker Autoscaling & Graceful Draining

### 5.1 Autoscaling Policy (AWS Application Auto Scaling)
* **Scale-Out Trigger:** Custom CloudWatch metric `OmniPublish/QueueDepth/media` $> 5$ for 2 consecutive minutes $\implies$ Add 2 worker tasks (up to Max 8).
* **Scale-In Trigger:** `QueueDepth/media` $== 0$ for 15 consecutive minutes $\implies$ Decrement by 1 worker task (down to Min 1).

### 5.2 Graceful Shutdown (`SIGTERM`)
During rolling deployments or scale-in events:
1. ECS sends `SIGTERM` to the container.
2. Laravel queue worker intercepts `SIGTERM` (via PHP `pcntl` extension).
3. The worker stops accepting new jobs from Redis but allows the active FFmpeg transcode to finish (up to 300 seconds).
4. Scratch cleanup executes in `finally`, and the process terminates with exit code 0.
