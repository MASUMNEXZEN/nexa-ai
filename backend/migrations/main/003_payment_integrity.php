<?php
/** Add payment idempotency and replay-protection constraints. */
return static function (PDO $db): void {
    $hasColumn = static function (string $table, string $column) use ($db): bool {
        $quotedTable = str_replace('"', '""', $table);
        $stmt = $db->query('PRAGMA table_info("' . $quotedTable . '")');
        foreach ($stmt as $row) {
            if ((string)$row['name'] === $column) {
                return true;
            }
        }
        return false;
    };

    if (!$hasColumn('payments', 'idempotency_key')) {
        $db->exec("ALTER TABLE payments ADD COLUMN idempotency_key TEXT NOT NULL DEFAULT ''");
    }

    $duplicateKeys = (int)$db->query(
        "SELECT COUNT(*) FROM (
            SELECT user_email, idempotency_key
            FROM payments
            WHERE idempotency_key <> ''
            GROUP BY user_email, idempotency_key
            HAVING COUNT(*) > 1
        )"
    )->fetchColumn();
    if ($duplicateKeys > 0) {
        throw new RuntimeException('Payment idempotency data contains duplicate keys; resolve them before migration.');
    }

    $duplicateProviderIds = (int)$db->query(
        "SELECT COUNT(*) FROM (
            SELECT razorpay_payment_id
            FROM payments
            WHERE razorpay_payment_id <> ''
            GROUP BY razorpay_payment_id
            HAVING COUNT(*) > 1
        )"
    )->fetchColumn();
    if ($duplicateProviderIds > 0) {
        throw new RuntimeException('Payment provider IDs are duplicated; resolve them before migration.');
    }

    $db->exec('CREATE INDEX IF NOT EXISTS idx_pay_email ON payments(user_email)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_pay_order ON payments(razorpay_order_id)');
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_payments_user_idempotency
        ON payments(user_email, idempotency_key) WHERE idempotency_key <> ''");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS ux_payments_provider_payment
        ON payments(razorpay_payment_id) WHERE razorpay_payment_id <> ''");
};