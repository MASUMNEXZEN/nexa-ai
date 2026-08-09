<?php
/**
 * Shared HTTP security and authentication helpers.
 *
 * Keep origin allowlists and authorization decisions on the server. No API
 * endpoint should invent its own admin or token behavior.
 */

function nexa_allowed_origins(): array
{
    $configured = getenv('NEXA_ALLOWED_ORIGINS');
    if ($configured === false || trim((string)$configured) === '') {
        $envFile = __DIR__ . '/../.env';
        if (is_file($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                if (preg_match('/^NEXA_ALLOWED_ORIGINS\s*=\s*(.*)$/', trim($line), $match)) {
                    $configured = trim($match[1]);
                    break;
                }
            }
        }
    }

    $configured = $configured ?: 'https://ai.nexzen.live';
    return array_values(array_filter(array_map('trim', explode(',', $configured))));
}

function nexa_start_session(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
}
function nexa_request_id(): string
{
    static $requestId = null;
    if ($requestId === null) {
        $requestId = 'req_' . bin2hex(random_bytes(8));
    }
    return $requestId;
}

/**
 * Write a small, structured operational event without accepting sensitive
 * request/provider data into the log context.
 */
function nexa_log_event(string $event, array $context = []): void
{
    $safe = [
        'event' => $event,
        'request_id' => nexa_request_id(),
        'timestamp' => gmdate('c'),
    ];

    foreach ($context as $key => $value) {
        if (!is_scalar($value) || preg_match('/password|otp|token|secret|prompt|raw|key|error/i', (string)$key)) {
            continue;
        }
        $safe[(string)$key] = $value;
    }

    error_log('[Nexa] ' . json_encode($safe, JSON_UNESCAPED_SLASHES));
}

function nexa_safe_error(int $status, string $code, string $message): void
{
    http_response_code($status);
    echo json_encode([
        'error' => $message,
        'code' => $code,
        'request_id' => nexa_request_id(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function nexa_apply_security_headers(string $methods = 'GET, POST, OPTIONS'): void
{
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('X-Content-Type-Options: nosniff');
    header('Referrer-Policy: strict-origin-when-cross-origin');

    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    $allowed = nexa_allowed_origins();
    if ($origin !== '' && in_array($origin, $allowed, true)) {
        header('Access-Control-Allow-Origin: ' . $origin);
        header('Access-Control-Allow-Credentials: true');
        header('Vary: Origin');
    }

    header('Access-Control-Allow-Methods: ' . $methods);
    header('Access-Control-Allow-Headers: Content-Type, X-CSRF-Token, X-Device-Id, X-Request-Id');

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
        if ($origin !== '' && !in_array($origin, $allowed, true)) {
            http_response_code(403);
            exit;
        }
        http_response_code(204);
        exit;
    }

    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD', 'OPTIONS'], true)) {
        nexa_require_csrf();
    }
}

function nexa_reject_json(int $status, string $message): void
{
    http_response_code($status);
    echo json_encode(['error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function nexa_enforce_max_body_size(int $maxBytes, string $message = 'Request too large.'): void
{
    $contentLength = isset($_SERVER['CONTENT_LENGTH']) ? (int)$_SERVER['CONTENT_LENGTH'] : 0;
    if ($contentLength > $maxBytes) {
        nexa_reject_json(413, $message);
    }
}

function nexa_read_json_body(int $maxBytes = 1048576, string $message = 'Invalid request body.'): array
{
    nexa_enforce_max_body_size($maxBytes, 'Request too large.');
    $rawBody = file_get_contents('php://input', false, null, 0, $maxBytes + 1);
    if (is_string($rawBody) && strlen($rawBody) > $maxBytes) {
        nexa_reject_json(413, 'Request too large.');
    }
    $body = json_decode($rawBody ?: '', true);
    if (!is_array($body)) {
        nexa_reject_json(400, $message);
    }
    return $body;
}

function nexa_validate_ai_contents($contents): ?string
{
    if (!is_array($contents) || count($contents) < 1 || count($contents) > 8) {
        return 'Invalid conversation payload.';
    }

    $totalTextLength = 0;
    $totalBinaryBytes = 0;
    $allowedMimeTypes = ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'];

    foreach ($contents as $turn) {
        if (!is_array($turn) || !in_array($turn['role'] ?? '', ['user', 'model'], true)) {
            return 'Invalid conversation role.';
        }
        if (!isset($turn['parts']) || !is_array($turn['parts']) || count($turn['parts']) < 1 || count($turn['parts']) > 8) {
            return 'Invalid conversation parts.';
        }

        foreach ($turn['parts'] as $part) {
            if (!is_array($part)) {
                return 'Invalid conversation part.';
            }

            if (array_key_exists('text', $part)) {
                if (!is_string($part['text']) || strlen($part['text']) > 20000) {
                    return 'Text input is too large.';
                }
                $totalTextLength += strlen($part['text']);
                if ($totalTextLength > 120000) {
                    return 'Conversation is too large.';
                }
                continue;
            }

            if (array_key_exists('inline_data', $part)) {
                $inline = $part['inline_data'];
                if (!is_array($inline) || !in_array($inline['mime_type'] ?? '', $allowedMimeTypes, true)) {
                    return 'Unsupported attachment type.';
                }
                $encoded = $inline['data'] ?? '';
                if (!is_string($encoded) || $encoded === '' || strlen($encoded) > 14000000) {
                    return 'Attachment is too large.';
                }
                $decoded = base64_decode($encoded, true);
                if ($decoded === false || strlen($decoded) > 10000000) {
                    return 'Attachment is too large or invalid.';
                }
                $totalBinaryBytes += strlen($decoded);
                if ($totalBinaryBytes > 10000000) {
                    return 'Attachments are too large.';
                }
                continue;
            }

            return 'Invalid conversation part.';
        }
    }

    return null;
}

function nexa_validate_ai_request(array $body): ?string
{
    $contentsError = nexa_validate_ai_contents($body['contents'] ?? null);
    if ($contentsError !== null) {
        return $contentsError;
    }

    if (isset($body['system_instruction'])) {
        $systemParts = $body['system_instruction']['parts'] ?? null;
        if (!is_array($systemParts) || count($systemParts) < 1 || !is_string($systemParts[0]['text'] ?? null)) {
            return 'Invalid system instruction.';
        }
        if (strlen($systemParts[0]['text']) > 8000) {
            return 'System instruction is too large.';
        }
    }

    return null;
}
function nexa_csrf_token(): string
{
    nexa_start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function nexa_require_authenticated_user(PDO $db): array
{
    nexa_start_session();
    $email = strtolower(trim((string)($_SESSION['user_email'] ?? '')));

    if ($email === '' && !empty($_COOKIE['nexa_token'])) {
        $resolved = nexa_resolve_persistent_token($db, (string)$_COOKIE['nexa_token']);
        if ($resolved) {
            $email = strtolower(trim((string)$resolved['email']));
            $_SESSION['user_email'] = $email;
        }
    }

    if ($email === '') {
        nexa_safe_error(401, 'authentication_required', 'Please sign in to use the study planner.');
    }

    $statement = $db->prepare(
        'SELECT id, email, name, type
         FROM users WHERE lower(email) = lower(?) LIMIT 1'
    );
    $statement->execute([$email]);
    $user = $statement->fetch();
    if (!$user) {
        nexa_safe_error(401, 'authentication_required', 'Please sign in to use the study planner.');
    }

    return $user;
}
function nexa_require_admin(): void
{
    nexa_start_session();
    if (!empty($_SESSION['admin_logged_in'])) {
        return;
    }

    http_response_code(403);
    echo json_encode(['error' => 'Admin access required.']);
    exit;
}

function nexa_require_csrf(): void
{
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (in_array($method, ['GET', 'HEAD', 'OPTIONS'], true)) {
        return;
    }

    $allowed = nexa_allowed_origins();
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    $referer = trim((string)($_SERVER['HTTP_REFERER'] ?? ''));

    if ($origin !== '') {
        if (!in_array($origin, $allowed, true)) {
            http_response_code(403);
            echo json_encode(['error' => 'Cross-origin request blocked.']);
            exit;
        }
        return;
    }

    if ($referer !== '') {
        $parts = parse_url($referer);
        $refererOrigin = ($parts['scheme'] ?? '') . '://' . ($parts['host'] ?? '');
        if (isset($parts['port'])) {
            $refererOrigin .= ':' . $parts['port'];
        }
        if (!in_array($refererOrigin, $allowed, true)) {
            http_response_code(403);
            echo json_encode(['error' => 'Cross-origin request blocked.']);
            exit;
        }
        return;
    }

    nexa_start_session();
    $isAuthenticated = !empty($_SESSION['user_email']) || !empty($_SESSION['admin_logged_in']);
    $expected = $_SESSION['csrf_token'] ?? '';
    $provided = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if ($isAuthenticated && ($provided === '' || $expected === '' || !hash_equals($expected, $provided))) {
        http_response_code(403);
        echo json_encode(['error' => 'Security token missing or invalid.']);
        exit;
    }
}

function nexa_issue_persistent_token(PDO $db, string $email): string
{
    $token = bin2hex(random_bytes(32));
    $digest = hash('sha256', $token);
    $stmt = $db->prepare(
        'UPDATE users SET auth_token_hash = ?, auth_token = NULL WHERE email = ?'
    );
    $stmt->execute([$digest, strtolower($email)]);
    return $token;
}

function nexa_resolve_persistent_token(PDO $db, string $token): ?array
{
    if ($token === '') {
        return null;
    }

    $digest = hash('sha256', $token);
    $stmt = $db->prepare(
        'SELECT id, email, name, type, country, state, district, pin, address,
                verified, bonus_limit, referral_code, auth_token_hash
         FROM users WHERE auth_token_hash = ? LIMIT 1'
    );
    $stmt->execute([$digest]);
    $user = $stmt->fetch();
    if ($user) {
        return $user;
    }

    return null;
}

function nexa_revoke_persistent_token(PDO $db, string $token): void
{
    $user = nexa_resolve_persistent_token($db, $token);
    if (!$user || empty($user['id'])) {
        return;
    }

    $stmt = $db->prepare(
        'UPDATE users SET auth_token_hash = NULL, auth_token = NULL WHERE id = ?'
    );
    $stmt->execute([$user['id']]);
}
