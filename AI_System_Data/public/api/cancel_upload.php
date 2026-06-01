<?php
/**
 * cancel_upload.php - アップロード・解析処理の中断シグナル送信 API
 * 配置場所: public/api/cancel_upload.php
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';

header('Content-Type: application/json; charset=utf-8');

try {
    // 1. 認証チェック
    $auth = new Auth($pdo);
    if (!$auth->isLoggedIn()) {
        http_response_code(401);
        throw new Exception('ログインが必要です');
    }

    // 2. CSRFトークンの検証 (メタタグから取得された X-CSRF-Token ヘッダーを検証)
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($csrfHeader) || $csrfHeader !== ($_SESSION['csrf_token'] ?? '')) {
        http_response_code(403);
        throw new Exception('CSRF検証に失敗しました（不正なアクセス）');
    }

    $basePath = realpath(__DIR__ . '/../../');
    $logDir = $basePath . DIRECTORY_SEPARATOR . 'logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0777, true);
    }

    // ユーザーセッションIDに紐づいたキャンセルシグナル（フラグファイル）を生成
    $sessionId = session_id();
    $cancelFile = $logDir . DIRECTORY_SEPARATOR . 'cancel_' . $sessionId . '.flag';
    
    // 中断シグナルを書き込み
    file_put_contents($cancelFile, 'cancel_requested');

    echo json_encode([
        'success' => true,
        'message' => '解析の中断リクエストを送信しました。安全に停止しています...'
    ]);

} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}