<?php
// ssl_test.php - 診斷 SSL 連線問題
echo "<h2>SSL 連線診斷工具</h2>";

echo "<h3>PHP 資訊</h3>";
echo "PHP 版本: " . PHP_VERSION . "<br>";
echo "PDO 驅動: " . implode(', ', PDO::getAvailableDrivers()) . "<br>";

echo "<h3>OpenSSL 資訊</h3>";
if (extension_loaded('openssl')) {
    echo "✅ OpenSSL 擴展已載入<br>";
    echo "OpenSSL 版本: " . OPENSSL_VERSION_TEXT . "<br>";
} else {
    echo "❌ OpenSSL 擴展未載入<br>";
}

echo "<h3>PDO MySQL SSL 常數</h3>";
$constants = [
    'PDO::MYSQL_ATTR_SSL_KEY',
    'PDO::MYSQL_ATTR_SSL_CERT',
    'PDO::MYSQL_ATTR_SSL_CA',
    'PDO::MYSQL_ATTR_SSL_CAPATH',
    'PDO::MYSQL_ATTR_SSL_CIPHER',
    'PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT'
];

foreach ($constants as $const) {
    if (defined($const)) {
        echo "✅ $const = " . constant($const) . "<br>";
    } else {
        echo "❌ $const 未定義<br>";
    }
}

echo "<h3>測試 TiDB 連線</h3>";

// 載入配置
$configFile = __DIR__ . '/config.php';
if (file_exists($configFile)) {
    $config = require $configFile;
    $dbConfig = $config['tidb'];
} else {
    echo "❌ config.php 不存在<br>";
    exit;
}

echo "主機: " . $dbConfig['host'] . "<br>";
echo "埠號: " . $dbConfig['port'] . "<br>";
echo "資料庫: " . $dbConfig['database'] . "<br>";

// 測試不同的 SSL 設定
$tests = [
    '不使用 SSL' => [],
    'SSL CA = true' => [
        PDO::MYSQL_ATTR_SSL_CA => true,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
    ],
    'SSL CA = 檔案路徑' => [
        PDO::MYSQL_ATTR_SSL_CA => __DIR__ . '/isrgrootx1.pem',
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
    ],
    'SSL 完整設定' => [
        PDO::MYSQL_ATTR_SSL_KEY => NULL,
        PDO::MYSQL_ATTR_SSL_CERT => NULL,
        PDO::MYSQL_ATTR_SSL_CA => __DIR__ . '/isrgrootx1.pem',
        PDO::MYSQL_ATTR_SSL_CAPATH => NULL,
        PDO::MYSQL_ATTR_SSL_CIPHER => NULL,
        PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT => false,
    ],
];

echo "<hr>";

foreach ($tests as $testName => $sslOptions) {
    echo "<h4>測試: $testName</h4>";

    try {
        $dsn = 'mysql:host='.$dbConfig['host'].';port='.$dbConfig['port'].';dbname='.$dbConfig['database'].';charset=utf8mb4';

        $options = array_merge([
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ], $sslOptions);

        $pdo = new PDO($dsn, $dbConfig['username'], $dbConfig['password'], $options);
        echo "✅ <strong style='color: green;'>連線成功！</strong><br>";

        // 測試查詢
        $stmt = $pdo->query("SELECT VERSION() as version");
        $result = $stmt->fetch();
        echo "TiDB 版本: " . $result['version'] . "<br>";

    } catch (PDOException $e) {
        echo "❌ <strong style='color: red;'>連線失敗</strong><br>";
        echo "錯誤: " . htmlspecialchars($e->getMessage()) . "<br>";
    }

    echo "<hr>";
}

// ========== 測試 MySQLi 連線 ==========
echo "<h3>測試 MySQLi 連線（通常 SSL 支援更好）</h3>";

if (extension_loaded('mysqli')) {
    echo "✅ MySQLi 擴展已載入<br><hr>";

    $mysqliTests = [
        'MySQLi 不使用 SSL' => false,
        'MySQLi 使用 SSL (不驗證)' => true,
    ];

    foreach ($mysqliTests as $testName => $useSSL) {
        echo "<h4>測試: $testName</h4>";

        try {
            $mysqli = mysqli_init();

            if ($useSSL) {
                // 設定 SSL
                $caFile = __DIR__ . '/isrgrootx1.pem';
                if (file_exists($caFile)) {
                    echo "使用 CA 檔案: $caFile<br>";
                    $mysqli->ssl_set(NULL, NULL, $caFile, NULL, NULL);
                } else {
                    echo "CA 檔案不存在，使用預設 SSL<br>";
                    $mysqli->ssl_set(NULL, NULL, NULL, NULL, NULL);
                }
                $mysqli->options(MYSQLI_OPT_SSL_VERIFY_SERVER_CERT, false);
            }

            $connected = $mysqli->real_connect(
                $dbConfig['host'],
                $dbConfig['username'],
                $dbConfig['password'],
                $dbConfig['database'],
                $dbConfig['port'],
                NULL,
                $useSSL ? MYSQLI_CLIENT_SSL : 0
            );

            if ($connected) {
                echo "✅ <strong style='color: green;'>連線成功！</strong><br>";

                // 檢查是否使用 SSL
                $result = $mysqli->query("SHOW STATUS LIKE 'Ssl_cipher'");
                if ($result) {
                    $row = $result->fetch_assoc();
                    if (!empty($row['Value'])) {
                        echo "🔒 SSL 已啟用，加密方式: " . $row['Value'] . "<br>";
                    } else {
                        echo "⚠️ 連線成功但未使用 SSL<br>";
                    }
                }

                // 測試查詢
                $result = $mysqli->query("SELECT VERSION() as version");
                if ($result) {
                    $row = $result->fetch_assoc();
                    echo "TiDB 版本: " . $row['version'] . "<br>";
                }

                $mysqli->close();
            } else {
                echo "❌ <strong style='color: red;'>連線失敗</strong><br>";
                echo "錯誤: " . htmlspecialchars($mysqli->connect_error) . "<br>";
            }

        } catch (Exception $e) {
            echo "❌ <strong style='color: red;'>連線失敗</strong><br>";
            echo "錯誤: " . htmlspecialchars($e->getMessage()) . "<br>";
        }

        echo "<hr>";
    }
} else {
    echo "❌ MySQLi 擴展未載入<br>";
}
?>
