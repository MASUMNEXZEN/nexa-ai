<?php
require_once __DIR__ . '/security.php';
nexa_apply_security_headers('GET, POST, OPTIONS');
require_once __DIR__ . '/db.php';
nexa_require_admin();

$db = get_db();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {

    // Revenue totals
    $revenue = $db->query("SELECT SUM(amount_paise) as total, COUNT(*) as total_payments FROM payments WHERE status = 'success'")->fetch();
    $revenueThisMonth = $db->query("SELECT SUM(amount_paise) as total FROM payments WHERE status = 'success' AND created_at >= date('now','start of month')")->fetch();

    // Plan counts
    $planCounts = $db->query("SELECT plan_name, COUNT(*) as cnt FROM user_subscriptions WHERE status = 'active' GROUP BY plan_name")->fetchAll();
    $counts = ['free' => 0, 'pro' => 0, 'premium' => 0];
    foreach ($planCounts as $pc) $counts[$pc['plan_name']] = (int)$pc['cnt'];

    // Subscriber list (last 100)
    $subs = $db->query("
        SELECT us.user_email, us.plan_name, us.status, us.start_date, us.end_date, us.razorpay_sub_id,
               u.name, u.type
        FROM user_subscriptions us
        LEFT JOIN users u ON u.email = us.user_email
        ORDER BY us.created_at DESC LIMIT 100
    ")->fetchAll();

    // Payment history (last 50)
    $payments = $db->query("
        SELECT p.user_email, p.plan_name, p.amount_paise, p.status, p.created_at, p.razorpay_payment_id
        FROM payments p
        ORDER BY p.created_at DESC LIMIT 50
    ")->fetchAll();
    foreach ($payments as &$p) {
        $p['amount_inr'] = round((int)$p['amount_paise'] / 100);
    }

    echo json_encode([
        'revenue_total_paise'      => (int)($revenue['total'] ?? 0),
        'revenue_total_inr'        => round((int)($revenue['total'] ?? 0) / 100),
        'revenue_this_month_inr'   => round((int)($revenueThisMonth['total'] ?? 0) / 100),
        'total_payments'           => (int)($revenue['total_payments'] ?? 0),
        'plan_counts'              => $counts,
        'subscribers'              => $subs,
        'payments'                 => $payments,
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body      = json_decode(file_get_contents('php://input'), true);
    $action    = $body['action']     ?? ''; // upgrade | downgrade | cancel | reset_count | grant_bonus
    $userEmail = trim($body['email'] ?? '');
    $planName  = trim($body['plan']  ?? 'free');

    if (!$userEmail) { http_response_code(400); echo json_encode(['error' => 'Email required']); exit; }

    if ($action === 'upgrade' || $action === 'downgrade') {
        // Get plan info
        $plan = $db->prepare("SELECT * FROM subscription_plans WHERE name = ?");
        $plan->execute([$planName]);
        $plan = $plan->fetch();
        if (!$plan) { http_response_code(404); echo json_encode(['error' => 'Plan not found']); exit; }

        // Cancel existing
        $db->prepare("UPDATE user_subscriptions SET status = 'cancelled' WHERE user_email = ? AND status = 'active'")->execute([$userEmail]);
        // Add new
        $endDate = ($planName === 'free') ? null : date('Y-m-d H:i:s', strtotime('+30 days'));
        $db->prepare("INSERT INTO user_subscriptions (user_email, plan_name, status, start_date, end_date) VALUES (?, ?, 'active', CURRENT_TIMESTAMP, ?)")
           ->execute([$userEmail, $planName, $endDate]);
        // Update user type
        $newType = in_array($planName, ['pro', 'premium']) ? $planName : 'email';
        $db->prepare("UPDATE users SET type = ?, bonus_limit = ? WHERE email = ?")->execute([$newType, (int)$plan['daily_limit'], $userEmail]);
        // Update FCM
        $db->prepare("UPDATE fcm_tokens SET plan_name = ? WHERE user_email = ?")->execute([$planName, $userEmail]);
        echo json_encode(['success' => true, 'message' => "User plan set to {$planName}."]);

    } elseif ($action === 'cancel') {
        $db->prepare("UPDATE user_subscriptions SET status = 'cancelled' WHERE user_email = ? AND status = 'active'")->execute([$userEmail]);
        $db->prepare("UPDATE users SET type = 'email', bonus_limit = 0 WHERE email = ?")->execute([$userEmail]);
        echo json_encode(['success' => true, 'message' => 'Subscription cancelled. User moved to Free.']);

    } elseif ($action === 'reset_count') {
        $today = date('Y-m-d');
        $db->prepare("UPDATE rate_limits SET count = 0 WHERE user_key = ? AND date = ?")->execute([$userEmail, $today]);
        echo json_encode(['success' => true, 'message' => "Daily count reset for {$userEmail}."]);

    } elseif ($action === 'ban') {
        $db->prepare("UPDATE users SET type = 'banned' WHERE email = ?")->execute([$userEmail]);
        echo json_encode(['success' => true, 'message' => "User {$userEmail} banned."]);

    } elseif ($action === 'unban') {
        $db->prepare("UPDATE users SET type = 'email' WHERE email = ? AND type = 'banned'")->execute([$userEmail]);
        echo json_encode(['success' => true, 'message' => "User {$userEmail} unbanned."]);

    } elseif ($action === 'grant_bonus') {
        $bonus = (int)($body['bonus'] ?? 10);
        $db->prepare("UPDATE users SET bonus_limit = bonus_limit + ? WHERE email = ?")->execute([$bonus, $userEmail]);
        echo json_encode(['success' => true, 'message' => "{$bonus} bonus questions granted to {$userEmail}."]);

    } else {
        http_response_code(400);
        echo json_encode(['error' => "Unknown action: {$action}"]);
    }
}




