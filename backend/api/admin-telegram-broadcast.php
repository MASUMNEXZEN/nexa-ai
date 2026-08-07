<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';

nexa_apply_security_headers('POST, OPTIONS');
nexa_require_admin();

$input = json_decode(file_get_contents('php://input'), true);
$messageText = trim((string) ($input['message'] ?? ''));

if ($messageText === '') {
    http_response_code(400);
    echo json_encode(['status' => 'error', 'error' => 'Message cannot be empty']);
    exit;
}

if (TELEGRAM_BOT_TOKEN === '') {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'error' => 'Telegram integration is not configured.']);
    exit;
}

$telegramApiUrl = 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/';
$telegramDataDir = DATA_DIR . 'telegram/';
$usersToMessage = [];

if (is_dir($telegramDataDir)) {
    foreach (glob($telegramDataDir . '*_profile.json') ?: [] as $file) {
        $id = str_replace('_profile.json', '', basename($file));
        if (is_numeric($id)) {
            $usersToMessage[] = $id;
        }
    }
}

if ($usersToMessage === []) {
    echo json_encode(['status' => 'error', 'error' => 'No Telegram users found to broadcast to']);
    exit;
}

$successCount = 0;
$failCount = 0;
$ch = curl_init($telegramApiUrl . 'sendMessage');

curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POST => true,
    CURLOPT_TIMEOUT => 5,
]);

foreach ($usersToMessage as $chatId) {
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
        'chat_id' => $chatId,
        'text' => $messageText,
        'parse_mode' => 'Markdown',
    ], JSON_UNESCAPED_UNICODE));

    curl_exec($ch);
    if (curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
        $successCount++;
    } else {
        $failCount++;
    }

    usleep(100000);
}

curl_close($ch);

echo json_encode([
    'status' => 'success',
    'message' => 'Broadcast complete',
    'success_count' => $successCount,
    'fail_count' => $failCount,
    'total_attempted' => count($usersToMessage),
]);