<?php
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('GET, POST, OPTIONS');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
session_start();

header('Content-Type: application/json; charset=utf-8');


if ($_SERVER['REQUEST_METHOD'] !== 'POST')    { http_response_code(405); echo json_encode(['error' => 'Method not allowed.']); exit; }

$rawBody = file_get_contents('php://input');
$data    = json_decode($rawBody, true);

$email = strtolower(filter_var($data['email'] ?? '', FILTER_SANITIZE_EMAIL));
$otp   = preg_replace('/\D/', '', $data['otp'] ?? '');

if (!$email || !$otp) {
    http_response_code(400);
    echo json_encode(['error' => 'Email and OTP required.']); exit;
}

$db = get_db();
if (!$db) {
    http_response_code(500);
    echo json_encode(['error' => 'Database unavailable.']); exit;
}

// Load pending registration
$stmt = $db->prepare("SELECT * FROM pending_users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$record = $stmt->fetch();

if (!$record) {
    http_response_code(400);
    echo json_encode(['error' => 'No pending registration. Please register again.']); exit;
}

// Expiry check (10 minutes)
if ((time() - (int)$record['created_at']) > 600) {
    $db->prepare("DELETE FROM pending_users WHERE email = ?")->execute([$email]);
    http_response_code(400);
    echo json_encode(['error' => 'OTP expired. Please register again.']); exit;
}

// Max 5 attempts
if ((int)$record['attempts'] >= 5) {
    $db->prepare("DELETE FROM pending_users WHERE email = ?")->execute([$email]);
    http_response_code(429);
    echo json_encode(['error' => 'Too many wrong attempts. Please register again.']); exit;
}

// Verify OTP
if ($record['otp'] !== $otp) {
    $db->prepare("UPDATE pending_users SET attempts = attempts + 1 WHERE email = ?")->execute([$email]);
    $left = 5 - ((int)$record['attempts'] + 1);
    http_response_code(401);
    echo json_encode(['error' => "Wrong code. {$left} attempt(s) left."]); exit;
}

$stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$existing = $stmt->fetch();

if ($existing) {
    // Already exists, just refresh token
    $token = nexa_issue_persistent_token($db, $email);
} else {
    // Apply referral bonus to the referrer (O(1) Indexed Lookup)
    if (!empty($record['referral'])) {
        $refStmt = $db->prepare("SELECT id FROM users WHERE referral_code = ? LIMIT 1");
        $refStmt->execute([$record['referral']]);
        if ($ru = $refStmt->fetch()) {
            $db->prepare("UPDATE users SET bonus_limit = bonus_limit + 20 WHERE id = ?")->execute([$ru['id']]);
        }
    }

    // Generate my own referral code
    $myReferralCode = 'NX-' . strtoupper(substr(md5($record['email']), 0, 4));

    // Create the user
    $stmt = $db->prepare("INSERT INTO users
        (email, password_hash, auth_token_hash, type, name, country, state, district, pin, address, verified, referral_code)
        VALUES (?, ?, ?, 'email', ?, ?, ?, ?, ?, ?, 1, ?)");
    $stmt->execute([
        $record['email'],
        $record['password_hash'],
        hash('sha256', $token),
        $record['name'],
        $record['country'],
        $record['state'],
        $record['district'],
        $record['pin'],
        $record['address'],
        $myReferralCode
    ]);
}

// Remove from pending
$db->prepare("DELETE FROM pending_users WHERE email = ?")->execute([$email]);

// Set persistent cookie
$year = 60 * 60 * 24 * 30;
setcookie('nexa_token', $token, [
    'expires'  => time() + $year,
    'path'     => '/',
    'secure'   => NEXA_COOKIE_SECURE,
    'httponly' => true,
    'samesite' => 'Lax'
]);

$_SESSION['user_email'] = $record['email'];
echo json_encode(['success' => true, 'email' => $record['email']]);

