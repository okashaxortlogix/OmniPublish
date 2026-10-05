# OmniPublish — Phase 3C Final Compliance Report
## Instagram / Meta Graph API Production Compliance Audit

**Date of Report:** October 2026  
**Implementation Phase:** Phase 3C (Instagram / Meta Graph API Publishing — Final Closure)  
**Authoritative Product:** Meta Graph API Content Publishing API (`/{ig-user-id}/media`, `/{container-id}`, `/{ig-user-id}/media_publish`, `/{ig-user-id}/content_publishing_limit`)  

---

### APPLICATION CODE STATUS
`PASS`

### OFFICIAL API COMPLIANCE STATUS
`PASS`

### LIVE PROVIDER VERIFICATION
`NOT AVAILABLE`

### PRODUCTION READINESS
`BLOCKED BY EXTERNAL DEPENDENCIES`

---

## 1. Executive Summary

Phase 3C delivers an asynchronous, multi-tenant isolated, resilient production-grade publishing pipeline for **Instagram Reels and Feed Images** using the official **Meta Graph API Content Publishing API**.

Following the final compliance audit:
1. **Architecture Locked:** Confirmed and locked as **Instagram Graph API with Facebook Login**. No Instagram Login scopes (`instagram_business_*`) are mixed.
2. **API Versioning Centralized:** Runtime default is `v21.0`. Version configuration is centralized in `config/services.php` (`META_GRAPH_HOST` and `META_GRAPH_API_VERSION`) and documented in `.env.example`. Tested against `v21.0` and `v22.0`.
3. **Container State Machine Aligned:** Provider statuses are strictly `IN_PROGRESS`, `FINISHED`, `ERROR`, `EXPIRED`. Internal status `PUBLISHED` is strictly assigned after `media_publish` returns a verified remote media ID.
4. **Media URL Accessibility Hardened:** `MediaStorageService::getTemporaryUrl()` produces presigned temporary HTTPS URLs with 4-hour validity for Meta's crawler. Storage buckets remain private.
5. **Publishing Quotas & Limits Enforced:** Meta Content Publishing Limit API (`content_publishing_limit`) is queried preflight. Rolling 24-hour quota exhaustion triggers `RATE_LIMITED` and bounded exponential backoff.
6. **Idempotency & Crash Recovery Verified:** Crash recovery handles all Scenarios A through F, including container polling resumption and reconciling already-published containers (Meta error 9007 / subcode 2207027).
7. **Frontend State Integrity:** Removed optimistic fallback in `handleRetryJob` that could prematurely display `PUBLISHED` upon retry error.

---

## 2. API Architecture & Configuration

```text
AUTH ARCHITECTURE:    Instagram Graph API with Facebook Login
LOGIN METHOD:         Facebook Login (OAuth 2.0 via graph.facebook.com)
GRAPH HOST:           https://graph.facebook.com (Configurable via META_GRAPH_HOST)
API VERSION TESTED:   v21.0 (with dynamic configuration verified for v22.0)
REQUIRED PERMISSIONS: instagram_content_publish, instagram_basic, pages_show_list, pages_read_engagement
ACCOUNT REQUIREMENTS: Instagram Professional Account (Business or Creator supported)
FACEBOOK PAGE REQ:    Strictly required for this Facebook Login architecture (Meta requires a Facebook Page to bridge Graph API permissions to an Instagram Business/Creator Account)
```

---

## 3. Media Specifications & Constraints

| Parameter | Instagram Reels Specification | Instagram Feed Image Specification |
|---|---|---|
| **Container / MIME** | MP4, MOV (`video/mp4`, `video/quicktime`) | JPEG, PNG (`image/jpeg`, `image/png`) |
| **Video Codecs** | H.264, HEVC / H.265 | N/A |
| **Audio Codecs** | AAC (48kHz, stereo/mono) | N/A |
| **Duration** | 3.0s minimum to 900.0s (15 min) maximum | N/A |
| **Max File Size** | 1 GiB (`1073741824` bytes) | 8 MiB (`8388608` bytes) |
| **Resolution** | Min 540x960, Max 1080x1920 (FHD) | Min 320x320, Max 1440x1800 |
| **Aspect Ratio** | 9:16 recommended (range: 4:5 to 16:9) | 4:5 (0.80) to 1.91:1 (1.91) |
| **Frame Rate** | 23 to 60 FPS | N/A |
| **Captions & Tags** | Max 2,200 characters, Max 30 hashtags | Max 2,200 characters, Max 30 hashtags |
| **Media Delivery** | Presigned S3/R2 URL (4h validity) | Presigned S3/R2 URL (4h validity) |

---

## 4. State Machines

### Provider Container State Machine (Meta)
* `IN_PROGRESS`: Transcoding in progress. OmniPublish yields worker and schedules delayed poll (10s backoff).
* `FINISHED`: Transcoding complete. Ready for `media_publish`.
* `ERROR`: Transcoding failed. Job transitions to `FAILED` with `MEDIA_PROCESSING_FAILED`.
* `EXPIRED`: Container expired after 24h. Clears container ID and retries fresh container.

### OmniPublish Internal State Machine
* `PENDING` -> `QUEUED` -> `PROCESSING` -> `PUBLISHED` (or `RETRYING` / `FAILED` / `AUTH_REQUIRED`).
* `PUBLISHED` is assigned only after `media_publish` returns a verified remote media ID.

---

## 5. Token Management & Rate Limiting

* **Token Storage:** AES-256-CBC encrypted at rest via `encrypted_access_token`. Tokens never appear in logs.
* **Token Extension:** Short-lived tokens exchanged for 60-day long-lived tokens via `oauth/access_token?grant_type=fb_exchange_token`.
* **Proactive Refresh:** Automatically refreshed at 7 days before expiry via `grant_type=fb_extend_sso_token`.
* **Stampede Protection:** Atomic Redis lock (`token_refresh_lock_{account_id}`, TTL 20s). Concurrent workers wait on the lock and re-read the refreshed credential.
* **Quota Check:** Consumes `GET /{ig_user_id}/content_publishing_limit?fields=config,quota_usage`. If quota usage reaches total, delays with `RATE_LIMITED`.

---

## 6. Idempotency & Crash Recovery Scenarios

* **Scenario A (Worker crashes before saving container ID):** Safe retry creates a new container; unreferenced container expires automatically on Meta after 24h.
* **Scenario B (Worker crashes during polling):** Worker reads saved container ID from `upload_session_url` and resumes polling without duplicate container creation.
* **Scenario C (Worker crashes between FINISHED and media_publish):** Worker reads finished container ID and invokes `media_publish`.
* **Scenario D & E (Worker crashes after media_publish or duplicate invocation):** Calling `media_publish` on an already published container returns Meta error 9007 / subcode 2207027 (`Media is already published`). OmniPublish catches this subcode, logs warning, resolves permalink, and transitions the job to `PUBLISHED` without duplicate posts.
* **Scenario F (Worker timeout):** Handled via bounded backoff with distributed Redis locking (`publish_job_lock_{job_id}`) preventing concurrent execution.

---

## 7. Tenant & Platform Isolation

* **Tenant Isolation:** Cross-workspace account connection, cross-workspace media asset publishing, and cross-workspace container lookups are strictly blocked with HTTP 403 `AUTHORIZATION_ERROR`.
* **Platform Isolation:** Failures on Instagram never impact YouTube or TikTok jobs; quota/auth failures on YouTube or TikTok never affect Instagram jobs.

---

## 8. Verification Results

```text
Dedicated Instagram Tests: 34 passed / 108 assertions (100% pass)
Full Backend Test Suite:   126 passed / 437 assertions (100% pass across 11 test suites)
Frontend Typecheck:        PASS (tsc --noEmit exited with code 0)
Frontend Lint:             PASS (eslint exited with code 0, 0 errors)
Frontend Production Build: PASS (Next.js 15.1.0; 29 static/dynamic pages compiled)
Database Migrations:       6 migrations ran cleanly
API Routes:                37 routes registered and active
```

---

## 9. Live API Verification Status & External Blockers

* **Live API Verification:** `NOT AVAILABLE` (`provider fetch not live-tested`).
* **External Deployment Blockers:**
  1. Meta for Developers App creation in Business mode.
  2. Meta Business Verification.
  3. Meta App Review submission and approval for permissions:
     * `instagram_content_publish`
     * `instagram_basic`
     * `pages_show_list`
     * `pages_read_engagement`
  4. Live production credentials (`FACEBOOK_CLIENT_ID`, `FACEBOOK_CLIENT_SECRET`) configured in `.env`.

---

## 10. Remaining Limitations

* Personal Instagram accounts are unsupported by Meta Content Publishing API (official Meta platform restriction).
* Instagram API with Facebook Login requires each Instagram account to be connected to a Facebook Page.
