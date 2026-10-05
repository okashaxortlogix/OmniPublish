# Database Migration Runbook: SQLite to PostgreSQL

**Document ID:** RUNBOOK-DB-001  
**Project:** OmniPublish  
**Author:** Antigravity Engineering  
**Version:** 1.0  
**Target Environment:** Staging & Production Cutover

---

## 1. Overview & Objective
This runbook defines the step-by-step operational procedure for executing zero-data-loss database schema migrations and data transition from local development SQLite environments to production-grade PostgreSQL instances.

---

## 2. Pre-Migration Prerequisites

1. Target PostgreSQL database provisioned (PostgreSQL 16.x recommended).
2. Database user created with DDL and DML permissions on target schema (`public`).
3. Dedicated backup storage accessible via encrypted channel.
4. Maintenance window scheduled and announced if performing live cutover.
5. All background workers and schedulers paused before commencing data export.

```bash
# Verify target database connectivity from backend environment
php -r "new PDO('pgsql:host='.getenv('DB_HOST').';port=5432;dbname='.getenv('DB_DATABASE'), getenv('DB_USERNAME'), getenv('DB_PASSWORD')); echo 'Connected successfully to PostgreSQL'.PHP_EOL;"
```

---

## 3. Migration Execution Procedure

### Step 1: Pre-Migration Backup (Source Environment)
Before any cutover activity, capture a timestamped copy of the current SQLite database:

```bash
# In backend root directory
cp database/database.sqlite database/backups/database_pre_migration_$(date +%Y%m%d_%H%M%S).sqlite
sha256sum database/backups/database_pre_migration_*.sqlite > database/backups/checksum.txt
```

### Step 2: Schema Migration on PostgreSQL
Execute standard Laravel migrations against the PostgreSQL connection without dropping existing tables:

```bash
# Point migration command to PostgreSQL connection
php artisan migrate --database=pgsql --force
```

Verify that all migrations executed cleanly:
```bash
php artisan migrate:status --database=pgsql
```

### Step 3: Data Migration / Export Strategy
If existing development or staging SQLite data contains critical accounts, workspaces, or configurations that must be preserved:

1. **Option A: Automated Artisan Data Porter (Recommended)**
   Export records sequentially honoring foreign key hierarchy:
   `users` -> `workspaces` -> `workspace_members` -> `social_accounts` -> `media_assets` -> `content_items` -> `publish_batches` -> `publish_jobs` -> `audit_events`.

2. **Option B: Seed Baseline Data for Fresh Environments**
   If migrating to a pristine production environment:
   ```bash
   php artisan db:seed --database=pgsql --force
   ```

### Step 4: Schema & Data Integrity Validation
Run consistency verification queries on the PostgreSQL database:

```sql
-- 1. Verify table counts
SELECT count(*) FROM workspaces;
SELECT count(*) FROM workspace_members;
SELECT count(*) FROM social_accounts;

-- 2. Verify foreign key integrity
SELECT wm.id, wm.workspace_id 
FROM workspace_members wm 
LEFT JOIN workspaces w ON wm.workspace_id = w.id 
WHERE w.id IS NULL; -- MUST RETURN 0 ROWS

-- 3. Verify encrypted tokens are intact
SELECT id, platform, length(encrypted_access_token) 
FROM social_accounts 
WHERE encrypted_access_token IS NOT NULL;
```

### Step 5: Application Cutover
1. Update `.env` or production secrets manager:
   ```ini
   DB_CONNECTION=pgsql
   DB_HOST=your-postgres-host
   DB_PORT=5432
   DB_DATABASE=omnipublish_prod
   DB_USERNAME=omnipublish_app
   DB_PASSWORD=your-secure-password
   ```
2. Clear and reload application cache:
   ```bash
   php artisan config:clear
   php artisan cache:clear
   php artisan route:clear
   php artisan config:cache
   ```
3. Restart application servers and queue workers:
   ```bash
   php artisan queue:restart
   ```

---

## 4. Rollback Strategy

If critical anomalies, schema mismatch, or unresolvable connection errors occur during the cutover:

1. **Immediate Application Reversion:**
   Switch `.env` back to the pre-migration source:
   ```ini
   DB_CONNECTION=sqlite
   DB_DATABASE=database/database.sqlite
   ```
2. **Reload Application Cache:**
   ```bash
   php artisan config:clear
   php artisan queue:restart
   ```
3. **PostgreSQL Schema Teardown (if needed for clean retry):**
   ```bash
   php artisan migrate:rollback --database=pgsql --force
   ```
4. **Post-Rollback Validation:**
   Execute test suite to confirm operational status:
   ```bash
   php artisan test
   ```

---

## 5. Post-Migration Verification Checklist
- [x] Web API returns `200 OK` on `/api/v1/health/ready`.
- [x] Workspace listing and switching functioning seamlessly.
- [x] Media asset creation and listing functions under PostgreSQL.
- [x] Publishing batch creation and queue dispatch operations commit successfully.
- [x] Queue workers process jobs against PostgreSQL database driver (or Redis).
- [x] Audit logs record new events with accurate timestamps and JSON payloads.
