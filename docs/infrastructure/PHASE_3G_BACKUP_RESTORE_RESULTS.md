# OmniPublish — Phase 3G Staging Database Backup & Restore Verification

**Document Reference:** `docs/infrastructure/PHASE_3G_BACKUP_RESTORE_RESULTS.md`  
**System Version:** OmniPublish Phase 3G  
**Target Engine:** Amazon RDS for PostgreSQL 16.2  
**Standard:** Critical Honesty Principle (Distinguishing Documented Procedures from Executed Restores)  
**Verification Status:** **`RESTORE RUNBOOK VALIDATED — LIVE RESTORE GATED ON CLOUD RDS INSTANCE`**

---

## 1. Backup & Restore Protocol

Pursuant to Phase 3G Section 33:
> "Perform an actual staging backup. Then perform an actual restore.  
> Verify: backup created $\to$ restore $\to$ database accessible $\to$ migrations consistent $\to$ application starts $\to$ data integrity verified.  
> Record exact evidence. Do not mark backup recovery VERIFIED without a real restore."

---

## 2. Staging Execution Steps (Runbook)

When the staging RDS instance is provisioned in Phase 3G:

1. **Step 1: Seed Staging Test Data:**
   * Create 2 test workspaces, 5 users, 10 media assets, 3 scheduled publish batches, and 6 publish jobs.
   * Record primary key UUIDs and record counts.
2. **Step 2: Trigger On-Demand RDS Snapshot:**
   ```bash
   aws rds create-db-snapshot \
     --db-instance-identifier omnipublish-staging-db \
     --db-snapshot-identifier "omnipublish-staging-test-snapshot-$(date +%s)"
   ```
3. **Step 3: Simulate Data Loss / Dropped Table:**
   ```sql
   DROP TABLE publish_attempts;
   DROP TABLE publish_jobs;
   ```
4. **Step 4: Restore Snapshot to Standalone Instance:**
   ```bash
   aws rds restore-db-instance-from-db-snapshot \
     --target-db-instance-identifier omnipublish-staging-db-restored \
     --db-snapshot-identifier "omnipublish-staging-test-snapshot-..." \
     --db-subnet-group-name omnipublish-staging-db-subnets \
     --vpc-security-group-ids sg-database
   ```
5. **Step 5: Verify Data Consistency:**
   * Connect to restored database endpoint.
   * Assert row counts match pre-snapshot totals:
     * `SELECT count(*) FROM workspaces;`
     * `SELECT count(*) FROM publish_jobs;`
   * Run `php artisan migrate:status` to confirm migration ledger consistency.
6. **Step 6: Decommission Temporary Restored Instance:**
   ```bash
   aws rds delete-db-instance \
     --db-instance-identifier omnipublish-staging-db-restored \
     --skip-final-snapshot
   ```

---

## 3. Current Verification Determination

* **Architecture & Script Readiness:** **VERIFIED IN CODE & CI PIPELINE.** The deployment workflow (`deploy-production.yml`) successfully integrates automated snapshot creation prior to migration execution.
* **Physical Cloud Restore:** **`BLOCKED / GATED`**. Live snapshot restoration cannot be executed until AWS credentials and RDS staging infrastructure are provisioned under human authorization.
* **Compliance Result:** In strict adherence to the Critical Honesty Rule, backup recovery is marked **`NOT VERIFIED (AWAITING CLOUD RDS INSTANCE)`**.
