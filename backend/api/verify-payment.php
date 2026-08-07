<?php
/**
 * Verify a Razorpay payment and grant the server-owned entitlement exactly once.
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
require_once __DIR__ . '/db.php';

nexa_apply_security_headers('POST, OPTIONS');
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed.']);
    exit;
}

if (empty($_SESSION['user_email'])) {
    http_response_code(401);
    echo json_encode(['error' => 'Not authenticated']);
    exit;
}

$body = json_decode(file_get_contents('php://input'), true);
if (!is_array($body)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body']);
    exit;
}

$userEmail = strtolower(trim((string)$_SESSION['user_email']));
$paymentId = trim((string)($body['razorpay_payment_id'] ?? ''));
$orderId   = trim((string)($body['razorpay_order_id'] ?? ''));
$signature = trim((string)($body['razorpay_signature'] ?? ''));
$requestedPlan = trim((string)($body['plan_name'] ?? ''));

if ($paymentId === '' || $orderId === '' || $signature === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing payment fields']);
    exit;
}

$db = get_db();
if (!$db) {
    http_response_code(503);
    echo json_encode(['error' => 'Payment service unavailable']);
    exit;
}

$paymentStmt = $db->prepare(
    "SELECT * FROM payments WHERE razorpay_order_id = ? LIMIT 1"
);
$paymentStmt->execute([$orderId]);
$payment = $paymentStmt->fetch();

if (!$payment) {
    http_response_code(404);
    echo json_encode(['error' => 'Payment order not found']);
    exit;
}

if (strtolower((string)$payment['user_email']) !== $userEmail) {
    http_response_code(403);
    echo json_encode(['error' => 'Payment order does not belong to this account']);
    exit;
}

$planName = (string)$payment['plan_name'];
if ($requestedPlan !== '' && $requestedPlan !== $planName) {
    http_response_code(409);
    echo json_encode(['error' => 'Payment plan does not match the server order']);
    exit;
}

$planStmt = $db->prepare("SELECT * FROM subscription_plans WHERE name = ? LIMIT 1");
$planStmt->execute([$planName]);
$plan = $planStmt->fetch();

if (!$plan) {
    http_response_code(409);
    echo json_encode(['error' => 'Payment plan is no longer configured']);
    exit;
}

if ((int)$payment['amount_paise'] !== (int)$plan['price_paise']) {
    http_response_code(409);
    echo json_encode(['error' => 'Payment amount does not match the server plan']);
    exit;
}

$expectedSignature = hash_hmac(
    'sha256',
    $orderId . '|' . $paymentId,
    RAZORPAY_KEY_SECRET
);

if (!hash_equals($expectedSignature, $signature)) {
    http_response_code(400);
    echo json_encode(['error' => 'Payment signature mismatch']);
    exit;
}

if ((string)$payment['status'] === 'success') {
    if ((string)$payment['razorpay_payment_id'] !== $paymentId) {
        http_response_code(409);
        echo json_encode(['error' => 'Payment order has already been used']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'plan' => $planName,
        'plan_display' => $plan['display_name'],
        'daily_limit' => (int)$plan['daily_limit'],
        'message' => 'Payment already verified.',
    ]);
    exit;
}

if ((string)$payment['status'] !== 'pending') {
    http_response_code(409);
    echo json_encode(['error' => 'Payment order is not pending']);
    exit;
}

$endDate = date('Y-m-d H:i:s', strtotime('+30 days'));

try {
    $db->beginTransaction();

    $updatePayment = $db->prepare(
        "UPDATE payments
         SET razorpay_payment_id = ?, status = 'success'
         WHERE razorpay_order_id = ? AND user_email = ? AND status = 'pending'"
    );
    $updatePayment->execute([$paymentId, $orderId, $userEmail]);

    if ($updatePayment->rowCount() !== 1) {
        throw new RuntimeException('Payment order was already processed');
    }

    $db->prepare(
        "UPDATE user_subscriptions
         SET status = 'cancelled'
         WHERE user_email = ? AND status = 'active'"
    )->execute([$userEmail]);

    $db->prepare(
        "INSERT INTO user_subscriptions
         (user_email, plan_name, status, razorpay_sub_id, start_date, end_date)
         VALUES (?, ?, 'active', ?, CURRENT_TIMESTAMP, ?)"
    )->execute([$userEmail, $planName, $paymentId, $endDate]);

    $newType = $planName === 'premium' ? 'premium' : 'pro';
    $db->prepare(
        "UPDATE users SET type = ?, bonus_limit = ? WHERE email = ?"
    )->execute([$newType, (int)$plan['daily_limit'], $userEmail]);

    $db->prepare(
        "UPDATE fcm_tokens SET plan_name = ? WHERE user_email = ?"
    )->execute([$planName, $userEmail]);

    $db->commit();
} catch (Throwable $error) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log('[NexA] Payment verification failed: ' . $error->getMessage());
    http_response_code(409);
    echo json_encode(['error' => 'Payment could not be finalized. Please contact support.']);
    exit;
}

echo json_encode([
    'success' => true,
    'plan' => $planName,
    'plan_display' => $plan['display_name'],
    'daily_limit' => (int)$plan['daily_limit'],
    'expires' => $endDate,
    'message' => "Welcome to {$plan['display_name']}! Your plan is now active.",
]);
