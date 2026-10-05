# OmniPublish — Production Disaster Recovery Plan

**Document Reference:** `docs/infrastructure/DISASTER_RECOVERY_PLAN.md`  
**System Version:** OmniPublish Phase 3GA  
**Target RPO:** $< 15\text{ minutes}$ (Recovery Point Objective)  
**Target RTO:** $< 30\text{ minutes}$ (Recovery Time Objective)  
**Certification Status:** `DESIGNED — UNTESTED ON LIVE CLOUD (PENDING PHASE 3G PROVISIONING)`

---

## 1. Disaster Recovery Objectives & Classification

This plan establishes operational procedures to restore OmniPublish services in the event of catastrophic infrastructure loss, regional cloud outages, database corruption, or credential compromise.

### 1.1 Recovery Metrics
* **Recovery Point Objective (RPO):** The maximum acceptable data loss measured in time.
  * Relational Database (PostgreSQL): **$< 5\text{ minutes}$** (backed by continuous WAL streaming).
  * Binary Media (Object Storage): **$< 15\text{ minutes}$** (backed by S3 cross-region replication).
  * Queue / In-Flight State: **Zero permanent business loss** (reconstructed from PostgreSQL state machines).
* **Recovery Time Objective (RTO):** The maximum acceptable downtime to restore functionality.
  * Target: **$< 30\text{ minutes}$** from disaster declaration to healthy API health checks.

### 1.2 Data Recoverability Classification

| Data Category | Recoverable? | Recovery Source | Expected Data Loss Window |
|---|---|---|---|
| Workspaces, Users, Accounts | **YES** | RDS Automated Snapshots & WAL Logs | 0 to 5 minutes |
| Content Items & Scheduled Posts | **YES** | RDS Automated Snapshots & WAL Logs | 0 to 5 minutes |
| Published Post History & URLs | **YES** | RDS Snapshots + Remote Provider APIs | 0 to 5 minutes |
| Raw Videos & Thumbnails | **YES** | S3 Standard / Cross-Region Replica | 0 to 15 minutes |
| In-Flight Transcoding Jobs | **Partial** | Scratch disk lost; auto-requeued | Active transcode must restart |
| In-Flight Platform Uploads | **YES** | Resumed via resumable upload session | Resumes from last acknowledged byte |
| Redis In-Memory Cache | **No** (Rebuilt) | Dynamic warm-up from PostgreSQL | Zero business data loss |

---

## 2. Component Recovery Procedures

### 2.1 Scenario 1: Primary Database Corruption or AZ Outage
**Impact:** Primary PostgreSQL instance is unresponsive or data was corrupted by bad migration.
**Automated Recovery (AZ Outage):**
* AWS RDS Multi-AZ automatically detects primary failure and promotes the synchronous standby in AZ-b within 60 seconds. RDS Proxy handles socket reconnects automatically.
**Manual Recovery (Data Corruption / Fatal Deletion):**
1. Initiate Point-In-Time Recovery to a timestamp immediately preceding the corruption:
   ```bash
   aws rds restore-db-instance-to-point-in-time \
     --source-db-instance-identifier omnipublish-prod \
     --target-db-instance-identifier omnipublish-prod-restored \
     --restore-time "2026-10-04T12:00:00Z" \
     --db-subnet-group-name omnipublish-data-subnets \
     --vpc-security-group-ids sg-database
   ```
2. Once the restored instance reaches `available` status (~15 minutes), update AWS Secrets Manager `DB_HOST` to point to the new RDS endpoint.
3. Restart ECS API and Worker services:
   ```bash
   aws ecs update-service --cluster omnipublish-cluster --service omnipublish-api --force-new-deployment
   aws ecs update-service --cluster omnipublish-cluster --service omnipublish-publishing-worker --force-new-deployment
   ```
4. Run `publishing:reap-stalled` and `publishing:reconcile` to restore queue alignment.

---

### 2.2 Scenario 2: Total Loss of Redis Cluster
**Impact:** ElastiCache cluster fails or is accidentally deleted. Queues, locks, and cache disappear.
**Recovery Procedure:**
1. Provision a new ElastiCache Valkey/Redis replication group via Terraform (`infra/terraform/modules/redis`).
2. Update Secrets Manager `REDIS_HOST` and `REDIS_PASSWORD`.
3. Restart ECS Fargate tasks to refresh socket bindings.
4. **Reconstruct Queues from PostgreSQL:**
   Execute the built-in self-healing commands to scan PostgreSQL and re-dispatch all orphaned work:
   ```bash
   # 1. Re-queue all pending scheduled batches
   php artisan publishing:dispatch-scheduled
   # 2. Re-queue in-flight jobs that lost worker locks
   php artisan publishing:reap-stalled
   # 3. Re-queue pending media assets stuck in PROCESSING
   # (Assets in PROCESSING with no active lock are reset to QUEUED)
   ```
5. Cache warms up dynamically on subsequent user requests.

---

### 2.3 Scenario 3: Complete Regional Cloud Outage (us-east-1 Down)
**Impact:** Entire AWS US-East-1 region is unreachable.
**Recovery Procedure (Multi-Region Failover):**
1. Activate pre-configured secondary region (`us-west-2`).
2. Promote Cross-Region RDS Read Replica in `us-west-2` to standalone primary database.
3. Switch primary storage endpoint to secondary S3 bucket (populated via S3 Cross-Region Replication).
4. Deploy ECS Fargate task definitions in `us-west-2` pointing to images in GHCR / ECR.
5. Update Amazon Route 53 DNS failover routing policy to route `app.omnipublish.com` and `api.omnipublish.com` to the `us-west-2` Application Load Balancer.

---

## 3. Credential Compromise Response Runbook

If production secrets (e.g. database password, S3 secret key, OAuth app secret, or `APP_KEY`) are leaked:

1. **Immediate Revocation & Rotation:**
   * **Database:** Rotate RDS password via Secrets Manager with immediate credential rotation.
   * **Social OAuth Secrets:** Log into Google Cloud Console, TikTok Developer Portal, and Meta App Dashboard. Immediately revoke compromised client secrets, generate new secrets, and update Secrets Manager.
   * **Application Key (`APP_KEY`):**
     > **CRITICAL WARNING:** Social account access tokens are encrypted with `APP_KEY` via AES-256-CBC. Changing `APP_KEY` without re-encrypting existing database rows will render all connected social accounts unreadable!  
     *Run the token re-encryption command prior to rotating key in production.*
2. **Container Rolling Restart:**
   * Force a new deployment across all ECS services to inject new secrets into container memory.
3. **Audit Inspection:**
   * Query `audit_events` for unauthorized workspace actions or anomalous IP addresses.
   * Terminate all active sessions via personal access tokens revocation.

---

## 4. DR Testing Gating Notice

Pursuant to the Critical Honesty Principle:
* **The procedures above are structurally validated against the codebase architecture.**
* **They have NOT yet been executed against live AWS infrastructure.**
* **Mandatory Phase 3G Pre-Production Gate:** An end-to-end database snapshot restoration and container failover drill MUST be executed and recorded on staging infrastructure prior to production launch.
