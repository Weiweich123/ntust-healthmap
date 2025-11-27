<?php
require_once __DIR__ . '/db.php';
require_login();

$user_id = $_SESSION['user_id'];
// get user's team
$stmt = $pdo->prepare('SELECT t.team_id AS id,t.name,t.code FROM teams t JOIN team_members tm ON t.team_id=tm.team_id WHERE tm.user_id=? LIMIT 1');
$stmt->execute([$user_id]);
$team = $stmt->fetch();

// list members
$members = [];
if ($team) {
  $stmt = $pdo->prepare('SELECT u.user_id AS id,u.username,u.display_name,tm.role FROM team_members tm JOIN users u ON tm.user_id=u.user_id WHERE tm.team_id=?');
  $stmt->execute([$team['id']]);
    $members = $stmt->fetchAll();
}

// ensure each team has 3 active random tasks visible
if ($team) {
  // helper pool
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

  // count active tasks
  $stmt = $pdo->prepare('SELECT COUNT(*) FROM team_tasks WHERE team_id=? AND completed_at IS NULL');
  $stmt->execute([$team['id']]);
  $cnt = (int)$stmt->fetchColumn();

  while ($cnt < 3) {
    $pick = $taskPool[array_rand($taskPool)];
    $ist = $pdo->prepare('INSERT INTO team_tasks (team_id,title,points) VALUES (?,?,?)');
    $ist->execute([$team['id'], $pick['title'], $pick['points']]);
    $cnt++;
  }

  // fetch active tasks
    $stmt = $pdo->prepare('SELECT team_id,title,points,created_at FROM team_tasks WHERE team_id=? AND completed_at IS NULL ORDER BY created_at');
  $stmt->execute([$team['id']]);
  $tasks = $stmt->fetchAll();
} else {
  $tasks = [];
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
        <a class="btn btn-outline-primary btn-sm" href="create_team.php">
          <i class="fas fa-plus me-1"></i>建立
        </a>
        <a class="btn btn-outline-success btn-sm" href="join_team.php">
          <i class="fas fa-sign-in-alt me-1"></i>加入
        </a>
        <a class="btn btn-outline-secondary btn-sm" href="index.php">
          <i class="fas fa-arrow-left"></i>
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

            <?php if (!$team): ?>
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
              <div class="mb-4 p-3" style="background: linear-gradient(135deg, #F3E8FF 0%, #E9D5FF 100%); border-radius: var(--radius-md); border-left: 4px solid var(--primary);">
                <h5 class="mb-2">
                  <i class="fas fa-flag me-2"></i><?php echo htmlspecialchars($team['name']); ?>
                </h5>
                <div class="d-flex align-items-center gap-2">
                  <span class="text-muted"><i class="fas fa-key me-1"></i>邀請碼：</span>
                  <code class="px-2 py-1" style="background: white; border-radius: 6px; font-weight: 600; color: var(--primary);"><?php echo htmlspecialchars($team['code']); ?></code>
                </div>
              </div>

              <h6 class="mt-4 mb-3">
                <i class="fas fa-user-friends me-2"></i>成員列表
              </h6>
              <div class="table-responsive mb-3">
                <table class="table table-sm align-middle">
                  <thead>
                    <tr>
                      <th>名稱</th>
                      <th>身分</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach($members as $m): ?>
                      <tr>
                        <td><?php echo htmlspecialchars($m['display_name'] ?? $m['username']); ?></td>
                        <td><?php echo htmlspecialchars($m['role']); ?></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>

              <h6 class="mt-4 mb-3">
                <i class="fas fa-tasks me-2"></i>團隊任務
                <span class="badge bg-primary ms-2">3個任務</span>
              </h6>
              <div id="team-tasks" class="row row-cols-1 row-cols-md-3 g-3">
                <?php foreach($tasks as $t): ?>
                  <?php $task_key = $t['team_id'] . '|' . rawurlencode($t['created_at']); ?>
                  <div class="col" id="task-card-<?php echo htmlspecialchars($task_key); ?>">
                    <div class="card h-100" style="border-left: 4px solid var(--primary);">
                      <div class="card-body d-flex flex-column">
                        <h6 class="card-title mb-2">
                          <i class="fas fa-clipboard-check me-2"></i><?php echo htmlspecialchars($t['title']); ?>
                        </h6>
                        <p class="mb-3 text-muted">
                          <i class="fas fa-trophy me-1"></i>獎勵：<strong><?php echo (int)$t['points']; ?></strong> 點
                        </p>
                        <div class="mt-auto">
                          <button data-team-id="<?php echo (int)$t['team_id']; ?>" data-created-at="<?php echo htmlspecialchars($t['created_at']); ?>" class="btn btn-sm btn-primary btn-complete w-100">
                            <i class="fas fa-check-circle me-1"></i>完成
                          </button>
                        </div>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <hr class="my-4">
            <div class="text-center">
              <a href="index.php" class="btn btn-outline-secondary">
                <i class="fas fa-arrow-left me-2"></i>返回首頁
              </a>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    document.addEventListener('click', function(e){
      if (e.target && e.target.classList.contains('btn-complete')) {
        e.target.disabled = true;
        const teamId = e.target.getAttribute('data-team-id');
        const createdAt = e.target.getAttribute('data-created-at');
        const form = new FormData();
        form.append('team_id', teamId);
        form.append('created_at', createdAt);
        fetch('complete_task.php', { method: 'POST', body: form })
          .then(r=>r.json())
          .then(js=>{
            if (js.success) {
              // replace the card using composite key
              const oldKey = teamId + '|' + encodeURIComponent(createdAt);
              const oldCard = document.getElementById('task-card-' + oldKey);
              if (oldCard) {
                const nt = js.new_task;
                const newKey = nt.team_id + '|' + encodeURIComponent(nt.created_at);
                const col = document.createElement('div');
                col.className = 'col';
                col.id = 'task-card-' + newKey;
                col.innerHTML = `
                  <div class="card h-100" style="border-left: 4px solid var(--primary);">
                    <div class="card-body d-flex flex-column">
                      <h6 class="card-title mb-2">
                        <i class="fas fa-clipboard-check me-2"></i>${escapeHtml(nt.title)}
                      </h6>
                      <p class="mb-3 text-muted">
                        <i class="fas fa-trophy me-1"></i>獎勵：<strong>${nt.points}</strong> 點
                      </p>
                      <div class="mt-auto">
                        <button data-team-id="${nt.team_id}" data-created-at="${nt.created_at}" class="btn btn-sm btn-primary btn-complete w-100">
                          <i class="fas fa-check-circle me-1"></i>完成
                        </button>
                      </div>
                    </div>
                  </div>
                `;
                oldCard.replaceWith(col);
              }
              // show awarded points
              showTempAlert('任務完成，獲得 ' + js.awarded_points + ' 點');
            } else {
              showTempAlert('任務無法完成：' + (js.error||'unknown'));
            }
          })
          .catch(err=>{ showTempAlert('網路錯誤'); console.error(err); })
          .finally(()=>{ e.target.disabled = false; });
      }
    });

    function showTempAlert(msg) {
      const a = document.createElement('div');
      a.className = 'alert alert-info position-fixed bottom-0 end-0 m-3';
      a.textContent = msg;
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
