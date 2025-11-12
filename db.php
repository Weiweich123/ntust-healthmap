<?php
// db.php - PDO connection
session_start();

// 載入配置檔案
$config = require __DIR__ . '/config.php';
$env = $config['environment'];
$dbConfig = $config[$env];

// 設定資料庫常數
define('DB_HOST', $dbConfig['host']);
define('DB_PORT', $dbConfig['port']);
define('DB_NAME', $dbConfig['database']);
define('DB_USER', $dbConfig['username']);
define('DB_PASS', $dbConfig['password']);
define('USE_SSL', $dbConfig['use_ssl']);
define('DB_ENV', $env);

try {
    $dsn = 'mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4';

    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];

    // 如果是 TiDB，加上 SSL 設定
    if (USE_SSL && isset($dbConfig['ssl_ca'])) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $dbConfig['ssl_ca'];
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);

    // 顯示目前連線環境（開發時可以看到）
    if (DB_ENV === 'local') {
        // echo "<!-- 使用本地 MySQL -->";
    } else {
        // echo "<!-- 使用 TiDB Cloud -->";
    }

} catch (Exception $e) {
    die('Database connection failed: ' . htmlspecialchars($e->getMessage()));
}

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function require_login() {
    if (!is_logged_in()) {
        header('Location: login.php');
        exit;
    }
}

