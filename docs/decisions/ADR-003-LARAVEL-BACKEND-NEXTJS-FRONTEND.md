# ADR-003: Architecture Selection — Laravel PHP Backend & Next.js React Frontend

## Status
Accepted

## Context
OmniPublish requires a production-grade, highly resilient SaaS architecture with:
1. Distributed asynchronous job queues, database transactions, robust ORM, encryption, and scheduled tasks.
2. An ultra-responsive, studio-grade frontend with rich interactive previews (live 3-column composer, mobile phone simulation, real-time publishing monitor).

The user explicitly designated:
- **Backend**: Laravel PHP (PHP 8.2+)
- **Frontend**: Next.js & React with TypeScript

## Decision
1. **Backend Service (`backend/`)**:
   - Built with **Laravel 11 / PHP 8.2**.
   - **Database**: PostgreSQL (with SQLite for instant lightweight automated testing & local development).
   - **ORM**: Eloquent with strict UUID primary keys, foreign keys, timestamps, and tenant scoping.
   - **Queue & Async Execution**: Laravel Queue (Database / Redis driver) with database jobs table, failed jobs table, and artisan queue workers.
   - **Security**: Laravel Sanctum for API token & session authentication, AES-256-CBC/GCM encryption via Laravel `Crypt` facade, strict CORS, rate-limiting (`throttle:api`), and request validation (`FormRequest`).
   - **Platform Engine**: Object-oriented platform adapters implementing `PlatformAdapterInterface`, managed by `PlatformManager`.

2. **Frontend Service (`frontend/`)**:
   - Built with **Next.js App Router (React + TypeScript)**.
   - **Styling**: Tailwind CSS configured with the OmniPublish Cyber-Minimalist Studio Dark palette (`docs/ui/DESIGN_SYSTEM.md`).
   - **State & Data Fetching**: TanStack Query / SWR with shared typed API client.
   - **Validation**: Client-side validation mirrors backend constraints via preflight rules.

## Consequences
- Clean separation between API backend and Next.js frontend.
- Robust, battle-tested queue management with exponential backoff and failed job logging provided natively by Laravel.
- High developer velocity and enterprise security.
