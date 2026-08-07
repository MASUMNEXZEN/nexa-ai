# NexA AI — Product Requirements Document

**Document status:** Implementation baseline; production approval pending
**Version:** 1.1
**Date:** 2026-08-07  
**Product:** NexA AI by NEXZEN AI  
**Primary audience:** Students preparing for WBJEE, JENPAS UG, ANM/GNM, WBP/KP, SSC, and WB Board examinations

## 1. Executive summary

NexA AI is a Bengali-first AI study companion for students who need fast, understandable, exam-oriented help on mobile devices. It combines conversational tutoring, image/PDF question solving, generated practice quizzes, study history, and paid usage plans in a lightweight progressive web app.

The product should feel like a dependable premium study tool rather than a thin AI wrapper: answers must be clear and localized, the interface must be calm and fast, usage limits must be honest, payments must be trustworthy, and the system must fail safely when an AI provider or network is unavailable.

This PRD defines the desired product behavior. The implementation baseline is now cleanly separated into frontend and backend boundaries; production approval still depends on the operational checks listed in the TRD.

## 2. Product vision

Give every student a patient, practical tutor that explains difficult concepts in the language they understand, helps them practice deliberately, and remains useful on an ordinary mobile connection.

## 3. Problem statement

Students often have access to questions but not to immediate, affordable, context-aware explanations. Existing AI tools can be difficult to use in Bengali, may produce answers without exam context, and often obscure limits or fail unpredictably. NexA AI should reduce the time between “I am stuck” and “I understand the next step.”

## 4. Goals

1. Deliver a Bengali-first AI tutoring experience with streaming responses and useful fallback behavior.
2. Support the main study inputs students already have: typed questions, photos, and PDFs.
3. Make practice measurable through official-style and custom quizzes.
4. Provide transparent free and paid entitlements with secure payment verification.
5. Make the product feel polished, responsive, accessible, and trustworthy on mobile first.
6. Give administrators safe, auditable controls without exposing operational or user data.
7. Establish a release process that can be tested, observed, backed up, and rolled back.

## 5. Non-goals for the first production release

- Replacing a school, teacher, or official examination authority.
- Guaranteeing that AI answers are always correct.
- Building a full social network, public messaging community, or educator marketplace.
- Supporting multiple independent backend runtimes in production.
- Offering offline AI inference. The PWA may cache its shell and selected non-sensitive content, but AI requests require connectivity.
- Treating Telegram as a second product surface before the core web experience is reliable.

## 6. Product principles

- **Bengali-first:** Bengali is the default explanation language. English is used for English grammar or when the student explicitly requests English.
- **Exam-aware:** Answers should favor steps, shortcuts, formulas, examples, and likely exam patterns over generic essays.
- **Honest limits:** The user should always understand remaining usage, plan status, and why a request was rejected.
- **Progressive disclosure:** The interface stays simple for a first-time student while advanced controls remain available when needed.
- **Safe by default:** Authentication, billing, AI provider failures, and admin actions must fail closed.
- **Premium restraint:** Strong typography, spacing, loading states, empty states, feedback, and motion matter more than decorative complexity.
- **Low-bandwidth friendly:** Minimize JavaScript and image cost, stream useful output early, and preserve the conversation when a request fails.

## 7. Personas and access levels

### 7.1 Student

The primary user. A student may be anonymous in a limited app mode, but a registered account is required for persistent history, paid plans, and account recovery.

### 7.2 Returning registered student

Needs fast session restoration, history, profile controls, remaining quota, saved context, and continuity across devices.

### 7.3 Administrator

Manages users, plans, usage limits, feature flags, announcements, reports, cache, subscriptions, and operational health. Admin access must be separate from student access and all sensitive actions must be logged.

### 7.4 Support/operator

May need read-only diagnostics and user-support tools in a later phase. This role is not required for the first release; do not create a broad “admin” account when a narrower role would suffice.

## 8. Scope and feature definition

### 8.1 Authentication and account

- Email registration with OTP verification.
- Email/password login with brute-force protection.
- Google sign-in using verified identity tokens.
- Password reset with expiring, single-use OTP.
- Session restoration across page loads and supported devices.
- Logout from the current session and, later, all sessions.
- Profile fields: name, exam/category, preferred language, and optional study metadata.
- Referral code support with a one-time bonus, subject to anti-abuse controls.

### 8.2 AI tutor

- Text question entry.
- Image upload for question solving.
- PDF upload within a defined size/page limit.
- Streaming answer display.
- Bengali default response language, with explicit English override.
- Useful answer structure: conclusion, reasoning, steps, and exam tip where applicable.
- Conversation history for authenticated users.
- Graceful provider failure message with retry behavior.
- Clear indication when an answer is generated and may require verification.

### 8.3 Quiz and practice

- Official/preset quizzes by supported exam and subject.
- Custom AI-generated quiz with configurable subject, difficulty, question count, and language.
- One-question-at-a-time or compact mobile flow.
- Score, correct answer, explanation, time, and completion state.
- Quiz history for authenticated users.
- Anonymized leaderboard only when the user has opted into leaderboard participation.

### 8.4 Plans, payments, and entitlements

Initial plan catalog from the master document:

| Plan | Price | Monthly/request allowance | Initial intent |
|---|---:|---:|---|
| Free | ₹0 | 20 | Trial and light daily use |
| Pro | ₹99 | 100 | Regular individual study |
| Premium | ₹199 | 9,999 | Heavy study use |

The exact billing period and tax/invoice copy must be confirmed with the payment-account configuration before launch. The current intended period is 30 days.

Requirements:

- Show plan, price, period, included allowance, remaining allowance, and renewal/expiry state.
- Create an order server-side; never trust plan or amount values sent by the browser.
- Verify payment against the server-created order and the authenticated user.
- Make verification idempotent.
- Handle pending, failed, refunded, and cancelled states.
- Do not grant entitlements until payment verification succeeds.
- Preserve an auditable payment and entitlement history.

### 8.5 Admin console

- Secure admin login and session management.
- Dashboard: users, active subscriptions, usage, provider health, errors, and daily trends.
- User search and read-only profile/usage view.
- Plan and entitlement management.
- Global and plan-specific limits.
- Feature flags and maintenance mode.
- Announcement management.
- Cache inspection and safe invalidation.
- Export of approved operational reports.
- Audit log for admin sign-ins, configuration changes, exports, subscription changes, and destructive actions.

### 8.6 PWA experience

- Installable manifest and service worker.
- Cached application shell.
- Offline state that explains what is and is not available.
- Update prompt when a new app version is ready.
- No sensitive API response or authentication token in an offline cache.

### 8.7 Telegram integration

Telegram support may reuse the AI and usage services, but it is Phase 2 unless the core web release is already stable. Bot identity, per-user linking, quota enforcement, abuse controls, and operational monitoring must be specified before enabling it publicly.

## 9. Functional requirements

### Authentication

| ID | Requirement | Acceptance criteria |
|---|---|---|
| FR-AUTH-001 | Register a new student by email | Valid input creates a pending account; an OTP is sent; no active session is created before verification. |
| FR-AUTH-002 | Verify registration OTP | OTP expires after 10 minutes, is single-use, is attempt-limited, and creates an active account exactly once. |
| FR-AUTH-003 | Login | Correct credentials create a rotated secure session; invalid attempts are throttled and do not reveal which account field failed. |
| FR-AUTH-004 | Restore session | Session restoration uses one consistent auth contract and returns the same entitlement state as a fresh login. |
| FR-AUTH-005 | Reset password | Reset token is stored hashed, expires, is single-use, and invalidates existing persistent login tokens after success. |
| FR-AUTH-006 | Logout | Current session and persistent token are invalidated; back navigation cannot reopen an authenticated view. |
| FR-AUTH-007 | Google login | Backend verifies issuer, audience, signature, expiry, and email identity before account linking or login. |

### AI tutoring

| ID | Requirement | Acceptance criteria |
|---|---|---|
| FR-AI-001 | Submit a text question | Authenticated/eligible request passes server validation, consumes quota once, and returns a structured response or a safe error. |
| FR-AI-002 | Stream the response | The user sees a useful first chunk within the agreed performance budget; stream completion, provider error, and disconnect states are explicit. |
| FR-AI-003 | Process image/PDF input | MIME, size, page, and content limits are enforced server-side; rejected uploads do not consume a paid allowance. |
| FR-AI-004 | Apply language policy | Bengali is the default unless the request is English grammar or an explicit English preference/request is present. |
| FR-AI-005 | Cache safe answers | Only eligible, non-personal, non-error responses are cached using a versioned key and TTL; cache hits do not bypass entitlement checks. |
| FR-AI-006 | Provider fallback | A primary-provider failure uses the configured fallback when safe; missing or invalid provider configuration fails closed with an observable error. |
| FR-AI-007 | Protect user content | Sensitive prompts, files, and personal data are not written to logs or shared with unrelated users. |

### Quizzes

| ID | Requirement | Acceptance criteria |
|---|---|---|
| FR-QUIZ-001 | Start a preset quiz | Quiz is identified by exam/subject/difficulty and returns a stable question set. |
| FR-QUIZ-002 | Generate a custom quiz | Input values are validated, generation is quota-aware, and invalid/partial AI output cannot start a corrupt quiz. |
| FR-QUIZ-003 | Complete and score | Server validates the attempt, calculates score from the authoritative answer key, and stores completion once. |
| FR-QUIZ-004 | Show explanations | Each completed question can show a safe, readable explanation without injecting unsanitized HTML. |
| FR-QUIZ-005 | Leaderboard privacy | Display names are anonymized by default and participation is opt-in. |

### Billing and entitlements

| ID | Requirement | Acceptance criteria |
|---|---|---|
| FR-BILL-001 | List plans | Client receives plan data from the server; price/allowance is not duplicated as an authority in browser code. |
| FR-BILL-002 | Create payment order | Server derives amount and plan from a current plan record and binds the order to the authenticated user. |
| FR-BILL-003 | Verify payment | Signature, order ownership, plan, amount, status, and replay/idempotency are all validated before entitlement grant. |
| FR-BILL-004 | Enforce plan limit | Every AI/quiz entry point uses the same server-side entitlement service and applies the active plan allowance. |
| FR-BILL-005 | Expire subscription | Subscription expiry removes paid entitlements predictably while preserving historical records. |

### Administration

| ID | Requirement | Acceptance criteria |
|---|---|---|
| FR-ADMIN-001 | Protect admin APIs | Every admin route uses one centralized middleware and the same session/role contract. |
| FR-ADMIN-002 | Change limits/configuration | Changes are validated, audited, versioned where appropriate, and take effect according to a documented cache policy. |
| FR-ADMIN-003 | Export data | Exports are permission-gated, rate-limited, logged, and contain only approved fields. |
| FR-ADMIN-004 | Operate safely | Debug, migration, test, and diagnostic routes are unavailable in production. |

## 10. Business rules

1. A user may not consume AI quota before registration verification unless a deliberately scoped guest mode is enabled.
2. Quota is charged server-side at the accepted request boundary, once per request. Retries must not double-charge an idempotent request.
3. Plan-specific limits override the global default. A plan limit of zero disables that capability.
4. OTPs are valid for 10 minutes, are stored hashed, and are invalid after successful use or the maximum attempt count.
5. Passwords must meet the configured minimum policy and are stored using a modern adaptive password hash.
6. Paid access lasts 30 days from the verified entitlement start unless the payment provider configuration says otherwise.
7. Referral bonuses are granted once per eligible account and cannot be used to bypass quotas or payment controls.
8. AI output is advisory. The interface should encourage checking important answers against textbooks or official sources.
9. User data is retained only for the documented purpose and retention period. Account deletion and data-export policy must be published before launch.

## 11. User experience requirements

- Mobile-first layout at 320px–430px widths, with a comfortable desktop experience.
- Clear primary action on every screen.
- Visible loading, streaming, empty, offline, provider-error, quota-exhausted, payment-pending, and payment-failed states.
- Keyboard navigation and visible focus states.
- Text and controls meet WCAG 2.2 AA contrast and target-size expectations.
- Avoid unexplained jargon such as “provider,” “token,” or “rate limit” in student-facing messages.
- Use Bengali copy for Bengali-first flows; keep technical/legal/payment copy accurate and unambiguous.
- Use one visual system: one logo source, a small spacing scale, consistent radii, typography, iconography, and motion.
- Avoid blocking splash screens; the app should become useful as soon as the shell and auth state are known.

## 12. Non-functional requirements

### Performance

- First meaningful app content: p75 ≤ 2.5 seconds on a mid-range mobile device over a 4G-like connection.
- Time to first AI output: p75 ≤ 2.0 seconds when the provider is healthy.
- Initial JavaScript: target ≤ 250 KB compressed for the authenticated shell, excluding explicitly lazy-loaded tools.
- Largest above-the-fold images should be optimized and served in appropriate dimensions.
- No request should block the UI indefinitely; all network operations need timeouts and cancellation.

### Reliability

- Target 99.5% monthly availability for core auth and tutoring APIs after launch.
- Provider failure must not corrupt quota, conversations, or payments.
- Payment verification must be idempotent and recoverable after a timeout.
- Automated daily SQLite backup with tested restore procedure.

### Security and privacy

- No secrets committed to Git, bundled into frontend assets, or uploaded by deployment tooling.
- Strict CORS allowlist; no reflected arbitrary origin with credentials.
- CSRF protection for cookie-authenticated state-changing requests.
- Secure, HttpOnly, SameSite cookies and server-side session rotation.
- Centralized authorization; fail closed when configuration is missing.
- Audit logging for privileged and financial actions.
- Production error responses contain a request ID, not stack traces or credentials.

### Accessibility and compatibility

- WCAG 2.2 AA target.
- Current Chrome, Edge, Safari, and Firefox; supported Android/iOS mobile browsers.
- Reduced-motion preference respected.
- Touch, keyboard, and screen-reader basic flows tested before release.

## 13. Analytics and success metrics

Track privacy-conscious aggregate events, not raw prompt content:

- `signup_started`, `signup_verified`, `login_succeeded`, `login_failed`
- `question_submitted`, `answer_first_chunk`, `answer_completed`, `answer_failed`
- `quiz_started`, `quiz_completed`
- `plan_viewed`, `payment_started`, `payment_verified`, `payment_failed`
- `quota_exhausted`, `provider_fallback`, `pwa_installed`, `feedback_submitted`

Initial success targets for the first 30 days after public release:

- ≥ 60% of verified students submit a first question.
- ≥ 40% of first-question users return within seven days.
- ≥ 95% of accepted AI requests complete without a user-visible server error.
- ≥ 99% of successful payments produce the correct entitlement exactly once.
- < 2% of authenticated support events are attributable to incorrect quota display or entitlement state.
- No confirmed credential exposure, cross-user data exposure, or unauthorized admin action.

## 14. Release plan

### Phase 0 — Release hardening

- Rotate all exposed credentials and remove secret files from source/deployment scope.
- Choose and enforce the production runtime: PHP/Apache/SQLite for the documented shared-hosting target.
- Remove or protect debug, test, migration, and diagnostic routes.
- Reconcile admin session keys, CORS, rate limiting, OTP policy, and plan enforcement.
- Establish Git history, environment separation, backups, CI checks, and a rollback procedure.

### Phase 1 — Core production release

- Auth, AI tutor, image/PDF flow, history, preset/custom quiz, plans, payment verification, PWA shell, and admin essentials.
- Bengali-first UI and premium interaction states.
- Automated security, API, accessibility, and mobile smoke tests.

### Phase 2 — Growth and operational maturity

- Telegram integration.
- Better personalization and study recommendations.
- Read-only support role and richer analytics.
- Provider routing and cost controls based on observed usage.

## 15. Launch gates

The product is not launch-ready until all of the following are true:

- All live-looking API, database, FTP, OAuth, payment, email, Firebase, Telegram, and AI credentials have been rotated and verified absent from Git and deploy artifacts.
- One production runtime is selected and the other runtime’s routes cannot accidentally be invoked.
- Admin authorization is consistent across every admin endpoint.
- Quota enforcement is plan-aware, atomic, and tested under concurrency.
- Payment verification binds order, user, amount, plan, status, and idempotency.
- CORS, CSRF, cookies, upload validation, CSP, and production headers pass security review.
- No public debug/test/migration endpoint remains.
- Automated checks pass: PHP lint, JavaScript lint/type/syntax checks, unit tests, API integration tests, browser smoke tests, and accessibility checks.
- Backups have been restored successfully in a staging environment.
- A privacy policy, terms/payment policy, support contact, and AI accuracy disclaimer are published.

## 16. Open product decisions

1. Confirm whether guest app mode is retained for production and define its exact quota.
2. Confirm Razorpay order/webhook/refund requirements and tax/invoice copy.
3. Confirm the final OTP delivery provider and sender/domain requirements.
4. Confirm account deletion, export, and retention periods.
5. Confirm whether leaderboard participation is opt-in and what anonymization format is acceptable.
6. Confirm official quiz source ownership and content review process.
7. Confirm the final list of supported exams and subjects for the first release.

## 17. Traceability

The TRD accompanying this PRD is the implementation contract. Every FR requirement should map to at least one API/UI test, and every launch gate should map to a CI or deployment checklist item. Where the master document and current source differ, the documented product behavior in this PRD is the target; current source behavior must not be treated as acceptance criteria until it passes the launch gates.

