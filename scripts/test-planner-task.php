<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/planner/adapters/NexaPlannerRepository.php';

use Nexa\Planner\Adapters\NexaPlannerRepository;

$assert = static function (bool $condition, string $message): void {
    if (!$condition) {
        throw new RuntimeException($message);
    }
};

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('PRAGMA foreign_keys = ON');
foreach (glob(__DIR__ . '/../backend/migrations/main/*.php') as $migrationPath) {
    $migration = require $migrationPath;
    $migration($db);
}
$db->prepare('INSERT INTO users (email, name) VALUES (?, ?)')->execute(['task-test@example.test', 'Task Test']);
$userId = (int)$db->lastInsertId();
$examId = (int)$db->query("SELECT id FROM exams WHERE code = 'WB_ANM_GNM' LIMIT 1")->fetchColumn();
$subjectId = (int)$db->query('SELECT id FROM exam_subjects WHERE exam_id = ' . $examId . ' ORDER BY display_order LIMIT 1')->fetchColumn();
$db->prepare('INSERT INTO planner_profiles (user_id, exam_id, onboarding_completed_at, diagnostic_completed_at) VALUES (?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)')->execute([$userId, $examId]);
$db->prepare("INSERT INTO planner_months (user_id, exam_id, calendar_year, calendar_month, generation_version, status, summary_json) VALUES (?, ?, 2026, 8, 'test', 'active', '{}')")->execute([$userId, $examId]);
$monthId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO planner_weeks (month_id, user_id, week_start, week_end, generation_version, status) VALUES (?, ?, '2026-08-03', '2026-08-09', 'test', 'active')")->execute([$monthId, $userId]);
$weekId = (int)$db->lastInsertId();
$insertTask = $db->prepare("INSERT INTO planner_tasks (user_id, month_id, week_id, scheduled_date, task_type, subject_id, title_en, estimated_minutes, display_order, status) VALUES (?, ?, ?, '2026-08-08', 'learn', ?, ?, 30, ?, 'planned')");
$insertTask->execute([$userId, $monthId, $weekId, $subjectId, 'Task one', 1]);
$taskOne = (int)$db->lastInsertId();
$insertTask->execute([$userId, $monthId, $weekId, $subjectId, 'Task two', 2]);
$taskTwo = (int)$db->lastInsertId();
$insertTask->execute([$userId, $monthId, $weekId, $subjectId, 'Task three', 3]);
$taskThree = (int)$db->lastInsertId();
$repository = new NexaPlannerRepository($db);
$started = $repository->updateTaskStatus($userId, $taskOne, 'in_progress', null);
$assert($started['status'] === 'in_progress', 'Task start should persist.');
$completed = $repository->updateTaskStatus($userId, $taskOne, 'completed', 'Done');
$assert($completed['status'] === 'completed', 'Task completion should persist.');
$assert($completed['completion_note'] === 'Done', 'Completion notes should persist.');
$terminalRejected = false;
try {
    $repository->updateTaskStatus($userId, $taskOne, 'skipped', null);
} catch (RuntimeException) {
    $terminalRejected = true;
}
$assert($terminalRejected, 'Terminal tasks must reject later state changes.');
$rescheduled = $repository->rescheduleTask($userId, $taskTwo, '2026-08-09');
$assert($rescheduled['status'] === 'planned', 'Rescheduled task should create a planned successor.');
$assert((int)$rescheduled['rescheduled_from_task_id'] === $taskTwo, 'Rescheduling lineage should be preserved.');
$ordered = $repository->reorderTasks($userId, '2026-08-08', [$taskThree]);
$assert((int)$ordered[0]['display_order'] === 1, 'Task ordering should persist.');
$eventCount = (int)$db->query('SELECT COUNT(*) FROM planner_events')->fetchColumn();
$assert($eventCount >= 4, 'Task mutations should create planner events.');
echo "Planner task runtime tests passed.\n";