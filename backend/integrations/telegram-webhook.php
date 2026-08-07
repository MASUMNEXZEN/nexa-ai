<?php
/**
 * NexA AI - Telegram Bot Webhook (Modernized)
 * Integrates with unified SQLite backend for stats, logging, and limits.
 */

require_once __DIR__ . '/api/config.php';
require_once __DIR__ . '/api/db.php';

// Telegram Bot Token (from BotFather)
define('TELEGRAM_BOT_TOKEN', '7204667372:AAHnTWylevQHyIcHFRVvF1jCB0P2iLvFFaI');
define('TELEGRAM_API_URL', 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/');
define('TELEGRAM_DATA_DIR', __DIR__ . '/data/telegram/');
define('MAX_HISTORY_MESSAGES', 10);

if (!is_dir(TELEGRAM_DATA_DIR)) mkdir(TELEGRAM_DATA_DIR, 0777, true);

// 1. Get the incoming payload
$content = file_get_contents("php://input");
$update = json_decode($content, true);

if (!$update || !isset($update['message'])) exit;

$message = $update['message'];
$chatId = $message['chat']['id'];
$telegramUserId = (string)$message['from']['id'];
$text = isset($message['text']) ? trim($message['text']) : '';

// Helper: Send Message
function sendTelegramMessage($chatId, $text) {
    $url = TELEGRAM_API_URL . 'sendMessage';
    $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'Markdown'];
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    curl_exec($ch);
    curl_close($ch);
}

// 2. Commands
if ($text === '/start') {
    $welcome = "🎓 *Welcome to NexA AI Tutor!*\n\nI am your personal AI study assistant, powered by Gemini 2.5 Flash.\n\nType /help for instructions or /clear to reset history.";
    sendTelegramMessage($chatId, $welcome);
    exit;
}

if ($text === '/help') {
    $help = "*What NexA can do:*\n🔬 Solve math/science problems\n📝 Essay help\n💊 Nursing MCQ Practice\n\n_Use /clear to start a fresh topic!_";
    sendTelegramMessage($chatId, $help);
    exit;
}

if ($text === '/clear') {
    $historyFile = TELEGRAM_DATA_DIR . $telegramUserId . '_history.json';
    if (file_exists($historyFile)) unlink($historyFile);
    sendTelegramMessage($chatId, "✨ Chat history cleared!");
    exit;
}

if (empty($text)) {
    sendTelegramMessage($chatId, "Please send a text message.");
    exit;
}

// 3. Platform Integration (SQLite)
$db = get_db();
if ($db) {
    // A. Sync Telegram Profile
    $stmt = $db->prepare("INSERT OR REPLACE INTO telegram_users (telegram_id, first_name, last_name, username, language_code, last_active) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([
        $telegramUserId,
        $message['from']['first_name'] ?? '',
        $message['from']['last_name'] ?? '',
        $message['from']['username'] ?? '',
        $message['from']['language_code'] ?? 'en',
        date('Y-m-d H:i:s')
    ]);

    // B. Rate Limit Check (Telegram users are treated as 'appmode-' style for accounting)
    $userKey = 'telegram-' . $telegramUserId;
    $limitResult = check_nexa_limit($userKey, true); // Treat as App Mode (50/day)
    if (isset($limitResult['error'])) {
        sendTelegramMessage($chatId, "⚠️ *Daily Limit Reached*\n\nYou have used your daily quota. Please come back tomorrow!");
        exit;
    }

    // C. Daily Traffic Logging
    $today = date('Y-m-d');
    $db->prepare("INSERT OR IGNORE INTO daily_stats (date, count) VALUES (?, 0)")->execute([$today]);
    $db->prepare("UPDATE daily_stats SET count = count + 1 WHERE date = ?")->execute([$today]);
}

// 4. Conversation context
$historyFile = TELEGRAM_DATA_DIR . $telegramUserId . '_history.json';
$history = file_exists($historyFile) ? json_decode(file_get_contents($historyFile), true) : [];
$history[] = ['role' => 'user', 'parts' => [['text' => $text]]];

// 5. Call Gemini
$url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . GEMINI_API_KEY;
$systemInstruction = "You are NexA, a powerful AI study tutor developed by NexZen Group. Reply warmly in Bengali (unless English is more appropriate for the subject). Be concise and accurate. Sign off: '— NexA 🎓'";

$payload = [
    'system_instruction' => ['parts' => [['text' => $systemInstruction]]],
    'contents' => $history
];

$ch = curl_init($url);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
curl_setopt($ch, CURLOPT_TIMEOUT, 25);
$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);
$aiReply = "Sorry, I encountered an error. Please try again.";

if (isset($result['candidates'][0]['content']['parts'][0]['text'])) {
    $aiReply = $result['candidates'][0]['content']['parts'][0]['text'];
    $aiReply = preg_replace('/\*\*(.*?)\*\*/', '*$1*', $aiReply); // Markdown adjustment
    $aiReply = str_replace(['\\[', '\\]', '\\(', '\\)'], '', $aiReply);
    
    // Log token usage
    if (isset($result['usageMetadata'])) {
        log_nexa_usage('telegram-' . $telegramUserId, 'telegram', $result['usageMetadata']['promptTokenCount'], $result['usageMetadata']['candidatesTokenCount']);
    }
}

// Save history
$history[] = ['role' => 'model', 'parts' => [['text' => $aiReply]]];
if (count($history) > MAX_HISTORY_MESSAGES * 2) $history = array_slice($history, -(MAX_HISTORY_MESSAGES * 2));
file_put_contents($historyFile, json_encode($history));

// 6. Reply
sendTelegramMessage($chatId, $aiReply);
