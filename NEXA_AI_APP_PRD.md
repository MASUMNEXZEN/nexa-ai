# NexA AI Student App — Product Requirements Document

**Status:** Proposed implementation baseline  
**Version:** 1.0  
**Date:** 2026-08-09  
**Product:** NexA AI mobile study companion  
**Primary source of truth:** Existing NexA PHP/SQLite backend, `STUDY_PLANNER_PRD.md`, and `STUDY_PLANNER_TRD.md`

## 1. Product decision

NexA AI will become a mobile student app for Android and iOS, backed by the existing NexA API and portable study-planner module.

The app is a new client, not a second product backend. Authentication, content trust, question correctness, planner generation, progress, and AI-provider access remain server-controlled.

Recommended implementation baseline:

- React Native with TypeScript and Expo for one Android/iOS codebase.
- Existing PHP API as the server boundary.
- The current responsive web app remains available as the web/PWA client.
- No payment or subscription requirement in the first app release.
- Student academic content is served in English. The general assistant may support the existing Bengali/English conversation experience where the user explicitly asks for it.

## 2. Product vision

Give every student a calm, reliable study companion that turns a verified syllabus into a practical daily rhythm, explains difficult concepts, measures progress, and lets the student take control whenever the recommended plan does not fit real life.

The app should feel like a premium study workspace rather than a collection of disconnected quiz screens.

## 3. Users

### Primary user: exam student

Needs to understand what to study next, practise from trusted material, recover weak topics, and adapt the plan around school, work, travel, and missed days.

### Secondary user: returning learner

Already has history, saved answers, bookmarks, topic progress, and an active plan. Needs fast access to Today, manual planning, revision, and unfinished work.

### Internal users

- Admin: imports, validates, publishes, archives, and monitors academic content.
- Subject reviewer: corrects metadata and reviews assigned content when that workflow is enabled.

Internal users continue to use the protected web admin console in Phase 1; the student app is not an admin app.

## 4. Goals

1. Make the existing intelligent planner usable as a dependable mobile experience.
2. Generate daily, weekly, and calendar-month study plans without asking students for an exam date.
3. Let students manually plan a day or week without losing the intelligent recommendations.
4. Make verified syllabus topics and future PYQs easy to reach from the plan.
5. Preserve progress and task state across devices and intermittent connectivity.
6. Keep AI helpful but never authoritative for official question answers, scoring, or syllabus ownership.
7. Deliver an accessible, fast, polished experience on budget Android devices as well as iPhones.

## 5. Non-goals for the first app release

- No in-app payment, subscription purchase, or paywall.
- No exam-date collection or countdown-driven planning.
- No student-uploaded canonical content.
- No admin/content-review workflow inside the mobile app.
- No offline Gemini generation.
- No promise of marks, rank, selection, or exam success.
- No second database or planner algorithm in the mobile client.

## 6. MVP scope

### Account and identity

- Email/password sign-in and registration.
- Google sign-in where the production OAuth configuration supports the app client.
- Sign out from the current device and revoke other sessions where supported.
- Password reset and account profile.
- Guest chat may remain available, but saved plans, diagnostics, progress, bookmarks, and history require login.

### Study workspace

Bottom navigation or equivalent primary navigation:

1. Today
2. Planner
3. Practice
4. Chat
5. Profile

The student should reach the next task in two taps or fewer from app launch.

### Planner

- Calendar-month view with active days, minutes, task counts, and completion state.
- Week view with subject focus and task distribution.
- Today view with ordered sessions and completion actions.
- Progress view with topic accuracy, attempts, mastery state, and revision signals.
- Profile preferences: exam, capacity, available days, preferred session length, preferred time block, and current level.
- Diagnostic assessment before the first personalized plan.
- Intelligent month regeneration after relevant preference or progress changes.

### Manual planning

Students can choose either:

- **Plan a day:** choose a date, subject, syllabus topic, session type, duration, and optional title; add more day sessions as needed.
- **Plan a week:** add multiple sessions across one calendar week, mixing dates, subjects, syllabus topics, session types, and durations.

Manual sessions:

- Are authenticated and owned by the student.
- Use the same task lifecycle as generated sessions: planned, in progress, completed, skipped, rescheduled, or cancelled.
- Appear in Today, Week, Month, and progress-aware task lists.
- Remain after an intelligent plan refresh.
- Cannot reference an unavailable exam, subject, topic, or another student’s task.

### Practice

- Open a verified question attached to a planner task.
- Answer one question at a time.
- Show the authoritative result and explanation after submission.
- Record topic progress and update the task state where appropriate.
- Enter mock-test and custom practice modes through server-authorized flows.

### Chat

- Ask a study question in the existing supported conversation experience.
- Use local approved content first when a verified syllabus topic or question is relevant.
- Show provider-unavailable and network states clearly.
- Allow copy and save where supported.
- Never expose provider keys or technical provider errors.

### Profile and preferences

- View account identity and current exam.
- Change planner capacity and available weekdays.
- Change target exam only through a validated server flow.
- View saved answers/bookmarks and history.
- Toggle theme and notification preferences.
- Report a problem.

## 7. Core user journeys

### First-time student

1. Install and open the app.
2. Read a short value statement and sign in or continue as guest.
3. Select the available exam.
4. Set study minutes, session preference, current level, preferred time, and available days.
5. Complete the diagnostic using verified questions.
6. Land on Today with a clear next session.
7. Complete, skip, or manually adjust the plan.

### Returning student

1. Open the app.
2. See Today immediately after session validation.
3. Resume the next task or open the chat/practice entry point.
4. Use Week or Month to understand workload.

### Manual day planning

1. Open Today and tap **Plan this day**.
2. Select date, subject, topic, type, and duration.
3. Save the session.
4. See the saved session in the task list and receive the same task actions as an intelligent task.

### Manual week planning

1. Open Week and tap **Plan this week**.
2. Add one or more rows.
3. Select dates inside the same calendar week, subjects, topics, types, and durations.
4. Save the batch.
5. See the sessions grouped by date and continue editing through task actions.

### Missed session

1. Student skips or leaves a task incomplete.
2. The server records the outcome.
3. The next plan refresh reallocates future capacity according to planner policy.
4. The app explains what moved; it does not create an infinite backlog.

## 8. Product requirements

### PR-001 — Server-authoritative identity

The app must never treat a device identifier as the student identity. Every saved student operation requires an authenticated server identity.

### PR-002 — Safe first run

A student must see a useful explanation of why login is needed before being asked to create an account. Guest chat may remain usable without persistence.

### PR-003 — Planner readiness

The app must explain each missing prerequisite: profile, onboarding, or diagnostic. It must not display an empty planner page that looks broken.

### PR-004 — Calendar planning

Today, Week, and Month must be consistent projections of the same normalized task data. Sunday is a valid planning day when selected by the student.

### PR-005 — Manual day planning

The app must allow at least one and no more than twelve validated manual sessions for one date in a request.

### PR-006 — Manual week planning

The app must allow at least one and no more than twenty-one validated manual sessions in one calendar week.

### PR-007 — Same task lifecycle

Generated and manual sessions must share completion, skip, reorder, reschedule, question, and progress behavior where the task type supports it.

### PR-008 — English academic content

Syllabus topics, official questions, options, answers, explanations, planner task titles, and diagnostic content are served in English.

### PR-009 — Offline honesty

The app may show cached planner and content data offline, but must label stale data and must not claim that a server write succeeded until it is acknowledged or safely queued.

### PR-010 — Accessibility

Interactive controls must have accessible names, visible focus/pressed states, readable contrast, dynamic text support, and a minimum touch target of 44 dp.

### PR-011 — Error recovery

Every network-backed screen must provide loading, empty, offline, unauthorized, stale, and retry states. Technical provider/database details must never be shown to students.

### PR-012 — No payment dependency

No MVP screen may block planner, practice, chat, or saved progress on payment. Payment endpoints may remain available on the backend for future phases.

## 9. Content and AI policy

- Official syllabus and PYQs come from the owner-verified local database.
- AI may explain, summarize, polish presentation, and generate clearly labeled practice content under server policy.
- AI may not change an authoritative question, option, correct answer, source year, or marking category.
- The app must distinguish verified content from AI-generated practice.
- The app must show a source or trust label wherever a student could mistake generated practice for an official question.

## 10. Notifications

MVP notification support:

- Optional reminder for the next planned study session.
- Optional reminder for an overdue revision or unfinished task.
- No more than one daily reminder by default.
- Student-controlled quiet hours and opt-out.

Push delivery is a later implementation phase if the current FCM integration is not ready for native device registration.

## 11. Success metrics

### Activation

- 70% of logged-in new students complete planner onboarding.
- 60% complete the diagnostic within the first session.
- 60% open or complete one planned task within seven days.

### Engagement

- 3+ active study days per week among activated students.
- At least 20% of active planners use manual planning within the first month.
- 70% of planned task state changes are intentional completion or skip actions rather than silent abandonment.

### Quality and trust

- Zero authoritative answer mutations through AI polishing.
- Zero cross-user planner/task access findings.
- 99% of accepted manual-plan requests render in the next read response.
- Crash-free sessions target: 99.5% after public beta.

## 12. Acceptance criteria

The MVP is acceptable when:

1. A new student can sign in, configure a profile, complete the diagnostic, and see Today.
2. Today, Week, Month, and Progress use the same server task state.
3. A student can save a manual day plan using a real syllabus topic.
4. A student can save a multi-session manual week plan using different subjects.
5. Invalid subject/topic/date/week inputs are rejected with safe messages.
6. Manual sessions survive an intelligent month refresh.
7. Task completion and question answering update progress after reconnect.
8. Cached reads are clearly labeled offline/stale and never masquerade as successful writes.
9. App logout removes local private data and invalidates the session.
10. Android and iOS release builds pass accessibility, offline, security, and smoke tests.

## 13. Delivery phases

### Phase 0 — App foundation

Create the mobile shell, design tokens, navigation, secure auth session, API client, error states, and analytics consent.

### Phase 1 — Planner parity

Implement onboarding, diagnostic, Today, Week, Month, Progress, task actions, manual day/week planning, and sync.

### Phase 2 — Practice and chat parity

Implement verified practice, mock flows, saved answers, history, bookmarks, and chat streaming/retry behavior.

### Phase 3 — Offline and notifications

Add downloadable content packs, queued mutations, push reminders, quiet hours, and conflict resolution UX.

### Phase 4 — Expansion

Add more exam packs, richer analytics, subscriptions if approved, and app-specific features based on measured student needs.

## 14. Decisions required before implementation

The following defaults are recommended and can be changed without rewriting the product scope:

- **Mobile framework:** React Native + Expo + TypeScript.
- **Minimum platforms:** Android first, then iOS from the same codebase.
- **Offline depth:** cached planner and downloaded verified content in Phase 3; online AI only.
- **Push provider:** existing FCM integration, extended for native device tokens.
- **Analytics:** privacy-conscious, consent-based product events only; no full question text or private chat content.
- **Release channel:** internal Android testing, closed beta, then public release; iOS TestFlight follows Android validation.
