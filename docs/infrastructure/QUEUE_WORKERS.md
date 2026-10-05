# Queue Worker Architecture & Production Operations Guide

**OmniPublish Background Worker Infrastructure**  
**Version:** 1.0 (Phase 1 Migration)  
**Supported Runtimes:** Docker / Containerized Pods / Linux Systemd / Supervisor

---

## 1. Worker Topology & Sizing Specifications

OmniPublish asynchronous processing is split into dedicated worker pools to prevent long-running external video uploads from starving transactional messaging and batch aggregations.

### 1.1 Pool Definitions

| Pool Name | Queues Serviced (Priority Order) | Concurrency | Timeout | Max Memory | Max Tries |
|---|---|---|---|---|---|
| **Publishing Pool** | `publishing` | 4–8 processes | 600s | 512 MB | 3 |
| **Media Processing Pool** | `media` | 2–4 processes | 300s | 1024 MB | 2 |
| **Default / Sync Pool** | `default,analytics` | 2–4 processes | 120s | 256 MB | 3 |

---

## 2. Worker Command Flags & Rationales

```bash
php artisan queue:work redis \
    --queue=publishing,media,default,analytics \
    --timeout=600 \
    --tries=3 \
    --backoff=10 \
    --memory=512 \
    --sleep=3 \
    --rest=0 \
    --max-jobs=1000 \
    --max-time=3600
```

### Parameter Rationale:
- `--timeout=600`: Large 4K video uploads to external platforms (YouTube, etc.) take several minutes depending on payload size. Workers must not be terminated prematurely.
- `--tries=3`: Ensures transient network timeouts or momentary 503 rate-limits are retried up to three times before permanent failure.
- `--backoff=10`: Initial 10-second wait before queue retries to allow remote rate limits to cool off.
- `--memory=512`: Accommodates video buffering and token decryption without tripping PHP memory limits.
- `--max-jobs=1000` & `--max-time=3600`: Periodically recycles worker processes every 1,000 jobs or 1 hour to prevent subtle PHP memory leaks.

---

## 3. Production Process Manager: Supervisor Configuration

For standard Linux virtual machines or EC2 instances, use `supervisord` to manage and monitor worker processes:

```ini
[program:omnipublish-worker-publishing]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/omnipublish/backend/artisan queue:work redis --queue=publishing --timeout=600 --tries=3 --backoff=10 --memory=512 --sleep=3 --max-jobs=1000
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/supervisor/omnipublish-worker-publishing.log
stopwaitsecs=660

[program:omnipublish-worker-default]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/omnipublish/backend/artisan queue:work redis --queue=media,default,analytics --timeout=300 --tries=3 --backoff=5 --memory=512 --sleep=3 --max-jobs=1000
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/supervisor/omnipublish-worker-default.log
stopwaitsecs=360
```

> **CRITICAL:** `stopwaitsecs` must be greater than the `--timeout` (e.g. 660s for a 600s timeout). This guarantees that graceful shutdowns give in-flight video uploads adequate time to finish before SIGKILL is dispatched.

---

## 4. Graceful Shutdown & Deployments

During deployments, issue the Laravel queue restart signal:
```bash
php artisan queue:restart
```
This instructs all workers to finish their current job before gracefully terminating. Supervisor or Docker container orchestrators immediately respawn a fresh process running the newly deployed code.

---

## 5. Failed Job Management & Dead-Letter Queues

When a job exhausts all configured retry attempts:
1. It is logged in the `failed_jobs` table.
2. The `PublishJob` record transitions to `FAILED` or `AUTH_REQUIRED`.
3. The parent `PublishBatch` evaluates aggregate status (`PARTIAL_SUCCESS` or `FAILED`).

### Managing Failed Jobs:
```bash
# View list of failed jobs
php artisan queue:failed

# Retry a specific failed job by UUID
php artisan queue:retry <uuid>

# Retry all failed jobs
php artisan queue:retry all

# Purge resolved or unrecoverable failed jobs
php artisan queue:flush
```
