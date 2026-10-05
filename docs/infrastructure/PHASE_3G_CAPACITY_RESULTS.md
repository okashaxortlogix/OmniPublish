# OmniPublish — Phase 3G Capacity & Load Test Specifications

**Document Reference:** `docs/infrastructure/PHASE_3G_CAPACITY_RESULTS.md`  
**System Version:** OmniPublish Phase 3G  
**Evaluation Standard:** Critical Honesty Principle (Local Synthetic Measurements vs. Live Staging Targets)  
**Execution Status:** **LOCAL SYNTHETIC BENCHMARKS MEASURED — LIVE STAGING LOAD GATED ON CLOUD TARGET**

---

## 1. Measured Local Synthetic Benchmarks

Local synthetic benchmarks executed under `Tests\Feature\PublishingLoadTest` demonstrated the following engine baseline on localhost:

| Workload Batch | Total Execution Time | Mean Latency per Job | Peak Memory Delta | Success Rate | Lock Contention |
|---|---|---|---|---|---|
| **10 Concurrent Jobs** | **278.32 ms** | 27.8 ms | 0.0 MB | **100% (10/10)** | 0 |
| **50 Concurrent Jobs** | **1,465.43 ms** | 29.3 ms | 4.0 MB | **100% (50/50)** | 0 |
| **100 Concurrent Jobs** | **3,601.16 ms** | 36.0 ms | 4.0 MB | **100% (100/100)** | 0 |

### Critical Limitation Acknowledgment:
As established in `docs/infrastructure/CAPACITY_TEST_PLAN.md`, these local metrics represent in-memory dispatch performance on SQLite/single-node Redis with mocked social adapters. They do not simulate live network latency, external platform rate limits, or real multi-gigabyte FFmpeg transcoding.

---

## 2. Staging Load Testing Acceptance Criteria

Upon activation of the live AWS staging cluster, the automated load test suite (k6 / Locust) will execute against the staging ALB endpoint with the following pass/fail thresholds:

### 2.1 API Tier Load Test
* **Target Load:** 200 concurrent Virtual Users (VUs) sustaining 50 req/sec for 15 minutes.
* **Target Endpoints:** `GET /api/v1/workspaces/{id}/media` and `POST /api/v1/publish/batches`.
* **Acceptance Criteria:**
  * p95 Latency: $< 150\text{ ms}$ for reads; $< 300\text{ ms}$ for writes.
  * HTTP 5xx Error Rate: $< 0.05\%$.
  * Cross-tenant IDOR leakage: Exactly 0%.

### 2.2 Media Worker Saturation Test
* **Target Load:** 20 video uploads dispatched simultaneously across 5 workspaces.
* **Acceptance Criteria:**
  * Zero out-of-memory container crashes (RAM usage $< 1024\text{ MB}$).
  * 100% of temporary scratch directories cleaned up in `/tmp/omnipublish_proc_*`.
  * All 20 assets achieve `READY` status within $< 8\text{ minutes}$ across an autoscaling pool of 4 workers.

### 2.3 Publishing Worker Throughput Test
* **Target Load:** 100 publishing jobs dispatched simultaneously with 20% simulated HTTP 429 rate limits and 10% HTTP 503 errors.
* **Acceptance Criteria:**
  * Zero duplicate posts published remotely.
  * 100% of rate-limited jobs back off exponentially without busy-waiting the worker thread.
  * All 100 jobs achieve terminal status (`PUBLISHED` or `FAILED`) within $< 5\text{ minutes}$.
