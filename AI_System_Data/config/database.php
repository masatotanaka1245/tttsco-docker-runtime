<?php
// config/database.php

// ① Windows production / local environment settings
$env = [
    'DB_HOST'   => getenv('DB_HOST') ?: 'localhost',
    'DB_NAME'   => getenv('DB_NAME') ?: 'tepscoapp',
    'DB_USER'   => getenv('DB_USER') ?: 'newuser',
    'DB_PASS'   => getenv('DB_PASS') ?: 'password',
];

// ② PDO 接続
$dsn = sprintf(
    'mysql:host=%s;dbname=%s;charset=utf8mb4',
    $env['DB_HOST'],
    $env['DB_NAME']
);

try {
    $pdo = new PDO(
        $dsn,
        $env['DB_USER'],
        $env['DB_PASS'],
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    http_response_code(500);
    exit('DB 接続失敗: ' . $e->getMessage());
}
