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

$db->prepare('INSERT INTO users (email, name) VALUES (?, ?)')->execute(['manual-test@example.test', 'Manual Test']);
$userId = (int)$db->lastInsertId();
$examId = (int)$db->query("SELECT id FROM exams WHERE code = 'WB_ANM_GNM' LIMIT 1")->fetchColumn();
$subjectId = (int)$db->query('SELECT id FROM exam_subjects WHERE exam_id = ' . $examId . ' ORDER BY display_order LIMIT 1')->fetchColumn();
$db->prepare('INSERT INTO planner_profiles (user_id, exam_id, onboarding_completed_at, diagnostic_completed_at) VALUES (?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)')->execute([$userId, $examId]);
$db->prepare("INSERT INTO planner_months (user_id, exam_id, calendar_year, calendar_month, generation_version, status, summary_json) VALUES (?, ?, 2026, 8, 'test', 'active', '{}')")->execute([$userId, $examId]);
$monthId = (int)$db->lastInsertId();
$db->prepare("INSERT INTO planner_weeks (month_id, user_id, week_start, week_end, generation_version, status) VALUES (?, ?, '2026-08-03', '2026-08-09', 'test', 'active')")->execute([$monthId, $userId]);

$repository = new NexaPlannerRepository($db);
$created = $repository->createManualTasks($userId, $examId, [[
    'scheduled_date' => '2026-08-08',
    'task_type' => 'revision',
    'subject_id' => $subjectId,
    'unit_id' => null,
    'topic_id' => null,
    'title_en' => 'Manual cell revision',
    'estimated_minutes' => 45,
    'scope' => 'day',
]]);
$assert(count($created) === 1, 'A manual day session should be created.');
$assert($created[0]['origin'] === 'manual', 'Manual sessions must be marked as manual.');
$assert($created[0]['title_en'] === 'Manual cell revision', 'Manual session titles should persist.');

$generation = [
    'generation_version' => 'test-refresh',
    'summary' => ['active_days' => 1, 'total_minutes' => 30],
    'tasks' => [[
        'scheduled_date' => '2026-08-08',
        'task_type' => 'learn',
        'subject_id' => $subjectId,
        'unit_id' => null,
        'topic_id' => null,
        'question_id' => null,
        'title_en' => 'Generated session',
        'estimated_minutes' => 30,
        'display_order' => 1,
    ]],
];
$repository->persistMonth($userId, $examId, 2026, 8, $generation, hash('sha256', 'manual-refresh'));
$refreshed = $repository->findDayTasks($userId, '2026-08-08');
$manualTasks = array_values(array_filter($refreshed, static fn(array $task): bool => $task['origin'] === 'manual'));
$assert(count($manualTasks) === 1, 'Manual sessions must survive an intelligent month refresh.');
$assert($manualTasks[0]['title_en'] === 'Manual cell revision', 'Refreshed manual session content should remain unchanged.');
echo "Planner manual planning tests passed.\n";
