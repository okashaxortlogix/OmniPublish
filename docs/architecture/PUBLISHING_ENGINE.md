# Publishing Engine Architecture

**Module:** `App\Services\PublishingEngine`  
**Namespace:** Backend Core Services  
**Queue:** `publishing`  

---

## 1. Overview

The `PublishingEngine` is the centralized, platform-agnostic orchestrator responsible for executing all social media publishing operations across YouTube, TikTok, and Instagram. It encapsulates distributed locking, tenant authorization, media preparation, platform adapter invocation, progress tracking, heartbeat persistence, failure classification, and automated retry dispatch.

---

## 2. Responsibilities

```text
PublishDestinationJob (queue: publishing)
             │
             ▼
     PublishingEngine::execute($jobId)
             │
     ┌───────┴───────────────────────────────────────┐
     │ 1. Acquire Distributed Lock (660s)           │
     │ 2. Check Terminal & Cancelled State           │
     │ 3. Update Attempts & Transition to VALIDATING │
     │ 4. Preflight Validate Adapter Specs           │
     │ 5. Refresh OAuth Credentials if Needed        │
     │ 6. Transition to UPLOADING                    │
     │ 7. Invoke Platform Publisher Adapter          │
     │    ├── Sync Success ──> PUBLISHED (Terminal)  │
     │    └── Async Polling ─> PROCESSING            │
     │ 8. Handle Failures & Apply Retry Policy       │
     │ 9. Release Lock & Synchronize Batch Status    │
     └───────────────────────────────────────────────┘
```

---

## 3. Distributed Locking & Idempotency

- **Job Level Lock:** Every publishing execution acquires `Cache::lock("publish_lock_job_{$job->id}", 660)`.
- **Lock Duration:** Set to 660 seconds (job timeout of 600s + 60s buffer), preventing competing workers from executing duplicate uploads.
- **Strict Guard:** If a job has already transitioned to `PUBLISHED`, the worker immediately aborts and releases the lock without initiating network traffic.

---

## 4. Polling vs. Immediate Completion

Platform adapters return a standardized `PublishResult`:
- **`requiresPolling: false`**: Upload and processing finished synchronously (e.g., Instagram single photo or short YouTube clip). The engine transitions the job directly to `PUBLISHED`, setting `completed_at` and `remote_post_id`.
- **`requiresPolling: true`**: Upload is complete, but the provider is processing/transcoding remotely (e.g., YouTube processing details or Instagram container status). The job transitions to `PROCESSING` with `remote_post_id` populated, and delayed status checking is scheduled.

---

## 5. Event Dispatching

Every state transition dispatches standardized canonical events under `App\Events\Publishing`:
- `PublishingJobQueued`
- `PublishingJobValidating`
- `PublishingJobStarted`
- `PublishingJobProgress`
- `PublishingJobProcessing`
- `PublishingJobRetrying`
- `PublishingJobCompleted`
- `PublishingJobFailed`
- `PublishingJobAuthRequired`
- `PublishingJobCancelled`
- `PublishingJobReconciled`

Every event contains `workspace_id`, `job_id`, `platform`, `media_id`, `correlation_id`, and `timestamp`, strictly excluding sensitive credentials or access tokens.
