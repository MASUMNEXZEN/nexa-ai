<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../planner/adapters/NexaPlannerRepository.php';
require_once __DIR__ . '/../planner/application/PlannerService.php';

use Nexa\Planner\Adapters\NexaPlannerRepository;
use Nexa\Planner\Application\PlannerNotReadyException;
use Nexa\Planner\Application\PlannerService;

nexa_start_session();
nexa_apply_security_headers('GET, POST, OPTIONS');
$db = get_db();
if (!$db) {
    nexa_safe_error(503, 'planner_unavailable', 'The study planner is temporarily unavailable.');
}
$user = nexa_require_authenticated_user($db);
$repository = new NexaPlannerRepository($db);
$service = new PlannerService($repository);
$profile = $repository->getProfile((int)$user['id']);
if (!$profile) {
    nexa_safe_error(409, 'profile_required', 'Complete your study planner profile first.');
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    try {
        $date = trim((string)($_GET['date'] ?? ''));
        echo json_encode($service->weekPayload((int)$user['id'], $date === '' ? null : $date), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    } catch (PlannerNotReadyException $error) {
        nexa_safe_error(409, $error->reason, $error->getMessage());
    }
    exit;
}

if ($method !== 'POST') {
    nexa_safe_error(405, 'method_not_allowed', 'Use GET or POST for weekly planner preferences.');
}

$body = nexa_read_json_body(65536, 'Invalid weekly preference request.');
$date = trim((string)($body['date'] ?? ''));
if ($date === '') {
    $date = (new DateTimeImmutable('today', new DateTimeZone('Asia/Kolkata')))->format('Y-m-d');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    nexa_safe_error(422, 'invalid_date', 'Use a valid calendar date.');
}
$target = DateTimeImmutable::createFromFormat('!Y-m-d', $date, new DateTimeZone('Asia/Kolkata'));
if (!$target || $target->format('Y-m-d') !== $date) {
    nexa_safe_error(422, 'invalid_date', 'Use a valid calendar date.');
}

$codes = $body['subject_codes'] ?? [];
if (!is_array($codes) || count($codes) > 6) {
    nexa_safe_error(422, 'invalid_subjects', 'Choose up to six weekly subjects.');
}
$normalizedCodes = [];
foreach ($codes as $code) {
    $normalizedCode = strtoupper(trim((string)$code));
    if (!preg_match('/^[A-Z0-9_]{1,20}$/', $normalizedCode) || in_array($normalizedCode, $normalizedCodes, true)) {
        nexa_safe_error(422, 'invalid_subjects', 'Weekly subjects must be unique supported subject codes.');
    }
    $normalizedCodes[] = $normalizedCode;
}

$subjects = $repository->getSubjects((int)$profile['exam_id']);
$subjectIdsByCode = [];
foreach ($subjects as $subject) {
    $subjectIdsByCode[(string)$subject['code']] = (int)$subject['id'];
}
$subjectIds = [];
foreach ($normalizedCodes as $code) {
    if (!isset($subjectIdsByCode[$code])) {
        nexa_safe_error(422, 'invalid_subjects', 'One or more selected subjects are not available for this exam.');
    }
    $subjectIds[] = $subjectIdsByCode[$code];
}

try {
    $weekStart = $target->modify('monday this week');
    $repository->replaceWeeklyPreferences(
        (int)$user['id'],
        (int)$weekStart->format('o'),
        (int)$weekStart->format('W'),
        $subjectIds
    );
    echo json_encode([
        'success' => true,
        'message' => 'Weekly subject focus saved.',
        'week' => [
            'week_start' => $weekStart->format('Y-m-d'),
            'week_end' => $weekStart->modify('+6 days')->format('Y-m-d'),
            'calendar_year' => (int)$weekStart->format('o'),
            'calendar_week' => (int)$weekStart->format('W'),
        ],
        'preferences' => $repository->getPreferenceDetails(
            (int)$user['id'],
            (int)$weekStart->format('o'),
            (int)$weekStart->format('W')
        ),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $error) {
    nexa_log_event('planner_week_preference_save_failed', ['user_id' => (int)$user['id']]);
    nexa_safe_error(500, 'planner_week_failed', 'Weekly subject focus could not be saved.');
}