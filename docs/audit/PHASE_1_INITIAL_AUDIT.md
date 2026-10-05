# Phase 1 Initial Re-Audit & Production Baseline Report

**Project:** OmniPublish  
**Author:** Antigravity Engineering  
**Date:** October 2026  
**Status:** Complete  
**Scope:** Verification of current infrastructure drivers, schema, queue topology, scheduler, storage, and worker configurations prior to Phase 1 Production Infrastructure Migration.

---

## 1. Executive Summary

Phase 0 verified the functional core of OmniPublish, passing 19 test suites, securing YouTube OAuth flows, implementing tenant isolation, and encrypting access/refresh tokens. However, the operational baseline remained bound to single-node development primitives:

- **Database:** SQLite (`backend/database/database.sqlite`)
- **Queue Driver:** Database (`jobs` table via `DB_CONNECTION`)
- **Cache Driver:** Database (`cache` table)
- **Filesystem / Storage:** Local public storage (`storage/app/public`)
- **Upload Flow:** Multi-part POST passing complete video payload through the web server process
- **Scheduler:** Undeployed (empty `routes/console.php`)
- **Worker Configuration:** Local unmanaged `queue:work` process with misaligned `retry_after` (90s vs 600s job timeout)

This audit establishes the concrete architectural gap analysis and technical roadmap for Phase 1 migration to PostgreSQL, Redis, S3-compatible object storage, presigned uploads, hardened workers, and centralized scheduling.

---

## 2. Infrastructure Inventory & Current State

### 2.1 Database Layer
- **Current Driver:** `sqlite` (`DB_CONNECTION=sqlite`, defaulting to `database_path('database.sqlite')`).
- **Foreign Key Constraints:** Enabled conditionally (`foreign_key_constraints => env('DB_FOREIGN_KEYS', true)`). In SQLite, PRAGMA foreign_keys is required per-connection.
- **Data Types & Schema:**
  - `workspaces`: UUID primary key, `unsignedBigInteger` owner foreign key.
  - `workspace_members`: UUID primary key, UUID workspace FK, `unsignedBigInteger` user FK, `enum` role (`OWNER`, `ADMIN`, `EDITOR`, `VIEWER`).
  - `social_accounts`: UUID primary key, encrypted text fields for OAuth tokens, JSON fields for scopes and metadata.
  - `media_assets`: UUID primary key, text storage path, status strings.
  - `content_items`: UUID primary key, JSON metadata, JSON target platforms.
  - `publish_batches`: UUID primary key, scheduled_for index.
  - `publish_jobs`: UUID primary key, attempts tracking, error classification.
  - `publish_attempts`: UUID primary key, execution timestamps.
  - `audit_events`: UUID primary key, polymorphic resource references.
- **PostgreSQL Compatibility Assessment:**
  - Schema definitions use standard Laravel Blueprint primitives (`uuid()`, `json()`, `timestamps()`, `foreign()`).
  - All foreign keys match referenced primary key types (`id()` with `unsignedBigInteger()`, `uuid()` with `uuid()`).
  - PostgreSQL natively supports UUIDs, JSONB, and transactional DDL.
  - Index definitions and composite unique constraints (`['workspace_id', 'user_id']`) translate directly to Postgres B-tree indexes.

### 2.2 Queue Architecture & Reliability
- **Current Driver:** `database` connection (`jobs`, `failed_jobs`, `job_batches`).
- **Existing Job Classes:**
  - `PublishBatchJob`: Dispatches individual `PublishDestinationJob` instances for each target platform destination.
  - `PublishDestinationJob`: Executes platform publishing flow for a single destination.
- **Timeout & Retry Mismatch (Critical Finding):**
  - In `PublishDestinationJob.php`: `$timeout = 600` seconds (10 minutes) for large video uploads.
  - In `backend/config/queue.php`: `'retry_after' => (int) env('DB_QUEUE_RETRY_AFTER', 90)` and `'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 90)`.
  - **Vulnerability:** When a video upload takes more than 90 seconds, the queue driver considers the job abandoned, unhides it, and permits a second worker to pick up and process the identical job while the first worker is actively uploading.
  - **Resolution Required:** `retry_after` must be increased to at least 660 seconds (job timeout + safety buffer).
- **Locking & Idempotency:**
  - `PublishDestinationJob` acquires a distributed lock (`publish_lock_job_{$job->id}`) with a 120-second TTL. This TTL is also shorter than the 600-second job timeout, leaving a window for race conditions if execution is slow.
  - State check `if ($job->status === 'PUBLISHED')` prevents double-publishing if remote post ID is already recorded.

### 2.3 Cache Driver
- **Current Driver:** `database` (`cache` and `cache_locks` tables).
- **Production Target:** Redis with cluster/standalone connection, prefixed keys, and persistent connection options.

### 2.4 Filesystem & Media Storage
- **Current Driver:** `local` / `public` (`storage/app/public`).
- **Current Upload Flow:**
  - Client sends multipart file to `POST /api/v1/media/upload`.
  - Server buffers file in memory/tmp, writes to `storage/app/public/videos/{random}.mp4`.
  - Web server worker is blocked during multi-hundred-megabyte uploads.
  - Client-side mock upload bypasses disk verification entirely.
- **Asset URL Resolution:**
  - `MediaAsset` model hardcodes `Storage::disk('public')->url(...)`, preventing dynamic switching to S3/Cloudflare R2.
- **Production Target:**
  - Dual-disk abstraction (`MediaStorageInterface`).
  - Presigned upload authorization endpoint (`POST /api/v1/media/upload-authorization`).
  - Direct client-to-storage upload (S3, Cloudflare R2, MinIO).
  - Backend verification endpoint (`POST /api/v1/media/confirm-upload`) validating actual object presence, byte size, and MIME.

### 2.5 Scheduler
- **Current Implementation:** Only default Laravel `inspire` command in `backend/routes/console.php`. No cron tasks configured.
- **Production Target:** Centralized Laravel schedule registering:
  - `publishing:dispatch-scheduled` (every minute)
  - `publishing:reap-stalled` (every 10 minutes)
  - `tokens:refresh-expiring` (hourly)
  - `media:cleanup-stale-uploads` (hourly)

### 2.6 Docker & Local Development Setup
- **Existing Docker Compose:** `docker/docker-compose.local.yml` contains:
  - PostgreSQL 16 container (`postgres:16`) on port 5432
  - Redis 7 container (`redis:7-alpine`) on port 6379
  - MinIO container (`minio/minio`) on port 9000/9001
- **Status:** Architecture is already container-ready; environment variables and local bridges must be wired cleanly.

---

## 3. Migration Action Plan (Phase 1 Priorities)

1. **PostgreSQL Migration (Phase 1.1 - 1.2):**
   - Validate migrations against PostgreSQL schemas.
   - Maintain SQLite compatibility for lightweight local tests.
   - Produce `docs/infrastructure/POSTGRESQL.md` and `docs/infrastructure/DATABASE_MIGRATION_RUNBOOK.md`.
2. **Redis & Queue Reliability (Phase 1.3 - 1.5):**
   - Configure Redis queue and cache with 660s `retry_after`.
   - Harden distributed lock TTLs (660s).
   - Author queue reliability regression test suite (`QueueReliabilityTest.php`) verifying duplicate dispatch, worker interruption, API timeout, partial failure, and isolated retries.
   - Document queue architecture in `docs/infrastructure/REDIS.md` and `docs/infrastructure/QUEUE_WORKERS.md`.
3. **Scheduler Implementation (Phase 1.6):**
   - Register console commands for publishing dispatch, stalled job reaping, token refresh, and stale upload cleanup.
   - Document scheduler execution in `docs/infrastructure/SCHEDULER.md`.
4. **S3-Compatible Storage & Presigned Uploads (Phase 1.7 - 1.11):**
   - Implement `MediaStorageInterface` and `MediaStorageService`.
   - Update `MediaController` with `/upload-authorization`, `/confirm-upload`, and `/media/{id}` deletion.
   - Update `MediaAsset` model to use dynamic disk URLs.
   - Document object storage and pipeline in `docs/infrastructure/OBJECT_STORAGE.md` and `docs/infrastructure/MEDIA_UPLOAD_PIPELINE.md`.
5. **Observability, Security & Verification (Phase 1.12 - 1.20):**
   - Multi-tenant media storage security tests (`MediaStorageSecurityTest.php`).
   - Frontend direct upload integration with local fallback.
   - End-to-end verification and Ralph loop test execution.
