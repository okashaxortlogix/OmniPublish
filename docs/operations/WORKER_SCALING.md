# Worker Scaling & Queue Concurrency Specification

**Queues:**
- `publishing` (High Priority, External Network Bound)
- `media` (Medium Priority, CPU & Transcode Heavy)
- `default` (General Purpose Events & Audit)

---

## 1. Queue Isolation Architecture

Media transcoding (FFmpeg rendering, thumbnail extraction, bitrate conversion) consumes substantial CPU and disk I/O. If publishing jobs were queued together with media jobs on a single queue, bulk multi-platform publishing operations could starve media ingestion or cause worker thrashing.

### Priority Configuration:

```bash
php artisan queue:work --queue=publishing,media,default --timeout=660 --sleep=3 --tries=3
```

- **`publishing`**: Handled first. High concurrency, I/O wait on external provider APIs.
- **`media`**: Handled second. Dedicated CPU workers recommended.
- **`default`**: Handled last. Async events and housekeeping.

---

## 2. Environment Sizing Guidelines

| Metric | Development | Staging | Production |
| :--- | :---: | :---: | :---: |
| **Publishing Workers** | 1 | 2 - 4 | 8 - 16 |
| **Media Workers** | 1 | 2 | 4 - 8 |
| **Default Workers** | 1 | 1 | 2 |
| **Job Timeout** | 600s | 600s | 600s |
| **Retry After** | 660s | 660s | 660s |
| **Redis Cache DB** | Shared | Dedicated Redis 7+ | Redis Cluster with AOF |
| **DB Concurrency Pool**| 10 | 50 | 200+ |

---

## 3. Measured Load Test Benchmarks

Measured under controlled synthetic load testing on the actual implementation:

| Test Scale | Execution Duration | Latency / Job | Peak Memory Delta | Success Rate | Lock Contention |
| :---: | :---: | :---: | :---: | :---: | :---: |
| **10 Concurrent Jobs** | 426.62 ms | ~42.7 ms | 2.0 MB | 100% (10/10) | 0 |
| **50 Concurrent Jobs** | 1,465.43 ms | ~29.3 ms | 4.0 MB | 100% (50/50) | 0 |
| **100 Concurrent Jobs** | 3,601.16 ms | ~36.0 ms | 4.0 MB | 100% (100/100) | 0 |

---

## 4. Graceful Shutdown & Supervisor Setup

Production deployments must manage worker processes via `supervisord` or Kubernetes Deployments, sending `SIGTERM` signals for graceful drain:

```ini
[program:omnipublish-publishing-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/backend/artisan queue:work redis --queue=publishing --sleep=3 --tries=3 --timeout=660 --max-time=3600
autostart=true
autorestart=true
user=www-data
numprocs=8
redirect_stderr=true
stdout_logfile=/var/www/backend/storage/logs/publishing_worker.log
stopwaitsecs=670
```
