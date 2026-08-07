$ErrorActionPreference = 'Stop'

$root = Split-Path -Parent $PSScriptRoot
Set-Location $root

$blocked = @(
    '.env', 'backend/.env', 'backend/api/fcm-key.json', 'backend/data/fcm-key.json',
    'backend/api/gemini_raw_dump.txt', 'backend/api/trace.txt'
)
$present = $blocked | Where-Object { Test-Path -LiteralPath $_ }
if ($present) {
    throw "Release blocked by secret or diagnostic artifact(s): $($present -join ', ')"
}

$apiFiles = @(Get-ChildItem -Path 'backend/api' -Filter '*.php' -File | Sort-Object FullName)
foreach ($file in $apiFiles) {
    & php -l $file.FullName
    if ($LASTEXITCODE -ne 0) { throw "PHP lint failed: $($file.FullName)" }
}
& php -l 'scripts/local-router.php'
if ($LASTEXITCODE -ne 0) { throw 'PHP lint failed: scripts/local-router.php' }

& python -m py_compile deploy_all.py
if ($LASTEXITCODE -ne 0) { throw 'Deployment script syntax check failed.' }

$sourceFiles = Get-ChildItem -Recurse -File | Where-Object {
    $_.FullName -notmatch '\\.git\\|\\node_modules\\|\\.quarantine\\|\\backend\\data\\|\\.netlify\\'
}
$forbidden = $sourceFiles | Select-String -Pattern 'FTP_PASS\s*=\s*[''\"]|Access-Control-Allow-Origin:\s*\*|ADMIN_PASSWORD\s*=\s*[''\"]|TELEGRAM_BOT_TOKEN\s*=\s*[''\"]\w'
if ($forbidden) {
    $locations = $forbidden | ForEach-Object { "$($_.Path):$($_.LineNumber)" }
    throw "Forbidden release pattern(s) found: $($locations -join ', ')"
}

if (Test-Path '.quarantine') { Write-Host 'Quarantine directory present; excluded from release.' }
Write-Host "Release verification passed for $($apiFiles.Count) API files."