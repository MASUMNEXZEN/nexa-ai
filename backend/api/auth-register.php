<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('POST, OPTIONS');
require_once __DIR__ . '/db.php';
session_start();

header('Content-Type: application/json; charset=utf-8');


if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed.']); exit; }

$rawBody = file_get_contents('php://input');
$data    = json_decode($rawBody, true);

$email    = strtolower(filter_var($data['email'] ?? '', FILTER_SANITIZE_EMAIL));
$password = $data['password'] ?? '';
$name     = trim($data['name']     ?? '');
$country  = trim($data['country']  ?? '');
$state    = trim($data['state']    ?? '');
$district = trim($data['district'] ?? '');
$pin      = trim($data['pin']      ?? '');
$address  = trim($data['address']  ?? '');
$referral = strtoupper(trim($data['referral'] ?? ''));

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Please enter a valid email address.']); exit;
}
if (strlen($password) < 8 || !preg_match('/[A-Z]/', $password) || !preg_match('/[0-9]/', $password)) {
    http_response_code(400);
    echo json_encode(['error' => 'Password must be at least 8 characters with one uppercase letter and one number.']); exit;
}

$db = get_db();
if (!$db) {
    http_response_code(500);
    echo json_encode(['error' => 'Database unavailable. Please try again.']); exit;
}

// Check if already registered
$stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);
if ($stmt->fetch()) {
    http_response_code(400);
    echo json_encode(['error' => 'This email is already registered. Please sign in.']); exit;
}

// Generate 6-digit OTP
$otp = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

// Upsert into pending_users (overwrites old OTP for same email)
$stmt = $db->prepare("INSERT OR REPLACE INTO pending_users
    (email, password_hash, otp, attempts, name, country, state, district, pin, address, referral, created_at)
    VALUES (?, ?, ?, 0, ?, ?, ?, ?, ?, ?, ?, ?)");
$stmt->execute([
    $email,
    password_hash($password, PASSWORD_DEFAULT),
    $otp,
    $name, $country, $state, $district, $pin, $address, $referral,
    time()
]);

// Clean expired pending entries older than 15 minutes
$db->exec("DELETE FROM pending_users WHERE created_at < " . (time() - 900));

// Send OTP email
$subject = 'Your NexA AI Verification Code';
$body    = <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:#f4f4f7;">
  <table width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;margin:40px auto;background:#ffffff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">
    <tr><td style="background:linear-gradient(135deg,#e62335,#ff3d50);padding:28px 30px;text-align:center;">
        <h1 style="margin:0;color:#fff;font-size:24px;font-weight:700;">Nex<span style="font-weight:800;">A</span> AI</h1>
        <p style="margin:6px 0 0;color:rgba(255,255,255,0.85);font-size:13px;">by NexZen Institute</p>
    </td></tr>
    <tr><td style="padding:32px 30px 20px;text-align:center;">
        <p style="margin:0 0 8px;font-size:16px;color:#333;">Your verification code is:</p>
        <div style="display:inline-block;margin:16px 0;padding:16px 36px;background:#f8f9fa;border:2px dashed #e62335;border-radius:12px;">
          <span style="font-size:36px;font-weight:800;letter-spacing:8px;color:#e62335;">{$otp}</span>
        </div>
        <p style="margin:16px 0 0;font-size:13px;color:#888;">This code expires in <strong>15 minutes</strong>.</p>
    </td></tr>
    <tr><td style="padding:16px 30px;background:#f8f9fa;text-align:center;border-top:1px solid #eee;">
        <p style="margin:0;font-size:11px;color:#999;">© 2026 NexZen Institute · <a href="https://nexzen.live" style="color:#e62335;text-decoration:none;">nexzen.live</a></p>
    </td></tr>
  </table>
</body>
</html>
HTML;

$headers = implode("\r\n", [
    'From: NexA AI <noreply@nexzen.live>',
    'Reply-To: noreply@nexzen.live',
    'MIME-Version: 1.0',
    'Content-Type: text/html; charset=UTF-8',
]);


// Deliver OTP through the configured transport. Local development never requires SMTP.
if (NEXA_APP_ENV === 'local' && NEXA_MAIL_TRANSPORT === 'log') {
    @file_put_contents(DATA_DIR . 'dev-otp.log', date('c') . " {$email}: {$otp}\n", FILE_APPEND);
    echo json_encode(['success' => true, 'message' => 'Local verification code generated.', 'dev_otp' => $otp]);
    exit;
}

$sent = @mail($email, $subject, $body, $headers);
if (!$sent) {
    $sent = @mail($email, $subject, $body, "From: noreply@nexzen.live\r\nContent-Type: text/html; charset=UTF-8");
}

if ($sent) {
    echo json_encode(['success' => true, 'message' => "Verification code sent to {$email}"]);
} else {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to send verification email. Please try again.']);
}
