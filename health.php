<?php
// 簡單的健康檢查頁面
header('Content-Type: application/json');
echo json_encode([
    'status' => 'ok',
    'timestamp' => date('Y-m-d H:i:s'),
    'php_version' => phpversion(),
    'env_check' => [
        'TIDB_HOST' => getenv('TIDB_HOST') ? 'set' : 'not set',
        'TIDB_DATABASE' => getenv('TIDB_DATABASE') ? 'set' : 'not set',
        'TIDB_USER' => getenv('TIDB_USER') ? 'set' : 'not set',
    ]
]);
