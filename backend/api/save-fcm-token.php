<?php
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('GET, POST, OPTIONS');
// Called by Flutter app after Firebase initializes
// POST {token, platform}
header('Content-Type: application/json');


require_once __DIR__ . '/db.php';

session_start();
if (empty($_SESSION['user_email'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']); exit;
}

$userEmail = $_SESSION['user_email'];
$body      = json_decode(file_get_contents('php://input'), true);
$token     = trim($body['token']    ?? '');
$platform  = trim($body['platform'] ?? 'android');

if (!$token) {
    http_response_code(400);
    echo json_encode(['error' => 'Token required']); exit;
}

$db = get_db();

// Get user plan
$userRow = $db->prepare("SELECT type FROM users WHERE email = ?");
$userRow->execute([$userEmail]);
$userRow = $userRow->fetch();
$planName = $userRow['type'] ?? 'free';
if (!in_array($planName, ['free', 'pro', 'premium', 'admin'])) $planName = 'free';

// Upsert token (UNIQUE on token column)
$db->prepare("INSERT INTO fcm_tokens (user_email, token, plan_name, platform) VALUES (?, ?, ?, ?)
    ON CONFLICT(token) DO UPDATE SET user_email = excluded.user_email, plan_name = excluded.plan_name, created_at = CURRENT_TIMESTAMP")
   ->execute([$userEmail, $token, $planName, $platform]);

echo json_encode(['success' => true]);

