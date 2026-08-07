<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('GET, POST, OPTIONS');
require_once __DIR__ . '/db.php';

if (!empty($_COOKIE['nexa_token'])) {
    $db = get_db_connection();
    if ($db) {
        nexa_revoke_persistent_token($db, (string)$_COOKIE['nexa_token']);
    }

    setcookie('nexa_token', '', [
        'expires' => time() - 3600,
        'path' => '/',
        'secure' => NEXA_COOKIE_SECURE,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

nexa_start_session();
$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie(
        session_name(),
        '',
        time() - 42000,
        $params['path'] ?? '/',
        $params['domain'] ?? '',
        (bool)($params['secure'] ?? false),
        (bool)($params['httponly'] ?? true)
    );
}
session_destroy();

echo json_encode(['success' => true]);
