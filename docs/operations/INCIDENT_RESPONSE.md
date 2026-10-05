# OmniPublish — Incident Response Playbook

**Document Version:** 1.0 (Phase 3E Certified)  
**Classification:** SRE & On-Call Emergency Procedures  

---

## 1. Incident Severity Definitions

- **SEV-1 (Critical Outage):** Core publishing halted across all platforms, database unreachable, or data loss imminent.
- **SEV-2 (Major Degradation):** Single platform down (e.g., TikTok API down while YouTube works), media processing backlog growing rapidly, or worker crash loop.
- **SEV-3 (Moderate Impact):** Non-blocking UI defect, elevated retry rate on specific platform, isolated customer OAuth token expiration.

---

## 2. Component Failure Runbooks

### 2.1 API Outage (HTTP 502 / 503 / 504)
**Symptoms:** Front-end shows network errors; `/health/live` returns non-200 or times out.  
**Triage Steps:**
1. Check container/pod status:
   ```bash
   kubectl get pods -l app=omnipublish-api
   # or docker ps | grep omnipublish-api
   ```
2. Inspect recent container crash logs:
   ```bash
   kubectl logs --tail=100 -l app=omnipublish-api
   ```
3. Test backend readiness directly from internal network:
   ```bash
   curl -I http://omnipublish-api:8000/health/ready
   ```
4. If process is memory exhausted or blocked, execute zero-downtime rolling restart:
   ```bash
   kubectl rollout restart deployment/omnipublish-api
   ```

---

### 2.2 Database Outage (PostgreSQL Unreachable / Degraded)
**Symptoms:** Readiness probe reports `checks.database: UNHEALTHY`; API returns 503.  
**Triage Steps:**
1. Check RDS / PostgreSQL server status and connection pool usage:
   - Verify connection pool exhaustion (max connections exceeded).
   - Check if automated failover is in progress on cloud database cluster.
2. In case of idle connection leakage:
   ```sql
   SELECT pid, state, query_start, query 
   FROM pg_stat_activity 
   WHERE state = 'idle in transaction' AND query_start < NOW() - INTERVAL '5 minutes';
   ```
3. Terminate hung connections:
   ```sql
   SELECT pg_terminate_backend(pid) 
   FROM pg_stat_activity 
   WHERE state = 'idle in transaction' AND query_start < NOW() - INTERVAL '10 minutes';
   ```
4. Restart application connection pools if necessary.

---

### 2.3 Redis Outage / Redis Lock Stalls
**Symptoms:** Readiness probe reports `checks.redis: UNHEALTHY`; queue jobs fail to dispatch; distributed locks cannot be acquired.  
**Triage Steps:**
1. Ping Redis:
   ```bash
   redis-cli -h $REDIS_HOST -p 6379 ping
   ```
2. If Redis restarted or memory limit reached (OOM):
   - Check memory stats: `redis-cli info memory`
   - Ensure `maxmemory-policy` is set to `noeviction` for queue safety or `volatile-lru` for cache.
3. Drain stale publishing/token locks if lock TTL failed to expire during abnormal worker termination:
   ```bash
   # Check lock keys
   redis-cli keys "omnipublish:lock:*"
   ```
4. Application workers automatically reconnect via `phpredis` retry logic when Redis comes back online.

---

### 2.4 Object Storage Outage (S3 / R2 Unreachable)
**Symptoms:** Media upload confirmations fail; FFmpeg video download jobs fail with `StorageException`.  
**Triage Steps:**
1. Check cloud provider status dashboard (AWS S3 Service Health or Cloudflare Status).
2. Test presigned URL generation and access from CLI:
   ```bash
   aws s3 ls s3://$AWS_BUCKET/ --endpoint-url=$AWS_ENDPOINT
   ```
3. Queued media processing jobs will automatically back off and retry up to 3 times without data loss.

---

### 2.5 Media Worker / FFmpeg Crash
**Symptoms:** Video jobs stuck in `processing`; worker container restarts with exit code 137 (OOM killed).  
**Triage Steps:**
1. Inspect video processing log for specific job:
   ```bash
   grep "FFmpeg error" storage/logs/laravel.log
   ```
2. Check memory limit: Video transcoding large files (up to 4 GB) requires at least 2 GB RAM per worker pod.
3. If a corrupted video crashed the worker:
   - Worker restart triggers `publishing:reap-stalled` command to transition orphaned jobs to `failed`.
   - The user will see `Invalid or corrupted media file` in the composer interface.

---

### 2.6 External Social Provider Outage (YouTube / TikTok / Instagram)
**Symptoms:** Elevated failure rate for a specific platform; `error_category` in database recorded as `remote_server_error` or `rate_limited`.  
**Triage Steps:**
1. Check platform developer status:
   - Google Workspace / YouTube Data API Status
   - TikTok Developer Portal status
   - Meta Platform Status
2. OmniPublish Circuit Breaker & Retry Engine:
   - Transient 5xx and 429 errors automatically trigger exponential backoff.
   - Other platforms (e.g. YouTube while TikTok is down) continue publishing normally due to strict per-job failure isolation.
3. If platform outage lasts > 24 hours:
   - Notify affected workspace owners.
   - Reconcile jobs via `php artisan publishing:reconcile` once the provider recovers.

---

### 2.7 OAuth Token Expiration / Mass Revocation
**Symptoms:** Jobs fail with `error_category: auth_required`; social accounts show `requires_reconnect: true`.  
**Triage Steps:**
1. Verify if automated token refresh job ran:
   ```bash
   php artisan tokens:refresh-expiring
   ```
2. If refresh token was revoked by user or platform security policy:
   - Account state transitions to `requires_reconnect = true`.
   - System stops automated retries to prevent account lockout.
   - User is prompted in the UI to reconnect their channel.
