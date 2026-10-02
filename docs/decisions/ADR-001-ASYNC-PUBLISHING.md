# ADR-001: Use asynchronous publishing jobs

## Decision
Publishing is handled by durable background jobs.

## Why
Uploads and provider processing can take significant time and can fail independently. A browser request must not remain open until every platform finishes.

## Consequences
Positive:
- resilient retries
- independent destination status
- horizontal worker scaling
- better observability

Trade-off:
- more infrastructure
- eventual consistency
- more state management
