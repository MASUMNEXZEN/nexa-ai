# NexA AI Production Readiness Audit

**Audit date:** 2026-08-09
**Scope:** canonical frontend, PHP API, SQLite migrations, study planner module, content import, security boundaries, local runtime, and release packaging.

## Executive decision

The codebase passes the code-level production gate and is ready for controlled staging deployment. It is not yet approved for live production until the real server configuration and authenticated end-to-end smoke checks are completed.

This is a release candidate, not an unqualified live release. The remaining blockers are operational and content-related, not unresolved syntax or route failures.

## Architecture decision

Keep the current PHP + SQLite runtime and the explicit split:

- `frontend/` is the browser application, PWA shell, UI, icons, planner views, and static assets.
- `backend/api/` contains the explicit public PHP controllers and shared API helpers.
- `backend/planner/` is the portable planner module. Authentication, PDO, routing, NexA branding, and workspace integration stay at the host edges.
- `backend/migrations/` contains numbered schema changes.
- `backend/data/` is private writable runtime state and is never committed or deployed.
- `.quarantine/` contains recoverable historical code and is never routed or packaged.

No second chat engine, alternate runtime, broad API filename routing, or client-authoritative identity should be reintroduced.

## Audit findings

| Area | Status | Finding |
|---|---|---|
| Frontend shell and chat | PASS locally | Canonical responsive workspace, guest chat, login, icons, dark mode, and planner navigation render without browser console errors in the verified local flow. |
| Planner domain | PASS | Deterministic month generation honors the three-subject touchpoint rule, custom capacity, availability, weekly subject preference, topic weakness, and revision dates. |
| Planner persistence | PASS | Profile, diagnostic, month, week, day, task transitions, rescheduling, ordering, and planner events are server-owned and user-scoped. |
| Security boundaries | PASS locally | Authenticated planner endpoints, admin content endpoints, CSRF/origin checks, safe JSON errors, explicit columns, and ownership checks are in place. |
| Content quality | PASS by policy | Only owner/reviewer-verified, published, authoritative, English, structurally valid four-option questions can enter the diagnostic pool. |
| Database | PASS locally / staging required | Main schema is version 5 and cache schema is version 3; migrations are numbered, idempotent, and backup-first. |
| Release packaging | PASS | FTPS release construction is finite and explicit; planner APIs, module PHP files, migrations, frontend assets, and route allowlists are aligned. |
| Local smoke | PASS | Public shells, API guards, private paths, payment guards, and unauthenticated planner boundaries were checked over HTTP. |
| Live production | PENDING | Production secrets, OAuth origins, provider network/TLS, database backup/migration, content publication, and authenticated server smoke remain. |

## Confirmed production fixes in this audit

1. Repaired `deploy_all.py`, which had a recursive release-manifest return and could not complete a deployment.
2. Added planner and content endpoints to `scripts/local-router.php`, `.htaccess`, and the authenticated FTPS release manifest.
3. Added `planner-task.php` with authenticated start, complete, skip, reorder, and reschedule operations.
4. Added transaction-safe task writes, ownership checks, valid state transitions, rescheduling lineage, and `planner_events` audit records.
5. Reset diagnostic readiness when a learner changes exams and rejected completion of a diagnostic from a different active exam.
6. Restricted diagnostics and imports to complete four-option English MCQs with published authoritative records.
7. Replaced the guest planner's empty form state with an explicit sign-in prompt.
8. Added task controls to the planner UI and reduced month/day loading to parallel requests.
9. Updated the operator README and this audit to match the current architecture and release contract.

## What to keep

- The PHP + SQLite deployment model and numbered CLI migrations.
- The single canonical chat workspace and its guest mode.
- The explicit route allowlists and shared security helper.
- Prepared statements, explicit response fields, server-side identity, hashed persistent tokens, and admin guards.
- The portable planner domain/application/importer/adapters structure.
- Admin preview/publish workflow with source checksums, ownership confirmation, trust levels, duplicate detection, and transaction rollback on failure.
- Self-hosted frontend dependencies and the current responsive light/dark visual system.

## What to remove or keep out of the release

- Do not deploy `backend/data/`, `.env`, service-account keys, SQLite files, logs, generated dumps, or `.quarantine/`.
- Do not route any PHP file merely because it exists under `backend/api/`.
- Do not re-enable legacy `live_*`, test, debug, trace, migration, password-hash, or duplicate frontend engines.
- Do not serve unverified, non-English, incomplete, or AI-invented authoritative question text.
- Do not expose Gemini, OAuth, admin, payment, or deployment secrets to the browser.

## Improvements still recommended after launch

- Add CI browser regression tests for login, guest chat, send, quiz, planner onboarding, task completion, and logout.
- Add production rate limits and audit metrics for admin exports, auth failures, OTP, password reset, imports, and provider failures.
- Complete strict CSP after remaining inline styles are moved into stylesheets.
- Add provider latency/error dashboards, database retention policies, deployment checksums, and rollback artifacts.
- Add full planner progress and reviewer queue surfaces when those product phases are enabled.

## Verification evidence

All checks below passed in the current workspace:

- `npm run check`
  - canonical JavaScript syntax
  - PHP lint for 52 API/support files, migrations, router, and planner module
  - 22 payment integrity checks
  - planner rule tests
  - Markdown and DOCX importer tests
- `python -m py_compile deploy_all.py`
- `php scripts/migrate.php --dry-run`
  - main schema 5
  - cache schema 3
- `scripts/smoke-local.ps1`
  - frontend and login shells
  - API health and method guards
  - unknown and private route protection
  - unauthenticated planner profile, assessment, and task rejection
- Isolated clean release verification without secrets or runtime data
- Focused SQLite runtime test for task start, completion, terminal-state protection, rescheduling lineage, ordering, and event history
- `git diff --check` passed; only normal Windows line-ending warnings remain.

## Live launch checklist

Before calling the site live:

1. Configure production secrets outside the public web root or through the host environment.
2. Set `NEXA_APP_ENV=production`, `NEXA_COOKIE_SECURE=1`, exact `NEXA_ALLOWED_ORIGINS`, and HTTPS.
3. Register the exact production Google OAuth origin and redirect configuration.
4. Back up the production SQLite files, then run `php scripts/migrate.php` on the host.
5. Test Gemini connectivity and Google login from the original server.
6. Publish only corrected owner-verified English content. The supplied PYQ source remains blocked until its language and structural warnings are fixed.
7. Complete one authenticated profile -> diagnostic -> monthly plan -> task action flow on the original server.
8. Create and review the Git baseline before deployment so rollback and change attribution are reliable.

No user database or runtime data was deleted during this audit. Historical code remains recoverable under `.quarantine/`.
