<?php
/** Complete cache columns for databases created by earlier releases. */
return static function (PDO $db): void {
    $hasColumn = static function (string $table, string $column) use ($db): bool {
        $quotedTable = str_replace('"', '""', $table);
        $stmt = $db->query('PRAGMA table_info("' . $quotedTable . '")');
        foreach ($stmt as $row) {
            if ((string)$row['name'] === $column) {
                return true;
            }
        }
        return false;
    };

    $columns = [
        ['quizzes', 'lang', "TEXT NOT NULL DEFAULT 'Bengali'"],
        ['quizzes', 'sub_topic', "TEXT DEFAULT ''"],
        ['quizzes', 'exam_type', "TEXT NOT NULL DEFAULT ''"],
        ['quizzes', 'cache_version', "TEXT NOT NULL DEFAULT 'legacy'"],
        ['user_quiz_history', 'user_answer', "TEXT NOT NULL DEFAULT ''"],
        ['user_quiz_history', 'is_correct', 'INTEGER NOT NULL DEFAULT 0'],
        ['cache_responses', 'ts', 'DATETIME'],
    ];
    foreach ($columns as [$table, $column, $definition]) {
        if (!$hasColumn($table, $column)) {
            $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $column . ' ' . $definition);
        }
    }
};