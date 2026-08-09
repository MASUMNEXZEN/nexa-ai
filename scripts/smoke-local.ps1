[CmdletBinding()]
param(
    [string]$BaseUrl = 'http://127.0.0.1:8000'
)

$ErrorActionPreference = 'Stop'
$BaseUrl = $BaseUrl.TrimEnd('/')

function Invoke-SmokeRequest {
    param(
        [Parameter(Mandatory)] [string]$Path,
        [ValidateSet('GET', 'POST')] [string]$Method = 'GET',
        [string]$Body = ''
    )

    try {
        $params = @{
            Uri = $BaseUrl + $Path
            Method = $Method
            UseBasicParsing = $true
        }
        if ($Method -eq 'POST') {
            $params.ContentType = 'application/json'
            $params.Body = $Body
        }
        $response = Invoke-WebRequest @params
        return [pscustomobject]@{
            Status = [int]$response.StatusCode
            Body = [string]$response.Content
        }
    } catch {
        $response = $_.Exception.Response
        if (-not $response) {
            throw "Request failed for ${Path}: $($_.Exception.Message)"
        }
        $reader = New-Object System.IO.StreamReader($response.GetResponseStream())
        return [pscustomobject]@{
            Status = [int]$response.StatusCode
            Body = $reader.ReadToEnd()
        }
    }
}

function Assert-SmokeResponse {
    param(
        [Parameter(Mandatory)] [string]$Name,
        [Parameter(Mandatory)] $Response,
        [Parameter(Mandatory)] [int]$ExpectedStatus,
        [string]$ExpectedText = ''
    )

    if ($Response.Status -ne $ExpectedStatus) {
        throw "$Name expected HTTP $ExpectedStatus but received HTTP $($Response.Status): $($Response.Body)"
    }
    if ($ExpectedText -and $Response.Body -notlike "*$ExpectedText*") {
        throw "$Name response did not contain '$ExpectedText'."
    }
    Write-Host "PASS $Name ($($Response.Status))"
}

Assert-SmokeResponse 'frontend shell' (Invoke-SmokeRequest '/') 200 'NexA AI'
Assert-SmokeResponse 'login shell' (Invoke-SmokeRequest '/login') 200 'Sign In'
Assert-SmokeResponse 'self-hosted markdown library' (Invoke-SmokeRequest '/vendor/marked/marked.min.js') 200 'marked'
Assert-SmokeResponse 'self-hosted font stylesheet' (Invoke-SmokeRequest '/vendor/fonts.css') 200 '@font-face'
Assert-SmokeResponse 'health endpoint' (Invoke-SmokeRequest '/api/ping.php') 200 'OK'
Assert-SmokeResponse 'subscription plans read' (Invoke-SmokeRequest '/api/subscription-plans.php') 200 'plans'
Assert-SmokeResponse 'subscribe method guard' (Invoke-SmokeRequest '/api/subscribe.php') 405 'Method not allowed'
Assert-SmokeResponse 'verify method guard' (Invoke-SmokeRequest '/api/verify-payment.php') 405 'Method not allowed'
Assert-SmokeResponse 'unauthenticated order creation' (Invoke-SmokeRequest '/api/subscribe.php' 'POST' '{"plan_name":"pro","idempotency_key":"smoke-test-1234"}') 401 'Not authenticated'
Assert-SmokeResponse 'unauthenticated payment verification' (Invoke-SmokeRequest '/api/verify-payment.php' 'POST' '{}') 401 'Not authenticated'
Assert-SmokeResponse 'unauthorized plan mutation' (Invoke-SmokeRequest '/api/subscription-plans.php' 'POST' '{"name":"pro"}') 403 'Admin access required'
Assert-SmokeResponse 'invalid AI body' (Invoke-SmokeRequest '/api/app-ask.php' 'POST' '{"contents":[]}') 400 'Invalid conversation payload'
Assert-SmokeResponse 'unknown API route' (Invoke-SmokeRequest '/api/not-a-real-endpoint.php') 404 'Endpoint not found'
Assert-SmokeResponse 'private backend route' (Invoke-SmokeRequest '/backend/api/db.php') 404 ''
Assert-SmokeResponse 'private payment helper route' (Invoke-SmokeRequest '/api/payment.php') 404 'Endpoint not found'
Assert-SmokeResponse 'private migration tool route' (Invoke-SmokeRequest '/scripts/migrate.php') 404 ''

Write-Host "Local smoke tests passed against $BaseUrl."
