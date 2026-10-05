# FFmpeg & Media Processing Infrastructure Guide

**OmniPublish Video Processing & Transcoding Engine**  
**Version:** 2.0 (Phase 2 Implementation)  
**Detected Host Engine:** FFmpeg 9.0.1 / FFprobe 9.0.1 (Gyan Essentials Build)  
**Supported Runtime Minimum:** FFmpeg 6.0+

---

## 1. Environment Verification & Engine Specifications

OmniPublish requires FFmpeg and FFprobe binaries in queue worker execution environments for video inspection, thumbnail extraction, metadata normalization, and derivative generation.

### 1.1 Verified Local Host Engine
- **FFmpeg Version:** `ffmpeg version 9.0.1-essentials_build-www.gyan.dev`
- **FFprobe Version:** `ffprobe version 9.0.1-essentials_build-www.gyan.dev`
- **Compiler:** `gcc 16.1.0 (MSYS2)`
- **Key Codecs & Filters Enabled:**
  - Video encoders/decoders: `libx264` (H.264), `libx265` (HEVC/H.265), `libvpx` (VP8/VP9), `libaom` (AV1), `libwebp`
  - Audio encoders/decoders: `aac`, `libmp3lame` (MP3), `libopus`, `libvorbis`
  - Hardware acceleration support: `amf`, `nvenc`/`nvdec`, `d3d11va`, `d3d12va`, `dxva2`
  - Scaling & color libraries: `libswscale`, `libswresample`, `libzimg`

### 1.2 Binary Detection & Configuration
OmniPublish allows explicit binary path overrides via `.env`, falling back to standard system `$PATH`:
```ini
FFMPEG_PATH="C:\Users\Muhammad Talha\AppData\Local\Microsoft\WinGet\Packages\Gyan.FFmpeg.Essentials_Microsoft.Winget.Source_8wekyb3d8bbwe\ffmpeg-9.0.1-essentials_build\bin\ffmpeg.exe"
FFPROBE_PATH="C:\Users\Muhammad Talha\AppData\Local\Microsoft\WinGet\Packages\Gyan.FFmpeg.Essentials_Microsoft.Winget.Source_8wekyb3d8bbwe\ffmpeg-9.0.1-essentials_build\bin\ffprobe.exe"
```
In containerized environments (Docker/Alpine/Debian):
```ini
FFMPEG_PATH="/usr/bin/ffmpeg"
FFPROBE_PATH="/usr/bin/ffprobe"
```

---

## 2. Worker Hardware & Resource Considerations

Video transcoding is computationally intensive and memory-bound.

| Workload | Recommended CPU Cores | Memory Allocation | Max Concurrent Jobs |
|---|---|---|---|
| **Inspection & Thumbnail Extraction** | 1–2 vCPUs | 512 MB – 1 GB | 4–8 per worker |
| **720p / 1080p Normalization (H.264)** | 2–4 vCPUs | 1 GB – 2 GB | 2–4 per worker |
| **4K Transcoding / Platform Adaptation** | 4–8 vCPUs | 2 GB – 4 GB | 1–2 per worker |

### 2.1 Queue Process Configuration:
```bash
php artisan queue:work redis \
    --queue=media \
    --timeout=600 \
    --memory=1024 \
    --tries=2
```

---

## 3. Temporary Filesystem Requirements

Worker processing streams or downloads the remote object into an ephemeral directory on the worker node.

1. **Isolation:** Each job creates a unique scratch directory:
   $$\text{Path} = \text{sys\_get\_temp\_dir}() + \text{/omnipublish\_media\_} + \text{job\_uuid}$$
2. **Guaranteed Cleanup:** Temporary source files, extracted frames, and normalized derivatives are purged in a `finally` block regardless of whether processing succeeds, fails, or throws an unhandled exception.
3. **Disk Space Budget:** Allocate at least $3\times$ the maximum allowed source file size in temporary storage. For a 500MB max upload, reserve at least 1.5GB of free scratch disk per concurrent worker.

---

## 4. Security & Command Injection Guards

1. **Zero Shell Concatenation:** Never use `shell_exec("ffmpeg -i $path ...")` or `system()`.
2. **Controlled Argument Arrays:** Commands are executed strictly through `Symfony\Component\Process\Process` passing validated arguments as an array:
   ```php
   $process = new Process([
       $this->ffmpegPath,
       '-v', 'error',
       '-ss', (string) $timestamp,
       '-i', $sourcePath,
       '-vframes', '1',
       '-q:v', '2',
       '-y',
       $targetPath,
   ]);
   ```
3. **No User-Supplied Flags:** Filter graphs, scaling parameters, and codec flags are determined exclusively by internal platform media profiles.
