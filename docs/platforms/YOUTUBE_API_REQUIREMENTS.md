# YouTube API Integration & Resumable Upload Requirements

## 1. Overview & Official Specifications

This document defines the official Google YouTube Data API v3 requirements, OAuth scopes, and resumable upload protocol implemented in OmniPublish Phase 3A.

**Official References:**
- [Google YouTube Data API v3: Videos: insert](https://developers.google.com/youtube/v3/docs/videos/insert)
- [Google API Resumable Upload Protocol](https://developers.google.com/youtube/v3/guides/using_resumable_upload_protocol)
- [Google Identity: OAuth 2.0 for Web Server Applications](https://developers.google.com/identity/protocols/oauth2/web-server)

---

## 2. OAuth Scopes & Authentication

### 2.1 Required Scopes
To support video ingestion, channel sync, and publishing status checks, OmniPublish requests:
- `https://www.googleapis.com/auth/youtube.upload` *(Mandatory for `videos.insert`)*
- `https://www.googleapis.com/auth/youtube.readonly` *(Mandatory for channel sync & metadata)*
- `https://www.googleapis.com/auth/userinfo.profile` *(User identification)*
- `openid` *(Standard identity confirmation)*

### 2.2 Token Lifecycle
- **Access Token:** Short-lived JWT bearer token (typically expires in 3,599 seconds).
- **Refresh Token:** Issued once during initial user consent when `access_type=offline` and `prompt=consent` are specified. Stored encrypted at rest using AES-256-CBC via Laravel model attribute encryption.
- **Token Refresh Endpoint:** `POST https://oauth2.googleapis.com/token`
- **Revocation / Invalidation:** If the refresh token is revoked or invalidated by the user, Google returns HTTP 400 with `error: "invalid_grant"`. The system must detect this and immediately transition the connected account and publishing job to `AUTH_REQUIRED`.

---

## 3. Resumable Upload Protocol (`videos.insert`)

Resumable uploads are required for all video uploads in OmniPublish to prevent memory exhaustion, allow network interruption recovery, and handle large media assets reliably.

### 3.1 Step 1: Session Initiation
The publishing worker initiates the upload session by sending video metadata to the resumable endpoint:
```http
POST https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&part=snippet,status HTTP/1.1
Host: www.googleapis.com
Authorization: Bearer <ACCESS_TOKEN>
Content-Length: <METADATA_JSON_BYTES>
Content-Type: application/json; charset=UTF-8
X-Upload-Content-Length: <TOTAL_VIDEO_SIZE_BYTES>
X-Upload-Content-Type: video/mp4

{
  "snippet": {
    "title": "Title (max 100 chars)",
    "description": "Description (max 5000 chars)",
    "tags": ["tag1", "tag2"],
    "categoryId": "22"
  },
  "status": {
    "privacyStatus": "public",
    "selfDeclaredMadeForKids": false
  }
}
```

**Expected Response:**
- HTTP `200 OK`
- Header: `Location: https://www.googleapis.com/upload/youtube/v3/videos?uploadType=resumable&upload_id=<SESSION_ID>`
- The `Location` header is the unique **Resumable Session URI**.

### 3.2 Step 2: Chunked Upload Stream
The worker streams video bytes in discrete chunks.
- **Chunk Size Constraint:** Google requires every chunk (except the final chunk) to be a multiple of **256 KiB** (`262,144` bytes).
- **Recommended Chunk Size:** OmniPublish uses **8 MiB** (`8,388,608` bytes, which is 32 × 256 KiB) to balance memory usage, HTTP overhead, and worker I/O performance.

**Chunk Upload Request:**
```http
PUT <RESUMABLE_SESSION_URI> HTTP/1.1
Content-Length: <CHUNK_SIZE_BYTES>
Content-Range: bytes <START_BYTE>-<END_BYTE>/<TOTAL_SIZE>

<BINARY_CHUNK_BYTES>
```

**Chunk Upload Responses:**
1. **HTTP `308 Resume Incomplete`** (Chunk acknowledged, more chunks expected):
   - Response header: `Range: bytes=0-<LAST_ACKED_BYTE>`
   - Next chunk begins at `<LAST_ACKED_BYTE> + 1`.
2. **HTTP `200 OK` or `201 Created`** (Upload completed):
   - Response body contains the YouTube Video JSON resource with `id`.
   - The remote video URL is `https://www.youtube.com/watch?v={id}`.

### 3.3 Step 3: Resuming Interrupted Uploads
If a network disconnect, HTTP 5xx, or worker termination occurs mid-upload, the worker queries the upload status without sending a payload:
```http
PUT <RESUMABLE_SESSION_URI> HTTP/1.1
Content-Length: 0
Content-Range: bytes */<TOTAL_SIZE>
```

**Recovery Status Evaluation:**
- **HTTP `308 Resume Incomplete`** with `Range: bytes=0-<LAST_BYTE>`:
  - The worker resumes uploading starting from `<LAST_BYTE> + 1`.
- **HTTP `308 Resume Incomplete`** without `Range` header:
  - Zero bytes were saved by Google; start from byte `0`.
- **HTTP `200 OK` or `201 Created`**:
  - The upload finished right before the crash; extract the `id` from the body.
- **HTTP `404 Not Found` or `410 Gone`**:
  - The upload session expired (Google retains active sessions for ~24 hours). The session must be restarted from Step 1.

---

## 4. Quota and Verification Restrictions

### 4.1 Daily Quota Budget & Cost Structure
- **Default Project Allocation:** Projects registered in Google Cloud Console receive a default allocation of **10,000 quota units per day**, resetting daily at midnight Pacific Time (PT).
- **Operation Costs:**
  - `videos.insert` (resumable upload): **1,600 units** per call.
  - `videos.list` (processing status / metadata query): **1 unit** per call.
  - `channels.list` (channel info query): **1 unit** per call.
- **Quota Consumption:** The number of video uploads achievable within a project's daily quota depends on the overall distribution of API calls (inserts, lists, channel lookups) executed across all connected channels.
- **Quota Increases:** Applications requiring capacity beyond the standard allocation can apply for an API Quota Extension through the Google Cloud Console Quota Management interface. No artificial limit is hard-coded into OmniPublish.

### 4.2 API Project Verification & Unverified Constraints
- Projects not verified through Google Cloud Trust & Safety are limited:
  - Uploaded videos are forced to `private`.
  - Accounts are restricted to a total of 50 uploaded videos across the project.
- Production deployment requires verified domain ownership, privacy policy, terms of service, and an approved YouTube API Audit.

---

## 5. Normalized Error Mapping

| Google API Error / Condition | OmniPublish Category | Retryable | User Action |
|---|---|---|---|
| HTTP 401 `invalid_token` | `AUTHENTICATION_ERROR` | Auto-refresh | Automatic refresh attempt |
| HTTP 400 `invalid_grant` | `AUTH_REQUIRED` | No | Reconnect Google account |
| HTTP 403 `quotaExceeded` | `QUOTA_EXCEEDED` | Yes (daily reset) | Wait for daily quota reset |
| HTTP 403 `uploadLimitExceeded` | `RATE_LIMITED` | Yes | Backoff and retry later |
| HTTP 400 Invalid title / snippet | `INVALID_METADATA` | No | Fix title/metadata in Composer |
| HTTP 500 / 502 / 503 / 504 | `REMOTE_SERVER_ERROR`| Yes (exp backoff) | Automatic worker retry |
| Connection reset / timeout | `NETWORK_ERROR` | Yes (resume session) | Resume from last acknowledged byte |
