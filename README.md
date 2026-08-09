# NexA AI

NexA AI is a PHP + SQLite AI tutor for exam preparation. The browser client is a responsive PWA with guest chat, authenticated accounts, quizzes, history, bookmarks, and an admin console. Gemini is the primary AI provider; optional fallback providers are configured on the server.

## Architecture

- `frontend/` contains the browser-delivered HTML, CSS, JavaScript, PWA assets, and public images.
- `backend/api/` contains the explicitly public PHP controllers.
- `backend/data/` contains writable SQLite databases and local runtime logs. It must remain outside source control and outside the public web root.
- `backend/migrations/` is reserved for versioned database changes.
- `backend/planner/` is the portable planner module; NexA-specific authentication, PDO, routing, and workspace behavior stay at the edges.
- `scripts/` contains local development and verification commands.
- `.quarantine/` contains recoverable legacy code that is not part of the application or release.

The public URLs are stable: `/` serves the frontend, `/api/*.php` serves only the allowlisted API controllers, and `/login`, `/admin`, and the legal routes map to the corresponding frontend pages.

## Local development

Requirements:

- PHP 8.1+ with `pdo_sqlite` and cURL enabled
- Node.js 18+ for syntax checks
- A Gemini API key for live AI responses

1. Copy `backend/.env.example` to `backend/.env`.
2. Set `GEMINI_API_KEY` and any optional integration values.
3. Start the local server:

```powershell
.\scripts\start-local.ps1
```

4. Open <http://localhost:8000/> for the authenticated flow or <http://localhost:8000/?app=true&fresh=1> for guest chat.

The local configuration disables the sandbox-only outbound proxy. Production never uses that bypass.

## Verification

```powershell
npm run check
python -m py_compile deploy_all.py
.\scripts\smoke-local.ps1
php scripts/migrate.php --dry-run
powershell -NoProfile -ExecutionPolicy Bypass -File scripts/verify-release.ps1
```

`npm run check` validates the canonical JavaScript, production PHP allowlist, database migrations, and offline payment integrity suite. The release verifier must be run from a clean release environment without `.env`, keys, databases, logs, or diagnostic dumps.

## Production deployment

Deploy the complete release manifest to a PHP host with SQLite, cURL, HTTPS, and a writable private data directory. Do not deploy through GitHub Pages or a static-only host: authentication, SQLite, admin operations, and Gemini proxying require the PHP backend.

Before release:

- Configure production secrets through the server environment or `backend/.env` outside the web root.
- Set `NEXA_APP_ENV=production`, `NEXA_COOKIE_SECURE=1`, and the exact production `NEXA_ALLOWED_ORIGINS`.
- Register the production Google OAuth JavaScript origin and redirect configuration.
- Run `php scripts/migrate.php` on the host before serving traffic. It creates a private SQLite backup before applying pending changes.
- Run the release verifier and post-deploy smoke checks.
- Import only owner-verified English syllabus and question content through the admin preview/publish workflow; the planner will refuse incomplete or non-English records.

## Security rules

- Never commit `.env`, API keys, FCM keys, SQLite files, logs, or user data.
- Never add a PHP file to `backend/api` without adding it to the explicit route manifest and applying the shared security helper.
- Never expose admin data without `nexa_require_admin()`.
- Never put provider keys or admin credentials in frontend code.
- Keep user-facing errors generic and technical details in server logs only.

## Product documents

- [Product requirements](PRD.md)
- [Technical requirements](TRD.md)
- [Master product document](NEXZEN_AI_MASTER_DOC.md)
- [Student app PRD](NEXA_AI_APP_PRD.md)
- [Student app TRD](NEXA_AI_APP_TRD.md)
- [Latest audit report](AUDIT_REPORT.md)
- [AI agent instructions](AGENTS.md)
