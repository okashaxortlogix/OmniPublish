# Phase 4 — Multi-Platform Publishing Engine

## Build
- publish batch
- destination jobs
- queue
- worker
- adapter interface
- MVP platform adapters
- idempotency
- retry strategy
- error mapping
- status polling/webhooks
- publish monitor

## Critical test scenarios
- one platform succeeds, one fails
- token expires during publishing
- provider times out
- duplicate queue message
- retry after failure
- worker crashes mid-job
- publish request submitted twice

## Exit criteria
- no duplicate posts under tested retry scenarios
- every destination has an auditable lifecycle
- partial success is represented correctly
