# YouTube Data API v3 — Verified Capabilities & Specification

**API Version**: YouTube Data API v3  
**Verification Date**: October 2, 2026  
**Official Documentation Reference**:  
- https://developers.google.com/youtube/v3/docs/videos/insert  
- https://developers.google.com/youtube/v3/guides/using_resumable_upload_protocol  
- https://developers.google.com/youtube/v3/guides/auth/server-side-web-apps  

---

## 1. Authentication & Scopes
- **Authentication Method**: OAuth 2.0 (Authorization Code Flow with PKCE supported).
- **Token Handling**: Access tokens expire in 3600 seconds (1 hour). Refresh tokens are long-lived unless revoked. Offline access (`access_type=offline` and `prompt=consent`) is required to obtain refresh tokens.
- **Required Scopes**:
  - `https://www.googleapis.com/auth/youtube.upload`: Upload videos and directly publish.
  - `https://www.googleapis.com/auth/youtube.readonly`: Read channel metadata, video status, and audit data.
  - `https://www.googleapis.com/auth/userinfo.profile`: Display connected channel name, avatar, and ID.

---

## 2. Supported Operations
- **Resumable Video Uploads**: Multi-part and chunked resumable upload protocol (`/upload/youtube/v3/videos?uploadType=resumable`). Enables resilient recovery on network interruption.
- **Video Metadata**:
  - `snippet.title`: UTF-8 string, max 100 characters.
  - `snippet.description`: UTF-8 string, max 5,000 characters.
  - `snippet.tags`: List of strings, max total tag length ~500 characters.
  - `snippet.categoryId`: Numeric YouTube category ID (default `22` for People & Blogs, `28` for Science & Technology).
  - `snippet.defaultLanguage`: Optional IETF language tag (e.g. `en`).
- **Status & Visibility**:
  - `status.privacyStatus`: `public`, `unlisted`, `private`.
  - `status.selfDeclaredMadeForKids`: Boolean (mandatory by COPPA regulations).
  - `status.publishAt`: Scheduled publish timestamp in ISO 8601 (only valid when `privacyStatus` is `private`).
- **Custom Thumbnails**: `POST /youtube/v3/thumbnails/set` (Supported if channel is verified with phone number).
- **Processing Status Polling**: `GET /youtube/v3/videos?part=status,processingDetails&id={videoId}` to track transcoding (`processing`, `succeeded`, `failed`).

---

## 3. Unsupported Operations & Non-Goals
- **No Direct Shorts Flag**: YouTube does not have an explicit `is_short=true` boolean parameter. YouTube automatically classifies any vertical video (9:16 or 1:1) under 60 seconds (or 3 minutes for newer standard) as a YouTube Short.
- **No Community Post Publishing** via basic video endpoint.
- **No Automated Copyright Dispute Bypass**.

---

## 4. Account Restrictions & Quota Limits
- **Default Quota**: 10,000 units/day per Google Cloud project.
  - Video Insert cost: **1,600 units per video upload**.
  - Default project quota allows ~6 video uploads per day without an approved quota extension request.
- **Quota Exceeded Error**: HTTP 403 `quotaExceeded`. Must be classified as `RATE_LIMITED` / `QUOTA_EXCEEDED` with clear user messaging.
- **Account Verification**: Unverified YouTube accounts cannot upload videos longer than 15 minutes or set custom thumbnails.

---

## 5. Media Constraints
- **Container Format**: MP4 preferred (also accepts MOV, AVI, WMV, FLV, MKV).
- **Video Codec**: H.264 (AVC) or H.265 (HEVC), high profile.
- **Audio Codec**: AAC-LC, 48kHz or 96kHz, stereo.
- **Aspect Ratio**: 16:9 for standard widescreen; 9:16 for Shorts.
- **Max File Size**: 256 GB (or 12 hours duration, whichever is less).

---

## 6. Implementation Notes & Architecture
- Always use the **Resumable Upload Protocol** for reliability. Initiate session on server, stream file chunks, track byte ranges, and resume if disconnected.
- Persist the remote YouTube video ID (`remoteId: res.data.id`) immediately upon session completion before querying status.
- Implement quota budget tracking in Redis to warn users before hitting the daily 10,000 unit ceiling.
