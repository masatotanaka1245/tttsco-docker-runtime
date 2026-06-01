<?php
/**
 * get_upload_status.php - アップロード進捗取得 API (セッションロック完全回避＆共有ロック読込版)
 * logs/progress_<sessionId>.json から進捗状況を安全に読み取って返却します。
 */

// 不要な警告や通知文字が偶然交じってJSONレスポンスを破壊するのを防ぐためにバッファリング
ob_start();

// セッション設定の読み込み（内部で session_start() が自動実行される前提）
require_once __DIR__ . '/../../config/session.php';

// ★[最重要] セッションIDを捕捉した直後にセッションロックを即時解放！
// これにより、1.5秒ごとの高速ポーリング通信が、バックエンドの重いインポート処理（upload_csv.php）を
// ブロッキングするのを100%回避し、システム全体のプチフリーズ（引っかかり）を一掃します。
$sessionId = session_id();
session_write_close();

// 出力バッファをクリアし、正しいJSONヘッダーを宣言
ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

// パスの正規化（upload_csv.php のログ保存先と完全に同期させます）
$basePath = realpath(__DIR__ . '/../../');
$progressFile = $basePath . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'progress_' . $sessionId . '.json';

// 1. 進捗ファイルが存在しない場合は「待機中(idle)」を返す
if (!file_exists($progressFile)) {
    echo json_encode([
        'status' => 'idle',
        'stage' => null,
        'progress' => 0,
        'message' => '待機中',
        'error' => null,
        'estimated_remaining' => null,
        'updated_at' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 2. ★[改善] ファイルを共有ロック（LOCK_SH）で安全に読み込む
// file_get_contents では、書き込み側の LOCK_EX の瞬間と衝突した際に空文字や壊れたJSONを掴み、
// フロントエンド側に「解析失敗エラー」を誤認させてしまうため、厳格に排他制御を同期します。
$rawContent = '';
$fp = @fopen($progressFile, 'r');
if ($fp) {
    if (flock($fp, LOCK_SH)) { // 共有ロックを要求（バックエンドの書き込み完了まで安全に待機）
        $rawContent = stream_get_contents($fp);
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

if ($rawContent === false || $rawContent === "") {
    echo json_encode([
        'status' => 'processing', 
        'stage' => 'reading',
        'progress' => 0,
        'message' => 'データ読み込み中...',
        'error' => null,
        'updated_at' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$data = json_decode($rawContent, true);

// 3. JSONデコードに失敗した場合のハンドリング
if (json_last_error() !== JSON_ERROR_NONE) {
    echo json_encode([
        'status' => 'error',
        'stage' => 'internal_error',
        'progress' => 0,
        'message' => '進捗ファイルのデータ構造の解析に失敗しました。',
        'error' => 'Invalid progress file format: ' . json_last_error_msg(),
        'updated_at' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 4. タイムアウトチェック (30分 = 1800秒)
// 最後に更新されてから長時間経過している場合は「処理がクラッシュしたゴミファイル」とみなして自動クリーンアップ
if (isset($data['updated_at']) && (time() - $data['updated_at'] > 1800)) {
    @unlink($progressFile);
    echo json_encode([
        'status' => 'idle',
        'stage' => 'timeout_cleanup',
        'progress' => 0,
        'message' => '長期間更新がないため、進捗セッションを自動クリーンアップしました。',
        'error' => null,
        'updated_at' => time()
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// 5. 正常データの返却（日本語文字化けを防ぐ JSON_UNESCAPED_UNICODE）
echo json_encode($data, JSON_UNESCAPED_UNICODE);