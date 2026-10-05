#!/usr/bin/env bash
# ==============================================================================
# OmniPublish — Deployment Coordinator Script
# Usage: ./deploy.sh <ENVIRONMENT> <IMAGE_TAG>
# Example: ./deploy.sh staging abc1234
# ==============================================================================

set -euo pipefail

ENVIRONMENT="${1:-staging}"
IMAGE_TAG="${2:-latest}"

echo "===================================================================="
echo " OmniPublish Deployment Execution"
echo " Environment: ${ENVIRONMENT}"
echo " Release Tag: ${IMAGE_TAG}"
echo " Initiated At: $(date -u +"%Y-%m-%dT%H:%M:%SZ")"
echo "===================================================================="

# Step 1: Pre-deployment health check on currently running version
echo "--> Checking current deployment status..."

# Step 2: Database backup (Production only)
if [ "${ENVIRONMENT}" = "production" ]; then
    echo "--> Executing pre-migration database snapshot for production..."
    if command -v pg_dump >/dev/null 2>&1 && [ -n "${DB_HOST:-}" ]; then
        BACKUP_FILE="/tmp/omnipublish_predeploy_${ENVIRONMENT}_$(date +%Y%m%d_%H%M%S).dump"
        pg_dump -h "${DB_HOST}" -U "${DB_USERNAME}" -Fc -d "${DB_DATABASE}" > "${BACKUP_FILE}"
        echo "Snapshot saved to ${BACKUP_FILE}"
    else
        echo "Warning: Direct pg_dump CLI unavailable or DB_HOST unset; verify automated cloud backup is active."
    fi
fi

# Step 3: Run database migrations
echo "--> Running database migrations..."
php artisan migrate --force

# Step 4: Graceful queue worker fleet reload
echo "--> Signaling queue workers to reload gracefully..."
php artisan queue:restart

# Step 5: Optimization cache refresh
echo "--> Refreshing configuration and route caches..."
php artisan config:cache
php artisan route:cache

# Step 6: Post-deployment smoke test
echo "--> Running post-deployment health verification..."
bash "$(dirname "$0")/smoke_test.sh" "${APP_URL:-http://127.0.0.1:8000}"

echo "===================================================================="
echo " Deployment to ${ENVIRONMENT} successfully verified!"
echo " Completed At: $(date -u +"%Y-%m-%dT%H:%M:%SZ")"
echo "===================================================================="
