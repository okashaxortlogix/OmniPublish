# API Specification

Base path: `/api/v1`

## Authentication

- `POST /auth/register`
- `POST /auth/login`
- `POST /auth/logout`
- `POST /auth/refresh`

## Workspaces

- `GET /workspaces`
- `POST /workspaces`
- `GET /workspaces/:id`
- `PATCH /workspaces/:id`

## Social accounts

- `GET /social-accounts`
- `GET /social-accounts/:id`
- `POST /social-accounts/:platform/connect`
- `GET /oauth/:platform/callback`
- `POST /social-accounts/:id/reconnect`
- `DELETE /social-accounts/:id`

## Media

- `POST /media/uploads/initiate`
- `POST /media/uploads/:id/complete`
- `GET /media`
- `GET /media/:id`
- `DELETE /media/:id`

## Content

- `POST /content`
- `GET /content`
- `GET /content/:id`
- `PATCH /content/:id`
- `DELETE /content/:id`

## Publishing

- `POST /content/:id/publish`
- `POST /content/:id/schedule`
- `GET /publish-batches/:id`
- `GET /publish-batches/:id/jobs`
- `POST /publish-jobs/:id/retry`
- `POST /publish-batches/:id/cancel`

## Example publish request

```json
{
  "destinations": [
    {
      "socialAccountId": "uuid",
      "overrides": {
        "title": "Platform-specific title",
        "caption": "Platform-specific caption",
        "hashtags": ["#example"]
      }
    }
  ],
  "mode": "NOW",
  "idempotencyKey": "client-generated-stable-key"
}
```

## API requirements

Every mutating endpoint should define:
- authorization requirements
- validation schema
- idempotency behavior where applicable
- error codes
- audit event
- rate limit
- expected async behavior
