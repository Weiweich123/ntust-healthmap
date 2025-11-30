<?php
require_once __DIR__ . '/db.php';

if (is_logged_in()) {
    header('Location: index.php'); exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $display = trim($_POST['display_name'] ?? '');
    
    // 處理出生日期
    $birth_year = (int)($_POST['birth_year'] ?? 0);
    $birth_month = (int)($_POST['birth_month'] ?? 0);
    $birth_day = (int)($_POST['birth_day'] ?? 0);
    $birth_date = null;
    
    if ($birth_year > 0 && $birth_month > 0 && $birth_day > 0) {
        // 驗證日期有效性
        if (checkdate($birth_month, $birth_day, $birth_year)) {
            $birth_date = sprintf('%04d-%02d-%02d', $birth_year, $birth_month, $birth_day);
        }
    }

    if ($username === '' || $password === '') {
        $error = '請填寫帳號與密碼';
    } else {
    // check exists
    $stmt = $pdo->prepare('SELECT user_id FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $error = '帳號已被使用';
        } else {
            $hash = password_hash($password, PASSWORD_DEFAULT);
            $stmt = $pdo->prepare('INSERT INTO users (username,password_hash,display_name,birth_date) VALUES (?,?,?,?)');
            $stmt->execute([$username, $hash, $display, $birth_date]);
            header('Location: login.php'); exit;
        }
    }
}
?>
<!doctype html>
<html lang="zh-TW">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>註冊 - 台科大健康任務地圖</title>
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
            <i class="fas fa-user-plus"></i>
          </div>
          <h1 class="auth-title">建立帳號</h1>
          <p class="auth-subtitle">加入台科大健康任務地圖</p>
    
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
      <div class="mb-3">
        <label class="form-label">密碼</label>
        <input name="password" type="password" class="form-control" placeholder="請輸入密碼" required>
      </div>
      <div class="mb-4">
        <label class="form-label">顯示名稱 <span class="text-muted">(選填)</span></label>
        <input name="display_name" class="form-control" placeholder="其他人會看到的名稱">
      </div>
      
      <div class="mb-3">
        <label class="form-label">出生日期 <span class="text-muted">(選填)</span></label>
        <div class="row g-2">
          <div class="col-4">
            <select name="birth_year" class="form-select">
              <option value="">年</option>
              <?php
              $current_year = date('Y');
              for ($y = $current_year; $y >= $current_year - 100; $y--) {
                echo "<option value=\"$y\">$y</option>";
              }
              ?>
            </select>
          </div>
          <div class="col-4">
            <select name="birth_month" class="form-select">
              <option value="">月</option>
              <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?php echo $m; ?>"><?php echo $m; ?></option>
              <?php endfor; ?>
            </select>
          </div>
          <div class="col-4">
            <select name="birth_day" class="form-select">
              <option value="">日</option>
              <?php for ($d = 1; $d <= 31; $d++): ?>
                <option value="<?php echo $d; ?>"><?php echo $d; ?></option>
              <?php endfor; ?>
            </select>
          </div>
        </div>
        <small class="text-muted d-block mt-1">
          <i class="fas fa-info-circle me-1"></i>此僅為計算年齡參照運動基準使用
        </small>
      </div>
      
      <div class="d-grid">
        <button type="submit" class="btn btn-primary btn-lg">
          <i class="fas fa-check-circle me-2"></i>註冊
        </button>
      </div>
    </form>

    <div class="auth-footer">
      <span class="text-muted">已經有帳號？</span>
      <a href="login.php">立即登入</a>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
