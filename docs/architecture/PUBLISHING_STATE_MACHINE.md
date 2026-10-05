# Publishing State Machine Specification

**Model:** `App\Models\PublishJob`  
**Standard:** Phase 3D Canonical State Machine  

---

## 1. Canonical State Flow

```text
       ┌───────────┐
       │   DRAFT   │
       └─────┬─────┘
             │
             ▼
       ┌───────────┐         ┌───────────┐
       │  QUEUED   ├────────>│ SCHEDULED │
       └─────┬─────┘         └─────┬─────┘
             │                     │ (Due time reached)
             ▼                     ▼
       ┌─────────────────────────────┐
       │         VALIDATING          │
       └─────────────┬───────────────┘
                     │
                     ▼
       ┌─────────────────────────────┐
       │          UPLOADING          │
       └──────┬───────────────┬──────┘
              │               │
              │ (Sync)        │ (Async Polling)
              ▼               ▼
       ┌───────────┐   ┌─────────────┐
       │           │   │ PROCESSING  │
       │           │   └──────┬──────┘
       │           │          │ (Finished)
       │           │◄─────────┘
       │ PUBLISHED │
       │ (TERMINAL)│
       └───────────┘
```

### Failure Branches

```text
                      Any Active State
                             │
     ┌───────────────────────┼───────────────────────┐
     ▼                       ▼                       ▼
┌───────────┐         ┌─────────────┐         ┌───────────┐
│ RETRYING  │         │    FAILED   │         │   AUTH_   │
│           │         │  (TERMINAL) │         │ REQUIRED  │
└─────┬─────┘         └─────────────┘         └─────┬─────┘
      │                                             │
      │ (Delayed Redispatch)                        │ (User Reconnects)
      ▼                                             ▼
┌───────────┐                                 ┌───────────┐
│  QUEUED   │                                 │  QUEUED   │
└───────────┘                                 └───────────┘
```

---

## 2. Transition Matrix Table

| From State | Permitted Target States |
| :--- | :--- |
| **DRAFT** | `QUEUED`, `SCHEDULED`, `CANCELLED` |
| **QUEUED** | `SCHEDULED`, `VALIDATING`, `UPLOADING`, `PROCESSING`, `PUBLISHED`, `CANCELLED`, `FAILED`, `AUTH_REQUIRED` |
| **SCHEDULED** | `QUEUED`, `VALIDATING`, `CANCELLED` |
| **VALIDATING** | `UPLOADING`, `PROCESSING`, `PUBLISHED`, `FAILED`, `AUTH_REQUIRED`, `CANCELLED` |
| **UPLOADING** | `PROCESSING`, `PUBLISHED`, `RETRYING`, `FAILED`, `AUTH_REQUIRED`, `CANCELLED` |
| **PROCESSING** | `PUBLISHED`, `RETRYING`, `FAILED`, `AUTH_REQUIRED`, `CANCELLED` |
| **RETRYING** | `QUEUED`, `VALIDATING`, `UPLOADING`, `PROCESSING`, `PUBLISHED`, `FAILED`, `AUTH_REQUIRED`, `CANCELLED` |
| **AUTH_REQUIRED** | `QUEUED`, `VALIDATING` |
| **FAILED** | `QUEUED`, `VALIDATING` (via explicit manual retry) |
| **CANCELLED** | `QUEUED` (via re-submit) |
| **PUBLISHED** | *None* (Strictly Terminal and Immutable) |

---

## 3. Strict State Enforcement Rules

1. **Non-Optimistic PUBLISHED Rule:** Transitioning to `PUBLISHED` without a confirmed `remote_post_id` throws a `DomainException`. It is physically impossible for the application to mark a job published on assumptions.
2. **Normalized Failure Category Rule:** Every transition to `FAILED` requires a valid, normalized failure category. Unclassified errors default strictly to `UNKNOWN`.
3. **Automatic Timestamps:**
   - Transitioning to `VALIDATING` or `UPLOADING` sets `started_at` if previously null.
   - Active execution (`VALIDATING`, `UPLOADING`, `PROCESSING`) updates `last_heartbeat_at`.
   - Terminal transitions (`PUBLISHED`, `FAILED`, `CANCELLED`) automatically persist `completed_at = now()`.
4. **Auth Required Isolation:** `AUTH_REQUIRED` stops automatic queue retries immediately to protect against infinite provider authentication spam.
