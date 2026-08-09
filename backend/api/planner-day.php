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
nexa_apply_security_headers('GET, OPTIONS');
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    nexa_safe_error(405, 'method_not_allowed', 'Use GET for the daily planner.');
}
$db = get_db();
if (!$db) {
    nexa_safe_error(503, 'planner_unavailable', 'The study planner is temporarily unavailable.');
}
$user = nexa_require_authenticated_user($db);
$service = new PlannerService(new NexaPlannerRepository($db));
try {
    $date = trim((string)($_GET['date'] ?? ''));
    echo json_encode(
        $service->dayPayload((int)$user['id'], $date === '' ? null : $date),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (PlannerNotReadyException $error) {
    nexa_safe_error(409, $error->reason, $error->getMessage());
} catch (Throwable $error) {
    nexa_log_event('planner_day_read_failed', ['user_id' => (int)$user['id']]);
    nexa_safe_error(500, 'planner_day_failed', 'The daily plan could not be loaded.');
}