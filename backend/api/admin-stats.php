<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('GET, OPTIONS');
nexa_require_admin();
require_once __DIR__ . '/db.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');


$db = get_db();
$today = date('Y-m-d');

// 1. Subject Distribution
$subjectStats = [];
try {
    $stmt = $db->query("SELECT subject, count FROM analytics_subject ORDER BY count DESC");
    while ($row = $stmt->fetch()) {
        $subjectStats[$row['subject']] = (int)$row['count'];
    }
} catch (Exception $e) {}

// 2. Global Traffic Today
$totalQuestionsToday = 0;
try {
    $stmt = $db->prepare("SELECT count FROM daily_stats WHERE date = ?");
    $stmt->execute([$today]);
    $row = $stmt->fetch();
    if ($row) $totalQuestionsToday = (int)$row['count'];
} catch (Exception $e) {}

// 3. Active Users Today (from rate_limits)
$activeUsersStats = [];
try {
    $stmt = $db->prepare("SELECT user_key, count FROM rate_limits WHERE date = ? ORDER BY count DESC LIMIT 50");
    $stmt->execute([$today]);
    while ($row = $stmt->fetch()) {
        $activeUsersStats[] = [
            'email' => str_replace('appmode-', 'GUEST: ', $row['user_key']),
            'count' => (int)$row['count']
        ];
    }
} catch (Exception $e) {}

// 4. Registered Users (Web Accounts)
$registeredUsers = [];
$totalUsers = 0;
try {
    $stmt = $db->query("SELECT COUNT(*) as total FROM users");
    $totalUsers = (int)$stmt->fetch()['total'];

    $stmt = $db->query("SELECT id, email, name, type, country, state, district, pin, verified, bonus_limit, created_at FROM users ORDER BY created_at DESC LIMIT 500");
    $registeredUsers = $stmt->fetchAll();
} catch (Exception $e) {}

// 5. Daily Limit
$currentLimit = DEFAULT_DAILY_LIMIT;
try {
    $stmt = $db->prepare("SELECT value FROM global_config WHERE key = 'daily_limit'");
    $stmt->execute();
    $row = $stmt->fetch();
    if ($row) $currentLimit = (int)$row['value'];
} catch (Exception $e) {}

// 6. Volume Trend (Last 7 Days)
$graphDates = [];
$graphCounts = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $graphDates[] = date('M j', strtotime($d));
    
    $dayCount = 0;
    try {
        $stmt = $db->prepare("SELECT count FROM daily_stats WHERE date = ?");
        $stmt->execute([$d]);
        $r = $stmt->fetch();
        $dayCount = $r ? (int)$r['count'] : 0;
    } catch (Exception $e) {}
    $graphCounts[] = $dayCount;
}

// 7. Recent Queries (Live Firehose)
$recentQueries = [];
try {
    $stmt = $db->query("SELECT user_email AS user, query, created_at AS time FROM recent_queries ORDER BY id DESC LIMIT 100");
    $recentQueries = $stmt->fetchAll();
    foreach ($recentQueries as &$rq) {
        if (strpos($rq['user'], 'appmode-') === 0) $rq['user'] = 'GUEST: ' . substr($rq['user'], 8, 8);
        $rq['time'] = date('H:i:s', strtotime($rq['time']));
    }
} catch (Exception $e) {}

echo json_encode([
    'totalQuestionsToday' => $totalQuestionsToday,
    'currentLimit'        => $currentLimit,
    'activeUsersStats'    => $activeUsersStats,
    'totalUsers'          => $totalUsers,
    'registeredUsers'     => $registeredUsers,
    'graphDates'          => $graphDates,
    'graphCounts'         => $graphCounts,
    'recentQueries'       => $recentQueries,
    'subjectStats'        => $subjectStats,
    'date'                => $today
], JSON_INVALID_UTF8_SUBSTITUTE);
exit;




