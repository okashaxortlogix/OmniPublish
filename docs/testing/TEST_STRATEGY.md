# Test Strategy

## Unit tests
- metadata validation
- capability checks
- platform error mapping
- retry classification
- state transitions
- authorization policies
- idempotency logic

## Integration tests
- PostgreSQL repositories
- Redis/queue behavior
- object storage upload flow
- OAuth state/PKCE
- webhook deduplication
- publish job persistence

## Contract tests
Each platform adapter must have:
- request construction tests
- response parsing tests
- error mapping tests
- mocked provider fixtures
- capability tests

## End-to-end tests
Critical flows:
1. create workspace
2. connect mock platform
3. upload media
4. compose content
5. preview destination variants
6. publish
7. observe independent job statuses
8. retry failed job
9. schedule and execute a job
10. disconnect account

## Reliability tests
- worker crash recovery
- duplicate queue message
- provider timeout
- rate limit
- token expiry
- partial multi-platform success
- webhook replay
- database transaction failure

## Definition of release quality

No production release until:
- automated test suite passes,
- migrations are reversible or recovery documented,
- secrets are externalized,
- observability is working,
- critical flows are manually verified in staging,
- platform API versions and permissions have been rechecked.
