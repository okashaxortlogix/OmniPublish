# OmniPublish — Release Engineering & Versioning Standard

**Document Version:** 1.0 (Phase 3F Certified)  
**System:** OmniPublish Unified Multi-Platform Social Publishing SaaS  
**Standard:** Semantic Versioning 2.0.0 & GitFlow Release Automation  

---

## 1. Release Identification Standard

Every release of OmniPublish is identified by two coordinates:
1. **Semantic Version (SemVer):** `MAJOR.MINOR.PATCH` (e.g. `v1.2.0`).
   - `MAJOR`: Breaking architectural changes, incompatible database redesigns.
   - `MINOR`: New supported platform (e.g., Phase 4 platforms), backward-compatible feature expansions.
   - `PATCH`: Security fixes, platform adapter bug fixes, retry engine optimizations.
2. **Immutable Commit Identifier:** The 40-character Git commit SHA (e.g. `c4d9a1f...`).

Both coordinates are embedded into the running container and queryable via `/version` and `/api/v1/version`.

---

## 2. Release Promotion Pipeline

```text
[ Feature Branch ]
        │
        ▼ (Pull Request)
[ .github/workflows/ci.yml ]
   • Gitleaks & dependency audits
   • Postgres 16 clean migration test
   • 151 backend tests
   • Frontend lint, typecheck, production build
        │
        ▼ (Merge to develop)
[ Staging Deployment ]
   • Automated deployment to staging.omnipublish.com
   • Staging automated smoke test suite
   • QA verification
        │
        ▼ (PR to main)
[ Release Candidate Tagged ]
   • Git tag: vX.Y.Z
   • GitHub Release draft generated
        │
        ▼ (Manual Approval Gate)
[ Production Deployment ]
   • Pre-migration snapshot
   • Schema migration
   • Rolling container update
   • Post-deploy smoke test verification
```

---

## 3. Hotfix Release Procedure

When a critical production defect occurs (e.g. platform API breaking change or security patch):
1. Branch from `main`: `hotfix/v1.0.1-fix-name`.
2. Apply minimal targeted fix.
3. Validate locally:
   ```bash
   php artisan test
   npm run typecheck
   npm run lint
   npm run build
   ```
4. Open PR directly targeting `main`.
5. Verify CI passes completely.
6. Merge PR and tag: `git tag -a v1.0.1 -m "Hotfix v1.0.1" && git push origin v1.0.1`.
7. Execute `.github/workflows/deploy-production.yml` with tag `v1.0.1`.
8. Backport fix into `develop` to prevent regression.
