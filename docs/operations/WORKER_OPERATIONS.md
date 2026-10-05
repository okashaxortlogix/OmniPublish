# OmniPublish — Worker Operations & Lifecycle Manual

**Document Version:** 1.0 (Phase 3E Certified)  
**System:** OmniPublish Unified Multi-Platform Social Publishing SaaS  

---

## 1. Worker Fleet Architecture

OmniPublish isolates asynchronous workloads into dedicated worker pools to prevent high-latency operations (like video transcoding) from blocking fast operations (like social token refresh or post metadata dispatch).

### Worker Roles & Specifications

| Worker Target | Queue Monitored | Tries | Job Timeout | Memory Limit | Target Workload |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `publishing-worker` | `publishing` | 5 | 300s (5m) | 512 MB | YouTube, TikTok, Instagram uploads, API calls, status checks |
| `media-worker` | `media` | 3 | 600s (10m) | 2048 MB | FFmpeg probe, normalization, transcode, thumbnail extraction |
| `default-worker` | `default` | 3 | 120s (2m) | 256 MB | Notifications, analytics recalculations, audits |

---

## 2. Supervisor Configuration (Virtual Machines / Bare Metal)

For deployments running on Linux VMs (Ubuntu/Debian) managed by Supervisor:

`/etc/supervisor/conf.d/omnipublish-workers.conf`:
```ini
[program:omnipublish-publishing-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/artisan queue:work redis --queue=publishing --tries=5 --timeout=300 --sleep=3 --memory=512
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=4
redirect_stderr=true
stdout_logfile=/var/log/supervisor/omnipublish-publishing.log
stopwaitsecs=360

[program:omnipublish-media-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/artisan queue:work redis --queue=media --tries=3 --timeout=600 --sleep=3 --memory=2048
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/supervisor/omnipublish-media.log
stopwaitsecs=660

[program:omnipublish-default-worker]
process_name=%(program_name)s_%(process_num)02d
command=php /var/www/html/artisan queue:work redis --queue=default --tries=3 --timeout=120 --sleep=3 --memory=256
autostart=true
autorestart=true
stopasgroup=true
killasgroup=true
user=www-data
numprocs=2
redirect_stderr=true
stdout_logfile=/var/log/supervisor/omnipublish-default.log
stopwaitsecs=150
```

---

## 3. Signal Handling & Graceful Shutdown

1. **SIGTERM / SIGQUIT:** Laravel's `queue:work` catches SIGTERM and finishes processing the current job before exiting. Supervisor or Kubernetes must allow sufficient grace period (`stopwaitsecs` / `terminationGracePeriodSeconds`) greater than the job timeout:
   - Publishing worker: 360 seconds grace period
   - Media worker: 660 seconds grace period
2. **Reload Code without Dropping Jobs:**
   ```bash
   php artisan queue:restart
   ```
   This broadcasts a timestamp into the cache; workers gracefully terminate after completing their active job and Supervisor or container orchestrators restart them with updated code.
