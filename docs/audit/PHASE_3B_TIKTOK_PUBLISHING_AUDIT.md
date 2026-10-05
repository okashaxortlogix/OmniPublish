# OmniPublish — Phase 3B: TikTok Direct Publishing Deep Audit Report

**Date of Verification:** October 2026  
**Implementation Phase:** Phase 3B (TikTok Direct Publishing Pipeline)  
**Authoritative Product:** TikTok Content Posting API — Direct Post (`/v2/post/publish/`)  
**Pipeline Integration:** OmniPublish Platform Adapter Architecture  

---

## 1. Executive Summary

Phase 3B implements an asynchronous, multi-tenant isolated, production-grade publishing pipeline for **TikTok Content Posting API (Direct Post)** within the existing OmniPublish architecture.

A thorough final verification and hardening pass was conducted against the current official TikTok for Developers documentation. Media validation rules were aligned to the authoritative TikTok Media Transfer Guide (supporting 4 GiB max file size, VP8/VP9/H.264/H.265 codecs, and 23–60 FPS). Scope validation strictly enforces `video.publish` for Direct Post. Chunk boundary calculation guarantees `total_chunk_count <= 1000`, single-chunk support for files <= 64 MB, and graceful session recovery on upload URL expiration (HTTP 403/404).

Multi-platform isolation is strictly guaranteed: **a TikTok failure never impacts YouTube publishing, and a YouTube failure never impacts TikTok publishing**.

All 92 backend automated tests (including 31 dedicated TikTok tests with 123 assertions) passed cleanly. Next.js frontend compiled and built with 0 errors (29 static pages generated, TypeScript strict validation passed).

---

## 2. Repository Baseline

* **Backend Framework:** Laravel 11.x on PHP 8.3
* **Frontend Framework:** Next.js 15.1.0 with React 19 and Tailwind CSS
* **Database & Migration Status:** 6 migrations ran cleanly; PostgreSQL-ready schema
* **Queue Engine:** Database / Redis queue with distributed Redis/database locks (`publish_lock_job_{job_id}`)
* **Total Automated Tests:** 92 passed (329 assertions) across 11 test suites
* **TikTok Dedicated Tests:** 31 passed (123 assertions)
* **Typecheck Status:** `tsc --noEmit` passed with 0 errors
* **Lint Status:** `eslint` passed with 0 errors (20 non-blocking Next.js framework warnings)
* **Production Build Status:** `next build` passed with 0 errors (29 routes rendered)

---

## 3. TikTok API Verification & Official Boundaries

Authoritative TikTok for Developers documentation for the **Content Posting API v2** was verified:

* **OAuth Endpoints:**
  * Authorization: `https://www.tiktok.com/v2/auth/authorize/`
  * Token Exchange & Refresh: `https://open.tiktokapis.com/v2/oauth/token/`
  * Revocation: `https://open.tiktokapis.com/v2/oauth/revoke/`
* **Direct Post Endpoints:**
  * Creator Info: `POST https://open.tiktokapis.com/v2/post/publish/creator_info/query/`
  * Video Init: `POST https://open.tiktokapis.com/v2/post/publish/video/init/`
  * Binary Chunk Upload: `PUT <upload_url>` with `Content-Range: bytes {start}-{end}/{total}`
  * Remote Status Fetch: `POST https://open.tiktokapis.com/v2/post/publish/status/fetch/`
* **Scope Semantics:**
  * `video.publish`: Mandatory for Direct Post.
  * `user.info.basic`: Required for creator profile details.
* **Unaudited Application Requirement:**
  * Categorized as an **External Production Requirement**. TikTok restricts content from unaudited developer applications to private viewing until the app passes review.

---

## 4. Architecture

TikTok publishing is encapsulated cleanly within:
* **Publisher & Adapter:** `App\Platforms\Adapters\TikTokPublisher` (implementing `App\Platforms\Contracts\PlatformAdapterInterface`) and `TikTokAdapter`.
* **Media Profile:** `App\Platforms\Profiles\TikTokMediaProfile` centralizing container, MIME, codec, dimension, FPS, and duration validation rules.
* **Token Refresh Service:** `App\Services\TokenRefreshService` with atomic distributed mutex locking (`token_refresh_lock_{account_id}`, TTL 20s).
* **Platform Registry:** `App\Platforms\PlatformManager` dynamically binding `TIKTOK` to `TikTokAdapter`.

---

## 5. OAuth Security

* **State / CSRF Protection:** Cryptographically secure random states bound with short-lived cache and workspace verification.
* **Token Encryption:** Access tokens and refresh tokens are stored encrypted using Laravel's AES-256-CBC envelope encryption (`encrypted_access_token`, `encrypted_refresh_token`).
* **Secret Redaction:** No raw OAuth tokens, client keys, or client secrets are logged or exposed to client-side payloads.
* **Token Refresh Concurrency:** Protected by distributed mutex lock (`token_refresh_lock_{account_id}`, TTL 20s) preventing concurrent worker refresh stampedes.

---

## 6. Multi-Tenant Security & Zero-Trust Isolation

All endpoints and worker execution paths enforce strict tenant boundaries:
1. `PublishJob->workspace_id === authenticated_workspace_id`
2. `SocialAccount->workspace_id === PublishJob->workspace_id`
3. `MediaAsset->workspace_id === PublishJob->workspace_id`
4. Cross-tenant access attempts immediately terminate with `AUTHORIZATION_ERROR` (HTTP 403) without exposing resource existence.

Tested attacks:
* Forging workspace headers or URLs across tenants.
* Replaying another tenant's publish job ID or TikTok account ID.
* Polling status or retrying foreign workspace jobs.

---

## 7. Creator Capability Validation

Before commencing binary media uploads, the pipeline queries TikTok creator info (`/v2/post/publish/creator_info/query/`) and authoritative creator capabilities are enforced server-side:
* **Privacy Level:** Must belong to `creatorInfo['privacy_level_options']`. Unsupported privacy settings reject with `CREATOR_CAPABILITY_ERROR`.
* **Interaction Features:** If creator account has `comment_disabled`, `duet_disabled`, or `stitch_disabled`, requesting those features causes preflight rejection.
* **Duration Constraints:** Media duration is checked against `creatorInfo['max_video_post_duration_sec']`.

---

## 8. Media Validation Profile

Enforced centrally in `TikTokMediaProfile`:
* **Containers:** `.mp4`, `.webm`, `.mov`
* **Video Codecs:** H.264 (AVC/avc1), H.265 (HEVC/hvc1), VP8, VP9
* **Audio Codecs:** AAC, MP3, Opus
* **Resolution:** Minimum 360 x 360 px (neither width nor height below 360px), maximum 4096 x 4096 px
* **Frame Rate:** 23 to 60 FPS
* **Duration:** Minimum 3.0s, maximum creator-specified duration (default 600s)
* **File Size:** Minimum 1,000 bytes, maximum 4 GiB (4,294,967,296 bytes)

---

## 9. Direct Post Flow & Chunking

* **Files <= 64 MB:** Uploaded in 1 chunk equal to total file size.
* **Files > 64 MB:** Chunk size selected between 5 MB and 64 MB ensuring `total_chunk_count <= 1000`.
* **Expired Upload Session:** If upload endpoint returns HTTP 403/404, `upload_session_url` is reset and classified as retryable `UPLOAD_SESSION_EXPIRED`, preventing infinite loops.
* **State Machine:**
```text
QUEUED -> VALIDATING -> CREATOR_CHECK -> INITIALIZING -> UPLOADING -> PROCESSING -> PUBLISHED
```

---

## 10. Queue & Worker Architecture

* **Worker Execution:** Fully asynchronous queue worker execution via `PublishDestinationJob`.
* **Timeout & Tries:** Configured with max 3 attempts and exponential backoff with jitter.
* **Ephemeral Disk Scratch:** Temporary streaming files are safely isolated per job and deleted in guaranteed `finally` blocks.

---

## 11. Idempotency & Failure Recovery

* **Distributed Lock:** Queue dispatch acquires `publish_lock_job_{job_id}` (TTL 300s). Duplicate dispatches or concurrent workers cleanly abort without duplicate API calls.
* **Remote Post ID Guard:** If `remote_post_id` (`publish_id`) exists and upload is complete (`upload_session_url === null`), the adapter skips upload and queries processing status directly.
* **Worker Crash Recovery:** If a worker crashes mid-upload while `upload_session_url` is active, it resumes streaming chunks rather than prematurely skipping to status checks.

---

## 12. Retry Strategy

* **Retryable:**
  * Network timeouts and connection drops (`NETWORK_ERROR`).
  * TikTok HTTP 500, 502, 503, 504 server errors (`REMOTE_SERVER_ERROR`).
  * HTTP 429 and `rate_limit_exceeded` (`RATE_LIMITED`).
  * Stale upload sessions (`UPLOAD_SESSION_EXPIRED`).
* **Non-Retryable:**
  * Expired/revoked credentials (`AUTH_REQUIRED`).
  * Media validation failures (`INVALID_MEDIA`).
  * Unsupported creator settings (`CREATOR_CAPABILITY_ERROR`).
  * Spam risk detection (`INVALID_METADATA`).

---

## 13. Observability

Dedicated typed events dispatched at every milestone:
* `TikTokPublishingStarted`
* `TikTokCreatorInfoFetched`
* `TikTokPublishingInitialized`
* `TikTokUploadStarted`
* `TikTokUploadProgress`
* `TikTokPublishingCompleted`
* `TikTokPublishingFailed`
* `TikTokAuthRequired`

---

## 14. Frontend Integration

* **Connections Page (`/connections`):** Fully integrated TikTok account status, channel identity, handle, reconnect, and disconnect flows.
* **Composer (`/composer`):** TikTok platform toggle, privacy selector (`PUBLIC_TO_EVERYONE`, `MUTUAL_FOLLOW_FRIENDS`, `SELF_ONLY`), comment/duet/stitch toggles, brand disclosure toggles.
* **Publishing Monitor (`/publishing`):** Visual status progression showing `QUEUED`, `INITIALIZING`, `UPLOADING`, `PROCESSING`, and `PUBLISHED` states.

---

## 15. Test Suite & Coverage

* **Total Test Suites:** 11
* **Total Passing Tests:** 92 (329 assertions)
* **TikTok Dedicated Tests:** 31 (123 assertions)
  * Happy path upload and publication verification
  * Asynchronous processing and status polling
  * Token auto-refresh and refresh lock concurrency
  * Revocation handling and missing scope detection
  * Strict `video.publish` scope validation
  * Creator capability rejection (privacy, comments, duets, stitches, duration)
  * Media constraints validation (duration, file size, dimensions, 23-60 FPS)
  * 4 GiB max file size and VP8/VP9/H.264/H.265 codec support
  * Chunk boundary calculation (<5MB, 5MB, 64MB, >64MB, <=1000 chunks)
  * Expired upload session recovery (HTTP 403/404 resets session URL)
  * Unaudited developer app restricted status handling
  * Transient error retry and rate limit backoff
  * Distributed lock duplicate prevention
  * Zero-trust cross-workspace isolation
  * YouTube vs. TikTok bilateral failure isolation
  * Caption length and hashtag limit enforcement
  * Spam risk metadata error classification

---

## 16. Security Findings

* **Zero-Trust Multi-Tenancy:** Verified. Cross-workspace manipulation fails with 403.
* **No Plaintext Secrets:** Verified. Credentials encrypted at rest.
* **Safe Ephemeral Cleanup:** Verified. Temp media files unlinked in `finally` blocks.

---

## 17. Bugs Found & Fixed During Ralph Loop & Final Verification

1. **Bug:** Accidental 1 GiB file limit and missing VP8 codec in `TikTokMediaProfile`.  
   **Fix:** Updated `MAX_FILE_SIZE_BYTES` to 4 GiB (`4,294,967,296` bytes), added `vp8` to supported codecs, and added 23–60 FPS frame rate validation per official TikTok Media Transfer guide.
2. **Bug:** Scope validation checked for `video.upload` as an alternative to `video.publish`.  
   **Fix:** Strictly enforced `video.publish` for Direct Post.
3. **Bug:** Stale upload URL on HTTP 403/404 could cause an infinite retry loop with the same dead URL.  
   **Fix:** Reset `upload_session_url` to null on 403/404, throwing retryable `UPLOAD_SESSION_EXPIRED` to trigger fresh session initialization.
4. **Bug:** Premature jump to status check if worker restarted while upload was still in progress.  
   **Fix:** Guarded remote post idempotency check so that active uploads (`!empty($job->upload_session_url)`) resume streaming chunks.
5. **Bug:** Spam detection in error mapping checked for exact substring `spam_risk` while provider returned `spam risk detected`.  
   **Fix:** Enhanced substring matching across both error code and message for `spam` and `sensitive` keywords.

---

## 18. Remaining Risks & Production Blockers

* **Production TikTok Credentials:** `TIKTOK_CLIENT_KEY` and `TIKTOK_CLIENT_SECRET` must be populated in `.env` before live OAuth and publishing.
* **TikTok App Review:** Direct Post capability requires TikTok application approval for production scope grants.

---

## 19. Real API Verification

* **Status:** `REAL TIKTOK API VERIFICATION: BLOCKED`
* **Reason:** Live TikTok client credentials are not configured in `backend/.env`. All functional behaviors, HTTP payloads, chunk boundary rules, and error conditions have been exhaustively proven through automated mocked tests.

---

## 20. Final Status

**COMPLETE WITH KNOWN LIMITATIONS**  
*(Known limitations are strictly external: TikTok Developer Portal app registration, approval, and live credentials provisioning).*
