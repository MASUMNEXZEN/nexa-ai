<?php
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('GET, POST, OPTIONS');
require_once __DIR__ . '/db.php';
nexa_require_admin();

$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $rows = $db->query("SELECT key, value FROM global_config ORDER BY key")->fetchAll();
    $config = [];
    foreach ($rows as $r) $config[$r['key']] = $r['value'];
    echo json_encode(['config' => $config]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body    = json_decode(file_get_contents('php://input'), true);
    $updates = $body['updates'] ?? []; // {key: value, ...}

    if (empty($updates) || !is_array($updates)) {
        http_response_code(400);
        echo json_encode(['error' => 'updates object required']); exit;
    }

    // Allowlist of editable keys (security: never let arbitrary keys in)
    $allowedKeys = [
        'daily_limit', 'announcement_text', 'announcement_active',
        'free_daily_limit', 'pro_daily_limit', 'premium_daily_limit',
        'voice_enabled', 'camera_enabled', 'quiz_enabled', 'subscription_enabled',
        'maintenance_mode', 'maintenance_message',
        'app_version_required', 'app_update_url',
        'welcome_message', 'free_trial_days',
    ];

    $stmt = $db->prepare("INSERT INTO global_config (key, value) VALUES (?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value");
    $saved = [];
    foreach ($updates as $key => $value) {
        if (!in_array($key, $allowedKeys)) continue;
        $stmt->execute([$key, (string)$value]);
        $saved[] = $key;
    }

    echo json_encode(['success' => true, 'saved_keys' => $saved]);
}




