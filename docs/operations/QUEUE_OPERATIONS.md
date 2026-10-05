# OmniPublish — Queue Operations & Backlog Management

**Document Version:** 1.0 (Phase 3E Certified)  
**System:** OmniPublish Unified Multi-Platform Social Publishing SaaS  

---

## 1. Queue Architecture & Monitoring

OmniPublish relies on Redis-backed queues segregated by workload SLA:
- `publishing`
- `media`
- `default`

### 1.1 Live Queue Health Check
Run the queue monitor command:
```bash
php artisan queue:monitor redis:publishing,redis:media,redis:default --max=100
```
Alert when:
- `redis:publishing` depth > 50 jobs
- `redis:media` depth > 10 jobs

---

## 2. Failed Job & Dead Letter Queue (DLQ) Procedures

When a job exhausts all permitted retries, Laravel pushes it to the `failed_jobs` table.

### 2.1 Viewing Failed Jobs
```bash
php artisan queue:failed
```

### 2.2 Inspecting a Specific Failure
```bash
php artisan queue:failed-job <job-uuid>
```

### 2.3 Retrying Failed Jobs
- **Retry single publishing job:**
  ```bash
  php artisan queue:retry <job-uuid>
  ```
- **Retry all failed publishing jobs:**
  ```bash
  php artisan queue:retry --queue=publishing
  ```

### 2.4 Purging Failed Jobs
```bash
# Delete single job
php artisan queue:forget <job-uuid>

# Flush all failed records
php artisan queue:flush
```

---

## 3. Automated Reconciliation & Self-Healing Engines

OmniPublish includes built-in commands scheduled via Laravel's cron mechanism:

### 3.1 Stalled Job Reaper
```bash
php artisan publishing:reap-stalled
```
- Scans `publish_jobs` table for jobs marked `processing` or `publishing` where `updated_at` exceeds the stall threshold (15 minutes).
- Automatically resets retry counters or transitions unrecoverable jobs to `failed` with clear error diagnostics.

### 3.2 Post-Processing & Remote Reconciliation
```bash
php artisan publishing:reconcile
```
- Queries YouTube, TikTok, and Meta Graph APIs for asynchronous transcoding completion on posts uploaded earlier.
- Transitions jobs from `processing` to `published` when the remote platform finishes video ingestion.

### 3.3 Expiring Token Auto-Refresh
```bash
php artisan tokens:refresh-expiring
```
- Scans `social_accounts` with tokens expiring within 60 minutes.
- Uses distributed concurrency locks to refresh OAuth tokens before publishing jobs encounter authentication errors.
