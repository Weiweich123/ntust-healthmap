<?php
require_once __DIR__ . '/db.php';
require_login();

$user_id = $_SESSION['user_id'];
// fetch user info
$stmt = $pdo->prepare('SELECT user_id, username, display_name, points, money FROM users WHERE user_id = ?');
$stmt->execute([$user_id]);
$user = $stmt->fetch();

// If the user row cannot be found (stale session), clear session and force login
if (!$user) {
  session_unset();
  session_destroy();
  header('Location: login.php');
  exit;
}

// safe display values for templates
$display_name = htmlspecialchars($user['display_name'] ?? $user['username'] ?? '訪客', ENT_QUOTES, 'UTF-8');
$points = isset($user['points']) ? (int)$user['points'] : 0;
$money = isset($user['money']) ? (int)$user['money'] : 0;

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
</head>
<body>
  <nav class="navbar navbar-expand-lg">
    <div class="container">
      <a class="navbar-brand" href="#">
        <i class="fas fa-heartbeat me-2"></i>台科大健康任務地圖
      </a>
      <div class="d-flex align-items-center gap-3">
        <div class="d-none d-md-block">
          <i class="fas fa-user-circle me-1 text-muted"></i>
          <strong><?php echo $display_name; ?></strong>
        </div>
        <span class="badge bg-primary">
          <i class="fas fa-star me-1"></i><?php echo $points; ?> 點
        </span>
        <span class="badge bg-success">
          <i class="fas fa-coins me-1"></i><?php echo $money; ?> 元
        </span>
        <a class="btn btn-outline-secondary btn-sm" href="logout.php">
          <i class="fas fa-sign-out-alt"></i>
        </a>
      </div>
    </div>
  </nav>

  <div class="container container-main">
    <div class="row g-4">
      <div class="col-lg-4">
        <div class="card mb-4">
          <div class="card-body">
            <h5 class="card-title">
              <i class="fas fa-running"></i>提交今日運動
            </h5>
            <form id="activityForm" method="post" action="submit_activity.php">
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
              <div class="d-grid">
                <button type="submit" class="btn btn-primary">
                  <i class="fas fa-paper-plane me-2"></i>提交紀錄
                </button>
              </div>
            </form>
          </div>
        </div>

        <div class="card mb-4">
          <div class="card-body">
            <h5 class="card-title">
              <i class="fas fa-users"></i>團隊系統
            </h5>
            <p class="text-muted small mb-3">與朋友一起解任務可獲得額外加成</p>
            <div class="d-grid gap-2">
              <a class="btn btn-outline-info" href="team.php">
                <i class="fas fa-user-friends me-2"></i>我的團隊
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

        <div class="card">
          <div class="card-body">
            <h6 class="card-title mb-3">
              <i class="fas fa-lightbulb"></i>使用提示
            </h6>
            <p class="small text-muted mb-2">
              <i class="fas fa-info-circle me-2"></i>活動提交僅會增加點數，金錢需透過升級建築獲得
            </p>
            <p class="small text-muted mb-0">
              <i class="fas fa-map-marker-alt me-2"></i>地圖上藍色標記為未解鎖，紅色為已解鎖建築
            </p>
          </div>
        </div>
      </div>

      <div class="col-lg-8">
        <div id="map"></div>
      </div>
    </div>
  </div>

  <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    const map = L.map('map').setView([25.0193,121.5392], 17);
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19}).addTo(map);

    const userBuildings = <?php echo json_encode($ub_levels, JSON_HEX_TAG); ?>;
    const blueIcon = L.icon({
      iconUrl: 'icons/blue-pin.svg',
      iconSize: [32,42],
      iconAnchor: [16,42],
      popupAnchor: [0,-40]
    });
    const redIcon = L.icon({
      iconUrl: 'icons/red-pin.svg',
      iconSize: [32,42],
      iconAnchor: [16,42],
      popupAnchor: [0,-40]
    });

    function showNotification(message, type = 'info') {
      const notification = document.createElement('div');
      notification.className = `alert alert-${type} position-fixed top-0 start-50 translate-middle-x mt-3`;
      notification.style.cssText = 'z-index: 9999; min-width: 300px; animation: fadeInUp 0.3s ease-out;';
      notification.innerHTML = `<i class="fas fa-${type === 'success' ? 'check-circle' : 'info-circle'} me-2"></i>${message}`;
      document.body.appendChild(notification);
      setTimeout(() => {
        notification.style.animation = 'fadeOut 0.3s ease-out';
        setTimeout(() => notification.remove(), 300);
      }, 3000);
    }

    function makePopupHtml(b, unlockedLevel){
      let html = `<div class="popup-content">`;
      html += `<h6 class="mb-2"><i class="fas fa-building me-2"></i>${b.name}</h6>`;
      html += `<div class="text-muted small mb-2">${b.description}</div>`;
      if (unlockedLevel > 0) {
        const upgradeCost = (b.unlock_cost || 1) * (unlockedLevel + 1);
        const upgradeReward = Math.floor(upgradeCost / 2);
        html += `<hr class="my-2">`;
        html += `<div class="mb-1"><i class="fas fa-level-up-alt me-2"></i>等級: <strong>${unlockedLevel}</strong></div>`;
        html += `<div class="mb-1"><i class="fas fa-star me-2"></i>升級所需: <strong>${upgradeCost}</strong> 點</div>`;
        html += `<div class="mb-2"><i class="fas fa-coins me-2"></i>升級獲得: <strong>${upgradeReward}</strong> 元</div>`;
        if (unlockedLevel < 9) {
          html += `<div class="popup-actions"><button class="btn btn-sm btn-upgrade" onclick="upgrade(${b.id})"><i class="fas fa-arrow-up me-1"></i>升級</button></div>`;
        } else {
          html += `<div class="mt-2 text-success"><i class="fas fa-trophy me-1"></i>已達最高等級</div>`;
        }
      } else {
        html += `<hr class="my-2">`;
        html += `<div class="mb-2"><i class="fas fa-lock me-2"></i>解鎖成本: <strong>${b.unlock_cost}</strong> 點</div>`;
        html += `<div class="popup-actions"><button class="btn btn-sm btn-unlock" onclick="unlock(${b.id})"><i class="fas fa-unlock me-1"></i>解鎖</button></div>`;
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
      .then(r=>r.json()).then(j=>{
        if (j.success) {
          showNotification(j.message, 'success');
          setTimeout(() => location.reload(), 1500);
        } else {
          showNotification('錯誤: ' + j.message, 'danger');
        }
      }).catch(err => {
        showNotification('網路錯誤，請稍後再試', 'danger');
      });
    }

    function upgrade(bid){
      fetch('upgrade_building.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({building_id:bid})})
      .then(r=>r.json()).then(j=>{
        if (j.success) {
          showNotification(j.message, 'success');
          setTimeout(() => location.reload(), 1500);
        } else {
          showNotification('錯誤: ' + j.message, 'danger');
        }
      }).catch(err => {
        showNotification('網路錯誤，請稍後再試', 'danger');
      });
    }
  </script>

</body>
</html>
