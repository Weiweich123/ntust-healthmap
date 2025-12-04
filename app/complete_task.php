<?php
require_once __DIR__ . '/db.php';
require_login();
header('Content-Type: application/json; charset=utf-8');

$user_id = $_SESSION['user_id'];
$task_id = (int)($_POST['task_id'] ?? 0);
if (!$task_id) {
    echo json_encode(['success'=>false,'error'=>'invalid_task']);
    exit;
}

try {
    $pdo->beginTransaction();

    // lock the task by task_id
    $stmt = $pdo->prepare('SELECT task_id,team_id,title,points,completed_at FROM team_tasks WHERE task_id=? FOR UPDATE');
    $stmt->execute([$task_id]);
    $task = $stmt->fetch();
    if (!$task) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'error'=>'not_found']); exit;
    }
    if ($task['completed_at'] !== null) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'error'=>'already_completed']); exit;
    }

    $team_id = (int)$task['team_id'];

    // check user belongs to team
    $stmt = $pdo->prepare('SELECT 1 FROM team_members WHERE team_id=? AND user_id=? LIMIT 1');
    $stmt->execute([$team_id,$user_id]);
    if (!$stmt->fetch()) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'error'=>'not_member']); exit;
    }

    // 檢查是否已經確認過
    $stmt = $pdo->prepare('SELECT 1 FROM task_confirmations WHERE task_id=? AND user_id=? LIMIT 1');
    $stmt->execute([$task_id, $user_id]);
    if ($stmt->fetch()) {
        $pdo->rollBack();
        echo json_encode(['success'=>false,'error'=>'already_confirmed']); exit;
    }

    // 記錄此用戶的確認
    $stmt = $pdo->prepare('INSERT INTO task_confirmations (task_id, user_id) VALUES (?, ?)');
    $stmt->execute([$task_id, $user_id]);

    // 獲取團隊總人數
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM team_members WHERE team_id=?');
    $stmt->execute([$team_id]);
    $total_members = (int)$stmt->fetchColumn();

    // 獲取已確認人數
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM task_confirmations WHERE task_id=?');
    $stmt->execute([$task_id]);
    $confirmed_count = (int)$stmt->fetchColumn();

    // 檢查是否所有成員都已確認
    if ($confirmed_count >= $total_members) {
        // 所有成員都確認了，完成任務並發放點數
        $upd = $pdo->prepare('UPDATE team_tasks SET completed_at=NOW() WHERE task_id=?');
        $upd->execute([$task_id]);

        $pts = (int)$task['points'];
        $task_title = $task['title'];

        // 給所有團隊成員發放點數
        $stmt = $pdo->prepare('SELECT user_id FROM team_members WHERE team_id=?');
        $stmt->execute([$team_id]);
        $all_members = $stmt->fetchAll();

        foreach ($all_members as $member) {
            $member_id = (int)$member['user_id'];
            // 更新用戶點數
            $upd2 = $pdo->prepare('UPDATE users SET points = points + ? WHERE user_id=?');
            $upd2->execute([$pts, $member_id]);

            // 記錄點數到 points_logs
            $log_stmt = $pdo->prepare('INSERT INTO points_logs (user_id, amount, source, description, related_id, created_at) VALUES (?, ?, ?, ?, ?, NOW())');
            $log_stmt->execute([$member_id, $pts, 'team_task', "完成團隊任務：{$task_title}", $task_id]);
        }

        // 創建新的隨機任務
        $pool = [
            ['title'=>'團隊步行 5000 步','points'=>10],
            ['title'=>'一起喝 8 杯水','points'=>8],
            ['title'=>'團體做 20 分鐘伸展','points'=>12],
            ['title'=>'完成 30 分鐘有氧運動','points'=>15],
            ['title'=>'共同完成 10000 步（分攤）','points'=>18],
            ['title'=>'早睡 8 小時一次','points'=>8],
            ['title'=>'完成 10 次深蹲','points'=>7],
            ['title'=>'完成 15 分鐘核心訓練','points'=>9],
            ['title'=>'團隊騎車 5 公里','points'=>14]
        ];
        $pick = $pool[array_rand($pool)];
        $ins = $pdo->prepare('INSERT INTO team_tasks (team_id,title,points) VALUES (?,?,?)');
        $ins->execute([$team_id,$pick['title'],$pick['points']]);
        $new_task_id = (int)$pdo->lastInsertId();

        $pdo->commit();

        echo json_encode([
            'success'=>true,
            'task_completed'=>true,
            'awarded_points'=>$pts,
            'confirmed_count'=>$confirmed_count,
            'total_members'=>$total_members,
            'new_task'=>[
                'task_id'=>$new_task_id,
                'team_id'=>$team_id,
                'title'=>$pick['title'],
                'points'=>$pick['points']
            ]
        ]);
    } else {
        // 還有成員未確認
        $pdo->commit();

        echo json_encode([
            'success'=>true,
            'task_completed'=>false,
            'confirmed_count'=>$confirmed_count,
            'total_members'=>$total_members,
            'message'=>"已確認！等待其他成員確認 ({$confirmed_count}/{$total_members})"
        ]);
    }
    exit;
} catch (Exception $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    echo json_encode(['success'=>false,'error'=>'exception','message'=>$e->getMessage()]);
    exit;
}

?>
