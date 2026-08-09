<?php
/** Add columns and indexes introduced after the original production schema. */
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

    if (!$hasColumn('users', 'referral_code')) {
        $db->exec('ALTER TABLE users ADD COLUMN referral_code TEXT');
    }
    if (!$hasColumn('users', 'auth_token_hash')) {
        $db->exec('ALTER TABLE users ADD COLUMN auth_token_hash TEXT');
    }
    $db->exec('CREATE INDEX IF NOT EXISTS idx_users_referral ON users(referral_code)');
    $db->exec('CREATE INDEX IF NOT EXISTS idx_users_auth_token_hash ON users(auth_token_hash)');
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS login_attempts (
    email        TEXT NOT NULL PRIMARY KEY COLLATE NOCASE,
    attempts     INTEGER DEFAULT 0,
    locked_until INTEGER DEFAULT 0
)
SQL
    );
    $db->exec('CREATE INDEX IF NOT EXISTS idx_login_attempts_locked ON login_attempts(locked_until)');
};