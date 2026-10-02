# TikTok Content Posting API v2 — Verified Capabilities & Specification

**API Version**: Content Posting API v2 (`https://open.tiktokapis.com/v2/post/publish/`)  
**Verification Date**: October 2, 2026  
**Official Documentation Reference**:  
- https://developers.tiktok.com/doc/content-posting-api-get-started  
- https://developers.tiktok.com/doc/content-posting-api-reference-direct-post  
- https://developers.tiktok.com/doc/content-posting-api-reference-upload-video  

---

## 1. Authentication & Scopes
- **Authentication Method**: OAuth 2.0 (Authorization Code flow with PKCE required).
- **Token Handling**: Access token expires in 86,400 seconds (24 hours). Refresh token is valid for 365 days.
- **Required Scopes**:
  - `video.publish`: Direct publishing of videos to creator's TikTok profile.
  - `video.upload`: Upload video as private draft to creator's inbox/drafts.
  - `user.info.basic`: Read avatar, open_id, and display name.

---

## 2. Supported Operations
- **Direct Video Publishing (`/v2/post/publish/video/init/`)**:
  - Direct post mode (`post_mode: DIRECT_POST`) publishes directly to the user's feed upon passing TikTok automated moderation.
  - Pull from URL mode (`source_info: { source: "PULL_FROM_URL", video_url: "..." }`) or File upload mode (`source_info: { source: "FILE_UPLOAD", video_size: ..., chunk_size: ..., total_chunk_count: ... }`).
- **Post Metadata & Permissions**:
  - `post_info.title`: The video caption and hashtags combined, max **2,200 characters** (recently expanded from 150).
  - `post_info.privacy_level`: `PUBLIC_TO_EVERYONE`, `MUTUAL_FOLLOW_FRIENDS`, `FOLLOWER_OF_CREATOR`, `SELF_ONLY`.
  - `post_info.disable_duet`: Boolean.
  - `post_info.disable_stitch`: Boolean.
  - `post_info.disable_comment`: Boolean.
  - `post_info.video_cover_timestamp_ms`: Number (frame offset in ms for video cover).
  - `post_info.brand_content_toggle`: Boolean (Commercial Content Disclosure).
  - `post_info.brand_organic_toggle`: Boolean.
- **Publish Status Polling (`/v2/post/publish/status/fetch/`)**:
  - Returns `publish_id` status:
    - `PROCESSING_DOWNLOAD`: TikTok is retrieving media.
    - `PROCESSING_UPLOAD`: TikTok is encoding video.
    - `SUCCESS`: Post published successfully.
    - `FAILED`: Failure reason (e.g. invalid format, sound copyright, banned term).

---

## 3. Unsupported Operations & Non-Goals
- **No Standalone Title Field**: TikTok captions include the description and hashtags in a single unified text block (`post_info.title`).
- **No Separate Tag List**: Tags must be embedded as hashtags (`#hashtag`) inside the caption.
- **No Direct Native Scheduling via API**: Scheduled publishing must be orchestrated by OmniPublish's internal BullMQ scheduler and dispatched at execution time.

---

## 4. Account Restrictions & Verification
- **Creator Account vs Business Account**: Both account types are supported under Content Posting API v2.
- **App Review & Audit**: Direct Post requires TikTok Developer App Review approval. In Sandbox/Development mode, posting is limited to designated sandbox test users registered in the developer console.
- **Rate Limits**:
  - Default rate limit: 6 requests per second, 10,000 requests per day per client.
  - Max video uploads per user: typically 5-10 videos per minute depending on account standing.

---

## 5. Media Constraints
- **Container Format**: MP4 or WebM.
- **Resolution**: Min 360p, max 4K (1080x1920 recommended for 9:16 vertical video).
- **Aspect Ratio**: Recommended vertical `9:16` (aspect ratio between 9:16 and 16:9 supported, horizontal will display letterboxed).
- **Duration**: Min 3 seconds, max 10 minutes (600 seconds).
- **File Size**: Max 1 GB for direct API upload (up to 4 GB with pull from URL).
- **Frame Rate**: 23 - 60 fps.

---

## 6. Implementation Notes & Architecture
- TikTok API is strictly asynchronous: after `/v2/post/publish/video/init/`, the endpoint returns a `publish_id`.
- The worker MUST poll `/v2/post/publish/status/fetch/` using an exponential backoff polling strategy (e.g., 5s, 10s, 20s, 30s) up to a max timeout of 5 minutes before declaring `PUBLISHED` or `FAILED`.
- Any TikTok error code `spam_risk_detected` or `video_title_sensitive` must be parsed and presented cleanly to the user.
