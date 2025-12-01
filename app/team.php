<?php
require_once __DIR__ . '/db.php';
require_login();

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// 處理退出/踢出團隊請求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'leave_team') {
        $team_id = (int)($_POST['team_id'] ?? 0);
        // 檢查自己是否在此團隊
        $stmt = $pdo->prepare('SELECT role FROM team_members WHERE team_id = ? AND user_id = ?');
        $stmt->execute([$team_id, $user_id]);
        $membership = $stmt->fetch();
        if ($membership) {
            $was_owner = ($membership['role'] === 'owner');
            
            // 刪除自己
            $stmt = $pdo->prepare('DELETE FROM team_members WHERE team_id = ? AND user_id = ?');
            $stmt->execute([$team_id, $user_id]);
            
            // 如果是擁有者退出，將擁有權轉移給下一位成員
            if ($was_owner) {
                // 找到下一位成員（按加入順序）
                $stmt = $pdo->prepare('SELECT user_id FROM team_members WHERE team_id = ? ORDER BY user_id ASC LIMIT 1');
                $stmt->execute([$team_id]);
                $next_member = $stmt->fetch();
                if ($next_member) {
                    // 將下一位成員設為擁有者
                    $stmt = $pdo->prepare('UPDATE team_members SET role = "owner" WHERE team_id = ? AND user_id = ?');
                    $stmt->execute([$team_id, $next_member['user_id']]);
                    $message = '已退出團隊，擁有者身分已轉移給下一位成員';
                } else {
                    // 如果沒有其他成員，刪除團隊
                    $stmt = $pdo->prepare('DELETE FROM teams WHERE team_id = ?');
                    $stmt->execute([$team_id]);
                    $message = '已退出團隊，團隊已解散';
                }
            } else {
                $message = '已退出團隊';
            }
        }
    } elseif ($_POST['action'] === 'kick_member') {
        $team_id = (int)($_POST['team_id'] ?? 0);
        $member_id = (int)($_POST['member_id'] ?? 0);
        // 檢查自己是否是 owner
        $stmt = $pdo->prepare('SELECT role FROM team_members WHERE team_id = ? AND user_id = ?');
        $stmt->execute([$team_id, $user_id]);
        $my_role = $stmt->fetch();
        if ($my_role && $my_role['role'] === 'owner') {
            // 不能踢自己
            if ($member_id != $user_id) {
                $stmt = $pdo->prepare('DELETE FROM team_members WHERE team_id = ? AND user_id = ?');
                $stmt->execute([$team_id, $member_id]);
                $message = '已將成員移出團隊';
            }
        } else {
            $error = '只有團隊擁有者可以移除成員';
        }
    } elseif ($_POST['action'] === 'accept_invite') {
        $invite_id = (int)($_POST['invite_id'] ?? 0);
        // 確認邀請存在且是給自己的
        $stmt = $pdo->prepare('SELECT * FROM team_invites WHERE invite_id = ? AND invitee_id = ? AND status = "pending"');
        $stmt->execute([$invite_id, $user_id]);
        $invite = $stmt->fetch();
        if ($invite) {
            // 加入團隊
            $stmt = $pdo->prepare('INSERT IGNORE INTO team_members (team_id, user_id, role) VALUES (?, ?, "member")');
            $stmt->execute([$invite['team_id'], $user_id]);
            // 更新邀請狀態
            $stmt = $pdo->prepare('UPDATE team_invites SET status = "accepted" WHERE invite_id = ?');
            $stmt->execute([$invite_id]);
            $message = '已成功加入團隊';
        } else {
            $error = '邀請不存在或已過期';
        }
    } elseif ($_POST['action'] === 'reject_invite') {
        $invite_id = (int)($_POST['invite_id'] ?? 0);
        // 確認邀請存在且是給自己的
        $stmt = $pdo->prepare('SELECT * FROM team_invites WHERE invite_id = ? AND invitee_id = ? AND status = "pending"');
        $stmt->execute([$invite_id, $user_id]);
        if ($stmt->fetch()) {
            // 更新邀請狀態為拒絕
            $stmt = $pdo->prepare('UPDATE team_invites SET status = "rejected" WHERE invite_id = ?');
            $stmt->execute([$invite_id]);
            $message = '已拒絕團隊邀請';
        }
    }
}

// 獲取待處理的團隊邀請
$stmt = $pdo->prepare('
    SELECT ti.invite_id, ti.team_id, ti.created_at,
           t.name AS team_name,
           u.display_name AS inviter_name, u.username AS inviter_username
    FROM team_invites ti
    JOIN teams t ON ti.team_id = t.team_id
    JOIN users u ON ti.inviter_id = u.user_id
    WHERE ti.invitee_id = ? AND ti.status = "pending"
    ORDER BY ti.created_at DESC
');
$stmt->execute([$user_id]);
$pending_invites = $stmt->fetchAll();

// 獲取用戶所在的所有團隊
$stmt = $pdo->prepare('SELECT t.team_id AS id, t.name, t.code FROM teams t JOIN team_members tm ON t.team_id=tm.team_id WHERE tm.user_id=?');
$stmt->execute([$user_id]);
$teams = $stmt->fetchAll();

// 任務池
$taskPool = [
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

// 為每個團隊確保有3個任務並獲取資料
$teamsData = [];
foreach ($teams as $team) {
  // 確保每個團隊有3個任務
  $stmt = $pdo->prepare('SELECT COUNT(*) FROM team_tasks WHERE team_id=? AND completed_at IS NULL');
  $stmt->execute([$team['id']]);
  $cnt = (int)$stmt->fetchColumn();
  
  while ($cnt < 3) {
    $pick = $taskPool[array_rand($taskPool)];
    $ist = $pdo->prepare('INSERT INTO team_tasks (team_id,title,points) VALUES (?,?,?)');
    $ist->execute([$team['id'], $pick['title'], $pick['points']]);
    $cnt++;
  }
  
  // 獲取任務
  $stmt = $pdo->prepare('SELECT task_id,team_id,title,points,created_at FROM team_tasks WHERE team_id=? AND completed_at IS NULL ORDER BY created_at');
  $stmt->execute([$team['id']]);
  $tasks = $stmt->fetchAll();
  
  // 獲取每個任務的確認狀態
  $task_confirmations = [];
  foreach ($tasks as $t) {
    $stmt = $pdo->prepare('SELECT user_id FROM task_confirmations WHERE task_id=?');
    $stmt->execute([$t['task_id']]);
    $confirmed_users = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $task_confirmations[$t['task_id']] = $confirmed_users;
  }
  
  // 獲取成員
  $stmt = $pdo->prepare('SELECT u.user_id AS id,u.username,u.display_name,tm.role FROM team_members tm JOIN users u ON tm.user_id=u.user_id WHERE tm.team_id=?');
  $stmt->execute([$team['id']]);
  $members = $stmt->fetchAll();
  
  // 獲取自己在此團隊的角色
  $stmt = $pdo->prepare('SELECT role FROM team_members WHERE team_id = ? AND user_id = ?');
  $stmt->execute([$team['id'], $user_id]);
  $my_role_row = $stmt->fetch();
  $my_role = $my_role_row ? $my_role_row['role'] : '';
  
  $teamsData[] = [
    'team' => $team,
    'tasks' => $tasks,
    'members' => $members,
    'my_role' => $my_role,
    'task_confirmations' => $task_confirmations
  ];
}
?>
<!doctype html>
<html lang="zh-TW">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>我的團隊 - 台科大健康任務地圖</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
</head>
<body>
  <nav class="navbar navbar-expand-lg">
    <div class="container">
      <a class="navbar-brand" href="index.php">
        <i class="fas fa-heartbeat me-2"></i>台科大健康任務地圖
      </a>
      <div class="d-flex align-items-center gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="index.php">
          <i class="fas fa-arrow-left me-1"></i>返回
        </a>
      </div>
    </div>
  </nav>

  <div class="container container-main">
    <div class="row justify-content-center">
      <div class="col-lg-10">
        <div class="card">
          <div class="card-body">
            <h3 class="card-title">
              <i class="fas fa-users"></i>我的團隊
            </h3>

            <?php if ($message): ?>
              <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
              <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <!-- 團隊邀請區塊 -->
            <?php if (count($pending_invites) > 0): ?>
              <div class="mb-4">
                <h5 class="mb-3">
                  <i class="fas fa-envelope me-2 text-warning"></i>待處理的團隊邀請
                  <span class="badge bg-danger ms-2"><?php echo count($pending_invites); ?></span>
                </h5>
                <?php foreach ($pending_invites as $invite): ?>
                  <div class="alert alert-warning d-flex justify-content-between align-items-center mb-2" style="border-left: 4px solid #F59E0B;">
                    <div>
                      <i class="fas fa-user-plus me-2"></i>
                      <strong><?php echo htmlspecialchars($invite['inviter_name'] ?? $invite['inviter_username']); ?></strong>
                      邀請你加入團隊
                      <strong><?php echo htmlspecialchars($invite['team_name']); ?></strong>
                    </div>
                    <div class="d-flex gap-2">
                      <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="accept_invite">
                        <input type="hidden" name="invite_id" value="<?php echo $invite['invite_id']; ?>">
                        <button type="submit" class="btn btn-success btn-sm">
                          <i class="fas fa-check me-1"></i>是
                        </button>
                      </form>
                      <form method="post" class="d-inline">
                        <input type="hidden" name="action" value="reject_invite">
                        <input type="hidden" name="invite_id" value="<?php echo $invite['invite_id']; ?>">
                        <button type="submit" class="btn btn-danger btn-sm">
                          <i class="fas fa-times me-1"></i>否
                        </button>
                      </form>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
              <hr class="mb-4">
            <?php endif; ?>

            <?php if (count($teamsData) === 0): ?>
              <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>您目前尚未加入任何團隊
              </div>
              <div class="d-flex gap-2">
                <a class="btn btn-success" href="create_team.php">
                  <i class="fas fa-plus-circle me-2"></i>建立團隊
                </a>
                <a class="btn btn-primary" href="join_team.php">
                  <i class="fas fa-sign-in-alt me-2"></i>加入團隊
                </a>
              </div>
            <?php else: ?>
              <?php foreach ($teamsData as $idx => $data): ?>
                <?php $team = $data['team']; $tasks = $data['tasks']; $members = $data['members']; $my_role = $data['my_role']; ?>
                <div class="mb-4 p-3" style="background: linear-gradient(135deg, #F3E8FF 0%, #E9D5FF 100%); border-radius: var(--radius-md); border-left: 4px solid var(--primary);">
                  <div>
                    <h5 class="mb-2">
                      <i class="fas fa-flag me-2"></i><?php echo htmlspecialchars($team['name']); ?>
                      <?php if ($my_role === 'owner'): ?>
                        <span class="badge bg-warning text-dark ms-2">擁有者</span>
                      <?php endif; ?>
                    </h5>
                    <div class="d-flex align-items-center gap-2">
                      <span class="text-muted"><i class="fas fa-key me-1"></i>邀請碼：</span>
                      <code class="px-2 py-1" style="background: white; border-radius: 6px; font-weight: 600; color: var(--primary);"><?php echo htmlspecialchars($team['code']); ?></code>
                    </div>
                  </div>
                </div>

                <h6 class="mt-4 mb-3">
                  <i class="fas fa-user-friends me-2"></i>成員列表
                </h6>
                <div class="table-responsive mb-3">
                  <table class="table table-sm align-middle text-center">
                    <thead>
                      <tr>
                        <th class="text-center">名稱</th>
                        <th class="text-center">身分</th>
                        <th class="text-center">操作</th>
                      </tr>
                    </thead>
                    <tbody>
                      <?php foreach($members as $m): ?>
                        <tr>
                          <td class="text-center">
                            <?php echo htmlspecialchars($m['display_name'] ?? $m['username']); ?>
                          </td>
                          <td class="text-center">
                            <?php if ($m['role'] === 'owner'): ?>
                              <span class="badge bg-warning text-dark">擁有者</span>
                            <?php else: ?>
                              <span class="badge bg-secondary">成員</span>
                            <?php endif; ?>
                          </td>
                          <td class="text-center">
                            <?php if ($m['id'] == $user_id): ?>
                              <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#leaveTeamModal<?php echo $team['id']; ?>">
                                <i class="fas fa-sign-out-alt me-1"></i>退出
                              </button>
                            <?php elseif ($my_role === 'owner'): ?>
                              <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#kickMemberModal<?php echo $team['id']; ?>_<?php echo $m['id']; ?>">
                                <i class="fas fa-user-times me-1"></i>移除
                              </button>
                            <?php else: ?>
                              <span class="text-muted">-</span>
                            <?php endif; ?>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>

                <h6 class="mt-4 mb-3">
                  <i class="fas fa-tasks me-2"></i>團隊任務
                  <span class="badge bg-primary ms-2"><?php echo count($tasks); ?>個任務</span>
                </h6>
                <div id="team-tasks-<?php echo $team['id']; ?>" class="row row-cols-1 row-cols-md-3 g-3 mb-4">
                  <?php foreach($tasks as $t): 
                    $task_id = (int)$t['task_id'];
                    $confirmed_users = $data['task_confirmations'][$task_id] ?? [];
                    $confirmed_count = count($confirmed_users);
                    $total_members = count($members);
                    $user_confirmed = in_array($user_id, $confirmed_users);
                  ?>
                    <div class="col" id="task-card-<?php echo $task_id; ?>">
                      <div class="card h-100" style="border-left: 4px solid var(--primary);">
                        <div class="card-body d-flex flex-column">
                          <h6 class="card-title mb-2">
                            <i class="fas fa-clipboard-check me-2"></i><?php echo htmlspecialchars($t['title']); ?>
                          </h6>
                          <p class="mb-2 text-muted">
                            <i class="fas fa-trophy me-1"></i>獎勵：<strong><?php echo (int)$t['points']; ?></strong> 點
                          </p>
                          <div class="mt-auto">
                            <div class="text-center mb-2">
                              <span class="badge bg-info">
                                <i class="fas fa-users me-1"></i>完成人數：<?php echo $confirmed_count; ?>/<?php echo $total_members; ?>
                              </span>
                            </div>
                            <?php if ($user_confirmed): ?>
                              <button class="btn btn-sm btn-success w-100" disabled>
                                <i class="fas fa-check me-1"></i>已確認
                              </button>
                            <?php else: ?>
                              <button data-task-id="<?php echo $task_id; ?>" data-total="<?php echo $total_members; ?>" class="btn btn-sm btn-primary btn-complete w-100">
                                <i class="fas fa-check-circle me-1"></i>確認完成
                              </button>
                            <?php endif; ?>
                          </div>
                        </div>
                      </div>
                    </div>
                  <?php endforeach; ?>
                </div>

                <?php if ($idx < count($teamsData) - 1): ?>
                  <hr class="my-4">
                <?php endif; ?>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
        </div>
      </div>
    </div>
  </div>

  <!-- 所有 Modal 放在 body 最外層 -->
  <?php foreach ($teamsData as $data): ?>
    <?php $team = $data['team']; $members = $data['members']; $my_role = $data['my_role']; ?>
    
    <!-- 退出團隊確認 Modal -->
    <div class="modal fade" id="leaveTeamModal<?php echo $team['id']; ?>" tabindex="-1" aria-labelledby="leaveTeamModalLabel<?php echo $team['id']; ?>" aria-hidden="true">
      <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
          <div class="modal-header">
            <h5 class="modal-title" id="leaveTeamModalLabel<?php echo $team['id']; ?>"><i class="fas fa-exclamation-triangle text-warning me-2"></i>確認退出團隊</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
          </div>
          <div class="modal-body">
            <p>確定要退出團隊 <strong><?php echo htmlspecialchars($team['name']); ?></strong> 嗎？</p>
          </div>
          <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
              <i class="fas fa-times me-1"></i>否
            </button>
            <form method="post" class="d-inline">
              <input type="hidden" name="action" value="leave_team">
              <input type="hidden" name="team_id" value="<?php echo $team['id']; ?>">
              <button type="submit" class="btn btn-danger">
                <i class="fas fa-check me-1"></i>是
              </button>
            </form>
          </div>
        </div>
      </div>
    </div>
    
    <!-- 踢出成員確認 Modals -->
    <?php if ($my_role === 'owner'): ?>
      <?php foreach ($members as $m): ?>
        <?php if ($m['id'] != $user_id): ?>
        <div class="modal fade" id="kickMemberModal<?php echo $team['id']; ?>_<?php echo $m['id']; ?>" tabindex="-1" aria-labelledby="kickMemberModalLabel<?php echo $team['id']; ?>_<?php echo $m['id']; ?>" aria-hidden="true">
          <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
              <div class="modal-header">
                <h5 class="modal-title" id="kickMemberModalLabel<?php echo $team['id']; ?>_<?php echo $m['id']; ?>"><i class="fas fa-exclamation-triangle text-warning me-2"></i>確認移除成員</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
              </div>
              <div class="modal-body">
                <p>確定要將 <strong><?php echo htmlspecialchars($m['display_name'] ?? $m['username']); ?></strong> 移出團隊 <strong><?php echo htmlspecialchars($team['name']); ?></strong> 嗎？</p>
              </div>
              <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                  <i class="fas fa-times me-1"></i>否
                </button>
                <form method="post" class="d-inline">
                  <input type="hidden" name="action" value="kick_member">
                  <input type="hidden" name="team_id" value="<?php echo $team['id']; ?>">
                  <input type="hidden" name="member_id" value="<?php echo $m['id']; ?>">
                  <button type="submit" class="btn btn-danger">
                    <i class="fas fa-check me-1"></i>是
                  </button>
                </form>
              </div>
            </div>
          </div>
        </div>
        <?php endif; ?>
      <?php endforeach; ?>
    <?php endif; ?>
  <?php endforeach; ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.addEventListener('click', function(e){
      if (e.target && e.target.classList.contains('btn-complete')) {
        e.target.disabled = true;
        const btn = e.target;
        const taskId = btn.getAttribute('data-task-id');
        const totalMembers = parseInt(btn.getAttribute('data-total')) || 1;
        const form = new FormData();
        form.append('task_id', taskId);
        fetch('complete_task.php', { method: 'POST', body: form })
          .then(r=>r.json())
          .then(js=>{
            if (js.success) {
              if (js.task_completed) {
                // 所有成員都確認了，任務完成
                const oldCard = document.getElementById('task-card-' + taskId);
                if (oldCard) {
                  const nt = js.new_task;
                  const col = document.createElement('div');
                  col.className = 'col';
                  col.id = 'task-card-' + nt.task_id;
                  col.innerHTML = `
                    <div class="card h-100" style="border-left: 4px solid var(--primary);">
                      <div class="card-body d-flex flex-column">
                        <h6 class="card-title mb-2">
                          <i class="fas fa-clipboard-check me-2"></i>${escapeHtml(nt.title)}
                        </h6>
                        <p class="mb-2 text-muted">
                          <i class="fas fa-trophy me-1"></i>獎勵：<strong>${nt.points}</strong> 點
                        </p>
                        <div class="mt-auto">
                          <div class="text-center mb-2">
                            <span class="badge bg-info">
                              <i class="fas fa-users me-1"></i>完成人數：0/${totalMembers}
                            </span>
                          </div>
                          <button data-task-id="${nt.task_id}" data-total="${totalMembers}" class="btn btn-sm btn-primary btn-complete w-100">
                            <i class="fas fa-check-circle me-1"></i>確認完成
                          </button>
                        </div>
                      </div>
                    </div>
                  `;
                  oldCard.replaceWith(col);
                }
                showTempAlert('🎉 所有成員都已確認！任務完成，每人獲得 ' + js.awarded_points + ' 點', 'success');
              } else {
                // 還有成員未確認，更新按鈕狀態
                btn.innerHTML = '<i class="fas fa-check me-1"></i>已確認';
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-success');
                btn.disabled = true;
                
                // 更新確認進度
                const card = document.getElementById('task-card-' + taskId);
                if (card) {
                  const badge = card.querySelector('.badge.bg-info');
                  if (badge) {
                    badge.innerHTML = `<i class="fas fa-users me-1"></i>完成人數：${js.confirmed_count}/${js.total_members}`;
                  }
                }
                
                showTempAlert(js.message, 'info');
              }
            } else {
              if (js.error === 'already_confirmed') {
                showTempAlert('您已經確認過此任務了', 'warning');
                btn.innerHTML = '<i class="fas fa-check me-1"></i>已確認';
                btn.classList.remove('btn-primary');
                btn.classList.add('btn-success');
              } else {
                showTempAlert('任務無法完成：' + (js.error||'unknown'), 'danger');
                btn.disabled = false;
              }
            }
          })
          .catch(err=>{ 
            showTempAlert('網路錯誤', 'danger'); 
            console.error(err);
            btn.disabled = false;
          });
      }
    });

    function showTempAlert(msg, type = 'info') {
      const a = document.createElement('div');
      a.className = 'alert alert-' + type + ' position-fixed bottom-0 end-0 m-3';
      a.style.zIndex = '9999';
      a.innerHTML = msg;
      document.body.appendChild(a);
      setTimeout(()=>{ a.remove(); }, 3500);
    }

    function escapeHtml(s){
      return String(s).replace(/[&<>\"]/g, function(c){
        return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c];
      });
    }
  </script>
</body>
</html>
