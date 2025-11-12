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
  <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
  <link rel="stylesheet" href="assets/styles.css">
</head>
<body>
  <nav class="navbar navbar-expand-lg navbar-light bg-white shadow-sm">
    <div class="container">
      <a class="navbar-brand" href="#">台科大健康任務地圖</a>
        <div class="d-flex align-items-center">
        <div class="me-3">歡迎，<strong><?php echo $display_name; ?></strong></div>
        <div class="me-3">點數: <span class="badge bg-primary"><?php echo $points; ?></span></div>
        <div class="me-3">金錢: <span class="badge bg-success"><?php echo $money; ?></span></div>
        <a class="btn btn-outline-secondary btn-sm" href="logout.php">登出</a>
      </div>
    </div>
  </nav>

  <div class="container container-main">
    <div class="row g-3">
      <div class="col-md-4">
        <div class="card mb-3">
          <div class="card-body">
            <h5 class="card-title">提交今日運動</h5>
            <form id="activityForm" method="post" action="submit_activity.php">
              <div class="mb-2">
                <label class="form-label">步數</label>
                <input name="steps" type="number" min="0" class="form-control" required>
              </div>
              <div class="mb-2">
                <label class="form-label">運動時間 (分鐘)</label>
                <input name="time_minutes" type="number" min="0" class="form-control" required>
              </div>
              <div class="mb-3">
                <label class="form-label">喝水 (毫升)</label>
                <input name="water_ml" type="number" min="0" class="form-control" required>
              </div>
              <div class="d-grid">
                <button type="submit" class="btn btn-primary">提交</button>
              </div>
            </form>
          </div>
        </div>

        <div class="card mb-3">
          <div class="card-body">
            <h5 class="card-title">團隊</h5>
            <p>與朋友一起解任務可獲得額外加成。</p>
            <div class="d-grid gap-2">
              <a class="btn btn-outline-primary" href="create_team.php">建立團隊</a>
              <a class="btn btn-outline-success" href="join_team.php">加入團隊</a>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-body small text-muted">
            <p class="mb-1"><strong>提示：</strong>活動提交僅會增加點數，金錢不會由活動直接給予。</p>
            <p class="mb-0">在地圖上點選建物可解鎖或升級，解鎖為藍色圖示，解鎖後為紅色。</p>
          </div>
        </div>
      </div>

      <div class="col-md-8">
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
