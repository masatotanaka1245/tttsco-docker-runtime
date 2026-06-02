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
    'pdfinfo' => [
        'binary' => 'pdfinfo',
        'candidates' => [$toolsDir . DIRECTORY_SEPARATOR . 'pdfinfo.exe']
    ],
    'pdftotext' => [
        'binary' => 'pdftotext',
        'candidates' => [$toolsDir . DIRECTORY_SEPARATOR . 'pdftotext.exe']
    ],
    'pdftopng/pdftoppm' => [
        'binary' => 'pdftoppm',
        'candidates' => [
            $toolsDir . DIRECTORY_SEPARATOR . 'pdftoppm.exe',
            $toolsDir . DIRECTORY_SEPARATOR . 'pdftopng.exe'
        ]
    ],
    'wkhtmltopdf' => [
        'binary' => 'wkhtmltopdf',
        'candidates' => [
            $toolsDir . DIRECTORY_SEPARATOR . 'wkhtmltopdf.exe',
            'C:\\Program Files\\wkhtmltopdf\\bin\\wkhtmltopdf.exe',
            'C:\\Program Files (x86)\\wkhtmltopdf\\bin\\wkhtmltopdf.exe'
        ]
    ]
];

function resolveDebugTool(string $binaryName, array $candidatePaths): array
{
    $detectedPath = trim((string)@shell_exec('command -v ' . escapeshellarg($binaryName) . ' 2>&1'));
    if ($detectedPath !== '' && is_file($detectedPath)) {
        return ['path' => $detectedPath, 'source' => 'PATH'];
    }

    foreach ($candidatePaths as $candidatePath) {
        if ($candidatePath !== '' && file_exists($candidatePath)) {
            return ['path' => $candidatePath, 'source' => 'candidate'];
        }
    }

    return ['path' => '', 'source' => 'not_found'];
}

function readDebugEnvValue(string $key, string $basePath): string
{
    $envPath = $basePath . DIRECTORY_SEPARATOR . '.env';
    if (!is_file($envPath) || !is_readable($envPath)) {
        return '';
    }

    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    if ($lines === false) {
        return '';
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$envKey, $envValue] = explode('=', $line, 2);
        if (trim($envKey) === $key) {
            return trim($envValue, " \t\n\r\0\x0B\"'");
        }
    }

    return '';
}

$resolvedTools = [];

echo "--- ツールパス確認 ---\n";
foreach ($tools as $name => $toolDef) {
    $resolved = resolveDebugTool($toolDef['binary'], $toolDef['candidates']);
    $resolvedTools[$name] = $resolved;
    $path = $resolved['path'];
    $status = $path !== '' ? "OK ({$resolved['source']})" : "NG (見つかりません)";
    echo "[$name]: {$status}\n";
    echo "Path: " . ($path !== '' ? $path : '(未検出)') . "\n";
    if ($path !== '' && preg_match('/\.exe$/i', $path) && DIRECTORY_SEPARATOR === '/') {
        echo "Note: Linux/Docker上ではWindows用 .exe は実行できません。PATH上のLinux版ツールを優先してください。\n";
    }
    echo "\n";
}

echo "--- 実行テスト (pdfinfo -v) ---\n";
$pdfinfoPath = $resolvedTools['pdfinfo']['path'] ?? '';
$cmd = $pdfinfoPath !== '' ? escapeshellarg($pdfinfoPath) . " -v 2>&1" : '';
$output = $cmd !== '' ? shell_exec($cmd) : null;
echo "Output: " . ($output ?: "何も出力されませんでした。実行権限がない可能性があります。") . "\n\n";

echo "--- 報告書PDF変換ツール確認 ---\n";
$reportConverters = [
    'WKHTMLTOPDF_PATH' => getenv('WKHTMLTOPDF_PATH') ?: '',
    'CHROME_PATH' => getenv('CHROME_PATH') ?: '',
    'wkhtmltopdf(PATH)' => trim((string)@shell_exec('command -v wkhtmltopdf 2>&1')),
    'Chromium(PATH)' => trim((string)@shell_exec('command -v chromium 2>&1')),
    'Chromium Browser(PATH)' => trim((string)@shell_exec('command -v chromium-browser 2>&1')),
    'Google Chrome(PATH)' => trim((string)@shell_exec('command -v google-chrome 2>&1')),
    'tools/wkhtmltopdf.exe' => $toolsDir . DIRECTORY_SEPARATOR . 'wkhtmltopdf.exe',
    'Chrome(Windows)' => 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe',
    'Chrome(Windows x86)' => 'C:\\Program Files (x86)\\Google\\Chrome\\Application\\chrome.exe',
    'Edge(Windows)' => 'C:\\Program Files\\Microsoft\\Edge\\Application\\msedge.exe',
    'Edge(Windows x86)' => 'C:\\Program Files (x86)\\Microsoft\\Edge\\Application\\msedge.exe',
    'Chrome(macOS)' => '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome',
    'Edge(macOS)' => '/Applications/Microsoft Edge.app/Contents/MacOS/Microsoft Edge',
];
foreach ($reportConverters as $name => $path) {
    if ($path === '') {
        echo "[$name]: 未設定\n";
        continue;
    }
    echo "[$name]: " . (file_exists($path) ? "OK" : "NG") . "\n";
    echo "Path: $path\n";
}
echo "\n";

// =========================================================================
// ★改善: ハードコード撤廃。セッションからOllamaのURLを動的に取得する
// =========================================================================
@session_start();
$ollama_host = $_SESSION['ollama_host']
    ?? getenv('OLLAMA_HOST')
    ?: readDebugEnvValue('OLLAMA_HOST', $basePath)
    ?: 'http://127.0.0.1:11434';
$ollama_host = rtrim($ollama_host, '/');
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
