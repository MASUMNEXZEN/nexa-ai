<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';

nexa_apply_security_headers('GET, POST, OPTIONS');
nexa_require_admin();

require_once __DIR__ . '/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$db = get_db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $stmt = $db->query(
        "SELECT key, value
         FROM global_config
         WHERE key IN ('announcement_text', 'announcement_active')"
    );

    $config = [];
    while ($row = $stmt->fetch()) {
        $config[$row['key']] = $row['value'];
    }

    echo json_encode([
        'text' => $config['announcement_text'] ?? '',
        'active' => ($config['announcement_active'] ?? '0') === '1',
    ]);
    exit;
}

if ($method === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);

    if (!is_array($data) || !isset($data['text'], $data['active'])) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid data format']);
        exit;
    }

    $stmt = $db->prepare("INSERT OR REPLACE INTO global_config (key, value) VALUES ('announcement_text', ?)");
    $stmt->execute([(string) $data['text']]);

    $stmt = $db->prepare("INSERT OR REPLACE INTO global_config (key, value) VALUES ('announcement_active', ?)");
    $stmt->execute([$data['active'] ? '1' : '0']);

    $stmt = $db->prepare("INSERT OR REPLACE INTO global_config (key, value) VALUES ('announcement_updated_at', ?)");
    $stmt->execute([date('c')]);

    echo json_encode(['success' => true, 'message' => 'Announcement updated']);
    exit;
}

http_response_code(405);
header('Allow: GET, POST, OPTIONS');
echo json_encode(['error' => 'Method not allowed']);