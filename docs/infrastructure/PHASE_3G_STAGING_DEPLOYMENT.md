# OmniPublish — Phase 3G Staging Deployment Specification & Runbook

**Document Reference:** `docs/infrastructure/PHASE_3G_STAGING_DEPLOYMENT.md`  
**System Version:** OmniPublish Phase 3G  
**Target Environment:** AWS Staging (`staging.omnipublish.com`)  
**Deployment Pipeline:** GitHub Actions (`.github/workflows/deploy-staging.yml`)  
**Status:** **PIPELINE SPECIFIED & SCRIPTED — READY FOR CLOUD TARGET ACTIVATION**

---

## 1. Continuous Deployment Pipeline Overview

Staging deployment is fully automated via GitHub Actions, triggering on merges to the `develop` branch or on manual `workflow_dispatch`:

```text
Git Merge (develop) ──▶ Secret Scan (Gitleaks) ──▶ Run Test Matrix (152 tests)
                                                          │
          ┌───────────────────────────────────────────────┘
          ▼
Build Multi-Role Docker Images (API, Workers, Frontend)
          │
          ▼
Aqua Security Trivy Vulnerability Scan (Critical/High)
          │
          ▼
Push Immutable Images Tagged by Git SHA: ecr.aws/...:sha-<commit_sha>
          │
          ▼
Run Database Migrations: php artisan migrate --force
          │
          ▼
Rolling Update ECS Services (Zero-Downtime Deployment)
          │
          ▼
Graceful Worker Fleet Signal: php artisan queue:restart
          │
          ▼
Execute Automated Smoke Test Suite: infra/scripts/smoke_test.sh
```

---

## 2. Immutable Image Tagging Policy

OmniPublish strictly prohibits the deployment of mutable tags such as `latest` in staging and production:
* Every release candidate is tagged with the exact Git commit SHA:
  $$\text{omnipublish-api:sha-}\{github.sha\}$$
* ECS Task Definitions are updated dynamically during deployment to point to the new Git SHA.
* Rollback is instantaneous by updating the task definition back to the previous known-good Git commit SHA.

---

## 3. Safe Schema Migration Protocol

Database migrations in staging execute under automated safety guards:

1. **Pre-Migration Snapshot:**
   The deployment workflow requests an on-demand snapshot of the RDS instance:
   ```bash
   aws rds create-db-snapshot \
     --db-instance-identifier omnipublish-staging-db \
     --db-snapshot-identifier "omnipublish-staging-predeploy-${GITHUB_SHA}"
   ```
2. **Ephemeral Migration Task:**
   Migrations run as a standalone, one-shot ECS Fargate task:
   ```bash
   aws ecs run-task \
     --cluster omnipublish-staging-cluster \
     --task-definition omnipublish-staging-api \
     --overrides '{"containerOverrides": [{"name": "api", "command": ["php", "artisan", "migrate", "--force"]}]}'
   ```
3. **Reversibility Guarantee:**
   If the migration fails, the pipeline immediately halts before updating the API or worker containers.

---

## 4. Post-Deployment Smoke Test Protocol

Upon completion of the ECS rolling deployment, GitHub Actions executes [`infra/scripts/smoke_test.sh`](file:///e:/Okashaaaaa/Projects/OmniPublish/OmniPublish/infra/scripts/smoke_test.sh) against the staging endpoint:

```bash
bash infra/scripts/smoke_test.sh https://staging-api.omnipublish.com
```

### Probed Endpoints & Acceptance Thresholds:
1. `GET /health/live`: Expects HTTP 200 `{"status":"UP"}`.
2. `GET /health/ready`: Expects HTTP 200 `{"status":"READY","checks":{"database":"UP","redis":"UP","storage":"UP"}}`.
3. `GET /version`: Expects HTTP 200 reporting environment `staging` and the deployed Git commit SHA.
4. `GET /api/v1/workspaces`: Expects HTTP 200 / 401 (Valid API response).
5. `GET /api/v1/publish/batches`: Expects HTTP 200 / 401.
6. `GET /api/v1/calendar`: Expects HTTP 200 / 401.
7. `GET /api/v1/non-existent-probe-test`: Expects HTTP 404 (Zero unexpected routing leaks).
