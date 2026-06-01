<?php
/**
 * 外部ツールの実行権限とパスを確認するためのデバッグスクリプト
 * ★[改善] ハードコード撤廃。セッションからOllamaのURLを動的に取得するよう改修
 */
require_once __DIR__ . '/../../config/session.php';

header('Content-Type: text/plain; charset=utf-8');

// 1. パスの計算
$basePath = realpath(__DIR__ . '/../../'); 
$toolsDir = $basePath . DIRECTORY_SEPARATOR . 'tools';

$tools = [
    'pdfinfo' => $toolsDir . DIRECTORY_SEPARATOR . 'pdfinfo.exe',
    'pdftotext' => $toolsDir . DIRECTORY_SEPARATOR . 'pdftotext.exe',
    'pdftopng' => $toolsDir . DIRECTORY_SEPARATOR . 'pdftopng.exe'
];

echo "--- ツールパス確認 ---\n";
foreach ($tools as $name => $path) {
    echo "[$name]: " . (file_exists($path) ? "OK" : "NG (見つかりません)") . "\n";
    echo "Path: $path\n\n";
}

echo "--- 実行テスト (pdfinfo -v) ---\n";
$cmd = escapeshellarg($tools['pdfinfo']) . " -v 2>&1";
$output = shell_exec($cmd);
echo "Output: " . ($output ?: "何も出力されませんでした。実行権限がない可能性があります。") . "\n\n";

// =========================================================================
// ★改善: ハードコード撤廃。セッションからOllamaのURLを動的に取得する
// =========================================================================
@session_start();
$ollama_host = rtrim($_SESSION['ollama_host'] ?? 'http://127.0.0.1:11434', '/');
session_write_close();

echo "--- Ollama 接続テスト ({$ollama_host}) ---\n";
$ch = curl_init("{$ollama_host}/api/tags");
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 5);
$res = curl_exec($ch);
if ($res) {
    echo "Ollama OK: モデル一覧を取得できました。\n";
} else {
    echo "Ollama NG: " . curl_error($ch) . "\n";
}
curl_close($ch);

echo "\n--- ログフォルダの書き込み権限 ---\n";
$logDir = $basePath . DIRECTORY_SEPARATOR . 'logs';
echo "LogDir: $logDir\n";
echo "Writable: " . (is_writable($logDir) ? "YES" : "NO (書き込み不可)") . "\n";