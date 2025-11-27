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
    <link href="assets/styles.css" rel="stylesheet">
</head>
<body>
    <nav class="navbar navbar-expand-lg navbar-light bg-light mb-4">
        <div class="container">
            <a class="navbar-brand" href="index.php">台科大健康任務地圖</a>
            <div class="collapse navbar-collapse">
                <ul class="navbar-nav ms-auto">
                    <li class="nav-item"><a class="nav-link" href="team.php">我的團隊</a></li>
                </ul>
            </div>
        </div>
    </nav>

    <div class="container">
        <div class="row justify-content-center">
            <div class="col-md-6">
                <div class="card shadow-sm">
                    <div class="card-body">
                        <h3 class="card-title mb-3">建立團隊</h3>
                        <?php if($msg):?><div class="alert alert-info"><?php echo htmlspecialchars($msg);?></div><?php endif;?>
                        <form method="post">
                            <div class="mb-3">
                                <label class="form-label">團隊名稱</label>
                                <input name="name" class="form-control" required>
                            </div>
                            <div class="d-grid">
                                <button class="btn btn-success" type="submit">建立團隊</button>
                            </div>
                        </form>
                        <hr>
                        <p class="mb-0"><a href="index.php">回首頁</a></p>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
