<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';

nexa_apply_security_headers('GET, POST, OPTIONS');
$db = get_db();
if (!$db) {
    nexa_reject_json(503, 'Database unavailable');
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $plans = $db->query(
        "SELECT name, display_name, price_paise, daily_limit, features,
                razorpay_plan_id, active, updated_at
         FROM subscription_plans WHERE active = 1 ORDER BY price_paise ASC"
    )->fetchAll();
    foreach ($plans as &$plan) {
        $plan['features'] = json_decode((string)$plan['features'], true) ?: [];
        $plan['price_paise'] = (int)$plan['price_paise'];
        $plan['price_inr'] = round($plan['price_paise'] / 100);
        $plan['daily_limit'] = (int)$plan['daily_limit'];
        $plan['active'] = (int)$plan['active'];
    }
    unset($plan);
    echo json_encode(['plans' => array_values($plans)], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    nexa_reject_json(405, 'Method not allowed.');
}

nexa_require_admin();
$body = nexa_read_json_body(65536);
$name = isset($body['name']) && is_string($body['name']) ? trim($body['name']) : '';
$displayName = isset($body['display_name']) && is_string($body['display_name'])
    ? trim($body['display_name']) : '';
$pricePaise = filter_var($body['price_paise'] ?? null, FILTER_VALIDATE_INT);
$dailyLimit = filter_var($body['daily_limit'] ?? null, FILTER_VALIDATE_INT);
$featuresRaw = $body['features'] ?? [];
$rpPlanId = isset($body['razorpay_plan_id']) && is_string($body['razorpay_plan_id'])
    ? trim($body['razorpay_plan_id']) : '';
$active = filter_var($body['active'] ?? 1, FILTER_VALIDATE_INT);

if (!in_array($name, ['free', 'pro', 'premium'], true)
    || $displayName === '' || strlen($displayName) > 80
    || $pricePaise === false || $pricePaise < 0 || $pricePaise > 100000000
    || $dailyLimit === false || $dailyLimit < 1 || $dailyLimit > 1000000
    || !is_array($featuresRaw) || count($featuresRaw) > 20
    || $active === false || !in_array($active, [0, 1], true)
    || strlen($rpPlanId) > 128) {
    nexa_reject_json(400, 'Invalid plan fields');
}

$features = [];
foreach ($featuresRaw as $feature) {
    if (!is_string($feature) || trim($feature) === '' || strlen($feature) > 120) {
        nexa_reject_json(400, 'Invalid plan feature');
    }
    $features[] = trim($feature);
}
$featuresJson = json_encode(array_values($features), JSON_UNESCAPED_UNICODE);

$stmt = $db->prepare(
    "INSERT INTO subscription_plans
        (name, display_name, price_paise, daily_limit, features, razorpay_plan_id, active, updated_at)
     VALUES (?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)
     ON CONFLICT(name) DO UPDATE SET
        display_name = excluded.display_name,
        price_paise = excluded.price_paise,
        daily_limit = excluded.daily_limit,
        features = excluded.features,
        razorpay_plan_id = excluded.razorpay_plan_id,
        active = excluded.active,
        updated_at = CURRENT_TIMESTAMP"
);
$stmt->execute([$name, $displayName, $pricePaise, $dailyLimit, $featuresJson, $rpPlanId, $active]);

$configKey = $name . '_daily_limit';
$db->prepare("UPDATE global_config SET value = ? WHERE key = ?")
    ->execute([(string)$dailyLimit, $configKey]);

echo json_encode([
    'success' => true,
    'message' => "Plan '{$name}' updated.",
], JSON_UNESCAPED_UNICODE);