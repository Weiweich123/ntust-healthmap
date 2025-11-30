<?php
require_once __DIR__ . '/db.php';
require_login();

$user_id = $_SESSION['user_id'];

// 取得使用者資訊
$stmt = $pdo->prepare('SELECT username, display_name, points, money FROM users WHERE user_id = ?');
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// 預設時間區間（過去30天）
$default_start = date('Y-m-d', strtotime('-30 days'));
$default_end = date('Y-m-d');

$start_date = $_GET['start_date'] ?? $default_start;
$end_date = $_GET['end_date'] ?? $default_end;

// 驗證日期格式
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start_date)) {
    $start_date = $default_start;
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $end_date)) {
    $end_date = $default_end;
}

// 查詢點數紀錄（優先使用 points_logs，若無則用 activities）
$stmt = $pdo->prepare('
    SELECT SUM(amount) as total_points
    FROM points_logs
    WHERE user_id = ?
    AND DATE(created_at) BETWEEN ? AND ?
');
$stmt->execute([$user_id, $start_date, $end_date]);
$points_log_summary = $stmt->fetch();

// 如果 points_logs 沒有資料，使用 activities
if (empty($points_log_summary['total_points'])) {
    $stmt = $pdo->prepare('
        SELECT SUM(points_earned) as total_points
        FROM activities
        WHERE user_id = ?
        AND activity_date BETWEEN ? AND ?
    ');
    $stmt->execute([$user_id, $start_date, $end_date]);
    $activity_summary = $stmt->fetch();
    $total_points = (int)($activity_summary['total_points'] ?? 0);
} else {
    $total_points = (int)($points_log_summary['total_points'] ?? 0);
}

// 查詢建築相關獲得的金錢（解鎖 + 升級）
$stmt = $pdo->prepare('
    SELECT SUM(amount) as total_building_money
    FROM money_logs
    WHERE user_id = ?
    AND source IN ("building_unlock", "building_upgrade")
    AND DATE(created_at) BETWEEN ? AND ?
');
$stmt->execute([$user_id, $start_date, $end_date]);
$building_summary = $stmt->fetch();

$total_money = (int)($building_summary['total_building_money'] ?? 0);

// 查詢點數明細（優先使用 points_logs）
$stmt = $pdo->prepare('
    SELECT
        DATE(created_at) as record_date,
        SUM(amount) as daily_points
    FROM points_logs
    WHERE user_id = ?
    AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
');
$stmt->execute([$user_id, $start_date, $end_date]);
$points_log_records = $stmt->fetchAll();

// 如果沒有 points_logs 資料，使用 activities
if (empty($points_log_records)) {
    $stmt = $pdo->prepare('
        SELECT
            activity_date as record_date,
            SUM(points_earned) as daily_points
        FROM activities
        WHERE user_id = ?
        AND activity_date BETWEEN ? AND ?
        GROUP BY activity_date
    ');
    $stmt->execute([$user_id, $start_date, $end_date]);
    $points_log_records = $stmt->fetchAll();
}

// 查詢建築每日金錢明細
$stmt = $pdo->prepare('
    SELECT
        DATE(created_at) as record_date,
        SUM(amount) as daily_money
    FROM money_logs
    WHERE user_id = ?
    AND source IN ("building_unlock", "building_upgrade")
    AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
');
$stmt->execute([$user_id, $start_date, $end_date]);
$building_records = $stmt->fetchAll();

// 查詢詳細的點數紀錄列表
$stmt = $pdo->prepare('
    SELECT log_id, amount, source, description, created_at
    FROM points_logs
    WHERE user_id = ?
    AND DATE(created_at) BETWEEN ? AND ?
    ORDER BY created_at DESC
    LIMIT 100
');
$stmt->execute([$user_id, $start_date, $end_date]);
$detailed_points_logs = $stmt->fetchAll();

// 合併並按日期分組
$daily_data = [];
foreach ($points_log_records as $r) {
    $date = $r['record_date'];
    if (!isset($daily_data[$date])) {
        $daily_data[$date] = ['points' => 0, 'money' => 0];
    }
    $daily_data[$date]['points'] += (int)$r['daily_points'];
}
foreach ($building_records as $r) {
    $date = $r['record_date'];
    if (!isset($daily_data[$date])) {
        $daily_data[$date] = ['points' => 0, 'money' => 0];
    }
    $daily_data[$date]['money'] += (int)$r['daily_money'];
}

// 按日期降序排列
krsort($daily_data);
?>
<!doctype html>
<html lang="zh-TW">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>點數金錢紀錄查詢 - 台科大健康任務地圖</title>
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
      <div class="d-flex align-items-center gap-3">
        <span class="badge bg-primary">
          <i class="fas fa-star me-1"></i><?php echo (int)$user['points']; ?> 點
        </span>
        <span class="badge bg-success">
          <i class="fas fa-coins me-1"></i><?php echo (int)$user['money']; ?> 元
        </span>
        <a class="btn btn-outline-secondary btn-sm" href="index.php">
          <i class="fas fa-arrow-left me-1"></i>返回首頁
        </a>
      </div>
    </div>
  </nav>

  <div class="container container-main">
    <div class="row justify-content-center">
      <div class="col-lg-10">
        <div class="card mb-4">
          <div class="card-body">
            <h4 class="card-title mb-4">
              <i class="fas fa-chart-line me-2" style="color: var(--primary);"></i>點數金錢紀錄查詢
            </h4>

            <!-- 時間區間查詢表單 -->
            <form method="get" class="row g-3 mb-4">
              <div class="col-md-5">
                <label class="form-label">開始日期</label>
                <input type="date" name="start_date" class="form-control" value="<?php echo htmlspecialchars($start_date); ?>">
              </div>
              <div class="col-md-5">
                <label class="form-label">結束日期</label>
                <input type="date" name="end_date" class="form-control" value="<?php echo htmlspecialchars($end_date); ?>">
              </div>
              <div class="col-md-2 d-flex align-items-end">
                <button type="submit" class="btn btn-primary w-100">
                  <i class="fas fa-search me-2"></i>查詢
                </button>
              </div>
            </form>

            <!-- 快速選擇按鈕 -->
            <div class="mb-4">
              <span class="text-muted me-2">快速選擇：</span>
              <a href="?start_date=<?php echo date('Y-m-d', strtotime('-1 days')); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="btn btn-outline-secondary btn-sm">近一日</a>
              <a href="?start_date=<?php echo date('Y-m-d', strtotime('-3 days')); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="btn btn-outline-secondary btn-sm">近三日</a>
              <a href="?start_date=<?php echo date('Y-m-d', strtotime('-7 days')); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="btn btn-outline-secondary btn-sm">近七日</a>
              <a href="?start_date=<?php echo date('Y-m-d', strtotime('-30 days')); ?>&end_date=<?php echo date('Y-m-d'); ?>" class="btn btn-outline-secondary btn-sm">近一月</a>
            </div>

            <!-- 統計摘要 -->
            <div class="row g-3 mb-4">
              <div class="col-md-6">
                <div class="card bg-primary text-white">
                  <div class="card-body text-center">
                    <h3 class="mb-1"><?php echo number_format($total_points); ?></h3>
                    <div><i class="fas fa-star me-1"></i>獲得點數</div>
                  </div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="card bg-success text-white">
                  <div class="card-body text-center">
                    <h3 class="mb-1"><?php echo number_format($total_money); ?></h3>
                    <div><i class="fas fa-coins me-1"></i>獲得金錢</div>
                  </div>
                </div>
              </div>
            </div>

            <!-- 每日明細表格 -->
            <?php if (count($daily_data) > 0): ?>
            <h5 class="mb-3"><i class="fas fa-list me-2"></i>每日明細</h5>
            <div class="table-responsive">
              <table class="table table-hover">
                <thead>
                  <tr>
                    <th class="text-center">日期</th>
                    <th class="text-center">獲得點數</th>
                    <th class="text-center">獲得金錢</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($daily_data as $date => $data): ?>
                  <tr>
                    <td class="text-center"><?php echo htmlspecialchars($date); ?></td>
                    <td class="text-center">
                      <span class="text-primary">
                        <i class="fas fa-star me-1"></i><?php echo number_format($data['points']); ?>
                      </span>
                    </td>
                    <td class="text-center">
                      <span class="text-success">
                        <i class="fas fa-coins me-1"></i><?php echo number_format($data['money']); ?>
                      </span>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php else: ?>
            <div class="alert alert-info text-center">
              <i class="fas fa-info-circle me-2"></i>此期間內沒有任何紀錄
            </div>
            <?php endif; ?>

            <!-- 詳細點數紀錄 -->
            <?php if (!empty($detailed_points_logs)): ?>
            <h5 class="mb-3 mt-4"><i class="fas fa-history me-2"></i>詳細點數紀錄</h5>
            <div class="table-responsive">
              <table class="table table-hover table-sm">
                <thead>
                  <tr>
                    <th>時間</th>
                    <th>來源</th>
                    <th>說明</th>
                    <th class="text-end">點數</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($detailed_points_logs as $log):
                    $source_labels = [
                      'activity' => '<span class="badge bg-primary">運動</span>',
                      'team_bonus' => '<span class="badge bg-info">團隊獎勵</span>',
                      'team_task' => '<span class="badge bg-warning text-dark">團隊任務</span>',
                      'other' => '<span class="badge bg-secondary">其他</span>',
                    ];
                    $source_label = $source_labels[$log['source']] ?? '<span class="badge bg-secondary">' . htmlspecialchars($log['source']) . '</span>';
                  ?>
                  <tr>
                    <td><?php echo date('m/d H:i', strtotime($log['created_at'])); ?></td>
                    <td><?php echo $source_label; ?></td>
                    <td><?php echo htmlspecialchars($log['description'] ?? '-'); ?></td>
                    <td class="text-end <?php echo $log['amount'] >= 0 ? 'text-success' : 'text-danger'; ?>">
                      <?php echo ($log['amount'] >= 0 ? '+' : '') . number_format($log['amount']); ?>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php endif; ?>

          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
