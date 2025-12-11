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

// 查詢活動紀錄中的點數
$stmt = $pdo->prepare('
    SELECT SUM(points_earned) as total_points
    FROM activities 
    WHERE user_id = ? 
    AND activity_date BETWEEN ? AND ?
');
$stmt->execute([$user_id, $start_date, $end_date]);
$activity_summary = $stmt->fetch();

// 查詢團隊任務獲得的點數
$stmt = $pdo->prepare('
    SELECT SUM(amount) as total_task_points
    FROM points_logs 
    WHERE user_id = ? 
    AND source = "team_task"
    AND DATE(created_at) BETWEEN ? AND ?
');
$stmt->execute([$user_id, $start_date, $end_date]);
$task_summary = $stmt->fetch();

// 查詢建築解鎖/升級扣除的點數
$stmt = $pdo->prepare('
    SELECT 
        COALESCE(SUM(CASE WHEN source = "building_unlock" THEN cost ELSE 0 END), 0) as unlock_cost,
        COALESCE(SUM(CASE WHEN source = "building_upgrade" THEN cost ELSE 0 END), 0) as upgrade_cost
    FROM (
        SELECT "building_unlock" as source, unlock_cost as cost, unlocked_at as created_at
        FROM user_buildings ub
        JOIN buildings b ON ub.building_id = b.building_id
        WHERE ub.user_id = ? AND DATE(ub.unlocked_at) BETWEEN ? AND ?
    ) as deductions
');
$stmt->execute([$user_id, $start_date, $end_date]);
$deduction_summary = $stmt->fetch();

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

$activity_points = (int)($activity_summary['total_points'] ?? 0);
$task_points = (int)($task_summary['total_task_points'] ?? 0);
$unlock_cost = (int)($deduction_summary['unlock_cost'] ?? 0);
$upgrade_cost = (int)($deduction_summary['upgrade_cost'] ?? 0);
$earned_points = $activity_points + $task_points - $unlock_cost - $upgrade_cost;
$total_money = (int)($building_summary['total_building_money'] ?? 0);

// 查詢活動每日點數明細
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
$activity_records = $stmt->fetchAll();

// 查詢團隊任務每日點數明細
$stmt = $pdo->prepare('
    SELECT 
        DATE(created_at) as record_date,
        SUM(amount) as daily_points
    FROM points_logs 
    WHERE user_id = ? 
    AND source = "team_task"
    AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
');
$stmt->execute([$user_id, $start_date, $end_date]);
$task_records = $stmt->fetchAll();

// 查詢建築解鎖每日扣除點數
$stmt = $pdo->prepare('
    SELECT 
        DATE(unlocked_at) as record_date,
        SUM(b.unlock_cost) as daily_deduct
    FROM user_buildings ub
    JOIN buildings b ON ub.building_id = b.building_id
    WHERE ub.user_id = ? 
    AND DATE(ub.unlocked_at) BETWEEN ? AND ?
    GROUP BY DATE(unlocked_at)
');
$stmt->execute([$user_id, $start_date, $end_date]);
$unlock_deduct_records = $stmt->fetchAll();

// 查詢建築升級每日扣除點數（從 money_logs 反推，升級費用 = 獲得金錢 * 2）
$stmt = $pdo->prepare('
    SELECT 
        DATE(created_at) as record_date,
        SUM(amount * 2) as daily_deduct
    FROM money_logs 
    WHERE user_id = ? 
    AND source = "building_upgrade"
    AND DATE(created_at) BETWEEN ? AND ?
    GROUP BY DATE(created_at)
');
$stmt->execute([$user_id, $start_date, $end_date]);
$upgrade_deduct_records = $stmt->fetchAll();

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

// 合併並按日期分組（扣除點數已計算在獲得點數中）
$daily_data = [];
foreach ($activity_records as $r) {
    $date = $r['record_date'];
    if (!isset($daily_data[$date])) {
        $daily_data[$date] = ['points' => 0, 'money' => 0];
    }
    $daily_data[$date]['points'] += (int)$r['daily_points'];
}
foreach ($task_records as $r) {
    $date = $r['record_date'];
    if (!isset($daily_data[$date])) {
        $daily_data[$date] = ['points' => 0, 'money' => 0];
    }
    $daily_data[$date]['points'] += (int)$r['daily_points'];
}
foreach ($unlock_deduct_records as $r) {
    $date = $r['record_date'];
    if (!isset($daily_data[$date])) {
        $daily_data[$date] = ['points' => 0, 'money' => 0];
    }
    $daily_data[$date]['points'] -= (int)$r['daily_deduct'];
}
foreach ($upgrade_deduct_records as $r) {
    $date = $r['record_date'];
    if (!isset($daily_data[$date])) {
        $daily_data[$date] = ['points' => 0, 'money' => 0];
    }
    $daily_data[$date]['points'] -= (int)$r['daily_deduct'];
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

// 查詢詳細點數紀錄（運動獲得 + 團隊任務獲得 + 建築解鎖扣除）
$detailed_points = [];

// 運動獲得點數
$stmt = $pdo->prepare('
    SELECT 
        activity_date as record_date,
        created_at,
        steps,
        time_minutes,
        water_ml,
        points_earned
    FROM activities 
    WHERE user_id = ? 
    AND activity_date BETWEEN ? AND ?
    ORDER BY created_at DESC
');
$stmt->execute([$user_id, $start_date, $end_date]);
$activity_details = $stmt->fetchAll();

foreach ($activity_details as $r) {
    $detailed_points[] = [
        'datetime' => $r['created_at'],
        'source' => '運動',
        'description' => '步數:' . number_format($r['steps']) . ' / 運動:' . $r['time_minutes'] . '分鐘 / 喝水:' . number_format($r['water_ml']) . 'ml',
        'points' => (int)$r['points_earned']
    ];
}

// 團隊任務獲得點數
$stmt = $pdo->prepare('
    SELECT 
        pl.created_at,
        pl.amount,
        pl.description
    FROM points_logs pl
    WHERE pl.user_id = ? 
    AND pl.source = "team_task"
    AND DATE(pl.created_at) BETWEEN ? AND ?
    ORDER BY pl.created_at DESC
');
$stmt->execute([$user_id, $start_date, $end_date]);
$task_details = $stmt->fetchAll();

foreach ($task_details as $r) {
    $detailed_points[] = [
        'datetime' => $r['created_at'],
        'source' => '團隊任務',
        'description' => $r['description'] ?? '完成團隊任務',
        'points' => (int)$r['amount']
    ];
}

// 建築解鎖扣除點數
$stmt = $pdo->prepare('
    SELECT 
        ub.unlocked_at as created_at,
        b.name as building_name,
        b.unlock_cost
    FROM user_buildings ub
    JOIN buildings b ON ub.building_id = b.building_id
    WHERE ub.user_id = ? 
    AND DATE(ub.unlocked_at) BETWEEN ? AND ?
    ORDER BY ub.unlocked_at DESC
');
$stmt->execute([$user_id, $start_date, $end_date]);
$unlock_details = $stmt->fetchAll();

foreach ($unlock_details as $r) {
    $detailed_points[] = [
        'datetime' => $r['created_at'],
        'source' => '解鎖建築',
        'description' => '解鎖「' . $r['building_name'] . '」',
        'points' => -(int)$r['unlock_cost']
    ];
}

// 建築升級扣除點數（從 money_logs 反推）
$stmt = $pdo->prepare('
    SELECT 
        ml.created_at,
        ml.description,
        ml.amount
    FROM money_logs ml
    WHERE ml.user_id = ? 
    AND ml.source = "building_upgrade"
    AND DATE(ml.created_at) BETWEEN ? AND ?
    ORDER BY ml.created_at DESC
');
$stmt->execute([$user_id, $start_date, $end_date]);
$upgrade_point_details = $stmt->fetchAll();

foreach ($upgrade_point_details as $r) {
    $detailed_points[] = [
        'datetime' => $r['created_at'],
        'source' => '升級建築',
        'description' => $r['description'] ?? '升級建築',
        'points' => -(int)($r['amount'] * 2)  // 升級費用 = 獲得金錢 * 2
    ];
}

// 按時間降序排列
usort($detailed_points, function($a, $b) {
    return strtotime($b['datetime']) - strtotime($a['datetime']);
});

// 查詢詳細金錢紀錄
$stmt = $pdo->prepare('
    SELECT 
        ml.created_at,
        ml.source,
        ml.description,
        ml.amount
    FROM money_logs ml
    WHERE ml.user_id = ? 
    AND ml.source IN ("building_unlock", "building_upgrade")
    AND DATE(ml.created_at) BETWEEN ? AND ?
    ORDER BY ml.created_at DESC
');
$stmt->execute([$user_id, $start_date, $end_date]);
$detailed_money = $stmt->fetchAll();
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
                    <h3 class="mb-1"><?php echo number_format($earned_points); ?></h3>
                    <div><i class="fas fa-star me-1"></i>點數總計</div>
                  </div>
                </div>
              </div>
              <div class="col-md-6">
                <div class="card bg-success text-white">
                  <div class="card-body text-center">
                    <h3 class="mb-1"><?php echo number_format($total_money); ?></h3>
                    <div><i class="fas fa-coins me-1"></i>金錢總計</div>
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
                    <th class="text-center">點數總計</th>
                    <th class="text-center">金錢總計</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($daily_data as $date => $data): ?>
                  <tr>
                    <td class="text-center"><?php echo htmlspecialchars($date); ?></td>
                    <td class="text-center">
                      <span class="<?php echo $data['points'] >= 0 ? 'text-primary' : 'text-danger'; ?>">
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
            <h5 class="mb-3 mt-4"><i class="fas fa-history me-2"></i>詳細點數紀錄</h5>
            <?php if (count($detailed_points) > 0): ?>
            <div class="table-responsive">
              <table class="table table-hover table-sm">
                <thead>
                  <tr>
                    <th class="text-center">時間</th>
                    <th class="text-center">來源</th>
                    <th>說明</th>
                    <th class="text-center">點數</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($detailed_points as $record): ?>
                  <tr>
                    <td class="text-center text-nowrap">
                      <?php 
                        $dt = new DateTime($record['datetime']);
                        echo $dt->format('m/d H:i');
                      ?>
                    </td>
                    <td class="text-center">
                      <?php if ($record['source'] === '運動'): ?>
                        <span class="badge bg-success"><i class="fas fa-running me-1"></i>運動</span>
                      <?php elseif ($record['source'] === '團隊任務'): ?>
                        <span class="badge bg-info"><i class="fas fa-users me-1"></i>團隊任務</span>
                      <?php elseif ($record['source'] === '解鎖建築'): ?>
                        <span class="badge bg-warning text-dark"><i class="fas fa-unlock me-1"></i>解鎖建築</span>
                      <?php elseif ($record['source'] === '升級建築'): ?>
                        <span class="badge bg-danger"><i class="fas fa-arrow-up me-1"></i>升級建築</span>
                      <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($record['description']); ?></td>
                    <td class="text-center">
                      <span class="<?php echo $record['points'] >= 0 ? 'text-primary' : 'text-danger'; ?> fw-bold">
                        <?php echo $record['points'] >= 0 ? '+' : ''; ?><?php echo number_format($record['points']); ?>
                      </span>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php else: ?>
            <div class="alert alert-secondary text-center">
              <i class="fas fa-info-circle me-2"></i>此期間內沒有點數紀錄
            </div>
            <?php endif; ?>

            <!-- 詳細金錢紀錄 -->
            <h5 class="mb-3 mt-4"><i class="fas fa-coins me-2"></i>詳細金錢紀錄</h5>
            <?php if (count($detailed_money) > 0): ?>
            <div class="table-responsive">
              <table class="table table-hover table-sm">
                <thead>
                  <tr>
                    <th class="text-center">時間</th>
                    <th class="text-center">來源</th>
                    <th>說明</th>
                    <th class="text-center">金額</th>
                  </tr>
                </thead>
                <tbody>
                  <?php foreach ($detailed_money as $record): ?>
                  <tr>
                    <td class="text-center text-nowrap">
                      <?php 
                        $dt = new DateTime($record['created_at']);
                        echo $dt->format('m/d H:i');
                      ?>
                    </td>
                    <td class="text-center">
                      <?php if ($record['source'] === 'building_unlock'): ?>
                        <span class="badge bg-warning text-dark"><i class="fas fa-unlock me-1"></i>解鎖建築</span>
                      <?php elseif ($record['source'] === 'building_upgrade'): ?>
                        <span class="badge bg-danger"><i class="fas fa-arrow-up me-1"></i>升級建築</span>
                      <?php endif; ?>
                    </td>
                    <td><?php echo htmlspecialchars($record['description'] ?? ''); ?></td>
                    <td class="text-center">
                      <span class="text-success fw-bold">
                        +<?php echo number_format($record['amount']); ?>
                      </span>
                    </td>
                  </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
            <?php else: ?>
            <div class="alert alert-secondary text-center">
              <i class="fas fa-info-circle me-2"></i>此期間內沒有金錢紀錄
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
