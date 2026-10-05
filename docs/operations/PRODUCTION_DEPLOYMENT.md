# OmniPublish — Production Deployment Guide

**Document Version:** 1.0 (Phase 3F Certified)  
**Environment:** Production (`app.omnipublish.com`)  
**Audience:** Site Reliability Engineers, Release Managers, Lead Engineers  

---

## 1. Production Release Prerequisites

A release candidate may only be promoted to production when ALL of the following criteria are met:
1. **Green CI Pipeline:** All tests, static validation, and clean PostgreSQL migration checks in `.github/workflows/ci.yml` passed on `main`.
2. **Immutable Artifact Built:** Docker images built and scanned with 0 critical/high CVEs by `.github/workflows/docker.yml`.
3. **Successful Staging Validation:** Release successfully deployed and verified in staging with green smoke tests.
4. **Origin Gate:** Originates strictly from `main` branch or a verified SemVer release tag (`v*.*.*`).
5. **Manual Reviewer Approval:** Approved by at least one designated SRE / Release Manager in GitHub Environment protection rules.

---

## 2. Step-by-Step Production Release Workflow

### Step 1: Triggering the Release
1. Create a GitHub Release or tag a commit on `main`:
   ```bash
   git checkout main
   git pull origin main
   git tag -a v1.0.0 -m "Release v1.0.0"
   git push origin v1.0.0
   ```
2. Or trigger manually via **Actions > OmniPublish Deploy Production**:
   - Provide the release tag or Git SHA.

### Step 2: Manual Reviewer Gate
- GitHub Actions pauses the workflow at the `deploy-production` job.
- Designated reviewers receive a notification and must click **Review deployments > Approve and deploy**.

### Step 3: Pre-Deployment Database Snapshot
- The pipeline initiates an automated snapshot of the production PostgreSQL cluster before running any schema migrations.
- If the backup snapshot fails, the deployment halts immediately.

### Step 4: Backward-Compatible Schema Migration
- Migrations are applied using:
  ```bash
  php artisan migrate --force
  ```
- *Rule:* Schema changes must be backward-compatible with running API containers (expand-contract pattern).

### Step 5: Rolling Application Deployment
- Backend API and Next.js frontend pods are updated using a rolling deployment strategy:
  - New pods boot up and must pass the `/health/live` and `/health/ready` probes before receiving production traffic.
  - Old pods are drained of connections and terminated cleanly.

### Step 6: Graceful Worker Fleet Reload
- Workers are signaled via Redis cache:
  ```bash
  php artisan queue:restart
  ```
- Active publishing and media processing jobs finish their work without being dropped; workers restart with new code on their next poll.

### Step 7: Post-Deployment Smoke Test
- Executes `infra/scripts/smoke_test.sh https://api.omnipublish.com`.
- Confirms:
  - `/health/live` returns HTTP 200 `UP`.
  - `/health/ready` returns HTTP 200 `READY`.
  - `/version` returns HTTP 200 with the matching Git SHA.
  - Tenant isolation and authentication endpoints enforce expected authorization rules.

### Step 8: Audit Trail Recording
- A structured record containing the deployment timestamp, release SHA, deploying actor, and final status is logged in the workflow run summary.
