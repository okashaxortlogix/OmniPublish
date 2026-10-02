# System Architecture

## Recommended stack

### Frontend
- Next.js (App Router) + TypeScript
- React 19 / 18
- Tailwind CSS (Studio Cyber-Dark Theme)
- Lucide React Icons
- TanStack Query / React Query
- Zod & Client-side Preflight Validation

### Backend
- Laravel PHP (PHP 8.2+)
- Modular Architecture: Controllers, FormRequests, Services, Repositories, Platform Adapters
- PostgreSQL / SQLite (Multi-driver Eloquent migrations)
- Redis / Database Queue for distributed asynchronous job processing
- Laravel Sanctum for API token & session authentication
- AES-256 encrypted credential vault for OAuth tokens

### Storage
- S3-compatible object storage
- CDN for user-facing media where appropriate

### Media
- FFmpeg worker/container
- metadata extraction
- thumbnail generation
- optional platform-specific transcoding

### Observability
- OpenTelemetry
- structured logs
- metrics
- error tracking
- queue dashboards

### Deployment
- Docker
- managed PostgreSQL
- managed Redis
- object storage
- CI/CD

## Logical architecture

```text
Browser
  |
  v
Next.js Web App
  |
  v
API Gateway / Backend
  |
  +---- PostgreSQL
  |
  +---- Redis / Queue
  |
  +---- Object Storage
  |
  +---- OAuth / Platform APIs
  |
  +---- Media Processing Workers
  |
  +---- Publishing Workers
```

## Publishing architecture

```text
Create Publish Request
        |
        v
Validate Content + Destination Capabilities
        |
        v
Create PublishBatch
        |
        +--> YouTube PublishJob --> Worker --> Platform API
        |
        +--> TikTok PublishJob  --> Worker --> Platform API
        |
        +--> Instagram Job     --> Worker --> Platform API
        |
        +--> Future adapters...
        |
        v
Aggregate status for dashboard
```

## Adapter pattern

Every platform implements a common interface:

```ts
interface PlatformAdapter {
  getCapabilities(): PlatformCapabilities;
  validate(input: PublishInput): ValidationResult;
  refreshAuth?(account: SocialAccount): Promise<AuthResult>;
  createPublishJob(input: PublishInput): Promise<RemotePublishResult>;
  getPublishStatus?(job: RemoteJob): Promise<RemoteStatus>;
  cancel?(job: RemoteJob): Promise<void>;
}
```

Never put platform-specific logic inside generic publishing services.

## Important design rule

The generic orchestrator knows **what** to publish. The platform adapter knows **how** that platform publishes it.
