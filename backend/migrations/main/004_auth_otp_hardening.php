<?php
/** Hash credentials used by one-time flows and retire plaintext values. */
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

    foreach (['pending_users', 'password_resets'] as $table) {
        if (!$hasColumn($table, 'otp_hash')) {
            $db->exec('ALTER TABLE ' . $table . ' ADD COLUMN otp_hash TEXT');
        }
    }

    $migrateOtp = static function (string $table, string $keyColumn) use ($db): void {
        $rows = $db->query(
            'SELECT ' . $keyColumn . ' AS record_key, otp
             FROM ' . $table . '
             WHERE otp IS NOT NULL AND otp <> ""
               AND (otp_hash IS NULL OR otp_hash = "" )'
        )->fetchAll();

        $update = $db->prepare(
            'UPDATE ' . $table . ' SET otp_hash = ?, otp = "" WHERE ' . $keyColumn . ' = ?'
        );
        $clearInvalid = $db->prepare(
            'UPDATE ' . $table . ' SET otp = "" WHERE ' . $keyColumn . ' = ?'
        );

        foreach ($rows as $row) {
            $otp = (string)($row['otp'] ?? '');
            if (!preg_match('/^\d{6}$/', $otp)) {
                $clearInvalid->execute([$row['record_key']]);
                continue;
            }
            $update->execute([
                password_hash($otp, PASSWORD_DEFAULT),
                $row['record_key'],
            ]);
        }
    };

    $migrateOtp('pending_users', 'email');
    $migrateOtp('password_resets', 'email');

    $legacyTokens = $db->query(
        'SELECT id, auth_token, auth_token_hash
         FROM users
         WHERE auth_token IS NOT NULL AND auth_token <> ""'
    )->fetchAll();

    $tokenUpdate = $db->prepare(
        'UPDATE users SET auth_token_hash = ?, auth_token = NULL WHERE id = ?'
    );
    $clearToken = $db->prepare('UPDATE users SET auth_token = NULL WHERE id = ?');

    foreach ($legacyTokens as $row) {
        $legacyToken = (string)($row['auth_token'] ?? '');
        if ($legacyToken === '') {
            continue;
        }
        if (empty($row['auth_token_hash'])) {
            $tokenUpdate->execute([hash('sha256', $legacyToken), $row['id']]);
        } else {
            $clearToken->execute([$row['id']]);
        }
    }
};
