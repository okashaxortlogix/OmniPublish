# Risk Register

| Risk | Impact | Mitigation |
|---|---|---|
| Platform API changes | High | Adapter isolation + periodic API verification |
| OAuth token expiry | High | Refresh/reconnect state machine |
| Duplicate publication | High | Idempotency + remote ID persistence |
| Provider rate limits | High | Backoff + per-platform throttling |
| Large video uploads | High | Direct/resumable object storage upload |
| Media incompatibility | High | Capability validation + FFmpeg |
| Partial success | High | Independent destination jobs |
| Provider outage | Medium/High | Retry classification + status visibility |
| Secret leakage | Critical | Encryption + secret manager + log redaction |
| Workspace data leak | Critical | Server-side tenant authorization |
| Queue failure | High | Durable queue + retry/recovery |
| Misleading success UI | High | Confirm provider result before success state |
