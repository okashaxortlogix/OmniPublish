#!/usr/bin/env bash
# ==============================================================================
# OmniPublish — Post-Deployment Automated Smoke Test Script
# Usage: ./smoke_test.sh <BASE_URL>
# Example: ./smoke_test.sh https://staging-api.omnipublish.com
# ==============================================================================

set -euo pipefail

BASE_URL="${1:-http://127.0.0.1:8001}"
BASE_URL="${BASE_URL%/}" # Remove trailing slash

GREEN='\033[0;32m'
RED='\033[0;31m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

echo "===================================================================="
echo " OmniPublish Post-Deployment Smoke Test Runner"
echo " Target Base URL: ${BASE_URL}"
echo " Timestamp: $(date -u +"%Y-%m-%dT%H:%M:%SZ")"
echo "===================================================================="

FAILURES=0

test_endpoint() {
    local name="$1"
    local path="$2"
    local expected_status="$3"
    local required_json_key="${4:-}"

    echo -n "Testing [${name}] (${path}) ... "

    response=$(curl -s -w "\n%{http_code}" -m 10 "${BASE_URL}${path}" || echo -e "\n000")
    http_code=$(echo "$response" | tail -n1)
    body=$(echo "$response" | sed '$d')

    if [ "$http_code" -eq "$expected_status" ]; then
        if [ -n "$required_json_key" ]; then
            if echo "$body" | grep -q "\"$required_json_key\""; then
                echo -e "${GREEN}PASS (HTTP ${http_code}, matched key '${required_json_key}')${NC}"
            else
                echo -e "${RED}FAIL (HTTP ${http_code}, missing key '${required_json_key}')${NC}"
                echo "Response body: $body"
                FAILURES=$((FAILURES + 1))
            fi
        else
            echo -e "${GREEN}PASS (HTTP ${http_code})${NC}"
        fi
    else
        echo -e "${RED}FAIL (Expected HTTP ${expected_status}, got ${http_code})${NC}"
        echo "Response body: $body"
        FAILURES=$((FAILURES + 1))
    fi
}

# 1. Health & Process Probes
test_endpoint "Liveness Probe" "/health/live" 200 "status"
test_endpoint "Readiness Probe" "/health/ready" 200 "checks"
test_endpoint "Root Health" "/health" 200 "status"
test_endpoint "API v1 Health Probe" "/api/v1/health" 200 "status"

# 2. Release & Version Identification
test_endpoint "Release Version Endpoint" "/version" 200 "commit_sha"
test_endpoint "API v1 Version Endpoint" "/api/v1/version" 200 "application"

# 3. Core API Endpoints
test_endpoint "Workspaces Index" "/api/v1/workspaces" 200 "success"
test_endpoint "Publish Batches Index" "/api/v1/publish/batches" 200 "success"
test_endpoint "Social Accounts Index" "/api/v1/social-accounts" 200 "success"
test_endpoint "Calendar Index" "/api/v1/calendar" 200 "success"

# 4. Error Handling
test_endpoint "Unknown Route 404 Check" "/api/v1/non-existent-probe-test" 404 ""

echo "===================================================================="
if [ "$FAILURES" -eq 0 ]; then
    echo -e "${GREEN}SMOKE TEST SUITE PASSED — ALL PROBES HEALTHY${NC}"
    exit 0
else
    echo -e "${RED}SMOKE TEST SUITE FAILED WITH ${FAILURES} ERRORS${NC}"
    exit 1
fi
