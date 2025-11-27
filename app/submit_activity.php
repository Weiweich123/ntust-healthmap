<?php
require_once __DIR__ . '/db.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php'); exit;
}

$user_id = $_SESSION['user_id'];
$steps = (int)($_POST['steps'] ?? 0);
$time_minutes = (int)($_POST['time_minutes'] ?? 0);
$water_ml = (int)($_POST['water_ml'] ?? 0);

// Simple points formula (you can tune these):
// 每1000步 = 2 點; 每30分鐘運動 = 3 點; 每500ml水 = 1 點
    $steps_points = floor($steps / 1000) * 2;
    $time_points = floor($time_minutes / 30) * 3;
    $water_points = floor($water_ml / 500) * 1;
    $points = max(0, $steps_points + $time_points + $water_points);
    // MONEY intentionally disabled on activity submission: only points are granted
    $money = 0;

try {
    $pdo->beginTransaction();
    // Insert a new activity record every time the user submits.
    // Previously the table used a composite PK (user_id, activity_date) and the code
    // updated the existing row. We now record multiple submissions per user.
    $today = date('Y-m-d');
    $stmt = $pdo->prepare('INSERT INTO activities (user_id,activity_date,steps,time_minutes,water_ml,points_earned,money_earned) VALUES (?,?,?,?,?,?,?)');
    $stmt->execute([$user_id,$today,$steps,$time_minutes,$water_ml,$points,$money]);

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
