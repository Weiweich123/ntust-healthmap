<?php
// db.php - PDO connection with environment variable support (for Wasmer/Docker)
session_start();

// 載入配置檔案（如果存在）
$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) {
    $config = require $configFile;
    $env = $config['environment'] ?? 'local';
    $dbConfig = $config[$env] ?? [];
} else {
    // 使用環境變數作為後備方案（適用於 Wasmer、Docker 等容器環境）
    $env = getenv('DB_ENV') ?: 'local';
    $dbConfig = [
        'host' => getenv('DB_HOST') ?: getenv('TIDB_HOST') ?: '127.0.0.1',
        'port' => getenv('DB_PORT') ?: getenv('TIDB_PORT') ?: '3306',
        'database' => getenv('DB_NAME') ?: getenv('TIDB_DATABASE') ?: 'ntust_healthmap',
        'username' => getenv('DB_USER') ?: getenv('TIDB_USER') ?: 'root',
        'password' => getenv('DB_PASS') ?: getenv('TIDB_PASS') ?: getenv('TIDB_PASSWORD') ?: '',
        'use_ssl' => (getenv('DB_USE_SSL') === 'true' || getenv('TIDB_USE_SSL') === 'true'),
        'ssl_ca' => getenv('DB_SSL_CA') ?: getenv('TIDB_SSL_CA') ?: __DIR__ . '/isrgrootx1.pem',
    ];
}

// 設定資料庫常數
define('DB_HOST', $dbConfig['host'] ?? '127.0.0.1');
define('DB_PORT', $dbConfig['port'] ?? '3306');
define('DB_NAME', $dbConfig['database'] ?? 'ntust_healthmap');
define('DB_USER', $dbConfig['username'] ?? 'root');
define('DB_PASS', $dbConfig['password'] ?? '');
define('USE_SSL', $dbConfig['use_ssl'] ?? false);
define('DB_ENV', $env);

try {
    $dsn = 'mysql:host=' . DB_HOST . ';port=' . DB_PORT . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ];

    // 如果需要 SSL 連線 (TiDB Cloud)
    if (USE_SSL && isset($dbConfig['ssl_ca']) && file_exists($dbConfig['ssl_ca'])) {
        $options[PDO::MYSQL_ATTR_SSL_CA] = $dbConfig['ssl_ca'];
        $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;
    }

    $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
} catch (Exception $e) {
    // In production, don't echo details. For development, helpful to show.
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
