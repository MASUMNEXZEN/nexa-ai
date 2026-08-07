<?php
// POST {plan_name}
// Returns {order_id, amount, currency, key_id}
// App opens Razorpay checkout, then calls verify-payment.php
header('Content-Type: application/json');


require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('POST, OPTIONS');
require_once __DIR__ . '/db.php';

session_start();
if (empty($_SESSION['user_email'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']); exit;
}

$userEmail = $_SESSION['user_email'];
$body      = json_decode(file_get_contents('php://input'), true);
$planName  = trim($body['plan_name'] ?? '');

if (!$planName || $planName === 'free') {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid plan']); exit;
}

$db = get_db();
$plan = $db->prepare("SELECT * FROM subscription_plans WHERE name = ? AND active = 1");
$plan->execute([$planName]);
$plan = $plan->fetch();

if (!$plan) {
    http_response_code(404);
    echo json_encode(['error' => 'Plan not found']); exit;
}

if ((int)$plan['price_paise'] === 0) {
    http_response_code(400);
    echo json_encode(['error' => 'Free plan requires no payment']); exit;
}

// Create Razorpay order via API
$keyId     = RAZORPAY_KEY_ID;
$keySecret = RAZORPAY_KEY_SECRET;

if (!$keyId || !$keySecret) {
    http_response_code(500);
    echo json_encode(['error' => 'Payment gateway not configured']); exit;
}

$orderData = [
    'amount'          => (int)$plan['price_paise'],
    'currency'        => 'INR',
    'receipt'         => 'nexa_' . time() . '_' . substr(md5($userEmail), 0, 8),
    'notes'           => ['user_email' => $userEmail, 'plan' => $planName],
    'payment_capture' => 1,
];

$ch = curl_init('https://api.razorpay.com/v1/orders');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($orderData),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
    CURLOPT_USERPWD        => "{$keyId}:{$keySecret}",
]);
$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

$order = json_decode($response, true);
if ($httpCode !== 200 || empty($order['id'])) {
    error_log("[NexA] Razorpay order error: " . $response);
    http_response_code(500);
    echo json_encode(['error' => 'Failed to create payment order. Try again.']); exit;
}

// Log pending payment
$stmt = $db->prepare("INSERT INTO payments (user_email, razorpay_order_id, amount_paise, plan_name, status) VALUES (?, ?, ?, ?, 'pending')");
$stmt->execute([$userEmail, $order['id'], (int)$plan['price_paise'], $planName]);

echo json_encode([
    'order_id'     => $order['id'],
    'amount'       => (int)$plan['price_paise'],
    'currency'     => 'INR',
    'key_id'       => $keyId,
    'plan_name'    => $planName,
    'plan_display' => $plan['display_name'],
    'user_email'   => $userEmail,
]);




