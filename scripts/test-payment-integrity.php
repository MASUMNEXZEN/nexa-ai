<?php
/**
 * Offline payment integrity tests. This is CLI-only and never contacts Razorpay.
 */
if (PHP_SAPI !== 'cli') {
    exit("Not found\n");
}

$root = dirname(__DIR__);
require_once $root . '/backend/api/payment.php';

$passed = 0;
function payment_test_assert(bool $condition, string $message): void
{
    global $passed;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $passed++;
    echo "PASS {$message}\n";
}

function payment_test_throws(callable $callback, string $message): void
{
    global $passed;
    try {
        $callback();
    } catch (Throwable $error) {
        $passed++;
        echo "PASS {$message}\n";
        return;
    }
    throw new RuntimeException('FAIL: ' . $message);
}

try {
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    $migrationFiles = glob($root . '/backend/migrations/main/*.php') ?: [];
    sort($migrationFiles, SORT_NATURAL);
    foreach ($migrationFiles as $migrationPath) {
        $migration = require $migrationPath;
        if (!is_callable($migration)) {
            throw new RuntimeException('Migration is not callable: ' . basename($migrationPath));
        }
        $migration($db);
    }

    payment_test_assert(nexa_payment_idempotency_key_is_valid('pay-test-key'), 'accepts valid idempotency keys');
    payment_test_assert(!nexa_payment_idempotency_key_is_valid('short'), 'rejects short idempotency keys');
    payment_test_assert(nexa_payment_provider_field_is_valid('pay_test-001'), 'accepts valid provider identifiers');
    payment_test_assert(!nexa_payment_provider_field_is_valid('pay/test/001'), 'rejects unsafe provider identifiers');
    payment_test_assert(nexa_payment_signature_is_valid(str_repeat('a', 64)), 'accepts valid signature format');
    payment_test_assert(!nexa_payment_signature_is_valid('not-a-signature'), 'rejects invalid signature format');

    $secret = 'offline-test-secret';
    $signature = nexa_payment_signature('order_test_001', 'pay_test_001', $secret);
    payment_test_assert(
        hash_equals(hash_hmac('sha256', 'order_test_001|pay_test_001', $secret), $signature),
        'computes the provider signature from the server order and payment IDs'
    );
    payment_test_assert(
        !hash_equals($signature, nexa_payment_signature('order_test_001', 'pay_tampered', $secret)),
        'signature changes when the payment ID is tampered'
    );

    $db->prepare("INSERT INTO users (email, name, type) VALUES (?, ?, 'email')")
        ->execute(['student@example.com', 'Payment Test']);
    $db->prepare('INSERT INTO fcm_tokens (user_email, token) VALUES (?, ?)')
        ->execute(['student@example.com', 'fcm-payment-test']);
    $db->prepare(
        "INSERT INTO user_subscriptions (user_email, plan_name, status, end_date)
         VALUES (?, 'free', 'active', '2030-01-01 00:00:00')"
    )->execute(['student@example.com']);

    $insertPayment = $db->prepare(
        "INSERT INTO payments
            (user_email, razorpay_order_id, idempotency_key, amount_paise, plan_name, status)
         VALUES (?, ?, ?, ?, ?, 'pending')"
    );
    $insertPayment->execute(['student@example.com', 'order_test_001', 'pay-test-key', 9900, 'pro']);
    payment_test_throws(
        static function () use ($insertPayment): void {
            $insertPayment->execute(['student@example.com', 'order_test_002', 'pay-test-key', 9900, 'pro']);
        },
        'database rejects duplicate user idempotency keys'
    );

    $payment = $db->query(
        "SELECT id, user_email, razorpay_payment_id, razorpay_order_id,
                idempotency_key, amount_paise, plan_name, status
         FROM payments WHERE razorpay_order_id = 'order_test_001' LIMIT 1"
    )->fetch();
    $plan = $db->query(
        "SELECT name, display_name, price_paise, daily_limit
         FROM subscription_plans WHERE name = 'pro' LIMIT 1"
    )->fetch();
    payment_test_assert(nexa_payment_owner_matches($payment, 'STUDENT@example.com'), 'matches payment ownership case-insensitively');
    payment_test_assert(!nexa_payment_owner_matches($payment, 'other@example.com'), 'rejects payment ownership mismatch');
    payment_test_assert(nexa_payment_plan_matches($payment, $plan), 'matches server payment plan and amount');
    payment_test_assert(
        !nexa_payment_plan_matches($payment, ['name' => 'premium', 'price_paise' => 19900]),
        'rejects server plan or amount mismatch'
    );
    payment_test_assert(!nexa_payment_replay_conflict(null, (int)$payment['id']), 'allows a payment ID with no replay row');
    payment_test_assert(
        nexa_payment_replay_conflict(['id' => (int)$payment['id'] + 1], (int)$payment['id']),
        'detects a payment ID already bound to another order'
    );

    nexa_finalize_payment(
        $db,
        $payment,
        $plan,
        'student@example.com',
        'pay_test_001',
        '2030-02-01 00:00:00'
    );
    $finalPayment = $db->query(
        "SELECT razorpay_payment_id, status FROM payments WHERE id = " . (int)$payment['id']
    )->fetch();
    $activeSubscription = $db->query(
        "SELECT plan_name, status FROM user_subscriptions
         WHERE user_email = 'student@example.com' AND status = 'active' LIMIT 1"
    )->fetch();
    $oldSubscription = $db->query(
        "SELECT status FROM user_subscriptions
         WHERE user_email = 'student@example.com' AND plan_name = 'free' LIMIT 1"
    )->fetch();
    $user = $db->query(
        "SELECT type, bonus_limit FROM users WHERE email = 'student@example.com' LIMIT 1"
    )->fetch();
    $token = $db->query(
        "SELECT plan_name FROM fcm_tokens WHERE token = 'fcm-payment-test' LIMIT 1"
    )->fetch();

    payment_test_assert($finalPayment['status'] === 'success' && $finalPayment['razorpay_payment_id'] === 'pay_test_001', 'commits the verified payment exactly once');
    payment_test_assert($activeSubscription['plan_name'] === 'pro', 'creates the paid active subscription');
    payment_test_assert($oldSubscription['status'] === 'cancelled', 'cancels the previous active subscription');
    payment_test_assert($user['type'] === 'pro' && (int)$user['bonus_limit'] === 100, 'updates the user entitlement fields');
    payment_test_assert($token['plan_name'] === 'pro', 'updates push-token plan metadata');
    payment_test_throws(
        static function () use ($db, $payment, $plan): void {
            nexa_finalize_payment($db, $payment, $plan, 'student@example.com', 'pay_test_001', '2030-02-01 00:00:00');
        },
        'rejects a second entitlement finalization'
    );
    payment_test_throws(
        static function () use ($db): void {
            $db->prepare(
                "INSERT INTO payments
                    (user_email, razorpay_payment_id, razorpay_order_id, idempotency_key, amount_paise, plan_name, status)
                 VALUES (?, ?, ?, ?, ?, ?, 'pending')"
            )->execute(['other@example.com', 'pay_test_001', 'order_replay', 'replay-key', 9900, 'pro']);
        },
        'database rejects provider payment replay'
    );

    echo "Payment integrity tests passed: {$passed}\n";
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . "\n");
    exit(1);
}