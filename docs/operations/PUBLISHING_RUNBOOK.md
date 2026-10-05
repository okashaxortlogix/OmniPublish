# OmniPublish Operations Runbook

**Service:** OmniPublish Publishing Orchestration  
**Platform Scope:** YouTube, TikTok, Instagram  
**Severity Levels:** P0 (Total Outage), P1 (Provider Outage), P2 (Degraded Performance)  

---

## 1. Routine Operational Commands

### 1.1 Manual Job Reaping
If workers crashed during unexpected infrastructure restarts:
```bash
php artisan publishing:reap-stalled
```

### 1.2 Manual Job Reconciliation
Force an immediate status synchronization against remote provider APIs:
```bash
php artisan publishing:reconcile
```

### 1.3 Trigger Scheduled Dispatch Manually
Check and immediately dispatch due scheduled batches:
```bash
php artisan publishing:dispatch-scheduled
```

### 1.4 Refresh Expiring OAuth Tokens
Proactively refresh any platform access tokens expiring in the next 24 hours:
```bash
php artisan tokens:refresh-expiring
```

---

## 2. Incident Playbooks

### Playbook A: Provider Outage (e.g. Meta Graph API 5xx / YouTube Outage)
1. **Symptom:** Influx of jobs transitioning to `RETRYING` or `FAILED` with category `PROVIDER_UNAVAILABLE`.
2. **Verification:** Inspect logs with correlation ID:
   ```bash
   grep -i "PROVIDER_UNAVAILABLE" storage/logs/laravel.log | tail -n 50
   ```
3. **Action:**
   - Provider isolation automatically prevents unrelated platforms from failing.
   - Transient backoff triggers automatically (up to 4 attempts).
   - If provider outage exceeds 1 hour, notify users on the dashboard status banner.
   - Do NOT manually mass-retry while provider is down.

### Playbook B: Bulk OAuth Token Invalidation (e.g. App Secret Rotated)
1. **Symptom:** Jobs transition immediately to `AUTH_REQUIRED` with failure category `AUTHENTICATION`.
2. **Action:**
   - Jobs are deliberately NOT retried automatically to avoid provider spam.
   - Direct creators to `/connections` in the UI to perform a 1-click account reconnect.
   - Once reconnected, users click "Retry" on their jobs in `/publishing`.

### Playbook C: Redis Queue Stoppage
1. **Symptom:** Jobs created in DB as `QUEUED`, but worker processing ceases.
2. **Action:**
   - Check Redis connectivity: `redis-cli ping`.
   - Restart worker pool: `supervisorctl restart omnipublish-publishing-worker:*`.
   - Run `php artisan publishing:reconcile` to recover any in-flight transitions.
