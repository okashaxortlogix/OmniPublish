# OmniPublish — Production Secrets Inventory & Key Management

**Document Reference:** `docs/infrastructure/PRODUCTION_SECRETS_INVENTORY.md`  
**System Version:** OmniPublish Phase 3GA  
**Security Standard:** Zero-Trust Credential Architecture (Zero Plaintext Secrets in Repositories)  
**Notice:** This document contains metadata and rotation procedures only. **ZERO ACTUAL SECRET VALUES ARE PRINTED.**

---

## 1. Secrets Management Architecture

OmniPublish enforces strict secret isolation:
1. **Zero Secret Footprint in Git:** All `.env`, `.env.production`, private keys, and credential tokens are excluded via `.gitignore` and enforced via automated CI Gitleaks scanning.
2. **Runtime Memory Injection:** Production secrets are stored in **AWS Secrets Manager** (or HashiCorp Vault) and injected into ECS Fargate containers as in-memory environment variables during task boot.
3. **Frontend Isolation:** Next.js client-exposed variables (`NEXT_PUBLIC_*`) are strictly audited to ensure they contain zero private tokens, database strings, or cloud keys.

---

## 2. Comprehensive Production Secrets Inventory

| # | Secret Name | Functional Purpose | Designated Storage Location | Environment | Rotation Cadence | Exposure Risk & Impact |
|---|---|---|---|---|---|---|
| 1 | `APP_KEY` | Laravel application master key; encrypts social tokens (AES-256) & sessions. | AWS Secrets Manager (`omnipublish/prod/APP_KEY`) | Prod & Staging (Unique per env) | Manual (Requires token re-encryption script) | **CRITICAL:** Compromise allows decryption of all stored platform OAuth tokens. |
| 2 | `DB_PASSWORD` | PostgreSQL database user authentication password. | AWS Secrets Manager (`omnipublish/prod/DB_PASSWORD`) | Prod & Staging | Automated (30–90 days via RDS rotation) | **CRITICAL:** Compromise allows unauthorized database read/write access. |
| 3 | `REDIS_PASSWORD` | ElastiCache Redis / Valkey AUTH token. | AWS Secrets Manager (`omnipublish/prod/REDIS_PASSWORD`)| Prod & Staging | Automated (90 days) | **HIGH:** Compromise allows queue tampering or cache poisoning. |
| 4 | `AWS_ACCESS_KEY_ID` / `AWS_SECRET_ACCESS_KEY` | S3 Object Storage API credentials (or Cloudflare R2 token). | AWS Secrets Manager / ECS Task Role | Prod & Staging | Automated (90 days) or Task IAM Role (Instant) | **CRITICAL:** Compromise allows unauthorized media asset extraction/tampering. |
| 5 | `GOOGLE_CLIENT_ID` / `GOOGLE_CLIENT_SECRET` | Google Cloud OAuth 2.0 Web Client for YouTube Data API v3. | AWS Secrets Manager (`omnipublish/prod/GOOGLE_OAUTH`) | Prod & Staging | Manual (Annual or upon developer personnel change) | **HIGH:** Compromise allows unauthorized impersonation of OmniPublish OAuth app. |
| 6 | `TIKTOK_CLIENT_KEY` / `TIKTOK_CLIENT_SECRET` | TikTok for Developers Direct Post API application credentials. | AWS Secrets Manager (`omnipublish/prod/TIKTOK_OAUTH`) | Prod & Staging | Manual (Annual or upon personnel change) | **HIGH:** Compromise allows unauthorized TikTok publishing authorization requests. |
| 7 | `META_APP_ID` / `META_APP_SECRET` | Meta for Developers Instagram Graph API application credentials. | AWS Secrets Manager (`omnipublish/prod/META_OAUTH`) | Prod & Staging | Manual (Annual or upon personnel change) | **HIGH:** Compromise permits Meta Graph API app impersonation and webhook spoofing. |
| 8 | `SANCTUM_STATEFUL_DOMAINS` / Session Secret | Session cookie signing and tenant state isolation. | AWS Secrets Manager (`omnipublish/prod/APP_KEY`) | Prod & Staging | Bound to `APP_KEY` | **MEDIUM:** Cookie tampering risk if compromised. |
| 9 | `MAIL_PASSWORD` | SMTP mailer password for transactional tenant notifications. | AWS Secrets Manager (`omnipublish/prod/MAIL_PASSWORD`) | Prod & Staging | 90 days | **MEDIUM:** Spam / mail server relay abuse if leaked. |
| 10| `SENTRY_DSN` / Datadog API Key | External APM and error tracking telemetry reporting. | AWS Secrets Manager (`omnipublish/prod/SENTRY_DSN`) | Prod & Staging | Annual | **LOW:** Spurious error log ingestion if abused. |
| 11| `GHCR_TOKEN` / `ECR_AUTH` | Container registry pull/push credentials. | GitHub Actions Secrets (`${{ secrets.GITHUB_TOKEN }}`) | CI/CD | Auto-rotated per workflow run | **HIGH:** Registry supply chain injection if leaked. |
| 12| `STAGING_SSH_KEY` / AWS IAM OIDC Role | Deployment execution credentials for staging/production pipelines. | GitHub Actions Secrets / AWS OIDC Role | CI/CD | Rotated per CI/CD security policy | **CRITICAL:** Compromise permits unauthorized production releases. |

---

## 3. Secret Rotation Procedures

### 3.1 Social Platform OAuth Credentials Rotation
1. Generate new Client Secret in provider console (Google Cloud, TikTok Developer Portal, or Meta App Dashboard) alongside the active secret (dual-secret grace period).
2. Update the secret value in AWS Secrets Manager:
   ```bash
   aws secretsmanager update-secret \
     --secret-id "omnipublish/prod/GOOGLE_OAUTH" \
     --secret-string '{"client_id":"...","client_secret":"NEW_SECRET"}'
   ```
3. Issue a rolling container restart across API and Publishing Worker services.
4. Verify OAuth connect flow in staging/production smoke tests.
5. Revoke the old client secret in the provider console.

### 3.2 Database Password Rotation
1. AWS Secrets Manager rotates RDS passwords automatically using the managed RDS rotation Lambda function.
2. RDS Proxy dynamically reads the rotated credential from Secrets Manager without requiring application container restarts or dropping client connection pools.

### 3.3 Master Application Key (`APP_KEY`) Emergency Rotation
Because social account access tokens are encrypted with `APP_KEY`, rotation requires:
1. Running a custom migration script (`php artisan tokens:reencrypt --old-key=... --new-key=...`) to decrypt existing tokens with the old key and re-encrypt with the new key in a single database transaction.
2. Updating `APP_KEY` in Secrets Manager.
3. Restarting application services.
