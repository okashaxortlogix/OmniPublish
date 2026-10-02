# Platform Adapter Specification

Each adapter must document:

1. Authentication mechanism.
2. Required OAuth scopes.
3. Supported media types.
4. Maximum media size/duration constraints.
5. Caption/title/description rules.
6. Hashtag/tag rules.
7. Thumbnail behavior.
8. Visibility/privacy options.
9. Scheduling capability.
10. Async processing behavior.
11. Rate limits.
12. Webhook/polling behavior.
13. Error mapping.
14. Account-type restrictions.
15. App-review requirements.
16. Current API version/date verified.

## Adapter directory

```text
backend/src/platforms/
  platform-core/
    platform-adapter.ts
    capability.types.ts
    platform-error.ts
  youtube/
  tiktok/
  instagram/
  facebook/
  linkedin/
  x/
  pinterest/
```

## Rule

Platform documentation must be verified before implementation. Do not hard-code assumptions from another platform's behavior.
