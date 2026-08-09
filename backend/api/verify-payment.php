<?php
/**
 * Verify a Razorpay payment and grant the server-owned entitlement exactly once.
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
if (RAZORPAY_KEY_SECRET === '') {
    nexa_reject_json(503, 'Payment gateway not configured');
}

$body = nexa_read_json_body(65536);
$userEmail = strtolower(trim((string)$_SESSION['user_email']));
$paymentId = isset($body['razorpay_payment_id']) && is_string($body['razorpay_payment_id'])
    ? trim($body['razorpay_payment_id']) : '';
$orderId = isset($body['razorpay_order_id']) && is_string($body['razorpay_order_id'])
    ? trim($body['razorpay_order_id']) : '';
$signature = isset($body['razorpay_signature']) && is_string($body['razorpay_signature'])
    ? trim($body['razorpay_signature']) : '';
$requestedPlan = isset($body['plan_name']) && is_string($body['plan_name'])
    ? trim($body['plan_name']) : '';

if (!nexa_payment_provider_field_is_valid($paymentId)
    || !nexa_payment_provider_field_is_valid($orderId)
    || !nexa_payment_signature_is_valid($signature)) {
    nexa_reject_json(400, 'Missing or invalid payment fields');
}

$db = get_db();
if (!$db) {
    nexa_reject_json(503, 'Payment service unavailable');
}

$paymentStmt = $db->prepare(
    "SELECT id, user_email, razorpay_payment_id, razorpay_order_id,
            idempotency_key, amount_paise, plan_name, status, created_at
     FROM payments WHERE razorpay_order_id = ? LIMIT 1"
);
$paymentStmt->execute([$orderId]);
$payment = $paymentStmt->fetch();
if (!$payment) {
    nexa_reject_json(404, 'Payment order not found');
}
if (!nexa_payment_owner_matches($payment, $userEmail)) {
    nexa_reject_json(403, 'Payment order does not belong to this account');
}

$planName = (string)$payment['plan_name'];
if ($requestedPlan !== '' && $requestedPlan !== $planName) {
    nexa_reject_json(409, 'Payment plan does not match the server order');
}

$planStmt = $db->prepare(
    "SELECT name, display_name, price_paise, daily_limit, features, razorpay_plan_id
     FROM subscription_plans WHERE name = ? LIMIT 1"
);
$planStmt->execute([$planName]);
$plan = $planStmt->fetch();
if (!$plan) {
    nexa_reject_json(409, 'Payment plan is no longer configured');
}
if (!nexa_payment_plan_matches($payment, $plan)) {
    nexa_reject_json(409, 'Payment amount does not match the server plan');
}

$expectedSignature = nexa_payment_signature($orderId, $paymentId, RAZORPAY_KEY_SECRET);
if (!hash_equals($expectedSignature, $signature)) {
    nexa_reject_json(400, 'Payment signature mismatch');
}

$replayStmt = $db->prepare(
    "SELECT id, razorpay_order_id, user_email, status
     FROM payments WHERE razorpay_payment_id = ? LIMIT 1"
);
$replayStmt->execute([$paymentId]);
$replay = $replayStmt->fetch();
if (nexa_payment_replay_conflict($replay ?: null, (int)$payment['id'])) {
    nexa_reject_json(409, 'Payment has already been used');
}

if ((string)$payment['status'] === 'success') {
    if ((string)$payment['razorpay_payment_id'] !== $paymentId) {
        nexa_reject_json(409, 'Payment order has already been used');
    }
    echo json_encode([
        'success' => true,
        'plan' => $planName,
        'plan_display' => $plan['display_name'],
        'daily_limit' => (int)$plan['daily_limit'],
        'message' => 'Payment already verified.',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
if ((string)$payment['status'] !== 'pending') {
    nexa_reject_json(409, 'Payment order is not pending');
}

$endDate = date('Y-m-d H:i:s', strtotime('+30 days'));
try {
    nexa_finalize_payment($db, $payment, $plan, $userEmail, $paymentId, $endDate);
} catch (Throwable $error) {
    error_log('[NexA] Payment verification failed: ' . get_class($error));
    nexa_reject_json(409, 'Payment could not be finalized. Please contact support.');
}

echo json_encode([
    'success' => true,
    'plan' => $planName,
    'plan_display' => $plan['display_name'],
    'daily_limit' => (int)$plan['daily_limit'],
    'expires' => $endDate,
    'message' => "Welcome to {$plan['display_name']}! Your plan is now active.",
], JSON_UNESCAPED_UNICODE);