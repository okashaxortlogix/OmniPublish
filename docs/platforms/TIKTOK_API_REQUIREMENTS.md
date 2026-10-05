# TikTok Content Posting API — Direct Post Requirements & Specification

**Date of Verification:** October 2026 (API Version: v2)  
**Authoritative Source:** TikTok for Developers Official Documentation (`developers.tiktok.com`)  
**Product:** Content Posting API — Direct Post (`/v2/post/publish/`)

---

## 1. Developer Application & Product Setup

To use the Direct Post API, an application must be registered and approved in the TikTok Developer Portal:

1. **Application Registration:**
   * Create an application in the TikTok for Developers portal.
   * Obtain `Client Key` (corresponds to client ID) and `Client Secret`.
2. **Product Activation:**
   * Request and add the **Content Posting API** product to the application.
3. **Application Approval Requirements:**
   * Direct Post requires elevated review and approval by TikTok before production deployment.
   * Apps in development/sandbox mode can only publish to pre-registered creator test accounts (Sandbox accounts).
   * Direct post capability is granted after product capability review and privacy policy / term submission.
4. **Unaudited Application Behavior (EXTERNAL PRODUCTION REQUIREMENT):**
   * TikTok documentation explicitly notes that videos published by applications that have not yet passed TikTok's official security/product audit are automatically restricted to private viewing (`SELF_ONLY` / creator only) or rejected by moderation.
   * This is categorized as an **External Production Requirement**, not an application or platform bug. OmniPublish does not circumvent or bypass this provider restriction.

---

## 2. OAuth 2.0 Specifications & Security

* **Authorization Base URL:** `https://www.tiktok.com/v2/auth/authorize/`
* **Token Base URL:** `https://open.tiktokapis.com/v2/oauth/token/`
* **Revocation Base URL:** `https://open.tiktokapis.com/v2/oauth/revoke/`
* **Grant Type:** `authorization_code` with PKCE (`code_challenge` / `code_verifier` with `S256`).

### Scope Semantics & Requirements
* `video.publish`: **MANDATORY** for Direct Post video publishing (`/v2/post/publish/video/init/`). The platform adapter strictly requires this scope before allowing publication.
* `user.info.basic`: Required for reading creator handle, display name, and avatar details for tenant display.
* `video.upload`: Optional supplementary scope for legacy video upload endpoints. It does not replace `video.publish` for Direct Post.

### Token Characteristics
* **Access Token Lifetime:** ~86,400 seconds (24 hours).
* **Refresh Token Lifetime:** ~31,536,000 seconds (365 days) or until explicitly revoked.
* **Refresh Mechanism:** `POST https://open.tiktokapis.com/v2/oauth/token/` with `grant_type=refresh_token`. Refresh rotation replaces the refresh token; applications must atomically persist both the new access token and new refresh token. Concurrency is guarded via distributed Redis/database locks (`token_refresh_lock_{account_id}`, TTL 20s).

---

## 3. Creator Capabilities & Information API

Before initializing a direct post, the client queries creator capabilities fresh on every publish run to ensure compliance with real-time creator settings and restrictions.

* **Endpoint:** `POST https://open.tiktokapis.com/v2/post/publish/creator_info/query/`
* **Headers:** `Authorization: Bearer <access_token>`, `Content-Type: application/json; charset=UTF-8`

### Authoritative Response Fields
* `creator_username`: Handle of the creator (e.g. `creator_username`).
* `creator_nickname`: Display name of the creator.
* `privacy_level_options`: Array of permitted privacy settings for this creator (e.g. `['PUBLIC_TO_EVERYONE', 'MUTUAL_FOLLOW_FRIENDS', 'FOLLOWER_OF_CREATOR', 'SELF_ONLY']`).
* `comment_disabled`: Boolean flag indicating if comments are disabled on the creator account.
* `duet_disabled`: Boolean flag indicating if Duets are disabled.
* `stitch_disabled`: Boolean flag indicating if Stitches are disabled.
* `max_video_post_duration_sec`: Integer maximum duration allowed for this creator (typically 600s, up to 1800s for select partners).

**Architectural Rule:** The application must strictly validate requested user options against this creator response. If a requested privacy level or interaction is rejected by the creator's configuration, the job fails immediately with `CREATOR_CAPABILITY_ERROR` prior to media upload. Capabilities are never cached indefinitely across posts.

---

## 4. Direct Post Protocol & Upload Flow

TikTok Content Posting API uses a 3-stage protocol:

```text
[1. Init] POST /v2/post/publish/video/init/
    ├── video_size: total file size in bytes
    ├── chunk_size: chunk size in bytes (min 5MB, max 64MB)
    ├── total_chunk_count: ceil(video_size / chunk_size) <= 1000
    └── post_info: caption, privacy_level, disable_comment, etc.
    └── Returns: publish_id, upload_url

[2. Binary Chunk Upload] PUT <upload_url>
    ├── Header: Content-Range: bytes {start}-{end}/{total_size}
    ├── Header: Content-Type: video/mp4
    └── Stream raw bytes for each chunk sequentially

[3. Asynchronous Status Verification] POST /v2/post/publish/status/fetch/
    ├── publish_id: <publish_id>
    └── Returns: status (PROCESSING_UPLOAD, PROCESSING_DOWNLOAD, PUBLISH_COMPLETE, FAILED)
```

### Chunk Constraints & Boundaries
* **Files <= 64 MiB (67,108,864 bytes):** Uploaded in a single chunk equal to the total file size (`total_chunk_count = 1`).
* **Files > 64 MiB:** Chunk size is selected between 5 MiB (5,242,880 bytes) and 64 MiB (67,108,864 bytes) such that `total_chunk_count <= 1000`.
* **Maximum Chunks:** `total_chunk_count` must never exceed 1,000 chunks.
* **Upload URL Expiry:** If the upload URL returns HTTP 403 or 404 (`upload_task_not_found`), the adapter resets `upload_session_url` to null and classifies the error as `UPLOAD_SESSION_EXPIRED` (retryable), triggering clean re-initialization rather than an infinite loop.

---

## 5. Media Asset Constraints & Validation Profile

* **Containers / Extensions:** `.mp4`, `.webm`, `.mov`.
* **MIME Types:** `video/mp4`, `video/webm`, `video/quicktime`.
* **Video Codecs:** H.264 (AVC/avc1), H.265 (HEVC/hvc1), VP8, VP9.
* **Audio Codecs:** AAC, MP3, Opus.
* **Resolution / Dimensions:**
  * Minimum: 360 x 360 px (neither dimension may be below 360px).
  * Maximum: 4096 x 4096 px.
* **Frame Rate:** 23 to 60 FPS (per official TikTok Media Transfer guide).
* **Aspect Ratios Supported:** 9:16 (standard vertical), 1:1 (square), 16:9 (horizontal).
* **Duration Limits:**
  * Minimum: 3.0 seconds.
  * Maximum: Creator-specific (`max_video_post_duration_sec` from creator query; default 600.0s).
* **File Size:**
  * Minimum: 1,000 bytes.
  * Maximum: 4 GiB (4,294,967,296 bytes) for Direct Post file upload.

---

## 6. Rate Limiting & Error Semantics

* **Rate Limits:** TikTok enforces per-developer app and per-creator posting quotas. Exceeding limits returns HTTP 429 or error code `rate_limit_exceeded`.
* **Retryable Errors:**
  * Network timeouts / connection resets (`NETWORK_ERROR`).
  * HTTP 500, 502, 503, 504 (`REMOTE_SERVER_ERROR`).
  * HTTP 429 / `rate_limit_exceeded` (`RATE_LIMITED`).
  * Stale upload session (`UPLOAD_SESSION_EXPIRED`).
* **Non-Retryable Errors:**
  * Expired / revoked OAuth credentials requiring user re-auth (`AUTH_REQUIRED`).
  * Missing permissions or lack of `video.publish` scope (`AUTH_REQUIRED`).
  * Unsupported privacy or interaction options (`CREATOR_CAPABILITY_ERROR`).
  * Invalid dimensions, duration, corrupt media, or file exceeding 4GB (`INVALID_MEDIA`).
  * Spam risk detection or sensitive keywords (`INVALID_METADATA`).
