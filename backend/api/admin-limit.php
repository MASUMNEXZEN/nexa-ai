<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('GET, POST, OPTIONS');
nexa_require_admin();
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$rawBody = file_get_contents('php://input');
$data = json_decode($rawBody, true);
$newLimit = isset($data['daily_limit']) ? (int)$data['daily_limit'] : 0;

if ($newLimit < 1 || $newLimit > 1000) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid limit. Must be between 1 and 1000.']);
    exit;
}

$db = get_db();
if (!$db) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

try {
    $stmt = $db->prepare("INSERT OR REPLACE INTO global_config (key, value) VALUES ('daily_limit', ?)");
    $stmt->execute([$newLimit]);
    
    // Also log who updated it
    $stmt = $db->prepare("INSERT OR REPLACE INTO global_config (key, value) VALUES ('limit_updated_by', ?)");
    $stmt->execute([$_SESSION['admin_email'] ?? 'admin']);
    
    $stmt = $db->prepare("INSERT OR REPLACE INTO global_config (key, value) VALUES ('limit_updated_at', ?)");
    $stmt->execute([date('c')]);

    echo json_encode(['status' => 'success', 'newLimit' => $newLimit]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to update limit in database']);
}
exit;





