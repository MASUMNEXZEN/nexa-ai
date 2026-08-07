<?php
// Script to export Telegram AI Users to CSV
// Intended as an admin-only feature

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('GET, OPTIONS');
nexa_require_admin();
// Security Check - Ensure user is logged in as admin

// Ensure no buffering is active
if (ob_get_level()) {
    ob_end_clean();
}

// Data Directory
$telegramDataDir = DATA_DIR . 'telegram/';
$users = [];

if (is_dir($telegramDataDir)) {
    $files = glob($telegramDataDir . '*_profile.json');
    foreach ($files as $file) {
        $id = str_replace('_profile.json', '', basename($file));
        $data = json_decode(file_get_contents($file), true);
        if ($data) {
            $data['telegram_id'] = $id;
            $users[] = $data;
        }
    }
}

// Sort by last active descending
usort($users, function($a, $b) {
    $timeA = isset($a['last_active']) ? strtotime($a['last_active']) : 0;
    $timeB = isset($b['last_active']) ? strtotime($b['last_active']) : 0;
    return $timeB - $timeA;
});

// Prepare CSV Headers
$filename = "nexzen_telegram_users_" . date('Y-m-d') . ".csv";

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Pragma: no-cache');
header('Expires: 0');

$output = fopen('php://output', 'w');

// Add UTF-8 BOM for proper Excel rendering
fputs($output, $bom = (chr(0xEF) . chr(0xBB) . chr(0xBF)));

// Define columns
$columns = [
    'Telegram ID',
    'Phone Number',
    'First Name',
    'Last Name',
    'Username',
    'Daily Queries (Today)',
    'Last Active Date'
];
fputcsv($output, $columns);

// Write data
foreach ($users as $u) {
    $firstName = isset($u['first_name']) ? $u['first_name'] : '';
    $lastName = isset($u['last_name']) ? $u['last_name'] : '';
    $username = isset($u['username']) ? $u['username'] : '';
    $phone = isset($u['phone_number']) ? $u['phone_number'] : 'N/A';
    $dailyQueries = isset($u['daily_queries']) ? $u['daily_queries'] : '0';
    $lastActive = isset($u['last_active']) ? $u['last_active'] : 'Unknown';

    fputcsv($output, [
        $u['telegram_id'],
        $phone,
        $firstName,
        $lastName,
        $username,
        $dailyQueries,
        $lastActive
    ]);
}

fclose($output);
exit;





