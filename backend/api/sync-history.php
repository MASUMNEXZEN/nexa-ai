<?php
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('GET, POST, OPTIONS');
// Auth: SQLite-first (no users.json dependency)
session_start();
header('Content-Type: application/json; charset=utf-8');



require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$email = null;

// 1. PHP session (fastest path)
if (!empty($_SESSION['user_email'])) {
    $email = $_SESSION['user_email'];
}

if (!$email && !empty($_COOKIE['nexa_token'])) {
    $cookieToken = $_COOKIE['nexa_token'];
    $db = get_db();
    if ($db) {
        $row = nexa_resolve_persistent_token($db, $cookieToken);
        if ($row) {
            $email = $row['email'];
            $_SESSION['user_email'] = $email;
        }
    }
}

if (!$email) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

// History stored as flat JSON file per user (keyed by md5 of email)
$historyFile = DATA_DIR . 'history_' . md5(strtolower($email)) . '.json';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawBody = file_get_contents('php://input');
    $data = json_decode($rawBody, true);

    if ($data === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }

    if (!is_dir(DATA_DIR)) mkdir(DATA_DIR, 0755, true);
    file_put_contents($historyFile, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), LOCK_EX);

    echo json_encode(['success' => true]);
} else {
    // GET: return history
    if (file_exists($historyFile)) {
        echo file_get_contents($historyFile);
    } else {
        echo json_encode([]);
    }
}


