# OmniPublish — Staging Deployment Guide

**Document Version:** 1.0 (Phase 3F Certified)  
**Environment:** Staging (`staging.omnipublish.com`)  
**Audience:** QA Engineers, Developers, DevOps  

---

## 1. Staging Environment Isolation

To ensure complete safety and prevent test pollution from affecting production data:

| Component | Staging Resource | Isolation Guarantee |
| :--- | :--- | :--- |
| **Database** | `omnipublish_staging` | Completely isolated PostgreSQL instance / database. Zero production data. |
| **Redis** | `redis-staging.internal:6379` | Dedicated Redis instance. No cross-environment lock sharing. |
| **Object Storage** | `omnipublish-media-staging` | Dedicated S3 bucket with staging lifecycle rules. |
| **Provider OAuth** | Sandbox / Dev Apps | Configured with developer sandbox credentials (e.g. YouTube sandbox quota, TikTok Developer sandbox, Meta Test Apps). |
| **Domains** | `staging.omnipublish.com` / `staging-api.omnipublish.com` | Isolated TLS certificates and separate DNS records. |

---

## 2. Automated Staging Deployment Workflow

Staging deployments trigger automatically whenever changes merge into the `develop` branch via `.github/workflows/deploy-staging.yml`.

### Deployment Lifecycle
1. **Source Checkout:** Resolves the exact Git commit SHA from `develop`.
2. **Container Pull:** Pulls images built by `.github/workflows/docker.yml` matching `ghcr.io/omnipublish/backend-api:<commit-sha>`.
3. **Database Migration:** Executes `php artisan migrate --force`.
4. **Worker Fleet Restart:** Signals worker containers to gracefully reload (`php artisan queue:restart`).
5. **Configuration Cache Warmup:** Executes `php artisan config:cache && php artisan route:cache`.
6. **Automated Smoke Test:** Runs `infra/scripts/smoke_test.sh https://staging-api.omnipublish.com`.
7. **Audit Record:** Emits a structured staging deployment record with deployment timestamp and initiator.

---

## 3. Manual Staging Deployment Trigger

To trigger a manual staging deployment via GitHub Actions:
1. Navigate to **Actions > OmniPublish Deploy Staging**.
2. Click **Run workflow**.
3. Select branch `develop` (or specific feature branch for test deployment).
4. Click **Run workflow**.
5. Monitor logs and verify that the post-deployment smoke test step completes with `SMOKE TEST SUITE PASSED`.
