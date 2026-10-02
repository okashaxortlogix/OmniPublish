# Deployment

## Environments

### Local
Docker Compose:
- web
- api
- worker
- postgres
- redis
- object storage emulator
- optional mail service

### Staging
Production-like infrastructure with sandbox/test accounts.

### Production
- CDN
- web application
- API service
- worker autoscaling
- PostgreSQL
- Redis
- object storage
- secret manager
- monitoring
- error tracking

## CI/CD

Pipeline:
1. install
2. lint
3. typecheck
4. unit tests
5. integration tests
6. build
7. dependency/security scan
8. migration validation
9. deploy staging
10. smoke tests
11. manual approval
12. deploy production

## Worker scaling

Publishing workers should scale independently from the web/API tier.

## Backups
- automated database backups
- restore drills
- object-storage lifecycle policy
- disaster recovery documentation

## Health endpoints

- `/health/live`
- `/health/ready`

Readiness should verify required dependencies.
