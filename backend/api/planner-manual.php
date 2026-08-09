<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../planner/adapters/NexaPlannerRepository.php';

use Nexa\Planner\Adapters\NexaPlannerRepository;

nexa_start_session();
nexa_apply_security_headers('POST, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    nexa_safe_error(405, 'method_not_allowed', 'POST is required for manual planning.');
}

$db = get_db();
if (!$db) {
    nexa_safe_error(503, 'planner_unavailable', 'The study planner is temporarily unavailable.');
}
$user = nexa_require_authenticated_user($db);
$repository = new NexaPlannerRepository($db);
$profile = $repository->getProfile((int)$user['id']);
if (!$profile || empty($profile['onboarding_completed_at']) || empty($profile['diagnostic_completed_at'])) {
    nexa_safe_error(409, 'planner_not_ready', 'Complete your planner setup and diagnostic before adding manual sessions.');
}

$body = nexa_read_json_body(131072, 'Invalid manual planner request.');
$scope = strtolower(trim((string)($body['scope'] ?? '')));
if (!in_array($scope, ['day', 'week'], true)) {
    nexa_safe_error(422, 'invalid_scope', 'Choose day or week planning.');
}

$entries = $body['entries'] ?? [];
$maximumEntries = $scope === 'day' ? 12 : 21;
if (!is_array($entries) || count($entries) < 1 || count($entries) > $maximumEntries) {
    nexa_safe_error(422, 'invalid_sessions', 'Add at least one session within the selected planning range.');
}

$timezone = new DateTimeZone('Asia/Kolkata');
$subjects = $repository->getSubjects((int)$profile['exam_id']);
$subjectsByCode = [];
foreach ($subjects as $subject) {
    $subjectsByCode[(string)$subject['code']] = $subject;
}
$topicsById = [];
foreach ($repository->getTopics((int)$profile['exam_id']) as $topic) {
    $topicsById[(int)$topic['id']] = $topic;
}
$taskTypes = [
    'learn' => 'Learn',
    'practice' => 'Practice',
    'pyq' => 'PYQ practice',
    'revision' => 'Revision',
    'error_review' => 'Error review',
    'mock_test' => 'Mock test',
    'recovery' => 'Catch-up',
];

$normalizedTasks = [];
$weekKey = null;
$dayKey = null;
foreach ($entries as $entry) {
    if (!is_array($entry)) {
        nexa_safe_error(422, 'invalid_session', 'Each manual session must be an object.');
    }
    $dateValue = trim((string)($entry['scheduled_date'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateValue)) {
        nexa_safe_error(422, 'invalid_date', 'Use a valid study date.');
    }
    $date = DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue, $timezone);
    if (!$date || $date->format('Y-m-d') !== $dateValue) {
        nexa_safe_error(422, 'invalid_date', 'Use a valid study date.');
    }
    $entryWeekKey = $date->format('o-W');
    if ($scope === 'day') {
        $dayKey ??= $dateValue;
        if ($dayKey !== $dateValue) {
            nexa_safe_error(422, 'invalid_day_range', 'Day planning sessions must use the same date.');
        }
    } else {
        $weekKey ??= $entryWeekKey;
        if ($weekKey !== $entryWeekKey) {
            nexa_safe_error(422, 'invalid_week_range', 'Week planning sessions must stay within one calendar week.');
        }
    }

    $subjectCode = strtoupper(trim((string)($entry['subject_code'] ?? '')));
    if (!isset($subjectsByCode[$subjectCode])) {
        nexa_safe_error(422, 'invalid_subject', 'Choose a subject from the selected exam.');
    }
    $subject = $subjectsByCode[$subjectCode];
    $topicId = null;
    $unitId = null;
    $topicTitle = '';
    if (array_key_exists('topic_id', $entry) && $entry['topic_id'] !== null && $entry['topic_id'] !== '') {
        $topicId = filter_var($entry['topic_id'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($topicId === false || !isset($topicsById[(int)$topicId])) {
            nexa_safe_error(422, 'invalid_topic', 'Choose a syllabus topic from the selected exam.');
        }
        $topic = $topicsById[(int)$topicId];
        if ((int)$topic['subject_id'] !== (int)$subject['id']) {
            nexa_safe_error(422, 'invalid_topic', 'The selected topic does not belong to that subject.');
        }
        $unitId = (int)$topic['unit_id'];
        $topicTitle = (string)$topic['title_en'];
    }

    $taskType = strtolower(trim((string)($entry['task_type'] ?? 'learn')));
    if (!isset($taskTypes[$taskType])) {
        nexa_safe_error(422, 'invalid_task_type', 'Choose a supported study session type.');
    }
    $minutes = filter_var($entry['estimated_minutes'] ?? null, FILTER_VALIDATE_INT, [
        'options' => ['min_range' => 30, 'max_range' => 1440],
    ]);
    if ($minutes === false) {
        nexa_safe_error(422, 'invalid_minutes', 'Manual sessions must be between 30 and 1440 minutes.');
    }
    $title = trim((string)($entry['title_en'] ?? ''));
    if (strlen($title) > 120 || preg_match('/[\x00-\x1F\x7F]/', $title)) {
        nexa_safe_error(422, 'invalid_title', 'Use a short study title without control characters.');
    }
    if ($title === '') {
        $title = $taskTypes[$taskType] . ': ' . ($topicTitle !== '' ? $topicTitle : (string)$subject['name_en']);
    }

    $normalizedTasks[] = [
        'scheduled_date' => $dateValue,
        'task_type' => $taskType,
        'subject_id' => (int)$subject['id'],
        'unit_id' => $unitId,
        'topic_id' => $topicId === null ? null : (int)$topicId,
        'title_en' => $title,
        'estimated_minutes' => (int)$minutes,
        'scope' => $scope,
    ];
}

if ($scope === 'week') {
    $weekStart = trim((string)($body['week_start'] ?? ''));
    if ($weekStart !== '') {
        $parsedWeekStart = DateTimeImmutable::createFromFormat('!Y-m-d', $weekStart, $timezone);
        if (!$parsedWeekStart || $parsedWeekStart->format('Y-m-d') !== $weekStart || $parsedWeekStart->format('o-W') !== $weekKey || $parsedWeekStart->format('N') !== '1') {
            nexa_safe_error(422, 'invalid_week_start', 'Choose the Monday for the selected planning week.');
        }
    }
}

try {
    $tasks = $repository->createManualTasks((int)$user['id'], (int)$profile['exam_id'], $normalizedTasks);
    echo json_encode([
        'success' => true,
        'scope' => $scope,
        'message' => $scope === 'day' ? 'Your manual day plan was saved.' : 'Your manual week plan was saved.',
        'tasks' => $tasks,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (RuntimeException $error) {
    nexa_safe_error(422, 'manual_plan_failed', $error->getMessage());
} catch (Throwable $error) {
    nexa_log_event('planner_manual_plan_failed', ['user_id' => (int)$user['id']]);
    nexa_safe_error(500, 'manual_plan_failed', 'The manual plan could not be saved.');
}
