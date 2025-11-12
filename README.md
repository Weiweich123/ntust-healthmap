# 台科大健康任務地圖 (NTUST Health Task Map)

## 簡介
這是一個使用 PHP + MySQL / TiDB 的健康任務管理網站，功能包含：

- 使用者註冊 / 登入 / 登出（session）。
- 提交每日運動（步數 / 運動時間 / 喝水量），計算點數與金錢並寫入資料庫。
- 地圖 (Leaflet + OpenStreetMap) 顯示台科大校內建築 (來自 `buildings.json`)。
- 點選建築可查看介紹、解鎖所需點數與可獲得金錢，解鎖後能升級 (1~9 級)。
- 團隊模式：建立或加入團隊，共同獲得額外點數。
- 支援本地 MySQL 或 TiDB Cloud 雲端資料庫。

## 快速安裝與執行

### 1. 環境需求
- PHP 7.4+
- MySQL 5.7+ 或 TiDB Cloud
- XAMPP (本地開發) 或任何 PHP 環境

### 2. 安裝步驟

1. **複製專案**
   ```bash
   git clone <your-repo-url>
   cd ntusthealthmap3
   ```

2. **設定資料庫配置**
   ```bash
   cp config.example.php config.php
   ```
   
   編輯 `config.php`，填入你的資料庫資訊：
   - 本地開發：使用 `local` 環境設定
   - 線上部署：使用 `tidb` 環境設定

3. **建立資料庫結構**
   - 本地 MySQL：使用 phpMyAdmin 或 mysql CLI 匯入 `schema.sql`
   - TiDB Cloud：在 Chat2Query 或 SQL Editor 中執行 `schema.sql`

4. **啟動服務**
   - 啟動 Apache 與 MySQL
   - 瀏覽 `http://localhost/ntusthealthmap3/`

### 3. 環境切換

在 `config.php` 中修改 `environment` 參數：
```php
'environment' => 'local',  // 本地開發
// 或
'environment' => 'tidb',   // 使用 TiDB Cloud
```

## 功能特色

✅ 使用 PDO + prepared statements 避免 SQL 注入  
✅ 使用 `password_hash` / `password_verify` 安全儲存密碼  
✅ 支援本地和雲端資料庫切換  
✅ 響應式設計，支援手機瀏覽  

## 安全性說明

- **不要**將 `config.php` 上傳到 GitHub（已加入 .gitignore）
- **不要**將 SSL 憑證檔案（.pem）上傳到公開儲存庫
- 生產環境需額外處理 CSRF、輸入驗證與資安強化

## 檔案說明

- `config.example.php` - 配置檔案範例（安全上傳）
- `config.php` - 實際配置檔案（不上傳，包含敏感資訊）
- `db.php` - 資料庫連線核心
- `schema.sql` - 資料庫結構與初始資料
- `*.php` - 各功能頁面

## 授權

MIT License
