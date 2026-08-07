<?php
// ALways return 200 OK immediately so Telegram doesn't retry/fail
http_response_code(200);

try {
    require_once __DIR__ . '/config.php';
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

    $content = file_get_contents("php://input");
    @file_put_contents(TELEGRAM_DATA_DIR . "log.txt", date("Y-m-d H:i:s") . " - " . $content . "\n", FILE_APPEND);
    
    if (!$content) exit;
    $update = json_decode($content, true);

    if (!$update || !isset($update['message'])) {
        exit;
    }

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
        $fileInfoUrl = TELEGRAM_API_URL . "getFile?file_id=" . $photo['file_id'];
        $fileInfo = @json_decode(@file_get_contents($fileInfoUrl), true);
        if (isset($fileInfo['result']['file_path'])) {
            $dlUrl = "https://api.telegram.org/file/bot" . TELEGRAM_BOT_TOKEN . "/" . $fileInfo['result']['file_path'];
            $imgData = @file_get_contents($dlUrl);
            if ($imgData) {
                $imagePart = ['inline_data' => ['mime_type' => 'image/jpeg', 'data' => base64_encode($imgData)]];
                if (isset($message['caption'])) $text = trim($message['caption']);
                if (empty($text)) $text = "Describe or solve what is in this image.";
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
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
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
        @file_put_contents(TELEGRAM_DATA_DIR . 'gemini_error.log',
            date('Y-m-d H:i:s') . " HTTP:{$httpCode} " . $response . "\n", FILE_APPEND);

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
    @file_put_contents(__DIR__ . '/../data/telegram/crash.log', date('Y-m-d H:i:s') . ' - ' . $e->getMessage() . "\n", FILE_APPEND);
}
