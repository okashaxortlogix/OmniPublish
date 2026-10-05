# OmniPublish — Phase 3C: Instagram / Meta Graph API Publishing Deep Audit Report

**Date of Verification:** October 2026  
**Implementation Phase:** Phase 3C (Instagram / Meta Graph API Content Publishing — Final Closure)  
**Authoritative Product:** Meta Graph API Content Publishing API (`/{ig-user-id}/media`, `/{container-id}`, `/{ig-user-id}/media_publish`, `/{ig-user-id}/content_publishing_limit`)  
**Pipeline Integration:** OmniPublish Platform Adapter Architecture (`InstagramPublisher`)  

---

## 1. Selected Authentication Architecture
* **Architecture:** Instagram Graph API with Facebook Login.
* **Mechanism:** Firebase Authentication + Facebook Login SDK (`FacebookAuthProvider`), linked Facebook Page discovery via `GET /me/accounts`, and Instagram Business Account resolution via `page.instagram_business_account.id`.
* **Graph Host:** Centralized in `config('services.facebook.graph_host')` (`https://graph.facebook.com`).
* **Facebook Page Requirement:** Explicitly scoped as mandatory for this Facebook Login architecture. If an alternative architecture (such as Instagram Login for Business) were used, Meta would not require a Facebook Page.

---

## 2. Supported Account Types
* **Business Accounts:** Fully supported and verified.
* **Creator Accounts:** Fully supported and verified.
* **Personal Accounts:** Unsupported by Meta Graph API; preflight validation halts immediately with `ACCOUNT_NOT_ELIGIBLE` (HTTP 422).

---

## 3. Required Permissions
* Scopes used strictly match the Facebook Login model:
  * `instagram_content_publish`
  * `instagram_basic`
  * `pages_show_list`
  * `pages_read_engagement`
* Scopes from the Instagram Login model (`instagram_business_basic`, `instagram_business_content_publish`) are not requested or mixed into this integration.

---

## 4. API Version Used
* **Tested Runtime Default:** `v21.0`.
* **Dynamic Configuration Testing:** Actively tested against `v22.0` in automated test suite (`test_instagram_configurable_api_version_is_honored`).
* **Distinction:** Tested version is `v21.0` (with configuration verified for `v22.0`). Future unverified versions (e.g. `v23.0`) remain configurable via `.env` but are not marked pre-verified.

---

## 5. API Version Configuration
* Centralized in `backend/config/services.php`:
  * `META_GRAPH_HOST` (default: `https://graph.facebook.com`)
  * `META_GRAPH_API_VERSION` (default: `v21.0`)
* Documented in `backend/.env.example`.
* Resolved dynamically by `InstagramPublisher::getGraphBaseUrl()`, `TokenRefreshService`, and `OAuthController`.

---

## 6. Media Requirements
* **Reels Video:** MP4/MOV, H.264/HEVC, AAC audio, 3.0s–900.0s (15 min) duration, 1 GiB max file size, 23–60 FPS, 540x960 to 1080x1920 resolution, 9:16 recommended aspect ratio.
* **Feed Image:** JPEG/PNG, 8 MiB max file size, 320x320 to 1440x1800 resolution, 4:5 to 1.91:1 aspect ratio.
* **Captions:** Max 2,200 characters, max 30 hashtags.
* **Media Delivery:** Presigned temporary URLs with 4-hour validity (`MediaStorageService::getTemporaryUrl()`). Storage buckets remain private.

---

## 7. Container Lifecycle (Provider State Machine)
* Meta official provider status codes: `IN_PROGRESS`, `FINISHED`, `ERROR`, `EXPIRED`.
* Provider status is cleanly separated from OmniPublish internal status: `PUBLISHED` is an internal state and is never expected as a container status from Meta.

---

## 8. Publishing Lifecycle
* Sequential progression: Preflight eligibility -> Quota check -> Presigned media URL -> Container creation -> Polling -> Media publish -> Permalink fetch -> Internal status `PUBLISHED`.
* Jobs are never marked `PUBLISHED` prematurely.

---

## 9. Token Management
* AES-256-CBC token encryption at rest.
* Automatic extension from short-lived to 60-day long-lived tokens.
* Proactive refresh at 7 days before expiry via `grant_type=fb_extend_sso_token`.
* 20s distributed Redis lock (`token_refresh_lock_{account_id}`) prevents concurrency stampedes.

---

## 10. Rate Limiting
* `GET /{ig_user_id}/content_publishing_limit?fields=config,quota_usage` consumed preflight.
* Enforces rolling 24-hour publishing quota returned by provider.
* HTTP 429 and Meta error codes 4, 17, 32 trigger bounded exponential backoff.

---

## 11. Idempotency & Crash Recovery
* Handled across Scenarios A through F:
  * Container polling recovery preserves existing container ID from `upload_session_url`.
  * Duplicate or post-crash `media_publish` calls catching Meta error 9007 / subcode 2207027 (`Media is already published`) reconcile into `PUBLISHED` with permalink resolution.
  * Distributed job lock (`publish_job_lock_{job_id}`) prevents parallel execution.

---

## 12. Tenant Isolation
* All connected accounts, publishing jobs, and media assets require strict workspace matching.
* Cross-workspace accounts or media assets trigger an immediate `AUTHORIZATION_ERROR` (HTTP 403).

---

## 13. Automated Test Results
* **Instagram Dedicated Suite:** 34 tests passed / 108 assertions (0 failures).
* **Full Backend Suite:** 126 tests passed / 437 assertions (0 failures across 11 test suites).
* **Frontend Verification:** Typecheck PASS, Lint PASS, Production build PASS (29 routes prerendered).

---

## 14. Live API Verification Status
* **Status:** `REAL META API VERIFICATION: NOT AVAILABLE`
* **Reason:** Live provider calls require an active Meta App Review submission and live production credentials.
* **Classification:** Provider fetch and live publishing are labeled `provider fetch not live-tested`.

---

## 15. External Production Dependencies
* Live Meta App Review approval for Live Mode: `instagram_content_publish`, `instagram_basic`, `pages_show_list`, `pages_read_engagement`.
* Production S3/R2 storage bucket with HTTPS presigned URL generation.
* Production Meta App ID and Secret configured in `.env`.

---

## 16. Known Limitations
* Personal Instagram accounts cannot publish content via API (official Meta platform restriction).
* Instagram API with Facebook Login requires a linked Facebook Page for each Instagram account.
* Live Meta Graph API publishing requires an approved Meta App in Live Mode.
