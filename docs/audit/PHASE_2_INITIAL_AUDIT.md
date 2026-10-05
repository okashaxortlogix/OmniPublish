# Phase 2 Initial Re-Audit & Video Pipeline Baseline Report

**Project:** OmniPublish  
**Author:** Antigravity Engineering  
**Date:** October 2026  
**Status:** Complete  
**Scope:** Pre-implementation architectural audit of media models, controller confirmation flows, storage pipeline, and queue worker readiness for Phase 2 Production Video Processing & Transcoding Engine.

---

## 1. Executive Summary

Phase 1 established the storage and multi-tenant persistence foundation: PostgreSQL schema compatibility, Redis queue reliability, S3-compatible object storage abstractions (`MediaStorageInterface`), and presigned direct browser uploads with zero-trust physical existence verification.

However, media confirmation currently performs **static metadata assignment**:
- `width` (1080), `height` (1920), `aspect_ratio` ('9:16'), `duration_seconds` (45), `codec` ('H.264 / AAC'), and `thumbnail_path` (external unsplash placeholder) are hardcoded.
- Media files are not inspected with `ffprobe` for actual container streams, pixel formats, bitrates, audio channels, or sample rates.
- No real asynchronous transcoding, thumbnail extraction, or platform-specific derivative generation exists.
- The pipeline does not yet isolate the original master from processed derivatives.

Phase 2 replaces these placeholders with a production-grade, asynchronous FFmpeg video processing pipeline that guarantees original immutability, deterministic state transitions, security against command injection, and tenant isolation.

---

## 2. Current State Inventory & Architectural Gaps

### 2.1 Media Asset Domain Model
- **Current Model:** `App\Models\MediaAsset`
- **Current Table Schema:**
  - `id` (UUID primary key)
  - `workspace_id` (UUID foreign key referencing `workspaces`)
  - `filename`, `original_name`, `mime_type`, `size_bytes`, `storage_path`, `thumbnail_path`
  - `duration_seconds`, `width`, `height`, `aspect_ratio`, `codec`
  - `status`: `UPLOAD_AUTHORIZED`, `UPLOADING`, `PROCESSING`, `READY`, `FAILED`, `DELETED`
  - `error_message`
- **Gap Analysis:**
  - No independent tracking for derivatives (thumbnails, normalized masters, platform-specific adaptations).
  - No structured metadata columns for detailed technical inspection: `bitrate`, `frame_rate`, `audio_codec`, `audio_channels`, `audio_sample_rate`, `pixel_format`, `container_format`.
  - Processing lifecycle lacks fine-grained progression tracking (`QUEUED`, `ANALYZING`, `PROCESSING`, `GENERATING_THUMBNAIL`, `READY`, `FAILED`, `CANCELLED`).

### 2.2 Ingestion & Confirmation Flow
- **Current Implementation:** `MediaController::confirmUpload` directly verifies physical storage presence (`MediaStorageInterface::verifyUploadedObject`) and transitions the asset to `READY` synchronously inside the HTTP response.
- **Architectural Violation:** Video processing must **never** run synchronously inside an HTTP request cycle.
- **Phase 2 Target:**
  - `confirmUpload` transitions status to `QUEUED` and dispatches `ProcessMediaAssetJob` to the background queue.
  - The API returns HTTP 202 / 200 with `status: QUEUED`.
  - Worker processes the asset asynchronously through `ffprobe` -> validation -> thumbnail extraction -> derivative creation -> `READY`.

### 2.3 Queue & Worker Environment
- **Current Queues:** `publishing`, `media`, `default`, `analytics`.
- **Worker Configuration:** Dedicated `media` queue already defined in `docs/infrastructure/QUEUE_WORKERS.md` with 300s timeout and 1024MB memory ceiling.
- **Missing Link:** Dedicated queue job `ProcessMediaAssetJob` with distributed locking (`Cache::lock("process_media_{$id}", 660)`).

### 2.4 FFmpeg & Host Environment
- **Initial Verification:** `ffmpeg` and `ffprobe` were not globally present in the host PATH.
- **Remediation Plan:**
  - Local environment: Provision static builds via winget / portable binary detection.
  - Container environment: Build dedicated worker Dockerfile with Alpine/Debian `ffmpeg` and `ffprobe` packages.
  - Runtime service: Controlled, injectable `FFmpegService` implementing argument encapsulation via `Symfony\Component\Process\Process` to eliminate shell injection vulnerabilities.

---

## 3. Phase 2 Implementation Roadmap

1. **Environment & Binaries:** Verify and document FFmpeg/ffprobe installation (`docs/infrastructure/FFMPEG.md`).
2. **Schema Migration:** Add `media_derivatives` table and enrich `media_assets` with technical stream metadata and fine-grained processing states.
3. **State Machine:** Implement `MediaProcessingState` with strict transition validation (`UPLOADED` -> `QUEUED` -> `PROCESSING` -> `READY`/`FAILED`).
4. **FFprobe Inspector & Validator:** Extract authentic container, video, and audio streams; reject corrupt or unparseable files.
5. **Thumbnail & Derivative Pipeline:** Asynchronously extract frame-accurate thumbnails and normalized derivatives to object storage.
6. **Platform Media Profiles:** Define extensible profile architecture for YouTube, TikTok, and Instagram without hardcoding speculative constraints.
7. **Security & Isolation:** Enforce zero shell concatenation, isolated temp directories, and strict multi-tenant access controls.
8. **Observability & Testing:** Test suite covering corrupt files, duplicate dispatch, timeout limits, worker crash recovery, and tenant boundaries.
