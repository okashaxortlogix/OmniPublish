# Database Schema

## users
- id UUID PK
- email unique
- password_hash nullable if social login is added
- status
- created_at
- updated_at

## workspaces
- id UUID PK
- name
- owner_user_id FK
- created_at
- updated_at

## memberships
- id UUID PK
- workspace_id FK
- user_id FK
- role
- created_at

## social_accounts
- id UUID PK
- workspace_id FK
- platform
- external_account_id
- display_name
- encrypted_access_token
- encrypted_refresh_token nullable
- token_expires_at nullable
- scopes JSONB
- status
- metadata JSONB
- created_at
- updated_at

Unique constraint:
`workspace_id + platform + external_account_id`

## media_assets
- id UUID PK
- workspace_id FK
- object_key
- original_filename
- mime_type
- size_bytes
- duration_seconds
- width
- height
- checksum
- processing_status
- created_at

## content_items
- id UUID PK
- workspace_id FK
- media_asset_id FK
- title
- description
- hashtags JSONB
- tags JSONB
- thumbnail_asset_id nullable
- status
- created_at
- updated_at

## content_variants
- id UUID PK
- content_item_id FK
- platform
- title nullable
- caption nullable
- description nullable
- hashtags JSONB
- tags JSONB
- settings JSONB

Unique:
`content_item_id + platform`

## publish_batches
- id UUID PK
- content_item_id FK
- mode
- scheduled_for nullable
- status
- idempotency_key
- created_by FK
- created_at
- started_at nullable
- completed_at nullable

## publish_jobs
- id UUID PK
- batch_id FK
- social_account_id FK
- platform
- status
- attempt_count
- remote_post_id nullable
- remote_url nullable
- last_error_code nullable
- last_error_message nullable
- next_retry_at nullable
- created_at
- updated_at

Unique:
`batch_id + social_account_id`

## publish_attempts
- id UUID PK
- publish_job_id FK
- attempt_number
- started_at
- finished_at
- result
- provider_request_id nullable
- error_code nullable
- error_message nullable
- metadata JSONB

## audit_events
- id UUID PK
- workspace_id
- actor_user_id nullable
- event_type
- resource_type
- resource_id
- metadata JSONB
- created_at

## webhook_events
- id UUID PK
- platform
- external_event_id
- payload JSONB
- received_at
- processed_at nullable
- status

Unique:
`platform + external_event_id`

## usage_records
- id UUID PK
- workspace_id
- metric
- quantity
- period_start
- period_end
