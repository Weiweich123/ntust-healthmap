<?php
/**
 * 聊天 API
 * 處理好友之間的聊天訊息
 */
require_once __DIR__ . '/db.php';
require_login();

header('Content-Type: application/json; charset=utf-8');

$user_id = $_SESSION['user_id'];
$action = $_REQUEST['action'] ?? '';

// 驗證是否為好友
function are_friends($pdo, $user1, $user2) {
    $stmt = $pdo->prepare('
        SELECT 1 FROM friendships
        WHERE ((user_id = ? AND friend_id = ?) OR (user_id = ? AND friend_id = ?))
        AND status = "accepted"
    ');
    $stmt->execute([$user1, $user2, $user2, $user1]);
    return $stmt->fetch() !== false;
}

switch ($action) {
    // 取得與某好友的聊天記錄
    case 'get_messages':
        $friend_id = (int)($_REQUEST['friend_id'] ?? 0);
        if ($friend_id <= 0) {
            echo json_encode(['success' => false, 'message' => '無效的好友ID']);
            exit;
        }

        // 驗證是否為好友
        if (!are_friends($pdo, $user_id, $friend_id)) {
            echo json_encode(['success' => false, 'message' => '對方不是你的好友']);
            exit;
        }

        // 取得聊天記錄（最近100則）
        $stmt = $pdo->prepare('
            SELECT m.message_id, m.sender_id, m.receiver_id, m.message_type, m.content,
                   m.is_read, DATE_ADD(m.created_at, INTERVAL 8 HOUR) AS created_at,
                   u.display_name AS sender_name, u.username AS sender_username
            FROM chat_messages m
            JOIN users u ON m.sender_id = u.user_id
            WHERE (m.sender_id = ? AND m.receiver_id = ?)
               OR (m.sender_id = ? AND m.receiver_id = ?)
            ORDER BY m.created_at ASC
            LIMIT 100
        ');
        $stmt->execute([$user_id, $friend_id, $friend_id, $user_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 標記對方發送的訊息為已讀
        $stmt = $pdo->prepare('
            UPDATE chat_messages
            SET is_read = "Read", read_at = NOW()
            WHERE sender_id = ? AND receiver_id = ? AND is_read = "Unread"
        ');
        $stmt->execute([$friend_id, $user_id]);

        echo json_encode(['success' => true, 'messages' => $messages, 'user_id' => $user_id]);
        break;

    // 發送訊息
    case 'send_message':
        $friend_id = (int)($_REQUEST['friend_id'] ?? 0);
        $message_type = $_REQUEST['message_type'] ?? 'text';
        $content = trim($_REQUEST['content'] ?? '');

        if ($friend_id <= 0) {
            echo json_encode(['success' => false, 'message' => '無效的好友ID']);
            exit;
        }

        if ($content === '') {
            echo json_encode(['success' => false, 'message' => '訊息內容不能為空']);
            exit;
        }

        // 驗證是否為好友
        if (!are_friends($pdo, $user_id, $friend_id)) {
            echo json_encode(['success' => false, 'message' => '對方不是你的好友']);
            exit;
        }

        // 驗證訊息類型
        if (!in_array($message_type, ['text', 'voice'])) {
            $message_type = 'text';
        }

        // 插入訊息
        $stmt = $pdo->prepare('
            INSERT INTO chat_messages (sender_id, receiver_id, message_type, content)
            VALUES (?, ?, ?, ?)
        ');
        $stmt->execute([$user_id, $friend_id, $message_type, $content]);
        $message_id = $pdo->lastInsertId();

        // 取得剛插入的訊息
        $stmt = $pdo->prepare('SELECT * FROM chat_messages WHERE message_id = ?');
        $stmt->execute([$message_id]);
        $message = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'message' => $message]);
        break;

    // 上傳語音訊息
    case 'upload_voice':
        $friend_id = (int)($_POST['friend_id'] ?? 0);

        if ($friend_id <= 0) {
            echo json_encode(['success' => false, 'message' => '無效的好友ID']);
            exit;
        }

        // 驗證是否為好友
        if (!are_friends($pdo, $user_id, $friend_id)) {
            echo json_encode(['success' => false, 'message' => '對方不是你的好友']);
            exit;
        }

        // 檢查是否有上傳檔案
        if (!isset($_FILES['voice']) || $_FILES['voice']['error'] !== UPLOAD_ERR_OK) {
            echo json_encode(['success' => false, 'message' => '語音上傳失敗']);
            exit;
        }

        // 建立語音檔案目錄
        $upload_dir = __DIR__ . '/uploads/voice/';
        if (!is_dir($upload_dir)) {
            mkdir($upload_dir, 0755, true);
        }

        // 產生唯一檔名
        $filename = uniqid('voice_' . $user_id . '_') . '.webm';
        $filepath = $upload_dir . $filename;

        // 移動上傳的檔案
        if (!move_uploaded_file($_FILES['voice']['tmp_name'], $filepath)) {
            echo json_encode(['success' => false, 'message' => '儲存語音檔案失敗']);
            exit;
        }

        // 儲存到資料庫
        $content = 'uploads/voice/' . $filename;
        $stmt = $pdo->prepare('
            INSERT INTO chat_messages (sender_id, receiver_id, message_type, content)
            VALUES (?, ?, "voice", ?)
        ');
        $stmt->execute([$user_id, $friend_id, $content]);
        $message_id = $pdo->lastInsertId();

        // 取得剛插入的訊息
        $stmt = $pdo->prepare('SELECT * FROM chat_messages WHERE message_id = ?');
        $stmt->execute([$message_id]);
        $message = $stmt->fetch(PDO::FETCH_ASSOC);

        echo json_encode(['success' => true, 'message' => $message]);
        break;

    // 取得未讀訊息數量（用於顯示徽章）
    case 'get_unread_counts':
        $stmt = $pdo->prepare('
            SELECT sender_id, COUNT(*) as unread_count
            FROM chat_messages
            WHERE receiver_id = ? AND is_read = "Unread"
            GROUP BY sender_id
        ');
        $stmt->execute([$user_id]);
        $counts = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 轉換成以 sender_id 為 key 的陣列
        $unread = [];
        foreach ($counts as $row) {
            $unread[$row['sender_id']] = (int)$row['unread_count'];
        }

        echo json_encode(['success' => true, 'unread' => $unread]);
        break;

    // 檢查新訊息（用於輪詢）
    case 'check_new_messages':
        $friend_id = (int)($_REQUEST['friend_id'] ?? 0);
        $last_message_id = (int)($_REQUEST['last_message_id'] ?? 0);

        if ($friend_id <= 0) {
            echo json_encode(['success' => false, 'message' => '無效的好友ID']);
            exit;
        }

        // 取得新訊息
        $stmt = $pdo->prepare('
            SELECT m.message_id, m.sender_id, m.receiver_id, m.message_type, m.content,
                   m.is_read, DATE_ADD(m.created_at, INTERVAL 8 HOUR) AS created_at
            FROM chat_messages m
            WHERE ((m.sender_id = ? AND m.receiver_id = ?) OR (m.sender_id = ? AND m.receiver_id = ?))
              AND m.message_id > ?
            ORDER BY m.created_at ASC
        ');
        $stmt->execute([$user_id, $friend_id, $friend_id, $user_id, $last_message_id]);
        $messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // 標記對方發送的訊息為已讀
        if (count($messages) > 0) {
            $stmt = $pdo->prepare('
                UPDATE chat_messages
                SET is_read = "Read", read_at = NOW()
                WHERE sender_id = ? AND receiver_id = ? AND is_read = "Unread"
            ');
            $stmt->execute([$friend_id, $user_id]);
        }

        // 檢查自己發送的訊息是否已讀
        $stmt = $pdo->prepare('
            SELECT message_id, is_read FROM chat_messages
            WHERE sender_id = ? AND receiver_id = ? AND is_read = "Read"
        ');
        $stmt->execute([$user_id, $friend_id]);
        $read_status = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $read_ids = [];
        foreach ($read_status as $row) {
            $read_ids[] = (int)$row['message_id'];
        }

        echo json_encode([
            'success' => true,
            'messages' => $messages,
            'read_ids' => $read_ids,
            'user_id' => $user_id
        ]);
        break;

    default:
        echo json_encode(['success' => false, 'message' => '無效的操作']);
        break;
}
