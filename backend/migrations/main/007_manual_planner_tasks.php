<?php
/**
 * Allow students to add durable, validated tasks to a day or week.
 */
return static function (PDO $db): void {
    $columns = $db->query('PRAGMA table_info(planner_tasks)')->fetchAll();
    $hasOrigin = false;
    foreach ($columns as $column) {
        if (($column['name'] ?? '') === 'origin') {
            $hasOrigin = true;
            break;
        }
    }

    if (!$hasOrigin) {
        $db->exec(
            "ALTER TABLE planner_tasks
             ADD COLUMN origin TEXT NOT NULL DEFAULT 'generated'
             CHECK (origin IN ('generated', 'manual'))"
        );
    }

    $db->exec(
        'CREATE INDEX IF NOT EXISTS idx_planner_tasks_origin
         ON planner_tasks (user_id, origin, scheduled_date)'
    );
};
