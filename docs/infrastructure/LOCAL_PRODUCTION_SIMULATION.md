# Local Production Infrastructure Simulation Guide

**OmniPublish Full-Stack Infrastructure Simulation**  
**Version:** 1.0 (Phase 1 Migration)  
**Topology:** Next.js (Port 3000) -> Laravel API (Port 8001) -> PostgreSQL (Port 5432) -> Redis (Port 6379) -> MinIO S3 (Port 9000/9001)

---

## 1. Architecture Overview

This simulation provisions a local environment that mirrors the exact network topology and asynchronous dataflow of OmniPublish in production:

```text
[ Browser / Next.js Client ] (Port 3000)
              │
              ├── 1. Request Upload Authorization
              ▼
[ Laravel API Engine ] (Port 8001)
              │
              ├── 2. Issues S3 Presigned URL & creates MediaAsset record
              │
[ Browser / Next.js Client ]
              │
              ├── 3. Streams Video Payload Directly to Object Storage (PUT)
              ▼
[ MinIO / S3 Object Storage ] (Port 9000)
              ▲
              │ 4. Direct Upload Completed
              │
[ Browser / Next.js Client ]
              │
              ├── 5. POST /api/v1/media/confirm-upload
              ▼
[ Laravel API Engine ]
              │
              ├── 6. Verifies S3 Object Size, MIME & Tenant Scoping
              ├── 7. Transitions MediaAsset -> READY
              │
[ Browser / Next.js Client ]
              │
              ├── 8. User Creates Batch in Composer -> POST /api/v1/publish/batches
              ▼
[ Laravel API Engine ]
              │
              ├── 9. Persists Batch & Destination Jobs (PostgreSQL)
              ├── 10. Enqueues PublishDestinationJob -> Redis
              ▼
[ Redis 7 Queue Engine ] (Port 6379)
              ▲
              │ 11. Atomic Pop (Priority: publishing queue)
              ▼
[ Production Queue Worker ]
              │
              ├── 12. Acquires Distributed Lock (Redis Cache Lock, TTL 660s)
              ├── 13. Preflight Validations & Token Decryption
              ├── 14. Publishes to Target Platform
              ├── 15. Commits Remote Post ID & Transitions State -> PUBLISHED
              └── 16. Updates Batch Aggregate State (PostgreSQL)
```

---

## 2. Step-by-Step Simulation Execution

### Step 1: Boot Container Infrastructure
In the project root:
```bash
docker compose up -d
```
Verify container health:
```bash
docker compose ps
```
All three services (`omnipublish-postgres`, `omnipublish-redis`, `omnipublish-minio`) should report `healthy` or `running`.

### Step 2: Configure Environment for Cloud Simulation
In `backend/.env`:
```ini
DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=omnipublish
DB_USERNAME=postgres
DB_PASSWORD=postgres

QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379
REDIS_QUEUE_RETRY_AFTER=660

CACHE_STORE=redis

FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=minio
AWS_SECRET_ACCESS_KEY=minio-password
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=omnipublish-media
AWS_ENDPOINT=http://127.0.0.1:9000
AWS_USE_PATH_STYLE_ENDPOINT=true
```

### Step 3: Run Database Migrations
```bash
php artisan migrate --force
```

### Step 4: Launch Dedicated Queue Workers & Scheduler
In Terminal 1 (Publishing Queue Worker):
```bash
php artisan queue:work redis --queue=publishing,media,default --timeout=600 --tries=3
```

In Terminal 2 (Artisan Scheduler Runner):
```bash
php artisan schedule:work
```

### Step 5: Execute End-to-End User Flow
1. Navigate to `http://localhost:3000/upload`.
2. Select a video file (`.mp4` / `.mov`).
3. Browser automatically requests upload authorization from `POST /api/v1/media/upload-authorization`.
4. Browser streams bytes directly to `http://127.0.0.1:9000/omnipublish-media/workspaces/...`.
5. Upon stream completion, browser dispatches `POST /api/v1/media/confirm-upload`.
6. Backend HEAD-checks MinIO storage, verifies byte count, and sets asset status to `READY`.
7. Click "Next, Add Details" to launch the Composer.
8. Select platforms and click "Publish Now".
9. Worker claims job from Redis, publishes destination atomically, and transitions state to `PUBLISHED`.
10. Dashboard and calendar display published status with remote platform link.
