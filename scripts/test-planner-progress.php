<?php
declare(strict_types=1);

require_once __DIR__ . '/../backend/planner/adapters/NexaPlannerRepository.php';
require_once __DIR__ . '/../backend/planner/adapters/NexaPlannerProgressRepository.php';
require_once __DIR__ . '/../backend/planner/domain/PlannerEngine.php';
require_once __DIR__ . '/../backend/planner/domain/PlannerRules.php';

use Nexa\Planner\Adapters\NexaPlannerProgressRepository;
use Nexa\Planner\Adapters\NexaPlannerRepository;
use Nexa\Planner\Domain\PlannerEngine;

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

$db->prepare('INSERT INTO users (email, name) VALUES (?, ?)')->execute(['progress-test@example.test', 'Progress Test']);
$userId = (int)$db->lastInsertId();
$examId = (int)$db->query("SELECT id FROM exams WHERE code = 'WB_ANM_GNM' LIMIT 1")->fetchColumn();
$subjects = $db->query('SELECT id FROM exam_subjects WHERE exam_id = ' . $examId . ' ORDER BY display_order ASC LIMIT 3')->fetchAll(PDO::FETCH_COLUMN);
$assert(count($subjects) === 3, 'The test exam must expose at least three subjects.');

$topicIds = [];
foreach ($subjects as $index => $subjectId) {
    $unit = $db->prepare(
        'INSERT INTO syllabus_units (exam_id, subject_id, code, title_en, estimated_minutes)
         VALUES (?, ?, ?, ?, 30)'
    );
    $unit->execute([$examId, (int)$subjectId, 'TEST-UNIT-' . $index, 'Test unit ' . $index]);
    $unitId = (int)$db->lastInsertId();
    $topic = $db->prepare(
        'INSERT INTO syllabus_topics (unit_id, code, title_en, estimated_minutes)
         VALUES (?, ?, ?, 30)'
    );
    $topic->execute([$unitId, 'TEST-TOPIC-' . $index, 'Test topic ' . $index]);
    $topicIds[] = (int)$db->lastInsertId();
}

$questionInsert = $db->prepare(
    'INSERT INTO questions
        (exam_id, subject_id, unit_id, topic_id, question_year, category,
         prompt_en, correct_option_key, authoritative, publication_state, trust_level)
     VALUES (?, ?, ?, ?, ?, \'CATEGORY_I\', ?, \'A\', 1, \'published\', \'owner_verified\')'
);
$optionInsert = $db->prepare(
    'INSERT INTO question_options (question_id, option_key, option_text_en, display_order)
     VALUES (?, ?, ?, ?)'
);
$questionIds = [];
foreach ([2024, 2025] as $year) {
    $questionInsert->execute([
        $examId,
        (int)$subjects[0],
        null,
        $topicIds[0],
        $year,
        'Verified test question ' . $year,
    ]);
    $questionId = (int)$db->lastInsertId();
    $questionIds[] = $questionId;
    foreach (['A' => 'Correct answer', 'B' => 'Distractor one', 'C' => 'Distractor two', 'D' => 'Distractor three'] as $key => $label) {
        $optionInsert->execute([$questionId, $key, $label, ord($key) - 64]);
    }
}

$db->prepare(
    'INSERT INTO planner_profiles
        (user_id, exam_id, onboarding_completed_at, diagnostic_completed_at)
     VALUES (?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)'
)->execute([$userId, $examId]);

$progress = new NexaPlannerProgressRepository($db);
$attemptInsert = $db->prepare(
    'INSERT INTO diagnostic_attempts (user_id, exam_id, question_count, status)
     VALUES (?, ?, 2, \'started\')'
);
$attemptInsert->execute([$userId, $examId]);
$attemptId = (int)$db->lastInsertId();
$answerInsert = $db->prepare(
    'INSERT INTO diagnostic_answers (attempt_id, question_id, selected_option_key, is_correct)
     VALUES (?, ?, ?, ?)'
);
$answerInsert->execute([$attemptId, $questionIds[0], 'A', 1]);
$answerInsert->execute([$attemptId, $questionIds[1], 'B', 0]);
$progress->recordDiagnostic($userId, $examId, $attemptId);
$topicProgress = $db->query(
    'SELECT attempts, correct_attempts, recent_accuracy, mastery_status, next_revision_at
     FROM topic_progress WHERE user_id = ' . $userId . ' AND topic_id = ' . $topicIds[0]
)->fetch();
$assert((int)$topicProgress['attempts'] === 2, 'Diagnostic attempts must feed topic progress.');
$assert((int)$topicProgress['correct_attempts'] === 1, 'Diagnostic correctness must feed topic progress.');
$assert((float)$topicProgress['recent_accuracy'] === 0.5, 'Recent diagnostic accuracy must be calculated.');
$assert($topicProgress['next_revision_at'] !== null, 'Diagnostic progress must schedule a revision.');

for ($index = 0; $index < 8; $index++) {
    $progress->recordAnswer($userId, $questionIds[0], 'A', null, 'practice');
}
$topicProgress = $db->query(
    'SELECT attempts, correct_attempts, recent_accuracy, mastery_status
     FROM topic_progress WHERE user_id = ' . $userId . ' AND topic_id = ' . $topicIds[0]
)->fetch();
$assert((int)$topicProgress['attempts'] === 10, 'Answer submissions must accumulate attempts.');
$assert((int)$topicProgress['correct_attempts'] === 9, 'Answer submissions must accumulate correct attempts.');
$assert((float)$topicProgress['recent_accuracy'] === 0.9, 'Recent answer accuracy must be recalculated.');
$assert($topicProgress['mastery_status'] === 'mastered', 'Ten high-confidence answers must mark a topic mastered.');

$monthInsert = $db->prepare(
    'INSERT INTO planner_months
        (user_id, exam_id, calendar_year, calendar_month, generation_version, status, summary_json)
     VALUES (?, ?, 2026, 8, ?, \'active\', \'{}\')'
);
$weekInsert = $db->prepare(
    'INSERT INTO planner_weeks (month_id, user_id, week_start, week_end, generation_version, status)
     VALUES (?, ?, \'2026-08-03\', \'2026-08-09\', ?, \'active\')'
);
$taskInsert = $db->prepare(
    'INSERT INTO planner_tasks
        (user_id, month_id, week_id, scheduled_date, task_type, subject_id,
         unit_id, topic_id, title_en, estimated_minutes, display_order, status)
     VALUES (?, ?, ?, \'2026-08-08\', \'learn\', ?, ?, ?, ?, 30, 1, \'planned\')'
);

$repository = new NexaPlannerRepository($db);
$generation = [
    'generation_version' => 'planner-test',
    'summary' => ['active_days' => 1, 'total_tasks' => 1, 'total_minutes' => 30],
    'tasks' => [[
        'scheduled_date' => '2026-08-08',
        'task_type' => 'learn',
        'subject_id' => (int)$subjects[0],
        'unit_id' => null,
        'topic_id' => $topicIds[0],
        'question_id' => $questionIds[0],
        'title_en' => 'Learn: test topic',
        'estimated_minutes' => 30,
        'display_order' => 1,
    ]],
];
$repository->persistMonth($userId, $examId, 2026, 8, $generation, hash('sha256', 'first'));
$month = $repository->findActiveMonth($userId, $examId, 2026, 8);
$assert($month !== null, 'A generated month must be queryable.');
$tasks = $repository->findDayTasks($userId, '2026-08-08');
$assert(count($tasks) === 1 && (int)$tasks[0]['question_id'] === $questionIds[0], 'Persisted planner tasks must retain question linkage.');
$plannerQuestion = $repository->getPlannerQuestion($userId, $questionIds[0]);
$assert($plannerQuestion !== null && count($plannerQuestion['options']) === 4, 'Planner question reads must return four safe options.');
$assert(!array_key_exists('explanation_en', $plannerQuestion), 'Planner question reads must not reveal the explanation before answering.');
$repository->updateTaskStatus($userId, (int)$tasks[0]['id'], 'completed', 'Finished this review.');

$repository->persistMonth($userId, $examId, 2026, 8, $generation, hash('sha256', 'second'));
$regeneratedTasks = $repository->findDayTasks($userId, '2026-08-08');
$assert(count($regeneratedTasks) === 1, 'Regeneration must not duplicate the semantic task.');
$assert($regeneratedTasks[0]['status'] === 'completed', 'Regeneration must preserve completed task state.');
$assert($regeneratedTasks[0]['completion_note'] === 'Finished this review.', 'Regeneration must preserve completion notes.');

$repository->persistMonth($userId, $examId, 2026, 8, $generation, hash('sha256', 'second'));
$repeatedTasks = $repository->findDayTasks($userId, '2026-08-08');
$assert(count($repeatedTasks) === 1 && $repeatedTasks[0]['status'] === 'completed', 'Repeated regeneration with the same snapshot must remain safe.');

$subjectsForEngine = [];
foreach ($subjects as $index => $subjectId) {
    $subject = $db->query('SELECT id, code, name_en, official_weight_percent, display_order FROM exam_subjects WHERE id = ' . (int)$subjectId)->fetch();
    $subjectsForEngine[] = $subject;
}
$topicsForEngine = [];
foreach ($topicIds as $index => $topicId) {
    $topic = $db->query(
        'SELECT t.id, t.unit_id, u.subject_id, t.code, t.title_en, t.scope_tag, t.estimated_minutes
         FROM syllabus_topics t JOIN syllabus_units u ON u.id = t.unit_id WHERE t.id = ' . $topicId
    )->fetch();
    $topicsForEngine[] = $topic;
}
$engineResult = PlannerEngine::generateMonth(
    new DateTimeImmutable('2026-08-10'),
    new DateTimeImmutable('2026-08-10'),
    ['daily_minutes' => 30, 'preferred_session_minutes' => 30],
    [['weekday' => 1, 'available' => 1, 'minutes' => 30]],
    $subjectsForEngine,
    $topicsForEngine,
    [],
    [],
    $repository->getPlannerQuestions($examId)
);
$assert(count(array_filter($engineResult['tasks'], static fn(array $task): bool => $task['question_id'] !== null)) > 0, 'Planner generation must attach verified questions when available.');

echo "Planner progress runtime tests passed.\n";
