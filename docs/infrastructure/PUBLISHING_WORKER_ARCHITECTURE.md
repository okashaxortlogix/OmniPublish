# OmniPublish — Publishing Worker & Orchestration Architecture

**Document Reference:** `docs/infrastructure/PUBLISHING_WORKER_ARCHITECTURE.md`  
**System Version:** OmniPublish Phase 3GA  
**Target Container:** `docker/Dockerfile` (target: `publishing-worker`)  
**Standard:** Critical Honesty Principle (Zero Synchronous Uploads on API Tier)

---

## 1. Operational Worker Model

The publishing pipeline coordinates the reliable delivery of video content to third-party social platforms (YouTube, TikTok, and Instagram).

### Core Architectural Principle:
```text
[ Client / Browser ]
        │
        ▼ POST /api/v1/publish/batches
[ Laravel API Tier ] ──(Pushes to Redis in < 50ms)──▶ [ Redis Queue: publishing ]
        │                                                         │
  (Returns 201 Created)                                            │
                                                                   ▼
                                                     [ Publishing Worker Fleet ]
                                                                   │
                                                      Platform Publisher Adapters
                                                                   │
                                      ┌────────────────────────────┼────────────────────────────┐
                                      ▼                            ▼                            ▼
                               YouTube API                    TikTok API                  Instagram API
                           (Resumable Upload)             (Chunked Direct Post)       (Container Polling)
```

**The API tier NEVER performs third-party video uploads or status polling synchronously.** Every publishing operation is dispatched as an asynchronous queue job.

---

## 2. Worker Configuration & Resource Profile

* **Dedicated Queue:** `publishing`
* **Worker Execution Command:**
  ```bash
  php artisan queue:work redis \
    --queue=publishing \
    --tries=5 \
    --timeout=300 \
    --sleep=3 \
    --memory=512
  ```
* **Resource Allocation per Container (ECS Fargate):**
  * vCPU: 0.5 vCPU
  * Memory: 1024 MB RAM (Worker process capped at 512 MB via `--memory=512`)
  * Ephemeral Disk: 20 GB standard storage
* **Network Profile:** Outbound HTTPS (TCP port 443) via regional NAT Gateway with persistent Keep-Alive connections.

---

## 3. Platform Ingestion Topologies

Each platform adapter encapsulates provider-specific protocols without leaking transport details into core orchestration:

### 3.1 YouTube Data API v3 (Resumable Upload)
1. **Initiation:** Worker requests upload session from Google Cloud endpoint (`POST /upload/youtube/v3/videos?uploadType=resumable`).
2. **Chunked Transfer:** Worker reads video stream from S3 and uploads in 8 MB chunks via HTTP PUT.
3. **Interruption Recovery:** If a network blip occurs, the worker queries Google for the last acknowledged byte (`PUT` with `Content-Range: bytes */size`) and resumes transmission from that exact offset.

### 3.2 TikTok Content Posting API (Direct Post)
1. **Initialization:** Worker calls `POST /v2/post/publish/video/init/` receiving a `publish_id` and upload URL.
2. **Streaming:** Worker streams binary payload to TikTok's chunked ingestion gateway.
3. **Async Status Polling:** Worker transitions job status to `PROCESSING` with `remote_post_id = publish_id`. Asynchronous reconciliation polls status until final confirmation.

### 3.3 Instagram Graph API v21.0 (Container Flow)
1. **Temporary Presigned Link:** Worker generates a 4-hour temporary presigned S3 download URL.
2. **Container Creation:** Worker calls Meta Graph API `POST /{ig-user-id}/media` passing `video_url`.
3. **Processing Verification:** Meta asynchronously downloads and encodes the video. Worker polls container status (`status_code: EXPIRED | ERROR | FINISHED | IN_PROGRESS`).
4. **Publish Execution:** Once `FINISHED`, worker issues `POST /{ig-user-id}/media_publish` to make the post live.

---

## 4. Rate Limiting, Backoff & Error Taxonomy

OmniPublish implements a 12-category normalized failure taxonomy (`App\Services\PublishingRetryPolicy`) with exponential backoff and decorrelated jitter:

| Error Category | Retryable? | Max Attempts | Base Backoff | Jitter Range | Handling Behavior |
|---|---|---|---|---|---|
| `RATE_LIMITED` | Yes | 5 | 60 seconds | 15 seconds | Captures platform `Retry-After` header if present; backs off exponentially. |
| `NETWORK` | Yes | 4 | 10 seconds | 5 seconds | Transient TCP socket resets, DNS timeouts, connection drops. |
| `TIMEOUT` | Yes | 3 | 20 seconds | 5 seconds | Upstream provider gateway timeouts (HTTP 504). |
| `PROVIDER_UNAVAILABLE`| Yes | 4 | 30 seconds | 10 seconds | Platform 5xx server errors, maintenance windows. |
| `AUTHENTICATION` | **No** | 1 | None | None | Halts retry immediately; marks account `ACTION_REQUIRED`. |
| `AUTHORIZATION` | **No** | 1 | None | None | Missing scopes; halts retry immediately. |
| `VALIDATION` | **No** | 1 | None | None | Invalid aspect ratio, caption too long; terminal failure. |
| `MEDIA_INVALID` | **No** | 1 | None | None | Unreadable audio stream, corrupted container; terminal failure. |
| `QUOTA_EXCEEDED` | **No** | 1 | None | None | Daily YouTube quota unit cap reached (10,000 units/day). |
| `DUPLICATE` | **No** | 1 | None | None | Remote post already exists; marked terminal. |

---

## 5. Proactive & Reactive OAuth Token Refresh

* **Proactive Refresh:** The scheduler runs `tokens:refresh-expiring` hourly, refreshing any OAuth access token expiring within 24 hours.
* **Reactive Refresh:** If a publishing worker encounters an HTTP 401 `AUTHENTICATION` error during a job:
  1. The worker acquires distributed lock `token_refresh_lock_{account_id}`.
  2. Calls `TokenRefreshService::refreshIfExpired`.
  3. If refresh succeeds, updates the encrypted token in PostgreSQL and retries the job immediately.
  4. If refresh fails (refresh token revoked by user), transitions job to `AUTH_REQUIRED` and stops retry loops.

---

## 6. Self-Healing & Stalled Job Reconciliation

If a publishing worker container crashes mid-upload (e.g. EC2 host reboot, Fargate task eviction, OOM panic):
1. **Distributed Lock Expiry:** The Redis lock `publish_lock_job_{id}` expires automatically after 660 seconds.
2. **Stalled Job Reaping (`publishing:reap-stalled`):**
   * Scheduled command runs every 10 minutes.
   * Scans PostgreSQL for jobs in `UPLOADING` or `PROCESSING` with `last_attempt_at <= now() - 15 minutes`.
   * Re-dispatches the destination job to the queue if within `max_attempts`.
3. **Status Reconciliation (`publishing:reconcile`):**
   * Runs every 5 minutes.
   * Queries provider status for jobs possessing a `remote_post_id`.
   * Confirms whether the post succeeded remotely despite the worker crash, marking it `PUBLISHED` without duplicate re-upload.
