# Direct Media Upload Pipeline & Backend Verification

**OmniPublish Asynchronous Media Ingestion Pipeline**  
**Version:** 1.0 (Phase 1 Migration)  
**Lifecycle States:** `UPLOAD_AUTHORIZED` -> `PROCESSING` -> `READY` / `FAILED` / `DELETED`

---

## 1. End-to-End Direct Upload Sequence

```text
Browser / Client               OmniPublish API                 S3 / R2 Object Storage
       |                              |                                  |
       | 1. POST /media/upload-authorization                             |
       |    (filename, size, mime)    |                                  |
       |----------------------------->|                                  |
       |                              | 2. Scopes workspace & tenancy    |
       |                              | 3. Generates UUID & secure key   |
       |                              | 4. Creates MediaAsset:           |
       |                              |    status: UPLOAD_AUTHORIZED     |
       |                              | 5. Signs temporary upload URL    |
       | 6. Returns upload URL & ID   |                                  |
       |<-----------------------------|                                  |
       |                                                                 |
       | 7. PUT /workspaces/.../video.mp4 (Direct Stream)                |
       |---------------------------------------------------------------->|
       | 8. 200 OK (Upload Finished)                                     |
       |<----------------------------------------------------------------|
       |                                                                 |
       | 9. POST /media/confirm-upload                                   |
       |    (media_id, workspace_id)  |                                  |
       |----------------------------->|                                  |
       |                              | 10. Validates tenant ownership   |
       |                              | 11. HEAD Object on S3/Disk       |
       |                              |     (Verifies existence, bytes)  |
       |                              |--------------------------------->|
       |                              | 12. Object validated             |
       |                              |<---------------------------------|
       |                              | 13. Transitions status: READY    |
       |                              | 14. Logs AuditEvent              |
       | 15. Returns verified asset   |                                  |
       |<-----------------------------|                                  |
```

---

## 2. Zero-Trust Backend Verification

Clients cannot simply notify the API that an upload finished and expect the server to trust them. The backend executes rigorous verification in `MediaController::confirmUpload`:

1. **Workspace Ownership Match:** Verifies the requesting tenant owns the target `media_id`. Cross-tenant confirmations are blocked with `403 Forbidden`.
2. **Physical Object Existence:** Invokes `Storage::disk()->exists($storagePath)` to verify the payload is present in object storage.
3. **Payload Size Validation:** Verifies actual byte size in storage (`size > 0`). Empty or aborted uploads are flagged as `FAILED` with `422 Unprocessable Entity`.
4. **MIME Integrity:** Inspects storage MIME classification against allowed video profiles (`video/mp4`, `video/quicktime`, `video/webm`, `video/x-matroska`).
5. **Audit Logging:** Every transition is recorded in the immutable `audit_events` ledger.

---

## 3. Lifecycle States & Finite State Machine

```text
[Initiated]
    │
    ▼
UPLOAD_AUTHORIZED ──(Timeout >2 hrs)──► FAILED (Stale Cleanup)
    │
    ▼ (Client uploads to storage)
    │
    ▼ (POST /media/confirm-upload)
    ├──(Object Missing / Size 0)──────► FAILED (Verification Error)
    │
    ▼ (Object Verified)
  READY
    │
    ▼ (DELETE /media/{id})
 DELETED (Physical Storage Purged)
```

---

## 4. Stale Upload Expiration & Automatic Cleanup

If a client requests upload authorization but drops connection or closes their browser before uploading, the `MediaAsset` remains in `UPLOAD_AUTHORIZED`.

The OmniPublish scheduler executes `media:cleanup-stale-uploads` hourly:
```bash
php artisan media:cleanup-stale-uploads
```
Any upload authorization older than 2 hours is automatically transitioned to `FAILED`, preventing pending records from cluttering the tenant's media dashboard.
