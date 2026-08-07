<?php
// Secrets are loaded from .env (NOT hardcoded here)

$envFile = getenv('NEXA_ENV_FILE') ?: (__DIR__ . '/../.env');
if (file_exists($envFile)) {
    $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($key, $val) = explode('=', $line, 2);
        $key = trim($key); $val = trim($val);
        if ($key && !defined($key)) {
            putenv("$key=$val");
            $_ENV[$key] = $val;
        }
    }
}

define('GEMINI_API_KEY', getenv('GEMINI_API_KEY') ?: '');

define('RAZORPAY_KEY_ID',     getenv('RAZORPAY_KEY_ID')     ?: '');
define('RAZORPAY_KEY_SECRET', getenv('RAZORPAY_KEY_SECRET') ?: '');

define('TELEGRAM_BOT_TOKEN', getenv('TELEGRAM_BOT_TOKEN') ?: '');


define('DEEPSEEK_API_KEY', getenv('DEEPSEEK_API_KEY') ?: '');

define('BEDROCK_PROXY_URL', getenv('BEDROCK_PROXY_URL') ?: '');
define('BEDROCK_PROXY_SECRET', getenv('BEDROCK_PROXY_SECRET') ?: ''); // Must match Lambda

define('GOOGLE_CLIENT_ID', getenv('GOOGLE_CLIENT_ID') ?: '');

// ADMIN_PASSWORD_HASH in .env is a bcrypt hash of the admin password.
// Generate with: password_hash('yourpassword', PASSWORD_BCRYPT, ['cost'=>12])
define('ADMIN_EMAIL', getenv('ADMIN_EMAIL') ?: '');
define('ADMIN_PASSWORD_HASH', getenv('ADMIN_PASSWORD_HASH') ?: '');

define('DEFAULT_DAILY_LIMIT', 100);
define('NEXA_COOKIE_SECURE', getenv('NEXA_COOKIE_SECURE') !== '0');
define('NEXA_APP_ENV', getenv('NEXA_APP_ENV') ?: 'production');
define('NEXA_MAIL_TRANSPORT', getenv('NEXA_MAIL_TRANSPORT') ?: 'mail');
/** Clear the unavailable local sandbox proxy for outbound AI calls only. */
function nexa_configure_curl($handle) {
    if (defined('NEXA_APP_ENV') && NEXA_APP_ENV === 'local') {
        curl_setopt($handle, CURLOPT_PROXY, '');
    }
}

$sessionLifetime = 60 * 60 * 24 * 30; // 30 days in seconds
if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.gc_maxlifetime', $sessionLifetime);
    ini_set('session.cookie_lifetime', $sessionLifetime);
    session_set_cookie_params([
        'lifetime' => $sessionLifetime,
        'path'     => '/',
        'secure'   => NEXA_COOKIE_SECURE,
        'httponly'  => true,
        'samesite'  => 'Lax'
    ]);
}

define('DATA_DIR', __DIR__ . '/../data/');


function nexa_client_ip(): string
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (getenv('NEXA_TRUST_PROXY_HEADERS') === '1') {
        $ip = $_SERVER['HTTP_CF_CONNECTING_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $ip;
    }
    if (strpos($ip, ',') !== false) {
        $ip = trim(explode(',', $ip)[0]);
    }
    return preg_replace('/[^a-zA-Z0-9.:_-]/', '_', trim($ip)) ?: 'unknown';
}

function nexa_guest_key(): string
{
    return 'appmode-' . substr(hash('sha256', nexa_client_ip()), 0, 32);
}
