$ErrorActionPreference = 'Stop'
$root = Split-Path -Parent $PSScriptRoot
Set-Location $root
if (-not (Test-Path 'backend/.env')) {
    throw 'Missing backend/.env. Copy backend/.env.example to backend/.env and configure local values first.'
}
Write-Host 'NexA AI local server: http://localhost:8000'
Write-Host 'Press Ctrl+C to stop.'
php -S localhost:8000 -t . scripts/local-router.php
