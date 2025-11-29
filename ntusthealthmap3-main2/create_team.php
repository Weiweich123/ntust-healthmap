<?php
require_once __DIR__ . '/db.php';
require_login();

$msg = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') $msg = '請輸入團隊名稱';
    else {
        // create code
        $code = bin2hex(random_bytes(4));
        $stmt = $pdo->prepare('INSERT INTO teams (name,code) VALUES (?,?)');
        $stmt->execute([$name,$code]);
        $team_id = $pdo->lastInsertId();
        $stmt = $pdo->prepare('INSERT INTO team_members (team_id,user_id,role) VALUES (?,?,?)');
        $stmt->execute([$team_id,$_SESSION['user_id'],'owner']);
        $msg = '建立成功，邀請碼: ' . $code;
    }
}
?>
<!doctype html>
<html lang="zh-TW">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>建立團隊 - 台科大健康任務地圖</title>
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
                            <i class="fas fa-users"></i>
                          </div>
                          <h3 class="card-title justify-content-center">建立團隊</h3>
                          <p class="text-muted">創建您的健康任務團隊</p>
                        </div>
                        <?php if($msg):?>
                          <div class="alert alert-success">
                            <i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($msg);?>
                          </div>
                        <?php endif;?>
                        <form method="post">
                            <div class="mb-4">
                                <label class="form-label">團隊名稱</label>
                                <input name="name" class="form-control" placeholder="輸入團隊名稱" required>
                            </div>
                            <div class="d-grid">
                                <button class="btn btn-primary btn-lg" type="submit">
                                  <i class="fas fa-plus-circle me-2"></i>建立團隊
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
