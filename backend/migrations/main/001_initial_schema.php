<?php
/**
 * Initial application schema.
 *
 * This migration is safe to run against an existing database because every
 * table/index uses IF NOT EXISTS. Legacy databases are adopted by running
 * this migration once, then applying the forward hardening migrations.
 */
return static function (PDO $db): void {
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS users (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    email           TEXT NOT NULL UNIQUE COLLATE NOCASE,
    password_hash   TEXT,
    auth_token      TEXT,
    auth_token_hash TEXT,
    type            TEXT DEFAULT 'email',
    name            TEXT DEFAULT '',
    country         TEXT DEFAULT '',
    state           TEXT DEFAULT '',
    district        TEXT DEFAULT '',
    pin             TEXT DEFAULT '',
    address         TEXT DEFAULT '',
    google_sub      TEXT,
    referral_code   TEXT UNIQUE,
    bonus_limit     INTEGER DEFAULT 0,
    verified        INTEGER DEFAULT 0,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS pending_users (
    email         TEXT PRIMARY KEY COLLATE NOCASE,
    password_hash TEXT NOT NULL,
    otp           TEXT NOT NULL,
    attempts      INTEGER DEFAULT 0,
    name          TEXT,
    country       TEXT,
    state         TEXT,
    district      TEXT,
    pin           TEXT,
    address       TEXT,
    referral      TEXT,
    created_at    INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS rate_limits (
    user_key TEXT NOT NULL,
    count    INTEGER DEFAULT 0,
    date     TEXT NOT NULL,
    PRIMARY KEY (user_key, date)
);

CREATE TABLE IF NOT EXISTS ip_limits (
    ip        TEXT NOT NULL PRIMARY KEY,
    count     INTEGER DEFAULT 0,
    window_ts INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS api_usage_log (
    id           INTEGER PRIMARY KEY AUTOINCREMENT,
    user_email   TEXT NOT NULL,
    type         TEXT NOT NULL,
    input_tokens INTEGER DEFAULT 0,
    output_tokens INTEGER DEFAULT 0,
    timestamp    DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS daily_stats (
    date  TEXT PRIMARY KEY,
    count INTEGER DEFAULT 0
);

CREATE TABLE IF NOT EXISTS recent_queries (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_email TEXT,
    query      TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS analytics_subject (
    subject TEXT PRIMARY KEY,
    count   INTEGER DEFAULT 0
);

CREATE TABLE IF NOT EXISTS bug_reports (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_email TEXT,
    page       TEXT,
    message    TEXT,
    device_id  TEXT,
    timestamp  DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS global_config (
    key   TEXT NOT NULL PRIMARY KEY,
    value TEXT NOT NULL
);

CREATE TABLE IF NOT EXISTS subscription_plans (
    id               INTEGER PRIMARY KEY AUTOINCREMENT,
    name             TEXT NOT NULL UNIQUE,
    display_name     TEXT NOT NULL,
    price_paise      INTEGER DEFAULT 0,
    daily_limit      INTEGER NOT NULL DEFAULT 20,
    features         TEXT NOT NULL DEFAULT '[]',
    razorpay_plan_id TEXT DEFAULT '',
    active           INTEGER DEFAULT 1,
    updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS user_subscriptions (
    id              INTEGER PRIMARY KEY AUTOINCREMENT,
    user_email      TEXT NOT NULL,
    plan_name       TEXT NOT NULL DEFAULT 'free',
    status          TEXT DEFAULT 'active',
    razorpay_sub_id TEXT DEFAULT '',
    start_date      DATETIME DEFAULT CURRENT_TIMESTAMP,
    end_date        DATETIME,
    created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS payments (
    id                  INTEGER PRIMARY KEY AUTOINCREMENT,
    user_email          TEXT NOT NULL,
    razorpay_payment_id TEXT DEFAULT '',
    razorpay_order_id   TEXT DEFAULT '',
    idempotency_key     TEXT NOT NULL DEFAULT '',
    amount_paise        INTEGER NOT NULL,
    plan_name           TEXT NOT NULL,
    status              TEXT DEFAULT 'pending',
    created_at          DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS fcm_tokens (
    id         INTEGER PRIMARY KEY AUTOINCREMENT,
    user_email TEXT NOT NULL,
    token      TEXT NOT NULL UNIQUE,
    plan_name  TEXT DEFAULT 'free',
    platform   TEXT DEFAULT 'android',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS telegram_users (
    telegram_id   TEXT PRIMARY KEY,
    first_name    TEXT,
    last_name     TEXT,
    username      TEXT,
    language_code TEXT,
    last_active   DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS password_resets (
    email      TEXT NOT NULL PRIMARY KEY COLLATE NOCASE,
    otp        TEXT NOT NULL,
    attempts   INTEGER DEFAULT 0,
    created_at INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS otp_requests (
    ip           TEXT PRIMARY KEY,
    count        INTEGER DEFAULT 0,
    last_request INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS login_attempts (
    email        TEXT NOT NULL PRIMARY KEY COLLATE NOCASE,
    attempts     INTEGER DEFAULT 0,
    locked_until INTEGER DEFAULT 0
);

CREATE INDEX IF NOT EXISTS idx_sub_email ON user_subscriptions(user_email);
CREATE INDEX IF NOT EXISTS idx_pay_email ON payments(user_email);
CREATE INDEX IF NOT EXISTS idx_pay_order ON payments(razorpay_order_id);
CREATE INDEX IF NOT EXISTS idx_fcm_email ON fcm_tokens(user_email);
CREATE INDEX IF NOT EXISTS idx_recent_queries_user ON recent_queries(user_email, id);
CREATE INDEX IF NOT EXISTS idx_login_attempts_locked ON login_attempts(locked_until);
SQL
    );

    $db->exec("INSERT OR IGNORE INTO global_config (key, value) VALUES
        ('daily_limit', '100'),
        ('announcement_text', ''),
        ('announcement_active', '0'),
        ('free_daily_limit', '20'),
        ('pro_daily_limit', '100'),
        ('premium_daily_limit', '9999'),
        ('voice_enabled', '1'),
        ('camera_enabled', '1'),
        ('quiz_enabled', '1'),
        ('subscription_enabled', '1'),
        ('maintenance_mode', '0'),
        ('maintenance_message', 'We are upgrading NexA AI. Back soon!'),
        ('app_version_required', '1.0.0'),
        ('app_update_url', 'https://play.google.com/store/apps/details?id=live.nexzen.nexa'),
        ('welcome_message', ''),
        ('free_trial_days', '0')");

    $db->exec("INSERT OR IGNORE INTO subscription_plans
        (name, display_name, price_paise, daily_limit, features) VALUES
        ('free', 'Free', 0, 20, '[\"20 questions/day\",\"Voice Input\",\"Basic Quiz\"]'),
        ('pro', 'Pro', 9900, 100, '[\"100 questions/day\",\"Voice Input\",\"Camera/Image\",\"Full Quiz\",\"Offline Mode\",\"No Ads\"]'),
        ('premium', 'Premium', 19900, 9999, '[\"Unlimited questions\",\"All Pro features\",\"Priority AI Speed\",\"Exclusive Badge\"]')");
};