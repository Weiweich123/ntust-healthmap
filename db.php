<?php
// db.php - PDO connection
session_start();

// 載入配置檔案（如果存在）
$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) {
    $config = require $configFile;
    $env = $config['environment'];
    $dbConfig = $config[$env];
} else {
    // 使用環境變數作為後備方案（適用於 Wasmer、Docker 等容器環境）
    $env = getenv('DB_ENV') ?: 'tidb';
    $dbConfig = [
        'host' => getenv('DB_HOST') ?: 'gateway01.ap-northeast-1.prod.aws.tidbcloud.com',
        'port' => getenv('DB_PORT') ?: '4000',
        'database' => getenv('DB_NAME') ?: 'ntust_healthmap',
        'username' => getenv('DB_USER') ?: '',
        'password' => getenv('DB_PASS') ?: '',
        'use_ssl' => (getenv('DB_USE_SSL') === 'true') ?: true,
        'ssl_ca' => getenv('DB_SSL_CA') ?: __DIR__ . '/isrgrootx1.pem',
    ];
}

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

    // 如果是 TiDB，嘗試加上 SSL 設定
    if (USE_SSL) {
        // 檢查 SSL CA 檔案是否存在
        if (isset($dbConfig['ssl_ca']) && file_exists($dbConfig['ssl_ca'])) {
            $options[PDO::MYSQL_ATTR_SSL_CA] = $dbConfig['ssl_ca'];
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        } else {
            // SSL CA 檔案不存在，嘗試不驗證憑證
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
        }
    }

    try {
        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    } catch (PDOException $sslError) {
        // 如果 SSL 連線失敗，嘗試不使用 SSL 驗證
        if (USE_SSL && strpos($sslError->getMessage(), 'SSL') !== false) {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
            unset($options[PDO::MYSQL_ATTR_SSL_CA]);
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } else {
            throw $sslError;
        }
    }

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

