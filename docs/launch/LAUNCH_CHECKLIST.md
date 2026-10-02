# Production Launch Checklist

## Product
- [ ] MVP acceptance criteria passed
- [ ] user-facing copy reviewed
- [ ] failure states reviewed

## Platform integrations
- [ ] official docs verified
- [ ] OAuth scopes verified
- [ ] app review completed where required
- [ ] production redirect URIs configured
- [ ] rate limits documented
- [ ] media constraints tested
- [ ] policy/terms requirements reviewed

## Security
- [ ] secrets in secret manager
- [ ] token encryption active
- [ ] logs redacted
- [ ] authorization tests pass
- [ ] dependency scan pass
- [ ] security review complete

## Infrastructure
- [ ] database backups
- [ ] restore test
- [ ] queue monitoring
- [ ] object storage lifecycle
- [ ] alerts
- [ ] rollback procedure

## Reliability
- [ ] duplicate publish test
- [ ] worker crash test
- [ ] token expiry test
- [ ] provider timeout test
- [ ] partial success test
- [ ] retry test

## Launch
- [ ] staging smoke test
- [ ] production canary
- [ ] monitor first publishing jobs
