<?php
/** Initial AI-cache schema. Cache data is intentionally separate from user data. */
return static function (PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS quizzes (
    id             INTEGER PRIMARY KEY AUTOINCREMENT,
    topic          TEXT NOT NULL,
    difficulty     TEXT NOT NULL,
    lang           TEXT NOT NULL DEFAULT 'Bengali',
    question       TEXT NOT NULL,
    opt_a          TEXT NOT NULL,
    opt_b          TEXT NOT NULL,
    opt_c          TEXT NOT NULL,
    opt_d          TEXT NOT NULL,
    correct_answer TEXT NOT NULL,
    explanation    TEXT NOT NULL,
    sub_topic      TEXT DEFAULT '',
    exam_type      TEXT NOT NULL DEFAULT '',
    cache_version  TEXT NOT NULL DEFAULT 'legacy',
    created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_quiz_history (
    id          INTEGER PRIMARY KEY AUTOINCREMENT,
    user_email  TEXT NOT NULL,
    quiz_id     INTEGER NOT NULL,
    user_answer TEXT NOT NULL DEFAULT '',
    is_correct  INTEGER NOT NULL DEFAULT 0,
    timestamp   DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS cache_responses (
    q_hash   TEXT PRIMARY KEY,
    question TEXT NOT NULL,
    answer   TEXT NOT NULL,
    ts       DATETIME DEFAULT CURRENT_TIMESTAMP
);
SQL
    );
};