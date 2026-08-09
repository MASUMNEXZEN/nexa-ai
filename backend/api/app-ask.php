<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('POST, OPTIONS');
require_once __DIR__ . '/deepseek-fallback.php';
require_once __DIR__ . '/bedrock-fallback.php';
require_once __DIR__ . '/db.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

$body = nexa_read_json_body(15000000, 'Invalid request body.');

if (!$body || !isset($body['contents'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body.']);
    exit;
}

$validationError = nexa_validate_ai_request($body);
if ($validationError !== null) {
    nexa_reject_json(400, $validationError);
}

// Identify user (App Mode) - Secure against fake devices by adding IP

$userEmail = nexa_guest_key();

$limitResult = check_nexa_limit($userEmail, true); // true = isAppMode
if (isset($limitResult['error'])) {
    http_response_code(429);
    echo json_encode(['error' => $limitResult['error']]);
    exit;
}
$remaining = $limitResult['remaining'];
$DAILY_LIMIT = $limitResult['limit'];

$userQuestionFull = "Unknown Query";
$parts = end($body['contents'])['parts'] ?? [];
foreach($parts as $p) { if(isset($p['text'])) { $userQuestionFull = $p['text']; break; } }
$userQuestion = strlen($userQuestionFull) > 100 ? substr($userQuestionFull, 0, 97) . '...' : $userQuestionFull;

$db = get_db();
if ($db) {
    $today = date('Y-m-d');
    $db->prepare("INSERT OR IGNORE INTO daily_stats (date, count) VALUES (?, 0)")->execute([$today]);
    $db->prepare("UPDATE daily_stats SET count = count + 1 WHERE date = ?")->execute([$today]);

    $db->prepare("INSERT INTO recent_queries (user_email, query) VALUES (?, ?)")->execute([$userEmail, $userQuestion]);
    $db->exec("DELETE FROM recent_queries WHERE id NOT IN (SELECT id FROM recent_queries ORDER BY id DESC LIMIT 100)");

    // Subject Analytics
    $lowerQ = strtolower($userQuestion);
    $subject = 'General';
    if (preg_match('/\b(math|maths|calculate|equation|algebra|calculus|geometry|trig|integral|derivative|sum|subtract|multiply|divide|fraction|number|solve|value of)\b/', $lowerQ)) { $subject = 'Math'; }
    elseif (preg_match('/\b(science|physics|chemistry|biology|atom|molecule|cell|gravity|force|energy|dna|protein|reaction|velocity|mass|acid|base)\b/', $lowerQ)) { $subject = 'Science'; }
    elseif (preg_match('/\b(nursing|anatomy|physiology|disease|medication|patient|symptom|diagnosis|treatment|dosage|blood|heart|lung|vein|artery)\b/', $lowerQ)) { $subject = 'Nursing'; }
    elseif (preg_match('/\b(code|programming|php|javascript|html|css|python|java|bug|error|function|array|object)\b/', $lowerQ)) { $subject = 'Programming'; }

    $db->prepare("INSERT OR IGNORE INTO analytics_subject (subject, count) VALUES (?, 0)")->execute([$subject]);
    $db->prepare("UPDATE analytics_subject SET count = count + 1 WHERE subject = ?")->execute([$subject]);
}

// Hash uses FULL un-truncated question to prevent cross-question collisions (bug fix)
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
    while (ob_get_level() > 0) ob_end_clean();
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('Connection: keep-alive');
    header('X-Accel-Buffering: no');
    
    echo "data: " . json_encode(['type' => 'meta', 'remaining' => $remaining, 'limit' => $DAILY_LIMIT, 'cached' => true]) . "\n\n";
    
    $chunks = explode(' ', $cachedResponse);
    foreach ($chunks as $chunk) {
        $part = ['candidates' => [['content' => ['parts' => [['text' => $chunk . ' ']]]]]];
        echo "data: " . json_encode($part) . "\n\n";
        flush();
        usleep(8000); 
    }
    echo "data: [DONE]\n\n";
    flush();
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
        'maxOutputTokens'  => $maxTokens,
        'temperature'      => 0.1,
        'topP'             => 0.8,
        'thinkingConfig'   => ['thinkingBudget' => 0], // Disable thinking tokens: faster + no extra billing
    ]
];
$langPolicy = "Rules: Be concise. Use bullets. Cite facts accurately. CRITICAL: Respond exclusively in intact Bengali with an exam-oriented scholarly tone. Output English ONLY for English grammar/subject questions or if explicitly demanded. Never fabricate medical doses or exam stats.";
if (isset($body['system_instruction']['parts'][0]['text'])) {
    $geminiPayload['systemInstruction'] = [
        'parts' => [['text' => $langPolicy . ' ' . $body['system_instruction']['parts'][0]['text']]]
    ];
} else {
    $geminiPayload['systemInstruction'] = [
        'parts' => [['text' => $langPolicy]]
    ];
}

while (ob_get_level() > 0) ob_end_clean();
header('Content-Type: text/event-stream');
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Connection: keep-alive');
header('X-Accel-Buffering: no');

echo "data: " . json_encode(['type' => 'meta', 'remaining' => $remaining, 'limit' => $DAILY_LIMIT]) . "\n\n";
flush();

$usageData = ['i' => 0, 'o' => 0];
$streamBuffer = '';
$fullTextAccum = '';

$ch = curl_init($geminiUrl);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => false,
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($geminiPayload),
    CURLOPT_TIMEOUT => 120,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_WRITEFUNCTION => function($curl, $data) use (&$usageData, &$streamBuffer, &$fullTextAccum) {
        echo $data;
        $length = strlen($data);
        $streamBuffer .= $data;
        
        while (preg_match('/\r?\n\r?\n/', $streamBuffer, $matches, PREG_OFFSET_CAPTURE)) {
            $pos = $matches[0][1];
            $len = strlen($matches[0][0]);
            $chunk = substr($streamBuffer, 0, $pos);
            $streamBuffer = substr($streamBuffer, $pos + $len);
            
            $lines = preg_split('/\r?\n/', $chunk);
            foreach($lines as $line) {
                if(strpos($line, 'data: ') === 0) {
                    $plain = trim(substr($line, 6));
                    if ($plain === '[DONE]') continue;
                    $json = json_decode($plain, true);
                    if ($json) {
                        if (isset($json['usageMetadata'])) {
                            $usageData['i'] = $json['usageMetadata']['promptTokenCount'] ?? 0;
                            $usageData['o'] = $json['usageMetadata']['candidatesTokenCount'] ?? 0;
                        }
                        $candidate = $json['candidates'][0] ?? [];
                        $txt = $candidate['content']['parts'][0]['text'] ?? '';
                        $fullTextAccum .= $txt;
                    }
                }
            }
        }
        flush();
        return $length;
    }
]);

nexa_configure_curl($ch);

curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($httpCode === 200) {
    log_nexa_usage($userEmail, 'app-chat', $usageData['i'], $usageData['o']);
    
    // Save to Cache DB
    $isImageRequest = false;
    foreach ($body['contents'] as $c) {
        foreach ($c['parts'] ?? [] as $p) {
            if (isset($p['inline_data'])) { $isImageRequest = true; break 2; }
        }
    }
    // Only cache if response is substantial and does not look like an error (bug fix)
    $isErrorResponse = stripos($fullTextAccum, 'error') !== false && strlen($fullTextAccum) < 200;
    if (NEXA_CHAT_RESPONSE_CACHE_ENABLED && !$isImageRequest && strlen($fullTextAccum) > 50 && !$isErrorResponse && isset($cacheDb) && $cacheDb) {
        try {
            $cacheDb->prepare("INSERT OR IGNORE INTO cache_responses (q_hash, question, answer) VALUES (?, ?, ?)")
                    ->execute([$qHash, $userQuestionFull, $fullTextAccum]);
        } catch (Exception $e) {}
    }
} else {
    $fallbackResult = fallback_to_deepseek($body, $qHash, $userQuestion, $userEmail, $cacheDb, $maxTokens);
    if (!$fallbackResult) {
        if (defined('NEXA_APP_ENV') && NEXA_APP_ENV === 'local') {
            $previewText = (defined('GEMINI_API_KEY') && GEMINI_API_KEY)
                ? 'Gemini is configured, but this local server could not reach Google AI. Check network or TLS access, then try again.'
                : 'Local guest chat is connected. Add a Gemini API key to enable live AI answers.';
            echo "data: " . json_encode(['candidates' => [['content' => ['parts' => [['text' => $previewText]]]]]]) . "\n\n";
            echo "data: [DONE]\n\n";
            flush();
        } else {
            echo "data: " . json_encode(['error' => 'API currently unavailable. Please try again.']) . "\n\n";
        }
    }
}

exit;

