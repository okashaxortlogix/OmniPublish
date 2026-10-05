# Media Cleanup & Storage Reconciliation Strategy

## 1. Overview & Core Directives

In OmniPublish Phase 2, video processing involves multiple distributed components:
- Source upload verification (direct S3/MinIO bucket `omnipublish-media-raw`)
- Local worker temporary scratch directories (`storage/app/temp/media_{id}_{rand}`)
- Extracted representative thumbnails (`derivatives/thumbnails/{workspace_id}/{hash}.jpg`)
- Normalized master derivatives (`derivatives/masters/{workspace_id}/{hash}.mp4`)
- Platform-tailored derivatives (`derivatives/{platform}/{workspace_id}/{hash}.mp4`)

A failure at any stage (worker crash, OOM kill, network timeout to S3) can potentially leave orphaned files or database inconsistencies.

### Fundamental Retention Rule
> **ORIGINAL UPLOADS ARE IMMUTABLE AND SACROSANCT.**
> Automated cleanup daemons and sweeps **MUST NEVER** automatically delete source raw uploads (`media_assets.storage_path`) unless an explicit user-initiated deletion (`DELETE /api/v1/media/{id}`) or a verified workspace tenant purge command is executed.

---

## 2. Categories of Stale/Orphaned Artifacts

### 2.1 Stale Local Worker Scratch Directories
- **Location:** `storage/app/temp/media_*`
- **Cause:** Abrupt worker container termination, unhandled SIGKILL, or physical host power loss before the `finally` cleanup block executes.
- **Impact:** Temporary local disk consumption.
- **Reconciliation Policy:**
  - Standard execution cleans up scratch directories deterministically via `try ... finally` inside `ProcessMediaAssetJob`.
  - A scheduled cleanup task runs every hour:
    ```bash
    php artisan media:prune-scratch --older-than=120
    ```
    This deletes any temporary directories in `storage/app/temp/` with `mtime` older than 120 minutes.

### 2.2 Abandoned / Stalled Processing Jobs
- **Symptom:** `media_assets.status = 'PROCESSING'` or `'QUEUED'` with `processing_started_at` older than the configured timeout (e.g., 10 minutes).
- **Cause:** Worker OOM, network partition, or ungraceful daemon restart while holding the distributed lock.
- **Reconciliation Policy:**
  - The distributed lock (`media_processing_lock_{media_id}`) has a strict TTL of 360 seconds (6 minutes).
  - The centralized scheduler (`app/Console/Kernel.php`) reaps dead jobs every 5 minutes:
    - Any asset in `PROCESSING` status older than 15 minutes is transitioned to `FAILED` with `processing_error = 'Processing timeout: worker heartbeat expired'`.
    - This allows the user to click "Retry" in the UI safely.

### 2.3 Orphaned Storage Derivatives (Derivatives with No Database Record)
- **Cause:** Derivative was uploaded to S3 bucket `omnipublish-media-processed`, but database transaction crashed before inserting into `media_derivatives`.
- **Reconciliation Policy:**
  - S3 bucket lifecycle rule: `derivatives/` can be audited via daily reconciliation job.
  - The reconciliation command `php artisan media:audit-storage-orphans` scans storage keys and checks if `MediaDerivative::where('storage_path', $key)->exists()`.
  - Storage objects unreferenced for > 7 days are staged for deletion.

### 2.4 Ghost Database Records (Records Pointing to Missing Storage Objects)
- **Cause:** Underlying S3 bucket key deleted externally or corrupted storage volume.
- **Reconciliation Policy:**
  - Health check command `php artisan media:verify-integrity {--workspace=}`:
    - Runs `MediaStorage::exists($asset->storage_path)` for assets marked `READY`.
    - If missing, flags record as `FAILED` with `processing_error = 'Source object missing in storage'` rather than silently failing during publishing.

### 2.5 Partial Processing / Derivative Replacement on Retry
- **Scenario:** Processing failed halfway through derivative transcoding, and user initiates reprocess (`POST /api/v1/media/{id}/reprocess`).
- **Policy:**
  - `ProcessMediaAssetJob` deletes any previously existing `MediaDerivative` rows and corresponding storage objects before writing new derivatives.
  - This prevents duplicate derivative explosion across retry loops.

---

## 3. Scheduled Maintenance Commands

| Command | Frequency | Target | Action |
|---|---|---|---|
| `media:prune-scratch` | Hourly | Local `/temp` | Removes scratch dirs > 2h old |
| `media:reap-stalled` | Every 5 min | DB `media_assets` | Transitions stale `PROCESSING` > 15m to `FAILED` |
| `media:audit-storage-orphans` | Weekly (Sunday) | S3 buckets | Flags unreferenced objects > 7d old |

---

## 4. Production Checklist

- [x] Ephemeral scratch storage located inside isolated per-job subdirectories
- [x] Guaranteed `finally` cleanup in `ProcessMediaAssetJob`
- [x] Distributed lock TTL configured to prevent permanent deadlock
- [x] Original master files marked immutable
- [x] Partial derivative cleanup on retry
