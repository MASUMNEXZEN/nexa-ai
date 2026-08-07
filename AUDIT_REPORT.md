# NexA AI Codebase Audit Report

**Audit date:** 2026-08-07  
**Scope:** Repository structure, frontend, backend APIs, authentication, AI provider integration, SQLite persistence, deployment, security, documentation, and local runtime checks.  
**Current verdict:** **AMBER — implementation baseline is clean and locally verifiable; production approval is still pending operational configuration.**

## Executive summary

The codebase now has one clear runtime architecture:

- frontend/ contains the browser application and static pages.
- backend/api/ contains the 43-file canonical PHP API surface.
- backend/data/ contains private runtime databases, logs, and credentials.
- scripts/ contains local routing and verification tools.
- .quarantine/ contains recoverable historical/debug material and is never deployed.

The product core is worth keeping. The highest-risk repository problems found during the audit were addressed: arbitrary API exposure, public diagnostic endpoints, hard-coded credential utilities, inconsistent admin authorization, schema-name drift in leaderboard queries, client-controlled guest identity, incomplete deployment packaging, stale documentation, duplicate frontend engines, and visible text encoding corruption.

The project is not being called production-ready yet because production secrets, OAuth origins, provider connectivity, database migration discipline, and a first reviewed Git baseline still need to be completed on the actual server.

## Decision

Keep the PHP + SQLite runtime and the canonical frontend/backend split. Keep the coral NexA AI chat experience, guest chat, authentication, quiz, history, subscriptions, Telegram integration, PWA shell, and admin features. Do not reintroduce the archived alternate runtimes or duplicate frontend engines.

No user database or runtime data was deleted. Historical code was moved into .quarantine/ so it is recoverable but cannot be routed or packaged.

## Current release gate

| Area | Status | Assessment |
|---|---|---|
| Guest chat UI | Green locally | Canonical chat interface rendered and browser-checked. |
| Login UI | Green locally | Login page rendered and browser-checked. |
| Frontend syntax | Green | Node syntax checks pass for the canonical JavaScript files. |
| Backend syntax | Green | PHP lint passes for all 43 canonical API files and the local router. |
| Public API exposure | Green locally | Router and .htaccess use explicit allowlists; unknown API files return 404. |
| Admin authorization | Green locally | Admin action endpoints use the shared guard; unauthenticated requests return 403. |
| Session and CSRF controls | Amber | Shared security layer is in place; production cookie/origin validation still needs server verification. |
| Database and migrations | Amber | Runtime schema compatibility is preserved, but formal one-time migrations remain future work. |
| AI provider connectivity | Amber | Local transport can work; production host network/TLS and provider credentials must be tested. |
| Deployment | Green in code / blocked locally | Authenticated FTPS manifest is complete; local secret-safety gate correctly blocks this developer checkout. |
| Source control | Red | Repository has no reviewed baseline commit yet. |
| Production approval | Amber | Operational configuration and production smoke tests remain. |

## What to keep

### Product and frontend

- frontend/index.html, frontend/nexa-app.js, and frontend/nexa-main.css as the canonical guest application.
- The coral/red visual system, responsive chat layout, quiz experience, attachments, markdown rendering, PWA shell, and authenticated feature surfaces.
- The existing marked plus DOMPurify rendering path, with dynamic content kept sanitized.
- Guest mode as a deliberate product path, with quota enforcement on the server.
- The current PHP/static deployment model; no framework migration is justified by this audit.

### Backend

- backend/api/config.php, security.php, and db.php as the shared platform boundary.
- Prepared statements, password hashing, session rotation, persistent-token hashing, and explicit authentication response fields.
- Gemini and fallback provider routing, with provider failures surfaced as safe user-facing errors.
- The canonical schema names used by leaderboard and usage queries.
- Shared admin authorization and request security headers.
- Explicit API route allowlisting and path containment.

### Tooling and documentation

- README.md as the local and production operator guide.
- PRD.md and TRD.md as product and technical source documents.
- AGENTS.md as the contribution and architecture guardrail.
- package.json/package-lock.json with no unnecessary runtime dependencies.
- scripts/check-production.ps1, scripts/verify-release.ps1, scripts/local-router.php, and scripts/start-local.ps1.
- deploy_all.py as the single release mechanism.

## What was removed from the active release

The following classes of material were moved to .quarantine/ rather than destructively deleted:

- Debug, trace, diagnostic, test, temporary password-hash, and migration PHP files.
- Parallel live_* backend implementations and old Netlify/tooling directories.
- Duplicate frontend engines and styles: app.js, app.css, nexa-engine.js, nexa-style.css, frontend/js/, frontend/legacy/, and the widget demo.
- Root scratch repair scripts and old planning fragments.
- Private dumps and destructive utility files that must not be web reachable.

The active router only exposes the intended canonical API controllers. A filename under backend/api/ no longer automatically becomes a public endpoint.

## Main improvements implemented

### Security

- Centralized security headers, session initialization, origin checks, CSRF handling, admin authorization, and persistent-token helpers.
- Removed the public password-hash utility and blocked all quarantined endpoints with HTTP 404.
- Added session ID rotation after successful user login.
- Revoke persistent user tokens during logout and clear session cookies.
- Removed direct admin-password handling from cache administration.
- Added explicit admin guards to all admin action endpoints.
- Removed client-controlled device identity from guest quota keys; the server derives the guest key from the request identity.
- Moved the FCM service account into private backend/data/ storage and excluded runtime data from deployment.

### Correctness

- Repaired leaderboard queries to use is_correct and timestamp, matching the canonical schema.
- Replaced broad SELECT * usage in sensitive admin/report/export paths with explicit columns where applicable.
- Fixed the Telegram broadcast URL initialization bug.
- Fixed the usage CSV row/header mismatch.
- Added JSON 404 responses for unknown API paths.
- Added safe MIME handling and frontend path containment to the local router.

### Release engineering

- deploy_all.py now uses authenticated FTPS, an explicit 43-file API manifest, complete canonical frontend packaging, missing-file checks, and private-artifact rejection.
- Release verification blocks local .env, service-account keys, dumps, and traces from production packaging.
- .gitignore excludes runtime databases, logs, secrets, quarantine material, and environment files.
- The project deployment skill no longer contains a plaintext credential and now delegates to deploy_all.py.

### Maintainability and UX

- Rewrote the root, frontend, and backend READMEs around the actual architecture.
- Added AGENTS.md with ownership, conventions, checks, and “do not reintroduce” rules.
- Repaired mojibake and replacement-character fallbacks in active frontend/backend text.
- Kept the interface simple and canonical instead of maintaining duplicate engines.

## Remaining risks and required next actions

### P0 — required before production

1. Create the first reviewed Git baseline commit. The current repository is uncommitted, so rollback and change attribution are not yet reliable.
2. Configure production secrets outside the public web root or through the host environment. Rotate any provider, OAuth, payment, Telegram, or deployment credentials that may have existed in older files.
3. Configure Google OAuth JavaScript origins and redirect behavior for the exact production origin and the approved local origins. Local origin mismatch is configuration-specific, not a frontend styling issue.
4. Run the full application on the original server and verify Gemini/provider network and TLS access from that host.
5. Run the release verifier from a clean checkout that does not contain backend/.env or backend/data/fcm-key.json.
6. Back up the production SQLite files before deployment and document rollback ownership.

### P1 — required for a durable production release

1. Move request-time schema creation and compatibility ALTER logic into numbered, one-time migrations under backend/migrations/ after reviewing the existing data.
2. Add automated API tests for guest quota, auth/session rotation, CSRF/origin behavior, admin authorization, quiz scoring, and provider failures.
3. Add upload size/type limits and malware/content validation for every attachment/history path.
4. Add request IDs and structured server-side logging without exposing provider responses, tokens, or credentials.
5. Add rate limits and audit logging for admin exports, broadcasts, auth failures, OTP, and password reset.
6. Add a production CSP and review external CDN dependencies; self-host or pin critical assets where practical.
7. Add browser smoke tests to CI for login, guest chat, sending a message, quiz launch, and logout.

### P2 — quality and scale improvements

- Split the large frontend controller into transport, auth, chat, quiz, attachment, profile, and UI modules after stability is proven.
- Add accessibility regression checks for keyboard navigation, focus management, labels, contrast, and reduced motion.
- Add database indexes based on production query plans and retention policies for logs/history.
- Add deployment checksums, rollback artifacts, and a post-deploy smoke report.
- Add observability for provider latency, error rates, quota rejection, and failed payments.

## Verification performed

All results below were run against the current workspace:

- npm run check — **PASS**
  - canonical JavaScript syntax passed.
  - PHP syntax passed for all 43 backend/api files.
  - scripts/local-router.php passed PHP lint.
- python -m py_compile deploy_all.py — **PASS**
- Admin guard inventory — **PASS**
  - all eight protected admin action groups use nexa_require_admin().
  - admin login/check/logout remain intentionally public session lifecycle endpoints.
- Local HTTP smoke — **PASS**
  - / returned 200.
  - /login returned 200.
  - /api/app-config.php returned 200 JSON.
  - /api/ping.php returned 200.
  - quarantined temp_hash.php, test2.php, and clear-quiz-db.php returned 404.
  - unauthenticated admin stats, announcement, and Telegram broadcast returned 403.
- Browser smoke — **PASS**
  - login and chat tabs rendered the intended UI.
  - no browser console errors were reported.
- Deployment consistency — **PASS**
  - backend/api contains 43 PHP files.
  - deploy_all.py contains the same 43-file API manifest.
  - no active source references the quarantined runtime paths.
- Text quality — **PASS**
  - active source contains no detected mojibake or replacement characters.
- scripts/verify-release.ps1 — **INTENTIONALLY BLOCKED**
  - current developer workspace contains backend/.env and backend/data/fcm-key.json.
  - This is a safety gate, not a code failure. Run it from a clean release checkout with secrets injected only by the server/CI environment.

## Final assessment

The implementation is now clean enough for continued local development and controlled staging. It is materially safer, simpler, and easier to operate than the original repository. It should not be labeled production-ready until the P0 operational actions are complete, especially secret rotation, OAuth origin configuration, provider connectivity on the real host, database backup/migration planning, and the first Git checkpoint.

The right next move is a reviewed baseline commit followed by a clean-checkout staging deployment and real-server smoke test.