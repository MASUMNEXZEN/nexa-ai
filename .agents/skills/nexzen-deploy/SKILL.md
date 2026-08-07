---
name: nexzen-deploy
description: Deploy the canonical NexA AI frontend and backend release to the NexZen live server through the repository FTPS deployment script. Use this skill whenever deployment or going live is requested.
---

# NexZen deploy skill

Use the repository deployment script for production uploads. The script packages only the canonical frontend/ and explicitly allowlisted backend/api/ files, rejects private artifacts, and uses authenticated FTPS.

## Required configuration

Set these environment variables in the deployment shell or CI secret store:

~~~powershell
$env:NEXA_FTPS_HOST = 'ftp.example.com'
$env:NEXA_FTPS_USER = 'deploy-user'
$env:NEXA_FTPS_PASS = '<secret-from-secret-store>'
$env:NEXA_FTPS_REMOTE_ROOT = '/nexa-ai'
~~~

Never commit, paste, or store deployment credentials in this repository. The live host, username, and password must come from the hosting provider's secret manager or deployment environment.

## Preflight

From the repository root:

~~~powershell
npm run check
python -m py_compile deploy_all.py
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/verify-release.ps1
~~~

verify-release.ps1 must pass in a clean release checkout. A developer workspace containing backend/.env or backend/data/fcm-key.json is expected to be blocked by that safety gate.

## Deploy

~~~powershell
python deploy_all.py
~~~

The script uses NEXA_FTPS_* variables, uploads .htaccess, the complete frontend release set, and the explicit canonical API allowlist. It fails on missing files, private artifacts, or missing deployment variables; it does not silently skip files.

## Post-deploy verification

Check the production application and representative endpoints:

~~~powershell
Invoke-WebRequest 'https://ai.nexzen.live/' -UseBasicParsing
Invoke-WebRequest 'https://ai.nexzen.live/login' -UseBasicParsing
Invoke-WebRequest 'https://ai.nexzen.live/api/ping.php' -UseBasicParsing
~~~

Then verify the chat UI, authentication flow, admin authorization, and provider connectivity from the production host. Do not report deployment success until those checks pass.