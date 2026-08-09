<?php
$root = dirname(__DIR__);
$frontendRoot = realpath($root . DIRECTORY_SEPARATOR . 'frontend');
$backendApiRoot = realpath($root . DIRECTORY_SEPARATOR . 'backend' . DIRECTORY_SEPARATOR . 'api');

// A file under backend/api is not public unless it is listed here.
$publicApi = [
    'app-ask.php', 'ask.php', 'quiz-api.php', 'leaderboard.php', 'app-config.php',
    'auth-check.php', 'auth-login.php', 'auth-register.php', 'auth-verify-otp.php',
    'auth-logout.php', 'auth-profile.php', 'auth-google.php', 'auth-forgot-password.php',
    'auth-reset-password.php', 'report-problem.php', 'sync-history.php',
    'subscription-plans.php', 'subscribe.php', 'verify-payment.php', 'save-fcm-token.php',
    'telegram-webhook.php', 'ping.php',
    'admin-login.php', 'admin-check.php', 'admin-logout.php', 'admin-stats.php',
    'admin-config.php', 'admin-limit.php', 'admin-announcement.php',
    'admin-subscriptions.php', 'admin-clear-cache.php', 'admin-export-csv.php',
    'admin-export-telegram-csv.php', 'admin-export-usage.php', 'admin-reports.php',
    'admin-telegram-broadcast.php', 'admin-telegram-users.php', 'push-broadcast.php',
];

$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$requestPath = '/' . ltrim($requestPath, '/');

if (str_starts_with($requestPath, '/api/')) {
    $endpoint = basename($requestPath);
    if (!str_ends_with($endpoint, '.php')) $endpoint .= '.php';
    $file = $backendApiRoot ? $backendApiRoot . DIRECTORY_SEPARATOR . $endpoint : '';
    if (in_array($endpoint, $publicApi, true) && is_file($file)) {
        require $file;
        return true;
    }
    http_response_code(404);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['error' => 'Endpoint not found']);
    return true;
}

$routes = [
    '/' => 'index.html',
    '/login' => 'login.html',
    '/admin-login' => 'admin-login.html',
    '/admin' => 'admin.html',
    '/maintenance' => 'maintenance.html',
    '/privacy' => 'privacy.html',
    '/reset' => 'reset.html',
    '/terms' => 'terms.html',
    '/manifest.json' => 'manifest.json',
    '/robots.txt' => 'robots.txt',
    '/sw.js' => 'sw.js',
];
$relative = $routes[$requestPath] ?? ltrim($requestPath, '/');
$file = $frontendRoot ? $frontendRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative) : '';
$resolvedFile = $file !== '' ? realpath($file) : false;
$isInsideFrontend = $resolvedFile !== false && $frontendRoot !== false
    && ($resolvedFile === $frontendRoot || str_starts_with($resolvedFile, $frontendRoot . DIRECTORY_SEPARATOR));

if ($isInsideFrontend && is_file($resolvedFile)) {
    $mimeTypes = [
        'html' => 'text/html; charset=utf-8',
        'css' => 'text/css; charset=utf-8',
        'js' => 'text/javascript; charset=utf-8',
        'json' => 'application/json; charset=utf-8',
        'txt' => 'text/plain; charset=utf-8',
        'svg' => 'image/svg+xml',
        'png' => 'image/png',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'webp' => 'image/webp',
        'ico' => 'image/x-icon',
        'woff' => 'font/woff',
        'woff2' => 'font/woff2',
        'ttf' => 'font/ttf',
        'otf' => 'font/otf',
    ];
    $extension = strtolower(pathinfo($resolvedFile, PATHINFO_EXTENSION));
    header('Content-Type: ' . ($mimeTypes[$extension] ?? 'application/octet-stream'));
    readfile($resolvedFile);
    return true;
}

http_response_code(404);
echo 'Not found';
return true;