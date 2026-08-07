<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';

nexa_apply_security_headers('GET, OPTIONS');
nexa_require_admin();

$db = get_db();
if (!$db) {
    http_response_code(500);
    echo json_encode(['error' => 'Database unavailable.']);
    exit;
}

$stmt = $db->query(
    'SELECT telegram_id, first_name, last_name, username, language_code, last_active, created_at
     FROM telegram_users ORDER BY last_active DESC'
);
$users = $stmt->fetchAll();

echo json_encode([
    'status' => 'success',
    'total_users' => count($users),
    'users' => $users,
], JSON_UNESCAPED_UNICODE);
