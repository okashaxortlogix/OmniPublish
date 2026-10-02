# OmniPublish — GSD Master Execution Plan

**Version**: 1.0.0-PROD  
**Framework**: Get Shit Done (GSD) Spec-Driven Milestone Architecture  
**Execution Objective**: Zero to Production SaaS

---

## 1. GSD Execution Methodology

OmniPublish adheres to a strict spec-driven, milestone-gated delivery cycle:
1. **Spec First**: Every phase is preceded by a strict schema, contract, or capability document.
2. **Atomic Verification**: No phase is considered complete without passing its automated verification gate (compilation, lint, unit tests, integration tests, security audit).
3. **Continuous State Tracking**: Progress is tracked via persistent file-based artifacts (`.agent/tasks.json`, `docs/audit/BUG_REGISTER.md`).

---

## 2. Phase Breakdown & Milestones

### Phase 0: Discovery & API Verification
- **Goal**: Audit repository, initialize monorepo infrastructure, verify official platform APIs.
- **Tasks**:
  - [x] Initial Repository Audit (`docs/audit/INITIAL_REPOSITORY_AUDIT.md`)
  - [x] UI & Design System Specification (`docs/ui/UI_SPEC.md`, `docs/ui/DESIGN_SYSTEM.md`)
  - [ ] YouTube Data API v3 Verification (`docs/platforms/youtube-verified-capabilities.md`)
  - [ ] TikTok Content Posting API v2 Verification (`docs/platforms/tiktok-verified-capabilities.md`)
  - [ ] Instagram Graph API (Reels) Verification (`docs/platforms/instagram-verified-capabilities.md`)
  - [ ] Monorepo & Git Initialization (`package.json`, `turbo.json`, `tsconfig.base.json`, `.gitignore`)
- **Exit Gate**: All 3 MVP platform specs verified against official docs; Monorepo structure compiles.

---

### Phase 1: Foundation & Shared Packages
- **Goal**: Core type definitions, Zod validation schemas, database schema & migrations, encryption utilities.
- **Tasks**:
  - [ ] `@omnipublish/types`: Domain models, state machines, DTOs, platform capability interfaces.
  - [ ] `@omnipublish/validation`: Input validation schemas for user, workspace, composer, and publishing.
  - [ ] `@omnipublish/crypto`: AES-256-GCM token encryption and Argon2id password hashing utilities.
  - [ ] Database Schema: PostgreSQL schema definitions & migrations for all 15 core entities.
- **Exit Gate**: 100% type coverage, unit tests for validation & crypto passing.

---

### Phase 2: Authentication & Multi-Tenant Workspaces
- **Goal**: Secure auth service, session management, multi-tenant workspace isolation.
- **Tasks**:
  - [ ] User registration, login, logout, password reset flow.
  - [ ] Workspace creation, invitation, and RBAC (`OWNER`, `ADMIN`, `EDITOR`, `VIEWER`).
  - [ ] Multi-tenant isolation middleware & security test suite (`tests/security/tenant-isolation/`).
  - [ ] Frontend Auth views (Login, Register, Workspace Onboarding) with studio styling.
- **Exit Gate**: Security tests prove zero cross-tenant data leakage.

---

### Phase 3: Social Account Connection Framework
- **Goal**: Extensible OAuth manager, token lifecycle, platform connection cards.
- **Tasks**:
  - [ ] Base `PlatformAdapter` abstract class & connection lifecycle.
  - [ ] Secure OAuth state & PKCE management.
  - [ ] Token refresh worker & expiration notification alerts.
  - [ ] Frontend Connections Hub with live status pills, scope breakdown, and reconnect triggers.
- **Exit Gate**: Mock OAuth handshake succeeds; tokens stored encrypted; reconnect flows tested.

---

### Phase 4: Media Asset Pipeline
- **Goal**: Secure video upload, MIME inspection, metadata extraction, thumbnail generation.
- **Tasks**:
  - [ ] Direct-to-storage presigned upload URL architecture.
  - [ ] Media processing worker with FFmpeg metadata inspection (resolution, duration, fps, codecs).
  - [ ] Safe thumbnail extraction & aspect ratio validation (9:16, 16:9, 1:1).
  - [ ] Frontend Media Library with drag-and-drop uploader and live progress.
- **Exit Gate**: Untrusted file rejection tests pass; video metadata extracted accurately.

---

### Phase 5: Studio 3-Column Composer
- **Goal**: World-class composer with global metadata, platform overrides, and real-time native mobile preview.
- **Tasks**:
  - [ ] Global metadata state management (Title, Description, Hashtags, Thumbnail).
  - [ ] Platform-specific override tabs (YouTube tags/visibility, TikTok comment flags, Instagram caption).
  - [ ] Preflight validator checking platform constraints in real-time.
  - [ ] Interactive live mobile phone preview simulator.
- **Exit Gate**: Composer validates constraints dynamically across YouTube, TikTok, and Instagram.

---

### Phase 6: Publishing Orchestration Engine
- **Goal**: Resilient distributed publishing engine with BullMQ, Redis locks, idempotency, and retries.
- **Tasks**:
  - [ ] `PublishBatch` & `PublishJob` state machine (`CREATED` → `QUEUED` → `UPLOADING` → `PROCESSING` → `PUBLISHED`/`FAILED`).
  - [ ] Idempotency guard: Redis lock + remote ID check before dispatch.
  - [ ] Exponential backoff retry engine with jitter for transient errors.
  - [ ] User-friendly error classification (e.g., `AUTH_REQUIRED`, `RATE_LIMITED`, `INVALID_MEDIA`).
- **Exit Gate**: Double-publish simulation test passes without duplicate external post; retries succeed.

---

### Phase 7: MVP Platform Integrations
- **Goal**: Production-ready platform adapters for YouTube, TikTok, and Instagram.
- **Tasks**:
  - [ ] YouTube Data API v3 Adapter (resumable video upload, snippet metadata, category, privacy).
  - [ ] TikTok Content Posting API v2 Adapter (direct post, creator video upload, query status).
  - [ ] Instagram Graph API Adapter (container creation, media upload status check, container publish).
  - [ ] Partial success handler: Independent per-platform execution and single-platform retry.
- **Exit Gate**: End-to-end publishing flow succeeds against mock/sandbox provider endpoints.

---

### Phase 8: Scheduling & Calendar
- **Goal**: UTC-based scheduling engine, timezone translation, and visual content calendar.
- **Tasks**:
  - [ ] Scheduled job scheduler with BullMQ delayed queues.
  - [ ] Rescheduling, time adjustment, and cancellation with distributed lock.
  - [ ] Frontend interactive calendar (Month, Week, Day views) with drag-and-drop support.
- **Exit Gate**: Scheduled jobs execute accurately at scheduled UTC timestamp; cancellation works cleanly.

---

### Phase 9: Real-time Publishing Monitor
- **Goal**: Live pipeline observability, event timeline, and one-click retry for creators.
- **Tasks**:
  - [ ] WebSocket / SSE real-time job status streaming to frontend.
  - [ ] Per-platform execution cards with live progress bars and external links to published posts.
  - [ ] Actionable failure cards with direct "Reconnect" and "Retry" actions.
- **Exit Gate**: Frontend updates live without page refreshes during publishing cycles.

---

### Phase 10 - 17: Hardening, Observability & Final Production Readiness
- **Phase 10**: Analytics synchronization engine.
- **Phase 11**: AI Assistant for smart caption, hashtag, and platform variant generation.
- **Phase 12**: Team permissions, approval workflows, and audit logging.
- **Phase 13**: Billing, usage limits, and subscription tiers.
- **Phase 14**: Comprehensive Security Audit (OWASP Top 10, penetration tests).
- **Phase 15**: Performance, load testing, and database query optimization.
- **Phase 16**: Production Docker & CI/CD deployment configuration.
- **Phase 17**: Final Ralph Loop verification & Production Readiness Report.
