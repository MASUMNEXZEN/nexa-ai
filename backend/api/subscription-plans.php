<?php
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('GET, POST, OPTIONS');
header('Content-Type: application/json');


require_once __DIR__ . '/db.php';
require_once __DIR__ . '/config.php';

$db = get_db();
if (!$db) { echo json_encode(['error' => 'DB unavailable']); exit; }

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $plans = $db->query("SELECT * FROM subscription_plans WHERE active = 1 ORDER BY price_paise ASC")->fetchAll();
    foreach ($plans as &$p) {
        $p['features']    = json_decode($p['features'], true) ?: [];
        $p['price_paise'] = (int)$p['price_paise'];
        $p['price_inr']   = round($p['price_paise'] / 100);
        $p['daily_limit'] = (int)$p['daily_limit'];
    }
    echo json_encode(['plans' => array_values($plans)]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Admin auth check
    session_start();
    if (empty($_SESSION['admin_logged_in'])) {
        http_response_code(403);
        echo json_encode(['error' => 'Unauthorized']); exit;
    }

    $body = json_decode(file_get_contents('php://input'), true);
    $name        = trim($body['name'] ?? '');
    $displayName = trim($body['display_name'] ?? '');
    $pricePaise  = (int)($body['price_paise'] ?? 0);
    $dailyLimit  = (int)($body['daily_limit'] ?? 20);
    $features    = json_encode($body['features'] ?? []);
    $rpPlanId    = trim($body['razorpay_plan_id'] ?? '');
    $active      = (int)($body['active'] ?? 1);

    if (!$name || !$displayName) {
        http_response_code(400);
        echo json_encode(['error' => 'name and display_name required']); exit;
    }

    $stmt = $db->prepare("INSERT INTO subscription_plans (name, display_name, price_paise, daily_limit, features, razorpay_plan_id, active, updated_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
        ON CONFLICT(name) DO UPDATE SET
            display_name     = excluded.display_name,
            price_paise      = excluded.price_paise,
            daily_limit      = excluded.daily_limit,
            features         = excluded.features,
            razorpay_plan_id = excluded.razorpay_plan_id,
            active           = excluded.active,
            updated_at       = CURRENT_TIMESTAMP");
    $stmt->execute([$name, $displayName, $pricePaise, $dailyLimit, $features, $rpPlanId, $active]);

    // Update global_config daily limits to match plan changes
    if ($name === 'free')    $db->exec("UPDATE global_config SET value = '{$dailyLimit}' WHERE key = 'free_daily_limit'");
    if ($name === 'pro')     $db->exec("UPDATE global_config SET value = '{$dailyLimit}' WHERE key = 'pro_daily_limit'");
    if ($name === 'premium') $db->exec("UPDATE global_config SET value = '{$dailyLimit}' WHERE key = 'premium_daily_limit'");

    echo json_encode(['success' => true, 'message' => "Plan '{$name}' updated."]);
}

