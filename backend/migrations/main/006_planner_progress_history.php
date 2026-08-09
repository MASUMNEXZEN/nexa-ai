<?php
return static function (PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS planner_question_attempts (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    user_id INTEGER NOT NULL,
    exam_id INTEGER NOT NULL,
    question_id INTEGER NOT NULL,
    topic_id INTEGER,
    task_id INTEGER,
    attempt_id INTEGER,
    source_type TEXT NOT NULL CHECK (source_type IN ('diagnostic', 'planner_task', 'practice', 'mock')),
    selected_option_key TEXT,
    is_correct INTEGER NOT NULL CHECK (is_correct IN (0, 1)),
    answered_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (exam_id) REFERENCES exams(id) ON DELETE RESTRICT,
    FOREIGN KEY (question_id) REFERENCES questions(id) ON DELETE RESTRICT,
    FOREIGN KEY (topic_id) REFERENCES syllabus_topics(id) ON DELETE SET NULL,
    FOREIGN KEY (task_id) REFERENCES planner_tasks(id) ON DELETE SET NULL,
    FOREIGN KEY (attempt_id) REFERENCES diagnostic_attempts(id) ON DELETE SET NULL,
    UNIQUE (attempt_id, question_id, source_type)
);
CREATE INDEX IF NOT EXISTS idx_planner_attempts_user_topic ON planner_question_attempts (user_id, topic_id, answered_at DESC, id DESC);
CREATE INDEX IF NOT EXISTS idx_planner_attempts_user_exam ON planner_question_attempts (user_id, exam_id, answered_at DESC);
CREATE INDEX IF NOT EXISTS idx_planner_months_active_lookup ON planner_months (user_id, exam_id, calendar_year, calendar_month, status, generated_at DESC);
SQL
    );
};
