# Product Requirements Document

## 1. Problem

Creators and businesses repeatedly upload the same video and manually recreate titles, descriptions, hashtags, tags, thumbnails, visibility settings, and schedules on different platforms.

## 2. Solution

A centralized publishing hub where one source video can be prepared once and dispatched to multiple connected platform accounts.

## 3. Core entities

- User
- Workspace
- Membership
- SocialAccount
- Platform
- MediaAsset
- ContentItem
- ContentVariant
- PublishJob
- PublishAttempt
- Schedule
- AuditEvent
- WebhookEvent
- UsageRecord

## 4. Functional requirements

### Account and workspace
- registration/login
- workspace creation
- role-based access
- session management
- account deletion/export

### Social account connections
- OAuth connect/disconnect
- token refresh where supported
- encrypted credential storage
- account health/status
- required scopes display
- reconnect flow after authorization failure

### Media
- resumable/large upload strategy
- object storage
- MIME/type validation
- size/duration validation
- thumbnail support
- media processing status
- checksum/deduplication

### Composer
- global title/caption/description
- hashtags
- tags where supported
- thumbnail
- visibility/privacy
- destination selection
- per-platform overrides
- validation before publish

### Publishing
- publish now
- scheduled publishing
- queue-based workers
- idempotency
- platform-specific retries
- status timeline
- partial success
- retry failed destinations

### Reporting
- publishing summary
- per-platform external ID/URL when available
- error category
- timestamps
- retry history

## 5. UX states

Every destination must be able to display:
`DRAFT`, `QUEUED`, `UPLOADING`, `PROCESSING`, `PUBLISHED`, `FAILED`, `CANCELLED`, `AUTH_REQUIRED`, `UNSUPPORTED`, `RETRYING`.

## 6. Product rule

A multi-platform publish is not one atomic transaction. It is a collection of independent destination jobs. The UI must never imply that all destinations succeeded merely because the parent publish request was accepted.

## 7. MVP acceptance criteria

- User can connect supported accounts.
- User can upload a video.
- User can select multiple destinations.
- User can override metadata per destination.
- User can publish without waiting for all uploads in the browser request.
- Each destination has an independent result.
- Failed destinations can be retried safely.
- Tokens are never exposed to the frontend.
- Audit events are recorded.
