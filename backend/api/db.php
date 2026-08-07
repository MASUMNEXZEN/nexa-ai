<?php
require_once __DIR__ . '/config.php';

if (!is_dir(DATA_DIR)) {
    mkdir(DATA_DIR, 0755, true);
}

/**
 * Main Database connection (Users, Limits, Usage, History)
 */
function get_db() {
    static $db = null;
    if ($db) return $db;

    $dbFile = DATA_DIR . 'nexa.sqlite';
    try {
        $db = new PDO("sqlite:" . $dbFile);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec('PRAGMA journal_mode = wal;');
        $db->exec('PRAGMA synchronous = NORMAL;');

        $db->exec("CREATE TABLE IF NOT EXISTS users (
            id            INTEGER PRIMARY KEY AUTOINCREMENT,
            email         TEXT    NOT NULL UNIQUE COLLATE NOCASE,
            password_hash TEXT,
            auth_token    TEXT,
            auth_token_hash TEXT,
            type          TEXT    DEFAULT 'email',
            name          TEXT    DEFAULT '',
            country       TEXT    DEFAULT '',
            state         TEXT    DEFAULT '',
            district      TEXT    DEFAULT '',
            pin           TEXT    DEFAULT '',
            address       TEXT    DEFAULT '',
            google_sub    TEXT,
            bonus_limit   INTEGER DEFAULT 0,
            verified      INTEGER DEFAULT 0,
            created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        
        try {
            $db->exec("ALTER TABLE users ADD COLUMN referral_code TEXT UNIQUE");
            $db->exec("CREATE INDEX idx_users_referral ON users(referral_code)");
        } catch (Exception $e) { /* Column likely exists */ }

        $db->exec("CREATE TABLE IF NOT EXISTS pending_users (
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
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            user_key  TEXT    NOT NULL,
            count     INTEGER DEFAULT 0,
            date      TEXT    NOT NULL,
            PRIMARY KEY (user_key, date)
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS ip_limits (
            ip        TEXT    NOT NULL PRIMARY KEY,
            count     INTEGER DEFAULT 0,
            window_ts INTEGER NOT NULL
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS api_usage_log (
            id           INTEGER PRIMARY KEY AUTOINCREMENT,
            user_email   TEXT    NOT NULL,
            type         TEXT    NOT NULL, -- 'chat' or 'quiz'
            input_tokens INTEGER DEFAULT 0,
            output_tokens INTEGER DEFAULT 0,
            timestamp    DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS daily_stats (
            date  TEXT PRIMARY KEY,
            count INTEGER DEFAULT 0
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS recent_queries (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_email TEXT,
            query      TEXT,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS analytics_subject (
            subject TEXT PRIMARY KEY,
            count   INTEGER DEFAULT 0
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS bug_reports (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_email TEXT,
            page       TEXT,
            message    TEXT,
            device_id  TEXT,
            timestamp  DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        static $configChecked = false;
        if (!$configChecked) {
            $db->exec("CREATE TABLE IF NOT EXISTS global_config (
                key   TEXT NOT NULL PRIMARY KEY,
                value TEXT NOT NULL
            )");
            // Core config
            $db->exec("INSERT OR IGNORE INTO global_config (key, value) VALUES ('daily_limit', '" . DEFAULT_DAILY_LIMIT . "')");
            $db->exec("INSERT OR IGNORE INTO global_config (key, value) VALUES ('announcement_text', '')");
            $db->exec("INSERT OR IGNORE INTO global_config (key, value) VALUES ('announcement_active', '0')");
            // Plan-specific limits
            $db->exec("INSERT OR IGNORE INTO global_config (key, value) VALUES ('free_daily_limit', '20')");
            $db->exec("INSERT OR IGNORE INTO global_config (key, value) VALUES ('pro_daily_limit', '100')");
            $db->exec("INSERT OR IGNORE INTO global_config (key, value) VALUES ('premium_daily_limit', '9999')");
            // Feature flags
            $db->exec("INSERT OR IGNORE INTO global_config (key, value) VALUES ('voice_enabled', '1')");
            $db->exec("INSERT OR IGNORE INTO global_config (key, value) VALUES ('camera_enabled', '1')");
            $db->exec("INSERT OR IGNORE INTO global_config (key, value) VALUES ('quiz_enabled', '1')");
            $db->exec("INSERT OR IGNORE INTO global_config (key, value) VALUES ('subscription_enabled', '1')");
            // Maintenance mode
            $db->exec("INSERT OR IGNORE INTO global_config (key, value) VALUES ('maintenance_mode', '0')");
            $db->exec("INSERT OR IGNORE INTO global_config (key, value) VALUES ('maintenance_message', 'We are upgrading NexA AI. Back soon!')");
            // App version gate
            $db->exec("INSERT OR IGNORE INTO global_config (key, value) VALUES ('app_version_required', '1.0.0')");
            $db->exec("INSERT OR IGNORE INTO global_config (key, value) VALUES ('app_update_url', 'https://play.google.com/store/apps/details?id=live.nexzen.nexa')");
            // Content config
            $db->exec("INSERT OR IGNORE INTO global_config (key, value) VALUES ('welcome_message', '')");
            $db->exec("INSERT OR IGNORE INTO global_config (key, value) VALUES ('free_trial_days', '0')");
            $configChecked = true;
        }

        $db->exec("CREATE TABLE IF NOT EXISTS subscription_plans (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            name             TEXT NOT NULL UNIQUE,
            display_name     TEXT NOT NULL,
            price_paise      INTEGER DEFAULT 0,
            daily_limit      INTEGER NOT NULL DEFAULT 20,
            features         TEXT NOT NULL DEFAULT '[]',
            razorpay_plan_id TEXT DEFAULT '',
            active           INTEGER DEFAULT 1,
            updated_at       DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $db->exec("INSERT OR IGNORE INTO subscription_plans (name, display_name, price_paise, daily_limit, features) VALUES
            ('free',    'Free',    0,     20,   '[\"20 questions/day\",\"Voice Input\",\"Basic Quiz\"]'),
            ('pro',     'Pro',     9900,  100,  '[\"100 questions/day\",\"Voice Input\",\"Camera/Image\",\"Full Quiz\",\"Offline Mode\",\"No Ads\"]'),
            ('premium', 'Premium', 19900, 9999, '[\"Unlimited questions\",\"All Pro features\",\"Priority AI Speed\",\"Exclusive Badge\"]')
        ");

        $db->exec("CREATE TABLE IF NOT EXISTS user_subscriptions (
            id               INTEGER PRIMARY KEY AUTOINCREMENT,
            user_email       TEXT NOT NULL,
            plan_name        TEXT NOT NULL DEFAULT 'free',
            status           TEXT DEFAULT 'active',
            razorpay_sub_id  TEXT DEFAULT '',
            start_date       DATETIME DEFAULT CURRENT_TIMESTAMP,
            end_date         DATETIME,
            created_at       DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_sub_email ON user_subscriptions(user_email)");

        $db->exec("CREATE TABLE IF NOT EXISTS payments (
            id                  INTEGER PRIMARY KEY AUTOINCREMENT,
            user_email          TEXT NOT NULL,
            razorpay_payment_id TEXT DEFAULT '',
            razorpay_order_id   TEXT DEFAULT '',
            amount_paise        INTEGER NOT NULL,
            plan_name           TEXT NOT NULL,
            status              TEXT DEFAULT 'pending',
            created_at          DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_pay_email ON payments(user_email)");

        $db->exec("CREATE TABLE IF NOT EXISTS fcm_tokens (
            id         INTEGER PRIMARY KEY AUTOINCREMENT,
            user_email TEXT NOT NULL,
            token      TEXT NOT NULL UNIQUE,
            plan_name  TEXT DEFAULT 'free',
            platform   TEXT DEFAULT 'android',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP
        )");
        $db->exec("CREATE INDEX IF NOT EXISTS idx_fcm_email ON fcm_tokens(user_email)");

        $db->exec("CREATE TABLE IF NOT EXISTS telegram_users (
            telegram_id  TEXT PRIMARY KEY,
            first_name   TEXT,
            last_name    TEXT,
            username     TEXT,
            language_code TEXT,
            last_active   DATETIME DEFAULT CURRENT_TIMESTAMP,
            created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        $db->exec("CREATE TABLE IF NOT EXISTS password_resets (
            email      TEXT NOT NULL PRIMARY KEY COLLATE NOCASE,
            otp        TEXT NOT NULL,
            attempts   INTEGER DEFAULT 0,
            created_at INTEGER NOT NULL
        )");
        $db->exec("CREATE TABLE IF NOT EXISTS otp_requests (
            ip           TEXT PRIMARY KEY,
            count        INTEGER DEFAULT 0,
            last_request INTEGER NOT NULL
        )");

        // Persistent auth token migration. Existing plaintext values are
        // upgraded when next used; new values are hash-only.
        try {
            $db->exec("ALTER TABLE users ADD COLUMN auth_token_hash TEXT");
        } catch (Exception $e) {}
        $db->exec("CREATE INDEX IF NOT EXISTS idx_users_auth_token_hash ON users(auth_token_hash)");
        return $db;
    } catch (PDOException $e) {
        error_log("Main DB Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Alias for get_db() — many endpoints use this name.
 */
function get_db_connection() {
    return get_db();
}

/**
 * Cache Database connection (Quizzes, AI response cache)
 * Segregated to keep the main DB small and fast.
 */
function get_cache_db() {
    static $cacheDb = null;
    if ($cacheDb) return $cacheDb;

    $dbFile = DATA_DIR . 'nexa-cache.sqlite';
    try {
        $cacheDb = new PDO("sqlite:" . $dbFile);
        $cacheDb->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $cacheDb->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $cacheDb->exec('PRAGMA journal_mode = wal;');
        $cacheDb->exec('PRAGMA synchronous = NORMAL;');

        $cacheDb->exec("CREATE TABLE IF NOT EXISTS quizzes (
            id             INTEGER PRIMARY KEY AUTOINCREMENT,
            topic          TEXT NOT NULL,
            difficulty     TEXT NOT NULL,
            lang           TEXT NOT NULL DEFAULT 'Bengali',
            question       TEXT NOT NULL,
            opt_a          TEXT NOT NULL,
            opt_b          TEXT NOT NULL,
            opt_c          TEXT NOT NULL,
            opt_d          TEXT NOT NULL,
            correct_answer TEXT NOT NULL,
            explanation    TEXT NOT NULL,
            sub_topic      TEXT DEFAULT '',
            created_at     DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        try {
            $cacheDb->exec("ALTER TABLE quizzes ADD COLUMN lang TEXT NOT NULL DEFAULT 'Bengali'");
        } catch (Exception $e) {}
        try {
            $cacheDb->exec("ALTER TABLE quizzes ADD COLUMN sub_topic TEXT DEFAULT ''");
        } catch (Exception $e) {}

        $cacheDb->exec("CREATE TABLE IF NOT EXISTS user_quiz_history (
            id                INTEGER PRIMARY KEY AUTOINCREMENT,
            user_email        TEXT NOT NULL,
            quiz_id           INTEGER NOT NULL,
            user_answer       TEXT NOT NULL DEFAULT '',
            is_correct        INTEGER NOT NULL DEFAULT 0,
            timestamp         DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Migrations: add columns that may be missing in older live databases
        try { $cacheDb->exec("ALTER TABLE user_quiz_history ADD COLUMN user_answer TEXT NOT NULL DEFAULT ''"); } catch (Exception $e) {}
        try { $cacheDb->exec("ALTER TABLE user_quiz_history ADD COLUMN is_correct INTEGER NOT NULL DEFAULT 0"); } catch (Exception $e) {}

        $cacheDb->exec("CREATE TABLE IF NOT EXISTS cache_responses (
            q_hash   TEXT PRIMARY KEY,
            question TEXT NOT NULL,
            answer   TEXT NOT NULL,
            ts       DATETIME DEFAULT CURRENT_TIMESTAMP
        )");

        // Migration: add ts column if it doesn't exist
        try {
            $cacheDb->exec("ALTER TABLE cache_responses ADD COLUMN ts DATETIME");
        } catch (Exception $e) {}

        // Auto-evict stale cache entries older than 14 days
        // Prevents hallucinated or incorrect AI answers from being served forever
        try {
            $cacheDb->exec("DELETE FROM cache_responses WHERE ts < datetime('now', '-14 days')");
        } catch (Exception $e) {
            // If the column ts is missing (e.g. older SQLite failed to alter table), drop the cache table so it recreates next time
            try { $cacheDb->exec("DROP TABLE IF EXISTS cache_responses"); } catch (Exception $ex) {}
        }

        return $cacheDb;
    } catch (PDOException $e) {
        error_log("Cache DB Error: " . $e->getMessage());
        return null;
    }
}

/**
 * Centralized, plan-aware rate limiter.
 *
 * The increment is guarded by the limit in the UPDATE predicate, making the
 * quota decision safe under concurrent requests.
 */
function check_nexa_limit($userKey, $isAppMode = false) {
    $db = get_db();
    if (!$db) return ['error' => 'Rate limit database unavailable.'];

    $clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    if (getenv('NEXA_TRUST_PROXY_HEADERS') === '1') {
        $clientIp = $_SERVER['HTTP_CF_CONNECTING_IP']
            ?? $_SERVER['HTTP_X_FORWARDED_FOR']
            ?? $clientIp;
    }
    if (strpos($clientIp, ',') !== false) {
        $clientIp = trim(explode(',', $clientIp)[0]);
    }

    if ($clientIp !== 'unknown') {
        $hourlyWindow = (int)floor(time() / 3600);
        $db->prepare(
            "INSERT OR IGNORE INTO ip_limits (ip, count, window_ts) VALUES (?, 0, ?)"
        )->execute([$clientIp, $hourlyWindow]);
        $db->prepare(
            "UPDATE ip_limits SET count = 0, window_ts = ?
             WHERE ip = ? AND window_ts < ?"
        )->execute([$hourlyWindow, $clientIp, $hourlyWindow]);

        $ipUpdate = $db->prepare(
            "UPDATE ip_limits
             SET count = count + 1
             WHERE ip = ? AND window_ts = ? AND count < 300"
        );
        $ipUpdate->execute([$clientIp, $hourlyWindow]);
        if ($ipUpdate->rowCount() !== 1) {
            return ['error' => 'Network traffic from this IP is too high. Please wait an hour.'];
        }
    }

    $today = date('Y-m-d');
    $limit = DEFAULT_DAILY_LIMIT;
    $planName = null;

    if (!$isAppMode && filter_var($userKey, FILTER_VALIDATE_EMAIL)) {
        $planStmt = $db->prepare(
            "SELECT plan_name
             FROM user_subscriptions
             WHERE user_email = ? AND status = 'active'
               AND (end_date IS NULL OR end_date >= CURRENT_TIMESTAMP)
             ORDER BY end_date DESC
             LIMIT 1"
        );
        $planStmt->execute([$userKey]);
        $activePlan = $planStmt->fetchColumn();
        $planName = $activePlan ? strtolower((string)$activePlan) : 'free';
    }

    if ($planName !== null) {
        $limitStmt = $db->prepare(
            "SELECT daily_limit FROM subscription_plans
             WHERE name = ? AND active = 1 LIMIT 1"
        );
        $limitStmt->execute([$planName]);
        $planLimit = $limitStmt->fetchColumn();
        if ($planLimit !== false) {
            $limit = max(0, (int)$planLimit);
        }
    } else {
        $limitStmt = $db->prepare(
            "SELECT value FROM global_config WHERE key = 'daily_limit' LIMIT 1"
        );
        $limitStmt->execute();
        $globalLimit = $limitStmt->fetchColumn();
        if ($globalLimit !== false) {
            $limit = max(0, (int)$globalLimit);
        }
    }

    $db->prepare(
        "INSERT OR IGNORE INTO rate_limits (user_key, count, date)
         VALUES (?, 0, ?)"
    )->execute([$userKey, $today]);

    $increment = $db->prepare(
        "UPDATE rate_limits
         SET count = count + 1
         WHERE user_key = ? AND date = ? AND count < ?"
    );
    $increment->execute([$userKey, $today, $limit]);

    if ($increment->rowCount() !== 1) {
        return [
            'error' => 'You have reached your daily limit of ' . $limit .
                ' questions. Please upgrade or try again tomorrow!'
        ];
    }

    $countStmt = $db->prepare(
        "SELECT count FROM rate_limits WHERE user_key = ? AND date = ? LIMIT 1"
    );
    $countStmt->execute([$userKey, $today]);
    $count = (int)$countStmt->fetchColumn();

    return [
        'count' => $count,
        'limit' => $limit,
        'remaining' => max(0, $limit - $count)
    ];
}
/**
 * Token Usage Logger
 */
function log_nexa_usage($userEmail, $type, $inputTokens, $outputTokens) {
    try {
        $db = get_db();
        if (!$db) return;
        $stmt = $db->prepare("INSERT INTO api_usage_log (user_email, type, input_tokens, output_tokens) VALUES (?, ?, ?, ?)");
        $stmt->execute([$userEmail, $type, (int)$inputTokens, (int)$outputTokens]);
    } catch (Exception $e) {}
}

