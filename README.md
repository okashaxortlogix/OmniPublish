# OmniPublish — Upload Once, Publish Everywhere

OmniPublish is a multi-platform social video publishing SaaS concept:
upload a video once, prepare shared and platform-specific metadata, connect multiple social accounts, and publish or schedule the content across supported platforms from one dashboard.

## Product principle

**Upload Once → Customize Once → Publish Everywhere → Track Everything**

## Supported product direction

### MVP
- YouTube
- TikTok
- Instagram
- Central video/content library
- Platform account connections
- One-click multi-platform publishing
- Platform-specific metadata overrides
- Background publishing queue
- Per-platform status and retry
- Basic scheduling
- Audit logs
- Secure OAuth/token handling

### Expansion
- Facebook
- LinkedIn
- X
- Pinterest
- More platform adapters
- Analytics aggregation
- AI metadata generation
- Automatic aspect-ratio/transcoding workflows
- Team workspaces and approval flows
- Content calendar
- Advanced scheduling
- Billing and usage limits

## Important platform rule

Never assume that every platform supports the same publishing operation. Each adapter must implement only the capabilities officially exposed by that platform's current API, with account-type, permission, media-format, review, and quota constraints represented explicitly in the system.

## Repository structure

See `docs/architecture/`, `docs/product/`, `docs/platforms/`, `docs/api/`, `docs/database/`, `docs/security/`, `docs/testing/`, `docs/deployment/`, and `phases/`.

This package is a detailed implementation blueprint and starter repository structure. It intentionally does not contain private API credentials, production OAuth secrets, or fabricated platform API guarantees.
