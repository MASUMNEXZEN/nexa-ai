<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../planner/adapters/NexaPlannerRepository.php';
require_once __DIR__ . '/../planner/adapters/NexaPlannerProgressRepository.php';

use Nexa\Planner\Adapters\NexaPlannerRepository;
use Nexa\Planner\Adapters\NexaPlannerProgressRepository;

nexa_start_session();
nexa_apply_security_headers('POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    nexa_safe_error(405, 'method_not_allowed', 'POST is required for task updates.');
}
$db = get_db();
if (!$db) {
    nexa_safe_error(503, 'planner_unavailable', 'The study planner is temporarily unavailable.');
}
$user = nexa_require_authenticated_user($db);
$body = nexa_read_json_body(65536, 'Invalid planner task request.');
$action = strtolower(trim((string)($body['action'] ?? '')));
$repository = new NexaPlannerRepository($db);

try {
    if (in_array($action, ['start', 'complete', 'skip'], true)) {
        $taskId = filter_var($body['task_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($taskId === false) {
            nexa_safe_error(422, 'invalid_task', 'Choose a valid study task.');
        }
        $note = null;
        if (array_key_exists('completion_note', $body)) {
            if (!is_string($body['completion_note']) || strlen($body['completion_note']) > 500) {
                nexa_safe_error(422, 'invalid_completion_note', 'The task note must be 500 characters or fewer.');
            }
            $note = trim($body['completion_note']);
        }
        $status = ['start' => 'in_progress', 'complete' => 'completed', 'skip' => 'skipped'][$action];
        $task = $repository->updateTaskStatus((int)$user['id'], (int)$taskId, $status, $note);
        (new Nexa\Planner\Adapters\NexaPlannerProgressRepository($db))->recordTaskOutcome((int)$user['id'], $task, $status);
        echo json_encode([
            'success' => true,
            'action' => $action,
            'task' => $task,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'reschedule') {
        $taskId = filter_var($body['task_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $date = trim((string)($body['scheduled_date'] ?? ''));
        if ($taskId === false || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            nexa_safe_error(422, 'invalid_reschedule', 'Choose a valid target date.');
        }
        $target = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Kolkata'));
        if (!$target || $target->format('Y-m-d') !== $date) {
            nexa_safe_error(422, 'invalid_reschedule', 'Choose a valid target date.');
        }
        $task = $repository->rescheduleTask((int)$user['id'], (int)$taskId, $date);
        echo json_encode([
            'success' => true,
            'action' => 'reschedule',
            'task' => $task,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'reorder') {
        $date = trim((string)($body['scheduled_date'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            nexa_safe_error(422, 'invalid_date', 'Choose a valid study date.');
        }
        $target = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Kolkata'));
        if (!$target || $target->format('Y-m-d') !== $date) {
            nexa_safe_error(422, 'invalid_date', 'Choose a valid study date.');
        }
        $taskIds = $body['task_ids'] ?? null;
        if (!is_array($taskIds) || count($taskIds) < 1 || count($taskIds) > 30) {
            nexa_safe_error(422, 'invalid_order', 'Provide between one and thirty task IDs.');
        }
        $normalizedIds = [];
        foreach ($taskIds as $taskId) {
            $normalizedId = filter_var($taskId, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($normalizedId === false) {
                nexa_safe_error(422, 'invalid_order', 'Task IDs must be positive integers.');
            }
            $normalizedIds[] = (int)$normalizedId;
        }
        $tasks = $repository->reorderTasks((int)$user['id'], $date, $normalizedIds);
        echo json_encode([
            'success' => true,
            'action' => 'reorder',
            'date' => $date,
            'tasks' => $tasks,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    nexa_safe_error(422, 'invalid_task_action', 'Use start, complete, skip, reschedule, or reorder.');
} catch (RuntimeException $error) {
    nexa_safe_error(422, 'task_action_failed', $error->getMessage());
} catch (Throwable $error) {
    nexa_log_event('planner_task_action_failed', ['user_id' => (int)$user['id']]);
    nexa_safe_error(500, 'task_action_failed', 'The study task could not be updated.');
}