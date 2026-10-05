param (
    [string]$BaseUrl = 'http://127.0.0.1:8001'
)

$BaseUrl = $BaseUrl.TrimEnd('/')
$global:Failures = 0

Write-Host '====================================================================' -ForegroundColor Cyan
Write-Host ' OmniPublish Post-Deployment Smoke Test Runner (PowerShell)' -ForegroundColor Cyan
Write-Host " Target Base URL: $BaseUrl" -ForegroundColor Cyan
Write-Host '====================================================================' -ForegroundColor Cyan

function Test-Endpoint {
    param (
        [string]$Name,
        [string]$Path,
        [int]$ExpectedStatus,
        [string]$RequiredJsonKey = ''
    )

    Write-Host -NoNewline "Testing [$Name] ($Path) ... "
    $Url = "$BaseUrl$Path"

    $statusCode = 0
    $content = ''

    try {
        $response = Invoke-WebRequest -Uri $Url -Method Get -TimeoutSec 10 -UseBasicParsing
        $statusCode = [int]$response.StatusCode
        $content = [string]$response.Content
    } catch [System.Net.WebException] {
        if ($_.Exception.Response) {
            $statusCode = [int]$_.Exception.Response.StatusCode
            $content = ''
        } else {
            Write-Host "ERROR: $_" -ForegroundColor Red
            $global:Failures++
            return
        }
    } catch {
        Write-Host "ERROR: $_" -ForegroundColor Red
        $global:Failures++
        return
    }

    if ($statusCode -eq $ExpectedStatus) {
        if ($RequiredJsonKey -ne '' -and -not $content.Contains($RequiredJsonKey)) {
            Write-Host "FAIL (HTTP $statusCode, missing key '$RequiredJsonKey')" -ForegroundColor Red
            $global:Failures++
        } else {
            Write-Host "PASS (HTTP $statusCode)" -ForegroundColor Green
        }
    } else {
        Write-Host "FAIL (Expected HTTP $ExpectedStatus, got $statusCode)" -ForegroundColor Red
        $global:Failures++
    }
}

# 1. Health & Process Probes
Test-Endpoint -Name 'Liveness Probe' -Path '/health/live' -ExpectedStatus 200 -RequiredJsonKey 'status'
Test-Endpoint -Name 'Readiness Probe' -Path '/health/ready' -ExpectedStatus 200 -RequiredJsonKey 'checks'
Test-Endpoint -Name 'Root Health' -Path '/health' -ExpectedStatus 200 -RequiredJsonKey 'status'
Test-Endpoint -Name 'API v1 Health Probe' -Path '/api/v1/health' -ExpectedStatus 200 -RequiredJsonKey 'status'

# 2. Release & Version Identification
Test-Endpoint -Name 'Release Version Endpoint' -Path '/version' -ExpectedStatus 200 -RequiredJsonKey 'commit_sha'
Test-Endpoint -Name 'API v1 Version Endpoint' -Path '/api/v1/version' -ExpectedStatus 200 -RequiredJsonKey 'application'

# 3. Core API Endpoints
Test-Endpoint -Name 'Workspaces Index' -Path '/api/v1/workspaces' -ExpectedStatus 200 -RequiredJsonKey 'success'
Test-Endpoint -Name 'Publish Batches Index' -Path '/api/v1/publish/batches' -ExpectedStatus 200 -RequiredJsonKey 'success'
Test-Endpoint -Name 'Social Accounts Index' -Path '/api/v1/social-accounts' -ExpectedStatus 200 -RequiredJsonKey 'success'
Test-Endpoint -Name 'Calendar Index' -Path '/api/v1/calendar' -ExpectedStatus 200 -RequiredJsonKey 'success'

# 4. Error Handling
Test-Endpoint -Name 'Unknown Route 404 Check' -Path '/api/v1/non-existent-probe-test' -ExpectedStatus 404

Write-Host '====================================================================' -ForegroundColor Cyan
if ($global:Failures -eq 0) {
    Write-Host 'SMOKE TEST SUITE PASSED — ALL PROBES HEALTHY' -ForegroundColor Green
    exit 0
} else {
    Write-Host "SMOKE TEST SUITE FAILED WITH $($global:Failures) ERRORS" -ForegroundColor Red
    exit 1
}
