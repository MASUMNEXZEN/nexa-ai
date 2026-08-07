<?php
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('GET, POST, OPTIONS');
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';

$db = get_db_connection();
if (!$db) { echo json_encode(['leaders' => []]); exit; }

$today = date('Y-m-d');

// Top 10 students by correct answers today
$stmt = $db->prepare("
    SELECT 
        u.email,
        COALESCE(u.profile, '{}') as profile_json,
        COUNT(CASE WHEN h.is_correct = 1 THEN 1 END) as score,
        COUNT(h.id) as total_attempted
    FROM user_quiz_history h
    JOIN users u ON h.user_email = u.email
    WHERE date(h.timestamp) = ?
    GROUP BY h.user_email
    HAVING total_attempted > 0
    ORDER BY score DESC, total_attempted ASC
    LIMIT 10
");
$stmt->execute([$today]);
$rows = $stmt->fetchAll();

$leaders = array_map(function($row) {
    $profile = json_decode($row['profile_json'] ?? '{}', true) ?: [];
    $name    = $profile['name'] ?? '';
    // Anonymize: show only first name + initial of last
    $parts = array_filter(explode(' ', trim($name)));
    if (count($parts) >= 2) {
        $display = $parts[0] . ' ' . strtoupper(substr(end($parts), 0, 1)) . '.';
    } elseif (count($parts) === 1) {
        $display = $parts[0];
    } else {
        // Use email prefix, partially masked
        $email = $row['email'] ?? '';
        $prefix = explode('@', $email)[0];
        $display = strlen($prefix) > 3
            ? substr($prefix, 0, 2) . str_repeat('*', strlen($prefix) - 2)
            : $prefix;
    }
    return [
        'name'  => $display,
        'score' => (int)$row['score'],
        'total' => (int)$row['total_attempted'],
    ];
}, $rows);

echo json_encode(['leaders' => $leaders, 'date' => $today]);

