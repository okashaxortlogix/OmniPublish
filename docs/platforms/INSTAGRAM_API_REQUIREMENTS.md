# Instagram Content Publishing API — Specification & Requirements

**Date of Verification:** October 2026  
**Authoritative Product:** Meta Graph API Content Publishing API (`/{ig-user-id}/media`, `/{container-id}`, `/{ig-user-id}/media_publish`, `/{ig-user-id}/content_publishing_limit`)  
**Pipeline Integration:** OmniPublish Platform Adapter Architecture (`InstagramPublisher`)  

---

## 1. Selected Authentication Architecture
* **Selected Model:** **Instagram Graph API with Facebook Login**.
* **Rationale:** Built upon Firebase Authentication + Facebook Login SDK (`FacebookAuthProvider`), discovering user-managed Facebook Pages and resolving connected Instagram Professional accounts.
* **Graph Host:** `https://graph.facebook.com` (Centralized via `config('services.facebook.graph_host')` / `META_GRAPH_HOST`).
* **OAuth Dialog:** `https://www.facebook.com/{version}/dialog/oauth`
* **OAuth Token Exchange:** `https://graph.facebook.com/{version}/oauth/access_token`
* **Facebook Page Requirement Scoping:**  
  A Facebook Page is **strictly required for this Facebook Login architecture**. Under this configuration, Meta requires a Facebook Page to bridge Graph API permissions (`pages_show_list` -> `page.instagram_business_account.id`).  
  *Note:* If OmniPublish were to adopt an alternative configuration (such as Instagram Login for Business), a Facebook Page would not be required. The Page requirement is strictly scoped to this selected Facebook Login architecture.

---

## 2. Supported Account Types
* **Instagram Business Accounts:** Fully Supported.
* **Instagram Creator Accounts:** Fully Supported.
* **Instagram Personal Accounts:** Strictly Unsupported. Personal accounts lack access to the Graph API Content Publishing API. Preflight checks reject personal accounts with `ACCOUNT_NOT_ELIGIBLE` and guide creators to convert to Professional accounts.

---

## 3. Required Permissions
OmniPublish utilizes exclusively the Facebook Login permission model:
* `instagram_content_publish`: Grants container creation and media publishing capability.
* `instagram_basic`: Grants access to Instagram user profile, account ID, and media counts.
* `pages_show_list`: Grants permission to discover user-administered Facebook Pages.
* `pages_read_engagement`: Allows reading Page metadata to resolve linked Instagram accounts.

*No Permission Mixing:* Scopes from the Instagram Login model (e.g., `instagram_business_basic`, `instagram_business_content_publish`) are **not** requested or mixed into this architecture.

---

## 4. API Version Used
* **Active Runtime Default:** `v21.0` (Graph API v21.0).
* **Version Verification:** `v21.0` is verified against official Meta documentation and actively tested. In addition, dynamic configuration was tested and verified with `v22.0`.
* **Deprecation Safety:** Meta provides 2-year version stability windows.

---

## 5. API Version Configuration
All Graph API versioning is centralized in `config/services.php`:
```php
'facebook' => [
    'client_id'         => env('FACEBOOK_CLIENT_ID'),
    'client_secret'     => env('FACEBOOK_CLIENT_SECRET'),
    'redirect_uri'      => env('FACEBOOK_REDIRECT_URI', 'http://localhost:8001/api/v1/oauth/facebook/callback'),
    'graph_host'        => env('META_GRAPH_HOST', 'https://graph.facebook.com'),
    'graph_api_version' => env('META_GRAPH_API_VERSION', 'v21.0'),
],
```
No hardcoded version strings exist in platform adapters or controllers. All network requests resolve endpoints via `$this->getGraphBaseUrl()`.

---

## 6. Media Requirements

### Instagram Reels (Video)
* **API Identifier:** `media_type=REELS`
* **Container Formats:** MP4, MOV (`video/mp4`, `video/quicktime`)
* **Video Codecs:** H.264, HEVC / H.265
* **Audio Codecs:** AAC (48kHz sample rate, stereo or mono)
* **Duration Constraints:** 3.0s minimum to 900.0s (15 minutes) maximum
* **File Size Limit:** 1 GiB (`1073741824` bytes) maximum
* **Frame Rate:** 23 to 60 FPS
* **Resolution:** 540x960 minimum to 1080x1920 maximum (Full HD)
* **Aspect Ratio:** 9:16 recommended (acceptable range: 4:5 to 16:9)

### Instagram Feed Images
* **API Identifier:** Default / Photo
* **Supported Formats:** JPEG, PNG (`image/jpeg`, `image/png`)
* **File Size Limit:** 8 MiB (`8388608` bytes) maximum
* **Resolution:** 320x320 minimum to 1440x1800 maximum
* **Aspect Ratio:** 4:5 (0.80) to 1.91:1 (1.91)

### Metadata Constraints
* **Captions:** 2,200 characters maximum.
* **Hashtags:** 30 hashtags maximum.

*Distinction:* All above limits reflect official Meta API requirements, with 9:16 aspect ratio recommendation enforced as an application UX standard.

---

## 7. Container Lifecycle (Provider State Machine)
Meta enforces an asynchronous container lifecycle. Official provider status codes for `GET /{container_id}?fields=status_code,status`:
1. `IN_PROGRESS`: Transcoding/video rendering is in progress. The worker yields, preserves container ID, and schedules delayed polling (10s backoff).
2. `FINISHED`: Transcoding is complete. Container is ready to be published via `media_publish`.
3. `ERROR`: Transcoding failed. Job transitions to `FAILED` with category `MEDIA_PROCESSING_FAILED`.
4. `EXPIRED`: Container expired (Meta expires unpublished containers after 24 hours). OmniPublish clears container ID and retries fresh container creation.

*Provider Status vs Internal Status:* `PUBLISHED` is **not** an official Meta container status code. Meta container status queries only return the 4 codes above. `PUBLISHED` is strictly an internal OmniPublish state.

---

## 8. Publishing Lifecycle
The publishing flow is strictly sequential:
1. Preflight account validation (`account_type` check).
2. Content publishing limit check (`content_publishing_limit`).
3. Presigned media URL generation with 4-hour validity (`MediaStorageService::getTemporaryUrl()`).
4. Container Creation (`POST /{ig_user_id}/media`).
5. Asynchronous Polling (`GET /{container_id}`).
6. Final Media Publish (`POST /{ig_user_id}/media_publish`).
7. Permalink Retrieval (`GET /{media_id}?fields=permalink`).
8. Internal status transitioned to `PUBLISHED` only after remote media ID is received.

---

## 9. Token Management
* **Token Encryption:** AES-256-CBC encryption at rest for all tokens via `encrypted_access_token`. Tokens never appear in application logs or exception traces.
* **Extension Mechanism:** Short-lived tokens (1–2h) are exchanged for 60-day long-lived tokens via `oauth/access_token?grant_type=fb_exchange_token`.
* **Proactive Refresh:** Tokens are refreshed when within 7 days of expiry via `grant_type=fb_extend_sso_token`.
* **Concurrency Lock:** Token refresh is serialized across workers using an atomic distributed Redis lock (`token_refresh_lock_{account_id}`, TTL 20s). Concurrent workers wait on the lock and re-read the refreshed credential from the database.

---

## 10. Rate Limiting & Quotas
* **Content Publishing Limit API:** OmniPublish queries `GET /{ig_user_id}/content_publishing_limit?fields=config,quota_usage`. If `quota_usage >= quota_total` (typically 100 posts / 24 hours), publishing is delayed with category `RATE_LIMITED`.
* **Application-Level Rate Limits:** HTTP 429 and Meta error codes 4, 17, 32 are classified as `RATE_LIMITED` and scheduled for retry with bounded exponential backoff.

---

## 11. Idempotency & Crash Recovery
* **Scenario A (Crash before saving container ID):** Worker retries fresh container creation safely.
* **Scenario B (Crash during polling):** Worker reads existing container ID from `upload_session_url` and resumes polling without recreating container.
* **Scenario C (Crash between FINISHED and media_publish):** Worker reads finished container ID and invokes `media_publish`.
* **Scenario D & E (Crash after media_publish or duplicate call):** Calling `media_publish` on an already published container returns Meta error 9007 / subcode 2207027 (`Media is already published`). OmniPublish catches this subcode, logs warning, resolves permalink, and transitions the job to `PUBLISHED` without duplicate post creation.
* **Queue Concurrency:** Distributed job locks (`publish_job_lock_{job_id}`, TTL 120s) ensure only one worker processes a job at any given time.

---

## 12. Tenant Isolation
* All connected accounts, publishing jobs, and media assets require strict workspace matching.
* Cross-workspace accounts or media assets trigger an immediate `AUTHORIZATION_ERROR` (HTTP 403).
* Container reconciliation and status queries are strictly scoped to the job's tenant.

---

## 13. Automated Test Results
* **Instagram Dedicated Suite:** 34 tests passed / 108 assertions (0 failures).
* **Full Backend Suite:** 126 tests passed / 437 assertions (0 failures across 11 test suites).
* **Frontend Verification:** Typecheck passed (0 errors), Lint passed (0 errors), Production build passed (29 routes prerendered).

---

## 14. Live API Verification Status
* **Status:** `REAL META API VERIFICATION: NOT AVAILABLE`
* **Reason:** Live provider calls require an active Meta App Review submission, approved permissions, and live production Instagram Business credentials.
* **Classification:** Provider fetch and live publishing are `provider fetch not live-tested`.

---

## 15. External Production Dependencies
* Meta for Developers App ID and App Secret.
* Meta Business Verification.
* Meta App Review approval for Live Mode: `instagram_content_publish`, `instagram_basic`, `pages_show_list`, `pages_read_engagement`.
* Production S3/R2 storage bucket with HTTPS presigned URL generation.

---

## 16. Known Limitations
* Personal Instagram accounts cannot publish content via API (official Meta platform restriction).
* Instagram API with Facebook Login requires a linked Facebook Page for each Instagram account.
* Live Meta Graph API publishing requires an approved Meta App in Live Mode.
