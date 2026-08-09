<?php
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('GET, POST, OPTIONS');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

nexa_start_session();
$userEmail = $_SESSION['user_email'] ?? 'guest';
$deviceId = $_SERVER['HTTP_X_DEVICE_ID'] ?? ($_SERVER['REMOTE_ADDR'] ?? 'unknown');

$db = get_db();
if (!$db) {
    http_response_code(500);
    echo json_encode(['error' => 'Database connection failed']);
    exit;
}

// Simple rate limit: 5 reports per day per device_id
$today = date('Y-m-d');
$stmt = $db->prepare("SELECT COUNT(*) as cnt FROM bug_reports WHERE device_id = ? AND timestamp >= ?");
$stmt->execute([substr($deviceId, 0, 50), $today . ' 00:00:00']);
$row = $stmt->fetch();
if ($row && (int)$row['cnt'] >= 5) {
    http_response_code(429);
    echo json_encode(['error' => 'Daily reporting limit reached. Thank you!']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
$message = trim($body['message'] ?? '');
$page    = trim($body['page'] ?? 'unknown');

if (strlen($message) < 5) {
    http_response_code(400);
    echo json_encode(['error' => 'Message too short']);
    exit;
}

try {
    $stmt = $db->prepare("INSERT INTO bug_reports (user_email, page, message, device_id) VALUES (?, ?, ?, ?)");
    $stmt->execute([
        $userEmail,
        substr($page, 0, 200),
        substr($message, 0, 2000),
        substr($deviceId, 0, 50)
    ]);
    echo json_encode(['success' => true, 'message' => 'Report submitted. Thank you!']);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to save report']);
}
exit;
