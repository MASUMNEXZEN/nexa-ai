<?php
/**
 * NexA AI — DeepSeek Fallback Logic
 * Seamlessly handles requests when Gemini API is unavailable or rate-limited.
 */

function fallback_to_deepseek($body, $qHash, $userQuestion, $userEmail, $cacheDb, $maxTokens = 500) {
    if (!defined('DEEPSEEK_API_KEY') || empty(DEEPSEEK_API_KEY)) {
        return false;
    }

    // --- 1. Prepare OpenAI-style messages ---
    $messages = [];
    
    // Add system instruction if present
    if (isset($body['system_instruction']['parts'][0]['text'])) {
        $messages[] = ['role' => 'system', 'content' => $body['system_instruction']['parts'][0]['text']];
    }

    // Add conversation history
    if (isset($body['contents']) && is_array($body['contents'])) {
        foreach ($body['contents'] as $turn) {
            $role = ($turn['role'] === 'model') ? 'assistant' : 'user';
            $txt = '';
            foreach ($turn['parts'] ?? [] as $part) {
                if (isset($part['text'])) $txt .= $part['text'];
                // Skip inline_data (images) as deepseek-chat doesn't support them natively here
            }
            if ($txt) {
                $messages[] = ['role' => $role, 'content' => $txt];
            }
        }
    }

    $dsPayload = [
        'model' => 'deepseek-chat',
        'messages' => $messages,
        'stream' => true,
        'max_tokens' => $maxTokens,
        'temperature' => 0.6
    ];

    $ch = curl_init('https://api.deepseek.com/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false, // We stream directly
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . DEEPSEEK_API_KEY
        ],
        CURLOPT_POSTFIELDS => json_encode($dsPayload),
        CURLOPT_TIMEOUT => 60,
        CURLOPT_WRITEFUNCTION => function($curl, $data) use (&$fullResponse, &$streamBuffer) {
            $length = strlen($data);
            $streamBuffer .= $data;
            
            // Standard OpenAI SSE format: data: {...}\n\n
            while (preg_match('/data: (\{.*?\})\n\n/', $streamBuffer, $matches, PREG_OFFSET_CAPTURE)) {
                $jsonStr = $matches[1][0];
                $pos = $matches[0][1];
                $len = strlen($matches[0][0]);
                $streamBuffer = substr($streamBuffer, $pos + $len);
                
                $json = json_decode($jsonStr, true);
                if ($json && isset($json['choices'][0]['delta']['content'])) {
                    $txt = $json['choices'][0]['delta']['content'];
                    $fullResponse .= $txt;
                    // Pack into Gemini-style candidate object for the frontend
                    $out = ['candidates' => [['content' => ['parts' => [['text' => $txt]]]]]];
                    echo "data: " . json_encode($out) . "\n\n";
                    if (ob_get_level() > 0) ob_flush();
                    flush();
                }
            }
            return $length;
        }
    ]);

    // Ensure we are in a stream context
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-store, no-cache, must-revalidate');
    header('X-Accel-Buffering: no'); // Important for Nginx

    $fullResponse = '';
    $streamBuffer = '';
    
    $success = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($success && $httpCode === 200) {
        // Output done signal
        echo "data: [DONE]\n\n";
        if (ob_get_level() > 0) ob_flush();
        flush();

        // Optional: Update usage stats ? (Input/Output tokens not easily parsed mid-stream for DS)
        // log_nexa_usage($userEmail, 'chat-fallback', 0, 0);

        // Bug Fix 4: Only cache if response is substantial and doesn't look like an error message
        // This prevents fallback error strings from being permanently served to future users
        $isErrorResponse = stripos($fullResponse, 'error') !== false && strlen($fullResponse) < 200;
        if (defined('NEXA_CHAT_RESPONSE_CACHE_ENABLED') && NEXA_CHAT_RESPONSE_CACHE_ENABLED && strlen($fullResponse) > 50 && !$isErrorResponse && $cacheDb && !empty($qHash)) {
            try {
                $cacheDb->prepare("INSERT OR IGNORE INTO cache_responses (q_hash, question, answer) VALUES (?, ?, ?)")
                        ->execute([$qHash, $userQuestion, $fullResponse]);
            } catch (Exception $e) {}
        }
        return true;
    }

    return false;
}
