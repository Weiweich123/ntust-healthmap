<?php
// config.example.php - 配置檔案範例
// 複製此檔案為 config.php 並填入你的資料庫資訊

return [
    // 環境設定：'local' 或 'tidb'
    'environment' => 'local',

    // 本地 MySQL 設定
    'local' => [
        'host' => '127.0.0.1',
        'port' => '3306',
        'database' => 'ntust_healthmap',
        'username' => 'root',
        'password' => '',
        'use_ssl' => false,
    ],

    // TiDB Cloud 設定
    'tidb' => [
        'host' => 'your-tidb-host.tidbcloud.com',
        'port' => '4000',
        'database' => 'ntust_healthmap',
        'username' => 'your-username.root',
        'password' => 'your-password',
        'use_ssl' => true,
        'ssl_ca' => __DIR__ . '/isrgrootx1.pem',
    ],
];
