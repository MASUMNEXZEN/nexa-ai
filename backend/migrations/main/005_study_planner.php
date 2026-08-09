<?php
/**
 * Study planner foundation: portable content taxonomy, learner planning state,
 * diagnostic records, and normalized calendar tasks.
 */
return static function (PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS exams (
    id                INTEGER PRIMARY KEY AUTOINCREMENT,
    code              TEXT NOT NULL UNIQUE,
    display_name      TEXT NOT NULL,
    active            INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    syllabus_version  TEXT NOT NULL DEFAULT '1',
    last_verified_at  DATETIME,
    created_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS exam_subjects (
    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
    exam_id                  INTEGER NOT NULL,
    code                     TEXT NOT NULL,
    name_en                  TEXT NOT NULL,
    official_weight_percent  REAL NOT NULL DEFAULT 0 CHECK (official_weight_percent >= 0),
    display_order            INTEGER NOT NULL DEFAULT 0,
    active                   INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (exam_id, code),
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS content_sources (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    source_type         TEXT NOT NULL CHECK (source_type IN ('syllabus', 'pyq', 'book', 'question_bank')),
    title               TEXT NOT NULL,
    publisher           TEXT,
    source_year         INTEGER,
    source_reference    TEXT,
    original_filename   TEXT,
    file_checksum       TEXT,
    parser_version      TEXT,
    ownership_confirmed INTEGER NOT NULL DEFAULT 0 CHECK (ownership_confirmed IN (0, 1)),
    trust_level         TEXT NOT NULL DEFAULT 'unverified'
                        CHECK (trust_level IN ('owner_verified', 'reviewer_verified', 'unverified')),
    imported_by         INTEGER,
    imported_at         DATETIME,
    archived_at         DATETIME,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (imported_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS content_imports (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    source_id           INTEGER NOT NULL,
    format              TEXT NOT NULL CHECK (format IN ('markdown', 'docx')),
    status              TEXT NOT NULL DEFAULT 'uploaded'
                        CHECK (status IN ('uploaded', 'parsed', 'validated', 'imported', 'failed', 'archived')),
    total_records       INTEGER NOT NULL DEFAULT 0 CHECK (total_records >= 0),
    valid_records       INTEGER NOT NULL DEFAULT 0 CHECK (valid_records >= 0),
    invalid_records     INTEGER NOT NULL DEFAULT 0 CHECK (invalid_records >= 0),
    duplicate_records   INTEGER NOT NULL DEFAULT 0 CHECK (duplicate_records >= 0),
    validation_summary  TEXT NOT NULL DEFAULT '{}',
    created_by          INTEGER,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at        DATETIME,
    FOREIGN KEY (source_id) REFERENCES content_sources(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS syllabus_units (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    exam_id             INTEGER NOT NULL,
    subject_id          INTEGER NOT NULL,
    code                TEXT NOT NULL,
    title_en            TEXT NOT NULL,
    scope_tag           TEXT,
    default_difficulty  TEXT NOT NULL DEFAULT 'medium',
    estimated_minutes   INTEGER NOT NULL DEFAULT 30 CHECK (estimated_minutes > 0),
    active              INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    source_id           INTEGER,
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (exam_id, code),
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES exam_subjects(id) ON DELETE RESTRICT,
    FOREIGN KEY (source_id) REFERENCES content_sources(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS syllabus_topics (
    id                    INTEGER PRIMARY KEY AUTOINCREMENT,
    unit_id               INTEGER NOT NULL,
    code                  TEXT NOT NULL,
    title_en              TEXT NOT NULL,
    keywords              TEXT,
    scope_tag             TEXT,
    default_difficulty    TEXT NOT NULL DEFAULT 'medium',
    estimated_minutes     INTEGER NOT NULL DEFAULT 30 CHECK (estimated_minutes > 0),
    prerequisite_topic_id INTEGER,
    active                INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    source_id             INTEGER,
    created_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at            DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (unit_id, code),
    FOREIGN KEY (unit_id) REFERENCES syllabus_units(id) ON DELETE CASCADE,
    FOREIGN KEY (prerequisite_topic_id) REFERENCES syllabus_topics(id) ON DELETE SET NULL,
    FOREIGN KEY (source_id) REFERENCES content_sources(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS questions (
    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
    exam_id                  INTEGER NOT NULL,
    subject_id               INTEGER NOT NULL,
    unit_id                 INTEGER,
    topic_id                INTEGER,
    source_id               INTEGER,
    question_year           INTEGER,
    paper_session            TEXT,
    question_number         TEXT,
    category                 TEXT NOT NULL DEFAULT 'CATEGORY_I'
                             CHECK (category IN ('CATEGORY_I', 'CATEGORY_II')),
    question_type            TEXT NOT NULL DEFAULT 'mcq',
    prompt_en                TEXT NOT NULL,
    correct_option_key       TEXT NOT NULL,
    explanation_en           TEXT,
    difficulty               TEXT NOT NULL DEFAULT 'medium',
    scope_tag                TEXT,
    authoritative            INTEGER NOT NULL DEFAULT 0 CHECK (authoritative IN (0, 1)),
    publication_state        TEXT NOT NULL DEFAULT 'draft'
                             CHECK (publication_state IN ('draft', 'published', 'archived')),
    trust_level              TEXT NOT NULL DEFAULT 'unverified'
                             CHECK (trust_level IN ('owner_verified', 'reviewer_verified', 'unverified')),
    ai_polished_explanation  INTEGER NOT NULL DEFAULT 0 CHECK (ai_polished_explanation IN (0, 1)),
    ai_polish_version        TEXT,
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES exam_subjects(id) ON DELETE RESTRICT,
    FOREIGN KEY (unit_id) REFERENCES syllabus_units(id) ON DELETE SET NULL,
    FOREIGN KEY (topic_id) REFERENCES syllabus_topics(id) ON DELETE SET NULL,
    FOREIGN KEY (source_id) REFERENCES content_sources(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS question_options (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    question_id      INTEGER NOT NULL,
    option_key       TEXT NOT NULL,
    option_text_en   TEXT NOT NULL,
    display_order    INTEGER NOT NULL DEFAULT 0,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (question_id, option_key),
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS question_tags (
    question_id  INTEGER NOT NULL,
    tag_type     TEXT NOT NULL,
    tag_value    TEXT NOT NULL,
    PRIMARY KEY (question_id, tag_type, tag_value),
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS planner_profiles (
    user_id                    INTEGER PRIMARY KEY,
    exam_id                    INTEGER NOT NULL,
    daily_minutes              INTEGER CHECK (daily_minutes >= 0),
    preferred_session_minutes  INTEGER NOT NULL DEFAULT 50 CHECK (preferred_session_minutes >= 30),
    preferred_time_block       TEXT,
    current_level              TEXT NOT NULL DEFAULT 'beginner',
    onboarding_completed_at    DATETIME,
    diagnostic_completed_at    DATETIME,
    active                     INTEGER NOT NULL DEFAULT 1 CHECK (active IN (0, 1)),
    created_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at                 DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS planner_availability (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id     INTEGER NOT NULL,
    weekday     INTEGER NOT NULL CHECK (weekday BETWEEN 0 AND 6),
    available   INTEGER NOT NULL DEFAULT 0 CHECK (available IN (0, 1)),
    minutes     INTEGER NOT NULL DEFAULT 0 CHECK (minutes >= 0),
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, weekday),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS weekly_subject_preferences (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id             INTEGER NOT NULL,
    calendar_year       INTEGER NOT NULL,
    calendar_week       INTEGER NOT NULL CHECK (calendar_week BETWEEN 1 AND 53),
    subject_id          INTEGER NOT NULL,
    preference_rank     INTEGER NOT NULL DEFAULT 0,
    selected_by_student INTEGER NOT NULL DEFAULT 1 CHECK (selected_by_student IN (0, 1)),
    created_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, calendar_year, calendar_week, subject_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (subject_id) REFERENCES exam_subjects(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS diagnostic_attempts (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id        INTEGER NOT NULL,
    exam_id        INTEGER NOT NULL,
    question_count INTEGER NOT NULL DEFAULT 0 CHECK (question_count >= 0),
    started_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    completed_at   DATETIME,
    score          REAL,
    status         TEXT NOT NULL DEFAULT 'started'
                   CHECK (status IN ('started', 'completed', 'abandoned')),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS diagnostic_answers (
    id                   INTEGER PRIMARY KEY AUTOINCREMENT,
    attempt_id           INTEGER NOT NULL,
    question_id          INTEGER NOT NULL,
    selected_option_key  TEXT,
    is_correct            INTEGER CHECK (is_correct IN (0, 1)),
    answered_at          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (attempt_id, question_id),
    FOREIGN KEY (attempt_id) REFERENCES diagnostic_attempts(id) ON DELETE CASCADE,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS planner_months (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id            INTEGER NOT NULL,
    exam_id            INTEGER NOT NULL,
    calendar_year      INTEGER NOT NULL,
    calendar_month     INTEGER NOT NULL CHECK (calendar_month BETWEEN 1 AND 12),
    generated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    generation_version TEXT NOT NULL,
    status             TEXT NOT NULL DEFAULT 'active'
                       CHECK (status IN ('active', 'superseded', 'archived')),
    summary_json       TEXT NOT NULL DEFAULT '{}',
    UNIQUE (user_id, exam_id, calendar_year, calendar_month, generation_version),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE RESTRICT
);

CREATE TABLE IF NOT EXISTS planner_weeks (
    id                 INTEGER PRIMARY KEY AUTOINCREMENT,
    month_id           INTEGER NOT NULL,
    user_id            INTEGER NOT NULL,
    week_start         TEXT NOT NULL,
    week_end           TEXT NOT NULL,
    generation_version TEXT NOT NULL,
    status             TEXT NOT NULL DEFAULT 'active'
                       CHECK (status IN ('active', 'superseded', 'archived')),
    generated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, week_start, generation_version),
    FOREIGN KEY (month_id) REFERENCES planner_months(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS planner_tasks (
    id                       INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id                  INTEGER NOT NULL,
    month_id                 INTEGER,
    week_id                  INTEGER,
    scheduled_date           TEXT NOT NULL,
    task_type                TEXT NOT NULL
                             CHECK (task_type IN ('learn', 'practice', 'pyq', 'revision', 'error_review', 'mock_test', 'recovery')),
    subject_id               INTEGER NOT NULL,
    unit_id                  INTEGER,
    topic_id                 INTEGER,
    question_id              INTEGER,
    title_en                 TEXT NOT NULL,
    estimated_minutes        INTEGER NOT NULL DEFAULT 0 CHECK (estimated_minutes >= 0),
    display_order            INTEGER NOT NULL DEFAULT 0,
    status                   TEXT NOT NULL DEFAULT 'planned'
                             CHECK (status IN ('planned', 'in_progress', 'completed', 'skipped', 'rescheduled', 'cancelled')),
    rescheduled_from_task_id INTEGER,
    completed_at             DATETIME,
    completion_note          TEXT,
    created_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (month_id) REFERENCES planner_months(id) ON DELETE SET NULL,
    FOREIGN KEY (week_id) REFERENCES planner_weeks(id) ON DELETE SET NULL,
    FOREIGN KEY (subject_id) REFERENCES exam_subjects(id) ON DELETE RESTRICT,
    FOREIGN KEY (unit_id) REFERENCES syllabus_units(id) ON DELETE SET NULL,
    FOREIGN KEY (topic_id) REFERENCES syllabus_topics(id) ON DELETE SET NULL,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE SET NULL,
    FOREIGN KEY (rescheduled_from_task_id) REFERENCES planner_tasks(id) ON DELETE SET NULL
);

CREATE TABLE IF NOT EXISTS topic_progress (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id          INTEGER NOT NULL,
    topic_id         INTEGER NOT NULL,
    attempts         INTEGER NOT NULL DEFAULT 0 CHECK (attempts >= 0),
    correct_attempts INTEGER NOT NULL DEFAULT 0 CHECK (correct_attempts >= 0),
    recent_accuracy  REAL NOT NULL DEFAULT 0 CHECK (recent_accuracy BETWEEN 0 AND 1),
    mastery_status   TEXT NOT NULL DEFAULT 'not_started'
                     CHECK (mastery_status IN ('not_started', 'learning', 'practice', 'revision', 'mastered')),
    last_studied_at  DATETIME,
    next_revision_at DATETIME,
    updated_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (user_id, topic_id),
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (topic_id) REFERENCES syllabus_topics(id) ON DELETE CASCADE
);

CREATE TABLE IF NOT EXISTS planner_events (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id       INTEGER NOT NULL,
    task_id       INTEGER,
    event_type    TEXT NOT NULL,
    event_payload TEXT NOT NULL DEFAULT '{}',
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (task_id) REFERENCES planner_tasks(id) ON DELETE SET NULL
);

CREATE INDEX IF NOT EXISTS idx_syllabus_units_subject
    ON syllabus_units (exam_id, subject_id, active);
CREATE INDEX IF NOT EXISTS idx_syllabus_topics_unit
    ON syllabus_topics (unit_id, active);
CREATE INDEX IF NOT EXISTS idx_questions_subject_year
    ON questions (exam_id, subject_id, question_year, publication_state);
CREATE INDEX IF NOT EXISTS idx_questions_topic
    ON questions (topic_id, publication_state);
CREATE INDEX IF NOT EXISTS idx_planner_tasks_user_date
    ON planner_tasks (user_id, scheduled_date, status);
CREATE INDEX IF NOT EXISTS idx_planner_tasks_subject
    ON planner_tasks (user_id, subject_id, scheduled_date);
CREATE INDEX IF NOT EXISTS idx_planner_events_user
    ON planner_events (user_id, created_at);
SQL
    );

    $db->exec(
        "INSERT OR IGNORE INTO exams (code, display_name, syllabus_version, active)
         VALUES ('WB_ANM_GNM', 'West Bengal ANM / GNM', '1', 1)"
    );

    $examId = (int)$db->query(
        "SELECT id FROM exams WHERE code = 'WB_ANM_GNM' LIMIT 1"
    )->fetchColumn();

    $subjectStatement = $db->prepare(
        'INSERT OR IGNORE INTO exam_subjects
            (exam_id, code, name_en, official_weight_percent, display_order, active)
         VALUES (?, ?, ?, ?, ?, 1)'
    );

    $subjects = [
        ['LS', 'Life Science', 43.48, 1],
        ['PS', 'Physical Science', 21.74, 2],
        ['EN', 'English', 13.04, 3],
        ['MA', 'Mathematics', 8.70, 4],
        ['GK', 'General Knowledge', 8.70, 5],
        ['LR', 'Logical Reasoning', 4.35, 6],
    ];

    foreach ($subjects as [$code, $name, $weight, $displayOrder]) {
        $subjectStatement->execute([$examId, $code, $name, $weight, $displayOrder]);
    }
};