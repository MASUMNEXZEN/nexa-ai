register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true) && function_exists('nexa_log_event')) {
        nexa_log_event('chat_fatal_error', [
            'severity' => (int)$e['type'],
            'file' => basename((string)$e['file']),
            'line' => (int)$e['line'],
        ]);
    }
});
try {
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('POST, OPTIONS');
require_once __DIR__ . '/deepseek-fallback.php';
require_once __DIR__ . '/bedrock-fallback.php';

header('Content-Type: application/json; charset=utf-8');

// Handle preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Only allow POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$body = nexa_read_json_body(15000000, 'Invalid request body.');

require_once __DIR__ . '/db.php';

nexa_start_session();
$userEmail = null;
$isAppMode = !empty($body['app_mode']);

if ($isAppMode) {
    $userEmail = nexa_guest_key();

} elseif (!empty($_SESSION['user_email'])) {
    $userEmail = $_SESSION['user_email'];
}

$pdo = get_db();
if (!$userEmail && $pdo) {
    // Restore from persistent cookie token via DB lookup
    if ($pdo && !empty($_COOKIE['nexa_token'])) {
        $row = nexa_resolve_persistent_token($pdo, $_COOKIE['nexa_token']);
        if ($row) { $userEmail = $row['email']; $_SESSION['user_email'] = $userEmail; }
    }
}

if (!$userEmail) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized. Please log in.']);
    exit;
}

// Calculate IP
$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
    $clientIp = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0];
}
$clientIp = preg_replace('/[^a-zA-Z0-9\.\:]/', '_', trim($clientIp));

$limitResult = check_nexa_limit($userEmail, $isAppMode);
if (isset($limitResult['error'])) {
    http_response_code(429);
    echo json_encode(['error' => $limitResult['error']]);
    exit;
}
$remaining = $limitResult['remaining'];
$DAILY_LIMIT = $limitResult['limit'];
$newCount = $limitResult['count'];
$today = date('Y-m-d');

// $body already parsed at top of file (before session check)

if (!$body || !isset($body['contents']) || !is_array($body['contents'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body.']);
    exit;
}

$validationError = nexa_validate_ai_request($body);
if ($validationError !== null) {
    nexa_reject_json(400, $validationError);
}

if ($pdo) {
    $pdo->prepare("INSERT OR IGNORE INTO daily_stats (date, count) VALUES (?, 0)")->execute([$today]);
    $pdo->prepare("UPDATE daily_stats SET count = count + 1 WHERE date = ?")->execute([$today]);
}

$userQuestionFull = "Unknown Query";
$parts = end($body['contents'])['parts'] ?? [];
foreach($parts as $p) { if(isset($p['text'])) { $userQuestionFull = $p['text']; break; } }
$userQuestion = strlen($userQuestionFull) > 100 ? substr($userQuestionFull, 0, 97) . '...' : $userQuestionFull;

$lowerQ = strtolower($userQuestion);
$subject = 'General';
if (preg_match('/\b(math|maths|calculate|equation|algebra|calculus|geometry|trig|integral|derivative|sum|subtract|multiply|divide|fraction|number|solve|value of)\b/', $lowerQ)) {
    $subject = 'Math';
} elseif (preg_match('/\b(science|physics|chemistry|biology|atom|molecule|cell|gravity|force|energy|dna|protein|reaction|velocity|mass|acid|base)\b/', $lowerQ)) {
    $subject = 'Science';
} elseif (preg_match('/\b(nursing|anatomy|physiology|disease|medication|patient|symptom|diagnosis|treatment|dosage|blood|heart|lung|vein|artery)\b/', $lowerQ)) {
    $subject = 'Nursing';
} elseif (preg_match('/\b(code|programming|php|javascript|html|css|python|java|bug|error|function|array|object)\b/', $lowerQ)) {
    $subject = 'Programming';
}

if ($pdo) {
    $pdo->prepare("INSERT OR IGNORE INTO analytics_subject (subject, count) VALUES (?, 0)")->execute([$subject]);
    $pdo->prepare("UPDATE analytics_subject SET count = count + 1 WHERE subject = ?")->execute([$subject]);

    $pdo->prepare("INSERT INTO recent_queries (user_email, query) VALUES (?, ?)")->execute([$userEmail, $userQuestion]);
    $pdo->exec("DELETE FROM recent_queries WHERE id NOT IN (SELECT id FROM recent_queries ORDER BY id DESC LIMIT 100)");
}

// Hash uses the FULL un-truncated question to prevent cross-question collisions (bug fix)
$normalizedQ = strtolower(trim($userQuestionFull));
$normalizedQ = (string)preg_replace('/\s+/', ' ', $normalizedQ);
$sysTextForCache = $body['system_instruction']['parts'][0]['text'] ?? '';
$qHash = md5(NEXA_RESPONSE_CACHE_VERSION . '|' . $normalizedQ . '|' . $sysTextForCache);

$cachedResponse = null;
$cacheDb = get_cache_db();
if (NEXA_CHAT_RESPONSE_CACHE_ENABLED && $cacheDb) {
    try {
        $stmt = $cacheDb->prepare("SELECT answer FROM cache_responses WHERE q_hash = ? LIMIT 1");
        $stmt->execute([$qHash]);
        $cachedResponse = $stmt->fetchColumn();
    } catch (Exception $e) {}
}

if ($cachedResponse) {
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');

    echo "data: " . json_encode(['type' => 'meta', 'remaining' => $remaining, 'limit' => $DAILY_LIMIT, 'cached' => true]) . "\n\n";
    // Simulate streaming for UI consistency
    $chunks = explode(' ', $cachedResponse);
    foreach ($chunks as $chunk) {
        $part = ['candidates' => [['content' => ['parts' => [['text' => $chunk . ' ']]]]]];
        echo "data: " . json_encode($part) . "\n\n";
        flush();
        usleep(8000); // reduced from 10ms
    }
    echo "data: [DONE]\n\n";
    exit;
}

// gemini-2.5-flash with thinkingBudget:0 = no thinking tokens billed
$geminiUrl = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:streamGenerateContent?alt=sse&key=' . GEMINI_API_KEY;

$hasImage = false;
foreach ($body['contents'] as $c) {
    foreach ($c['parts'] ?? [] as $p) {
        if (isset($p['inline_data'])) { $hasImage = true; break 2; }
    }
}
$maxTokens = $hasImage ? 800 : 350;

$geminiPayload = [
    'contents' => $body['contents'],
    'generationConfig' => [
        'maxOutputTokens' => $maxTokens,
        'temperature'     => 0.1,
        'topP'            => 0.8,
        'thinkingConfig'  => ['thinkingBudget' => 0], // Disable thinking tokens: faster + no extra billing
    ]
];


$chainOfThought = "Rules: Be concise. Use bullets. Cite facts accurately. CRITICAL: Respond exclusively in intact Bengali with an exam-oriented scholarly tone. Output English ONLY for English grammar/subject questions or if explicitly demanded. Never fabricate medical doses or exam stats.";

if (isset($body['system_instruction']['parts'][0]['text'])) {
    $userSysText = $body['system_instruction']['parts'][0]['text'];
    $geminiPayload['systemInstruction'] = [
        'parts' => [['text' => $chainOfThought . ' ' . $userSysText]]
    ];
} else {
    $geminiPayload['systemInstruction'] = [
        'parts' => [['text' => $chainOfThought]]
    ];
}

// Clean output buffers for SSE
while (ob_get_level() > 0) {
    if (!@ob_end_clean()) break;
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Connection: keep-alive');
header('X-Accel-Buffering: no'); 

echo "data: " . json_encode(['type' => 'meta', 'remaining' => $remaining, 'limit' => $DAILY_LIMIT]) . "\n\n";
flush();

// Unlock session file so other tabs don't freeze while waiting for Gemini
session_write_close();

$fullResponse = '';
$streamBuffer = '';
$usageData = ['i' => 1, 'o' => 1]; // non-zero default
$ch = curl_init($geminiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($geminiPayload),
    CURLOPT_TIMEOUT => 120,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_WRITEFUNCTION => function($curl, $data) use (&$fullResponse, &$streamBuffer, &$usageData) {
        $length = strlen($data);
        $streamBuffer .= $data;
        
        while (preg_match('/\\r?\\n\\r?\\n/', $streamBuffer, $matches, PREG_OFFSET_CAPTURE)) {
            $pos = $matches[0][1];
            $len = strlen($matches[0][0]);
            $chunk = substr($streamBuffer, 0, $pos);
            $streamBuffer = substr($streamBuffer, $pos + $len);
            
            $lines = preg_split('/\\r?\\n/', $chunk);
            foreach($lines as $line) {
                if(strpos($line, 'data: ') === 0) {
                    $plain = trim(substr($line, 6));
                    if ($plain === '[DONE]') {
                        echo "data: [DONE]\n\n";
                        flush();
                        continue;
                    }
                    $json = json_decode($plain, true);
                    if ($json) {
                        if (isset($json['usageMetadata'])) {
                            $usageData['i'] = $json['usageMetadata']['promptTokenCount'] ?? 0;
                            $usageData['o'] = $json['usageMetadata']['candidatesTokenCount'] ?? 0;
                        }
                        $candidate = $json['candidates'][0] ?? [];
                        $txt = $candidate['content']['parts'][0]['text'] ?? '';
                        $fullResponse .= $txt;
                        if ($txt !== '') {
                            $out = ['candidates' => [['content' => ['parts' => [['text' => $txt]]]]]];
                            echo "data: " . json_encode($out) . "\n\n";
                            flush();
                        }
                    }
                }
            }
        }
        return $length;
    }
]);

nexa_configure_curl($ch);

curl_exec($ch);

$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    log_nexa_usage($userEmail, 'chat', $usageData['i'], $usageData['o']);

    $isImageRequest = false;
    foreach ($body['contents'] as $c) {
        foreach ($c['parts'] ?? [] as $p) {
            if (isset($p['inline_data'])) { $isImageRequest = true; break 2; }
        }
    }
    // Only cache if response is substantial and does not look like an error (bug fix: prevents caching bad responses)
    $isErrorResponse = stripos($fullResponse, 'error') !== false && strlen($fullResponse) < 200;
    if (NEXA_CHAT_RESPONSE_CACHE_ENABLED && !$isImageRequest && strlen($fullResponse) > 50 && !$isErrorResponse && $cacheDb) {
        try {
            $cacheDb->prepare("INSERT OR IGNORE INTO cache_responses (q_hash, question, answer) VALUES (?, ?, ?)")
                    ->execute([$qHash, $userQuestionFull, $fullResponse]);
        } catch (Exception $e) {}
    }
} else {
    if (function_exists('nexa_log_event')) {
        nexa_log_event('chat_provider_failed', [
            'provider' => 'gemini',
            'status_class' => $httpCode > 0 ? 'http_failure' : 'network_failure',
            'status_code' => (int)$httpCode,
        ]);
    }
    $fallbackResult = fallback_to_deepseek($body, $qHash, $userQuestion, $userEmail, $cacheDb, $maxTokens);
    if (!$fallbackResult) {
        http_response_code($httpCode ?: 500);
        echo json_encode(['error' => 'API currently unavailable. Please try again.']);
    }
}

exit;
} catch (Throwable $crashEx) {
    if (function_exists('nexa_log_event')) {
        nexa_log_event('chat_unhandled_exception', [
            'exception_class' => get_class($crashEx),
            'file' => basename($crashEx->getFile()),
            'line' => $crashEx->getLine(),
        ]);
    }
    http_response_code(500);
    echo json_encode(['error' => 'Internal server error. Please try again.']);
    exit;
}
