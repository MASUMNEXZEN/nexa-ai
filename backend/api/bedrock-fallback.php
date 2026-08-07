<?php
/**
 * NexA AI — AWS Bedrock Proxy Fallback Logic
 * Connects to your external AWS Lambda function stream.
 */

function fallback_to_bedrock($body, $qHash, $userQuestion, $userEmail, $cacheDb, $maxTokens = 800) {
    if (!defined('BEDROCK_PROXY_URL') || empty(BEDROCK_PROXY_URL)) {
        return false;
    }

    // 1. Prepare standardized format for our Lambda Proxy
    $messages = [];
    
    // Add conversation history
    if (isset($body['contents']) && is_array($body['contents'])) {
        foreach ($body['contents'] as $turn) {
            $role = ($turn['role'] === 'model') ? 'assistant' : 'user';
            $txt = '';
            foreach ($turn['parts'] ?? [] as $part) {
                if (isset($part['text'])) $txt .= $part['text'];
            }
            if ($txt) {
                $messages[] = ['role' => $role, 'content' => $txt];
            }
        }
    }

    $systemText = '';
    if (isset($body['system_instruction']['parts'][0]['text'])) {
        $systemText = $body['system_instruction']['parts'][0]['text'];
    }

    $lambdaPayload = [
        'messages' => $messages,
        'system' => $systemText,
        'max_tokens' => $maxTokens,
        'temperature' => 0.4
    ];

    $ch = curl_init(BEDROCK_PROXY_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => false, // Stream directly out!
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . (defined('BEDROCK_PROXY_SECRET') ? BEDROCK_PROXY_SECRET : '')
        ],
        CURLOPT_POSTFIELDS => json_encode($lambdaPayload),
        CURLOPT_TIMEOUT => 60,
        CURLOPT_WRITEFUNCTION => function($curl, $data) use (&$fullResponse) {
            $length = strlen($data);
            
            // By design, our Lambda script already formats the returning stream chunks
            // exactly as `data: {"candidates":[{"content":{"parts":[{"text":"..."}]}}]}\n\n`
            // So we simply echo it directly to NexA's frontend without complex decoding in PHP!
            echo $data;
            if (ob_get_level() > 0) ob_flush();
            flush();

            // We can also extract the text purely to save to Cache if we want
            // Extracting from stream on the fly in PHP is tricky but possible
            if (preg_match_all('/"text":"([^"]+)"/', $data, $matches)) {
                foreach($matches[1] as $txt) {
                    // Need to decode json string escapes like \n 
                    $fullResponse .= stripcslashes($txt); 
                }
            }

            return $length;
        }
    ]);

    // Ensure we are streaming
    header('Content-Type: text/event-stream');
    header('Cache-Control: no-cache');
    header('X-Accel-Buffering: no'); 

    $fullResponse = '';
    
    $success = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($success && $httpCode === 200) {
        // Cache the response
        if (strlen($fullResponse) > 10 && $cacheDb && !empty($qHash)) {
            try {
                $cacheDb->prepare("INSERT OR IGNORE INTO cache_responses (q_hash, question, answer) VALUES (?, ?, ?)")
                        ->execute([$qHash, $userQuestion, $fullResponse]);
            } catch (Exception $e) {}
        }
        return true;
    }

    return false;
}
