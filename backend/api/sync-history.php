<?php
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('GET, POST, OPTIONS');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

nexa_start_session();

$email = "";
if (!empty($_SESSION['user_email'])) {
    $email = strtolower(trim((string)$_SESSION['user_email']));
} elseif (!empty($_COOKIE['nexa_token'])) {
    $db = get_db();
    if ($db) {
        $row = nexa_resolve_persistent_token($db, (string)$_COOKIE['nexa_token']);
        if ($row) {
            $email = strtolower(trim((string)$row['email']));
            $_SESSION['user_email'] = $email;
        }
    }
}

if ($email === '') {
    nexa_reject_json(401, 'Not authenticated');
}

$historyFile = DATA_DIR . 'history_' . md5($email) . '.json';
$maxHistoryEntries = 40;
$maxTextLength = 20000;
$maxTotalTextLength = 240000;

function nexa_read_history_file(string $path): array
{
    if (!is_file($path)) {
        return [];
    }

    $contents = file_get_contents($path);
    $history = json_decode($contents ?: '', true);
    return is_array($history) ? $history : [];
}

function nexa_normalize_history($history, int $maxEntries, int $maxTextLength, int $maxTotalTextLength): ?array
{
    if (!is_array($history) || count($history) > $maxEntries) {
        return null;
    }

    $normalized = [];
    $totalTextLength = 0;

    foreach ($history as $entry) {
        if (!is_array($entry) || !in_array($entry['role'] ?? '', ['user', 'model'], true)) {
            return null;
        }

        $parts = $entry['parts'] ?? null;
        if (!is_array($parts) || count($parts) < 1 || count($parts) > 4) {
            return null;
        }

        $normalizedParts = [];
        foreach ($parts as $part) {
            if (!is_array($part) || !is_string($part['text'] ?? null) || strlen($part['text']) > $maxTextLength) {
                return null;
            }
            $totalTextLength += strlen($part['text']);
            if ($totalTextLength > $maxTotalTextLength) {
                return null;
            }
            $normalizedParts[] = ['text' => $part['text']];
        }

        $normalized[] = [
            'role' => $entry['role'],
            'parts' => $normalizedParts,
        ];
    }

    return $normalized;
}

function nexa_write_history_file(string $path, array $history): bool
{
    $encoded = json_encode($history, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($encoded === false) {
        return false;
    }

    $handle = fopen($path, 'c+b');
    if ($handle === false) {
        return false;
    }

    try {
        if (!flock($handle, LOCK_EX)) {
            return false;
        }
        if (!ftruncate($handle, 0) || fseek($handle, 0) !== 0) {
            return false;
        }
        $written = fwrite($handle, $encoded);
        fflush($handle);
        flock($handle, LOCK_UN);
        return $written === strlen($encoded);
    } finally {
        fclose($handle);
    }
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if ($method === 'GET') {
    echo json_encode(nexa_read_history_file($historyFile), JSON_UNESCAPED_UNICODE);
    exit;
}

if ($method !== 'POST') {
    nexa_reject_json(405, 'Method not allowed.');
}

$body = nexa_read_json_body(524288, 'Invalid history request.');
$action = $body['action'] ?? '';

if ($action === 'load') {
    echo json_encode(['history' => nexa_read_history_file($historyFile)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action !== 'save') {
    nexa_reject_json(400, 'Unsupported history action.');
}

$history = nexa_normalize_history(
    $body['history'] ?? null,
    $maxHistoryEntries,
    $maxTextLength,
    $maxTotalTextLength
);
if ($history === null) {
    nexa_reject_json(400, 'Invalid history format.');
}

if (!is_dir(DATA_DIR) && !mkdir(DATA_DIR, 0755, true) && !is_dir(DATA_DIR)) {
    nexa_reject_json(500, 'History storage is unavailable.');
}
if (!nexa_write_history_file($historyFile, $history)) {
    nexa_reject_json(500, 'Could not save history.');
}

echo json_encode(['success' => true], JSON_UNESCAPED_UNICODE);