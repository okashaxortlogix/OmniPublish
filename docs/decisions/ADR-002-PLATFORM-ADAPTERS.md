# ADR-002: Isolate platform-specific logic

## Decision
Use one adapter per platform behind a shared interface.

## Why
Platforms differ in authentication, media rules, metadata, asynchronous processing, errors, quotas, and permissions.

## Consequence
Adding a platform should not require rewriting the generic publishing engine.
