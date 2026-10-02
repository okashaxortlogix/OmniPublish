# Failure Handling

## Validation failure
Do not enqueue. Show field-level correction.

## Authentication failure
Set job to `AUTH_REQUIRED`, notify user, provide reconnect path.

## Rate limit
Set retry time according to provider response when available.

## Temporary network/provider failure
Retry with exponential backoff and jitter.

## Permanent provider rejection
Mark failed and preserve provider-safe error information.

## Partial success
Parent batch becomes `PARTIAL_SUCCESS`. Successful destinations remain published. Only failed/retryable destinations are retried.

## Unknown result
If the request may have reached the provider but the response was lost:
1. query provider status when supported,
2. use idempotency/remote identifiers where available,
3. do not blindly create a second publication.
