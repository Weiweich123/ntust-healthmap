<?php
require_once __DIR__ . '/db.php';

if (is_logged_in()) {
    header('Location: index.php'); exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($username && $password) {
    $stmt = $pdo->prepare('SELECT user_id, password_hash, display_name FROM users WHERE username = ?');
        $stmt->execute([$username]);
        $user = $stmt->fetch();
        if ($user && password_verify($password, $user['password_hash'])) {
      $_SESSION['user_id'] = $user['user_id'];
      $_SESSION['display_name'] = $user['display_name'] ?? $username;
            header('Location: index.php'); exit;
        }
    }
    $error = '帳號或密碼錯誤';
}
?>
<!doctype html>
<html lang="zh-TW">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>登入 - 台科大健康任務地圖</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link href="assets/styles.css" rel="stylesheet">
  <style>
    html, body {
      margin: 0;
      padding: 0;
      overflow: hidden !important;
      width: 100%;
      height: 100%;
    }
  </style>
</head>
<body class="auth-page">
  <div class="auth-card">
          <div class="auth-logo">
            <i class="fas fa-heartbeat"></i>
          </div>
          <h1 class="auth-title">歡迎回來</h1>
          <p class="auth-subtitle">登入以繼續您的健康任務</p>
    
    <?php if ($error): ?>
      <div class="alert alert-danger" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?>
      </div>
    <?php endif; ?>
    
    <form method="post">
      <div class="mb-3">
        <label class="form-label">帳號</label>
        <input name="username" class="form-control" placeholder="請輸入帳號" required>
      </div>
      <div class="mb-4">
        <label class="form-label">密碼</label>
        <input name="password" type="password" class="form-control" placeholder="請輸入密碼" required>
      </div>
      <div class="d-grid">
        <button type="submit" class="btn btn-primary btn-lg">
          <i class="fas fa-sign-in-alt me-2"></i>登入
        </button>
      </div>
    </form>
    
    <div class="auth-footer">
      <span class="text-muted">還沒有帳號？</span>
      <a href="register.php">立即註冊</a>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
