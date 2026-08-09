# NexA AI Study Planner — Product Requirements Document

Status: Approved product direction  
Scope: Phase 1 — West Bengal ANM(R) & GNM  
Primary audience: Logged-in students  
Student academic language: English only

## 1. Product decision summary

NexA AI will provide a personalized study-planning system built on a verified local academic database.

The first complete content pack is West Bengal ANM(R) & GNM. The architecture must remain reusable for WBJEE, JENPAS UG, WBP/KP, SSC, WBCS, and other supported exams.

The planner will not ask students for an examination date. Instead, it will generate continuous calendar-based plans from the student’s current study profile, available time, selected weekly subjects, syllabus progress, question performance, and revision needs.

The product has three connected planning levels:

- Daily planner: concrete study sessions and questions for today.
- Weekly planner: recommended and student-selected subjects, task distribution, and catch-up.
- Calendar-month planner: monthly syllabus coverage, PYQ targets, revision goals, and mock-test goals.

## 2. Owner decisions

- Students must be logged in to create and retain a planner.
- Students may provide a daily study-time preference, available study days, preferred session length, and preferred study time.
- If no daily study-time preference is provided, the planner uses 3 hours of self-study as the planning baseline.
- The student is never blocked by a fixed minimum daily study time. The smallest recommended session block is 30 minutes, and the student may choose a longer or custom duration.
- A diagnostic test is required before the first personalized plan.
- Official subject allocation is the baseline, but students may customize their weekly subject focus.
- Students can edit, skip, reorder, complete, and reschedule tasks.
- Missed work is intelligently reassigned rather than endlessly stacked.
- PYQs are available as year-wise papers, topic practice, and mock tests.
- Category II practice is recommended after concept learning and Category I confidence.
- Canonical content is uploaded as Markdown or Word documents by the owner/admin team.
- Owner-verified content does not need another academic approval cycle.
- The system still performs structural validation, duplicate checks, and import preview.
- Admin and subject-reviewer roles exist for content governance and future non-verified imports.
- Official questions and answers remain authoritative.
- AI may polish presentation or explanations before serving, but must not change an authoritative question, option, or answer.
- Students receive academic questions, explanations, plans, and quizzes in English.
- Books and question banks may be mapped automatically from headings, then validated during import.
- Gemini may use approved local content plus general knowledge for explanations, planning, and new practice questions.
- AI-generated content may be temporary or stored as a draft.
- Permanent generated content requires an explicit trusted publication decision.
- Mastery is initially reached at 80% accuracy across the latest 10 relevant questions.
- The owner confirms that uploaded content is owned or authorized for use.

## 3. Phase 1 content baseline

The ANM(R) & GNM syllabus contains six subjects:

| Subject | Code | Baseline allocation |
|---|---|---:|
| Life Science | LS | 43.48% |
| Physical Science | PS | 21.74% |
| Basic English | EN | 13.04% |
| Mathematics | MA | 8.70% |
| General Knowledge | GK | 8.70% |
| Logical Reasoning | LR | 4.35% |

The initial corpus includes 2022–2025 previous-year questions with year, subject, category, options, answers, and explanations. The content model must preserve the source year and question identity.

## 4. Goals

1. Turn the verified syllabus and PYQs into a usable student learning path.
2. Generate actionable daily, weekly, and calendar-month plans without requiring an exam date.
3. Make the plan responsive to student preferences and demonstrated performance.
4. Prioritize official core syllabus before lower-priority extensions.
5. Make PYQs and revision part of the normal plan, not separate hidden features.
6. Keep authoritative content local, traceable, and safe from accidental AI rewriting.
7. Provide an admin workflow that can scale to books, question banks, and additional exams.
8. Maintain a premium, calm, English-first student experience.

## 5. Non-goals

- Predicting a guaranteed rank, score, or admission result.
- Replacing official WBJEEB notices or information bulletins.
- Allowing students to publish into the canonical question bank.
- Asking students for or depending on a target examination date.
- Treating AI-generated material as official without a trusted publication decision.
- Building payment-dependent planner access in the first planner release.

## 6. Student onboarding

After login, the student selects an exam. For Phase 1 the available exam is ANM / GNM.

The planner collects:

- Optional daily study minutes or hours. If omitted, use 180 minutes as the default self-study capacity.
- Available days of the week.
- Preferred session length: 30 minutes or longer, including a custom duration.
- Preferred study time block.
- Current preparation level.
- Optional weekly subject focus.

The student is not asked for an examination date.

The student then completes a diagnostic assessment. The diagnostic should sample the official subject allocation and include a mixture of verified PYQs and curated local questions.

The first plan is generated only after the student profile and diagnostic are available. A student may regenerate the plan after changing preferences.

## 7. Weekly subject preferences

The planner recommends subjects for the coming week using:

- Official subject allocation.
- Diagnostic accuracy.
- Recent mistakes.
- Due revisions.
- PYQ recurrence.
- Unfinished core topics.
- Student-declared weekly focus.

Students can select their own weekly subjects. Their selection is a strong preference, not an instruction to permanently ignore the official baseline. The planner should explain when it keeps a small allocation for an unselected high-weight subject.

Every available study day must contain at least three distinct subject touchpoints. A touchpoint may be a concept, practice set, PYQ, revision item, or short recovery task. The three-subject rule is a coverage rule, not a minimum daily-time rule. If the student has very little available time, the planner keeps the three touchpoints short and clearly labels the day as light coverage.

## 8. Plan behavior

### Calendar-month plan

The monthly planner is a calendar-month view. It generates goals for the remaining days of the current month and future calendar months as requested.

Monthly goals include:

- Topics to learn.
- Topics to practise.
- PYQ volume.
- Mock-test targets.
- Revision targets.
- Weak-area recovery.
- Completion percentage.

### Weekly plan

The weekly plan distributes available study time across recommended and selected subjects. It reserves time for:

- New concepts.
- Category I practice.
- Category II practice.
- PYQs.
- Revision.
- Error-log review.
- Mock tests.

### Daily plan

A daily plan contains ordered tasks with estimated time, subject, topic, task type, and a clear completion action. It includes at least three distinct subjects on every available study day, while honoring declared capacity as closely as possible.

Task types:

- Learn.
- Practice.
- PYQ.
- Revision.
- Error review.
- Mock test.
- Recovery or catch-up.

Students can complete, skip, reorder, or reschedule tasks. Completing or skipping a task immediately updates future recommendations.
Students can also take direct control without replacing the intelligent plan:

- Manual day planning lets a student add one or more sessions to a chosen generated date.
- Manual week planning lets a student add a small set of sessions across one calendar week, mixing subjects, syllabus topics, session types, and durations.
- Manual sessions use the same authenticated task actions, progress tracking, and ownership rules as generated sessions.
- Manual sessions remain visible when the intelligent month is refreshed; the refresh may add or reorder generated recommendations around them.

## 9. Adaptive planning rules

1. Preserve the official subject-weight baseline.
2. Increase priority for topics with low recent accuracy.
3. Give uncompleted CORE topics priority over PYQ_EXT topics.
4. Promote PYQ_EXT topics when they recur across previous papers.
5. Keep current affairs as a separately refreshable monthly stream.
6. Recommend Category II after concept learning and sufficient Category I accuracy.
7. Schedule revision after approximately 1 day, 3–7 days, 15 days, and before a selected review point.
8. Reassign missed tasks into available future capacity instead of accumulating an unbounded backlog.
9. Reserve weekly capacity for PYQs, mocks, and error-log review.
10. Never convert readiness into a guaranteed score or rank.
11. Keep the student’s weekly subject choices visible in the reason for each recommendation.

## 10. Practice modes

### Learning mode

- No negative marking.
- Immediate correctness feedback.
- Explanation after each response.
- Recommended for concept building and revision.

### Exam mode

- Official question distribution where available.
- Timer.
- Official marking rules where available.
- Results and explanations after submission.
- Recommended for mocks and readiness checks.

## 11. Content experience

Students receive:

- Original English question text.
- Original answer options.
- Verified correct answer.
- Clear English explanation.
- Source year and subject where relevant.
- Topic and syllabus mapping where available.
- Practice mode and difficulty context.

AI polishing may improve grammar, structure, formatting, or explanation clarity. It must not silently change the authoritative question, options, correct answer, source year, or marking category.

## 12. Admin and subject-reviewer experience

Admin users can:

- Import Markdown or Word files.
- Preview parsed content.
- See structural validation errors.
- See duplicate candidates.
- Confirm the exam, subject, unit, topic, source, year, and trust level.
- Mark owner-verified content as publishable without another academic review.
- Assign content to a subject reviewer when the content is not already verified.
- Archive content without deleting historical question mappings.
- View import history and audit events.

Subject reviewers can:

- Inspect non-verified or flagged content.
- Correct metadata and mappings.
- Approve or reject content assigned to them.
- Record a review note.

## 13. Success metrics

- At least 80% of new students complete onboarding and diagnostic.
- At least 60% of active students complete one planned task within their first week.
- Planned tasks are completed or intentionally skipped, rather than silently abandoned.
- Topic mastery and revision schedules reflect new performance within one plan refresh.
- No published official question has a changed answer caused by AI polishing.
- Import validation catches malformed questions, missing options, invalid answers, and duplicates before publication.
- Students can find a relevant verified PYQ or syllabus topic from the planner without using free-form search.

## 14. Acceptance criteria

The planner is ready for Phase 1 when:

1. A logged-in ANM/GNM student can complete onboarding without providing an exam date.
2. A diagnostic result produces a personalized calendar-month plan.
3. The weekly planner recommends subjects and accepts student overrides.
4. Daily tasks are actionable, editable, and adaptive.
5. Missed work is rescheduled without infinite backlog growth.
6. Official PYQs are available year-wise, subject-wise, topic-wise, and in mocks.
7. Learning and Exam modes behave differently and visibly.
8. Markdown and Word imports have preview and structural validation.
9. Owner-verified uploads can publish without another academic review.
10. AI polishing cannot modify authoritative question or answer fields.
11. Admin and subject-reviewer permissions are enforced server-side.
12. The planner works on the existing responsive web/PWA architecture.
