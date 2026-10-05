# Reconciliation & Stalled Job Recovery Specification

**Artisan Commands:**
- `publishing:reconcile` (Every 5 minutes)
- `publishing:reap-stalled` (Every 10 minutes)

---

## 1. Reconciliation Overview

Asynchronous publishing pipelines across social networks introduce the risk of split-brain states where the remote platform has successfully published or failed a video, but network drops, worker crashes, or container expirations prevented the local database from recording the terminal state.

The OmniPublish Reconciliation Engine periodically reconciles these states using platform-native `getStatus()` queries rather than blindly re-executing uploads.

---

## 2. Reconciliation Lifecycle

```text
Every 5 Minutes: publishing:reconcile
       │
       ├─► Scan Jobs in PROCESSING with remote_post_id
       │     │
       │     ├─► Query $adapter->getStatus($remoteId)
       │     │     ├─► Remote PUBLISHED ──> Local PUBLISHED (Sync Completed)
       │     │     ├─► Remote FAILED    ──> Local FAILED (With Provider Reason)
       │     │     └─► Remote PENDING   ──> Refresh Heartbeat
       │
       └─► Scan Jobs in RETRYING with elapsed retry_at
             │
             └─► Transition to QUEUED & Dispatch PublishDestinationJob
```

---

## 3. Stalled Job Reaping Lifecycle

```text
Every 10 Minutes: publishing:reap-stalled
       │
       ▼
Query Jobs in VALIDATING, UPLOADING, PROCESSING where:
(last_heartbeat_at < NOW - 15m OR last_attempt_at < NOW - 15m)
       │
       ├─► attempts_count < max_attempts
       │     │
       │     └─► Transition to RETRYING (Category: TIMEOUT)
       │         Dispatch delayed retry (15s backoff)
       │
       └─► attempts_count >= max_attempts
             │
             └─► Transition to FAILED (Category: TIMEOUT)
                 User-facing: "Publishing process stalled and exceeded maximum execution timeout."
```

---

## 4. Idempotency Guarantees Across Crash Scenarios

| Crash Scenario | State at Crash | Reconciliation Action | Duplicate Risk |
| :--- | :--- | :--- | :---: |
| **Worker crash before publish call** | `VALIDATING` | Worker lock expires; job reaped after 15m or manually retried; uploads fresh | **0%** |
| **Worker crash during upload chunk** | `UPLOADING` | Resumable upload session URL inspected; resumed from last byte or restarted | **0%** |
| **Worker crash after provider success** | `PROCESSING` | `getStatus()` called on remote ID; finds video published; transitions to `PUBLISHED` | **0%** |
| **Duplicate queue job delivery** | `QUEUED` | Distributed lock `publish_lock_job_{id}` prevents concurrent execution | **0%** |
| **Scheduler process running concurrently**| `SCHEDULED` | `scheduler_dispatch_lock` acquired by 1st instance; 2nd instance skips | **0%** |
| **Application restart during publish** | `PROCESSING` | Container reconciled by `publishing:reconcile` upon restart | **0%** |
