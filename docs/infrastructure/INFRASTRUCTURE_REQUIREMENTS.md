# OmniPublish — Infrastructure Requirements Inventory

**Document Reference:** `docs/infrastructure/INFRASTRUCTURE_REQUIREMENTS.md`  
**System Version:** OmniPublish Phase 3GA  
**Engine Baseline:** Laravel 12.0 (PHP 8.3) REST API + Next.js 15.1.0 (Node 20) Frontend  
**Evaluation Standard:** Critical Honesty Principle (Distinguishing Code Reality, Emulated Testing, and Live Cloud Infrastructure)

---

## 1. Executive Overview

OmniPublish is a multi-tenant SaaS platform for automated cross-platform video publishing across YouTube, TikTok, and Instagram. The platform combines high-throughput asynchronous API handling, CPU-intensive video transcoding and inspection, network-intensive chunked/resumable video uploads, strict tenant data isolation, and distributed schedule coordination.

This document inventories the technical infrastructure requirements derived directly from the application codebase, container configurations, and operational workflows.

---

## 2. Application Tier Requirements

### 2.1 Backend API (Laravel 12)
* **Runtime:** PHP 8.3 CLI / FPM (Verified in `docker/Dockerfile`: `php:8.3-cli-alpine`).
* **Required PHP Extensions:**
  * `pdo` & `pdo_pgsql`: Core PostgreSQL database operations.
  * `redis` (via PECL `phpredis`): High-throughput queue polling, atomic cache locks, and event routing.
  * `bcmath`: High-precision arithmetic and temporal offset scheduling.
  * `pcntl`: Process control, worker signal handling (`SIGTERM`, `SIGINT`, `SIGHUP`), and graceful queue reloading.
  * `libpng`, `libzip`, `zip`, `unzip`: Asset packaging and archive handling.
  * `curl`: Outbound HTTP platform requests (Guzzle / Symfony HTTP client).
  * `sodium`: Authenticated ciphertext operations.
* **Concurrency & Execution Profile:**
  * Stateless REST API handling JSON payloads, pre-signed upload requests, OAuth callbacks, and status queries.
  * Target API Response Latency: $< 100\text{ ms}$ for authenticated metadata endpoints; $< 250\text{ ms}$ for upload authorization dispatch.
  * Concurrency Baseline: 50–200 concurrent HTTP requests per API container node.
  * Web Server Protocol: Nginx reverse proxy + PHP-FPM or high-performance application server (Laravel Octane / FrankenPHP or optimized containerized Nginx/PHP-FPM).

### 2.2 Frontend (Next.js 15.1)
* **Runtime:** Node.js 20 LTS Alpine (Verified in `frontend/Dockerfile`: `node:20-alpine`).
* **Framework:** Next.js 15.1.0 App Router, React 19, TypeScript 5.
* **Execution Model:**
  * Standalone Node.js production runner (`runner` stage executing `npm start` on port 3000 as unprivileged user `nextjs` UID 1001).
  * Server-Side Rendering (SSR) for dynamic workspace dashboard, client-side React hooks for real-time publishing status polling.
  * Build-time configuration injection via `NEXT_PUBLIC_API_URL`.
* **Resource Profile:**
  * Memory: 512 MB – 1 GB RAM per frontend replica.
  * CPU: 0.5 – 1 vCPU per replica.
  * Health Check: HTTP probe at `GET /` with 15s interval, 5s timeout.

---

## 3. Database Requirements (PostgreSQL)

### 3.1 Engine & Versions
* **Target Engine:** PostgreSQL 16.2+ (Compatible with PostgreSQL 15+).
* **Character Set & Collation:** `UTF8` / `en_US.UTF-8`.
* **Extensions:** `pgcrypto` (UUID functions), `pg_trgm` (trigram text search for media titles/captions), `btree_gist`.

### 3.2 Workload Characteristics
* **Relational Multi-Tenancy:** Strict workspace scoping via `workspace_id` foreign keys with engine-level cascade deletions (`ON DELETE CASCADE`).
* **Read/Write Ratio:**
  * Web API: ~70% Reads (dashboard, calendar, media queries), ~30% Writes (drafts, publish requests, audit events).
  * Background Queue: Highly write-intensive (job status transitions: `QUEUED` $\to$ `VALIDATING` $\to$ `UPLOADING` $\to$ `PROCESSING` $\to$ `PUBLISHED` / `FAILED`, attempt logging, heartbeat tracking).
* **Data Types Utilized:** Native `UUID` primary keys across all 10 core tables, `JSONB` for `global_metadata`, `platform_overrides`, `scopes`, `destination_config`, `provider_error`, and `TEXT` for AES-256 encrypted OAuth tokens.
* **Migration Footprint:** 7 migration batches (`0001_01_01_000000_create_users_table`, `0001_01_01_000001_create_cache_table`, `0001_01_01_000002_create_jobs_table`, `2026_10_02_000001_create_omnipublish_core_tables`, `2026_10_03_000001_create_media_processing_tables`, `2026_10_03_000002_add_resumable_fields_to_publish_jobs_table`, `2026_10_03_000003_add_orchestration_fields_to_publish_jobs_table`).

### 3.3 Connection Pooling & Limits
* Each API worker, queue worker, and scheduler process maintains open PDO connections.
* A cluster of 16 queue workers + 4 API containers + 1 scheduler can easily produce 40–80 simultaneous open connections.
* Connection pooling via **PgBouncer** or **AWS RDS Proxy** in `transaction` mode is required for API nodes, with dedicated session connections for queue workers.

### 3.4 Backup & Point-in-Time Recovery (PITR)
* Continuous write-ahead log (WAL) archiving.
* Automated daily full snapshots with minimum 7-day retention (30-day for production).
* Target Recovery Point Objective (RPO): $< 15\text{ minutes}$.
* Target Recovery Time Objective (RTO): $< 30\text{ minutes}$.

---

## 4. Redis Requirements

### 4.1 Functional Roles
Redis is a mandatory operational tier in OmniPublish. It is strictly segregated by database index:
1. **Asynchronous Queues (`DB 0`):** Backs Laravel queue drivers for logical queues `publishing`, `media`, and `default`.
2. **Distributed Locks (`DB 0`):**
   * `publish_lock_job_{id}`: Guarantees atomic publishing execution (660s TTL).
   * `process_media_lock_{id}`: Prevents concurrent transcoding of the same asset (360s TTL).
   * `scheduler_dispatch_lock`: Guarantees single-node cron dispatch in multi-container setups (50s TTL).
   * `token_refresh_lock_{id}`: Protects against OAuth token refresh race conditions (60s TTL).
3. **Application Cache (`DB 1`):** Ephemeral tenant workspace caching, user sessions, and API route rate-limiting counters.

### 4.2 Engine & Reliability Parameters
* **Target Version:** Redis 7.x (or Valkey 7.2+).
* **Persistence Requirement:** AOF (`appendonly yes`, `appendfsync everysec`) is mandatory. While business truth is stored in PostgreSQL, unacknowledged queue jobs must survive transient container reboots.
* **Memory & Eviction Policy:**
  * Dedicated instance or separate logical database for queues.
  * Eviction policy for Queue DB (`DB 0`): `noeviction` (Never drop in-flight jobs or locks under memory pressure; throw errors instead).
  * Eviction policy for Cache DB (`DB 1`): `volatile-lru` or `allkeys-lru`.
* **High Availability:** Redis Sentinel or Multi-AZ Managed Cluster with automatic failover.

---

## 5. Object Storage Requirements (S3-Compatible)

### 5.1 Storage Inventory
OmniPublish stores all binary media off-server via S3 API abstraction:
1. **Original Uploads:** Raw video files uploaded directly by users via pre-signed PUT URLs.
2. **Thumbnails:** Extracted JPEG frames (typically 1280x720, ~150 KB).
3. **Normalized Masters & Derivatives:** Optimized H.264/AAC MP4 files formatted for specific platform aspect ratios (e.g. 9:16 vertical for Shorts/TikTok/Reels).
4. **Temporary Objects:** Expired upload authorizations and intermediate chunks.

### 5.2 Access & Security Specifications
* **Private Bucket Enforcement:** All buckets are completely private (`BlockPublicAcls = true`, `IgnorePublicAcls = true`, `BlockPublicPolicy = true`, `RestrictPublicBuckets = true`).
* **Presigned Upload Authorization:** Clients request `POST /api/v1/media/upload-authorize`, receiving a temporary presigned PUT URL with 30-minute expiration.
* **Temporary Download Authorization:** Social platforms (specifically Meta Graph API / Instagram) require a publicly reachable URL to pull video payloads. OmniPublish generates temporary signed GET URLs with 4-hour validity for container ingestion.
* **Partitioning & Traversal Prevention:** Storage keys follow strict multi-tenant namespacing:
  $$\text{workspaces/}\{workspace\_id\}\text{/media/}\{media\_id\}\text{/}\{random\_32\_char\_token\}.\{ext\}$$
* **CORS Policy:** Bucket must permit `PUT`, `GET`, `HEAD` from frontend web origins (`https://app.omnipublish.com`) with exposed headers `ETag`, `Content-Length`, `Content-Type`.
* **Encryption:** Server-Side Encryption with KMS or AES-256 (`SSE-S3`).
* **Lifecycle Rules:** Automatic cleanup of aborted multipart uploads after 7 days; deletion of unconfirmed uploads older than 48 hours.

---

## 6. Media Worker Requirements (FFmpeg / FFprobe)

### 6.1 Computational Profile
* **Binary Requirements:** Static builds of `ffmpeg` and `ffprobe` (version $\ge 6.0$, locally tested with 9.0.1).
* **Workload Characteristics:**
  * CPU-bound: H.264 transcoding (`libx264`, preset `fast`, crf 22–23), audio resampling to AAC 128–192 kbps.
  * Disk I/O bound: Streaming remote video from S3 into local scratch disk, generating transcode output, uploading back to S3.
* **Memory Allocation:** Minimum 1 GB RAM per worker process (Docker target specifies `--memory=1024`, high-resolution transcode requires up to 2048 MB).
* **CPU Allocation:** Minimum 1–2 vCPUs per active transcoding thread.
* **Temporary Scratch Storage:**
  * Ephemeral container disk space must equal at least $3\times$ maximum allowed upload size.
  * Maximum video upload limit: 500 MB (standard tier) to 2 GB (pro tier).
  * Scratch disk budget: Minimum 10 GB – 20 GB ephemeral SSD per worker instance.
  * Scratch directory lifecycle: Isolated directory per job (`/tmp/omnipublish_proc_{id}_{rand}`) cleaned up in `finally` block.
* **Job Limits & Timeouts:** Queue worker timeout: 600s (`--timeout=600`). Max attempts: 3.

---

## 7. Publishing Worker Requirements

### 7.1 Operational Profile
* **Workload Characteristics:** Network I/O bound. Communicates with external social platform APIs.
* **External Endpoints:**
  * YouTube: Google Cloud OAuth 2.0 & YouTube Data API v3 (`https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable`).
  * TikTok: TikTok for Developers Direct Post API (`https://open.tiktokapis.com/v2/post/publish/video/init/`).
  * Instagram: Meta Graph API v21.0 (`https://graph.facebook.com/v21.0/{ig-user-id}/media`).
* **Connection Profile:**
  * Sustained outbound HTTPS connections over TLS 1.3.
  * Multi-megabyte chunked streaming uploads.
  * Asynchronous polling loops waiting for platform video transcoding completion.
* **Worker Execution Limits:**
  * Queue: `publishing`.
  * Timeout: 300s (extended to 600s in code for large video resumable uploads).
  * Max tries: 5 attempts with exponential jittered backoff.
  * Memory: 512 MB per worker.

---

## 8. Scheduler Requirements

### 8.1 Cadence & Operations
All background maintenance runs via Laravel Console Scheduler (`php artisan schedule:work`):
* `publishing:dispatch-scheduled`: **Every minute** (`* * * * *`). Scans for queued batches whose scheduled time has passed and dispatches publishing destination jobs.
* `publishing:reconcile`: **Every 5 minutes** (`*/5 * * * *`). Polls processing remote media jobs and retries due retryable jobs.
* `publishing:reap-stalled`: **Every 10 minutes** (`*/10 * * * *`). Recovers jobs stranded in `UPLOADING` or `PROCESSING` $>15$ minutes due to hard worker crashes.
* `tokens:refresh-expiring`: **Hourly** (`0 * * * *`). Proactively refreshes OAuth tokens expiring within 24 hours.
* `media:cleanup-stale-uploads`: **Hourly** (`0 * * * *`). Marks abandoned uploads $>2$ hours old as failed.

### 8.2 Execution Safety
* Must run with `withoutOverlapping()` enabled across all commands via Redis lock.
* Only one scheduler runner active at any given second, or protected by distributed lock.
* Minimal resource requirement: 0.25 vCPU, 256 MB RAM.

---

## 9. Observability Requirements

* **Application Health Probes:**
  * Liveness: `GET /health/live` (HTTP 200, fast process check).
  * Readiness: `GET /health/ready` (HTTP 200/503, tests PostgreSQL, Redis, and Object Storage without leaking internal credentials).
  * Versioning: `GET /version` and `GET /api/v1/version` (reports environment, Git commit SHA, and build timestamp).
* **Correlation IDs:** Standardized UUID header `X-Correlation-Id` propagated across API requests, database jobs, and logs.
* **Structured Logging:** Single-line JSON formatted logging to stdout/stderr for log aggregation (CloudWatch, Datadog, or Grafana Loki).
* **Alerting Metrics:**
  * API 5xx error rate $> 1\%$.
  * Queue depth on `publishing` $> 50$ for $> 5\text{ min}$.
  * Queue depth on `media` $> 20$ for $> 10\text{ min}$.
  * Dead Letter / Failed job count $> 0$.
  * Database CPU utilization $> 80\%$.
  * Storage bucket 4xx/5xx error spikes.

---

## 10. Summary Requirements Matrix

| Component | Minimum vCPU | Minimum RAM | Ephemeral Disk | Network Profile | Scaling Driver |
|---|---|---|---|---|---|
| **API** | 0.5 vCPU | 512 MB | 2 GB | Inbound HTTP, Outbound DB/Redis | HTTP Request Rate / CPU |
| **Frontend** | 0.5 vCPU | 512 MB | 2 GB | Inbound HTTP, Internal API | HTTP Concurrency |
| **Media Worker** | 2.0 vCPU | 2048 MB | 20 GB (Fast SSD) | High S3 I/O, High CPU | `media` Queue Depth |
| **Publishing Worker**| 0.5 vCPU | 512 MB | 2 GB | Outbound HTTPS I/O | `publishing` Queue Depth |
| **Default Worker** | 0.25 vCPU| 256 MB | 2 GB | Outbound Mail/Notifications | `default` Queue Depth |
| **Scheduler** | 0.25 vCPU| 256 MB | 1 GB | Low Internal Polling | Single Instance (Guarded) |
| **PostgreSQL 16** | 2.0 vCPU | 2048 MB | 20–100 GB (gp3) | Private Subnet Only | Connection Count / IOPS |
| **Redis 7+ (AOF)** | 1.0 vCPU | 512–1024 MB | 10 GB (AOF log) | Private Subnet Only | Memory / Eviction Pressure |
| **Object Storage** | Managed | Managed | Scalable S3 | Inbound Presigned, Outbound S3 | Storage Volume / Egress |
