<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('GET, POST, OPTIONS');
// POST {title, body, target: 'all'|'free'|'pro'|'premium', email?}
// Uses Firebase HTTP v1 API (Modern & Secure)
header('Content-Type: application/json');

require_once __DIR__ . '/db.php';

nexa_require_admin();


$body   = json_decode(file_get_contents('php://input'), true);
$title  = trim($body['title']  ?? 'NexA AI');
$msg    = trim($body['body']   ?? '');
$target = trim($body['target'] ?? 'all');
$email  = trim($body['email']  ?? '');

if (!$msg) { http_response_code(400); echo json_encode(['error' => 'Message required']); exit; }

$keyPath = DATA_DIR . 'fcm-key.json';
if (!file_exists($keyPath)) {
    http_response_code(500);
    echo json_encode(['error' => 'FCM Service Account JSON not found']); exit;
}

$db = get_db();

if ($target === 'email' && $email) {
    $stmt = $db->prepare("SELECT DISTINCT token FROM fcm_tokens WHERE user_email = ?");
    $stmt->execute([$email]);
} elseif (in_array($target, ['free', 'pro', 'premium'])) {
    $stmt = $db->prepare("SELECT DISTINCT token FROM fcm_tokens WHERE plan_name = ?");
    $stmt->execute([$target]);
} else {
    $stmt = $db->query("SELECT DISTINCT token FROM fcm_tokens");
}
$tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);

if (empty($tokens)) {
    echo json_encode(['success' => true, 'sent' => 0, 'message' => 'No devices to notify.']);
    exit;
}

function getAccessToken($jsonPath) {
    $json = json_decode(file_get_contents($jsonPath), true);
    $now = time();
    $header = json_encode(['alg' => 'RS256', 'typ' => 'JWT']);
    $payload = json_encode([
        'iss'   => $json['client_email'],
        'scope' => 'https://www.googleapis.com/auth/cloud-platform',
        'aud'   => $json['token_uri'],
        'iat'   => $now,
        'exp'   => $now + 3600
    ]);

    $base64UrlHeader = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($header));
    $base64UrlPayload = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($payload));

    $signature = '';
    openssl_sign($base64UrlHeader . "." . $base64UrlPayload, $signature, $json['private_key'], 'SHA256');
    $base64UrlSignature = str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($signature));

    $jwt = $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;

    $ch = curl_init($json['token_uri']);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt
        ])
    ]);
    $resp = json_decode(curl_exec($ch), true);
    curl_close($ch);

    return $resp['access_token'] ?? null;
}

$accessToken = getAccessToken($keyPath);
if (!$accessToken) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to obtain FCM Access Token']); exit;
}

$jsonKey = json_decode(file_get_contents($keyPath), true);
$projectId = $jsonKey['project_id'];
$apiUrl = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

$sent  = 0;
$failed = 0;

foreach ($tokens as $token) {
    $payload = [
        'message' => [
            'token' => $token,
            'notification' => [
                'title' => $title,
                'body'  => $msg,
            ],
            'android' => [
                'notification' => [
                    'icon'  => 'ic_notification',
                    'color' => '#6200EE',
                    'sound' => 'default'
                ]
            ],
            'data' => [
                'type' => 'broadcast'
            ]
        ]
    ];

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer {$accessToken}",
        ],
    ]);
    $resp = json_decode(curl_exec($ch), true);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200) {
        $sent++;
    } else {
        $failed++;
    }
}

echo json_encode([
    'success'    => true,
    'sent'       => $sent,
    'failed'     => $failed,
    'total_tokens' => count($tokens),
    'message'    => "Push sent to {$sent} devices.",
]);


