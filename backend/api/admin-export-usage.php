<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';

nexa_apply_security_headers('GET, OPTIONS');
nexa_require_admin();

require_once __DIR__ . '/db.php';

$db = get_db();
if (!$db) {
    http_response_code(503);
    echo 'Database unavailable';
    exit;
}

$stmt = $db->query(
    'SELECT id, user_email, type, input_tokens, output_tokens, timestamp
     FROM api_usage_log
     ORDER BY timestamp DESC'
);
$logs = $stmt->fetchAll();

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="nexa-ai-usage-' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-store');

$output = fopen('php://output', 'w');
fputcsv($output, ['ID', 'User Email', 'Request Type', 'Input Tokens', 'Output Tokens', 'Timestamp']);

foreach ($logs as $log) {
    fputcsv($output, [
        $log['id'],
        $log['user_email'],
        $log['type'],
        $log['input_tokens'],
        $log['output_tokens'],
        $log['timestamp'],
    ]);
}

fclose($output);