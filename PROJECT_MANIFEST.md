# Project Manifest

## Goal
Build a reliable SaaS that lets creators manage connected social accounts and publish one video to multiple platforms with platform-aware metadata.

## Primary user journey
1. Create workspace/account.
2. Connect social accounts through OAuth.
3. Upload video.
4. Add global metadata.
5. Select destination platforms.
6. Review platform-specific previews/overrides.
7. Publish now or schedule.
8. Backend creates a publish job.
9. Queue workers process each platform independently.
10. Dashboard displays success/failure and allows safe retry.

## Non-goals for MVP
- Scraping social networks.
- Browser automation as the primary publishing mechanism.
- Storing raw platform passwords.
- Pretending unsupported platform features exist.
- Coupling all publishing into one synchronous HTTP request.
- Claiming guaranteed publication when a platform has asynchronous processing.

## Definition of done
A feature is complete only when:
- product behavior is documented,
- API contract is documented,
- authorization is handled,
- validation exists,
- failure states are modeled,
- tests exist,
- observability exists,
- security review is addressed,
- deployment configuration is documented,
- user-facing errors are understandable.
