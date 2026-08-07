<?php
error_reporting(0); ini_set('display_errors', '0');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('POST, OPTIONS');
require_once __DIR__ . '/db.php';
session_start();

header('Content-Type: application/json; charset=utf-8');


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'Method not allowed.']); exit;
}

$rawBody = file_get_contents('php://input');
$data    = json_decode($rawBody, true);

$email    = strtolower(filter_var($data['email'] ?? '', FILTER_SANITIZE_EMAIL));
$password = $data['password'] ?? '';

if (!$email || !$password) {
    http_response_code(400);
    echo json_encode(['error' => 'Email and password required.']); exit;
}

$db = get_db();
if (!$db) {
    http_response_code(500);
    echo json_encode(['error' => 'Database unavailable. Please try again.']); exit;
}

$db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
    email        TEXT NOT NULL PRIMARY KEY COLLATE NOCASE,
    attempts     INTEGER DEFAULT 0,
    locked_until INTEGER DEFAULT 0
)");

$now          = time();
$lockDuration = 15 * 60; // 15 minutes
$maxAttempts  = 5;

$stmt = $db->prepare("SELECT attempts, locked_until FROM login_attempts WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$attempt = $stmt->fetch();

if ($attempt && $attempt['locked_until'] > $now) {
    $minutesLeft = ceil(($attempt['locked_until'] - $now) / 60);
    http_response_code(429);
    echo json_encode(['error' => "Too many failed attempts. Try again in {$minutesLeft} minute(s)."]); exit;
}

$stmt = $db->prepare("SELECT id, email, password_hash FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$user = $stmt->fetch();

$passwordOk = ($user && password_verify($password, $user['password_hash'] ?? ''));

if (!$user || !$passwordOk) {
    $currentAttempts = ($attempt['attempts'] ?? 0) + 1;
    $lockedUntil     = ($currentAttempts >= $maxAttempts) ? $now + $lockDuration : 0;
    $db->prepare("INSERT INTO login_attempts (email, attempts, locked_until) VALUES (?,?,?)
        ON CONFLICT(email) DO UPDATE SET attempts = ?, locked_until = ?")
       ->execute([$email, $currentAttempts, $lockedUntil, $currentAttempts, $lockedUntil]);
    $remaining = max(0, $maxAttempts - $currentAttempts);
    $msg = $lockedUntil
        ? 'Account locked for 15 minutes due to too many failed attempts.'
        : "Invalid email or password. {$remaining} attempt(s) remaining before lockout.";
    http_response_code(401);
    echo json_encode(['error' => $msg]); exit;
}

$db->prepare("DELETE FROM login_attempts WHERE email = ?")->execute([$email]);

$token = nexa_issue_persistent_token($db, $email);

$year = 60 * 60 * 24 * 30;
setcookie('nexa_token', $token, [
    'expires'  => time() + $year,
    'path'     => '/',
    'secure'   => NEXA_COOKIE_SECURE,
    'httponly' => true,
    'samesite' => 'Lax'
]);

session_regenerate_id(true);
$_SESSION['user_email'] = $email;
echo json_encode(['success' => true, 'email' => $email]);




