# OmniPublish — Disaster Recovery & Backup Plan

**Document Version:** 1.0 (Phase 3E Certified)  
**System:** OmniPublish Unified Multi-Platform Social Publishing SaaS  
**Target Recovery Objectives:**  
- **RPO (Recovery Point Objective):** < 15 minutes (PostgreSQL WAL streaming)  
- **RTO (Recovery Time Objective):** < 30 minutes to full service restoration  

---

## 1. Component Backup Strategies

### 1.1 PostgreSQL 16+ Database
- **Automated Snapshots:** Nightly full backups retained for 30 days with AES-256 server-side encryption.
- **Continuous Archiving (WAL):** Write-Ahead Logging continuously streamed to isolated S3 backup bucket, enabling point-in-time recovery (PITR) to any second within the retention window.
- **Logical Dump Backup Command:**
  ```bash
  # Daily logical backup
  pg_dump -h $DB_HOST -U $DB_USERNAME -Fc -d $DB_DATABASE > /backups/omnipublish_$(date +%Y%m%d_%H%M%S).dump
  ```
- **Restore Procedure:**
  1. Provision target clean PostgreSQL 16+ cluster.
  2. Restore base dump:
     ```bash
     pg_restore -h $NEW_DB_HOST -U $DB_USERNAME -d $DB_DATABASE --clean --if-exists /backups/omnipublish_latest.dump
     ```
  3. Run migration verification:
     ```bash
     php artisan migrate:status
     ```

---

### 1.2 Object Storage (Master Media & Video Assets)
- **Bucket Versioning:** Enabled on production media bucket to prevent accidental deletion or overwriting.
- **Cross-Region Replication (CRR):** S3 bucket configured with asynchronous cross-region replication to a secondary disaster recovery region.
- **Lifecycle Rules:**
  - Incomplete multipart uploads aborted after 24 hours.
  - Non-current versions transitioned to Glacier/Cold storage after 90 days.
  - Stale unconfirmed uploads cleaned up by `media:cleanup-stale-uploads` Artisan command.

---

### 1.3 Redis In-Memory State
- **State Classification:** Redis holds ephemeral distributed locks, cached query responses, and active job queues.
- **Persistence:** Configured with `appendonly yes` (AOF) with `appendfsync everysec`.
- **Surviving Redis Loss:**
  - If Redis is completely lost or destroyed:
    1. Core relational state, users, social account OAuth credentials, publish batches, and job statuses remain 100% safe in PostgreSQL.
    2. Any jobs lost in-flight will be recovered by `publishing:reap-stalled` and `publishing:reconcile` scheduler commands.
    3. Re-provisioning a new Redis instance requires zero database migration; workers will immediately reconnect and resume queue polling.

---

## 2. Disaster Recovery Scenarios & Execution Runbook

### Scenario A: Complete Primary Cloud Zone / Datacenter Failure
1. **DNS Cutover:** Update Cloudflare / Route 53 DNS records to point `app.omnipublish.com` and `api.omnipublish.com` to secondary region load balancers.
2. **Promote Standby Database:** Promote read-replica in secondary region to primary read-write cluster.
3. **Point Object Storage:** Switch `AWS_BUCKET` / `AWS_ENDPOINT` to the replicated DR bucket.
4. **Deploy Application Pods:** Scale up backend API, workers, and Next.js frontend in secondary region.
5. **Execute Health Checks:**
   ```bash
   curl -I https://api.omnipublish.com/health/ready
   ```
6. **Resume Publishing:** Reconcile all pending and processing jobs:
   ```bash
   php artisan publishing:reconcile
   ```

### Scenario B: Accidental Data Corruption or Catastrophic Migration
1. Put application into maintenance mode:
   ```bash
   php artisan down --secret="omnipublish-dr-bypass"
   ```
2. Stop all queue workers:
   ```bash
   php artisan queue:clear
   ```
3. Execute Point-In-Time Recovery (PITR) on PostgreSQL cluster to timestamp immediately prior to corruption event.
4. Update connection string to restored database instance.
5. Verify integrity via `php artisan migrate:status`.
6. Bring application back online:
   ```bash
   php artisan up
   ```
