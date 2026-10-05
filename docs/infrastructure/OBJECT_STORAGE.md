# S3-Compatible Object Storage Architecture

**OmniPublish Media Storage & Distribution Guide**  
**Version:** 1.0 (Phase 1 Migration)  
**Supported Providers:** AWS S3, Cloudflare R2, MinIO, DigitalOcean Spaces, Wasabi

---

## 1. Overview & Provider Independence

OmniPublish decouples business logic from physical cloud storage via Laravel's Filesystem abstraction and the `App\Services\Contracts\MediaStorageInterface` service contract.

The system supports seamless switching across any S3-compatible object storage provider purely through configuration variables without changing application code:

```text
               +-----------------------------+
               |    OmniPublish Backend      |
               | (MediaStorageInterface API) |
               +--------------+--------------+
                              |
                 Filesystem Adapter Abstraction
                              |
        +---------------------+---------------------+
        |                     |                     |
   AWS S3 Standard     Cloudflare R2          MinIO Local
  (Production S3)     (Zero Egress Fee)   (Testing / Docker)
```

---

## 2. Supported Provider Configurations

### 2.1 AWS S3 (Production Primary)
```ini
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=AKIAIOSFODNN7EXAMPLE
AWS_SECRET_ACCESS_KEY=wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=omnipublish-media-prod
AWS_USE_PATH_STYLE_ENDPOINT=false
```

### 2.2 Cloudflare R2 (High-Egress / Cost-Optimized Video Delivery)
Cloudflare R2 eliminates S3 bandwidth egress charges, making it ideal for high-resolution video pipelines:
```ini
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=cf_r2_token_id
AWS_SECRET_ACCESS_KEY=cf_r2_secret_key
AWS_DEFAULT_REGION=auto
AWS_BUCKET=omnipublish-media
AWS_ENDPOINT=https://<account_id>.r2.cloudflarestorage.com
AWS_USE_PATH_STYLE_ENDPOINT=false
```

### 2.3 MinIO (Local Containerized S3 Emulation)
```ini
FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=minioadmin
AWS_SECRET_ACCESS_KEY=minioadmin
AWS_DEFAULT_REGION=us-east-1
AWS_BUCKET=omnipublish-media
AWS_ENDPOINT=http://127.0.0.1:9000
AWS_USE_PATH_STYLE_ENDPOINT=true
```

---

## 3. Storage Key Partitioning & Multi-Tenant Namespace

Every object stored in OmniPublish adheres to a strict, non-predictable, tenant-partitioned naming scheme:

```text
workspaces/{workspace_uuid}/media/{media_uuid}/{random_token}.{ext}
```

### Security Properties:
1. **No User-Supplied Names in Storage Keys:** Prevents directory traversal attacks (`../../`) and malicious file naming.
2. **Tenant Scoping:** The `workspace_uuid` partition allows granular bucket policy replication, tenant lifecycle rules, and per-tenant storage usage accounting.
3. **Entropy:** The 32-character random token prevents enumeration or timing attacks.

---

## 4. S3 CORS Configuration for Direct Browser Uploads

To allow browsers to stream multi-gigabyte video files directly to the S3/R2 bucket via presigned PUT URLs, configure the following CORS rules on the target bucket:

```json
[
  {
    "AllowedHeaders": [
      "*"
    ],
    "AllowedMethods": [
      "GET",
      "PUT",
      "HEAD"
    ],
    "AllowedOrigins": [
      "https://app.omnipublish.com",
      "http://localhost:3000"
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

## 5. Security & Secret Protection Guarantees
- S3 access keys and secret keys reside strictly in backend server environment variables.
- Direct upload authorizations use time-limited presigned URLs (30-minute expiration).
- Browsers never receive raw cloud credentials or access to bucket-level operations.
- Backend verification inspects byte length and MIME headers before marking any asset `READY`.
