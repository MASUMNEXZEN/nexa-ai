<?php
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('POST, OPTIONS');
require_once __DIR__ . '/config.php';

$webhookSecret = trim((string)NEXA_TELEGRAM_WEBHOOK_SECRET);
$providedSecret = trim((string)($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? ''));
if ($webhookSecret === '') {
    nexa_reject_json(503, 'Telegram webhook is not configured.');
}
if ($providedSecret === '' || !hash_equals($webhookSecret, $providedSecret)) {
    nexa_reject_json(403, 'Webhook authorization failed.');
}

nexa_enforce_max_body_size(524288, 'Telegram update is too large.');
http_response_code(200);

function nexa_telegram_log(string $event, array $context = []): void
{
    $record = [
        'time' => gmdate('c'),
        'event' => $event,
        'context' => $context,
    ];

    try {
        $dir = __DIR__ . '/../data/telegram/';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Telegram log directory unavailable.');
        }
        $line = json_encode($record, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($line === false || file_put_contents($dir . 'events.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX) === false) {
            throw new RuntimeException('Telegram event log write failed.');
        }
    } catch (Throwable $e) {
        error_log('[Nexa Telegram] event logging failed: ' . get_class($e));
    }
}

function nexa_telegram_get_json(string $url): ?array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);
    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $decoded = json_decode((string)$response, true);
    return $status === 200 && is_array($decoded) ? $decoded : null;
}

function nexa_telegram_download(string $url, int $maxBytes): ?array
{
    $buffer = '';
    $tooLarge = false;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
        CURLOPT_WRITEFUNCTION => function ($curl, string $chunk) use (&$buffer, &$tooLarge, $maxBytes): int {
            if (strlen($buffer) + strlen($chunk) > $maxBytes) {
                $tooLarge = true;
                return 0;
            }
            $buffer .= $chunk;
            return strlen($chunk);
        },
    ]);
    $success = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($success === false || $tooLarge || $status !== 200 || $buffer === '') {
        return null;
    }

    $mimeType = (new finfo(FILEINFO_MIME_TYPE))->buffer($buffer);
    if (!in_array($mimeType, ['image/jpeg', 'image/png', 'image/webp'], true)) {
        return null;
    }

    return ['data' => $buffer, 'mime_type' => $mimeType];
}

try {
    // Telegram bots use synchronous generation for reliable webhook latency.
    define('GEMINI_MODEL', getenv('TELEGRAM_GEMINI_MODEL') ?: 'gemini-2.0-flash');
    define('TELEGRAM_API_URL', 'https://api.telegram.org/bot' . TELEGRAM_BOT_TOKEN . '/');
    if (!GEMINI_API_KEY || !TELEGRAM_BOT_TOKEN) {
        throw new RuntimeException('Telegram integration is not configured.');
    }
    define('TELEGRAM_DATA_DIR', __DIR__ . '/../data/telegram/');
    define('MAX_HISTORY_MESSAGES', 10);
    define('TELEGRAM_DAILY_LIMIT', 50);

    if (!is_dir(TELEGRAM_DATA_DIR)) {
        @mkdir(TELEGRAM_DATA_DIR, 0777, true);
    }

    $content = file_get_contents('php://input');
    $update = json_decode($content ?: '', true);

    if (!is_array($update) || !isset($update['message']) || !is_array($update['message'])) {
        nexa_telegram_log('ignored_update', [
            'update_id' => $update['update_id'] ?? null,
            'has_message' => isset($update['message']),
        ]);
        exit;
    }

    $messageForLog = $update['message'];
    $chatIdForLog = $messageForLog['chat']['id'] ?? '';
    nexa_telegram_log('update_received', [
        'update_id' => $update['update_id'] ?? null,
        'chat_hash' => $chatIdForLog === '' ? null : hash('sha256', (string)$chatIdForLog),
        'has_photo' => isset($messageForLog['photo']),
    ]);

    $message = $update['message'];
    $chatId = $message['chat']['id'];
    $telegramUserId = $message['from']['id'];
    $telegramFirstName = isset($message['from']['first_name']) ? $message['from']['first_name'] : '';
    $telegramLastName = isset($message['from']['last_name']) ? $message['from']['last_name'] : '';
    $telegramUsername = isset($message['from']['username']) ? $message['from']['username'] : '';
    $text = isset($message['text']) ? trim($message['text']) : '';
    
    $imagePart = null;
    if (isset($message['photo'])) {
        $photo = end($message['photo']); // highest resolution
        $fileId = is_array($photo) ? (string)($photo['file_id'] ?? '') : '';
        if ($fileId !== '' && strlen($fileId) <= 256) {
            $fileInfoUrl = TELEGRAM_API_URL . "getFile?file_id=" . urlencode($fileId);
            $fileInfo = nexa_telegram_get_json($fileInfoUrl);
            $filePath = $fileInfo['result']['file_path'] ?? '';
            if (is_string($filePath) && preg_match('/^[A-Za-z0-9_.\/-]+$/', $filePath)) {
                $dlUrl = "https://api.telegram.org/file/bot" . TELEGRAM_BOT_TOKEN . "/" . $filePath;
                $download = nexa_telegram_download($dlUrl, 5000000);
                if ($download) {
                    $imagePart = ['inline_data' => [
                        'mime_type' => $download['mime_type'],
                        'data' => base64_encode($download['data']),
                    ]];
                    if (isset($message['caption'])) $text = trim((string)$message['caption']);
                    if (empty($text)) $text = "Describe or solve what is in this image.";
                }
            }
        }
    }

    function sendTelegramMessage($chatId, $text, $replyMarkup = null) {
        $url = TELEGRAM_API_URL . "sendMessage";
        $data = ['chat_id' => $chatId, 'text' => $text, 'parse_mode' => 'Markdown'];
        if ($replyMarkup) {
            $data['reply_markup'] = $replyMarkup;
        }
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        curl_exec($ch);
        curl_close($ch);
    }

    // Load Profile
    $userFile = TELEGRAM_DATA_DIR . $telegramUserId . '_profile.json';
    $userData = [];
    if (file_exists($userFile)) {
        $userData = json_decode(file_get_contents($userFile), true);
    }

    // Still optionally handle if someone somehow sends a contact, but don't force it
    if (isset($message['contact']['phone_number'])) {
        $userData['phone_number'] = $message['contact']['phone_number'];
        $userData['first_name'] = $telegramFirstName;
        $userData['last_name'] = $telegramLastName;
        $userData['username'] = $telegramUsername;
        @file_put_contents($userFile, json_encode($userData));
        
        $removeKeyboard = ['remove_keyboard' => true];
        sendTelegramMessage($chatId, json_decode('"\u2705"') . " *Phone Number Saved!*\n\nThanks for sharing. You can continue asking questions.", $removeKeyboard);
        exit;
    }

    if ($text === '/start') {
        // Just directly welcome them without requesting phone
        sendTelegramMessage($chatId, json_decode('"\ud83c\udf93"') . " *Welcome to NexA AI Tutor!*\n\nI am your personal AI study assistant, powered by NexZen Institute.\n\nYou can ask me math questions, science concepts, or essay help in English or Bengali.");
        exit;
    }

    if ($text === '/help') {
        sendTelegramMessage($chatId, "*What NexA can do:*\n" . json_decode('"\ud83d\udccf"') . " Solve math problems\n" . json_decode('"\ud83d\udd2c"') . " Explain science topics\n" . json_decode('"\ud83d\udc8a"') . " Nursing MCQ Practice");
        exit;
    }

    if ($text === '/clear') {
        $historyFile = TELEGRAM_DATA_DIR . $telegramUserId . '_history.json';
        if (file_exists($historyFile)) @unlink($historyFile);
        sendTelegramMessage($chatId, json_decode('"\u2728"') . " Chat history cleared! We are starting fresh.");
        exit;
    }

    if (empty($text) && !$imagePart) {
        sendTelegramMessage($chatId, "Please send a text message or a photo.");
        exit;
    }

    // Rate Limit Checks and Stats saving
    if (!isset($userData['daily_queries'])) $userData['daily_queries'] = 0;
    if (!isset($userData['last_reset'])) $userData['last_reset'] = date('Y-m-d');
    $userData['first_name'] = $telegramFirstName;
    $userData['last_name'] = $telegramLastName;
    $userData['username'] = $telegramUsername;
    $userData['last_active'] = date('Y-m-d H:i:s');
    
    if ($userData['last_reset'] !== date('Y-m-d')) {
        $userData['daily_queries'] = 0;
        $userData['last_reset'] = date('Y-m-d');
    }
    if ($userData['daily_queries'] >= TELEGRAM_DAILY_LIMIT) {
        sendTelegramMessage($chatId, json_decode('"\u26a0\ufe0f"') . " *Daily Limit Reached*");
        exit;
    }
    $userData['daily_queries']++;
    @file_put_contents($userFile, json_encode($userData));

    $historyFile = TELEGRAM_DATA_DIR . $telegramUserId . '_history.json';
    $history = [];
    if (file_exists($historyFile)) {
        $history = json_decode(file_get_contents($historyFile), true);
    }
    
    // Build user parts array
    $userParts = [];
    if (!empty($text)) {
        $userParts[] = ['text' => $text];
    }
    if ($imagePart) {
        $userParts[] = $imagePart;
        // Don't save large inline_data images into the chat history JSON forever
        $history[] = ['role' => 'user', 'parts' => [['text' => "[Image uploaded] " . $text]]];
    } else {
        $history[] = ['role' => 'user', 'parts' => $userParts];
    }

    $systemInstruction = "You are NexA, a powerful AI study tutor developed by NexZen Group in West Bengal.\nRULES:\n1. Always reply completely in Bengali unless explicitly requested by the user to use English.\n2. Be warm, encouraging, student-friendly.\n3. For math/science/images: provide clear step-by-step explanations.\n4. Keep answers focused.\n5. Do NOT repeat the question.\n6. Sign off every single message with exactly this text on a new line: — NexA " . json_decode('"\ud83c\udf93"');

    // For the immediate API request, we must pass the actual image inline_data, so we construct a temporary history payload
    $requestHistory = $history;
    if ($imagePart) {
        array_pop($requestHistory); // Remove the placeholder we just added to the file-saved history
        $requestHistory[] = ['role' => 'user', 'parts' => $userParts]; // Add the actual image payload for the API
    }

    $url = 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_MODEL . ':generateContent?key=' . GEMINI_API_KEY;
    $data = [
        'system_instruction' => ['parts' => [['text' => $systemInstruction]]],
        'contents' => $requestHistory,
        'generationConfig' => ['maxOutputTokens' => 700, 'temperature' => 0.4]
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_TIMEOUT, 25);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $result = json_decode($response, true);
    $aiReply = null;

    if ($httpCode === 200 && isset($result['candidates'][0]['content']['parts'][0]['text'])) {
        $aiReply = $result['candidates'][0]['content']['parts'][0]['text'];
    } else {
        // Log the exact Gemini error for debugging
        nexa_telegram_log('provider_error', [
            'provider' => 'gemini',
            'http_code' => $httpCode,
        ]);

        // DeepSeek Fallback
        $dsMessages = [['role' => 'system', 'content' => $systemInstruction]];
        foreach ($requestHistory as $turn) {
            $role = ($turn['role'] === 'model') ? 'assistant' : 'user';
            $txt = '';
            foreach ($turn['parts'] ?? [] as $part) {
                if (isset($part['text'])) $txt .= $part['text'];
            }
            if ($txt) $dsMessages[] = ['role' => $role, 'content' => $txt];
        }
        $dsCh = curl_init('https://api.deepseek.com/chat/completions');
        curl_setopt_array($dsCh, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . DEEPSEEK_API_KEY],
            CURLOPT_POSTFIELDS => json_encode(['model' => 'deepseek-chat', 'messages' => $dsMessages, 'max_tokens' => 700, 'temperature' => 0.4]),
            CURLOPT_TIMEOUT => 25
        ]);
        $dsResponse = curl_exec($dsCh);
        $dsCode = curl_getinfo($dsCh, CURLINFO_HTTP_CODE);
        curl_close($dsCh);
        $dsResult = json_decode($dsResponse, true);
        if ($dsCode === 200 && isset($dsResult['choices'][0]['message']['content'])) {
            $aiReply = $dsResult['choices'][0]['message']['content'];
        }
    }

    if (!$aiReply) {
        $aiReply = "Sorry, I encountered an error. Please try again later.";
    }
    // Clean up markdown bold to Telegram bold
    $aiReply = preg_replace('/\*\*(.*?)\*\*/', '*$1*', $aiReply);
    $aiReply = str_replace(['\\[', '\\]', '\\(', '\\)'], '', $aiReply);
    
    $history[] = ['role' => 'model', 'parts' => [['text' => $aiReply]]];
    if (count($history) > MAX_HISTORY_MESSAGES * 2) {
        $history = array_slice($history, -(MAX_HISTORY_MESSAGES * 2));
    }
    @file_put_contents($historyFile, json_encode($history));

    sendTelegramMessage($chatId, $aiReply);

} catch (\Throwable $e) {
    nexa_telegram_log('processing_error', ['type' => get_class($e)]);
}
