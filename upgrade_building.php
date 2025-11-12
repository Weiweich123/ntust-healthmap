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
    $pdo->beginTransaction();
    $stmt = $pdo->prepare('SELECT ub.level, b.unlock_cost FROM user_buildings ub JOIN buildings b ON ub.building_id=b.building_id WHERE ub.user_id=? AND ub.building_id=? FOR UPDATE');
    $stmt->execute([$uid,$bid]);
    $row = $stmt->fetch();
    if (!$row) throw new Exception('尚未解鎖，無法升級');
    $level = (int)$row['level'];
    if ($level >= 9) throw new Exception('已達最高等級');

    // define upgrade cost: base unlock_cost * (level + 1)
    $upgrade_cost = max(1, (int)$row['unlock_cost']) * ($level + 1);

    // check user points
    $stmt = $pdo->prepare('SELECT points FROM users WHERE user_id = ? FOR UPDATE');
    $stmt->execute([$uid]);
    $u = $stmt->fetch();
    if ($u['points'] < $upgrade_cost) throw new Exception('點數不足升級');

    // deduct points and increase level and reward money proportionally
    $reward = floor($upgrade_cost / 2);
    $stmt = $pdo->prepare('UPDATE users SET points = points - ?, money = money + ? WHERE user_id = ?');
    $stmt->execute([$upgrade_cost, $reward, $uid]);

    $stmt = $pdo->prepare('UPDATE user_buildings SET level = level + 1 WHERE user_id = ? AND building_id = ?');
    $stmt->execute([$uid,$bid]);

    $pdo->commit();
    // return updated level and user balances
    $stmt = $pdo->prepare('SELECT ub.level, u.points, u.money FROM user_buildings ub JOIN users u ON u.user_id = ? AND ub.user_id = u.user_id WHERE ub.user_id = ? AND ub.building_id = ?');
    $stmt->execute([$uid,$uid,$bid]);
    $res = $stmt->fetch();
    header('Content-Type: application/json'); echo json_encode(['success'=>true,'message'=>'升級成功','level'=>$res['level'],'points'=>$res['points'],'money'=>$res['money']]); exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    header('Content-Type: application/json'); echo json_encode(['success'=>false,'message'=>$e->getMessage()]); exit;
}
