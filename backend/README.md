# NexA AI backend

The backend is a small PHP API boundary backed by SQLite. Browser clients call it through stable `/api/*.php` URLs; the local router and production web server map those URLs to `backend/api/`.

## Runtime layout

- `api/` - canonical PHP controllers and shared infrastructure.
- `data/` - private SQLite databases, logs, and optional FCM credentials. Keep it web-server-denied and out of source control.
- `integrations/` - backend-only provider assets such as Telegram and the Bedrock proxy.
- `.env.example` - placeholder configuration for local development.

There is no production code in a legacy or diagnostic directory. Historical files are kept in the root `.quarantine/` directory, which is excluded from routing and release verification.

## Local development

1. Copy `.env.example` to `.env` in this directory.
2. Set `GEMINI_API_KEY` for live AI responses and keep all secrets out of the frontend.
3. From the repository root, run:

```powershell
.\scripts\start-local.ps1
```

4. Open <http://localhost:8000/>.

For Google sign-in, register these exact OAuth JavaScript origins on the configured web client:

- `http://localhost:8000`
- `http://127.0.0.1:8000`

Production uses `https://ai.nexzen.live` as a separate origin.

## API rules

- Add new controllers to the explicit route manifest in `.htaccess` and `scripts/local-router.php`.
- Use `security.php` for response headers, origin checks, CSRF protection, and admin authorization.
- Use prepared statements and explicit column lists for database access.
- Return generic client errors; log technical details on the server only.
- Never expose `.env`, SQLite files, FCM keys, logs, or private integration credentials.