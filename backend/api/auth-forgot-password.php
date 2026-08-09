<?php
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('GET, POST, OPTIONS');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
nexa_start_session();

header('Content-Type: application/json; charset=utf-8');


if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['error' => 'Method not allowed.']); exit; }

$data  = json_decode(file_get_contents('php://input'), true);
$email = strtolower(filter_var($data['email'] ?? '', FILTER_SANITIZE_EMAIL));

if (!$email || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(400);
    echo json_encode(['error' => 'Please enter a valid email address.']); exit;
}

$db = get_db_connection();
if (!$db) {
    http_response_code(500);
    echo json_encode(['error' => 'Maintenance mode. Try again later.']); exit;
}

// RATE LIMITING (Prevent Email Spam Bombing)
$ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
// otp_requests table created in db.php schema

$stmt = $db->prepare("SELECT last_request, count FROM otp_requests WHERE ip = ?");
$stmt->execute([$ip]);
$rate = $stmt->fetch();
$now = time();

if ($rate) {
    if ($now - $rate['last_request'] < 60) {
        http_response_code(429);
        echo json_encode(['error' => 'Please wait 60 seconds before requesting another code.']); exit;
    }
    if ($now - $rate['last_request'] < 3600 && $rate['count'] >= 5) {
        http_response_code(429);
        echo json_encode(['error' => 'Too many reset requests. Please try again later.']); exit;
    }
    if ($now - $rate['last_request'] >= 3600) {
        $db->prepare("UPDATE otp_requests SET count = 1, last_request = ? WHERE ip = ?")->execute([$now, $ip]);
    } else {
        $db->prepare("UPDATE otp_requests SET count = count + 1, last_request = ? WHERE ip = ?")->execute([$now, $ip]);
    }
} else {
    $db->prepare("INSERT INTO otp_requests (ip, count, last_request) VALUES (?, 1, ?)")->execute([$ip, $now]);
}

// Check if user exists in SQLite Database
$stmt = $db->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$stmt->execute([$email]);

if (!$stmt->fetch()) {
    echo json_encode(['success' => true, 'message' => 'If this email is registered, you will receive a reset code.']); exit;
}

// Generate 6-digit OTP
$otp = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

// Clean expired entries, then store OTP atomically in SQLite
$db->exec("DELETE FROM password_resets WHERE created_at < " . (time() - 900));
$db->prepare("INSERT OR REPLACE INTO password_resets (email, otp, otp_hash, attempts, created_at) VALUES (?, '', ?, 0, ?)")
   ->execute([$email, password_hash($otp, PASSWORD_DEFAULT), time()]);


// Send branded HTML reset email
$subject = 'Reset Your NexA AI Password';
$body = <<<HTML
<!DOCTYPE html>
<html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;font-family:'Segoe UI',Tahoma,sans-serif;background:#f4f4f7;">
  <table width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;margin:40px auto;background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 4px 24px rgba(0,0,0,0.08);">
    <tr><td style="background:linear-gradient(135deg,#625BEE,#7E9CF8);padding:28px 30px;text-align:center;">
        <h1 style="margin:0;color:#fff;font-size:24px;font-weight:700;">Password Reset</h1>
        <p style="margin:6px 0 0;color:rgba(255,255,255,0.85);font-size:13px;">NexA AI by NexZen Institute</p>
    </td></tr>
    <tr><td style="padding:32px 30px 20px;text-align:center;">
        <p style="margin:0 0 8px;font-size:16px;color:#333;">Your password reset code is:</p>
        <div style="display:inline-block;margin:16px 0;padding:16px 36px;background:#f8f9fa;border:2px dashed #625BEE;border-radius:12px;">
          <span style="font-size:36px;font-weight:800;letter-spacing:8px;color:#625BEE;">{$otp}</span>
        </div>
        <p style="margin:16px 0 0;font-size:13px;color:#888;">This code expires in <strong>10 minutes</strong>.</p>
    </td></tr>
    <tr><td style="padding:0 30px 28px;text-align:center;">
        <p style="margin:0;font-size:12px;color:#aaa;">If you didn&apos;t request this, your account is safe &mdash; just ignore this email.</p>
    </td></tr>
    <tr><td style="padding:16px 30px;background:#f8f9fa;text-align:center;border-top:1px solid #eee;">
        <p style="margin:0;font-size:11px;color:#999;">&copy; 2026 NexZen Institute</p>
    </td></tr>
  </table>
</body></html>
HTML;

$headers = implode("\r\n", [
    'From: NexA AI <noreply@nexzen.live>',
    'Reply-To: noreply@nexzen.live',
    'MIME-Version: 1.0',
    'Content-Type: text/html; charset=UTF-8',
]);

mail($email, $subject, $body, $headers);
echo json_encode(['success' => true, 'message' => 'If this email is registered, you will receive a reset code.']);

