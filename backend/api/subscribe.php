<?php
/**
 * Create a server-owned Razorpay order for the authenticated user.
 * POST {plan_name}; optional Idempotency-Key header/body field.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/payment.php';
require_once __DIR__ . '/db.php';

nexa_apply_security_headers('POST, OPTIONS');
nexa_start_session();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    nexa_reject_json(405, 'Method not allowed.');
}
if (empty($_SESSION['user_email'])) {
    nexa_reject_json(401, 'Not authenticated');
}

$userEmail = strtolower(trim((string)$_SESSION['user_email']));
$body = nexa_read_json_body(65536);
$planName = isset($body['plan_name']) && is_string($body['plan_name'])
    ? trim($body['plan_name'])
    : '';
$idempotencyKey = trim((string)($_SERVER['HTTP_IDEMPOTENCY_KEY'] ?? ($body['idempotency_key'] ?? '')));

if ($idempotencyKey === '') {
    $idempotencyKey = bin2hex(random_bytes(16));
} elseif (!nexa_payment_idempotency_key_is_valid($idempotencyKey)) {
    nexa_reject_json(400, 'Invalid payment request key.');
}

if (!in_array($planName, ['pro', 'premium'], true)) {
    nexa_reject_json(400, 'Invalid plan');
}

$db = get_db();
if (!$db) {
    nexa_reject_json(503, 'Payment service unavailable.');
}

$planStmt = $db->prepare(
    "SELECT name, display_name, price_paise, daily_limit, features, razorpay_plan_id
     FROM subscription_plans WHERE name = ? AND active = 1 LIMIT 1"
);
$planStmt->execute([$planName]);
$plan = $planStmt->fetch();

if (!$plan || (int)$plan['price_paise'] <= 0) {
    nexa_reject_json(404, 'Plan not found');
}

$existingStmt = $db->prepare(
    "SELECT razorpay_order_id, amount_paise, plan_name, user_email, status
     FROM payments
     WHERE user_email = ? AND idempotency_key = ?
     ORDER BY id DESC LIMIT 1"
);
$existingStmt->execute([$userEmail, $idempotencyKey]);
$existing = $existingStmt->fetch();
if ($existing) {
    if ((string)$existing['plan_name'] !== $planName || (int)$existing['amount_paise'] !== (int)$plan['price_paise']) {
        nexa_reject_json(409, 'Payment request key is already bound to another plan.');
    }
    echo json_encode([
        'order_id' => $existing['razorpay_order_id'],
        'amount' => (int)$existing['amount_paise'],
        'currency' => 'INR',
        'key_id' => RAZORPAY_KEY_ID,
        'plan_name' => $planName,
        'plan_display' => $plan['display_name'],
        'user_email' => $userEmail,
        'status' => $existing['status'],
        'reused' => true,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$keyId = RAZORPAY_KEY_ID;
$keySecret = RAZORPAY_KEY_SECRET;
if ($keyId === '' || $keySecret === '') {
    nexa_reject_json(503, 'Payment gateway not configured');
}

$orderData = [
    'amount' => (int)$plan['price_paise'],
    'currency' => 'INR',
    'receipt' => 'nexa_' . bin2hex(random_bytes(12)),
    'notes' => ['user_email' => $userEmail, 'plan' => $planName],
    'payment_capture' => 1,
];

$ch = curl_init('https://api.razorpay.com/v1/orders');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($orderData),
    CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
    CURLOPT_USERPWD => "{$keyId}:{$keySecret}",
    CURLOPT_CONNECTTIMEOUT => 5,
    CURLOPT_TIMEOUT => 20,
    CURLOPT_SSL_VERIFYPEER => true,
    CURLOPT_SSL_VERIFYHOST => 2,
]);
$response = curl_exec($ch);
$curlError = curl_error($ch);
$httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$order = is_string($response) ? json_decode($response, true) : null;
if ($httpCode !== 200 || !is_array($order) || !nexa_payment_provider_field_is_valid((string)($order['id'] ?? ''))) {
    error_log('[NexA] Razorpay order failed: http=' . $httpCode . ' curl=' . ($curlError !== '' ? 'yes' : 'no'));
    nexa_reject_json(502, 'Failed to create payment order. Try again.');
}

$insert = $db->prepare(
    "INSERT INTO payments
        (user_email, razorpay_order_id, idempotency_key, amount_paise, plan_name, status)
     VALUES (?, ?, ?, ?, ?, 'pending')"
);
try {
    $insert->execute([$userEmail, $order['id'], $idempotencyKey, (int)$plan['price_paise'], $planName]);
} catch (PDOException $error) {
    // A concurrent retry may have won the idempotency race. Return its order.
    $existingStmt->execute([$userEmail, $idempotencyKey]);
    $existing = $existingStmt->fetch();
    if (!$existing) {
        error_log('[NexA] Payment order persistence failed.');
        nexa_reject_json(503, 'Payment service unavailable.');
    }
    if ((string)$existing['plan_name'] !== $planName || (int)$existing['amount_paise'] !== (int)$plan['price_paise']) {
        nexa_reject_json(409, 'Payment request key is already bound to another plan.');
    }
    $order['id'] = $existing['razorpay_order_id'];
}

echo json_encode([
    'order_id' => $order['id'],
    'amount' => (int)$plan['price_paise'],
    'currency' => 'INR',
    'key_id' => $keyId,
    'plan_name' => $planName,
    'plan_display' => $plan['display_name'],
    'user_email' => $userEmail,
    'status' => 'pending',
], JSON_UNESCAPED_UNICODE);