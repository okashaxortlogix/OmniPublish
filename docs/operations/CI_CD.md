# OmniPublish — Continuous Integration & Continuous Delivery (CI/CD) Architecture

**Document Version:** 1.0 (Phase 3F Certified)  
**System:** OmniPublish Unified Multi-Platform Social Publishing SaaS  
**Audience:** Release Engineers, DevOps, Platform Engineers, Software Engineers  

---

## 1. Overview & Pipeline Philosophy

The OmniPublish CI/CD architecture implements an automated, secure, and reproducible path from pull request to production.

### Core Release Tenets
1. **Branch Gate Enforcement:** Production deployments are strictly prohibited from non-main feature branches. All production releases must originate from clean commits on `main` or signed SemVer release tags (`v*.*.*`).
2. **Shift-Left Security:** Secret detection (Gitleaks), dependency security auditing (`composer audit`, `npm audit`), and container image vulnerability scanning (Trivy) run on every push and pull request before code reaches any shared environment.
3. **Immutable Artifacts:** Docker images are tagged with immutable Git commit SHAs (`${{ github.sha }}`) in addition to environment and release tags. The exact image digest verified in CI and tested in staging is the identical artifact promoted to production.
4. **Clean Ephemeral Database Verification:** CI validates all database migrations against a fresh, ephemeral PostgreSQL 16 service container to ensure migration and rollback safety before merging.
5. **Zero Secret Leakage:** Production secrets are never embedded in Docker images, never logged to stdout, and never accessible to unapproved Pull Request runs.

---

## 2. GitHub Actions Workflow Topology

```text
                  [ Developer PR / Push ]
                             │
            ┌────────────────┴────────────────┐
            ▼                                 ▼
   [ secret-scanning ]               [ dependency-audit ]
            │                                 │
     (Gitleaks scan)                 (composer / npm audit)
            │                                 │
            └────────────────┬────────────────┘
                             │
            ┌────────────────┴────────────────┐
            ▼                                 ▼
     [ backend-ci ]                    [ frontend-ci ]
     • PHP 8.3 & FFmpeg                • Node 20 runtime
     • Clean Postgres 16 test DB       • TypeScript noEmit
     • Clean Redis service             • ESLint verification
     • migrate:fresh & rollback        • Next.js production build
     • 151 feature/unit tests
            │
            ▼
     [ docker.yml ]
     • Build targets: api, publishing-worker,
       media-worker, default-worker, scheduler, frontend
     • Tag with Git commit SHA
     • Aqua Security Trivy container scan
     • Push to GHCR / ECR
            │
            ▼ (on merge to develop)
   [ deploy-staging.yml ]
     • Deploy exact Git SHA
     • Run migrations (--force)
     • Graceful worker restart
     • Execute automated smoke test suite
            │
            ▼ (on release tag or manual promotion from main)
 [ deploy-production.yml ]
     • Gate: GitHub Environment 'production' (Manual Reviewer Approval)
     • Pre-deployment database snapshot
     • Zero-downtime rolling deployment
     • Post-deployment smoke test verification
     • Release audit trail record
```

---

## 3. Workflow Specifications

### 3.1 `.github/workflows/ci.yml`
- **Triggers:** Push to `main` or `develop`; Pull Requests targeting `main` or `develop`.
- **Jobs:**
  - `secret-scanning`: Verifies no private keys, cloud tokens, or `.env` files are tracked in git.
  - `backend-ci`: Boots PostgreSQL 16 and Redis 7 containers, runs strict composer validation and audit, verifies full migration cycle, and executes PHPUnit test suite.
  - `frontend-ci`: Validates TypeScript typing (`tsc --noEmit`), enforces ESLint rules, and compiles production Next.js bundle.

### 3.2 `.github/workflows/docker.yml`
- **Triggers:** Push to `main`, `develop`, tags `v*.*.*`, or manual dispatch.
- **Matrix Targets:** `api`, `publishing-worker`, `media-worker`, `default-worker`, `scheduler`, and `frontend`.
- **Security:** Scans built images using Aqua Security Trivy for high/critical CVEs.

### 3.3 `.github/workflows/deploy-staging.yml`
- **Triggers:** Automated on merge to `develop`, or manual dispatch.
- **Environment:** `staging`.
- **Verification:** Executes `infra/scripts/smoke_test.sh` against target URL.

### 3.4 `.github/workflows/deploy-production.yml`
- **Triggers:** Release publication (`v*.*.*`) or manual promotion via workflow dispatch.
- **Environment Gate:** `production` with required manual reviewer sign-off.
- **Safety Steps:** Pre-migration database backup, zero-downtime rolling deploy, and post-deploy health check.

---

## 4. Secret Configuration in GitHub

Configure the following GitHub Secrets under **Repository Settings > Secrets and Variables > Actions**:

| Secret Key | Required By | Description |
| :--- | :--- | :--- |
| `GITHUB_TOKEN` | All workflows | Automatic GitHub token for GHCR authentication and Gitleaks. |
| `STAGING_SSH_HOST` | `deploy-staging.yml` | IP or domain of staging host / Kubernetes jump host. |
| `STAGING_SSH_USER` | `deploy-staging.yml` | Deployment user SSH username. |
| `STAGING_SSH_KEY` | `deploy-staging.yml` | Private SSH key for staging server access. |
| `PROD_DB_HOST` | `deploy-production.yml` | Production RDS/PostgreSQL endpoint for pre-deploy snapshot. |
| `PROD_DB_DATABASE` | `deploy-production.yml` | Production database name. |
| `PROD_DB_USERNAME` | `deploy-production.yml` | Production database admin user for backup snapshot. |
