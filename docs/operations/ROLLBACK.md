# OmniPublish — Production Deployment Rollback Runbook

**Document Version:** 1.0 (Phase 3E Certified)  
**System:** OmniPublish Unified Multi-Platform Social Publishing SaaS  

---

## 1. Rollback Decision Triggers

Initiate immediate deployment rollback under any of the following conditions:
- **Readiness Failure:** `/health/ready` probe fails continuously for > 2 minutes after deployment.
- **Elevated 5xx Error Rate:** 5xx response errors exceed 2% of total traffic.
- **Worker Crash Loop:** Workers repeatedly fail with unhandled exceptions.
- **Database Deadlocks / Migration Failure:** New migrations cause locking or unhandled query exceptions.

---

## 2. Fast Rollback Procedure

### Step 1: Drain & Pause In-Flight Publishing Dispatches
If the rollback involves critical defects in platform adapters:
```bash
# Gracefully signal workers to finish active jobs without accepting new ones
php artisan queue:restart
```

### Step 2: Roll Back Container Deployments

#### Option A: GitHub Actions Automated Redeployment (Recommended)
1. Identify the previous known-good Git commit SHA from the release audit log.
2. Trigger **Actions > OmniPublish Deploy Production**.
3. Supply the previous known-good Git SHA as `release_tag`.
4. Approve the manual gate to roll forward to the previous stable release.

#### Option B: Direct Kubernetes Rollback
```bash
# Rollback API pods
kubectl rollout undo deployment/omnipublish-api

# Rollback worker deployments
kubectl rollout undo deployment/omnipublish-publishing-worker
kubectl rollout undo deployment/omnipublish-media-worker
kubectl rollout undo deployment/omnipublish-scheduler

# Rollback frontend web app
kubectl rollout undo deployment/omnipublish-frontend
```

#### Option C: Docker Swarm / Compose
```bash
# Revert to previous image tag
export IMAGE_TAG=<previous-git-sha>
docker compose -f docker-compose.yml up -d --no-deps api publishing-worker media-worker scheduler frontend
```

### Step 3: Database Migration Rollback Strategy & Limitations
> **IMPORTANT SAFETY NOTICE:**
> Never assume a database rollback is automatically safe. In a multi-tenant production system:
> 1. If new columns or tables were added without destructive changes, **do NOT roll back database migrations**; older application code will simply ignore the new columns.
> 2. If a migration broke active write operations or created invalid state, evaluate whether down-migrations drop live data.
> 3. If live data loss would occur from `migrate:rollback`, restore the pre-deployment database snapshot instead:

```bash
# Inspect current migration status
php artisan migrate:status

# If clean rollback is safe:
php artisan migrate:rollback --step=1

# If snapshot restoration is required:
# pg_restore -h $DB_HOST -U $DB_USERNAME -d $DB_DATABASE --clean /backups/omnipublish_predeploy_latest.dump
```

### Step 4: Stalled & Orphaned Job Reconciliation
Following container rollback, run the stalled-job reaper and reconciler to unstick any jobs that were interrupted mid-flight during the deployment:
```bash
php artisan publishing:reap-stalled
php artisan publishing:reconcile
```

### Step 5: Post-Rollback Health & Smoke Test Verification
1. Verify `/health/ready` returns HTTP 200:
   ```bash
   bash infra/scripts/smoke_test.sh https://api.omnipublish.com
   ```
2. Verify queue depths via `php artisan queue:monitor redis:publishing,redis:media`.
3. Check application logs for error rate normalization.

