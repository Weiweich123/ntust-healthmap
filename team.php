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
  <link href="assets/styles.css" rel="stylesheet">
</head>
<body>
  <nav class="navbar navbar-expand-lg navbar-light bg-light mb-4">
    <div class="container">
      <a class="navbar-brand" href="index.php">台科大健康任務地圖</a>
      <div class="collapse navbar-collapse">
        <ul class="navbar-nav ms-auto">
          <li class="nav-item"><a class="nav-link" href="create_team.php">建立團隊</a></li>
          <li class="nav-item"><a class="nav-link" href="join_team.php">加入團隊</a></li>
        </ul>
      </div>
    </div>
  </nav>

  <div class="container">
    <div class="row justify-content-center">
      <div class="col-lg-8">
        <div class="card shadow-sm">
          <div class="card-body">
            <h3 class="card-title mb-3">我的團隊</h3>

            <?php if (!$team): ?>
              <div class="alert alert-secondary">您目前尚未加入任何團隊。</div>
              <div class="d-flex gap-2">
                <a class="btn btn-success" href="create_team.php">建立團隊</a>
                <a class="btn btn-primary" href="join_team.php">加入團隊</a>
              </div>
            <?php else: ?>
              <div class="mb-3">
                <h5 class="mb-1"><?php echo htmlspecialchars($team['name']); ?></h5>
                <small class="text-muted">邀請碼: <?php echo htmlspecialchars($team['code']); ?></small>
              </div>

              <h6 class="mt-3">成員</h6>
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

              <h6 class="mt-3">團隊任務（保持三個任務）</h6>
              <div id="team-tasks" class="row row-cols-1 row-cols-md-3 g-3">
                <?php foreach($tasks as $t): ?>
                  <?php $task_key = $t['team_id'] . '|' . rawurlencode($t['created_at']); ?>
                  <div class="col" id="task-card-<?php echo htmlspecialchars($task_key); ?>">
                    <div class="card h-100">
                      <div class="card-body d-flex flex-column">
                        <h6 class="card-title mb-2"><?php echo htmlspecialchars($t['title']); ?></h6>
                        <p class="mb-2 text-muted">獎勵：<?php echo (int)$t['points']; ?> 點</p>
                        <div class="mt-auto">
                          <button data-team-id="<?php echo (int)$t['team_id']; ?>" data-created-at="<?php echo htmlspecialchars($t['created_at']); ?>" class="btn btn-sm btn-outline-success btn-complete">完成任務</button>
                        </div>
                      </div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>

            <hr>
            <p class="mb-0"><a href="index.php">回首頁</a></p>
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
                  <div class="card h-100">
                    <div class="card-body d-flex flex-column">
                      <h6 class="card-title mb-2">${escapeHtml(nt.title)}</h6>
                      <p class="mb-2 text-muted">獎勵：${nt.points} 點</p>
                      <div class="mt-auto">
                        <button data-team-id="${nt.team_id}" data-created-at="${nt.created_at}" class="btn btn-sm btn-outline-success btn-complete">完成任務</button>
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
