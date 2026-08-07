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

function nexa_csrf_token(): string
{
    nexa_start_session();
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
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

    // One-time compatibility path for legacy plaintext tokens.
    $legacy = $db->prepare(
        'SELECT id, email, name, type, country, state, district, pin, address,
                verified, bonus_limit, referral_code, auth_token
         FROM users WHERE auth_token = ? LIMIT 1'
    );
    $legacy->execute([$token]);
    $user = $legacy->fetch();
    if (!$user) {
        return null;
    }

    $upgrade = $db->prepare(
        'UPDATE users SET auth_token_hash = ?, auth_token = NULL WHERE id = ?'
    );
    $upgrade->execute([$digest, $user['id']]);
    $user['auth_token_hash'] = $digest;
    $user['auth_token'] = null;
    return $user;
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
