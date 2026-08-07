<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('GET, OPTIONS');
nexa_require_admin();
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');


$db = get_db();
if (!$db) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

try {
    $stmt = $db->query("SELECT id, user_email, page, message, device_id, timestamp FROM bug_reports ORDER BY timestamp DESC LIMIT 100");
    $reports = $stmt->fetchAll();
    
    $stmt = $db->query("SELECT COUNT(*) as total FROM bug_reports");
    $total = $stmt->fetch()['total'] ?? 0;

    echo json_encode(['reports' => $reports, 'total' => $total]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to fetch reports from database']);
}
exit;





