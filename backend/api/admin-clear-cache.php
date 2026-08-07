<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';

nexa_apply_security_headers('POST, OPTIONS');
nexa_require_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true) ?: [];
$action = $body['action'] ?? 'purge_all';
$cacheDb = get_cache_db();
if (!$cacheDb) {
    http_response_code(500);
    echo json_encode(['error' => 'Cache database unavailable.']);
    exit;
}

try {
    if ($action === 'purge_all') {
        $cacheDb->exec('DELETE FROM cache_responses');
        $deleted = (int)$cacheDb->query('SELECT changes()')->fetchColumn();
        echo json_encode(['success' => true, 'action' => $action, 'deleted' => $deleted]);
    } elseif ($action === 'purge_stale') {
        $cacheDb->exec("DELETE FROM cache_responses WHERE ts < datetime('now', '-14 days')");
        $deleted = (int)$cacheDb->query('SELECT changes()')->fetchColumn();
        echo json_encode(['success' => true, 'action' => $action, 'deleted' => $deleted]);
    } elseif ($action === 'count') {
        $total = (int)$cacheDb->query('SELECT COUNT(*) FROM cache_responses')->fetchColumn();
        $stale = (int)$cacheDb->query("SELECT COUNT(*) FROM cache_responses WHERE ts < datetime('now', '-14 days')")->fetchColumn();
        echo json_encode(['success' => true, 'action' => $action, 'total' => $total, 'stale' => $stale, 'fresh' => max(0, $total - $stale)]);
    } else {
        http_response_code(400);
        echo json_encode(['error' => 'Unknown cache action.']);
    }
} catch (Throwable $error) {
    error_log('Admin cache operation failed: ' . $error->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Cache operation failed.']);
}
