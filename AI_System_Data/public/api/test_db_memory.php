<?php

/**
 * public/api/test_db_memory.php
 *
 * SqlExecutionEngine.php の generateAndSaveDatabaseMemory() メソッド単体テスト用スクリプト
 * プロジェクトに属するDBのメタ情報をスキャンし、project_meta に記憶JSONとして保存されるかを検証します。
 */

// エラーを画面に表示してデバッグしやすくする設定
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// パス解決
$basePath = realpath(__DIR__ . '/../../');

// =========================================================================
// 1. 環境のセットアップとパス解決
// =========================================================================

// システムの標準的なデータベース接続ファイルを読み込み（※環境に合わせてファイル名を調整してください）
$dbConnectFile = $basePath . '/config/database.php'; 
if (file_exists($dbConnectFile)) {
    require_once $dbConnectFile;
} else {
    die("<h3 style='color:red;'>❌ データベース接続ファイルが見つかりません。パス: {$dbConnectFile}</h3>");
}

// SqlExecutionEngine の読み込み
require_once $basePath . '/src/SqlExecutionEngine.php';

// もし読み込んだ環境に chatLogger が存在しない場合の安全フォールバック（スタブ）
if (!function_exists('chatLogger')) {
    function chatLogger($msg) {
        global $basePath;
        $logDir = $basePath . DIRECTORY_SEPARATOR . 'logs';
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0777, true);
        }
        $debugLog = $logDir . DIRECTORY_SEPARATOR . 'chat_debug.log';
        $ts = date('Y-m-d H:i:s');
        file_put_contents($debugLog, "[$ts] [TEST_DB_MEMORY] $msg\n", FILE_APPEND);
    }
}

// =========================================================================
// 2. 検証用データのセットと実行
// =========================================================================

// 今回の検証ターゲット
$projectId = 35;

// HTML出力の開始
echo "<!DOCTYPE html>";
echo "<html lang='ja'>";
echo "<head>";
echo "<meta charset='UTF-8'>";
echo "<title>DB Memory 記憶生成テスト</title>";
echo "<style>
        body { font-family: 'Helvetica Neue', Arial, sans-serif; background-color: #f8fafc; color: #334155; padding: 30px; }
        .container { max-width: 900px; margin: 0 auto; background: #ffffff; padding: 20px 30px; border-radius: 8px; box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1); }
        h2 { border-bottom: 2px solid #e2e8f0; padding-bottom: 10px; }
        .success { color: #059669; font-weight: bold; padding: 10px; background: #d1fae5; border-radius: 6px; }
        .error { color: #e11d48; font-weight: bold; padding: 10px; background: #ffe4e6; border-radius: 6px; }
        pre { background: #1e293b; color: #e2e8f0; padding: 20px; border-radius: 8px; overflow-x: auto; font-size: 13px; line-height: 1.5; }
      </style>";
echo "</head>";
echo "<body>";
echo "<div class='container'>";
echo "<h2>🧠 SqlExecutionEngine::generateAndSaveDatabaseMemory() 実行テスト</h2>";
echo "<p>ターゲット案件ID: <strong>" . htmlspecialchars((string)$projectId) . "</strong></p>";

try {
    // $pdo は読み込んだ db_connect.php などの内部で生成されている前提
    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new Exception('$pdo インスタンスが存在しません。データベース接続設定を確認してください。');
    }

    // エンジンのインスタンス化
    $sqlEngine = new SqlExecutionEngine($pdo, $projectId);
    
    // メソッドのキック（戻り値は必ず boolean になるよう設計済み）
    $result = $sqlEngine->generateAndSaveDatabaseMemory();

    if ($result === true) {
        echo "<div class='success'>🎉 【成功】プロジェクト {$projectId} のデータベース記憶が正常に生成・保存されました！</div>";
        
        // =========================================================================
        // 3. 保存された記憶（JSON）の生プレビュー
        // =========================================================================
        $stmt = $pdo->prepare("SELECT meta_value FROM project_meta WHERE project_id = ? AND meta_key = 'ai_database_memory'");
        $stmt->execute([$projectId]);
        $jsonStr = $stmt->fetchColumn();
        
        if ($jsonStr) {
            $memoryArray = json_decode($jsonStr, true);
            
            echo "<h3>📝 project_meta テーブルから抽出した記憶データの生プレビュー:</h3>";
            echo "<pre>";
            // print_r の第2引数 true で文字列として返し、美しくダンプする
            echo htmlspecialchars(print_r($memoryArray, true));
            echo "</pre>";
        } else {
            echo "<div class='error'>⚠️ メソッドは true を返しましたが、project_meta テーブルからデータを引き抜けませんでした。</div>";
        }
    } else {
        echo "<div class='error'>❌ 【失敗】記憶の生成中にエラーが発生しました。</div>";
    }

} catch (Exception $e) {
    echo "<div class='error'>🚨 例外発生: " . htmlspecialchars($e->getMessage()) . "</div>";
}

echo "</div>";
echo "</body>";
echo "</html>";