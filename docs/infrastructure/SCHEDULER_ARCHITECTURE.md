# OmniPublish — Centralized Scheduler Architecture

**Document Reference:** `docs/infrastructure/SCHEDULER_ARCHITECTURE.md`  
**System Version:** OmniPublish Phase 3GA  
**Target Container:** `docker/Dockerfile` (target: `scheduler`)  
**Standard:** Critical Honesty Principle (Zero Duplicate Scheduled Publishing Executions)

---

## 1. Centralized Single-Entry-Point Model

OmniPublish consolidates all recurring temporal operations, maintenance tasks, and automated self-healing sweeps into the **Laravel Console Scheduler** (`backend/routes/console.php`).

Rather than scattering multiple cron jobs across OS crontabs or disparate cloud functions, a single daemon process invokes the scheduler:

```text
[ ECS Fargate Scheduler Task ]
   │
   └── Executes: php artisan schedule:work
         │
         ├── Every Minute:       publishing:dispatch-scheduled  (Guarded by Redis Lock)
         ├── Every 5 Minutes:    publishing:reconcile           (Guarded by Redis Lock)
         ├── Every 10 Minutes:   publishing:reap-stalled        (Guarded by Redis Lock)
         ├── Every Hour:         tokens:refresh-expiring        (Guarded by Redis Lock)
         └── Every Hour:         media:cleanup-stale-uploads    (Guarded by Redis Lock)
```

---

## 2. Scheduled Commands Inventory

| Command Name | Cadence | Execution Guard | Operational Purpose |
|---|---|---|---|
| `publishing:dispatch-scheduled` | `* * * * *` (Every minute) | `withoutOverlapping()` | Scans `publish_batches` with `status = QUEUED` and `scheduled_for <= now()`. Atomically transitions batch to `RUNNING` and dispatches child `PublishDestinationJob`s to Redis `publishing` queue. |
| `publishing:reconcile` | `*/5 * * * *` (Every 5 min) | `withoutOverlapping()` | Inspects jobs in `PROCESSING` possessing a `remote_post_id` and due retries in `RETRYING`. Polls platform APIs for completion and updates terminal state. |
| `publishing:reap-stalled` | `*/10 * * * *` (Every 10 min) | `withoutOverlapping()` | Detects jobs stranded in `UPLOADING` or `PROCESSING` with `last_attempt_at <= now() - 15 minutes` (caused by worker container death). Safely re-queues them. |
| `tokens:refresh-expiring` | `0 * * * *` (Hourly) | `withoutOverlapping()` | Proactively refreshes OAuth tokens for connected social accounts that expire within 24 hours. |
| `media:cleanup-stale-uploads` | `0 * * * *` (Hourly) | `withoutOverlapping()` | Sweeps unconfirmed media assets stranded in `UPLOAD_AUTHORIZED` for $> 2\text{ hours}$ and marks them `FAILED`. |

---

## 3. Concurrency Protection & Overlap Prevention

In multi-container or clustered deployment topologies, running two scheduler instances simultaneously could lead to duplicate job dispatches. OmniPublish neutralizes this risk through three complementary safeguards:

1. **Native Laravel `withoutOverlapping()`:** Every command registration in `routes/console.php` specifies `->withoutOverlapping()`. Laravel creates an atomic Redis cache lock before command execution. If a previous run is still active (e.g. refreshing 100 tokens), subsequent executions exit immediately.
2. **Distributed Redis Dispatch Lock:** `publishing:dispatch-scheduled` explicitly wraps its batch scanning query in a distributed lock (`scheduler_dispatch_lock`, 50-second duration).
3. **Database-Level State Check:** Batches are transitioned from `QUEUED` to `RUNNING` in a single atomic SQL `UPDATE` statement before dispatching child jobs. Even if a race condition occurred, only jobs still in `QUEUED` state are eligible for dispatch.

---

## 4. Deployment Topology & Runner Model

### 4.1 Production Execution (AWS ECS Fargate)
* **Task Definition:** Dedicated lightweight task running the `scheduler` target from `docker/Dockerfile`.
* **Container Command:** `php artisan schedule:work`
* **Resource Sizing:** 0.25 vCPU, 512 MB RAM.
* **Restart Policy:** ECS Service automatically restarts the container if the process exits.

### 4.2 Alternative Serverless Runner (AWS EventBridge)
If a persistent container is not desired, an AWS EventBridge Rule can trigger an ephemeral Fargate task every 1 minute running `php artisan schedule:run`. However, the persistent daemon (`schedule:work`) is preferred for lower execution latency and lower container startup overhead.

---

## 5. Failure Handling & Observability

* **Command Failure Isolation:** An unhandled exception in one command (e.g. `tokens:refresh-expiring` encountering an external network failure) does not crash the scheduler daemon or interrupt other scheduled commands.
* **Failure Alerting:** Exceptions within scheduled commands log directly to standard error with `correlation_id` and trigger CloudWatch Log Metric Filters (`[level = ERROR]`).
* **Heartbeat Monitoring:** The scheduler outputs heartbeats to stdout every minute (`Schedule run completed`). A CloudWatch Alarm alerts on `ScheduleSilence` if no logs are received for $> 3\text{ minutes}$.
