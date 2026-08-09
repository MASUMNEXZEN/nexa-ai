<?php
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate');

// Handle CORS preflight
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

ini_set('display_errors', '0');
set_error_handler(function (int $severity, string $message, string $file, int $line): bool {
    nexa_log_event('quiz_php_warning', [
        'severity' => $severity,
        'file' => basename($file),
        'line' => $line,
    ]);
    return false;
});
register_shutdown_function(function (): void {
    $e = error_get_last();
    if ($e && in_array($e['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        nexa_log_event('quiz_fatal_error', [
            'severity' => (int)$e['type'],
            'file' => basename((string)$e['file']),
            'line' => (int)$e['line'],
        ]);
    }
});

require_once __DIR__ . '/config.php';
nexa_start_session();
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


/**
 * Normalize and reject malformed AI-generated quiz items before they reach
 * the database or the student. A weak item fails closed and triggers a fresh
 * generation on the next request.
 */
function nexa_quiz_key($value): string {
    $value = trim((string)$value);
    return function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
}

function nexa_normalize_quiz_item($item): ?array {
    if (!is_array($item)) return null;

    $question = trim((string)($item['question'] ?? ''));
    $options = [];
    foreach (['opt_a', 'opt_b', 'opt_c', 'opt_d'] as $key) {
        $value = trim((string)($item[$key] ?? ''));
        if ($value === '' || strlen($value) > 300) return null;
        $options[$key] = $value;
    }

    $explanation = trim((string)($item['explanation'] ?? ''));
    $subTopic = trim((string)($item['sub_topic'] ?? ''));
    if (strlen($question) < 8 || strlen($question) > 600 || strlen($explanation) < 20 || strlen($explanation) > 1200) return null;

    $optionKeys = array_keys($options);
    $optionValues = array_map('nexa_quiz_key', array_values($options));
    if (count(array_unique($optionValues)) !== 4) return null;

    $badContent = $question . ' ' . implode(' ', $options) . ' ' . $explanation;
    if (preg_match('/\b(lorem ipsum|test question|sample question|dummy question|option a|option b|undefined|null)\b/i', $badContent)) return null;

    $correct = strtoupper(trim((string)($item['correct_answer'] ?? '')));
    if (!in_array($correct, ['A', 'B', 'C', 'D'], true)) {
        $correctKey = nexa_quiz_key($item['correct_answer'] ?? '');
        $match = array_search($correctKey, $optionValues, true);
        if ($match === false) return null;
        $correct = $optionKeys[$match] === 'opt_a' ? 'A' : ($optionKeys[$match] === 'opt_b' ? 'B' : ($optionKeys[$match] === 'opt_c' ? 'C' : 'D'));
    }

    $item['question'] = $question;
    foreach ($options as $key => $value) $item[$key] = $value;
    $item['correct_answer'] = $correct;
    $item['explanation'] = $explanation;
    $item['sub_topic'] = $subTopic;
    return $item;
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


    // Never serve legacy rows in the normal product flow. Reuse is opt-in and
    // additionally scoped by topic, exam, language, difficulty, and version.
    // Every cache candidate passes the same quality gate as fresh model output.
    $cachedQuiz = null;
    if (NEXA_QUIZ_CACHE_ENABLED) {
        $stmt = $cacheDb->prepare("
            SELECT id, topic, difficulty, lang, question, opt_a, opt_b, opt_c, opt_d,
                   correct_answer, explanation, sub_topic, exam_type, cache_version, created_at
            FROM quizzes
            WHERE topic = ? AND difficulty = ? AND lang = ? AND exam_type = ?
              AND cache_version = ?
              AND id NOT IN (SELECT quiz_id FROM user_quiz_history WHERE user_email = ?)
            ORDER BY created_at DESC, id DESC
            LIMIT 24
        ");
        $stmt->execute([$topic, $difficulty, $lang, $examType, NEXA_QUIZ_CACHE_VERSION, $userEmail]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $candidate) {
            if (nexa_normalize_quiz_item($candidate)) {
                $cachedQuiz = $candidate;
                break;
            }
        }
        if (!$cachedQuiz) {
            nexa_log_event('quiz_cache_miss', ['reason' => 'no_quality_candidate']);
        }
    }

    if ($cachedQuiz) {
        $cacheDb->prepare("INSERT INTO user_quiz_history (user_email, quiz_id, user_answer, is_correct) VALUES (?, ?, '', 0)")
               ->execute([$userEmail, $cachedQuiz['id']]);

        echo json_encode([
            'success'   => true,
            'source'    => 'cache',
            'quiz'      => $cachedQuiz,
            'remaining' => $remaining,
            'limit'     => $maxLimit
        ]);
        exit;
    }

    $langInstruction = "Write ENTIRELY in " . ($lang === 'Bengali' ? 'Bengali' : 'English');
    $quizCacheVersion = NEXA_QUIZ_CACHE_VERSION;
    $variationSeed = bin2hex(random_bytes(6));

    // Exclude only current-version questions. Legacy rows are intentionally
    // invisible to the generation loop and cannot contaminate new content.
    $recentQuestions = [];
    $recentStmt = $cacheDb->prepare("SELECT question FROM quizzes WHERE topic = ? AND difficulty = ? AND lang = ? AND exam_type = ? AND cache_version = ? ORDER BY id DESC LIMIT 16");
    $recentStmt->execute([$topic, $difficulty, $lang, $examType, $quizCacheVersion]);
    foreach ($recentStmt->fetchAll(PDO::FETCH_COLUMN) as $priorQuestion) {
        $recentQuestions[] = '- ' . trim((string)$priorQuestion);
    }
    $exclusionText = $recentQuestions ? "\nDo not repeat these previously generated questions:\n" . implode("\n", $recentQuestions) : '';

    $sharedPrompt = "Create a fresh set of exactly 3 high-quality, exam-standard MCQs about '{$topic}' at '{$difficulty}' difficulty for {$examType}. Generation key: {$variationSeed}. Each question must test a meaningful concept, have one unambiguous correct answer, and use plausible distractors rather than obvious or silly options. Avoid generic trivia, filler, copied textbook openings, and repeated wording. Return a JSON array of exactly 3 objects with keys: question, sub_topic, opt_a, opt_b, opt_c, opt_d, correct_answer (must be A/B/C/D), explanation." . $exclusionText;
    $sharedSystem = "{$examContext}\nRULES:\n1. {$langInstruction}.\n2. Difficulty must be genuinely appropriate for {$examType}, not basic warm-up trivia.\n3. Stay strictly within '{$topic}' and the stated exam syllabus.\n4. Use clear, natural language and verify the answer before returning it.\n5. Return ONLY a JSON array - no markdown fences, no extra text.";

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
            return ['error' => true, 'questions' => null, 'tokens' => [0, 0]];
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
            nexa_log_event('quiz_provider_failed', [
                'provider' => 'deepseek',
                'status_class' => 'failure',
            ]);
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
            nexa_log_event('quiz_provider_failed', [
                'provider' => 'gemini',
                'status_class' => 'network_failure',
            ]);
            nexa_safe_error(503, 'PROVIDER_UNAVAILABLE', 'Quiz generation is temporarily unavailable. Please try again.');
        }
        if ($geminiCode !== 200) {
            nexa_log_event('quiz_provider_failed', [
                'provider' => 'gemini',
                'status_class' => 'http_failure',
                'status_code' => (int)$geminiCode,
            ]);
            nexa_safe_error(503, 'PROVIDER_UNAVAILABLE', 'Quiz generation is temporarily unavailable. Please try again.');
        }

        $gemResData = json_decode($geminiResp, true);
        if (!$gemResData || !isset($gemResData['candidates'][0]['content']['parts'][0]['text'])) {
            nexa_log_event('quiz_provider_invalid_response', [
                'provider' => 'gemini',
                'status_class' => 'missing_content',
            ]);
            nexa_safe_error(502, 'PROVIDER_INVALID_RESPONSE', 'The AI returned an incomplete quiz. Please try again.');
        }

        $gemUsage   = $gemResData['usageMetadata'] ?? [];
        $usedTokens = [$gemUsage['promptTokenCount'] ?? 0, $gemUsage['candidatesTokenCount'] ?? 0];

        $gemRawText = $gemResData['candidates'][0]['content']['parts'][0]['text'];
        $gemRawText = preg_replace('/^```(?:json)?\s*/i', '', trim($gemRawText));
        $gemRawText = preg_replace('/```\s*$/i', '', trim($gemRawText));
        
        $qArray = json_decode($gemRawText, true);

        if (!is_array($qArray) || empty($qArray)) {
            nexa_log_event('quiz_provider_invalid_response', [
                'provider' => 'gemini',
                'status_class' => 'malformed_json',
            ]);
            nexa_safe_error(502, 'PROVIDER_INVALID_RESPONSE', 'The AI returned an invalid quiz. Please try again.');
        }
    }



    // Validate every model item before persistence or display. Bad model output
    // must never become tomorrow's cached question.
    $validatedQuestions = [];
    $seenQuestionKeys = [];
    foreach (array_slice(is_array($qArray) ? $qArray : [], 0, 6) as $rawQuestion) {
        $normalizedQuestion = nexa_normalize_quiz_item($rawQuestion);
        if (!$normalizedQuestion) continue;
        $questionKey = nexa_quiz_key($normalizedQuestion['question']);
        if (isset($seenQuestionKeys[$questionKey])) continue;
        $seenQuestionKeys[$questionKey] = true;
        $validatedQuestions[] = $normalizedQuestion;
        if (count($validatedQuestions) === 3) break;
    }
    if (count($validatedQuestions) !== 3) {
        nexa_log_event('quiz_quality_rejected', [
            'provider' => $source,
            'status_class' => 'insufficient_valid_items',
        ]);
        nexa_safe_error(502, 'QUIZ_QUALITY_REJECTED', 'The AI returned an incomplete question set. Please try again.');
    }
    $qArray = $validatedQuestions;

    log_nexa_usage($userEmail, 'quiz_' . $source, $usedTokens[0], $usedTokens[1]);

    $insertQ  = $cacheDb->prepare("INSERT INTO quizzes (topic, difficulty, lang, question, opt_a, opt_b, opt_c, opt_d, correct_answer, explanation, sub_topic, exam_type, cache_version) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    $checkQ   = $cacheDb->prepare("SELECT id FROM quizzes WHERE question = ? LIMIT 1");
    $firstQuiz = null;

    foreach ($qArray as $q) {
        if (empty($q['question'])) continue;
        $checkQ->execute([$q['question']]);
        if ($checkQ->fetch()) continue;
        try {
            $insertQ->execute([$topic, $difficulty, $lang,
                $q['question'], $q['opt_a'], $q['opt_b'], $q['opt_c'], $q['opt_d'],
                $q['correct_answer'], $q['explanation'], $q['sub_topic'] ?? $topic, $examType, $quizCacheVersion]);
            if (!$firstQuiz) {
                $q['id']         = $cacheDb->lastInsertId();
                $q['topic']      = $topic;
                $q['difficulty'] = $difficulty;
                $q['lang']       = $lang;
                $q['exam_type']  = $examType;
                $q['cache_version'] = $quizCacheVersion;
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
            'correct_answer' => $q['correct_answer'],
            'explanation'    => $q['explanation'],
            'sub_topic'      => $q['sub_topic'] ?? $topic,
            'exam_type'      => $examType,
            'cache_version'  => $quizCacheVersion,
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
