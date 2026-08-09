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
nexa_apply_security_headers('GET, POST, OPTIONS');
$db = get_db();
if (!$db) {
    nexa_safe_error(503, 'planner_unavailable', 'The study planner is temporarily unavailable.');
}
$user = nexa_require_authenticated_user($db);
$repository = new NexaPlannerRepository($db);
$profile = $repository->getProfile((int)$user['id']);
if (!$profile) {
    nexa_safe_error(409, 'profile_required', 'Complete your study planner profile first.');
}
if (empty($profile['onboarding_completed_at'])) {
    nexa_safe_error(409, 'onboarding_required', 'Complete planner onboarding before starting the diagnostic.');
}

$latest = $repository->getLatestDiagnosticAttempt((int)$user['id'], (int)$profile['exam_id']);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    $payload = [
        'status' => $latest['status'] ?? 'not_started',
        'attempt_id' => $latest ? (int)$latest['id'] : null,
        'question_count' => $latest ? (int)$latest['question_count'] : 0,
        'score' => $latest && $latest['score'] !== null ? (float)$latest['score'] : null,
        'diagnostic_completed' => !empty($profile['diagnostic_completed_at']),
    ];
    if ($latest && $latest['status'] === 'started') {
        $payload['questions'] = $repository->getDiagnosticAttemptQuestions((int)$user['id'], (int)$latest['id']);
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
if ($method !== 'POST') {
    nexa_safe_error(405, 'method_not_allowed', 'Use GET or POST for the diagnostic.');
}

$body = nexa_read_json_body(65536, 'Invalid diagnostic request.');
$action = strtolower(trim((string)($body['action'] ?? '')));
try {
    if ($action === 'start') {
        if ($latest && $latest['status'] === 'started') {
            $attemptId = (int)$latest['id'];
        } else {
            $questions = $repository->getDiagnosticQuestions((int)$profile['exam_id'], 12);
            if (count($questions) < 1) {
                nexa_safe_error(409, 'diagnostic_content_not_ready', 'The verified diagnostic question set is not ready yet.');
            }
            $attemptId = $repository->createDiagnosticAttempt(
                (int)$user['id'],
                (int)$profile['exam_id'],
                array_map(static fn(array $question): int => (int)$question['id'], $questions)
            );
        }
        echo json_encode([
            'status' => 'started',
            'attempt_id' => $attemptId,
            'questions' => $repository->getDiagnosticAttemptQuestions((int)$user['id'], $attemptId),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'answer') {
        $attemptId = filter_var($body['attempt_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $questionId = filter_var($body['question_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $optionKey = strtoupper(trim((string)($body['option_key'] ?? '')));
        if ($attemptId === false || $questionId === false || !preg_match('/^[A-D]$/', $optionKey)) {
            nexa_safe_error(422, 'invalid_answer', 'The diagnostic answer is invalid.');
        }
        $repository->saveDiagnosticAnswer((int)$user['id'], (int)$attemptId, (int)$questionId, $optionKey);
        echo json_encode([
            'success' => true,
            'answered' => true,
            'attempt_id' => (int)$attemptId,
            'question_id' => (int)$questionId,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'complete') {
        $attemptId = filter_var($body['attempt_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($attemptId === false) {
            nexa_safe_error(422, 'invalid_attempt', 'The diagnostic attempt is invalid.');
        }
        (new Nexa\Planner\Adapters\NexaPlannerProgressRepository($db))->recordDiagnostic((int)$user['id'], (int)$profile['exam_id'], (int)$attemptId);
        $result = $repository->completeDiagnostic((int)$user['id'], (int)$attemptId);
        echo json_encode([
            'success' => true,
            'status' => 'completed',
            'diagnostic_completed' => true,
            ...$result,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    nexa_safe_error(422, 'invalid_diagnostic_action', 'Use start, answer, or complete.');
} catch (RuntimeException $error) {
    nexa_safe_error(422, 'diagnostic_action_failed', $error->getMessage());
} catch (Throwable $error) {
    nexa_log_event('planner_diagnostic_failed', ['user_id' => (int)$user['id']]);
    nexa_safe_error(500, 'diagnostic_failed', 'The diagnostic could not be processed.');
}