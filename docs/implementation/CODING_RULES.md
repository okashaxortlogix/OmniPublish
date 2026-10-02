# Coding Rules

1. TypeScript strict mode.
2. No `any` unless explicitly justified.
3. Validate external input with schemas.
4. Treat provider responses as untrusted input.
5. Keep platform-specific code in adapters.
6. Use database transactions around state changes that must be atomic.
7. Use idempotency for externally visible publishing operations.
8. Never log secrets.
9. Write tests for every state transition.
10. Use feature flags for risky provider integrations.
11. Every new endpoint requires authorization tests.
12. Every external API integration requires timeout handling.
13. Every queue job requires retry classification.
14. Every user-visible error must have a safe actionable message.
15. Do not make unsupported platform features appear in the UI.
