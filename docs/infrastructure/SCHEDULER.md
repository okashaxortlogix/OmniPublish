# Centralized Production Scheduler Architecture

**OmniPublish Scheduled Automation & Cron Topology**  
**Version:** 1.0 (Phase 1 Migration)  
**Entry Point:** `backend/routes/console.php`

---

## 1. Overview & Single-Entry-Point Principle

All background maintenance, temporal publishing, and lifecycle cleanups are unified under the Laravel Console Scheduler. Rather than scattering individual crontabs across operating system layers, a single cron entry point invokes the Laravel scheduler every minute:

```bash
* * * * * cd /var/www/omnipublish/backend && php artisan schedule:run >> /dev/null 2>&1
```

---

## 2. Scheduled Operations Matrix

| Command | Cadence | Concurrency Guard | Purpose & Failure Recovery |
|---|---|---|---|
| `publishing:dispatch-scheduled` | Every Minute (`* * * * *`) | `withoutOverlapping()` | Scans for queued batches whose `scheduled_for` is due. Dispatches child `PublishDestinationJob`s atomically. |
| `publishing:reap-stalled` | Every 10 Minutes (`*/10 * * * *`) | `withoutOverlapping()` | Rescues publishing jobs stalled in `UPLOADING`/`PROCESSING` for >15 min (e.g. from hard worker crash). Retries or marks failed. |
| `tokens:refresh-expiring` | Hourly (`0 * * * *`) | `withoutOverlapping()` | Preemptively refreshes OAuth tokens expiring in <24 hours so publishing jobs never fail on expired credentials. |
| `media:cleanup-stale-uploads` | Hourly (`0 * * * *`) | `withoutOverlapping()` | Sweeps `media_assets` stuck in `UPLOAD_AUTHORIZED` >2 hours without client confirmation and marks them `FAILED`. |

---

## 3. Reliability & Guard Specifications

### 3.1 Overlap Prevention (`withoutOverlapping()`)
Every critical scheduled task employs atomic cache locks. If a task is still executing from the previous iteration (for example, refreshing 50 tokens), subsequent invocations will exit immediately without spawning duplicate operations.

### 3.2 Idempotency
Each scheduled command can be executed repeatedly without side effects:
- `publishing:dispatch-scheduled`: Transitions batch to `RUNNING` and only dispatches jobs still in `QUEUED` state.
- `tokens:refresh-expiring`: Delegates to `TokenRefreshService::refreshIfExpired`, which checks actual expiry timestamps before calling OAuth servers.
- `media:cleanup-stale-uploads`: Only mutates records exceeding the 2-hour window.

### 3.3 Timezone Awareness
The scheduler respects application timezone settings defined in `config/app.php` (`UTC` by default). All temporal comparisons (`scheduled_for <= now()`) execute in standard UTC epoch representations.

---

## 4. Production Runner Options

### 4.1 Systemd Timer (Modern Linux)
`/etc/systemd/system/omnipublish-scheduler.service`:
```ini
[Unit]
Description=OmniPublish Artisan Scheduler
After=network.target

[Service]
Type=oneshot
User=www-data
WorkingDirectory=/var/www/omnipublish/backend
ExecStart=/usr/bin/php artisan schedule:run
```

`/etc/systemd/system/omnipublish-scheduler.timer`:
```ini
[Unit]
Description=Run OmniPublish Scheduler every minute

[Timer]
OnCalendar=*:0/1
AccuracySec=1s
Persistent=true

[Install]
WantedBy=timers.target
```

### 4.2 Docker / Container Scheduler Service
In containerized environments, run a dedicated lightweight container executing:
```bash
while [ true ]; do
  php artisan schedule:run --verbose --no-interaction &
  sleep 60
done
```
Or use the built-in Laravel artisan worker:
```bash
php artisan schedule:work
```
