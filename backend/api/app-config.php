<?php
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('GET, POST, OPTIONS');
// Called by Flutter app on every startup
// Admin changes here reflect instantly without app update
header('Content-Type: application/json');


require_once __DIR__ . '/db.php';

$db = get_db();
if (!$db) {
    echo json_encode(['error' => 'DB unavailable']); exit;
}

// Fetch all config keys in one query
$rows = $db->query("SELECT key, value FROM global_config")->fetchAll();
$config = [];
foreach ($rows as $r) {
    $config[$r['key']] = $r['value'];
}

// Fetch subscription plans
$plans = $db->query("SELECT name, display_name, price_paise, daily_limit, features, razorpay_plan_id FROM subscription_plans WHERE active = 1 ORDER BY price_paise ASC")->fetchAll();
foreach ($plans as &$p) {
    $p['features']     = json_decode($p['features'], true) ?: [];
    $p['price_inr']    = round($p['price_paise'] / 100);
    $p['price_paise']  = (int)$p['price_paise'];
    $p['daily_limit']  = (int)$p['daily_limit'];
}

echo json_encode([
    // Plan limits
    'free_daily_limit'      => (int)($config['free_daily_limit'] ?? 20),
    'pro_daily_limit'       => (int)($config['pro_daily_limit'] ?? 100),
    'premium_daily_limit'   => (int)($config['premium_daily_limit'] ?? 9999),
    // Feature flags
    'voice_enabled'         => ($config['voice_enabled'] ?? '1') === '1',
    'camera_enabled'        => ($config['camera_enabled'] ?? '1') === '1',
    'quiz_enabled'          => ($config['quiz_enabled'] ?? '1') === '1',
    'subscription_enabled'  => ($config['subscription_enabled'] ?? '1') === '1',
    // Maintenance
    'maintenance_mode'      => ($config['maintenance_mode'] ?? '0') === '1',
    'maintenance_message'   => $config['maintenance_message'] ?? '',
    // App version gate
    'app_version_required'  => $config['app_version_required'] ?? '1.0.0',
    'app_update_url'        => $config['app_update_url'] ?? '',
    // Content
    'welcome_message'       => $config['welcome_message'] ?? '',
    'announcement_active'   => ($config['announcement_active'] ?? '0') === '1',
    'announcement_text'     => $config['announcement_text'] ?? '',
    'free_trial_days'       => (int)($config['free_trial_days'] ?? 0),
    // Plans
    'plans'                 => array_values($plans),
]);

