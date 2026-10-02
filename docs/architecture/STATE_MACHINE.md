# Publishing State Machine

## Parent batch

`DRAFT -> VALIDATING -> QUEUED -> RUNNING -> COMPLETED`

Alternative terminal states:
- `PARTIAL_SUCCESS`
- `FAILED`
- `CANCELLED`

## Destination job

`CREATED -> QUEUED -> UPLOADING -> PROCESSING -> PUBLISHED`

Failure paths:
- `AUTH_REQUIRED`
- `RATE_LIMITED`
- `VALIDATION_FAILED`
- `TRANSIENT_FAILURE`
- `PERMANENT_FAILURE`
- `CANCELLED`

## Retry policy

Retry only errors classified as transient:
- network timeout
- temporary platform outage
- explicit retryable HTTP status
- queue infrastructure failure

Do not blindly retry:
- invalid media
- missing permission
- unsupported account
- policy rejection
- malformed metadata

Use exponential backoff with jitter and a maximum attempt count.

## Idempotency

Each destination job has a stable internal idempotency key.

Before creating a remote publication:
1. lock destination job,
2. check whether a remote ID already exists,
3. if yes, do not create another remote post,
4. otherwise call adapter,
5. persist remote ID immediately when returned.

Retries must not accidentally create duplicate posts.
