# OmniPublish — Production Operations Runbook

**Document Version:** 1.0 (Phase 3E Certified)  
**System:** OmniPublish Unified Multi-Platform Social Publishing SaaS  
**Primary Audience:** Site Reliability Engineers, DevOps Engineers, On-Call Engineers  

---

## 1. System Architecture & Component Overview

OmniPublish runs as a distributed multi-tenant service composed of:
1. **Frontend App (`omnipublish-frontend`):** Next.js 15.1 React SSR/SSG application.
2. **Backend API (`omnipublish-api`):** Laravel 12 REST API providing authentication, tenant isolation, media upload signing, and publishing coordination.
3. **Queue Workers:**
   - **Publishing Workers (`queue:publishing`):** Dedicated worker pool for dispatching posts to YouTube, TikTok, and Instagram, processing platform responses, and handling rate limits.
   - **Media Workers (`queue:media`):** Dedicated compute-intensive worker pool executing FFmpeg video normalization, audio transcode, and thumbnail extraction.
   - **Default Workers (`queue:default`):** Notifications, analytics rollups, and system housekeeping.
4. **Distributed Scheduler:** Cron-driven scheduler executing:
   - `publishing:dispatch-scheduled` (every minute)
   - `publishing:reap-stalled` (every 5 minutes)
   - `publishing:reconcile` (every 15 minutes)
   - `tokens:refresh-expiring` (every 30 minutes)
   - `media:cleanup-stale-uploads` (daily)
5. **Data Stores:**
   - **PostgreSQL 16+:** Primary relational database with ACID guarantees and tenant isolation.
   - **Redis 7+:** Distributed locks, queue storage, and cache.
   - **S3 / Cloudflare R2:** S3-compatible private object storage for master media and derivatives.

---

## 2. Routine Operational Procedures

### 2.1 Daily Health Verification
Check the three primary health endpoints:
```bash
# Liveness Probe (process health)
curl -s -i https://api.omnipublish.com/health/live

# Readiness Probe (PostgreSQL, Redis, Storage)
curl -s -i https://api.omnipublish.com/health/ready
```
Expected output: HTTP 200 with JSON:
```json
{
  "status": "READY",
  "checks": {
    "database": "OK",
    "redis": "OK",
    "storage": "OK"
  },
  "timestamp": "2026-10-04T00:00:00Z"
}
```

### 2.2 Routine Maintenance & Deployments
To execute standard application deployments without service interruption:
1. **Pre-Deployment Check:** Verify CI pipeline passed tests and Docker images are pushed.
2. **Database Migrations:** Run migrations before updating worker containers:
   ```bash
   php artisan migrate --force
   ```
3. **Worker Restart (Graceful Reload):**
   ```bash
   php artisan queue:restart
   ```
   Workers will gracefully finish their current active job and restart with fresh code.
4. **Cache Warmup:**
   ```bash
   php artisan config:cache
   php artisan route:cache
   ```

---

## 3. Worker Scaling Guidelines

| Queue | Initial Concurrency | Scale-Up Trigger | Target Memory / Pod |
| :--- | :--- | :--- | :--- |
| `publishing` | 4 replicas | Queue backlog > 50 jobs OR wait time > 30s | 512 MB |
| `media` | 2 replicas | Queue backlog > 10 jobs OR video queue latency > 60s | 2048 MB (CPU bound) |
| `default` | 2 replicas | Backlog > 100 jobs | 256 MB |

---

## 4. Key Metrics & Alert Thresholds

- **5xx API Error Rate:** Alert when > 1% of requests fail over 5 minutes.
- **Queue Latency (`publishing`):** Alert when jobs sit in queue > 2 minutes.
- **Queue Latency (`media`):** Alert when jobs sit in queue > 5 minutes.
- **Dead Letter Queue (Failed Jobs):** Alert on any failed job in `failed_jobs` table.
- **OAuth Token Refresh Failures:** Alert when `auth_required` state transitions occur.
- **Redis Memory Utilization:** Alert when memory exceeds 80% of max allocated.
