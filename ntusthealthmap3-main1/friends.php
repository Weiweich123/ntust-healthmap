<?php
require_once __DIR__ . '/db.php';
require_login();

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// 處理加好友請求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'add_friend') {
        $friend_username = trim($_POST['friend_username'] ?? '');
        if ($friend_username === '') {
            $error = '請輸入對方的帳號';
        } else {
            // 查找用戶
            $stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
            $stmt->execute([$friend_username]);
            $friend = $stmt->fetch();
            
            if (!$friend) {
                $error = '找不到此用戶';
            } elseif ($friend['user_id'] == $user_id) {
                $error = '不能加自己為好友';
            } else {
                // 檢查是否已經是好友或已發送請求
                $stmt = $pdo->prepare('SELECT * FROM friendships WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)');
                $stmt->execute([$user_id, $friend['user_id'], $friend['user_id'], $user_id]);
                $existing = $stmt->fetch();
                
                if ($existing) {
                    if ($existing['status'] === 'accepted') {
                        $error = '你們已經是好友了';
                    } elseif ($existing['status'] === 'pending') {
                        $error = '好友請求已發送，等待對方確認';
                    } else {
                        $error = '無法發送好友請求';
                    }
                } else {
                    $stmt = $pdo->prepare('INSERT INTO friendships (user_id, friend_id, status) VALUES (?, ?, "pending")');
                    $stmt->execute([$user_id, $friend['user_id']]);
                    $message = '好友請求已發送';
                }
            }
        }
    } elseif ($_POST['action'] === 'accept_friend') {
        $friendship_id = (int)($_POST['friendship_id'] ?? 0);
        $stmt = $pdo->prepare('UPDATE friendships SET status = "accepted" WHERE friendship_id = ? AND friend_id = ?');
        $stmt->execute([$friendship_id, $user_id]);
        $message = '已接受好友請求';
    } elseif ($_POST['action'] === 'reject_friend') {
        $friendship_id = (int)($_POST['friendship_id'] ?? 0);
        $stmt = $pdo->prepare('DELETE FROM friendships WHERE friendship_id = ? AND friend_id = ?');
        $stmt->execute([$friendship_id, $user_id]);
        $message = '已拒絕好友請求';
    } elseif ($_POST['action'] === 'invite_to_team') {
        $friend_id = (int)($_POST['friend_id'] ?? 0);
        $team_id = (int)($_POST['team_id'] ?? 0);
        
        // 確認是好友
        $stmt = $pdo->prepare('SELECT * FROM friendships WHERE ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)) AND status = "accepted"');
        $stmt->execute([$user_id, $friend_id, $friend_id, $user_id]);
        if (!$stmt->fetch()) {
            $error = '對方不是你的好友';
        } else {
            // 確認自己在此團隊
            $stmt = $pdo->prepare('SELECT * FROM team_members WHERE team_id = ? AND user_id = ?');
            $stmt->execute([$team_id, $user_id]);
            if (!$stmt->fetch()) {
                $error = '你不在此團隊中';
            } else {
                // 將好友加入團隊
                $stmt = $pdo->prepare('INSERT IGNORE INTO team_members (team_id, user_id, role) VALUES (?, ?, "member")');
                $stmt->execute([$team_id, $friend_id]);
                $message = '已邀請好友加入團隊';
            }
        }
    } elseif ($_POST['action'] === 'delete_friend') {
        $friend_id = (int)($_POST['friend_id'] ?? 0);
        // 刪除好友關係（不影響團隊成員）
        $stmt = $pdo->prepare('DELETE FROM friendships WHERE (user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?)');
        $stmt->execute([$user_id, $friend_id, $friend_id, $user_id]);
        $message = '已刪除好友';
    }
}

// 獲取好友列表
$stmt = $pdo->prepare('
    SELECT u.user_id, u.username, u.display_name, f.friendship_id, f.status,
           CASE WHEN f.user_id = ? THEN "sent" ELSE "received" END as direction
    FROM friendships f
    JOIN users u ON (CASE WHEN f.user_id = ? THEN f.friend_id ELSE f.user_id END) = u.user_id
    WHERE (f.user_id = ? OR f.friend_id = ?) AND f.status = "accepted"
');
$stmt->execute([$user_id, $user_id, $user_id, $user_id]);
$friends = $stmt->fetchAll();

// 獲取待處理的好友請求（別人發給我的）
$stmt = $pdo->prepare('
    SELECT u.user_id, u.username, u.display_name, f.friendship_id
    FROM friendships f
    JOIN users u ON f.user_id = u.user_id
    WHERE f.friend_id = ? AND f.status = "pending"
');
$stmt->execute([$user_id]);
$pending_requests = $stmt->fetchAll();

// 獲取用戶所在的所有團隊
$stmt = $pdo->prepare('SELECT t.team_id, t.name FROM teams t JOIN team_members tm ON t.team_id = tm.team_id WHERE tm.user_id = ?');
$stmt->execute([$user_id]);
$my_teams = $stmt->fetchAll();
?>
<!doctype html>
<html lang="zh-TW">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>好友列表 - 台科大健康任務地圖</title>
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
      <a class="btn btn-outline-secondary btn-sm" href="index.php">
        <i class="fas fa-arrow-left me-1"></i>返回
      </a>
    </div>
  </nav>

  <div class="container container-main">
    <div class="row justify-content-center">
      <div class="col-lg-10">
        
        <?php if ($message): ?>
          <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
          <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <!-- 加好友區塊 -->
        <div class="card mb-4">
          <div class="card-body">
            <h5 class="card-title">
              <i class="fas fa-user-plus"></i>加入好友
            </h5>
            <form method="post" class="row g-3 align-items-end">
              <input type="hidden" name="action" value="add_friend">
              <div class="col-md-8">
                <label class="form-label">輸入對方的帳號</label>
                <input type="text" name="friend_username" class="form-control" placeholder="請輸入帳號" required>
              </div>
              <div class="col-md-4">
                <button type="submit" class="btn btn-primary w-100">
                  <i class="fas fa-paper-plane me-2"></i>發送請求
                </button>
              </div>
            </form>
          </div>
        </div>

        <!-- 待處理的好友請求 -->
        <?php if (count($pending_requests) > 0): ?>
        <div class="card mb-4">
          <div class="card-body">
            <h5 class="card-title">
              <i class="fas fa-bell"></i>待處理的好友請求
              <span class="badge bg-danger ms-2"><?php echo count($pending_requests); ?></span>
            </h5>
            <div class="list-group">
              <?php foreach ($pending_requests as $req): ?>
                <div class="list-group-item d-flex justify-content-between align-items-center">
                  <div>
                    <i class="fas fa-user me-2"></i>
                    <strong><?php echo htmlspecialchars($req['display_name'] ?? $req['username']); ?></strong>
                    <small class="text-muted">(@<?php echo htmlspecialchars($req['username']); ?>)</small>
                  </div>
                  <div class="d-flex gap-2">
                    <form method="post" class="d-inline">
                      <input type="hidden" name="action" value="accept_friend">
                      <input type="hidden" name="friendship_id" value="<?php echo $req['friendship_id']; ?>">
                      <button type="submit" class="btn btn-success btn-sm">
                        <i class="fas fa-check me-1"></i>接受
                      </button>
                    </form>
                    <form method="post" class="d-inline">
                      <input type="hidden" name="action" value="reject_friend">
                      <input type="hidden" name="friendship_id" value="<?php echo $req['friendship_id']; ?>">
                      <button type="submit" class="btn btn-outline-danger btn-sm">
                        <i class="fas fa-times me-1"></i>拒絕
                      </button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <?php endif; ?>

        <!-- 好友列表 -->
        <div class="card" style="overflow: visible;">
          <div class="card-body" style="overflow: visible;">
            <h5 class="card-title">
              <i class="fas fa-users"></i>我的好友
              <span class="badge bg-primary ms-2"><?php echo count($friends); ?></span>
            </h5>
            
            <?php if (count($friends) === 0): ?>
              <div class="alert alert-info mb-0">
                <i class="fas fa-info-circle me-2"></i>你還沒有好友，快去邀請朋友加入吧！
              </div>
            <?php else: ?>
              <div class="list-group" style="overflow: visible;">
                <?php foreach ($friends as $friend): ?>
                  <div class="list-group-item d-flex justify-content-between align-items-center" style="overflow: visible;">
                    <div>
                      <i class="fas fa-user-circle me-2 text-primary"></i>
                      <strong><?php echo htmlspecialchars($friend['display_name'] ?? $friend['username']); ?></strong>
                      <small class="text-muted">(@<?php echo htmlspecialchars($friend['username']); ?>)</small>
                    </div>
                    <?php if (count($my_teams) > 0): ?>
                    <div class="d-flex gap-2" style="overflow: visible;">
                      <div class="dropdown">
                        <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                          <i class="fas fa-user-plus me-1"></i>邀請加入團隊
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" style="z-index: 1050;">
                          <?php foreach ($my_teams as $team): ?>
                            <li>
                              <form method="post" class="dropdown-item-form">
                                <input type="hidden" name="action" value="invite_to_team">
                                <input type="hidden" name="friend_id" value="<?php echo $friend['user_id']; ?>">
                                <input type="hidden" name="team_id" value="<?php echo $team['team_id']; ?>">
                                <button type="submit" class="dropdown-item">
                                  <i class="fas fa-flag me-2"></i><?php echo htmlspecialchars($team['name']); ?>
                                </button>
                              </form>
                            </li>
                          <?php endforeach; ?>
                        </ul>
                      </div>
                      <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteFriendModal<?php echo $friend['user_id']; ?>">
                        <i class="fas fa-user-minus"></i>
                      </button>
                    </div>
                    <?php else: ?>
                    <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteFriendModal<?php echo $friend['user_id']; ?>">
                      <i class="fas fa-user-minus me-1"></i>刪除好友
                    </button>
                    <?php endif; ?>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

      </div>
    </div>
  </div>

  <!-- 刪除好友確認 Modals（放在 body 最外層） -->
  <?php foreach ($friends as $friend): ?>
  <div class="modal fade" id="deleteFriendModal<?php echo $friend['user_id']; ?>" tabindex="-1" aria-labelledby="deleteFriendModalLabel<?php echo $friend['user_id']; ?>" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="deleteFriendModalLabel<?php echo $friend['user_id']; ?>"><i class="fas fa-exclamation-triangle text-warning me-2"></i>確認刪除好友</h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <p>確定要刪除好友 <strong><?php echo htmlspecialchars($friend['display_name'] ?? $friend['username']); ?></strong> 嗎？</p>
          <p class="text-muted small mb-0">此操作不會影響你們在同一團隊中的關係。</p>
        </div>
        <div class="modal-footer">
          <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
            <i class="fas fa-times me-1"></i>否
          </button>
          <form method="post" class="d-inline">
            <input type="hidden" name="action" value="delete_friend">
            <input type="hidden" name="friend_id" value="<?php echo $friend['user_id']; ?>">
            <button type="submit" class="btn btn-danger">
              <i class="fas fa-check me-1"></i>是
            </button>
          </form>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
