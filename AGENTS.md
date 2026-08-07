# AGENTS.md

## Application

NexA AI is a PHP + SQLite exam-preparation tutor with a static/PWA frontend, Gemini-backed chat, account authentication, quizzes, history, bookmarks, subscriptions, and an admin console.

## Approved architecture

Keep one runtime architecture: `frontend/` for browser code and `backend/api/` for explicitly public PHP controllers. Shared server behavior belongs in the existing config, security, and database modules. Do not reintroduce Netlify/Deno, parallel `live_*` endpoints, or duplicate frontend engines.

`backend/data/` is private writable runtime state. It must never be committed or served. `.quarantine/` is recoverable historical code and is never deployed.

## Conventions

- Use clear, domain-oriented names.
- Prefer small functions and explicit data fields.
- Use prepared SQL statements and explicit column lists.
- Validate all request data at the API boundary.
- Return JSON with safe user-facing errors; log technical context server-side.
- Use the shared security helper on every API endpoint.
- Protect every admin endpoint with `nexa_require_admin()`.
- Keep provider keys, admin hashes, cookies, and private user data server-side.
- Do not add inline JavaScript event handlers that interpolate user or model text.
- Do not silently swallow exceptions. If an exception is intentionally non-fatal, log a useful redacted context.

## Commands

```powershell
.\scripts\start-local.ps1
npm run check
python -m py_compile deploy_all.py
powershell -NoProfile -ExecutionPolicy Bypass -File scripts\verify-release.ps1
```

## Before changing code

1. Read the relevant PRD/TRD section.
2. Trace imports, routes, and frontend callers.
3. Check `git status` and preserve unrelated work.
4. Make the smallest change that solves the confirmed problem.
5. Run syntax checks and the relevant local smoke test.

## Must not be reintroduced

- Public test, trace, debug, migration, password-hash, or `live_*` endpoints.
- A second chat engine or second database contract.
- Broad `/api/*.php` filename routing.
- `SELECT *` for token/admin/export responses.
- Direct admin password verification in action endpoints.
- Client-controlled device IDs as the sole anonymous identity.
- Plain FTP deployment or silent deployment skips.
- Secrets, runtime databases, dumps, or generated dependencies in source control.