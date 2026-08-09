<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/../planner/domain/PlannerRules.php';
require_once __DIR__ . '/../planner/domain/PlannerEngine.php';
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
$service = new PlannerService(new NexaPlannerRepository($db));
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

try {
    if ($method === 'GET') {
        $year = isset($_GET['year']) ? filter_var($_GET['year'], FILTER_VALIDATE_INT) : null;
        $month = isset($_GET['month']) ? filter_var($_GET['month'], FILTER_VALIDATE_INT) : null;
        if ((isset($_GET['year']) && $year === false) || (isset($_GET['month']) && $month === false)) {
            nexa_safe_error(422, 'invalid_month', 'Use a valid calendar month.');
        }
        echo json_encode(
            $service->generateCurrentMonth(
                (int)$user['id'],
                false,
                $year === false ? null : $year,
                $month === false ? null : $month
            ),
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        exit;
    }
    if ($method !== 'POST') {
        nexa_safe_error(405, 'method_not_allowed', 'Use GET or POST for the monthly planner.');
    }
    $body = nexa_read_json_body(16384, 'Invalid monthly planner request.');
    if (($body['regenerate'] ?? false) !== true) {
        nexa_safe_error(422, 'regeneration_confirmation_required', 'Set regenerate to true to refresh the plan.');
    }
    $year = array_key_exists('year', $body) ? filter_var($body['year'], FILTER_VALIDATE_INT) : null;
    $month = array_key_exists('month', $body) ? filter_var($body['month'], FILTER_VALIDATE_INT) : null;
    if (($year !== null && $year === false) || ($month !== null && $month === false)) {
        nexa_safe_error(422, 'invalid_month', 'Use a valid calendar month.');
    }
    echo json_encode(
        $service->generateCurrentMonth(
            (int)$user['id'],
            true,
            $year === false ? null : $year,
            $month === false ? null : $month
        ),
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );
} catch (PlannerNotReadyException $error) {
    nexa_safe_error(409, $error->reason, $error->getMessage());
} catch (InvalidArgumentException $error) {
    nexa_safe_error(422, 'planner_generation_invalid', $error->getMessage());
} catch (Throwable $error) {
    nexa_log_event('planner_month_generation_failed', ['user_id' => (int)$user['id']]);
    nexa_safe_error(500, 'planner_generation_failed', 'The monthly plan could not be generated.');
}