# OmniPublish — PostgreSQL Production Database Architecture

**Document Reference:** `docs/infrastructure/DATABASE_PRODUCTION_ARCHITECTURE.md`  
**System Version:** OmniPublish Phase 3GA  
**Target Engine:** Amazon RDS for PostgreSQL 16.2+  
**Evaluation Standard:** Critical Honesty Principle (Explicit distinction between planned design and untested live restore)

---

## 1. Engine & Instance Specification

OmniPublish relies on PostgreSQL 16 as its single authoritative relational system of record. SQLite is restricted strictly to local unit testing.

### 1.1 Instance Sizing by Environment
* **Staging Environment:**
  * Instance Class: `db.t4g.small` (2 vCPUs, 2 GB RAM, Burstable Graviton2).
  * Storage: 20 GB General Purpose SSD (gp3) with baseline 3,000 IOPS and 125 MB/s throughput.
  * Deployment: Single-AZ (Cost-optimized for staging validation).
* **Production Baseline Environment:**
  * Instance Class: `db.t4g.medium` (2 vCPUs, 4 GB RAM, Burstable Graviton2) or `db.m6g.large` (2 vCPUs, 8 GB RAM, General Purpose).
  * Storage: 50 GB gp3 with storage auto-scaling enabled up to 500 GB.
  * Deployment: **Multi-AZ** (Synchronous physical standby replica in secondary AZ with automated failover in $< 60\text{ seconds}$).
* **Engine Settings:**
  * `max_connections`: 100
  * `shared_buffers`: 25% of RAM (1 GB on 4 GB node)
  * `work_mem`: 16 MB
  * `maintenance_work_mem`: 128 MB
  * `wal_level`: `replica`
  * `archive_mode`: `on`
  * `ssl`: `on` (Enforced `sslmode=verify-full`)

---

## 2. Connection Management & Capacity Analysis

### 2.1 Empirical Connection Sizing (MVP Baseline)
OmniPublish utilizes containerized PHP workers and API nodes in AWS ECS Fargate:
* **Staging Environment (`db.t4g.small`):**
  * PostgreSQL 16 default connection limit: **225 max connections**.
  * Active compute: 1 API task (5-10 connections) + 3 Queue Workers (3 connections) + 1 Scheduler (1 connection) = **9–14 active connections**.
  * Connection Utilization: **$< 6.5\%$ of database capacity**.
* **Production Baseline Environment (`db.t4g.medium`):**
  * PostgreSQL 16 default connection limit: **450 max connections**.
  * Active compute: 2 API tasks (20–40 connections) + 3 Queue Workers (3 connections) + 1 Scheduler (1 connection) = **24–44 active connections**.
  * Connection Utilization: **$< 10\%$ of database capacity**.
  * Peak 4x Worker Surge (4 API, 4 Media, 8 Publishing, 2 Default, 1 Scheduler) = **~95 connections** ($< 21\%$ utilization).

### 2.2 Direct Connectivity vs Phase 4 RDS Proxy Decision
* **MVP Decision (Phase 3G / 3GA): DIRECT RDS CONNECTIONS.**
  * Because peak concurrency consumes $< 25\%$ of PostgreSQL 16 connection slots, direct private connections are fully adequate, highly performant, and eliminate the $21.90/month fixed proxy charge and configuration overhead.
  * Multi-AZ failover recovery is handled gracefully at the application layer via Laravel's exponential backoff retry policies (`PublishingRetryPolicy`).
* **Phase 4 Trigger for AWS RDS Proxy:**
  * Deploying an `aws_db_proxy` instance will be triggered when production horizontal autoscaling scales the API fleet beyond 10 Fargate tasks (>200 concurrent PHP processes).

---

## 3. Schema Architecture & Multi-Tenant Indexes

OmniPublish migrations define 10 core tables with engine-enforced integrity:
1. `workspaces`: Tenant root with UUID primary key.
2. `users`: System users with owner and collaborator roles.
3. `workspace_members`: Composite unique index `(workspace_id, user_id)`.
4. `social_accounts`: Platform bindings with index `(workspace_id, platform)`. Encrypted token storage.
5. `media_assets`: Primary media catalog with composite index `(workspace_id, status)`.
6. `media_derivatives`: Technical video outputs with index `(media_asset_id, derivative_type)`.
7. `content_items`: Canonical posts with JSONB metadata and overrides.
8. `publish_batches`: Master dispatch collections with indexes `(workspace_id, status)` and `scheduled_for`.
9. `publish_jobs`: Per-destination publishing records with composite orchestration indexes:
   * `idx_pub_jobs_ws_status_sched` on `(workspace_id, status, scheduled_at)`
   * `idx_pub_jobs_status_retry` on `(status, retry_at)`
   * `idx_pub_jobs_status_heartbeat` on `(status, last_heartbeat_at)`
   * `idx_pub_jobs_idempotency` on `idempotency_key`
   * `idx_pub_jobs_correlation` on `correlation_id`
10. `audit_events`: Immutable compliance ledger with index `(workspace_id, action)`.

---

## 4. Backup & Point-in-Time Recovery (PITR)

### 4.1 Backup Strategy
1. **Continuous WAL Streaming:** Transaction log files are automatically archived to Amazon S3 every 5 minutes.
2. **Automated Daily Snapshots:** Full storage volume snapshots captured daily during low-traffic window (03:00 UTC).
3. **Retention Period:**
   * Staging: 7 days.
   * Production: 35 days.
4. **Pre-Deployment Snapshots:** Integrated into the deployment pipeline (`deploy-production.yml`):
   ```bash
   aws rds create-db-snapshot \
     --db-instance-identifier omnipublish-prod \
     --db-snapshot-identifier "omnipublish-predeploy-${COMMIT_SHA}-$(date +%s)"
   ```

### 4.2 Point-in-Time Recovery Workflow
Point-In-Time Recovery allows restoring the database cluster to any specified second within the 35-day window:
* **Recovery Point Objective (RPO):** $< 5\text{ minutes}$ (governed by continuous WAL archiving).
* **Recovery Time Objective (RTO):** $< 20\text{ minutes}$ (time required by RDS to instantiate a new storage volume from snapshot and roll forward WALs).

> **CRITICAL HONESTY NOTICE:**  
> While backup configuration and automated snapshot scripts are authored and verified, **PHYSICAL RESTORATION TESTING HAS NOT YET BEEN CONDUCTED ON LIVE CLOUD INFRASTRUCTURE**. Live restoration testing is a mandatory gating requirement during Phase 3G staging provisioning.

---

## 5. Migration & Rollback Strategy

### 5.1 Deployment Pipeline Execution
1. All migrations are pre-validated in GitHub Actions CI using an ephemeral PostgreSQL 16 Alpine container, testing both forward migration and step-1 rollback (`migrate:rollback --step=1`).
2. In production, migrations execute with explicit lock timeout guards:
   ```bash
   php artisan migrate --force
   ```
3. To protect against transaction table locking on high-traffic tables (`publish_jobs`), migrations must avoid exclusive locks on busy tables or employ zero-downtime column addition (e.g. adding nullable columns without default values).

### 5.2 Rollback Runbook
* **Reversible Migrations (Schema Only):**
  If a release fails immediately post-deployment and schema changes are non-destructive:
  ```bash
  php artisan migrate:rollback --step=1
  ```
* **Irreversible Migrations (Data Mutation / Dropped Columns):**
  1. Halt application traffic via ALB maintenance rule.
  2. Restore the pre-deployment RDS snapshot created during the deployment pipeline:
     ```bash
     aws rds restore-db-instance-from-db-snapshot \
       --db-instance-identifier omnipublish-prod-restored \
       --db-snapshot-identifier "omnipublish-predeploy-${FAILED_RELEASE_TAG}"
     ```
  3. Swap RDS DNS endpoint CNAME to the restored instance.
  4. Redeploy previous known-good Git commit SHA.
  5. Run `publishing:reconcile` and `publishing:reap-stalled` to re-synchronize in-flight jobs.
