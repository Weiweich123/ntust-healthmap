<?php
require_once __DIR__ . '/db.php';
require_login();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $code = trim($_POST['code'] ?? '');
    if ($code === '') $msg = '請輸入團隊邀請碼';
    else {
        $stmt = $pdo->prepare('SELECT team_id FROM teams WHERE code = ?');
        $stmt->execute([$code]);
        $team = $stmt->fetch();
        if (!$team) $msg = '邀請碼無效';
        else {
            $stmt = $pdo->prepare('INSERT IGNORE INTO team_members (team_id,user_id,role) VALUES (?,?,?)');
            $stmt->execute([$team['team_id'],$_SESSION['user_id'],'member']);
            $msg = '已加入團隊';
        }
    }
}
?>
<!doctype html>
<html lang="zh-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>加入團隊 - 台科大健康任務地圖</title>
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
            <div class="col-md-6 col-lg-5">
                <div class="card">
                    <div class="card-body">
                        <div class="text-center mb-4">
                          <div class="auth-logo" style="margin: 0 auto 1rem;">
                            <i class="fas fa-sign-in-alt"></i>
                          </div>
                          <h3 class="card-title justify-content-center">加入團隊</h3>
                          <p class="text-muted">輸入邀請碼加入現有團隊</p>
                        </div>
                        <?php if($msg):?>
                          <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($msg);?>
                          </div>
                        <?php endif;?>
                        <form method="post">
                            <div class="mb-4">
                                <label class="form-label">邀請碼</label>
                                <input name="code" class="form-control" placeholder="輸入團隊邀請碼" required>
                                <small class="text-muted"><i class="fas fa-info-circle me-1"></i>請向團隊創建者索取邀請碼</small>
                            </div>
                            <div class="d-grid">
                                <button class="btn btn-primary btn-lg" type="submit">
                                  <i class="fas fa-user-plus me-2"></i>加入團隊
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
