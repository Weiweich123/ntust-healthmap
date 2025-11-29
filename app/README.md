# 台科大健康任務地圖 (NTUST Health Task Map)

簡介
---
這是一個使用 PHP + MySQL (XAMPP) 的簡易網站範例，功能包含：

- 使用者註冊 / 登入 / 登出（session）。
- 提交每日運動（步數 / 運動時間 / 喝水量），計算點數與金錢並寫入資料庫。
- 地圖 (Leaflet + OpenStreetMap) 顯示台科大校內建築 (來自 `buildings.json`)。
- 點選建築可查看介紹、解鎖所需點數與可獲得金錢，解鎖後能升級 (1~9 級)。
- 團隊模式：建立或加入團隊，共同獲得額外點數（簡化版本）。

注意：請使用 XAMPP 的 Apache + MySQL 環境。編輯 `db.php` 中的資料庫連線資訊以配合你的 MySQL 設定。

快速安裝與執行
---
1. 將本專案放到 XAMPP 的 `htdocs` 目錄下，或將 `htdocs` 指向本資料夾。
2. 編輯 `db.php`：設定 `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS`。
3. 使用 phpMyAdmin 或 mysql CLI 匯入 `schema.sql`（此檔同時包含範例建築資料）。
4. 啟動 Apache 與 MySQL，瀏覽 `http://localhost/ntusthealthmap3/login.php`（視放置路徑而定）。

建議測試帳號
---
- 在 `schema.sql` 我放入了部分範例建築資料；請先註冊一個帳號再登入測試提交運動、解鎖建築與建立團隊等功能。

安全與備註
---
- 使用 PDO + prepared statements 避免 SQL 注入。
- 使用 `password_hash` / `password_verify` 儲存密碼。
- 這是一個示範性的小專案，生產環境需額外處理 CSRF、防爬、輸入驗證與資安強化。

如需我把功能延伸為 REST API / React 前端 / 更完整的團隊任務系統，我可以接著實作。
