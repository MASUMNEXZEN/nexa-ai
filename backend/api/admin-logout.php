<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('GET, POST, OPTIONS');

nexa_start_session();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'] ?? '', $params['secure'], $params['httponly']);
}
session_destroy();

echo json_encode(['success' => true]);
