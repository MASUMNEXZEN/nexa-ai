<?php
require_once __DIR__ . '/config.php';

/** Open a database that has been prepared by the protected migration runner. */
function nexa_open_migrated_database(string $file, string $label, int $expectedVersion): PDO
{
    $db = new PDO('sqlite:' . $file);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA journal_mode = wal');
    $db->exec('PRAGMA synchronous = NORMAL');
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA busy_timeout = 10000');

    $migrationTable = $db->query(
        "SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'nexa_schema_migrations' LIMIT 1"
    )->fetchColumn();
    if ($migrationTable === false) {
        throw new RuntimeException("{$label} database is not migrated. Run php scripts/migrate.php.");
    }

    $versionCount = (int)$db->query('SELECT COUNT(*) FROM nexa_schema_migrations')->fetchColumn();
    $currentVersion = (int)$db->query('SELECT COALESCE(MAX(version), 0) FROM nexa_schema_migrations')->fetchColumn();
    if ($versionCount < $expectedVersion || $currentVersion !== $expectedVersion) {
        throw new RuntimeException("{$label} database schema is at version {$currentVersion}; expected {$expectedVersion}.");
    }
    return $db;
}

/**
 * Main Database connection (Users, Limits, Usage, History)
 */
function get_db()
{
    static $db = null;
    if ($db instanceof PDO) {
        return $db;
    }

    try {
        $db = nexa_open_migrated_database(DATA_DIR . 'nexa.sqlite', 'Main', NEXA_MAIN_SCHEMA_VERSION);
        return $db;
    } catch (Throwable $error) {
        error_log('Main DB Error: ' . $error->getMessage());
        return null;
    }
}

function nexa_consume_window(PDO $db, string $key, int $windowSeconds, int $maxCount): bool
{
    $window = (int)floor(time() / $windowSeconds);
    $db->prepare(
        "INSERT OR IGNORE INTO ip_limits (ip, count, window_ts) VALUES (?, 0, ?)"
    )->execute([$key, $window]);
    $db->prepare(
        "UPDATE ip_limits SET count = 0, window_ts = ?
         WHERE ip = ? AND window_ts < ?"
    )->execute([$window, $key, $window]);

    $update = $db->prepare(
        "UPDATE ip_limits SET count = count + 1
         WHERE ip = ? AND window_ts = ? AND count < ?"
    );
    $update->execute([$key, $window, $maxCount]);
    return $update->rowCount() === 1;
}

/** Alias retained for endpoints that use the explicit connection name. */
function get_db_connection()
{
    return get_db();
}

/** Cache Database connection for quizzes and AI response cache. */
function get_cache_db()
{
    static $cacheDb = null;
    if ($cacheDb instanceof PDO) {
        return $cacheDb;
    }

    try {
        $cacheDb = nexa_open_migrated_database(DATA_DIR . 'nexa-cache.sqlite', 'Cache', NEXA_CACHE_SCHEMA_VERSION);
        return $cacheDb;
    } catch (Throwable $error) {
        error_log('Cache DB Error: ' . $error->getMessage());
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

