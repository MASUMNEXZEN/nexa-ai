<?php
/** Add lookup indexes for bounded cache reads and exclusion queries. */
return static function (PDO $db): void {
    $db->exec('CREATE INDEX IF NOT EXISTS idx_quizzes_lookup ON quizzes(topic, difficulty, lang, exam_type, cache_version)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_quiz_history_user ON user_quiz_history(user_email, quiz_id)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_cache_responses_ts ON cache_responses(ts)');
};