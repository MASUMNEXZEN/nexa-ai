<?php
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('GET, POST, OPTIONS');
error_reporting(0); ini_set('display_errors', '0');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
nexa_start_session();

header('Content-Type: application/json; charset=utf-8');


if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405); echo json_encode(['error' => 'Method not allowed.']); exit;
}

$data    = nexa_read_json_body(65536, 'Invalid Google sign-in request.');
$idToken = $data['credential'] ?? '';

if (!is_string($idToken) || $idToken === '' || strlen($idToken) > 20000) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing Google credential token.']); exit;
}

$expectedClientId = defined('GOOGLE_CLIENT_ID') ? trim((string)GOOGLE_CLIENT_ID) : '';
if ($expectedClientId === '') {
    http_response_code(503);
    echo json_encode(['error' => 'Google sign-in is not configured.']); exit;
}

$verifyUrl = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . urlencode($idToken);
$ch = curl_init($verifyUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode !== 200 || !$response) {
    http_response_code(401);
    echo json_encode(['error' => 'Failed to verify Google token.']); exit;
}

$tokenData = json_decode($response, true);
if (!is_array($tokenData)) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid Google token response.']); exit;
}

// tokeninfo verifies the signature and expiry remotely; enforce the identity claims locally too.
$issuer = (string)($tokenData['iss'] ?? '');
$issuerValid = in_array($issuer, ['accounts.google.com', 'https://accounts.google.com'], true);
$emailVerified = in_array($tokenData['email_verified'] ?? false, [true, 'true', 1, '1'], true);
$expiresAt = filter_var($tokenData['exp'] ?? null, FILTER_VALIDATE_INT);
if (($tokenData['aud'] ?? '') !== $expectedClientId || !$issuerValid || !$emailVerified || !$expiresAt || $expiresAt <= time()) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid Google identity token.']); exit;
}

$email = strtolower(trim((string)($tokenData['email'] ?? '')));
$name  = trim((string)($tokenData['name'] ?? ''));
$sub   = trim((string)($tokenData['sub'] ?? '')); // Google unique user ID

if (!filter_var($email, FILTER_VALIDATE_EMAIL) || $sub === '' || strlen($sub) > 255) {
    http_response_code(401);
    echo json_encode(['error' => 'Could not retrieve email from Google.']); exit;
}
$name = substr(preg_replace('/[\x00-\x1F\x7F]/', '', $name), 0, 160);

$db = get_db_connection();
if (!$db) {
    http_response_code(500);
    echo json_encode(['error' => 'Database unavailable.']); exit;
}

// Check if user already exists in SQLite
$stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
$existingUser = $stmt->fetch();

if ($existingUser) {
    // Update existing user  
    $db->prepare("UPDATE users SET google_sub = ?, name = ? WHERE email = ?")
       ->execute([$sub, $name, $email]);
} else {
    $legacyData = [];
    $usersFile  = DATA_DIR . 'users.json';
    if (file_exists($usersFile)) {
        $jsonUsers = json_decode(file_get_contents($usersFile), true) ?: [];
        foreach ($jsonUsers as $ju) {
            if (strtolower($ju['email'] ?? '') === $email) {
                $legacyData = $ju; break;
            }
        }
    }

    // Insert into SQLite (merge legacy data if exists)
    $db->prepare("INSERT INTO users
        (email, password_hash, type, name, google_sub, country, state, district, pin, address, bonus_limit, verified, created_at)
        VALUES (?, ?, 'google', ?, ?, ?, ?, ?, ?, ?, ?, 1, ?)")
       ->execute([
            $email,
            $legacyData['password_hash'] ?? null,
            $name,
            $sub,
            $legacyData['country']  ?? '',
            $legacyData['state']    ?? '',
            $legacyData['district'] ?? '',
            $legacyData['pin']      ?? '',
            $legacyData['address']  ?? '',
            isset($legacyData['bonus_limit']) ? (int)$legacyData['bonus_limit'] : 0,
            $legacyData['created_at'] ?? date('Y-m-d H:i:s')
        ]);
}

$token = nexa_issue_persistent_token($db, $email);

$year = 60 * 60 * 24 * 30;
setcookie('nexa_token', $token, [
    'expires'  => time() + $year,
    'path'     => '/',
    'secure'   => NEXA_COOKIE_SECURE,
    'httponly' => true,
    'samesite' => 'Lax'
]);

// Start session
session_regenerate_id(true);
$_SESSION['user_email'] = $email;
$_SESSION['user_name']  = $name;

echo json_encode(['success' => true, 'email' => $email, 'name' => $name]);


