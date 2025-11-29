<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/age_standards.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php'); exit;
}

$user_id = $_SESSION['user_id'];
$activity_date = $_POST['activity_date'] ?? date('Y-m-d');
$steps = (int)($_POST['steps'] ?? 0);
$time_minutes = (int)($_POST['time_minutes'] ?? 0);
$water_ml = (int)($_POST['water_ml'] ?? 0);

// 驗證日期格式
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $activity_date)) {
    $activity_date = date('Y-m-d');
}

// 取得使用者出生日期，計算年齡
$stmt = $pdo->prepare('SELECT birth_date FROM users WHERE user_id = ?');
$stmt->execute([$user_id]);
$user_data = $stmt->fetch();
$birth_date = $user_data['birth_date'] ?? null;
$age = calculate_age($birth_date);

// 根據年齡計算點數
$points_result = calculate_points_by_age($age, $steps, $time_minutes, $water_ml);
$points = $points_result['total'];

// MONEY intentionally disabled on activity submission: only points are granted
$money = 0;

try {
    $pdo->beginTransaction();
    // Always insert a new activity record (keep history)
    $stmt = $pdo->prepare('INSERT INTO activities (user_id, activity_date, steps, time_minutes, water_ml, points_earned, money_earned, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())');
    $stmt->execute([$user_id, $activity_date, $steps, $time_minutes, $water_ml, $points, $money]);

    // add to user's points total only (money not awarded via activity submissions)
    $stmt = $pdo->prepare('UPDATE users SET points = points + ? WHERE user_id = ?');
    $stmt->execute([$points,$user_id]);

    // team bonus: if user belongs to a team, give small extra points to team members (simple implementation)
    $stmt = $pdo->prepare('SELECT team_id FROM team_members WHERE user_id = ? LIMIT 1');
    $stmt->execute([$user_id]);
    $tm = $stmt->fetch();
    if ($tm) {
        // give each team member +1 point
        $stmt = $pdo->prepare('SELECT user_id FROM team_members WHERE team_id = ?');
        $stmt->execute([$tm['team_id']]);
        $members = $stmt->fetchAll();
        foreach ($members as $m) {
            $pdo->prepare('UPDATE users SET points = points + 1 WHERE user_id = ?')->execute([$m['user_id']]);
        }
    }

    $pdo->commit();
    header('Location: index.php'); exit;
} catch (Exception $e) {
    $pdo->rollBack();
    die('提交失敗: ' . htmlspecialchars($e->getMessage()));
}
