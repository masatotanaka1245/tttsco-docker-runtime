<?php
// src/autoload.php
spl_autoload_register(function ($class) {
    // 名前空間区切りをディレクトリ区切りに変換
    $file = __DIR__ . '/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require $file;
    }
});