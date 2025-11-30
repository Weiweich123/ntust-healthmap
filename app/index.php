<?php
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/age_standards.php';
require_login();

$user_id = $_SESSION['user_id'];
// fetch user info
$stmt = $pdo->prepare('SELECT user_id, username, display_name, points, money, birth_date FROM users WHERE user_id = ?');
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// 計算使用者年齡並取得運動建議
$user_age = calculate_age($user['birth_date'] ?? null);
$exercise_rec = get_exercise_recommendations($user_age);

// get user's unlocked buildings and levels
$stmt = $pdo->prepare('SELECT ub.building_id, ub.level, b.name FROM user_buildings ub JOIN buildings b ON ub.building_id=b.building_id WHERE ub.user_id = ?');
$stmt->execute([$user_id]);
$user_buildings = [];
foreach ($stmt->fetchAll() as $r) {
  $user_buildings[$r['building_id']] = $r;
}

// prepare a simple mapping building_id -> level for frontend
$ub_levels = [];
foreach ($user_buildings as $bid => $info) {
  $ub_levels[$bid] = (int)$info['level'];
}

// 查詢待處理的好友請求數量
$stmt = $pdo->prepare('SELECT COUNT(*) FROM friendships WHERE friend_id = ? AND status = "pending"');
$stmt->execute([$user_id]);
$pending_friend_requests = (int)$stmt->fetchColumn();

// 查詢待處理的團隊邀請數量
$stmt = $pdo->prepare('SELECT COUNT(*) FROM team_invites WHERE invitee_id = ? AND status = "pending"');
$stmt->execute([$user_id]);
$pending_team_invites = (int)$stmt->fetchColumn();

?>
<!doctype html>
<html lang="zh-TW">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <title>台科大健康任務地圖</title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
  <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <link rel="stylesheet" href="assets/styles.css">
  <style>
    /* 強制下拉選單顯示在地圖之上 */
    .user-dropdown-menu {
      z-index: 99999 !important;
      position: absolute !important;
      background-color: white !important;
      opacity: 1 !important;
      box-shadow: 0 8px 24px rgba(0,0,0,0.25) !important;
      border: 1px solid #ddd !important;
    }
    .user-dropdown-menu * {
      background-color: white !important;
    }
    .user-dropdown-menu .dropdown-item:hover {
      background-color: #f0f0f0 !important;
    }
    .user-dropdown-menu .dropdown-divider {
      background-color: #dee2e6 !important;
    }
    /* 限制地圖區域的堆疊層級 */
    .col-lg-8 {
      position: relative;
      z-index: 1;
    }
    .navbar {
      position: relative;
      z-index: 9999;
    }
  </style>
</head>
<body>
  <nav class="navbar navbar-expand-lg">
    <div class="container">
      <a class="navbar-brand" href="#">
        <i class="fas fa-heartbeat me-2"></i>台科大健康任務地圖
      </a>
      <div class="d-flex align-items-center gap-3">
        <a class="btn btn-outline-warning btn-sm" href="points_history.php" title="查詢點數金錢紀錄">
          <i class="fas fa-chart-line me-1"></i>點數及金錢紀錄查詢
        </a>
        <span class="badge bg-primary">
          <i class="fas fa-star me-1"></i><?php echo (int)$user['points']; ?> 點
        </span>
        <span class="badge bg-success">
          <i class="fas fa-coins me-1"></i><?php echo (int)$user['money']; ?> 元
        </span>

        <!-- 使用者頭像下拉選單 -->
        <div class="dropdown">
          <button class="btn btn-outline-dark btn-sm dropdown-toggle position-relative" type="button" id="userDropdown" data-bs-toggle="dropdown" data-bs-display="static" aria-expanded="false">
            <i class="fas fa-user-circle me-1"></i>
            <?php echo htmlspecialchars($user['display_name'] ?? $user['username']); ?>
            <?php if ($pending_friend_requests > 0): ?>
            <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.65rem;">
              <?php echo $pending_friend_requests > 99 ? '99+' : $pending_friend_requests; ?>
            </span>
            <?php endif; ?>
          </button>
          <ul class="dropdown-menu dropdown-menu-end user-dropdown-menu" aria-labelledby="userDropdown">
            <li>
              <a class="dropdown-item py-2" href="friends.php">
                <i class="fas fa-heart me-2 text-info"></i>我的好友
                <?php if ($pending_friend_requests > 0): ?>
                <span class="badge bg-danger ms-2"><?php echo $pending_friend_requests > 99 ? '99+' : $pending_friend_requests; ?></span>
                <?php endif; ?>
              </a>
            </li>
            <li>
              <a class="dropdown-item py-2 d-lg-none" href="points_history.php">
                <i class="fas fa-chart-line me-2 text-warning"></i>點數及金錢紀錄
              </a>
            </li>
            <li><hr class="dropdown-divider"></li>
            <li>
              <a class="dropdown-item py-2 text-danger" href="logout.php">
                <i class="fas fa-sign-out-alt me-2"></i>登出
              </a>
            </li>
          </ul>
        </div>
      </div>
    </div>
  </nav>

  <div class="container container-main">
    <div class="row g-4">
      <div class="col-lg-4">
        <div class="card mb-4">
          <div class="card-body">
            <h5 class="card-title">
              <i class="fas fa-running"></i>提交運動數據
            </h5>
            <form id="activityForm" method="post" action="submit_activity.php">
              <div class="mb-3">
                <label class="form-label">日期</label>
                <input name="activity_date" type="date" class="form-control" value="<?php echo date('Y-m-d'); ?>" required>
              </div>
              <div class="mb-3">
                <label class="form-label">步數</label>
                <input name="steps" type="number" min="0" class="form-control" placeholder="今日步數" required>
              </div>
              <div class="mb-3">
                <label class="form-label">運動時間 (分鐘)</label>
                <input name="time_minutes" type="number" min="0" class="form-control" placeholder="運動分鐘數" required>
              </div>
              <div class="mb-4">
                <label class="form-label">喝水 (毫升)</label>
                <input name="water_ml" type="number" min="0" class="form-control" placeholder="飲水量" required>
              </div>
              <div class="alert alert-info py-2 mb-3" style="font-size: 0.85rem;">
                <i class="fas fa-info-circle me-1"></i>
                <strong><?php echo htmlspecialchars($exercise_rec['age_group']); ?>建議：</strong><br>
                步數 <?php echo number_format($exercise_rec['daily_steps']); ?> 步 / 運動 <?php echo $exercise_rec['daily_exercise']; ?> 分鐘 / 飲水 <?php echo number_format($exercise_rec['daily_water']); ?> ml
                <?php if ($user_age !== null): ?>
                <span class="badge bg-secondary ms-2"><?php echo $user_age; ?> 歲</span>
                <?php endif; ?>
              </div>
              <div class="d-grid gap-2">
                <button type="submit" class="btn btn-primary">
                  <i class="fas fa-paper-plane me-2"></i>提交紀錄
                </button>
                <a href="activity_history.php" class="btn btn-outline-secondary">
                  <i class="fas fa-history me-2"></i>查詢運動歷程
                </a>
              </div>
            </form>
          </div>
        </div>

        <div class="card">
          <div class="card-body">
            <h5 class="card-title">
              <i class="fas fa-users"></i>團隊系統
            </h5>
            <p class="text-muted small mb-3">與朋友一起解任務可獲得額外加成</p>
            <div class="d-grid gap-2">
              <a class="btn btn-outline-info position-relative" href="team.php">
                <i class="fas fa-user-friends me-2"></i>我的團隊
                <?php if ($pending_team_invites > 0): ?>
                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" style="font-size: 0.65rem;">
                  <?php echo $pending_team_invites > 99 ? '99+' : $pending_team_invites; ?>
                </span>
                <?php endif; ?>
              </a>
              <a class="btn btn-outline-primary" href="create_team.php">
                <i class="fas fa-plus-circle me-2"></i>建立團隊
              </a>
              <a class="btn btn-outline-success" href="join_team.php">
                <i class="fas fa-sign-in-alt me-2"></i>加入團隊
              </a>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-8 d-flex flex-column">
        <div style="position: relative; z-index: 1;">
          <div id="map" class="mb-4"></div>
        </div>
        <div class="card flex-grow-1">
          <div class="card-body d-flex flex-column justify-content-center align-items-center text-center">
            <h2 class="mb-4">
              <i class="fas fa-lightbulb me-2" style="color: var(--primary);"></i>使用提示
            </h2>
            <p class="text-muted mb-2 fs-5">
              <i class="fas fa-info-circle me-2"></i>活動提交僅會增加點數，金錢需透過升級建築獲得
            </p>
            <p class="text-muted mb-0 fs-5">
              <i class="fas fa-map-marker-alt me-2"></i>地圖上藍色標記為未解鎖，紅色為已解鎖建築
            </p>
          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // 國立台灣科技大學校園邊界
    const ntustBounds = L.latLngBounds(
      [25.0085, 121.5355],  // 西南角 (左下)
      [25.0175, 121.5475]   // 東北角 (右上)
    );
    
    // 地圖初始化，鎖定在台科大校園範圍
    const map = L.map('map', {
      center: [25.0130, 121.5415],
      zoom: 17,
      minZoom: 16,
      maxZoom: 19,
      maxBounds: ntustBounds.pad(0.1),  // 加一點緩衝區
      maxBoundsViscosity: 1.0  // 完全限制在邊界內
    });
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19}).addTo(map);

    const userBuildings = <?php echo json_encode($ub_levels, JSON_HEX_TAG); ?>;
    const blueIcon = L.icon({
      iconUrl: 'icons/blue-pin.svg',
      iconSize: [28,38],
      iconAnchor: [14,38],
      popupAnchor: [0,-35]
    });
    const redIcon = L.icon({
      iconUrl: 'icons/red-pin.svg',
      iconSize: [28,38],
      iconAnchor: [14,38],
      popupAnchor: [0,-35]
    });

    function makePopupHtml(b, unlockedLevel){
      let html = `<div class="popup-content">`;
      html += `<h6 class="mb-1">${b.name}</h6>`;
      html += `<div class="text-muted small">${b.description}</div>`;
      if (unlockedLevel > 0) {
        const upgradeCost = (b.unlock_cost || 1) * (unlockedLevel + 1);
        const upgradeReward = Math.floor(upgradeCost / 2);
        html += `<hr>`;
        html += `<div>等級: <strong>${unlockedLevel}</strong></div>`;
        html += `<div>升級所需點數: <strong>${upgradeCost}</strong></div>`;
        html += `<div>升級可得金錢: <strong>${upgradeReward}</strong></div>`;
        if (unlockedLevel < 9) {
          html += `<div class="popup-actions"><button class="btn btn-sm btn-upgrade" onclick="upgrade(${b.id})">升級</button></div>`;
        } else {
          html += `<div class="mt-2 text-success">已達最高等級</div>`;
        }
      } else {
        html += `<hr>`;
        html += `<div>解鎖成本: <strong>${b.unlock_cost}</strong> 點</div>`;
        html += `<div class="popup-actions"><button class="btn btn-sm btn-unlock" onclick="unlock(${b.id})">解鎖</button></div>`;
      }
      html += `</div>`;
      return html;
    }

  fetch('buildings.json').then(r=>r.json()).then(buildings=>{
      buildings.forEach(b=>{
        const unlockedLevel = userBuildings[b.id] || 0;
        const icon = unlockedLevel > 0 ? redIcon : blueIcon;
        const marker = L.marker([b.lat,b.lng], {icon: icon}).addTo(map);
        marker.bindPopup(makePopupHtml(b, unlockedLevel));
      });
    });

    function unlock(bid){
      fetch('unlock_building.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({building_id:bid})})
      .then(r=>r.json()).then(j=>{ if (j.success) {
          // nicer notification using bootstrap modal/alert might be added later
          alert(j.message);
          location.reload();
        } else {
          alert('錯誤: ' + j.message);
        }
      });
    }

    function upgrade(bid){
      fetch('upgrade_building.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({building_id:bid})})
      .then(r=>r.json()).then(j=>{ if (j.success) {
          alert(j.message);
          location.reload();
        } else {
          alert('錯誤: ' + j.message);
        }
      });
    }
  </script>

</body>
</html>
