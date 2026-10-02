# Security Requirements

## OAuth/token security
- Never send platform access/refresh tokens to the browser.
- Encrypt tokens at rest using a managed key/KMS where possible.
- Keep encryption keys outside source control.
- Minimize stored scopes.
- Revoke/delete credentials when account is disconnected.
- Log token events without logging token values.

## Authentication
- Secure password hashing (Argon2id or equivalent).
- HttpOnly, Secure, SameSite cookies for browser sessions where applicable.
- CSRF protection for cookie-authenticated mutation endpoints.
- MFA as a post-MVP security enhancement.
- Session revocation.

## Authorization
Every workspace resource must be authorized through workspace membership and role.

Suggested roles:
- OWNER
- ADMIN
- EDITOR
- VIEWER

## Input security
- Validate MIME types server-side.
- Do not trust client-provided file extensions.
- Limit upload size.
- Sanitize metadata rendering.
- Escape HTML.
- Validate redirects.
- Protect OAuth callback state/PKCE where applicable.

## Media security
- Store user media in private object storage.
- Use short-lived signed URLs.
- Scan/validate files before processing.
- Isolate FFmpeg workers.
- Never execute uploaded files.

## API security
- rate limiting
- request IDs
- structured audit logs
- safe error messages
- dependency scanning
- secret scanning
- security headers

## Privacy
Document:
- what account data is stored,
- why it is stored,
- retention,
- deletion,
- export,
- third-party data processing,
- platform-specific terms.

## Production rule

A platform connection is sensitive data. Treat it as a credentialed integration, not as a simple profile record.
