# OmniPublish — Phase 3G Disaster Recovery Drill Specifications

**Document Reference:** `docs/infrastructure/PHASE_3G_DR_RESULTS.md`  
**System Version:** OmniPublish Phase 3G  
**Standard:** Critical Honesty Principle (Distinguishing Documented Procedures from Executed Drills)  
**Verification Status:** **`DR SPECIFICATIONS AUDITED — LIVE DRILL GATED ON STAGING CLOUD INFRASTRUCTURE`**

---

## 1. Disaster Recovery Drill Scope

The Disaster Recovery (DR) drill tests the recovery of OmniPublish services in the event of an Availability Zone (AZ) failure, total Redis cluster corruption, or an unexpected worker fleet restart.

### Key Metrics to Measure During Live Staging Drill:
* **Target RPO (Recovery Point Objective):** $< 15\text{ minutes}$.
* **Target RTO (Recovery Time Objective):** $< 30\text{ minutes}$.

---

## 2. Test Drill Scenarios & Execution Protocol

### Drill 1: Redis In-Memory Evacuation & Queue Reconstitution
1. **Simulation:** Flush all data from Redis Database 0:
   ```bash
   redis-cli -h $REDIS_HOST -p 6379 -a $REDIS_AUTH FLUSHALL
   ```
2. **Measurement:**
   * Time to discover orphaned in-flight jobs via `publishing:reap-stalled`.
   * Time to re-dispatch pending scheduled batches via `publishing:dispatch-scheduled`.
3. **Acceptance Criteria:** Exactly 100% of pending jobs reconstituted in Redis without data loss; zero duplicate remote posts published.

### Drill 2: ECS Task Hard Kill During Transcode
1. **Simulation:** Issue an ungraceful termination (`SIGKILL`) against a media worker task during an active 100 MB video normalization.
2. **Measurement:**
   * Time for Fargate to detect task death and launch replacement container ($< 45\text{ seconds}$).
   * Time for `process_media_lock` to expire (360 seconds).
   * Verification of scratch directory cleanup.
3. **Acceptance Criteria:** Job re-queued cleanly; asset achieves `READY` status without human intervention.

---

## 3. Current Verification Determination

* **DR Architecture:** Fully designed and integrated into the application's reconciliation commands (`publishing:reconcile`, `publishing:reap-stalled`).
* **Live Execution Status:** Marked **`NOT VERIFIED`** pending provisioning of the live staging cluster. Under the Critical Honesty Principle, DR cannot be marked verified based solely on local process emulation.
