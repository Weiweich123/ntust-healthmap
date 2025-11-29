<?php
require_once __DIR__ . '/db.php';
require_login();

// expects JSON post {building_id: N}
$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!isset($data['building_id'])) {
    header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>'missing building_id']); exit;
}
$bid = (int)$data['building_id'];
$uid = $_SESSION['user_id'];

try {
    // load building cost
    $stmt = $pdo->prepare('SELECT unlock_cost, reward_money FROM buildings WHERE building_id = ?');
    $stmt->execute([$bid]);
    $b = $stmt->fetch();
    if (!$b) throw new Exception('找不到建築');

    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT points FROM users WHERE user_id = ? FOR UPDATE');
    $stmt->execute([$uid]);
    $u = $stmt->fetch();
    if (!$u) throw new Exception('找不到使用者');
    if ($u['points'] < $b['unlock_cost']) throw new Exception('點數不足');

    // check already unlocked
    $stmt = $pdo->prepare('SELECT level FROM user_buildings WHERE user_id=? AND building_id=?');
    $stmt->execute([$uid,$bid]);
    if ($stmt->fetch()) throw new Exception('已解鎖');

    // deduct points, add money, insert user_buildings
    $stmt = $pdo->prepare('UPDATE users SET points = points - ?, money = money + ? WHERE user_id = ?');
    $stmt->execute([$b['unlock_cost'], $b['reward_money'], $uid]);

    $stmt = $pdo->prepare('INSERT INTO user_buildings (user_id,building_id,level,unlocked_at) VALUES (?,?,?,NOW())');
    $stmt->execute([$uid,$bid,1]);

    // 記錄金錢獲得紀錄
    $stmt = $pdo->prepare('SELECT name FROM buildings WHERE building_id = ?');
    $stmt->execute([$bid]);
    $building = $stmt->fetch();
    $building_name = $building ? $building['name'] : '建築';
    
    $stmt = $pdo->prepare('INSERT INTO money_logs (user_id, amount, source, description, related_id) VALUES (?, ?, ?, ?, ?)');
    $stmt->execute([$uid, $b['reward_money'], 'building_unlock', "解鎖 {$building_name}", $bid]);

    $pdo->commit();
    // return updated level and user points/money for front-end convenience
    $stmt = $pdo->prepare('SELECT points,money FROM users WHERE user_id = ?');
    $stmt->execute([$uid]);
    $u2 = $stmt->fetch();
    header('Content-Type: application/json'); echo json_encode(['success'=>true,'message'=>'解鎖成功','level'=>1,'points'=>$u2['points'],'money'=>$u2['money']]); exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit;
}
