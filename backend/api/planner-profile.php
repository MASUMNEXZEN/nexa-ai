<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../planner/domain/PlannerRules.php';
require_once __DIR__ . '/../planner/adapters/NexaPlannerRepository.php';
require_once __DIR__ . '/../planner/application/PlannerService.php';

use Nexa\Planner\Adapters\NexaPlannerRepository;
use Nexa\Planner\Application\PlannerService;
use Nexa\Planner\Domain\PlannerRules;

nexa_start_session();
nexa_apply_security_headers('GET, POST, OPTIONS');
$db = get_db();
if (!$db) {
    nexa_safe_error(503, 'planner_unavailable', 'The study planner is temporarily unavailable.');
}
$user = nexa_require_authenticated_user($db);
$repository = new NexaPlannerRepository($db);
$service = new PlannerService($repository);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    echo json_encode($service->profilePayload((int)$user['id']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    nexa_safe_error(405, 'method_not_allowed', 'Use GET or POST for the planner profile.');
}

$body = nexa_read_json_body(131072, 'Invalid planner profile request.');
$examCode = strtoupper(trim((string)($body['exam_code'] ?? '')));
if (!preg_match('/^[A-Z0-9_]{2,40}$/', $examCode)) {
    nexa_safe_error(422, 'invalid_exam', 'Choose a supported exam.');
}
$exam = $repository->findActiveExam($examCode);
if (!$exam) {
    nexa_safe_error(422, 'invalid_exam', 'Choose a supported exam.');
}

try {
    $normalized = PlannerRules::normalizeProfile([
        'daily_minutes' => array_key_exists('daily_minutes', $body) ? $body['daily_minutes'] : null,
        'preferred_session_minutes' => $body['preferred_session_minutes'] ?? null,
    ]);
} catch (InvalidArgumentException $error) {
    nexa_safe_error(422, 'invalid_capacity', $error->getMessage());
}

$dailyMinutes = null;
if (array_key_exists('daily_minutes', $body) && $body['daily_minutes'] !== null && $body['daily_minutes'] !== '') {
    $dailyMinutes = $normalized['daily_minutes'];
}
$timeBlock = trim((string)($body['preferred_time_block'] ?? ''));
if ($timeBlock !== '' && (strlen($timeBlock) > 40 || !preg_match('/^[A-Za-z0-9 _:+\-–—]{1,40}$/u', $timeBlock))) {
    nexa_safe_error(422, 'invalid_time_block', 'Use a simple preferred study time block.');
}
$currentLevel = strtolower(trim((string)($body['current_level'] ?? 'beginner')));
if (!in_array($currentLevel, ['beginner', 'developing', 'advanced'], true)) {
    nexa_safe_error(422, 'invalid_level', 'Choose beginner, developing, or advanced.');
}

$availability = null;
if (array_key_exists('availability', $body)) {
    if (!is_array($body['availability']) || count($body['availability']) > 7) {
        nexa_safe_error(422, 'invalid_availability', 'Availability must contain up to seven weekdays.');
    }
    $availability = [];
    $seenWeekdays = [];
    foreach ($body['availability'] as $row) {
        if (!is_array($row)) {
            nexa_safe_error(422, 'invalid_availability', 'Each availability entry must be an object.');
        }
        $weekday = filter_var($row['weekday'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 6]]);
        if ($weekday === false || isset($seenWeekdays[(int)$weekday])) {
            nexa_safe_error(422, 'invalid_availability', 'Each weekday must appear once.');
        }
        $seenWeekdays[(int)$weekday] = true;
        $available = ($row['available'] ?? false) === true || ($row['available'] ?? null) === 1;
        $minutes = array_key_exists('minutes', $row) && $row['minutes'] !== ''
            ? filter_var($row['minutes'], FILTER_VALIDATE_INT, ['options' => ['min_range' => 0, 'max_range' => 1440]])
            : ($dailyMinutes ?? PlannerRules::DEFAULT_DAILY_MINUTES);
        if ($minutes === false) {
            nexa_safe_error(422, 'invalid_availability', 'Daily availability must be between 0 and 1440 minutes.');
        }
        $availability[] = [
            'weekday' => (int)$weekday,
            'available' => $available ? 1 : 0,
            'minutes' => $available ? (int)$minutes : 0,
        ];
    }
}

$completeOnboarding = ($body['complete_onboarding'] ?? false) === true;
try {
    $repository->saveProfile(
        (int)$user['id'],
        (int)$exam['id'],
        $dailyMinutes,
        $normalized['preferred_session_minutes'],
        $timeBlock === '' ? null : $timeBlock,
        $currentLevel,
        $completeOnboarding,
        $availability
    );
    echo json_encode([
        'success' => true,
        'message' => $completeOnboarding ? 'Planner profile saved.' : 'Planner preferences saved.',
        ...$service->profilePayload((int)$user['id']),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    nexa_log_event('planner_profile_save_failed', ['user_id' => (int)$user['id']]);
    nexa_safe_error(500, 'planner_profile_failed', 'The planner profile could not be saved.');
}