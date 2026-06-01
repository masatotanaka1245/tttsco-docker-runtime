<?php
/**
 * save_user_settings.php - ユーザー個別の設定(Ollama接続先等)を保存し検証するAPI
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';

header('Content-Type: application/json; charset=utf-8');

// 1. 認証チェック
$auth = new Auth($pdo);
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => '認証が必要です']);
    exit;
}

// 2. JSONデータのパース
$input = json_decode(file_get_contents('php://input'), true);
if (json_last_error() !== JSON_ERROR_NONE) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'データ形式が不正です']);
    exit;
}

// 3. CSRFトークンの検証
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrfHeader) || !verifyCsrfToken($csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF検証に失敗しました']);
    exit;
}

// 4. データの受け取りと正規化
$prompt = $input['default_prompt'] ?? 'construction_consultant';
$lang   = $input['default_lang'] ?? 'ja';
$model  = trim($input['default_model'] ?? 'gemma4:e4b');
$sub_model = trim($input['sub_model'] ?? 'gemma4:e4b');
// URLの末尾の「/」を取り除く
$ollama_host = rtrim(trim($input['ollama_host'] ?? 'http://127.0.0.1:11434'), '/');

// =========================================================================
// ★安全対策: 入力されたOllama URLへ接続テストを行う (3秒でタイムアウト)
// =========================================================================
if (function_exists('curl_init')) {
    $ch = curl_init("{$ollama_host}/api/tags");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 3);
    $res = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    // AIサーバーから正常なレスポンス(200)が返ってこない場合は保存をブロック
    if ($res === false || $httpCode !== 200) {
        echo json_encode(['success' => false, 'error' => "指定された AIサーバー({$ollama_host}) と通信できません。URLやポート(通常11434)が正しいか、サーバーが起動しているか確認してください。"]);
        exit;
    }
}

try {
    // 5. データベース(usersテーブル)の更新
    $stmt = $pdo->prepare("
        UPDATE users 
        SET default_prompt = ?, 
            default_lang = ?, 
            default_model = ?, 
            sub_model = ?, 
            ollama_host = ?, 
            updated_at = NOW() 
        WHERE id = ?
    ");
    $stmt->execute([$prompt, $lang, $model, $sub_model, $ollama_host, $_SESSION['user_id']]);

    // 6. セッションへ即時同期（再ログイン不要で最新設定を適用するため）
    $_SESSION['default_prompt'] = $prompt;
    $_SESSION['default_lang']   = $lang;
    $_SESSION['default_model']  = $model;
    $_SESSION['sub_model']      = $sub_model;
    $_SESSION['ollama_host']    = $ollama_host;

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DBエラー: ' . $e->getMessage()]);
}