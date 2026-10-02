# Observability

## Logs
Use structured JSON logs with:
- request_id
- workspace_id
- user_id where appropriate
- publish_job_id
- platform
- event
- duration
- result

Never log:
- OAuth tokens
- passwords
- full sensitive provider payloads

## Metrics
- API latency
- upload throughput
- queue depth
- job duration
- publish success/failure by platform
- retry rate
- auth failure rate
- provider rate-limit events
- worker utilization

## Tracing
Trace:
`HTTP request → DB → queue → worker → adapter → provider`

## Alerts
- queue backlog above threshold
- elevated provider failures
- worker crash loop
- token refresh failure spike
- object storage failures
- database saturation
