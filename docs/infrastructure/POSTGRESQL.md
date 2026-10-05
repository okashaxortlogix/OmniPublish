# PostgreSQL Production Infrastructure Guide

**OmniPublish Production Database Guide**  
**Version:** 1.0 (Phase 1 Migration)  
**Target Engine:** PostgreSQL 16.x (Compatible with PostgreSQL 15+)

---

## 1. Overview & Engine Specification

OmniPublish is engineered to run on PostgreSQL for all staging and production environments. SQLite remains supported strictly for local unit testing and quick headless development setups.

### 1.1 Recommended Versions & Distributions
- **Production Engine:** PostgreSQL 16.2+
- **Managed Providers:** AWS Aurora Serverless v2 (PostgreSQL compatible), AWS RDS PostgreSQL, Supabase Enterprise, GCP Cloud SQL for PostgreSQL, DigitalOcean Managed Databases.
- **Character Set / Encoding:** `UTF8`
- **Collation:** `en_US.UTF-8` or `C.UTF-8`

### 1.2 Required Extensions
OmniPublish utilizes application-layer UUID generation via PHP (`Illuminate\Support\Str::uuid()`), storing values in native PostgreSQL `UUID` columns. However, the following extensions are recommended or required for production database operations:

```sql
-- Enable pgcrypto for database-level cryptographic helpers and UUID generation
CREATE EXTENSION IF NOT EXISTS "pgcrypto";

-- Enable pg_trgm for efficient indexing and fuzzy searching across media filenames and post text
CREATE EXTENSION IF NOT EXISTS "pg_trgm";

-- Enable btree_gist for advanced exclusion and temporal index constraints
CREATE EXTENSION IF NOT EXISTS "btree_gist";
```

---

## 2. Schema Architecture & Compatibility Analysis

### 2.1 Native Data Type Mappings
Laravel migrations in `backend/database/migrations/2026_10_02_000001_create_omnipublish_core_tables.php` map cleanly to PostgreSQL native types:

| Domain Entity | Migration Schema Primitives | PostgreSQL Native Type | Compatibility Notes |
|---|---|---|---|
| Primary Keys | `$table->uuid('id')->primary()` | `uuid PRIMARY KEY` | Fast 16-byte fixed-size B-tree indexing. |
| User References | `$table->unsignedBigInteger('owner_id')` | `bigint` | Matches `users.id` auto-incrementing identity. |
| Foreign Keys | `$table->uuid('workspace_id')` | `uuid REFERENCES ... ON DELETE CASCADE` | Enforced strictly at the engine level with cascade constraints. |
| Metadata & Scopes | `$table->json('global_metadata')` | `json` / `jsonb` | Native JSON indexing and document querying. |
| Encrypted Tokens | `$table->text('encrypted_access_token')` | `text` | Arbitrary-length authenticated ciphertext (AES-256-CBC). |
| Role Enums | `$table->enum('role', [...])` | `character varying(255) CHECK (...)` | Standard Laravel check constraint mapping. |
| Timestamps | `$table->timestamps()` | `timestamp(0) without time zone` | Full microsecond/second temporal fidelity. |

### 2.2 Indexing & Multi-Tenant Performance
PostgreSQL executes composite indexes in index-only scans. OmniPublish migration indexes are designed for multi-tenant isolation:
1. `social_accounts (workspace_id, platform)` — Rapid platform lookup scoped to workspace.
2. `media_assets (workspace_id, status)` — Fast media library filtering by lifecycle state.
3. `publish_batches (workspace_id, status)` & `scheduled_for` — Efficient scheduler polling and dashboard queries.
4. `publish_jobs (publish_batch_id, platform)` — Granular destination status resolution.
5. `workspace_members (workspace_id, user_id)` — Unique constraint preventing duplicate tenant memberships.

---

## 3. Production Connection Configuration

### 3.1 Laravel `config/database.php` pgsql Driver
```php
'pgsql' => [
    'driver' => 'pgsql',
    'url' => env('DB_URL'),
    'host' => env('DB_HOST', '127.0.0.1'),
    'port' => env('DB_PORT', '5432'),
    'database' => env('DB_DATABASE', 'omnipublish'),
    'username' => env('DB_USERNAME', 'omnipublish_user'),
    'password' => env('DB_PASSWORD', ''),
    'charset' => env('DB_CHARSET', 'utf8'),
    'prefix' => '',
    'prefix_indexes' => true,
    'search_path' => env('DB_SCHEMA', 'public'),
    'sslmode' => env('DB_SSLMODE', 'prefer'),
    'options' => [
        PDO::ATTR_PERSISTENT => env('DB_PERSISTENT', false),
        PDO::ATTR_TIMEOUT => 5,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    ],
],
```

### 3.2 Environment Variables (`.env`)
```ini
DB_CONNECTION=pgsql
DB_HOST=postgres.production.internal
DB_PORT=5432
DB_DATABASE=omnipublish_prod
DB_USERNAME=omnipublish_app
DB_PASSWORD=SecureProductionPasswordHere
DB_SSLMODE=verify-full
DB_SCHEMA=public
```

---

## 4. Connection Pooling Recommendations

OmniPublish web requests and asynchronous queue workers generate concurrent database connections.

### 4.1 PgBouncer / RDS Proxy Topology
- **Web Requests (PHP-FPM / Octane / Artisan Serve):** Use **Transaction Pooling** (`pool_mode = transaction`). This permits hundreds of web requests to share 20–30 physical Postgres connections.
- **Queue Workers (`queue:work`):** Use **Session Pooling** or direct connections if persistent prepared statements or advisory locks are utilized.
- **Sizing Formula:**
  $$\text{Max Direct DB Connections} = (\text{CPU Cores} \times 2) + \text{Spindle/SSD Overhead}$$
  For a 4-core database server, set PostgreSQL `max_connections = 100`, with PgBouncer allocating up to 80 connections to the pool.

---

## 5. Backup & Disaster Recovery Procedures

### 5.1 Automated Logical Backups (Daily / Hourly)
```bash
# Full logical dump with custom compressed format
pg_dump -h $DB_HOST -p $DB_PORT -U $DB_USERNAME -F c -b -v -f "/backups/omnipublish_$(date +%Y%m%d_%H%M%S).dump" $DB_DATABASE
```

### 5.2 Restore Procedure
```bash
# 1. Terminate active application connections
psql -h $DB_HOST -U postgres -d postgres -c "SELECT pg_terminate_backend(pid) FROM pg_stat_activity WHERE datname = 'omnipublish_prod' AND pid <> pg_backend_pid();"

# 2. Re-create empty target database
psql -h $DB_HOST -U postgres -d postgres -c "DROP DATABASE IF EXISTS omnipublish_prod;"
psql -h $DB_HOST -U postgres -d postgres -c "CREATE DATABASE omnipublish_prod OWNER omnipublish_app ENCODING 'UTF8';"

# 3. Restore dump
pg_restore -h $DB_HOST -p $DB_PORT -U omnipublish_app -d omnipublish_prod -v "/backups/omnipublish_20261003_120000.dump"

# 4. Verify migration state
php artisan migrate:status
```

---

## 6. Migration Verification Checklist

Before directing production traffic to PostgreSQL:
- [x] Run `php artisan migrate:status` to ensure all core tables and indices are in place.
- [x] Confirm foreign key constraints enforce cascade deletions (`workspaces` -> `media_assets`, `publish_batches`).
- [x] Confirm unique index on `workspace_members (workspace_id, user_id)` stops duplicate associations.
- [x] Run `php artisan test` with PostgreSQL connection active.
- [x] Confirm JSON columns parse arrays and objects natively without serialization anomalies.
