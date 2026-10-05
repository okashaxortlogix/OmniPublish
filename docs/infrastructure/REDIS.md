# Redis Production Infrastructure Guide

**OmniPublish Cache & Asynchronous Queue Backend**  
**Version:** 1.0 (Phase 1 Migration)  
**Target Engine:** Redis 7.x (Compatible with Valkey / AWS ElastiCache / Redis Enterprise)

---

## 1. Overview & Architecture

OmniPublish utilizes Redis as its primary high-throughput backend for:
1. **Asynchronous Job Queues:** Video processing, multi-platform publishing, token refresh, and notifications.
2. **Distributed Locks:** Multi-worker idempotency protection and atomic publishing execution guards.
3. **Application & Route Cache:** Workspace tenant cache, platform capabilities, and ephemeral rate-limiting counters.

---

## 2. Logical Database & Topology Separation

To eliminate cache eviction from evicting in-flight queue jobs, Redis databases and key spaces are strictly segregated:

| Purpose | Logical Database | Key Prefix | Eviction Policy Strategy |
|---|---|---|---|
| Queues & Jobs | `DB 0` | `omnipublish-queue:` | `noeviction` (Never drop in-flight jobs) |
| Distributed Locks | `DB 0` | `omnipublish-locks:` | Explicit TTL (Managed by Laravel `Cache::lock`) |
| Application Cache | `DB 1` | `omnipublish-cache:` | `allkeys-lru` or `volatile-lru` |
| Session Store | `DB 2` | `omnipublish-session:` | Explicit session expiry |

---

## 3. Configuration Specification

### 3.1 Laravel Database Configuration (`config/database.php`)
```php
'redis' => [
    'client' => env('REDIS_CLIENT', 'phpredis'), // phpredis or predis

    'options' => [
        'cluster' => env('REDIS_CLUSTER', 'redis'),
        'prefix' => env('REDIS_PREFIX', 'omnipublish-database-'),
        'persistent' => env('REDIS_PERSISTENT', true),
    ],

    'default' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'username' => env('REDIS_USERNAME'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_DB', '0'),
        'max_retries' => env('REDIS_MAX_RETRIES', 3),
        'backoff_algorithm' => 'decorrelated_jitter',
        'backoff_base' => 100,
        'backoff_cap' => 1000,
    ],

    'cache' => [
        'url' => env('REDIS_URL'),
        'host' => env('REDIS_HOST', '127.0.0.1'),
        'username' => env('REDIS_USERNAME'),
        'password' => env('REDIS_PASSWORD'),
        'port' => env('REDIS_PORT', '6379'),
        'database' => env('REDIS_CACHE_DB', '1'),
        'max_retries' => env('REDIS_MAX_RETRIES', 3),
        'backoff_algorithm' => 'decorrelated_jitter',
        'backoff_base' => 100,
        'backoff_cap' => 1000,
    ],
],
```

### 3.2 Queue Configuration (`config/queue.php`)
```php
'redis' => [
    'driver' => 'redis',
    'connection' => env('REDIS_QUEUE_CONNECTION', 'default'),
    'queue' => env('REDIS_QUEUE', 'default'),
    'retry_after' => (int) env('REDIS_QUEUE_RETRY_AFTER', 660), // Must exceed job timeout
    'block_for' => (int) env('REDIS_QUEUE_BLOCK_FOR', 5),       // Blocking pop
    'after_commit' => false,
],
```

---

## 4. Queue Topology & Priorities

OmniPublish defines dedicated logical queues to isolate high-priority operations from long-running video uploads:

```text
[High Priority]     publishing     (Destination publishing dispatch and external API calls)
[Medium Priority]   media          (Media verification and processing)
[Standard]          default        (User onboarding, welcome emails, sync tasks)
[Low Priority]      analytics      (Platform metrics synchronization and polling)
```

Workers prioritize queues in sequential order:
```bash
php artisan queue:work redis --queue=publishing,media,default,analytics
```

---

## 5. Distributed Locking & Idempotency Rules

Every asynchronous publishing job (`PublishDestinationJob`) enforces atomic execution via Redis distributed locking:

```php
$lockKey = "publish_lock_job_{$job->id}";
$lock = Cache::lock($lockKey, 660); // 660s TTL aligns with 600s job timeout

if (!$lock->get()) {
    Log::warning("PublishJob {$job->id} is already acquired by another worker. Skipping.");
    return;
}
```

- **Lock Release:** Guaranteed via `try ... finally { $lock->release(); }`.
- **Fail-safe Expiry:** If a worker crashes hard (SIGKILL / hardware failure), the lock key automatically expires after 660 seconds, allowing the queue's `retry_after` window to reclaim the unacknowledged job cleanly.

---

## 6. Disaster Recovery & Sentinel / Cluster High Availability

For production deployments:
1. **AWS ElastiCache Redis:** Deploy Multi-AZ with Auto-Failover enabled.
2. **Persistence (RDB + AOF):**
   ```text
   save 900 1
   appendonly yes
   appendfsync everysec
   ```
3. **Connection Testing:**
   ```bash
   php artisan queue:monitor redis:publishing,redis:media,redis:default --max=100
   ```
