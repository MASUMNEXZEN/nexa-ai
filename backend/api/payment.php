<?php
/**
 * Internal payment invariants shared by the billing controllers.
 * This file is not a public route; deploy it only as controller support code.
 */

function nexa_payment_idempotency_key_is_valid(string $key): bool
{
    return preg_match('/^[A-Za-z0-9._:-]{8,128}$/', $key) === 1;
}

function nexa_payment_provider_field_is_valid(string $value): bool
{
    return $value !== ''
        && strlen($value) <= 128
        && preg_match('/^[A-Za-z0-9_.-]+$/', $value) === 1;
}

function nexa_payment_signature_is_valid(string $signature): bool
{
    return preg_match('/^[A-Fa-f0-9]{64}$/', $signature) === 1;
}

function nexa_payment_signature(string $orderId, string $paymentId, string $secret): string
{
    return hash_hmac('sha256', $orderId . '|' . $paymentId, $secret);
}

function nexa_payment_owner_matches(array $payment, string $userEmail): bool
{
    return strtolower((string)($payment['user_email'] ?? '')) === strtolower($userEmail);
}

function nexa_payment_plan_matches(array $payment, array $plan): bool
{
    return (string)($payment['plan_name'] ?? '') === (string)($plan['name'] ?? '')
        && (int)($payment['amount_paise'] ?? -1) === (int)($plan['price_paise'] ?? -2);
}

function nexa_payment_replay_conflict(?array $replay, int $currentPaymentId): bool
{
    return $replay !== null && (int)($replay['id'] ?? 0) !== $currentPaymentId;
}

function nexa_finalize_payment(
    PDO $db,
    array $payment,
    array $plan,
    string $userEmail,
    string $paymentId,
    string $endDate
): void {
    $paymentRowId = (int)($payment['id'] ?? 0);
    $planName = (string)($plan['name'] ?? '');
    if ($paymentRowId <= 0 || $planName === '' || !in_array($planName, ['pro', 'premium'], true)) {
        throw new InvalidArgumentException('Payment finalization input is incomplete.');
    }
    if (!nexa_payment_owner_matches($payment, $userEmail)
        || !nexa_payment_plan_matches($payment, $plan)
        || !nexa_payment_provider_field_is_valid($paymentId)
        || $endDate === '') {
        throw new InvalidArgumentException('Payment finalization input does not match the server record.');
    }
    if ((string)($payment['status'] ?? '') !== 'pending') {
        throw new RuntimeException('Payment order is not pending.');
    }

    try {
        $db->beginTransaction();

        $updatePayment = $db->prepare(
            "UPDATE payments
             SET razorpay_payment_id = ?, status = 'success'
             WHERE id = ? AND user_email = ? AND status = 'pending'"
        );
        $updatePayment->execute([$paymentId, $paymentRowId, $userEmail]);
        if ($updatePayment->rowCount() !== 1) {
            throw new RuntimeException('Payment order was already processed.');
        }

        $db->prepare(
            "UPDATE user_subscriptions SET status = 'cancelled'
             WHERE user_email = ? AND status = 'active'"
        )->execute([$userEmail]);

        $db->prepare(
            "INSERT INTO user_subscriptions
                (user_email, plan_name, status, razorpay_sub_id, start_date, end_date)
             VALUES (?, ?, 'active', ?, CURRENT_TIMESTAMP, ?)"
        )->execute([$userEmail, $planName, $paymentId, $endDate]);

        $newType = $planName === 'premium' ? 'premium' : 'pro';
        $db->prepare("UPDATE users SET type = ?, bonus_limit = ? WHERE email = ?")
            ->execute([$newType, (int)$plan['daily_limit'], $userEmail]);
        $db->prepare("UPDATE fcm_tokens SET plan_name = ? WHERE user_email = ?")
            ->execute([$planName, $userEmail]);

        $db->commit();
    } catch (Throwable $error) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        throw $error;
    }
}