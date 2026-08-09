<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../planner/adapters/NexaPlannerRepository.php';

use Nexa\Planner\Adapters\NexaPlannerRepository;

nexa_start_session();
nexa_apply_security_headers('GET, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    nexa_safe_error(405, 'method_not_allowed', 'GET is required for planner questions.');
}
$db = get_db();
if (!$db) {
    nexa_safe_error(503, 'planner_unavailable', 'The study planner is temporarily unavailable.');
}
$user = nexa_require_authenticated_user($db);
$repository = new NexaPlannerRepository($db);
$questionId = filter_var($_GET['question_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$taskId = filter_var($_GET['task_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
if ($questionId === false && $taskId === false) {
    nexa_safe_error(422, 'invalid_question', 'Choose a planner question or task.');
}

try {
    if ($questionId === false && $taskId !== false) {
        $task = $repository->getTask((int)$user['id'], (int)$taskId);
        if (!$task || $task['question_id'] === null) {
            nexa_safe_error(404, 'question_not_found', 'This planner task has no available question.');
        }
        $questionId = (int)$task['question_id'];
    }
    $question = $repository->getPlannerQuestion((int)$user['id'], (int)$questionId);
    if (!$question || count($question['options']) !== 4) {
        nexa_safe_error(404, 'question_not_found', 'This planner question is not available.');
    }
    echo json_encode([
        'question' => $question,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    nexa_log_event('planner_question_read_failed', ['user_id' => (int)$user['id']]);
    nexa_safe_error(500, 'planner_question_failed', 'The planner question could not be loaded.');
}
