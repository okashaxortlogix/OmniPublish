# OmniPublish Phase 1 Operations & Deployment Runbook

**Document ID:** RUNBOOK-PHASE-1  
**Project:** OmniPublish  
**Author:** Antigravity Engineering  
**Version:** 1.0 (Phase 1 Production Infrastructure)

---

## 1. System Topology & Service Dependency Map

OmniPublish Phase 1 architecture transitions the platform from a single-node development setup to a decoupled, multi-tenant cloud-native system:

```text
[ Client Traffic ]
       │
       ├── Web Application (Next.js 15 App Router on Port 3000)
       ├── REST API Engine (Laravel 12 on Port 8001)
       └── Direct Media Streams (S3 / Cloudflare R2 / MinIO on Port 9000)
              │
       [ Data & Messaging Tier ]
              ├── Relational Persistence (PostgreSQL 16 on Port 5432)
              ├── Cache & Distributed Locking (Redis 7 DB 1 & DB 0)
              └── Background Execution (Redis 7 DB 0 with Priority Queues)
                     │
              [ Asynchronous Workers & Schedulers ]
                     ├── High-Priority Publishing Pool (timeout=600s, tries=3)
                     ├── Media Verification Pool (timeout=300s, tries=2)
                     └── Centralized Scheduler (every minute via routes/console.php)
```

---

## 2. Bootstrapping the Infrastructure

### 2.1 Starting Infrastructure via Docker Compose
From the project root:
```bash
docker compose up -d
```

Verify services:
```bash
docker compose ps
```
Ensure `omnipublish-postgres`, `omnipublish-redis`, and `omnipublish-minio` are all healthy.

### 2.2 S3 Bucket Initialization (MinIO Local Testing)
When using local MinIO, verify the default bucket exists:
```bash
# Using AWS CLI with local MinIO endpoint
aws --endpoint-url http://127.0.0.1:9000 s3 mb s3://omnipublish-media
```

### 2.3 Running Database Migrations
```bash
cd backend
php artisan migrate --force
```

Verify migration status:
```bash
php artisan migrate:status
```

---

## 3. Running Services in Staging & Production

### 3.1 Web & API Services
- **Backend API:**
  ```bash
  cd backend
  php artisan serve --host=0.0.0.0 --port=8001
  ```
- **Frontend Next.js:**
  ```bash
  cd frontend
  npm run build
  npm run start -- -p 3000
  ```

### 3.2 Asynchronous Queue Workers
Launch dedicated worker processes separating publishing from standard jobs:
```bash
# High-priority publishing worker pool
php artisan queue:work redis --queue=publishing --timeout=600 --tries=3 --backoff=10 --memory=512

# Standard media, default, and analytics pool
php artisan queue:work redis --queue=media,default,analytics --timeout=300 --tries=3 --backoff=5 --memory=512
```

### 3.3 Centralized Scheduler
In container or VM crontab:
```bash
* * * * * cd /var/www/omnipublish/backend && php artisan schedule:run >> /dev/null 2>&1
```
Or for local development:
```bash
php artisan schedule:work
```

---

## 4. Operational Health & Monitoring

### 4.1 Health Check Endpoints
- **Liveness:** `GET /api/v1/health/live` -> Returns `200 OK` `{"status":"UP"}`.
- **Readiness:** `GET /api/v1/health/ready` -> Verifies database connectivity and returns `200 OK` `{"status":"READY"}`.

### 4.2 Queue Monitoring
```bash
# Check queue lengths
php artisan queue:monitor redis:publishing,redis:media,redis:default --max=100

# Inspect failed jobs
php artisan queue:failed

# Retry all failed jobs
php artisan queue:retry all
```

---

## 5. Incident Response & Troubleshooting

### Scenario A: Video Uploads Timing Out or Dropped
- **Symptom:** Worker terminates after 90 seconds while uploading 4K video to external platform.
- **Cause:** Queue `retry_after` smaller than worker `--timeout`.
- **Resolution:** Verify `REDIS_QUEUE_RETRY_AFTER=660` in `.env` and worker was invoked with `--timeout=600`.

### Scenario B: Storage Confirmation Failed (422)
- **Symptom:** Client reports "Storage object not found at target path".
- **Cause:** Client network dropped before completing direct stream to S3, or S3 CORS configuration rejected PUT request.
- **Resolution:** Check bucket CORS headers in `docs/infrastructure/OBJECT_STORAGE.md` and check object presence in bucket.

### Scenario C: PostgreSQL Connection Saturation
- **Symptom:** `SQLSTATE[08006] [7] FATAL: remaining connection slots are reserved`.
- **Cause:** Too many worker processes without connection pooling.
- **Resolution:** Deploy PgBouncer in transaction mode as documented in `docs/infrastructure/POSTGRESQL.md`.
