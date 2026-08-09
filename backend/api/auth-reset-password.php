<?php
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('GET, POST, OPTIONS');
// Auth: SQLite-first (no password-resets.json dependency)
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
nexa_start_session();

header('Content-Type: application/json; charset=utf-8');


if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed.']); exit; }

$data        = json_decode(file_get_contents('php://input'), true);
$email       = strtolower(filter_var($data['email'] ?? '', FILTER_SANITIZE_EMAIL));
$otp         = preg_replace('/\D/', '', (string)($data['otp'] ?? ''));
$newPassword = $data['new_password'] ?? '';

if (!$email || !preg_match('/^\d{6}$/', $otp) || !is_string($newPassword) || $newPassword === '') {
    http_response_code(400);
    echo json_encode(['error' => 'All fields are required.']); exit;
}

if (strlen($newPassword) < 8 || !preg_match('/[A-Z]/', $newPassword) || !preg_match('/[0-9]/', $newPassword)) {
    http_response_code(400);
    echo json_encode(['error' => 'Password must be at least 8 characters with one uppercase letter and one number.']); exit;
}

$db = get_db_connection();
if (!$db) {
    http_response_code(500);
    echo json_encode(['error' => 'Database unavailable.']); exit;
}

// Fetch reset record from SQLite
$stmt = $db->prepare("SELECT otp_hash, attempts, created_at FROM password_resets WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$record = $stmt->fetch();

if (!$record) {
    http_response_code(400);
    echo json_encode(['error' => 'No reset request found. Please request a new code.']); exit;
}

// Check expiry (10 min)
if ((time() - $record['created_at']) > 600) {
    $db->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);
    http_response_code(400);
    echo json_encode(['error' => 'Reset code expired. Please request a new one.']); exit;
}

// Rate limit: max 5 attempts
if ((int)$record['attempts'] >= 5) {
    $db->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);
    http_response_code(429);
    echo json_encode(['error' => 'Too many wrong attempts. Please request a new code.']); exit;
}

// Verify OTP
if (empty($record['otp_hash']) || !password_verify($otp, $record['otp_hash'])) {
    $db->prepare("UPDATE password_resets SET attempts = attempts + 1 WHERE email = ?")->execute([$email]);
    $left = 5 - ((int)$record['attempts'] + 1);
    http_response_code(401);
    echo json_encode(['error' => "Wrong code. {$left} attempt(s) left."]); exit;
}

$newHash = password_hash($newPassword, PASSWORD_DEFAULT);
$stmt = $db->prepare("UPDATE users SET password_hash = ? WHERE email = ?");
$stmt->execute([$newHash, $email]);

if ($stmt->rowCount() === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'User not found in system.']); exit;
}

// Clean up the used OTP
$db->prepare("DELETE FROM password_resets WHERE email = ?")->execute([$email]);

// Rotate both the session and persistent login token after a password reset.
$token = nexa_issue_persistent_token($db, $email);
session_regenerate_id(true);
$_SESSION['user_email'] = $email;
setcookie('nexa_token', $token, [
    'expires' => time() + (60 * 60 * 24 * 30),
    'path' => '/',
    'secure' => NEXA_COOKIE_SECURE,
    'httponly' => true,
    'samesite' => 'Lax',
]);

echo json_encode(['success' => true, 'message' => 'Password reset successful!']);

