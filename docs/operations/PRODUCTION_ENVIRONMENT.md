# OmniPublish — Production Environment & Secrets Specification

**Version:** Phase 3E Audit Baseline  
**Classification:** Internal Operations & Infrastructure Specification  
**Status:** Certified Architecture & Variable Reference  

---

## 1. Overview & Security Principles

This document defines every environment variable required to operate OmniPublish in a production SaaS environment.

### Core Security Rules
1. **Zero Secret Storage in Code:** No production secret, API credential, database password, or private encryption key is stored in source code, Docker images, or version control.
2. **External Injection:** All production configuration and secrets must be injected at runtime using a secure secret manager (e.g., AWS Secrets Manager, HashiCorp Vault, Kubernetes Secrets, or Doppler).
3. **Repository Cleanliness:** `.env` and `.env.local` files are strictly excluded from Git via `.gitignore`. Only `.env.example` templates containing sanitized placeholder values are tracked.
4. **Log Redaction & Leak Prevention:** Sensitive fields (such as access tokens, refresh tokens, passwords, database URLs, and bearer headers) are explicitly scrubbed from exceptions and application logs. Health check endpoints (`/health`, `/health/live`, `/health/ready`) return structured health states (`OK`, `UNHEALTHY`, `SKIPPED`) without echoing raw error messages or internal network paths.

---

## 2. Production Environment Variable Matrix

| Variable Name | Required / Optional | Secret / Non-Secret | Default / Example Value | Production Source | Purpose & Validation Method |
| :--- | :--- | :--- | :--- | :--- | :--- |
| `APP_NAME` | Required | Non-Secret | `OmniPublish` | Config Map / Env | Application display name. String validation. |
| `APP_ENV` | Required | Non-Secret | `production` | Config Map / Env | Environment name. Must equal `production`. |
| `APP_KEY` | **Required** | **Secret** | `base64:32-byte-key...` | Secrets Manager | AES-256-CBC primary application cipher key for session and payload encryption. Validated via `php artisan key:generate`. |
| `APP_DEBUG` | Required | Non-Secret | `false` | Config Map / Env | Debug output toggle. Must be strictly `false` in production. |
| `APP_URL` | Required | Non-Secret | `https://api.omnipublish.com` | Config Map / Env | Canonical HTTPS backend API domain. Validated via URL parser. |
| `FRONTEND_URL` | Required | Non-Secret | `https://app.omnipublish.com` | Config Map / Env | Canonical frontend web application domain. Used for CORS and cookie origin. |
| `INTERNAL_API_URL` | Optional | Non-Secret | `http://api:8000` | Config Map / Env | Internal network URL for Next.js server-side API proxy rewrites. |
| `BCRYPT_ROUNDS` | Optional | Non-Secret | `12` | Config Map / Env | Work factor for Argon2id/Bcrypt password hashing. |
| `LOG_CHANNEL` | Required | Non-Secret | `stderr` or `stack` | Config Map / Env | Monolog channel. Container stdout/stderr recommended. |
| `LOG_LEVEL` | Required | Non-Secret | `info` (or `warning`) | Config Map / Env | Minimum log severity. Must not be `debug` in production. |
| `DB_CONNECTION` | Required | Non-Secret | `pgsql` | Config Map / Env | Database driver. Strictly `pgsql` for PostgreSQL 16+. |
| `DB_HOST` | Required | Non-Secret | `postgres-cluster.internal` | Config Map / Env | PostgreSQL host/endpoint. |
| `DB_PORT` | Required | Non-Secret | `5432` | Config Map / Env | PostgreSQL port. Must be integer between 1-65535. |
| `DB_DATABASE` | Required | Non-Secret | `omnipublish_prod` | Config Map / Env | Database name. |
| `DB_USERNAME` | Required | Non-Secret | `omnipublish_app` | Config Map / Env | Dedicated application database role (least-privilege). |
| `DB_PASSWORD` | **Required** | **Secret** | `***` | Secrets Manager | PostgreSQL password. Min 32 chars alphanumeric. |
| `DB_SSLMODE` | Required | Non-Secret | `verify-full` or `require` | Config Map / Env | PostgreSQL TLS enforcement. Must not be `disable`. |
| `REDIS_CLIENT` | Required | Non-Secret | `phpredis` | Config Map / Env | Native C PHP Redis extension. |
| `REDIS_HOST` | Required | Non-Secret | `redis-cluster.internal` | Config Map / Env | Redis primary/cluster endpoint. |
| `REDIS_PORT` | Required | Non-Secret | `6379` | Config Map / Env | Redis port. |
| `REDIS_PASSWORD` | **Required** | **Secret** | `***` | Secrets Manager | Redis AUTH password. Non-null in production. |
| `REDIS_DB` | Required | Non-Secret | `0` | Config Map / Env | Dedicated DB index for queue locks and job dispatch. |
| `REDIS_CACHE_DB` | Required | Non-Secret | `1` | Config Map / Env | Dedicated DB index for cache store. |
| `QUEUE_CONNECTION` | Required | Non-Secret | `redis` | Config Map / Env | Asynchronous queue driver. Must be `redis`. |
| `CACHE_STORE` | Required | Non-Secret | `redis` | Config Map / Env | Application cache driver. Must be `redis`. |
| `SESSION_DRIVER` | Required | Non-Secret | `redis` (or `database`) | Config Map / Env | Web session store. |
| `SESSION_SECURE_COOKIE` | Required | Non-Secret | `true` | Config Map / Env | Enforces `Secure` flag on session cookies. |
| `FILESYSTEM_DISK` | Required | Non-Secret | `s3` | Config Map / Env | Object storage driver. Must be `s3`. |
| `AWS_ACCESS_KEY_ID` | **Required** | **Secret** | `AKIA...` | Secrets Manager | IAM Access Key or Cloudflare R2 Token ID. |
| `AWS_SECRET_ACCESS_KEY` | **Required** | **Secret** | `***` | Secrets Manager | IAM Secret Key or Cloudflare R2 Secret. |
| `AWS_DEFAULT_REGION` | Required | Non-Secret | `us-east-1` or `auto` | Config Map / Env | S3 bucket region. |
| `AWS_BUCKET` | Required | Non-Secret | `omnipublish-media-prod` | Config Map / Env | Dedicated private object storage bucket. |
| `AWS_ENDPOINT` | Optional | Non-Secret | `https://<account-id>.r2.cloudflarestorage.com` | Config Map / Env | Custom S3 endpoint for Cloudflare R2 or MinIO. |
| `AWS_USE_PATH_STYLE_ENDPOINT` | Optional | Non-Secret | `false` | Config Map / Env | Required `true` for MinIO, `false` for AWS/R2. |
| `FFMPEG_PATH` | Required | Non-Secret | `/usr/bin/ffmpeg` | Dockerfile / Env | Binary executable path for FFmpeg. Verified via `ffmpeg -version`. |
| `FFPROBE_PATH` | Required | Non-Secret | `/usr/bin/ffprobe` | Dockerfile / Env | Binary executable path for FFprobe. Verified via `ffprobe -version`. |
| `GOOGLE_CLIENT_ID` | **Required** | Non-Secret | `***.apps.googleusercontent.com` | Secrets Manager | Google OAuth 2.0 Web Client ID. |
| `GOOGLE_CLIENT_SECRET` | **Required** | **Secret** | `GOCSPX-***` | Secrets Manager | Google OAuth 2.0 Client Secret. |
| `GOOGLE_REDIRECT_URI` | Required | Non-Secret | `https://app.omnipublish.com/oauth/callback/youtube` | Config Map / Env | Production OAuth callback URL registered in Google Cloud Console. |
| `TIKTOK_CLIENT_KEY` | **Required** | Non-Secret | `aw***` | Secrets Manager | TikTok for Developers Client Key. |
| `TIKTOK_CLIENT_SECRET` | **Required** | **Secret** | `***` | Secrets Manager | TikTok for Developers Client Secret. |
| `TIKTOK_REDIRECT_URI` | Required | Non-Secret | `https://app.omnipublish.com/oauth/callback/tiktok` | Config Map / Env | Production OAuth redirect URL registered in TikTok Developer Portal. |
| `FACEBOOK_CLIENT_ID` | **Required** | Non-Secret | `1234567890***` | Secrets Manager | Meta for Developers App ID (Instagram Graph API). |
| `FACEBOOK_CLIENT_SECRET` | **Required** | **Secret** | `***` | Secrets Manager | Meta for Developers App Secret. |
| `FACEBOOK_REDIRECT_URI` | Required | Non-Secret | `https://api.omnipublish.com/api/v1/oauth/facebook/callback` | Config Map / Env | Valid OAuth Redirect URI registered in Meta App Dashboard. |
| `META_GRAPH_HOST` | Optional | Non-Secret | `https://graph.facebook.com` | Config Map / Env | Meta Graph API endpoint. Default `https://graph.facebook.com`. |
| `META_GRAPH_API_VERSION` | Optional | Non-Secret | `v21.0` | Config Map / Env | Official Meta Graph API version. |

---

## 3. Secret Leakage Audit & Verification Results

A comprehensive audit was performed across all workspace directories to identify any credential exposures:
- **Private RSA/SSH Keys:** None detected (`BEGIN RSA PRIVATE KEY` scan yielded 0 results).
- **AWS Credentials:** No static AWS Access Keys (`AKIA...`) found in code or templates.
- **OAuth Secrets:** All client secrets are parameterized through `config/services.php` and loaded dynamically via `env()`.
- **Git Status:** `.env` and `.env.local` files are ignored by git; no `.env` files are tracked in version control.
- **Frontend Templates:** `frontend/.env.example` created with safe placeholders only.
- **Health Endpoints:** Verified that failure in database connection does not emit raw PDO connection strings, usernames, or IP addresses to callers.
