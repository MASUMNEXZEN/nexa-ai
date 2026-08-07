<?php
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// Write all PHP errors to a log file (bypasses Apache ErrorDocument 500 redirect)
$logFile = __DIR__ . '/../data/quiz-error.log';
ini_set('log_errors', 1);
ini_set('error_log', $logFile);
ini_set('display_errors', 0);
set_error_handler(function($errno, $errstr, $errfile, $errline) use ($logFile) {
    file_put_contents($logFile, date('[Y-m-d H:i:s] ') . "PHP Error[$errno]: $errstr in $errfile:$errline\n", FILE_APPEND);
});
register_shutdown_function(function() use ($logFile) {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        file_put_contents($logFile, date('[Y-m-d H:i:s] ') . "FATAL: {$e['message']} in {$e['file']}:{$e['line']}\n", FILE_APPEND);
    }
});

require_once __DIR__ . '/config.php';
if (session_status() === PHP_SESSION_NONE) { session_start(); }
require_once __DIR__ . '/db.php';

$mainDb = get_db();
$cacheDb = get_cache_db();

if (!$mainDb || !$cacheDb) {
    echo json_encode(['error' => 'Database unavailable.']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['error' => 'Only POST allowed.']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!$body || !isset($body['action'])) {
    echo json_encode(['error' => 'Invalid request body.']);
    exit;
}

// Ensure Hostinger does not kill long-running Gemini API calls
set_time_limit(120);

$userEmail = null;
if (!empty($_SESSION['user_email'])) {
    $userEmail = $_SESSION['user_email'];
} elseif (!empty($_COOKIE['nexa_token'])) {
    $tokenUser = nexa_resolve_persistent_token($mainDb, $_COOKIE['nexa_token']);
    $userEmail = $tokenUser['email'] ?? null;
    if ($userEmail) $_SESSION['user_email'] = $userEmail;
}

if (!$userEmail && isset($_SERVER['HTTP_X_DEVICE_ID'])) {
    $userEmail = nexa_guest_key();

}

if (!$userEmail) {
    $userEmail = 'anonymous-' . preg_replace('/[^a-zA-Z0-9]/', '', $_SERVER['REMOTE_ADDR'] ?? 'ip');
}

$isAppMode = (strpos($userEmail, 'appmode-') === 0);

$remaining = 100;
$maxLimit = 100;
$count = 0;

if ($body['action'] === 'get_quiz') {
    $limitResult = check_nexa_limit($userEmail, $isAppMode);
    if (isset($limitResult['error'])) {
        http_response_code(429);
        echo json_encode(['error' => $limitResult['error']]);
        exit;
    }
    $remaining = $limitResult['remaining'];
    $maxLimit = $limitResult['limit'];
    $count = $limitResult['count'];
}

if ($body['action'] === 'get_quiz') {
    $topic = trim($body['topic'] ?? 'General Knowledge');
    $difficulty = trim($body['difficulty'] ?? 'Moderate');
    $lang = in_array($body['lang'] ?? '', ['Bengali', 'English']) ? $body['lang'] : 'Bengali';
    $examType = trim($body['exam_type'] ?? ''); // e.g. "ANM/GNM", "WBJEE", "WBP"
    if (!$examType) $examType = 'General Academic';

    // Force English language for English subject topics regardless of user preference
    $topicLower = strtolower($topic);
    if (preg_match('/\b(english|narration|voice change|grammar|vocabulary|synonym|antonym|preposition|idiom|phrase|comprehension|clause|tense|sentence|spell|spelling|verb|noun|adjective|adverb|pronoun|conjunction|interjection)\b/', $topicLower)) {
        $lang = 'English';
    }

    if (stripos($examType, 'ANM') !== false || stripos($examType, 'GNM') !== false) {
        $examContext = "You are an expert examiner for ANM/GNM nursing entrance exams in West Bengal. Questions MUST follow the ANM/GNM syllabus: Anatomy & Physiology, Microbiology, Nutrition, Child Health Nursing, Community Health Nursing, Pharmacology, and First Aid. Keep all questions within the nursing syllabus only.";
    } elseif (stripos($examType, 'JENPAS') !== false) {
        $examContext = "You are an expert examiner for JENPAS UG (West Bengal health sciences entrance). Questions MUST cover: Biology (Botany + Zoology), Physics, Chemistry, and English as per JENPAS UG syllabus. Clinical topics like Anatomy, Physiology, and Nursing sciences are also relevant.";
    } elseif (stripos($examType, 'WBJEE') !== false) {
        $examContext = "You are an expert examiner for WBJEE (West Bengal Joint Entrance Examination). Questions MUST follow the WBJEE syllabus: Mathematics (Algebra, Trigonometry, Coordinate Geometry, Calculus, Statistics), Physics (Mechanics, Optics, Thermodynamics, Electrodynamics), Chemistry (Physical, Organic & Inorganic Chemistry). Strictly no non-engineering topics.";
    } elseif (stripos($examType, 'WBP') !== false || stripos($examType, 'KP') !== false) {
        $examContext = "You are an expert examiner for WBP/KP Police recruitment exams (West Bengal). Questions MUST follow WBP syllabus: General Knowledge, Indian History & Polity, West Bengal GK, Geography, Arithmetic, Reasoning, and English Grammar.";
    } elseif (stripos($examType, 'WBCS') !== false) {
        $examContext = "You are an expert examiner for WBCS (West Bengal Civil Service) Preliminary Exam. Questions MUST follow WBCS syllabus: Indian History, Indian & WB Geography, Indian Polity, Economy, Science & Technology, English, and Current Affairs.";
    } elseif (stripos($examType, 'SSC') !== false || stripos($examType, 'RRB') !== false) {
        $examContext = "You are an expert examiner for SSC/RRB competitive exams. Questions MUST cover: General Intelligence & Reasoning, General Awareness, Quantitative Aptitude, and English Comprehension as per the latest SSC/RRB syllabus.";
    } elseif (stripos($examType, 'Madhyamik') !== false || stripos($examType, 'HS') !== false) {
        $examContext = "You are an expert examiner following the West Bengal Board (WBBSE/WBCHSE) Madhyamik or Higher Secondary curriculum. Questions MUST strictly follow the WB Board syllabus for the given subject and class level.";
    } elseif ($examType) {
        $examContext = "You are an expert examiner for $examType. Questions MUST be strictly relevant to the $examType syllabus and curriculum.";
    } else {
        $examContext = "You are an expert Indian academic examiner creating high-quality MCQs. Questions must be relevant to Indian academic curricula.";
    }


    $stmt = $cacheDb->prepare("
        SELECT * FROM quizzes 
        WHERE topic LIKE ? AND difficulty = ? AND lang = ?
        AND id NOT IN (SELECT quiz_id FROM user_quiz_history WHERE user_email = ?)
        ORDER BY RANDOM() LIMIT 1
    ");
    $stmt->execute(['%'.$topic.'%', $difficulty, $lang, $userEmail]);
    $cachedQuiz = $stmt->fetch();

    if ($cachedQuiz) {
        $cacheDb->prepare("INSERT INTO user_quiz_history (user_email, quiz_id, user_answer, is_correct) VALUES (?, ?, '', 0)")
               ->execute([$userEmail, $cachedQuiz['id']]);

        echo json_encode([
            'success'   => true,
            'source'    => 'db',
            'quiz'      => $cachedQuiz,
            'remaining' => $remaining,
            'limit'     => $maxLimit
        ]);
        exit;
    }

    $langInstruction = "Write ENTIRELY in " . ($lang === 'Bengali' ? 'Bengali (বাংলা)' : 'English');
    $sharedPrompt    = "Generate 3 unique MCQ questions about '{$topic}' at '{$difficulty}' difficulty for students preparing for {$examType}. Return a JSON array of exactly 3 objects, each with keys: question, sub_topic, opt_a, opt_b, opt_c, opt_d, correct_answer (must be A/B/C/D), explanation.";
    $sharedSystem    = "{$examContext}\nRULES:\n1. {$langInstruction}\n2. Difficulty: {$difficulty} appropriate for {$examType}.\n3. Strictly relevant to '{$topic}'.\n4. Return ONLY a JSON array - no markdown fences, no extra text.";

    session_write_close(); // Unlock session before long API wait
    set_time_limit(65);    // Give PHP enough time for slower providers

    // HELPER: Parse OpenAI-compatible response into question array
    $qArray     = null;
    $source     = '';
    $usedTokens = [0, 0];

    /**
     * Call an OpenAI-compatible chat endpoint (Groq / DeepSeek / etc.)
     * Returns parsed question array or null on failure.
     */
    function callOpenAICompatible($url, $apiKey, $model, $system, $prompt, $timeout = 15) {
        $payload = [
            'model'       => $model,
            'messages'    => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user',   'content' => $prompt],
            ],
            'temperature' => 0,
            'max_tokens'  => 3000,
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        nexa_configure_curl($ch);
        $resp = curl_exec($ch);
        $err  = curl_error($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!$resp || $err || $code !== 200) {
            return ['error' => "HTTP {$code}: " . ($err ?: substr($resp, 0, 300)), 'questions' => null, 'tokens' => [0, 0]];
        }
        $data    = json_decode($resp, true);
        $rawText = $data['choices'][0]['message']['content'] ?? '';
        // Strip markdown fences
        $rawText = preg_replace('/^```(?:json)?\s*/i', '', trim($rawText));
        $rawText = preg_replace('/```\s*$/i', '', trim($rawText));
        $rawText = trim($rawText);

        $decoded = json_decode($rawText, true);
        $qArr    = null;
        if (is_array($decoded) && !empty($decoded)) {
            if (array_key_exists(0, $decoded)) {
                $qArr = $decoded;
            } else {
                foreach ($decoded as $v) {
                    if (is_array($v) && array_key_exists(0, $v)) { $qArr = $v; break; }
                }
            }
        }
        $tokens = [
            $data['usage']['prompt_tokens']     ?? 0,
            $data['usage']['completion_tokens'] ?? 0,
        ];
        return ['error' => null, 'questions' => $qArr, 'tokens' => $tokens, 'raw' => $rawText];
    }

    if (!$qArray && DEEPSEEK_API_KEY) {
        $dsResult = callOpenAICompatible(
            'https://api.deepseek.com/chat/completions',
            DEEPSEEK_API_KEY,
            'deepseek-chat',
            $sharedSystem,
            $sharedPrompt,
            45  // DeepSeek is slower, give it room
        );
        if ($dsResult['questions']) {
            $qArray     = $dsResult['questions'];
            $usedTokens = $dsResult['tokens'];
            $source     = 'deepseek';
        } else {
            file_put_contents(DATA_DIR . 'quiz-error.log',
                date('[Y-m-d H:i:s] ') . "DeepSeek failed: {$dsResult['error']}\n", FILE_APPEND);
        }
    }

    // TIER 2: GEMINI 2.5-flash (fallback, thinkingBudget=0)
    if (is_null($qArray)) {
        $source       = 'gemini';
        $geminiUrl    = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash:generateContent?key=' . GEMINI_API_KEY;
        $geminiPayload = [
            'contents'          => [['role' => 'user', 'parts' => [['text' => $sharedPrompt]]]],
            'systemInstruction' => ['parts' => [['text' => $sharedSystem]]],
            'generationConfig'  => [
                'responseMimeType' => 'application/json',
                'maxOutputTokens'  => 3000,
                'temperature'      => 0,
                'thinkingConfig'   => ['thinkingBudget' => 0],
                'responseSchema'   => [
                    'type'  => 'ARRAY',
                    'items' => [
                        'type'       => 'OBJECT',
                        'properties' => [
                            'question'       => ['type' => 'STRING'],
                            'sub_topic'      => ['type' => 'STRING'],
                            'opt_a'          => ['type' => 'STRING'],
                            'opt_b'          => ['type' => 'STRING'],
                            'opt_c'          => ['type' => 'STRING'],
                            'opt_d'          => ['type' => 'STRING'],
                            'correct_answer' => ['type' => 'STRING'],
                            'explanation'    => ['type' => 'STRING'],
                        ],
                        'required' => ['question','sub_topic','opt_a','opt_b','opt_c','opt_d','correct_answer','explanation']
                    ]
                ]
            ]
        ];

        $ch = curl_init($geminiUrl);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
            CURLOPT_POSTFIELDS     => json_encode($geminiPayload),
            CURLOPT_TIMEOUT        => 55,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        nexa_configure_curl($ch);
        $geminiResp = curl_exec($ch);
        $geminiErr  = curl_error($ch);
        $geminiCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($geminiResp === false || $geminiErr) {
            http_response_code(500);
            echo json_encode(['error' => 'Network error reaching AI.', 'curl_error' => $geminiErr]);
            exit;
        }
        if ($geminiCode !== 200) {
            $errBody = json_decode($geminiResp, true);
            http_response_code(500);
            echo json_encode(['error' => 'AI API error.', 'details' => $errBody['error']['message'] ?? $geminiResp, 'http_code' => $geminiCode]);
            exit;
        }

        $gemResData = json_decode($geminiResp, true);
        if (!$gemResData || !isset($gemResData['candidates'][0]['content']['parts'][0]['text'])) {
            http_response_code(500);
            echo json_encode(['error' => 'Empty Gemini response.', 'raw' => substr($geminiResp, 0, 300)]);
            exit;
        }

        $gemUsage   = $gemResData['usageMetadata'] ?? [];
        $usedTokens = [$gemUsage['promptTokenCount'] ?? 0, $gemUsage['candidatesTokenCount'] ?? 0];

        $gemRawText = $gemResData['candidates'][0]['content']['parts'][0]['text'];
        $gemRawText = preg_replace('/^```(?:json)?\s*/i', '', trim($gemRawText));
        $gemRawText = preg_replace('/```\s*$/i', '', trim($gemRawText));
        
        // Log Gemini raw output explicitly if we suspect it's failing
        $qArray = json_decode($gemRawText, true);

        if (!is_array($qArray) || empty($qArray)) {
            file_put_contents(DATA_DIR . 'debug_gem_' . time() . '.txt', $gemRawText);
            http_response_code(500);
            echo json_encode(['error' => 'Malformed Gemini response.', 'raw' => substr($gemRawText, 0, 300)]);
            exit;
        }
    }


    log_nexa_usage($userEmail, 'quiz_' . $source, $usedTokens[0], $usedTokens[1]);

    $insertQ  = $cacheDb->prepare("INSERT INTO quizzes (topic, difficulty, lang, question, opt_a, opt_b, opt_c, opt_d, correct_answer, explanation, sub_topic) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $checkQ   = $cacheDb->prepare("SELECT id FROM quizzes WHERE question = ? LIMIT 1");
    $firstQuiz = null;

    foreach ($qArray as $q) {
        if (empty($q['question'])) continue;
        $checkQ->execute([$q['question']]);
        if ($checkQ->fetch()) continue;
        try {
            $insertQ->execute([$topic, $difficulty, $lang,
                $q['question'], $q['opt_a'], $q['opt_b'], $q['opt_c'], $q['opt_d'],
                strtoupper($q['correct_answer']), $q['explanation'], $q['sub_topic'] ?? $topic]);
            if (!$firstQuiz) {
                $q['id']         = $cacheDb->lastInsertId();
                $q['topic']      = $topic;
                $q['difficulty'] = $difficulty;
                $q['lang']       = $lang;
                $firstQuiz       = $q;
            }
        } catch (Exception $e) {}
    }

    if ($firstQuiz) {
        $cacheDb->prepare("INSERT INTO user_quiz_history (user_email, quiz_id, user_answer, is_correct) VALUES (?, ?, '', 0)")
                ->execute([$userEmail, $firstQuiz['id']]);
    } else {
        $q = $qArray[0];
        $firstQuiz = [
            'id'             => 0, 'topic' => $topic, 'difficulty' => $difficulty, 'lang' => $lang,
            'question'       => $q['question'],
            'opt_a'          => $q['opt_a'],          'opt_b' => $q['opt_b'],
            'opt_c'          => $q['opt_c'],          'opt_d' => $q['opt_d'],
            'correct_answer' => strtoupper($q['correct_answer']),
            'explanation'    => $q['explanation'],
            'sub_topic'      => $q['sub_topic'] ?? $topic,
        ];
    }

    echo json_encode([
        'success'   => true,
        'source'    => $source,   // 'deepseek' or 'gemini'
        'quiz'      => $firstQuiz,
        'remaining' => $remaining,
        'limit'     => $maxLimit,
    ]);
    exit;
}

if ($body['action'] === 'submit_answer') {
    $quizId = (int)($body['quiz_id'] ?? 0);
    $correct = (int)($body['correct'] ?? 0);
    $userAns = $body['user_answer'] ?? ''; // Optional fallback
    if ($quizId > 0) {
        $cacheDb->prepare("UPDATE user_quiz_history SET is_correct = ?, user_answer = ? WHERE user_email = ? AND quiz_id = ?")
               ->execute([$correct, $userAns, $userEmail, $quizId]);
    }
    echo json_encode(['success' => true]);
    exit;
}

echo json_encode(['error' => 'Invalid action.']);


