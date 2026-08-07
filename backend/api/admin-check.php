<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';

nexa_start_session();
nexa_apply_security_headers('GET, OPTIONS');

if (!empty($_SESSION['admin_logged_in'])) {
    echo json_encode([
        'loggedIn' => true,
        'email' => $_SESSION['admin_email'] ?? '',
        'csrf_token' => nexa_csrf_token(),
    ]);
    exit;
}

http_response_code(401);
echo json_encode(['loggedIn' => false, 'error' => 'Unauthorized']);
