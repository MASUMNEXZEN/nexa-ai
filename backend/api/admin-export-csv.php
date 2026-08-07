<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('GET, OPTIONS');
nexa_require_admin();
require_once __DIR__ . '/db.php';


$db = get_db();
if (!$db) {
    echo "Database Error";
    exit;
}

$stmt = $db->query("SELECT id, name, email, type, country, state, district, pin, address, created_at FROM users ORDER BY id ASC");
$users = $stmt->fetchAll();

// Set CSV headers
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="nexa-ai-users-' . date('Y-m-d') . '.csv"');
header('Cache-Control: no-cache, must-revalidate');

$output = fopen('php://output', 'w');

// Header row
fputcsv($output, ['#', 'Name', 'Email', 'Type', 'Country', 'State', 'District', 'PIN', 'Address', 'Joined']);

foreach ($users as $idx => $u) {
    fputcsv($output, [
        $idx + 1,
        $u['name'] ?? '',
        $u['email'] ?? '',
        $u['type'] ?? 'email',
        $u['country'] ?? '',
        $u['state'] ?? '',
        $u['district'] ?? '',
        $u['pin'] ?? '',
        $u['address'] ?? '',
        $u['created_at'] ?? ''
    ]);
}

fclose($output);





