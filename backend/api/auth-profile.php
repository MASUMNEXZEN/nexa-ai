<?php
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('GET, POST, OPTIONS');
error_reporting(0); ini_set('display_errors', '0');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
session_start();

header('Content-Type: application/json; charset=utf-8');



$email = null;

if (!empty($_SESSION['user_email'])) {
    $email = $_SESSION['user_email'];
} elseif (!empty($_COOKIE['nexa_token'])) {
    $cookieToken = $_COOKIE['nexa_token'];
    $db = get_db_connection();
    if ($db) {
        $row = nexa_resolve_persistent_token($db, $cookieToken);
        if ($row) { $email = $row['email']; $_SESSION['user_email'] = $email; }
    }
}


if (!$email) {
    http_response_code(401); echo json_encode(['error' => 'Not authenticated']); exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input) {
    http_response_code(400); echo json_encode(['error' => 'Invalid input']); exit;
}

$name     = trim($input['name']     ?? '');
$country  = trim($input['country']  ?? '');
$state    = trim($input['state']    ?? '');
$district = trim($input['district'] ?? '');
$pin      = trim($input['pin']      ?? '');
$address  = trim($input['address']  ?? '');

$db = $db ?? get_db_connection();
$sqliteUpdated = false;
if ($db) {
    $stmt = $db->prepare("UPDATE users SET name=?, country=?, state=?, district=?, pin=?, address=? WHERE email=?");
    $sqliteUpdated = $stmt->execute([$name, $country, $state, $district, $pin, $address, $email]);
    if ($sqliteUpdated && $stmt->rowCount() === 0) {
        // User doesn't exist in SQLite yet, insert them
        $db->prepare("INSERT INTO users (email, name, country, state, district, pin, address, type, verified)
                     VALUES (?, ?, ?, ?, ?, ?, ?, 'email', 1)")
           ->execute([$email, $name, $country, $state, $district, $pin, $address]);
    }
}

echo json_encode(['success' => true]);


