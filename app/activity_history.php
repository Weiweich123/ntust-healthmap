<?php
require_once __DIR__ . '/db.php';
require_login();

$user_id = $_SESSION['user_id'];
$message = '';
$error = '';

// 處理刪除請求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    if ($_POST['action'] === 'delete' && isset($_POST['delete_ids'])) {
        $ids = $_POST['delete_ids'];
        if (is_array($ids) && count($ids) > 0) {
            // 驗證所有 ID 都是數字
            $safe_ids = array_map('intval', $ids);
            $placeholders = implode(',', array_fill(0, count($safe_ids), '?'));

            // 只刪除屬於當前用戶的記錄
            $stmt = $pdo->prepare("DELETE FROM activities WHERE activity_id IN ($placeholders) AND user_id = ?");
            $params = array_merge($safe_ids, [$user_id]);
            $stmt->execute($params);
            $message = '已成功刪除 ' . $stmt->rowCount() . ' 筆記錄';
        }
    } elseif ($_POST['action'] === 'update') {
        $activity_id = (int)($_POST['activity_id'] ?? 0);
        $activity_date = $_POST['activity_date'] ?? '';
        $steps = (int)($_POST['steps'] ?? 0);
        $time_minutes = (int)($_POST['time_minutes'] ?? 0);
        $water_ml = (int)($_POST['water_ml'] ?? 0);

        if ($activity_id > 0 && $activity_date) {
            $stmt = $pdo->prepare('UPDATE activities SET activity_date = ?, steps = ?, time_minutes = ?, water_ml = ? WHERE activity_id = ? AND user_id = ?');
            $stmt->execute([$activity_date, $steps, $time_minutes, $water_ml, $activity_id, $user_id]);
            if ($stmt->rowCount() > 0) {
                // 修改成功後重導向，避免停留在編輯頁面
                $post_filter_year = $_POST['filter_year'] ?? '';
                $post_filter_month = $_POST['filter_month'] ?? '';
                $post_filter_day = $_POST['filter_day'] ?? '';
                $redirect_url = 'activity_history.php?updated=1';
                if ($post_filter_year !== '' || $post_filter_month !== '' || $post_filter_day !== '') {
                    $redirect_url = 'activity_history.php?updated=1&year=' . urlencode($post_filter_year) . '&month=' . urlencode($post_filter_month) . '&day=' . urlencode($post_filter_day);
                }
                header('Location: ' . $redirect_url);
                exit;
            } else {
                $error = '修改失敗，記錄可能不存在';
            }
        }
    }
}

// 查詢參數
$filter_year = $_GET['year'] ?? '';
$filter_month = $_GET['month'] ?? '';
$filter_day = $_GET['day'] ?? '';

// 建立查詢
$sql = 'SELECT activity_id, activity_date, steps, time_minutes, water_ml, created_at FROM activities WHERE user_id = ?';
$params = [$user_id];

if ($filter_year !== '') {
    $sql .= ' AND YEAR(activity_date) = ?';
    $params[] = (int)$filter_year;
}
if ($filter_month !== '') {
    $sql .= ' AND MONTH(activity_date) = ?';
    $params[] = (int)$filter_month;
}
if ($filter_day !== '') {
    $sql .= ' AND DAY(activity_date) = ?';
    $params[] = (int)$filter_day;
}

$sql .= ' ORDER BY activity_date DESC, created_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$activities = $stmt->fetchAll();

// 如果是編輯模式，獲取單筆記錄
$editRecord = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = (int)$_GET['edit'];
    $stmt = $pdo->prepare('SELECT * FROM activities WHERE activity_id = ? AND user_id = ?');
    $stmt->execute([$edit_id, $user_id]);
    $editRecord = $stmt->fetch();
}
?>
<!doctype html>
<html lang="zh-TW">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>運動歷程 - 台科大健康任務地圖</title>
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
      <div class="d-flex align-items-center gap-2">
        <a class="btn btn-outline-secondary btn-sm" href="index.php">
          <i class="fas fa-arrow-left me-1"></i>返回首頁
        </a>
      </div>
    </div>
  </nav>

  <div class="container container-main">
    <div class="row justify-content-center">
      <div class="col-lg-10">
        <div class="card">
          <div class="card-body">
            <h3 class="card-title">
              <i class="fas fa-history"></i>運動歷程查詢
            </h3>

            <?php if ($message): ?>
              <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if (isset($_GET['updated']) && $_GET['updated'] == '1'): ?>
              <div class="alert alert-success"><i class="fas fa-check-circle me-2"></i>記錄已成功修改</div>
            <?php endif; ?>
            <?php if ($error): ?>
              <div class="alert alert-danger"><i class="fas fa-exclamation-circle me-2"></i><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <?php if ($editRecord): ?>
            <!-- 修改模式 -->
            <div class="card mb-4" style="background: linear-gradient(135deg, #FEF3C7 0%, #FDE68A 100%); border-left: 4px solid #F59E0B;">
              <div class="card-body">
                <h5 class="mb-3"><i class="fas fa-edit me-2"></i>修改運動記錄</h5>
                <form method="post">
                  <input type="hidden" name="action" value="update">
                  <input type="hidden" name="activity_id" value="<?php echo $editRecord['activity_id']; ?>">
                  <input type="hidden" name="filter_year" value="<?php echo htmlspecialchars($filter_year); ?>">
                  <input type="hidden" name="filter_month" value="<?php echo htmlspecialchars($filter_month); ?>">
                  <input type="hidden" name="filter_day" value="<?php echo htmlspecialchars($filter_day); ?>">
                  <div class="row g-3">
                    <div class="col-md-3">
                      <label class="form-label">日期</label>
                      <input type="date" name="activity_date" class="form-control" value="<?php echo htmlspecialchars($editRecord['activity_date']); ?>" required>
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">步數</label>
                      <input type="number" name="steps" class="form-control" min="0" value="<?php echo (int)$editRecord['steps']; ?>" required>
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">運動時間 (分鐘)</label>
                      <input type="number" name="time_minutes" class="form-control" min="0" value="<?php echo (int)$editRecord['time_minutes']; ?>" required>
                    </div>
                    <div class="col-md-3">
                      <label class="form-label">喝水量 (毫升)</label>
                      <input type="number" name="water_ml" class="form-control" min="0" value="<?php echo (int)$editRecord['water_ml']; ?>" required>
                    </div>
                  </div>
                  <div class="mt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-warning">
                      <i class="fas fa-check me-1"></i>確認修改
                    </button>
                    <a href="activity_history.php<?php echo $filter_year || $filter_month || $filter_day ? '?year='.$filter_year.'&month='.$filter_month.'&day='.$filter_day : ''; ?>" class="btn btn-secondary">
                      <i class="fas fa-times me-1"></i>取消
                    </a>
                  </div>
                </form>
              </div>
            </div>
            <?php endif; ?>

            <!-- 查詢表單 -->
            <div class="card mb-4" style="background: linear-gradient(135deg, #F3E8FF 0%, #E9D5FF 100%); border-left: 4px solid var(--primary);">
              <div class="card-body">
                <form method="get" class="row g-3 align-items-end" id="searchForm">
                  <div class="col-md-2">
                    <label class="form-label">年</label>
                    <input type="number" name="year" class="form-control" placeholder="例：2025" min="2000" max="2100" value="<?php echo htmlspecialchars($filter_year); ?>">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">月</label>
                    <input type="number" name="month" class="form-control" placeholder="1-12" min="1" max="12" value="<?php echo htmlspecialchars($filter_month); ?>">
                  </div>
                  <div class="col-md-2">
                    <label class="form-label">日</label>
                    <input type="number" name="day" class="form-control" placeholder="1-31" min="1" max="31" value="<?php echo htmlspecialchars($filter_day); ?>">
                  </div>
                  <div class="col-md-6">
                    <label class="form-label">&nbsp;</label>
                    <div class="d-flex gap-2 flex-wrap justify-content-end">
                      <?php if ($filter_year !== '' || $filter_month !== '' || $filter_day !== ''): ?>
                      <a href="activity_history.php" class="btn btn-secondary btn-sm">
                        <i class="fas fa-arrow-left me-1"></i>返回
                      </a>
                      <?php endif; ?>
                      <button type="submit" class="btn btn-primary btn-sm">
                        <i class="fas fa-search me-1"></i>查詢
                      </button>
                      <button type="button" class="btn btn-danger btn-sm" id="btnDeleteMode">
                        <i class="fas fa-trash me-1"></i>刪除紀錄
                      </button>
                      <button type="button" class="btn btn-warning btn-sm" id="btnEditMode">
                        <i class="fas fa-edit me-1"></i>修改紀錄
                      </button>
                    </div>
                  </div>
                </form>
              </div>
            </div>

            <!-- 刪除模式控制列 -->
            <div class="card mb-3 d-none" id="deleteControls" style="background: #FEE2E2; border-left: 4px solid #EF4444;">
              <div class="card-body py-2">
                <form method="post" id="deleteForm">
                  <input type="hidden" name="action" value="delete">
                  <div class="d-flex justify-content-between align-items-center">
                    <span class="text-danger"><i class="fas fa-exclamation-triangle me-2"></i>刪除模式：請勾選要刪除的記錄</span>
                    <div class="d-flex gap-2">
                      <button type="submit" class="btn btn-danger btn-sm">
                        <i class="fas fa-trash me-1"></i>刪除已選
                      </button>
                      <button type="button" class="btn btn-secondary btn-sm" id="btnCancelDelete">
                        <i class="fas fa-times me-1"></i>取消
                      </button>
                    </div>
                  </div>
                </form>
              </div>
            </div>

            <!-- 修改模式控制列 -->
            <div class="card mb-3 d-none" id="editControls" style="background: #FEF3C7; border-left: 4px solid #F59E0B;">
              <div class="card-body py-2">
                <div class="d-flex justify-content-between align-items-center">
                  <span class="text-warning"><i class="fas fa-edit me-2"></i>修改模式：請點選要修改的記錄</span>
                  <button type="button" class="btn btn-secondary btn-sm" id="btnCancelEdit">
                    <i class="fas fa-times me-1"></i>取消
                  </button>
                </div>
              </div>
            </div>

            <!-- 歷程列表 -->
            <?php if (count($activities) === 0): ?>
              <div class="alert alert-info">
                <i class="fas fa-info-circle me-2"></i>目前沒有符合條件的運動記錄
              </div>
            <?php else: ?>
              <div class="table-responsive">
                <table class="table table-hover align-middle" id="activityTable">
                  <thead class="table-light">
                    <tr>
                      <th class="d-none delete-checkbox-col" style="width: 50px;">
                        <input type="checkbox" class="form-check-input" id="selectAll">
                      </th>
                      <th><i class="fas fa-calendar me-1"></i>日期</th>
                      <th><i class="fas fa-shoe-prints me-1"></i>步數</th>
                      <th><i class="fas fa-clock me-1"></i>運動時間</th>
                      <th><i class="fas fa-tint me-1"></i>喝水量</th>
                      <th class="d-none edit-action-col">操作</th>
                    </tr>
                  </thead>
                  <tbody>
                    <?php foreach ($activities as $act): ?>
                      <tr data-id="<?php echo $act['activity_id']; ?>">
                        <td class="d-none delete-checkbox-col">
                          <input type="checkbox" class="form-check-input delete-check" name="delete_ids[]" value="<?php echo $act['activity_id']; ?>" form="deleteForm">
                        </td>
                        <td><?php echo htmlspecialchars($act['activity_date']); ?></td>
                        <td><span class="badge bg-primary"><?php echo number_format($act['steps']); ?></span></td>
                        <td><span class="badge bg-success"><?php echo (int)$act['time_minutes']; ?> 分鐘</span></td>
                        <td><span class="badge bg-info"><?php echo number_format($act['water_ml']); ?> ml</span></td>
                        <td class="d-none edit-action-col">
                          <a href="activity_history.php?edit=<?php echo $act['activity_id']; ?>&year=<?php echo urlencode($filter_year); ?>&month=<?php echo urlencode($filter_month); ?>&day=<?php echo urlencode($filter_day); ?>" class="btn btn-outline-warning btn-sm edit-btn">
                            <i class="fas fa-pen"></i>
                          </a>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
              <div class="text-muted small mt-2">
                <i class="fas fa-info-circle me-1"></i>共 <?php echo count($activities); ?> 筆記錄
              </div>
            <?php endif; ?>

          </div>
        </div>
      </div>
    </div>
  </div>

  <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
  <script>
    // 模式切換
    const deleteControls = document.getElementById('deleteControls');
    const editControls = document.getElementById('editControls');
    const deleteCheckboxCols = document.querySelectorAll('.delete-checkbox-col');
    const editActionCols = document.querySelectorAll('.edit-action-col');
    const btnDeleteMode = document.getElementById('btnDeleteMode');
    const btnEditMode = document.getElementById('btnEditMode');
    const btnCancelDelete = document.getElementById('btnCancelDelete');
    const btnCancelEdit = document.getElementById('btnCancelEdit');
    const selectAll = document.getElementById('selectAll');

    function resetModes() {
      deleteControls.classList.add('d-none');
      editControls.classList.add('d-none');
      deleteCheckboxCols.forEach(col => col.classList.add('d-none'));
      editActionCols.forEach(col => col.classList.add('d-none'));
      btnDeleteMode.disabled = false;
      btnEditMode.disabled = false;
    }

    btnDeleteMode?.addEventListener('click', function() {
      resetModes();
      deleteControls.classList.remove('d-none');
      deleteCheckboxCols.forEach(col => col.classList.remove('d-none'));
      btnDeleteMode.disabled = true;
      btnEditMode.disabled = true;
    });

    btnEditMode?.addEventListener('click', function() {
      resetModes();
      editControls.classList.remove('d-none');
      editActionCols.forEach(col => col.classList.remove('d-none'));
      btnDeleteMode.disabled = true;
      btnEditMode.disabled = true;
    });

    btnCancelDelete?.addEventListener('click', resetModes);
    btnCancelEdit?.addEventListener('click', resetModes);

    // 全選功能
    selectAll?.addEventListener('change', function() {
      const checkboxes = document.querySelectorAll('.delete-check');
      checkboxes.forEach(cb => cb.checked = this.checked);
    });

    // 刪除確認
    document.getElementById('deleteForm')?.addEventListener('submit', function(e) {
      const checked = document.querySelectorAll('.delete-check:checked');
      if (checked.length === 0) {
        e.preventDefault();
        alert('請先勾選要刪除的記錄');
        return;
      }
      if (!confirm('確定要刪除選中的 ' + checked.length + ' 筆記錄嗎？此操作無法復原！')) {
        e.preventDefault();
      }
    });
  </script>
</body>
</html>
