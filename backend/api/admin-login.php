<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('POST, OPTIONS');
nexa_start_session();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$rawBody = file_get_contents('php://input');
$body = json_decode($rawBody, true);

$email = strtolower(trim((string)($body['email'] ?? '')));
$password = (string)($body['password'] ?? '');

// Check if email matches and password verifies against the hash
$emailValid = ($email === ADMIN_EMAIL);
$passwordValid = false;

if (defined('ADMIN_PASSWORD_HASH') && ADMIN_PASSWORD_HASH !== '') {
    $passwordValid = password_verify($password, ADMIN_PASSWORD_HASH);
}

if ($emailValid && $passwordValid) {
    session_regenerate_id(true);
    $_SESSION['admin_logged_in'] = true;
    $_SESSION['admin_email'] = $email;
    echo json_encode(['success' => true, 'email' => $email]);
} else {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid email or password.']);
}
