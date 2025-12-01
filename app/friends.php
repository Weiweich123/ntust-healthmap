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
                // 確認對方不在此團隊
                $stmt = $pdo->prepare('SELECT * FROM team_members WHERE team_id = ? AND user_id = ?');
                $stmt->execute([$team_id, $friend_id]);
                if ($stmt->fetch()) {
                    $error = '對方已經在此團隊中';
                } else {
                    // 確認沒有待處理的邀請
                    $stmt = $pdo->prepare('SELECT * FROM team_invites WHERE team_id = ? AND invitee_id = ? AND status = "pending"');
                    $stmt->execute([$team_id, $friend_id]);
                    if ($stmt->fetch()) {
                        $error = '已經發送過邀請，請等待對方回覆';
                    } else {
                        // 發送團隊邀請
                        $stmt = $pdo->prepare('INSERT INTO team_invites (team_id, inviter_id, invitee_id, status) VALUES (?, ?, ?, "pending")');
                        $stmt->execute([$team_id, $user_id, $friend_id]);
                        $message = '已發送團隊邀請，等待對方確認';
                    }
                }
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

// 為每個好友計算可邀請的團隊（排除好友已加入的團隊，以及好友是擁有者的團隊）
$friend_available_teams = [];
foreach ($friends as $friend) {
    $friend_id = $friend['user_id'];
    // 獲取好友已加入的團隊 ID
    $stmt = $pdo->prepare('SELECT team_id FROM team_members WHERE user_id = ?');
    $stmt->execute([$friend_id]);
    $friend_team_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // 獲取好友是擁有者的團隊 ID
    $stmt = $pdo->prepare('SELECT team_id FROM team_members WHERE user_id = ? AND role = "owner"');
    $stmt->execute([$friend_id]);
    $friend_owner_team_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);

    // 合併排除的團隊 ID
    $excluded_team_ids = array_unique(array_merge($friend_team_ids, $friend_owner_team_ids));

    // 過濾出可邀請的團隊
    $available_teams = [];
    foreach ($my_teams as $team) {
        if (!in_array($team['team_id'], $excluded_team_ids)) {
            $available_teams[] = $team;
        }
    }
    $friend_available_teams[$friend_id] = $available_teams;
}
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
  <style>
    /* 聊天視窗樣式 */
    .chat-modal .modal-dialog {
      max-width: 600px;
      height: 80vh;
      margin: 10vh auto;
    }
    .chat-modal .modal-content {
      height: 100%;
      display: flex;
      flex-direction: column;
    }
    .chat-modal .modal-body {
      flex: 1;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      padding: 0;
    }
    .chat-messages {
      flex: 1;
      overflow-y: auto;
      padding: 1rem;
      background: #f8f9fa;
    }
    .chat-input-area {
      padding: 1rem;
      border-top: 1px solid #dee2e6;
      background: white;
    }
    .message-bubble {
      max-width: 75%;
      padding: 0.75rem 1rem;
      border-radius: 1rem;
      margin-bottom: 0.5rem;
      position: relative;
      word-wrap: break-word;
    }
    .message-sent {
      background: linear-gradient(135deg, #6C63FF 0%, #9D4EDD 100%);
      color: white;
      margin-left: auto;
      border-bottom-right-radius: 0.25rem;
    }
    .message-received {
      background: white;
      color: #333;
      margin-right: auto;
      border-bottom-left-radius: 0.25rem;
      box-shadow: 0 1px 2px rgba(0,0,0,0.1);
    }
    .message-time {
      font-size: 0.7rem;
      opacity: 0.7;
      margin-top: 0.25rem;
    }
    .message-read-status {
      font-size: 0.65rem;
      color: rgba(255,255,255,0.8);
      margin-top: 0.15rem;
    }
    .message-voice {
      display: flex;
      align-items: center;
      gap: 0.5rem;
    }
    .message-voice audio {
      max-width: 200px;
      height: 32px;
    }
    .chat-btn-badge {
      position: absolute;
      top: -5px;
      right: -5px;
      font-size: 0.65rem;
      min-width: 18px;
      height: 18px;
      display: flex;
      align-items: center;
      justify-content: center;
      border-radius: 50%;
    }
    .recording-indicator {
      display: none;
      align-items: center;
      gap: 0.5rem;
      color: #dc3545;
    }
    .recording-indicator.active {
      display: flex;
    }
    .recording-dot {
      width: 12px;
      height: 12px;
      background: #dc3545;
      border-radius: 50%;
      animation: pulse 1s infinite;
    }
    @keyframes pulse {
      0%, 100% { opacity: 1; }
      50% { opacity: 0.5; }
    }
  </style>
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
                  <?php $available_teams = $friend_available_teams[$friend['user_id']] ?? []; ?>
                  <div class="list-group-item d-flex justify-content-between align-items-center" style="overflow: visible;">
                    <div>
                      <i class="fas fa-user-circle me-2 text-primary"></i>
                      <strong><?php echo htmlspecialchars($friend['display_name'] ?? $friend['username']); ?></strong>
                      <small class="text-muted">(@<?php echo htmlspecialchars($friend['username']); ?>)</small>
                    </div>
                    <div class="d-flex gap-2" style="overflow: visible;">
                      <?php if (count($available_teams) > 0): ?>
                      <div class="dropdown">
                        <button class="btn btn-outline-primary btn-sm dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                          <i class="fas fa-user-plus me-1"></i>邀請加入團隊
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end" style="z-index: 1050;">
                          <?php foreach ($available_teams as $team): ?>
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
                      <?php endif; ?>
                      <!-- 聊天按鈕 -->
                      <button type="button" class="btn btn-outline-info btn-sm position-relative chat-btn"
                              data-friend-id="<?php echo $friend['user_id']; ?>"
                              data-friend-name="<?php echo htmlspecialchars($friend['display_name'] ?? $friend['username']); ?>"
                              data-bs-toggle="modal" data-bs-target="#chatModal">
                        <i class="fas fa-comments"></i>
                        <span class="badge bg-danger chat-btn-badge d-none" id="unreadBadge<?php echo $friend['user_id']; ?>">0</span>
                      </button>
                      <!-- 刪除好友按鈕 -->
                      <button type="button" class="btn btn-outline-danger btn-sm" data-bs-toggle="modal" data-bs-target="#deleteFriendModal<?php echo $friend['user_id']; ?>">
                        <i class="fas fa-user-minus"></i>
                      </button>
                    </div>
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

  <!-- 聊天 Modal -->
  <div class="modal fade chat-modal" id="chatModal" tabindex="-1" aria-labelledby="chatModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
      <div class="modal-content">
        <div class="modal-header">
          <h5 class="modal-title" id="chatModalLabel">
            <i class="fas fa-comments text-info me-2"></i>
            與 <span id="chatFriendName"></span> 聊天
          </h5>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body">
          <div class="chat-messages" id="chatMessages">
            <!-- 訊息會動態載入 -->
            <div class="text-center text-muted py-5" id="chatLoading">
              <i class="fas fa-spinner fa-spin me-2"></i>載入中...
            </div>
          </div>
          <div class="chat-input-area">
            <div class="recording-indicator mb-2" id="recordingIndicator">
              <span class="recording-dot"></span>
              <span>錄音中... <span id="recordingTime">0:00</span></span>
              <button type="button" class="btn btn-sm btn-danger ms-auto" id="stopRecordingBtn">
                <i class="fas fa-stop me-1"></i>停止
              </button>
            </div>
            <div class="d-flex gap-2">
              <input type="text" class="form-control" id="chatInput" placeholder="輸入訊息..." autocomplete="off">
              <button type="button" class="btn btn-outline-secondary" id="voiceBtn" title="發送語音訊息">
                <i class="fas fa-microphone"></i>
              </button>
              <button type="button" class="btn btn-primary" id="sendBtn">
                <i class="fas fa-paper-plane"></i>
              </button>
            </div>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // 聊天功能
    let currentFriendId = null;
    let currentFriendName = '';
    let lastMessageId = 0;
    let pollInterval = null;
    let mediaRecorder = null;
    let audioChunks = [];
    let recordingStartTime = null;
    let recordingTimer = null;

    // 頁面載入時獲取未讀訊息數量
    document.addEventListener('DOMContentLoaded', function() {
      loadUnreadCounts();
      // 每30秒更新一次未讀數量
      setInterval(loadUnreadCounts, 30000);
    });

    // 載入未讀訊息數量
    function loadUnreadCounts() {
      fetch('chat_api.php?action=get_unread_counts')
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            // 更新所有好友的未讀徽章
            document.querySelectorAll('.chat-btn').forEach(btn => {
              const friendId = btn.dataset.friendId;
              const badge = document.getElementById('unreadBadge' + friendId);
              const count = data.unread[friendId] || 0;
              if (count > 0) {
                badge.textContent = count > 99 ? '99+' : count;
                badge.classList.remove('d-none');
              } else {
                badge.classList.add('d-none');
              }
            });
          }
        })
        .catch(err => console.error('載入未讀數量失敗:', err));
    }

    // 聊天按鈕點擊事件
    document.querySelectorAll('.chat-btn').forEach(btn => {
      btn.addEventListener('click', function() {
        currentFriendId = this.dataset.friendId;
        currentFriendName = this.dataset.friendName;
        document.getElementById('chatFriendName').textContent = currentFriendName;
        loadMessages();
      });
    });

    // Modal 關閉時停止輪詢
    document.getElementById('chatModal').addEventListener('hidden.bs.modal', function() {
      if (pollInterval) {
        clearInterval(pollInterval);
        pollInterval = null;
      }
      currentFriendId = null;
      lastMessageId = 0;
    });

    // 載入聊天記錄
    function loadMessages() {
      const container = document.getElementById('chatMessages');
      container.innerHTML = '<div class="text-center text-muted py-5"><i class="fas fa-spinner fa-spin me-2"></i>載入中...</div>';

      fetch('chat_api.php?action=get_messages&friend_id=' + currentFriendId)
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            container.innerHTML = '';
            if (data.messages.length === 0) {
              container.innerHTML = '<div class="text-center text-muted py-5"><i class="fas fa-comments me-2"></i>開始與好友聊天吧！</div>';
            } else {
              data.messages.forEach(msg => {
                appendMessage(msg, data.user_id);
                lastMessageId = Math.max(lastMessageId, parseInt(msg.message_id));
              });
              scrollToBottom();
            }
            // 更新未讀徽章
            const badge = document.getElementById('unreadBadge' + currentFriendId);
            badge.classList.add('d-none');
            // 開始輪詢新訊息
            startPolling();
          } else {
            container.innerHTML = '<div class="alert alert-danger">' + data.message + '</div>';
          }
        })
        .catch(err => {
          container.innerHTML = '<div class="alert alert-danger">載入失敗</div>';
        });
    }

    // 添加訊息到聊天視窗
    function appendMessage(msg, userId) {
      const container = document.getElementById('chatMessages');
      const isSent = parseInt(msg.sender_id) === parseInt(userId);
      const div = document.createElement('div');
      div.className = 'message-bubble ' + (isSent ? 'message-sent' : 'message-received');
      div.dataset.messageId = msg.message_id;

      let content = '';
      if (msg.message_type === 'voice') {
        content = '<div class="message-voice"><i class="fas fa-volume-up"></i><audio controls src="' + escapeHtml(msg.content) + '"></audio></div>';
      } else {
        content = escapeHtml(msg.content);
      }

      // 直接解析時間字串，避免時區轉換問題
      const timeStr = msg.created_at; // 格式: "2025-11-30 15:48:00"
      const timePart = timeStr.split(' ')[1]; // "15:48:00"
      const time = timePart ? timePart.substring(0, 5) : ''; // "15:48"

      div.innerHTML = content +
        '<div class="message-time">' + time + '</div>' +
        (isSent ? '<div class="message-read-status">' + (msg.is_read === 'Read' || msg.is_read === 1 || msg.is_read === '1' ? '已讀' : '') + '</div>' : '');

      container.appendChild(div);
    }

    // 開始輪詢新訊息
    function startPolling() {
      if (pollInterval) clearInterval(pollInterval);
      pollInterval = setInterval(checkNewMessages, 2000);
    }

    // 檢查新訊息
    function checkNewMessages() {
      if (!currentFriendId) return;

      fetch('chat_api.php?action=check_new_messages&friend_id=' + currentFriendId + '&last_message_id=' + lastMessageId)
        .then(r => r.json())
        .then(data => {
          if (data.success) {
            // 添加新訊息
            data.messages.forEach(msg => {
              appendMessage(msg, data.user_id);
              lastMessageId = Math.max(lastMessageId, parseInt(msg.message_id));
            });
            if (data.messages.length > 0) {
              scrollToBottom();
            }
            // 更新已讀狀態
            data.read_ids.forEach(id => {
              const bubble = document.querySelector('[data-message-id="' + id + '"]');
              if (bubble) {
                const status = bubble.querySelector('.message-read-status');
                if (status && !status.textContent) {
                  status.textContent = '已讀';
                }
              }
            });
          }
        })
        .catch(err => console.error('輪詢失敗:', err));
    }

    // 發送訊息
    let isSending = false;
    function sendMessage(retryCount = 0) {
      const input = document.getElementById('chatInput');
      const content = input.value.trim();
      if (!content || !currentFriendId) return;
      if (isSending && retryCount === 0) return; // 只阻止首次重複點擊

      isSending = true;
      input.disabled = true;
      document.getElementById('sendBtn').disabled = true;
      document.getElementById('sendBtn').innerHTML = '<i class="fas fa-spinner fa-spin"></i>';

      const resetUI = () => {
        isSending = false;
        input.disabled = false;
        document.getElementById('sendBtn').disabled = false;
        document.getElementById('sendBtn').innerHTML = '<i class="fas fa-paper-plane"></i>';
        input.focus();
      };

      fetch('chat_api.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=send_message&friend_id=' + currentFriendId + '&content=' + encodeURIComponent(content)
      })
        .then(r => {
          if (!r.ok) throw new Error('HTTP ' + r.status);
          return r.json();
        })
        .then(data => {
          if (data.success) {
            input.value = '';
            // 立即顯示已發送的訊息（不等輪詢）
            if (data.message) {
              appendMessage(data.message, <?php echo $user_id; ?>);
              lastMessageId = Math.max(lastMessageId, parseInt(data.message.message_id));
              scrollToBottom();
            }
            resetUI();
          } else {
            // 自動重試
            if (retryCount < 2) {
              setTimeout(() => sendMessage(retryCount + 1), 500);
            } else {
              alert(data.message || '發送失敗');
              resetUI();
            }
          }
        })
        .catch(err => {
          console.error('發送錯誤:', err);
          // 自動重試最多 3 次
          if (retryCount < 3) {
            setTimeout(() => sendMessage(retryCount + 1), 1000);
          } else {
            alert('發送失敗，請檢查網路連線後再試');
            resetUI();
          }
        });
    }

    // 發送按鈕
    document.getElementById('sendBtn').addEventListener('click', sendMessage);

    // Enter 鍵發送
    document.getElementById('chatInput').addEventListener('keypress', function(e) {
      if (e.key === 'Enter') {
        e.preventDefault();
        sendMessage();
      }
    });

    // 語音錄製
    document.getElementById('voiceBtn').addEventListener('click', async function() {
      if (mediaRecorder && mediaRecorder.state === 'recording') {
        return; // 已在錄音中
      }

      try {
        const stream = await navigator.mediaDevices.getUserMedia({ audio: true });
        mediaRecorder = new MediaRecorder(stream);
        audioChunks = [];

        mediaRecorder.ondataavailable = e => audioChunks.push(e.data);

        mediaRecorder.onstop = () => {
          stream.getTracks().forEach(track => track.stop());
          const blob = new Blob(audioChunks, { type: 'audio/webm' });
          uploadVoice(blob);
        };

        mediaRecorder.start();
        recordingStartTime = Date.now();
        document.getElementById('recordingIndicator').classList.add('active');
        this.disabled = true;

        // 更新錄音時間
        recordingTimer = setInterval(() => {
          const elapsed = Math.floor((Date.now() - recordingStartTime) / 1000);
          const min = Math.floor(elapsed / 60);
          const sec = elapsed % 60;
          document.getElementById('recordingTime').textContent = min + ':' + (sec < 10 ? '0' : '') + sec;
        }, 1000);

      } catch (err) {
        alert('無法存取麥克風');
      }
    });

    // 停止錄音
    document.getElementById('stopRecordingBtn').addEventListener('click', function() {
      if (mediaRecorder && mediaRecorder.state === 'recording') {
        mediaRecorder.stop();
        clearInterval(recordingTimer);
        document.getElementById('recordingIndicator').classList.remove('active');
        document.getElementById('voiceBtn').disabled = false;
      }
    });

    // 上傳語音
    function uploadVoice(blob) {
      const formData = new FormData();
      formData.append('action', 'upload_voice');
      formData.append('friend_id', currentFriendId);
      formData.append('voice', blob, 'voice.webm');

      fetch('chat_api.php', {
        method: 'POST',
        body: formData
      })
        .then(r => r.json())
        .then(data => {
          if (!data.success) {
            alert(data.message);
          }
        })
        .catch(err => alert('語音上傳失敗'));
    }

    // 滾動到底部
    function scrollToBottom() {
      const container = document.getElementById('chatMessages');
      container.scrollTop = container.scrollHeight;
    }

    // HTML 跳脫
    function escapeHtml(text) {
      const div = document.createElement('div');
      div.textContent = text;
      return div.innerHTML;
    }
  </script>
</body>
</html>
