<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../planner/adapters/NexaPlannerProgressRepository.php';

use Nexa\Planner\Adapters\NexaPlannerProgressRepository;

nexa_start_session();
nexa_apply_security_headers('GET, POST, OPTIONS');
$db = get_db();
if (!$db) {
    nexa_safe_error(503, 'planner_unavailable', 'The study planner is temporarily unavailable.');
}
$user = nexa_require_authenticated_user($db);
$userId = (int)$user['id'];
$profileStatement = $db->prepare(
    'SELECT exam_id, exam_code, exam_name
     FROM planner_profiles
     JOIN exams ON exams.id = planner_profiles.exam_id
     WHERE planner_profiles.user_id = ? AND planner_profiles.active = 1 LIMIT 1'
);
$profileStatement->execute([$userId]);
$profile = $profileStatement->fetch();
if (!$profile) {
    nexa_safe_error(409, 'profile_required', 'Complete your study planner profile first.');
}

$progress = new NexaPlannerProgressRepository($db);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    echo json_encode([
        'exam' => [
            'code' => (string)$profile['exam_code'],
            'name' => (string)$profile['exam_name'],
        ],
        'items' => $progress->getProgress($userId, (int)$profile['exam_id']),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($method !== 'POST') {
    nexa_safe_error(405, 'method_not_allowed', 'Use GET or POST for planner progress.');
}

$body = nexa_read_json_body(16384, 'Invalid planner progress request.');
$action = strtolower(trim((string)($body['action'] ?? '')));
if ($action !== 'answer') {
    nexa_safe_error(422, 'invalid_progress_action', 'Use the answer progress action.');
}
$questionId = filter_var($body['question_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
$taskId = null;
if (array_key_exists('task_id', $body) && $body['task_id'] !== null && $body['task_id'] !== '') {
    $taskId = filter_var($body['task_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if ($taskId === false) {
        nexa_safe_error(422, 'invalid_task', 'Choose a valid planner task.');
    }
}
$optionKey = strtoupper(trim((string)($body['option_key'] ?? '')));
$sourceType = strtolower(trim((string)($body['source_type'] ?? 'practice')));
if ($questionId === false || !preg_match('/^[A-D]$/', $optionKey)) {
    nexa_safe_error(422, 'invalid_answer', 'The planner answer is invalid.');
}
try {
    echo json_encode([
        'success' => true,
        ...$progress->recordAnswer($userId, (int)$questionId, $optionKey, $taskId === null ? null : (int)$taskId, $sourceType),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (RuntimeException $error) {
    nexa_safe_error(422, 'progress_action_failed', $error->getMessage());
} catch (Throwable $error) {
    nexa_log_event('planner_progress_write_failed', ['user_id' => $userId]);
    nexa_safe_error(500, 'progress_action_failed', 'Planner progress could not be saved.');
}
