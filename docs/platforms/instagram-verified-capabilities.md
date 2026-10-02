# Instagram Graph API (Reels Publishing) — Verified Capabilities & Specification

**API Version**: Meta Graph API v21.0+ (`https://graph.facebook.com/v21.0/`)  
**Verification Date**: October 2, 2026  
**Official Documentation Reference**:  
- https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/ig-user/media  
- https://developers.facebook.com/docs/instagram-platform/instagram-graph-api/reference/ig-user/media_publish  
- https://developers.facebook.com/docs/instagram-platform/content-publishing/  

---

## 1. Authentication & Scopes
- **Authentication Method**: Facebook OAuth 2.0 via Meta App.
- **Account Requirement**: Instagram Professional Account (Business or Creator) linked to a Facebook Page. Personal accounts **cannot** use the Content Publishing API.
- **Token Handling**: Short-lived tokens exchanged for Long-Lived User Access Tokens (valid for 60 days). Refreshable via `GET /oauth/access_token?grant_type=fb_exchange_token`.
- **Required Permissions/Scopes**:
  - `instagram_basic`: Read account ID, username, and profile picture.
  - `instagram_content_publish`: Upload media, create containers, and publish Reels/Feed videos.
  - `pages_show_list` & `pages_read_engagement`: Access linked Facebook Pages to discover connected Instagram accounts.

---

## 2. Supported Operations
Instagram video publishing is a **2-Step Container Flow**:

### Step 1: Create Media Container (`POST /{ig-user-id}/media`)
- Query parameters / payload:
  - `media_type`: `REELS` (for Instagram Reels) or `VIDEO`.
  - `video_url`: Publicly accessible, secure URL where Instagram servers can download the video (or resumable upload protocol).
  - `caption`: String, max **2,200 characters**, up to 30 hashtags.
  - `share_to_feed`: Boolean (`true` to show in both Reels and main profile grid).
  - `cover_url`: Optional public URL for custom video cover image.
  - `thumb_offset`: Millisecond timestamp for cover frame.
  - `audio_name`: Custom audio track name (optional).
- Returns: `{ "id": "<CONTAINER_ID>" }`

### Step 2: Poll Container Status (`GET /{container-id}?fields=status_code,status`)
- Container status lifecycle:
  - `IN_PROGRESS`: Video is downloading and being transcoded by Instagram.
  - `FINISHED`: Container is ready for publishing.
  - `ERROR`: Video processing failed. Returns error description.
  - `EXPIRED`: Container was not published within 24 hours.

### Step 3: Publish Container (`POST /{ig-user-id}/media_publish`)
- Payload: `creation_id: "<CONTAINER_ID>"`
- Returns: `{ "id": "<MEDIA_ID>" }` (The permanent Instagram Media ID).
- Post URL can then be fetched via `GET /{media-id}?fields=permalink`.

---

## 3. Unsupported Operations & Non-Goals
- **No Standalone Title**: Instagram has only a `caption` field. Title cannot be published separately; it must be merged or placed in the caption.
- **No Direct Personal Account Publishing**: API strictly requires Business or Creator accounts.
- **No Direct Native Scheduled Reels via Container API**: OmniPublish internal BullMQ queue handles scheduling timing.
- **Max 30 Hashtags**: Exceeding 30 hashtags causes an immediate API rejection (`OAuthException: 100`).

---

## 4. Account Restrictions & Rate Limits
- **Content Publishing Rate Limit**: Max **50 posts per 24-hour period** per Instagram account.
- **Meta Platform Call Quota**: 200 calls per user per hour sliding window.
- **Meta App Review**: Requires `instagram_content_publish` permission approved by Meta App Review before production publishing is allowed for public users. (Development mode allows app admins and developers).

---

## 5. Media Constraints for Reels
- **Container Format**: MP4 or MOV.
- **Video Codec**: H.264 or HEVC, Progressive scan, Closed GOP, 4:2:0 chroma subsampling.
- **Audio Codec**: AAC, 48kHz sample rate, stereo.
- **Aspect Ratio**: Recommended vertical `9:16` (`1080x1920`). Accepted range: `0.8:1` to `1.91:1`.
- **Duration**: Min 3 seconds, max **15 minutes** (900 seconds) for Reels.
- **Max File Size**: 1 GB.
- **Frame Rate**: 24 - 60 fps.

---

## 6. Implementation Notes & Architecture
- Since Instagram's API pulls media via `video_url`, OmniPublish must provide a secure, short-lived presigned S3/storage URL (valid for at least 30 minutes) for the container creation step.
- Container status check polling must be implemented in the worker with safe intervals (5s, 10s, 15s) with a 5-minute timeout.
- Meta errors often come in nested structures:
  `{ "error": { "message": "...", "type": "OAuthException", "code": 190, "error_subcode": 463 } }`
  Subcode 463 indicates expired token, which our error classifier maps directly to `AUTH_REQUIRED`.
