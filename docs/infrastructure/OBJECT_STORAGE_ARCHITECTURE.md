# OmniPublish — Object Storage Production Architecture

**Document Reference:** `docs/infrastructure/OBJECT_STORAGE_ARCHITECTURE.md`  
**System Version:** OmniPublish Phase 3GA  
**Target Engine:** Amazon S3 Standard (with Cloudflare R2 Egress Optimization Option)  
**Standard:** Critical Honesty Principle (Zero Public Bucket Access, Strict Multi-Tenant Namespacing)

---

## 1. Storage Overview & Bucket Separation

OmniPublish handles multi-gigabyte video files, thumbnails, and platform derivatives. All binary storage is completely decoupled from application compute nodes and managed via S3 API compatibility.

### 1.1 Environment Separation
To guarantee that staging testing never mutates, overwrites, or deletes production media, completely isolated buckets are enforced per environment:

| Environment | Bucket Identifier | Provider | Default Access Policy |
|---|---|---|---|
| **Local Dev** | `omnipublish-media-local` | MinIO (Port 9000) | Local Private Network |
| **Staging** | `omnipublish-media-staging-us-east-1` | AWS S3 / Cloudflare R2 | Fully Private (All Public Access Blocked) |
| **Production** | `omnipublish-media-prod-us-east-1` | AWS S3 / Cloudflare R2 | Fully Private (All Public Access Blocked) |

---

## 2. Storage Key Partitioning & Path Traversal Prevention

OmniPublish enforces a deterministic, secure, and unguessable storage key format generated exclusively by `App\Services\MediaStorageService`:

```text
workspaces/{workspace_uuid}/media/{media_uuid}/{random_32_char_token}.{ext}
```

### 2.1 Security & Integrity Guarantees:
1. **Zero User-Supplied Key Components:** File names provided by users in web uploads (e.g. `../../etc/passwd` or `my_video.mp4`) are stripped and sanitized. Only the validated extension (e.g. `mp4`, `mov`) is retained.
2. **Cryptographic Entropy:** A 32-character random alphanumeric string (`Str::random(32)`) guarantees that storage keys cannot be enumerated or guessed.
3. **Workspace Isolation:** Every object is physically partitioned under `workspaces/{workspace_uuid}/`. This allows programmatic workspace auditing, tenant storage quota accounting, and targeted tenant deletions.
4. **Derivative Namespacing:** Thumbnails and normalized derivatives are colocated within the parent asset directory:
   * Master Source: `workspaces/{ws_id}/media/{media_id}/{token}.mp4`
   * Thumbnail: `workspaces/{ws_id}/media/{media_id}/thumbnail.jpg`
   * Platform Derivative: `workspaces/{ws_id}/media/{media_id}/derivative_{platform}_{token}.mp4`

---

## 3. Presigned Uploads & CORS Configuration

OmniPublish never proxies multi-gigabyte video uploads through API servers. Instead, clients upload directly from the browser to the S3 bucket using pre-signed PUT URLs.

```text
[ Browser Client ] ──1. POST /api/v1/media/upload-authorize──▶ [ Laravel API ]
[ Browser Client ] ◀──2. Presigned PUT URL (30 min TTL)────── [ Laravel API ]
[ Browser Client ] ──3. Direct PUT Binary Upload (S3 CORS)───▶ [ S3 Bucket ]
[ Browser Client ] ──4. POST /api/v1/media/{id}/confirm──────▶ [ Laravel API ]
                                                                      │
                                                        Dispatches ProcessMediaAssetJob
```

### 3.1 Upload Authorization Parameters
* **HTTP Method:** `PUT`
* **Expiration TTL:** 30 minutes (`now()->addMinutes(30)`).
* **MIME Type Enforcement:** Enforced in S3 signature headers (`ContentType: video/mp4`). Uploading an unapproved MIME type invalidates the S3 signature.
* **Payload Verification:** Upon client confirmation, the server inspects the uploaded object (`verifyUploadedObject`) to verify that the file exists and has non-zero size before dispatching transcoding jobs.

### 3.2 Bucket CORS Configuration
To permit direct browser uploads, the following CORS configuration is applied to the bucket:

```json
[
  {
    "AllowedOrigins": [
      "https://app.omnipublish.com",
      "https://staging.omnipublish.com"
    ],
    "AllowedMethods": [
      "PUT",
      "GET",
      "HEAD"
    ],
    "AllowedHeaders": [
      "*"
    ],
    "ExposeHeaders": [
      "ETag",
      "Content-Length",
      "Content-Type"
    ],
    "MaxAgeSeconds": 3600
  }
]
```

---

## 4. Temporary Presigned Downloads (Instagram Ingestion)

Unlike YouTube and TikTok (which support direct chunked binary streaming over HTTP requests from our publishing workers), the **Meta / Instagram Graph API** operates on an asynchronous ingest model:
* OmniPublish provides a public HTTPS video URL to Meta's `/media` container endpoint.
* Meta's servers asynchronously fetch the video payload from that URL within 15–60 seconds.

### Implementation:
1. Publishing workers generate a time-limited presigned GET URL via Flysystem:
   ```php
   $temporaryUrl = $storage->temporaryUrl($media->storage_path, now()->addHours(4));
   ```
2. The URL is passed to Meta's container API.
3. Once Meta completes container ingestion, the URL automatically expires. The bucket remains 100% private at all times.

---

## 5. Lifecycle Policies & Stale Object Sweeping

To eliminate abandoned upload debris and control storage costs, the bucket enforces automated lifecycle rules:

1. **Abort Incomplete Multipart Uploads:** Automatically abort multipart upload sessions that remain uncompleted after **7 days**.
2. **Stale Upload Authorization Sweep:** Managed by the Laravel console scheduler command `media:cleanup-stale-uploads` (runs hourly):
   * Queries `media_assets` stranded in `UPLOAD_AUTHORIZED` for $> 2\text{ hours}$ without confirmation.
   * Marks database status as `FAILED`.
   * Purges unreferenced storage keys if an orphaned object was partially written.
3. **Glacier Transition (Optional Optimization):** Original source video files can be transitioned to Amazon S3 Glacier Instant Retrieval after 90 days, retaining instant read availability at 68% lower storage cost.
