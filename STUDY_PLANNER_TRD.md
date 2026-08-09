# NexA AI Study Planner — Technical Requirements Document

Status: Design baseline  
Scope: Phase 1 — West Bengal ANM(R) & GNM  
Runtime: Existing PHP + SQLite backend and static/PWA frontend

## 1. Technical principles

- Keep the existing canonical architecture: frontend/ and backend/api/.
- Keep private writable data under backend/data/.
- Do not add a second chat engine, database contract, or parallel runtime.
- The browser is a client, never the authority for identity, plan state, content trust, quota, or scoring.
- Use prepared SQL and explicit column lists.
- Validate every import and API request at the boundary.
- Keep source documents and published records traceable.
- Keep authoritative content separate from AI-generated or AI-polished text.
- All student planner endpoints require authentication.
- All admin content endpoints require admin authorization.
- Subject-reviewer capabilities must be server-side role permissions.
- Preserve historical records by archiving instead of deleting mapped content.

## 2. Portable module boundary

The planner is designed as an extractable product module. NexA-specific code must live at the edges.

### Portable core

The portable core owns:

- Planner policies and priority calculation.
- Calendar-month, weekly, and daily task generation.
- Capacity calculation.
- Mastery and revision rules.
- Task state transitions.
- Content and question contracts.
- Markdown and Word import normalization.
- Duplicate and structural validation.
- Learning-mode and Exam-mode scoring policies.
- AI polishing safety rules.

The portable core must not depend on:

- NexA branding.
- NexA route names.
- NexA DOM selectors.
- Google/Gemini client details.
- A specific authentication provider.
- A specific SQL driver.
- Payment or subscription implementation.
- A particular exam or subject name.

### Recommended extraction layout

Backend module:

- backend/planner/domain/: pure planning rules and value objects.
- backend/planner/application/: use cases such as generate month, complete task, and import content.
- backend/planner/contracts/: request, response, repository, and provider interfaces.
- backend/planner/importers/: Markdown and Word parsing plus normalized import records.
- backend/planner/adapters/: NexA PDO, session, audit, and AI adapters.
- backend/api/: thin NexA HTTP controllers only.

Frontend module:

- frontend/planner/core/: state, client-side models, and feature flags.
- frontend/planner/api/: host-neutral API client functions.
- frontend/planner/views/: month, week, day, onboarding, diagnostic, and task views.
- frontend/planner/components/: reusable task cards, progress views, subject selectors, and question cards.
- frontend/planner/adapters/: NexA workspace shell, navigation, theme, and icon adapters.

Content packs:

- A content pack declares exam code, subjects, official weights, syllabus units, topic IDs, marking rules, and source metadata.
- The planner core consumes a content-pack contract and never embeds ANM/GNM-specific branches.
- A second web product can supply a different content pack without changing planner algorithms.

### Host adapters

The NexA host supplies:

- Authentication and user identity.
- PDO connection and migrations.
- API route registration.
- Admin and subject-reviewer authorization.
- Gemini or another approved model provider.
- NexA workspace layout and theme.
- Logging, security headers, CSRF, and rate limits.

A future host supplies equivalent adapters. The planner core remains unchanged.

### Extraction rule

To reuse the module elsewhere, copy the portable backend and frontend directories, add the target host adapters, provide a content pack, run the planner migrations, and register thin host routes. No NexA API, logo, payment, or CSS dependency may be required by the core.

### Boundary tests

- Planner domain tests run without HTTP, browser DOM, Gemini, or SQLite.
- Importer tests run against Markdown and Word fixtures.
- Adapter tests verify the host-specific database, auth, and API contracts.
- Contract tests ensure every host returns the same planner response shape.

## 3. Runtime components

### Frontend

Extend the existing NexA workspace with:

- Planner dashboard.
- Month view.
- Week view.
- Today view.
- Onboarding and diagnostic flow.
- Weekly subject-selection control.
- Task completion, skip, reorder, and reschedule controls.
- Topic mastery and revision display.
- PYQ browsing and practice entry points.
- Learning-mode and Exam-mode quiz presentation.
- Import-preview UI for admin users.
- Review queue UI for subject reviewers where required.

The planner must remain usable on 320px–430px screens and desktop widths.

### Backend

Add focused PHP API controllers under backend/api/:

- planner-profile.php
- planner-month.php
- planner-week.php
- planner-day.php
- planner-task.php
- planner-assessment.php
- planner-progress.php
- admin-content-import.php
- admin-content-preview.php
- admin-content-review.php
- admin-content-publish.php

Every endpoint must define method, authentication, request schema, body limits, response codes, rate limit, and audit behavior. Do not add broad filename routing or debug endpoints.

## 4. Data model

The exact migration names will be selected during implementation. The following fields are the minimum contract.

### exams

- id
- code
- display_name
- active
- syllabus_version
- last_verified_at
- created_at
- updated_at
- origin: generated or manual

Example code: WB_ANM_GNM.

### exam_subjects

- id
- exam_id
- code
- name_en
- official_weight_percent
- display_order
- active
- created_at
- updated_at

Phase 1 subjects: LS, PS, EN, MA, GK, LR.

### syllabus_units

- id
- exam_id
- subject_id
- code
- title_en
- scope_tag
- default_difficulty
- estimated_minutes
- active
- source_id
- created_at
- updated_at

### syllabus_topics

- id
- unit_id
- code
- title_en
- keywords
- scope_tag
- default_difficulty
- estimated_minutes
- prerequisite_topic_id
- active
- source_id
- created_at
- updated_at

The stable unit and topic codes must be preserved when syllabus wording changes but topic meaning remains the same.

### content_sources

- id
- source_type: syllabus, pyq, book, question_bank
- title
- publisher
- source_year
- source_reference
- original_filename
- file_checksum
- parser_version
- ownership_confirmed
- trust_level: owner_verified, reviewer_verified, unverified
- imported_by
- imported_at
- archived_at

### content_imports

- id
- source_id
- format: markdown, docx
- status: uploaded, parsed, validated, imported, failed, archived
- total_records
- valid_records
- invalid_records
- duplicate_records
- validation_summary
- created_by
- created_at
- completed_at

### questions

- id
- exam_id
- subject_id
- unit_id
- topic_id
- source_id
- question_year
- paper_session
- question_number
- category: CATEGORY_I, CATEGORY_II
- question_type
- prompt_en
- correct_option_key
- explanation_en
- difficulty
- scope_tag
- authoritative
- publication_state: draft, published, archived
- trust_level
- ai_polished_explanation
- ai_polish_version
- created_at
- updated_at

The authoritative fields are prompt_en, correct_option_key, question_year, category, and source mapping. AI must never overwrite them.

### question_options

- id
- question_id
- option_key
- option_text_en
- display_order
- created_at

A question must have the required number of unique option keys. correct_option_key must reference an existing option.

### question_tags

- question_id
- tag_type
- tag_value

Use for PYQ recurrence, concept, chapter, nursing relevance, and search terms without duplicating the question record.

### planner_profiles

- user_id
- exam_id
- daily_minutes (nullable; defaults to 180 when omitted)
- preferred_session_minutes
- preferred_time_block
- current_level
- onboarding_completed_at
- diagnostic_completed_at
- active
- created_at
- updated_at

Do not store an exam date because the planner does not request one.

daily_minutes is a preference, not a hard minimum. The planner defaults an omitted value to 180 minutes of self-study. preferred_session_minutes must be at least 30 when supplied; custom values are allowed above that floor.

### planner_availability

- user_id
- weekday
- available
- minutes
- updated_at

Use one row per weekday rather than an opaque client-controlled JSON object.

### weekly_subject_preferences

- id
- user_id
- calendar_year
- calendar_week
- subject_id
- preference_rank
- selected_by_student
- created_at

Student selection is a strong preference signal, not permission to remove the official baseline.

### diagnostic_attempts

- id
- user_id
- exam_id
- question_count
- started_at
- completed_at
- score
- status

### diagnostic_answers

- id
- attempt_id
- question_id
- selected_option_key
- is_correct
- answered_at

### planner_months

- id
- user_id
- exam_id
- calendar_year
- calendar_month
- generated_at
- generation_version
- status
- summary_json

The calendar month is the planning boundary. The summary may be JSON for presentation only; authoritative tasks remain normalized.

### planner_weeks

- id
- month_id
- user_id
- week_start
- week_end
- generation_version
- status
- generated_at

### planner_tasks

- id
- user_id
- month_id
- week_id
- scheduled_date
- task_type
- subject_id
- unit_id
- topic_id
- question_id
- title_en
- estimated_minutes
- display_order
- status: planned, in_progress, completed, skipped, rescheduled, cancelled
- rescheduled_from_task_id
- completed_at
- completion_note
- created_at
- updated_at

### topic_progress

- id
- user_id
- topic_id
- attempts
- correct_attempts
- recent_accuracy
- mastery_status
- last_studied_at
- next_revision_at
- updated_at

mastery_status values: not_started, learning, practice, revision, mastered.

### planner_events

- id
- user_id
- task_id
- event_type
- event_payload
- created_at

Use for auditability of completion, skip, reorder, reschedule, and regeneration events.

## 5. Import contract

### Supported files

- Markdown .md
- Word .docx

Student uploads are not canonical content imports. Canonical import is admin-only.

### Required import metadata

Every import must identify:

- Exam.
- Subject.
- Unit or topic mapping.
- Source title.
- Source year where applicable.
- Owner or authorization confirmation.
- Trust level.
- Whether it is official, owner-created, book-derived, or question-bank content.

### Markdown structure

The importer should support the provided syllabus and PYQ style, including:

- Exam and source headings.
- Subject headings.
- Unit and topic headings.
- Question headings.
- Option lines.
- Correct-answer lines.
- Explanation lines.
- Year and category context.

The parser must not rely on one exact question number range. It must preserve source context and report ambiguous records.

### Word structure

The first production template should use heading styles and explicit labels for:

- Subject.
- Unit.
- Topic.
- Question.
- Option A/B/C/D.
- Correct answer.
- Explanation.
- Year.
- Category.
- Source.

The admin import UI must show a preview before writing records.

### Validation

Reject or flag:

- Missing exam or subject.
- Missing question text.
- Duplicate option keys.
- Correct answer not present among options.
- Missing source mapping.
- Invalid category.
- Unsupported file format.
- Excessive document size.
- Duplicate checksum or normalized question.
- Unsupported embedded content.
- Unmapped unit or topic.

Owner-verified content bypasses academic review after import validation. This is not a bypass of security, schema validation, duplicate detection, or audit logging.

## 6. Planner engine

The planner service calculates available capacity from planner_profile and planner_availability.

Each month:

1. Load the official syllabus baseline.
2. Load current topic progress and due revisions.
3. Load diagnostic and recent practice accuracy.
4. Load PYQ recurrence and source priority.
5. Load the student’s weekly subject preferences.
6. Reserve capacity for revision, PYQs, error review, and mocks.
7. Allocate new learning and practice tasks.
8. Generate normalized month, week, and day tasks.
9. Store generation_version and the input snapshot hash.

The planner must be deterministic for the same input snapshot and generation version. AI may assist with explanations and human-readable summaries but cannot be the only planner authority.

### Recommended priority model

The initial score may combine:

- Official subject baseline: 50%.
- Weakness and recent accuracy: 25%.
- Due revision: 15%.
- Student weekly preference: 7%.
- PYQ recurrence: 3%.

The coefficients must live in server configuration or a versioned planner policy, not in browser code.

### Capacity rules

- Never schedule more minutes than the student’s declared availability.
- If daily availability is omitted, plan against 180 minutes.
- Every available study day must contain at least three distinct subject touchpoints.
- Treat 30 minutes as the smallest recommended session block, not as a mandatory total daily study time.
- When capacity is too small for three full blocks, keep the three touchpoints short, mark the day as light coverage, and do not reject the profile.
- Preserve at least one recovery/catch-up slot where possible.
- Do not create an infinite backlog.
- If capacity is insufficient, explain which lower-priority tasks moved.
- A student can manually override display order, but server validation prevents impossible or unauthorized task references.

### Mastery and revision

- Initial mastery threshold: at least 80% over the latest 10 relevant questions.
- Use fewer than 10 attempts as provisional progress, never final mastery.
- Schedule revision after approximately 1 day, 3–7 days, 15 days, and later review.
- A wrong answer reopens the topic and can create a focused recovery task.
- Category II recommendations require concept exposure and adequate Category I confidence.

## 7. AI boundary

### Local-first retrieval

For an official question or syllabus topic:

1. Retrieve approved local content.
2. Pass the relevant source context to the model.
3. Ask for an English explanation or plan summary.
4. Preserve the authoritative fields separately.
5. Mark the response as AI-assisted.

### Allowed AI polishing

AI may:

- Improve grammar.
- Reformat an explanation.
- Make an explanation clearer.
- Create a concise hint.
- Create a student-friendly plan summary.
- Generate additional practice from approved topic context.

AI may not:

- Change the official question.
- Change options.
- Change correct_option_key.
- Change year, category, source, or official marking.
- Claim an unsupported source.
- Turn an unverified generated question into official content.

### Generated question modes

- Ephemeral: served to the student and not stored as canonical content.
- Draft: stored for later trusted publication.
- Published: only after explicit admin/reviewer trust decision.

## 8. API behavior

All planner routes require an authenticated user session and shared security middleware.

Minimum endpoints:

- planner-profile.php: read and update study preferences.
- planner-assessment.php: start, answer, and complete the diagnostic.
- planner-month.php: read or regenerate a calendar-month plan.
- planner-week.php: read weekly recommendations and save subject preferences.
- planner-day.php: read today’s task list.
- planner-task.php: complete, skip, reorder, or reschedule a task.
- planner-manual.php: create authenticated, syllabus-linked day or week sessions.
- planner-progress.php: read topic progress and mastery.
- admin-content-import.php: upload and create an import job.
- admin-content-preview.php: return parsed records and validation issues.
- admin-content-publish.php: publish or archive trusted content.
- admin-content-review.php: assign and resolve reviewer work.

Use explicit methods and request schemas. Return safe JSON errors with request IDs. Do not expose file paths, SQL, parser traces, or provider errors.

## 9. Authorization

- Student: own planner, own progress, own diagnostic, own task actions.
- Subject reviewer: assigned content review and metadata correction.
- Admin: import, trust, publish, archive, role assignment, and operational access.
- No client-provided user ID, exam ID, task ID, or trust flag is authoritative without server authorization.

## 10. Caching and consistency

- Planner reads may be cached briefly per authenticated user and generation version.
- Task mutation endpoints must invalidate the relevant day, week, and month view.
- Official content caches use source and content version.
- AI polish caches include prompt version, model policy, language, question ID, and content version.
- A content update must not silently rewrite historical attempts or completed tasks.

## 11. Security and privacy

- Enforce authentication and CSRF protections for cookie-authenticated mutations.
- Validate upload MIME, extension, size, and parser limits server-side.
- Store uploaded files outside public web roots.
- Keep source files and private import data under protected backend/data paths.
- Escape all displayed imported and AI-derived content.
- Record admin and reviewer actions with opaque IDs.
- Do not log full questions, private notes, or uploaded document contents unnecessarily.
- Enforce ownership and authorization for every planner read and write.

## 12. Testing requirements

### Unit tests

- Subject baseline allocation.
- Weekly preference weighting.
- Capacity calculation.
- Mastery threshold.
- Revision-date calculation.
- Missed-task rescheduling.
- Category II gating.
- Markdown parser.
- Word parser.
- Duplicate detection.
- Correct-option validation.
- Trust and publication transitions.

### Integration tests

- Authenticated planner creation.
- Diagnostic completion.
- Calendar-month generation.
- Weekly preference update.
- Task completion and adaptive regeneration.
- Manual day and week task creation, validation, persistence, and ownership.
- Unauthorized task access.
- Admin import preview.
- Owner-verified publication.
- Reviewer assignment and decision.
- AI polishing cannot mutate authoritative answer fields.

### Release checks

- Existing npm and PHP checks remain green.
- Migration checks pass on an empty and existing database.
- Mobile smoke tests cover onboarding, month, week, today, task completion, and quiz entry.
- Import fixtures include the supplied ANM/GNM syllabus and 2022–2025 PYQ documents.
- A release report records question counts, invalid records, duplicate candidates, and published content counts.

## 13. Rollout

1. Add migrations and seed the ANM/GNM exam taxonomy.
2. Build the admin Markdown/Word import preview.
3. Import the verified syllabus and PYQ corpus.
4. Build read-only content and topic APIs.
5. Build diagnostic and progress tracking.
6. Build month/week/day planner generation.
7. Add task actions and adaptive rescheduling.
8. Add PYQ and mock integration.
9. Add AI explanation/polish behind explicit feature flags.
10. Run content, security, accessibility, and mobile smoke tests.
11. Publish the ANM/GNM planner before adding the next exam.

## 14. Open implementation gate

Implementation should not begin until:

- The owner confirms the exact Word import template.
- The owner confirms the first planner capacity defaults.
- The owner confirms whether the supplied syllabus and PYQ documents are the first trusted import batch.

English-only applies to academic content. Navigation, settings, and operational UI remain governed by the existing product language policy.
