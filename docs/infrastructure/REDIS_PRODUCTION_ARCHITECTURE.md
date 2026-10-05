# OmniPublish — Redis & Valkey Production Architecture

**Document Reference:** `docs/infrastructure/REDIS_PRODUCTION_ARCHITECTURE.md`  
**System Version:** OmniPublish Phase 3GA  
**Target Engine:** Amazon ElastiCache for Valkey 7.2+ / Redis 7.x  
**Evaluation Standard:** Critical Honesty Principle (Distinguishing Queue State from Source of Truth)

---

## 1. Engine & Role Architecture

Redis serves as the high-throughput operational coordination backbone for OmniPublish. 

### Fundamental Architectural Rule:
> **Redis is NOT the source of truth for persistent business records.**  
> All business entities (Workspaces, Users, Media Assets, Batches, Publishing Jobs, Attempts, and Audit Events) reside authoritatively in PostgreSQL. Redis is used exclusively for:
> 1. Ephemeral Queue Delivery (`publishing`, `media`, `default`).
> 2. Distributed Execution Locks (`publish_lock_job_{id}`, `process_media_lock_{id}`, `scheduler_dispatch_lock`).
> 3. Temporary Platform Rate-Limit Counters and Route Response Cache.

If Redis were to experience total data corruption or complete memory loss, the system state is **100% reconstructible from PostgreSQL** using the built-in reconciliation engines (`publishing:reap-stalled` and `publishing:reconcile`).

---

## 2. Logical Database & Keyspace Segregation

To prevent cache eviction from evicting in-flight queue jobs or expiring distributed locks prematurely, OmniPublish strictly partitions Redis by logical database index:

```text
[ Redis / Valkey Cluster ]
   ├── DB 0: QUEUE & LOCK TIER (Policy: noeviction)
   │    ├── Keyspace: omnipublish-database-queues:publishing
   │    ├── Keyspace: omnipublish-database-queues:media
   │    ├── Keyspace: omnipublish-database-queues:default
   │    └── Keyspace: omnipublish-database-locks:*
   │
   └── DB 1: CACHE & EPHEMERAL STATE TIER (Policy: volatile-lru)
        ├── Keyspace: omnipublish-cache:workspace:*
        ├── Keyspace: omnipublish-cache:user_sessions:*
        └── Keyspace: omnipublish-cache:rate_limits:*
```

### Eviction Policy Specification:
* **Database 0 (Queues & Locks):** `noeviction`
  * Under memory pressure, Redis will return errors on memory-allocating commands (`RPUSH`, `SET`) rather than dropping queued publishing jobs or lock tokens.
* **Database 1 (Application Cache):** `volatile-lru`
  * Least-recently-used keys with explicit expiration TTLs will be evicted to free memory if limits are approached.

---

## 3. High Availability & Multi-AZ Topology

### 3.1 ElastiCache Replication Group Configuration
* **Engine:** Amazon ElastiCache for Valkey 7.2 (or Redis 7.1). Valkey is selected for 20% lower hourly compute cost and identical protocol compatibility with `phpredis`.
* **Topology:** 1 Primary (Read/Write) + 1 Replica (Read-Only) across 2 Availability Zones (`us-east-1a` and `us-east-1b`).
* **Auto-Failover:** Enabled. If the primary node crashes or AZ experiences an outage, ElastiCache detects failure within 15 seconds and promotes the replica to primary in $< 40\text{ seconds}$.
* **Instance Sizing:**
  * Staging: `cache.t4g.micro` (0.5 GiB RAM, Valkey $0.0128/hr $\approx$ $9.34/mo).
  * Production Baseline: `cache.t4g.small` (1.37 GiB RAM, Valkey $0.0205/hr $\approx$ $14.97/mo).
  * Production High: `cache.m6g.large` (6.38 GiB RAM, Valkey $0.068/hr $\approx$ $49.64/mo).

---

## 4. Persistence & Queue Durability

While PostgreSQL holds authoritative state, queued jobs represent pending work. To avoid dropping jobs during graceful node maintenance or unexpected container reboots:
1. **Append-Only File (AOF) Persistence:** Enabled (`appendonly yes`). Redis writes every write command to disk with `appendfsync everysec`.
2. **Maximum Queue Data Loss Window:** In the event of catastrophic hardware power loss prior to replication, maximum theoretical unwritten queue state is $< 1\text{ second}$.
3. **Automated Recovery:** Any publishing job queued but lost before execution will be detected by `publishing:reap-stalled` (which queries PostgreSQL for jobs in `QUEUED` past their dispatch timestamp) and re-dispatched cleanly.

---

## 5. Distributed Locking Contract

Distributed locking ensures multi-worker idempotency across parallel processes:

| Lock Name Pattern | TTL | Owner / Acquirer | Consequence if Lock Fails |
|---|---|---|---|
| `publish_lock_job_{id}` | 660s | `PublishDestinationJob` | Worker skips duplicate pickup; logs warning. |
| `process_media_lock_{id}` | 360s | `ProcessMediaAssetJob` | Worker skips concurrent transcoding; leaves job in queue. |
| `scheduler_dispatch_lock`| 50s | `publishing:dispatch-scheduled` | Secondary scheduler container aborts run immediately. |
| `token_refresh_lock_{id}` | 60s | `TokenRefreshService` | Secondary refresh request waits/exits cleanly. |

### Lock Safety Guarantees:
* **Release in `finally`:** All locks are explicitly released inside `try ... finally { $lock->release(); }` blocks.
* **Fail-Safe Expiry:** If a worker node is killed by `SIGKILL` or hardware panic, the lock automatically expires after its TTL, allowing stalled-job recovery mechanisms to take over.
* **Lock TTL Alignment:** Lock TTLs strictly exceed job timeouts (e.g. `publish_lock` TTL is 660s, while job timeout is 600s).

---

## 6. Security & Network Placement

* **Network Isolation:** Deployed exclusively inside `Isolated-Data-Subnets`. No public IP allocation, zero routing to Internet Gateways.
* **Firewall Rules:** Security group `sg-redis` only accepts inbound connections on port 6379 from `sg-app-compute`.
* **Encryption in Transit (TLS):** Enabled (`sslmode=require`). All traffic between PHP `phpredis` client and ElastiCache is encrypted with TLS 1.3.
* **Authentication (AUTH Token):** Managed via AWS Secrets Manager and passed as `REDIS_PASSWORD`. Unauthenticated socket connections are rejected immediately.
