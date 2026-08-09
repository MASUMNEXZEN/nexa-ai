$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

$files = @(Get-ChildItem -Path 'backend/api' -Filter '*.php' -File | Sort-Object FullName)
if ($files.Count -eq 0) { throw 'No backend API PHP files found.' }

foreach ($file in $files) {
    & php -l $file.FullName
    if ($LASTEXITCODE -ne 0) {
        throw "PHP lint failed: $($file.FullName)"
    }
}

& php -l 'scripts/local-router.php'
if ($LASTEXITCODE -ne 0) { throw 'PHP lint failed: scripts/local-router.php' }

$migrationFiles = @(Get-ChildItem -Path 'backend/migrations' -Filter '*.php' -Recurse -File)
foreach ($file in $migrationFiles) {
    & php -l $file.FullName
    if ($LASTEXITCODE -ne 0) { throw "PHP lint failed: $($file.FullName)" }
}
& php -l 'scripts/migrate.php'
if ($LASTEXITCODE -ne 0) { throw 'PHP lint failed: scripts/migrate.php' }

$forbiddenNames = $files | Where-Object { $_.Name -match '(^|[-_])(test|diag|debug|trace|live|temp|migrate|wrapper|traced)' }
if ($forbiddenNames) {
    throw "Forbidden legacy/debug API files found: $($forbiddenNames.Name -join ', ')"
}

Write-Host "Production PHP syntax checks passed for $($files.Count) API files."