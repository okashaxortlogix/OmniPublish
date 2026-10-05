# OmniPublish — Staging Capacity & Load Testing Plan

**Document Reference:** `docs/infrastructure/CAPACITY_TEST_PLAN.md`  
**System Version:** OmniPublish Phase 3GA  
**Execution Environment:** Dedicated Cloud Staging Cluster (Phase 3G Provisioning)  
**Standard:** Critical Honesty Principle (Synthetic Local Benchmarks $\neq$ Production Capacity Proof)

---

## 1. Executive Notice: Why Local Tests Are Insufficient

During Phase 3D, synthetic local benchmarks demonstrated high efficiency (100 mocked publishing jobs executed in 3,601 ms). However:
> **Synthetic local tests DO NOT prove production capacity.**  
> Local tests use SQLite in-memory or single-node PostgreSQL on localhost with zero network latency, zero real FFmpeg video I/O, zero TLS handshake overhead, and mocked HTTP responses from social platforms.

True production readiness requires rigorous, empirical load testing against **live, isolated cloud staging infrastructure** provisioned during Phase 3G. This document specifies the exact capacity testing protocols and measurable acceptance criteria.

---

## 2. Test Suite 1 — API Tier Concurrency & Tenant Isolation

### 2.1 Test Methodology (Tool: k6 / Locust)
* **Target Endpoint:** `GET /api/v1/workspaces/{id}/media` and `POST /api/v1/publish/batches`.
* **Traffic Profile:**
  * Ramp up from 10 to 200 concurrent virtual users (VUs) over 10 minutes.
  * Sustain 200 VUs for 15 minutes.
  * 50 distinct workspace bearer tokens with randomized `X-Workspace-Id` headers.
* **Failure Injection During Load:**
  * Interject 10% adversarial requests attempting to query Workspace B assets using Workspace A tokens (IDOR probe).

### 2.2 Measurable Acceptance Criteria
1. **Response Latency (p95):** $< 150\text{ ms}$ for read endpoints; $< 300\text{ ms}$ for batch creation endpoints under 200 concurrent VUs.
2. **Error Rate:** 5xx HTTP error rate must remain strictly $< 0.05\%$.
3. **Tenant Security:** Exactly 100% of cross-tenant IDOR probes must return HTTP 404 (zero leakage).
4. **CPU Saturation:** API container CPU utilization must stabilize $< 75\%$ via horizontal autoscaling.

---

## 3. Test Suite 2 — Media Worker & FFmpeg Saturation

### 3.1 Test Methodology
* **Workload:** Concurrently upload 20 video assets across 5 workspaces:
  * 10 standard short-form videos (1080x1920, 60s, ~50 MB).
  * 6 long-form videos (1920x1080, 10 min, ~250 MB).
  * 4 high-bitrate 4K assets (3840x2160, 3 min, ~500 MB).
* **Execution:** All 20 assets dispatched simultaneously to Redis `media` queue.
* **Failure Injection:** Issue a `docker kill -s 9` against one active media worker container during transcode.

### 3.2 Measurable Acceptance Criteria
1. **No Memory Exhaustion:** Worker containers must not trigger Linux OOM Killer. Memory usage per worker must remain $< 1024\text{ MB}$.
2. **Scratch Storage Retention:** Zero orphaned scratch directories remaining in `/tmp/omnipublish_proc_*` post-job execution.
3. **Crash Recovery:** The killed worker's job must be detected by `process_media_lock` expiry, re-queued by Redis, and completed successfully by an alternate worker replica.
4. **Total Fleet Processing Time:** All 20 videos must achieve `READY` status within $< 8\text{ minutes}$ across an autoscaling pool of 4 workers.

---

## 4. Test Suite 3 — Publishing Worker Throughput & Rate-Limit Backoff

### 4.1 Test Methodology
* **Workload:** 100 publishing jobs dispatched simultaneously across YouTube, TikTok, and Instagram staging mock adapters.
* **Fault Injection Profiles:**
  * 20% of requests return HTTP 429 (Rate Limited) with `Retry-After: 15`.
  * 10% of requests return HTTP 503 (Server Unavailable).
  * 5% of requests return HTTP 401 (Expired Token).
  * 5% of requests simulate sudden network socket resets mid-upload.

### 4.2 Measurable Acceptance Criteria
1. **Deterministic Idempotency:** Exactly 100 destination posts published. **Zero duplicate posts created** despite rate limits and retries.
2. **Backoff Verification:** Rate-limited jobs must respect `Retry-After` windows without busy-waiting the worker thread.
3. **Token Refresh Integration:** All HTTP 401 jobs must trigger automatic token refresh and succeed on retry.
4. **Queue Draining:** 100 jobs completed within $< 3\text{ minutes}$ across 4 publishing workers.

---

## 5. Test Suite 4 — Database Connection Saturation & Pooling

### 5.1 Test Methodology
* Scale API containers to 8 replicas (running 10 worker threads each) and queue workers to 16 replicas.
* Flood the API with 500 concurrent requests while queue workers simultaneously process 50 background jobs.
* Monitor connection metrics on AWS RDS Proxy and PostgreSQL engine (`pg_stat_activity`).

### 5.2 Measurable Acceptance Criteria
1. **Connection Ceiling:** Active physical connections to PostgreSQL must not exceed 50 connections (managed via RDS Proxy).
2. **Zero Connection Rejections:** Zero `FATAL: remaining connection slots are reserved` errors.
3. **Transaction Queue Latency:** RDS Proxy client connection queuing latency must remain $< 25\text{ ms}$.

---

## 6. Test Suite 5 — Redis Queue Throughput & Lock Contention

### 6.1 Test Methodology
* Enqueue 5,000 synthetic queue items into `DB 0` within 10 seconds.
* Concurrently attempt to acquire `publish_lock_job_{test_id}` from 50 worker threads simultaneously.

### 6.2 Measurable Acceptance Criteria
1. **Atomic Lock Guarantee:** Exactly 1 worker acquires the lock; exactly 49 workers receive `false` and gracefully exit without deadlocks.
2. **Queue Throughput:** Redis processing latency must remain $< 2\text{ ms}$ per pop operation under 5,000-item backlog.
3. **Zero Eviction:** Zero keys evicted from `DB 0` (`evicted_keys = 0`).
