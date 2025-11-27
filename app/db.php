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
        'host' => getenv('DB_HOST') ?: getenv('TIDB_HOST') ?: 'gateway01.ap-northeast-1.prod.aws.tidbcloud.com',
        'port' => getenv('DB_PORT') ?: getenv('TIDB_PORT') ?: '4000',
        'database' => getenv('DB_NAME') ?: getenv('TIDB_DATABASE') ?: 'ntust_healthmap',
        'username' => getenv('DB_USER') ?: getenv('TIDB_USER') ?: '',
        'password' => getenv('DB_PASS') ?: getenv('TIDB_PASS') ?: '',
        'use_ssl' => (getenv('DB_USE_SSL') === 'true' || getenv('TIDB_USE_SSL') === 'true') ?: true,
        'ssl_ca' => getenv('DB_SSL_CA') ?: getenv('TIDB_SSL_CA') ?: __DIR__ . '/isrgrootx1.pem',
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
    // 檢測是否在 Wasmer 環境 (PDO SSL 不支援時使用 MySQLi)
    $useMySQLi = USE_SSL && (getenv('WASMER_ENV') !== false || !getenv('XAMPP_ROOT'));

    if ($useMySQLi && extension_loaded('mysqli')) {
        // === 使用 MySQLi (適用於 Wasmer 等環境) ===
        $mysqli = mysqli_init();

        if (USE_SSL) {
            // 設定 SSL - Wasmer 環境下使用寬鬆的 SSL 設定
            $caFile = isset($dbConfig['ssl_ca']) ? $dbConfig['ssl_ca'] : null;
            
            // 嘗試設定 SSL,但不強制要求 CA 檔案
            if ($caFile && file_exists($caFile)) {
                $mysqli->ssl_set(NULL, NULL, $caFile, NULL, NULL);
            } else {
                // 在沒有 CA 檔案的情況下,仍然啟用 SSL 但不驗證
                $mysqli->ssl_set(NULL, NULL, NULL, NULL, NULL);
            }
            
            // 關閉伺服器憑證驗證 (Wasmer 環境需要)
            $mysqli->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
        }

        // 嘗試連接,記錄詳細錯誤資訊
        $connected = $mysqli->real_connect(
            DB_HOST,
            DB_USER,
            DB_PASS,
            DB_NAME,
            (int)DB_PORT,
            NULL,
            USE_SSL ? MYSQLI_CLIENT_SSL : 0
        );

        if (!$connected) {
            $error = $mysqli->connect_errno . ': ' . $mysqli->connect_error;
            error_log('MySQLi connection failed: ' . $error);
            throw new Exception('Database connection failed (MySQLi). Error: ' . $error);
        }

        $mysqli->set_charset('utf8mb4');

        // 建立 PDO 相容的包裝器
        $pdo = new class($mysqli) {
            private $mysqli;
            private $inTransaction = false;

            public function __construct($mysqli) {
                $this->mysqli = $mysqli;
            }

            public function prepare($sql) {
                $stmt = $this->mysqli->prepare($sql);
                if (!$stmt) {
                    throw new PDOException('Prepare failed: ' . $this->mysqli->error);
                }
                return new class($stmt) {
                    private $stmt;
                    private $params = [];

                    public function __construct($stmt) {
                        $this->stmt = $stmt;
                    }

                    public function execute($params = []) {
                        if (!empty($params)) {
                            $types = str_repeat('s', count($params));
                            $this->stmt->bind_param($types, ...$params);
                        }
                        $result = $this->stmt->execute();
                        if (!$result) {
                            throw new PDOException('Execute failed: ' . $this->stmt->error);
                        }
                        return $result;
                    }

                    public function fetch($mode = PDO::FETCH_ASSOC) {
                        $result = $this->stmt->get_result();
                        return $result ? $result->fetch_assoc() : false;
                    }

                    public function fetchAll($mode = PDO::FETCH_ASSOC) {
                        $result = $this->stmt->get_result();
                        return $result ? $result->fetch_all(MYSQLI_ASSOC) : [];
                    }

                    public function rowCount() {
                        return $this->stmt->affected_rows;
                    }

                    public function fetchColumn($column = 0) {
                        $result = $this->stmt->get_result();
                        if (!$result) return false;
                        $row = $result->fetch_row();
                        return $row ? $row[$column] : false;
                    }
                };
            }

            public function exec($sql) {
                $result = $this->mysqli->query($sql);
                if ($result === false) {
                    throw new PDOException('Query failed: ' . $this->mysqli->error);
                }
                return $this->mysqli->affected_rows;
            }

            public function query($sql) {
                $result = $this->mysqli->query($sql);
                if ($result === false) {
                    throw new PDOException('Query failed: ' . $this->mysqli->error);
                }
                return new class($result) {
                    private $result;
                    public function __construct($result) {
                        $this->result = $result;
                    }
                    public function fetch($mode = PDO::FETCH_ASSOC) {
                        return $this->result->fetch_assoc();
                    }
                    public function fetchAll($mode = PDO::FETCH_ASSOC) {
                        return $this->result->fetch_all(MYSQLI_ASSOC);
                    }
                    public function fetchColumn($column = 0) {
                        $row = $this->result->fetch_row();
                        return $row ? $row[$column] : false;
                    }
                };
            }

            public function lastInsertId() {
                return $this->mysqli->insert_id;
            }

            public function beginTransaction() {
                $this->inTransaction = true;
                return $this->mysqli->begin_transaction();
            }

            public function commit() {
                $this->inTransaction = false;
                return $this->mysqli->commit();
            }

            public function rollBack() {
                $this->inTransaction = false;
                return $this->mysqli->rollback();
            }

            public function inTransaction() {
                return $this->inTransaction;
            }
        };

    } else {
        // === 使用 PDO (本地 XAMPP 環境) ===
        $dsn = 'mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4';

        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];

        // TiDB Cloud SSL 設定
        if (USE_SSL) {
            $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = false;

            if (isset($dbConfig['ssl_ca']) && file_exists($dbConfig['ssl_ca'])) {
                $options[PDO::MYSQL_ATTR_SSL_CA] = $dbConfig['ssl_ca'];
            } else {
                $options[PDO::MYSQL_ATTR_SSL_CA] = true;
            }
        }

        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }

    // 顯示目前連線環境（開發時可以看到）
    if (DB_ENV === 'local') {
        // echo "<!-- 使用本地 MySQL -->";
    } else {
        // echo "<!-- 使用 TiDB Cloud (MySQLi: " . ($useMySQLi ? 'Yes' : 'No') . ") -->";
    }

} catch (Exception $e) {
    // 記錄錯誤到錯誤日誌
    error_log('DB Connection Error: ' . $e->getMessage());
    
    // 在開發環境顯示詳細錯誤,生產環境顯示簡單訊息
    $debugMode = getenv('DEBUG') === 'true' || getenv('APP_ENV') === 'development';
    
    if ($debugMode) {
        // 開發模式:顯示詳細錯誤資訊
        http_response_code(500);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Database Error</title></head><body>';
        echo '<h1>資料庫連線錯誤</h1>';
        echo '<pre>錯誤訊息: ' . htmlspecialchars($e->getMessage()) . '</pre>';
        echo '<h2>連線資訊:</h2><pre>';
        echo 'Host: ' . htmlspecialchars(DB_HOST) . "\n";
        echo 'Port: ' . htmlspecialchars(DB_PORT) . "\n";
        echo 'Database: ' . htmlspecialchars(DB_NAME) . "\n";
        echo 'User: ' . htmlspecialchars(DB_USER) . "\n";
        echo 'SSL: ' . (USE_SSL ? 'enabled' : 'disabled') . "\n";
        echo 'Environment: ' . htmlspecialchars(DB_ENV) . "\n";
        echo '</pre></body></html>';
    } else {
        // 生產模式:顯示簡單錯誤訊息
        http_response_code(500);
        echo '<!DOCTYPE html><html><head><meta charset="utf-8"><title>Service Unavailable</title></head><body>';
        echo '<h1>服務暫時無法使用</h1>';
        echo '<p>資料庫連線失敗,請稍後再試。</p>';
        echo '</body></html>';
    }
    exit(1);
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

