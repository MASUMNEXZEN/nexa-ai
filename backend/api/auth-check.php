<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';

nexa_start_session();
nexa_apply_security_headers('GET, OPTIONS');

$email = $_SESSION['user_email'] ?? null;
$user = null;
$db = get_db();

if (!$email && !empty($_COOKIE['nexa_token']) && $db) {
    $user = nexa_resolve_persistent_token($db, $_COOKIE['nexa_token']);
    if ($user) {
        $email = $user['email'];
        $_SESSION['user_email'] = $email;
    }
}

if (!$email || !$db) {
    echo json_encode(['logged_in' => false]);
    exit;
}

if (!$user) {
    $stmt = $db->prepare(
        'SELECT email, name, type, country, state, district, pin, address,
                bonus_limit, referral_code, google_sub
         FROM users WHERE email = ? LIMIT 1'
    );
    $stmt->execute([$email]);
    $user = $stmt->fetch();
}

if (!$user) {
    echo json_encode(['logged_in' => false]);
    exit;
}

$today = date('Y-m-d');
$countStmt = $db->prepare('SELECT count FROM rate_limits WHERE user_key = ? AND date = ? LIMIT 1');
$countStmt->execute([$email, $today]);
$todayCount = (int)($countStmt->fetchColumn() ?: 0);

$profile = [
    'name' => $user['name'] ?? '',
    'country' => $user['country'] ?? '',
    'state' => $user['state'] ?? '',
    'district' => $user['district'] ?? '',
    'pin' => $user['pin'] ?? '',
    'address' => $user['address'] ?? '',
];

echo json_encode([
    'logged_in' => true,
    'email' => $email,
    'type' => $user['type'] ?? (!empty($user['google_sub']) ? 'google' : 'email'),
    'profile' => $profile,
    'today_count' => $todayCount,
    'referralCode' => $user['referral_code'] ?? '',
    'bonus_limit' => (int)($user['bonus_limit'] ?? 0),
    'csrf_token' => nexa_csrf_token(),
]);
