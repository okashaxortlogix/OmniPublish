# OmniPublish — Production Failure Recovery Matrix

**Document Reference:** `docs/infrastructure/FAILURE_RECOVERY_MATRIX.md`  
**System Version:** OmniPublish Phase 3GA  
**Standard:** Critical Honesty Principle (Exhaustive Modeling of 18 Production Failure Scenarios)

---

## 1. Overview & Failure Philosophy

OmniPublish is engineered with a zero-trust, self-healing operational philosophy:
* **Decoupled Failure Domains:** A failure in video transcoding never impairs web API response times. An outage on YouTube never stalls TikTok publishing.
* **Deterministic Idempotency:** Distributed locks and state machine guards prevent duplicate external posts across any worker crash or retry.
* **Autonomous Self-Healing:** The scheduler and queue reliability engines automatically discover, reconcile, and re-drive stranded operations without requiring human intervention.

---

## 2. Comprehensive 18-Scenario Failure Matrix

### Scenario 1: API Container Crashes
* **Detection:** ALB health check fails (`GET /health/live` returns connection refused or 502).
* **Impact:** In-flight HTTP requests to that container fail.
* **Automatic Recovery:** ALB stops routing traffic to the failed task. ECS Fargate restarts or replaces the container within 30 seconds. Multi-AZ API replicas absorb traffic.
* **Manual Recovery:** None required unless all API containers crash (inspect CloudWatch Logs for fatal boot errors).
* **Data Loss Risk:** Zero. Transactions in PostgreSQL are atomic; uncommitted requests fail cleanly.
* **User-Visible Behavior:** Transient HTTP 502/504 if request was mid-flight; client retries cleanly.
* **Alert:** `ALB_Target_5xx_Error_High` alarm fires if 5xx rate $> 1\%$.

---

### Scenario 2: Frontend Container Crashes
* **Detection:** ALB health probe on port 3000 (`GET /`) fails.
* **Impact:** Active web browser users on that container experience connection drops.
* **Automatic Recovery:** ECS health check replaces container. Secondary frontend replica serves traffic. CloudFront edge cache serves static assets.
* **Manual Recovery:** None required.
* **Data Loss Risk:** Zero. Frontend is completely stateless.
* **User-Visible Behavior:** Brief page load delay or blank screen until page reload.
* **Alert:** `Frontend_Unhealthy_Host_Count` $> 0$.

---

### Scenario 3: PostgreSQL Database Unavailable
* **Detection:** `/health/ready` probe fails with HTTP 503 (`database: down`). Application logs throw `PDOException`.
* **Impact:** Full application read/write outage. Web requests return HTTP 503 `DEGRADED`. Workers pause queue processing.
* **Automatic Recovery:** If primary AZ fails, RDS Multi-AZ promotes standby replica in AZ-b within 60 seconds. RDS Proxy holds incoming connection queries and resumes once database is primary.
* **Manual Recovery:** If instance storage full or corrupted, restore from latest automated snapshot via PITR.
* **Data Loss Risk:** Zero under Multi-AZ synchronous replication; $< 5\text{ minutes}$ under catastrophic snapshot restore.
* **User-Visible Behavior:** Maintenance banner or structured error: `{"status":"DEGRADED","database":"UNAVAILABLE"}` with zero leaked passwords.
* **Alert:** `RDS_Database_Down_Critical` PagerDuty alert fires immediately.

---

### Scenario 4: Redis Cluster Unavailable
* **Detection:** `/health/ready` probe reports `redis: down`. Queue workers throw `RedisException: Connection refused`.
* **Impact:** Asynchronous jobs cannot be pushed or popped. Distributed locks cannot be acquired. Response caching disabled.
* **Automatic Recovery:** ElastiCache Multi-AZ promotes replica to primary in $< 40\text{ seconds}$.
* **Manual Recovery:** If cluster destroyed, spin up new cluster via Terraform. Run `publishing:dispatch-scheduled` and `publishing:reap-stalled` to re-queue work from PostgreSQL.
* **Data Loss Risk:** Zero business records lost. Maximum queue loss $< 1\text{ second}$ (AOF synced every second).
* **User-Visible Behavior:** Publishing requests queue in-memory or return temporary HTTP 503.
* **Alert:** `Redis_Cluster_Connection_Failure` PagerDuty alert.

---

### Scenario 5: Object Storage (S3 / R2) Unavailable
* **Detection:** `/health/ready` reports `storage: down`. Pre-signed URL requests throw S3 client connection exceptions.
* **Impact:** New video uploads fail. Media workers cannot fetch source files or save thumbnails.
* **Automatic Recovery:** AWS S3 internal SLA ($99.99\%$). Workers back off and retry via queue retry policy.
* **Manual Recovery:** Switch storage disk to secondary cross-region bucket if regional AWS outage occurs.
* **Data Loss Risk:** Zero for existing stored objects ($99.999999999\%$ durability).
* **User-Visible Behavior:** "Upload service temporarily unavailable. Please retry in a few moments."
* **Alert:** `S3_API_Error_Spike` CloudWatch alarm.

---

### Scenario 6: Media Worker Crashes During FFmpeg Transcoding
* **Detection:** Worker process exits abruptly (`SIGKILL`, host eviction, or out-of-memory). Redis lock `process_media_lock_{id}` remains active until TTL (360s).
* **Impact:** The specific video processing job halts. Source file and partial transcode remain on container scratch disk until container terminates.
* **Automatic Recovery:** Container is destroyed and replaced by ECS. Lock expires after 360s. Job is re-attempted by queue (max 3 tries).
* **Manual Recovery:** If repeatable OOM crash, adjust Fargate task memory from 4 GB to 8 GB.
* **Data Loss Risk:** Zero. Original video remains safe on S3.
* **User-Visible Behavior:** Media asset shows "Processing" status longer than usual, then recovers.
* **Alert:** `ECS_Task_Stopped_NonZero_Exit` CloudWatch alarm.

---

### Scenario 7: Publishing Worker Crashes
* **Detection:** Job in `UPLOADING` status stops updating `last_heartbeat_at`. Redis lock `publish_lock_job_{id}` expires after 660s.
* **Impact:** Destination publish job stalled.
* **Automatic Recovery:** `publishing:reap-stalled` runs every 10 minutes, discovers the orphaned job ($>15\text{ min}$ inactive), and re-queues it for retry.
* **Manual Recovery:** None required.
* **Data Loss Risk:** Zero.
* **User-Visible Behavior:** Publishing status remains "Publishing...", then completes automatically upon self-healing retry.
* **Alert:** `Publishing_Stalled_Job_Count` $> 0$.

---

### Scenario 8: Scheduler Container Crashes
* **Detection:** CloudWatch log silence: no `Schedule run completed` log line emitted for $> 3\text{ minutes}$.
* **Impact:** Scheduled batches are not dispatched at their exact minute; stalled job sweeps pause.
* **Automatic Recovery:** ECS service restarts the scheduler container within 30 seconds. Upon boot, `publishing:dispatch-scheduled` automatically scans for any past-due batches (`scheduled_for <= now()`) and dispatches them immediately.
* **Manual Recovery:** None required.
* **Data Loss Risk:** Zero.
* **User-Visible Behavior:** Scheduled posts may dispatch 1–2 minutes later than exact scheduled time.
* **Alert:** `Scheduler_Daemon_Heartbeat_Missing` alert.

---

### Scenario 9: Social Provider API Unavailable (e.g. YouTube 503)
* **Detection:** Publishing worker catches HTTP 5xx or `GuzzleHttp\Exception\ServerException`.
* **Impact:** Destination job cannot finish currently.
* **Automatic Recovery:** Normalized to `PROVIDER_UNAVAILABLE`. `PublishingRetryPolicy` calculates exponential backoff with jitter and sets `status = RETRYING` with `retry_at` timestamp. Job retries up to 4 times.
* **Manual Recovery:** If provider outage lasts $> 24\text{ hours}$, manual bulk re-dispatch via admin dashboard.
* **Data Loss Risk:** Zero.
* **User-Visible Behavior:** Destination status displays "Retrying: YouTube server unavailable (attempt 2 of 4)".
* **Alert:** `Provider_5xx_Error_Spike` CloudWatch alarm.

---

### Scenario 10: Social Platform OAuth Access Token Expires
* **Detection:** Platform API returns HTTP 401 Unauthorized or error category `AUTHENTICATION`.
* **Impact:** Immediate API call rejected.
* **Automatic Recovery:** Worker acquires `token_refresh_lock_{account_id}`, invokes `TokenRefreshService`, obtains fresh access token via refresh token, and immediately retries the API call.
* **Manual Recovery:** If refresh token itself was revoked by user in Google/TikTok/Meta account settings, status transitions to `AUTH_REQUIRED` and user is prompted to reconnect account.
* **Data Loss Risk:** Zero.
* **User-Visible Behavior:** Transparent to user if refresh succeeds; if revoked, UI displays "Account disconnected — please re-authenticate".
* **Alert:** `OAuth_Token_Revocation_Notice` logged to audit events.

---

### Scenario 11: Social Provider Rate Limit Hit (HTTP 429)
* **Detection:** Provider API returns HTTP 429 Too Many Requests.
* **Impact:** Job cannot be published immediately.
* **Automatic Recovery:** Classified as `RATE_LIMITED`. Captures platform `Retry-After` header (or applies default 60s base backoff + 15s jitter). Transitions job to `RETRYING`. Max 5 attempts.
* **Manual Recovery:** None required unless global workspace quota exceeded.
* **Data Loss Risk:** Zero.
* **User-Visible Behavior:** "Rate limit reached. Retrying automatically in 60s."
* **Alert:** `Provider_Rate_Limit_Warning` metric incremented.

---

### Scenario 12: Deployment Pipeline Failure
* **Detection:** GitHub Actions `deploy-production.yml` fails during container build, Trivy vulnerability scan, or health check.
* **Impact:** New release halted before reaching production.
* **Automatic Recovery:** CI pipeline exits with code 1. Existing running ECS containers remain untouched. Traffic continues uninterrupted on previous release.
* **Manual Recovery:** Fix build/lint/test failure in PR branch and re-run.
* **Data Loss Risk:** Zero.
* **User-Visible Behavior:** Zero downtime; users remain on existing stable version.
* **Alert:** GitHub Actions build failure notification to Slack/email.

---

### Scenario 13: Database Migration Failure During Release
* **Detection:** `php artisan migrate --force` throws SQL syntax error or lock timeout.
* **Impact:** Deployment workflow immediately halts before rolling container update.
* **Automatic Recovery:** Migrations execute inside a transaction where supported; partial migration fails cleanly.
* **Manual Recovery:** If non-transactional DDL failed, run `php artisan migrate:rollback --step=1` or restore pre-deployment RDS snapshot created immediately prior to migration step.
* **Data Loss Risk:** Zero (Guaranteed by pre-deployment snapshot).
* **User-Visible Behavior:** Zero downtime; active containers remain on old version.
* **Alert:** `Production_Migration_Failure_Critical` alert.

---

### Scenario 14: Corrupted Video Uploaded by User
* **Detection:** `ProcessMediaAssetJob` runs `ffprobe` on downloaded file; returns exit code 1 or zero video streams.
* **Impact:** Video asset cannot be processed.
* **Automatic Recovery:** Job catches `InvalidArgumentException`, transitions asset to `FAILED`, sets `error_message = "Failed to parse media container. The file may be corrupt or an unsupported format."`, records `MEDIA_PROCESSING_FAILED` audit event.
* **Manual Recovery:** None required (system correctly rejects invalid input).
* **Data Loss Risk:** Zero.
* **User-Visible Behavior:** Clear user-facing error in media library: "Corrupted video file. Please re-encode and re-upload."
* **Alert:** `Corrupt_Media_Upload_Notice` (Info level).

---

### Scenario 15: Network Interruption Between Media Worker & S3
* **Detection:** S3 download stream drops mid-transfer; `stream_copy_to_stream` returns fewer bytes than expected file size.
* **Impact:** Download incomplete.
* **Automatic Recovery:** Job verifies local file size against expected size; throws exception; scratch directory cleaned up in `finally`; job re-queued by Redis.
* **Manual Recovery:** None required.
* **Data Loss Risk:** Zero.
* **User-Visible Behavior:** Temporary processing delay.
* **Alert:** `S3_Transfer_Timeout` metric filter.

---

### Scenario 16: Worker Killed During In-Flight Upload
* **Detection:** Video upload to YouTube/TikTok was 60% complete when container terminated.
* **Impact:** Upload connection severed.
* **Automatic Recovery:** Stalled job recovery re-queues the job. The YouTube adapter queries the resumable upload URL for the last byte acknowledged by Google, and **resumes upload from byte 60,000,001 without re-uploading from scratch**.
* **Manual Recovery:** None required.
* **Data Loss Risk:** Zero.
* **User-Visible Behavior:** Post publishes successfully without double-upload.
* **Alert:** `Resumable_Upload_Resumed` log event.

---

### Scenario 17: Duplicate Publishing Request Submitted by User
* **Detection:** User double-clicks "Publish Now" button or script issues duplicate API requests.
* **Impact:** Potential double-post risk.
* **Automatic Recovery:**
  1. API validates unique `idempotency_key` on batch creation.
  2. State machine enforces that jobs already in `QUEUED`, `UPLOADING`, or `PUBLISHED` cannot be re-dispatched.
  3. Worker acquires Redis lock `publish_lock_job_{id}`; second worker skips execution.
* **Manual Recovery:** None required.
* **Data Loss Risk:** Zero.
* **User-Visible Behavior:** Second request returns existing batch details; exactly 1 post published to platform.
* **Alert:** None (Normal idempotent behavior).

---

### Scenario 18: Total Cloud Region Outage (`us-east-1` Outage)
* **Detection:** AWS Health Dashboard reports regional degradation; Route 53 health check alarms fire.
* **Impact:** Total primary datacenter unavailability.
* **Automatic Recovery:** None automatic across regions (Manual DR execution required to prevent split-brain).
* **Manual Recovery:** Execute `docs/infrastructure/DISASTER_RECOVERY_PLAN.md` Section 2.3: Promote `us-west-2` RDS replica, point DNS to secondary ALB, boot secondary ECS cluster.
* **Data Loss Risk:** $< 5\text{ minutes}$ of data from WAL replication lag.
* **User-Visible Behavior:** 15–30 minute maintenance window until secondary region is active.
* **Alert:** `Regional_Outage_Disaster_Declared` PagerDuty escalation.
