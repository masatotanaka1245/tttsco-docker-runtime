<?php
/**
 * save_user_settings.php - ユーザー個別の設定(Ollama接続先等)を保存し検証するAPI
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/ModelRoleResolver.php';
require_once __DIR__ . '/../../src/OllamaModelCatalog.php';
require_once __DIR__ . '/../../src/UserSettingsSchema.php';

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

$modelDefaults = ModelRoleResolver::defaults();

// 4. データの受け取りと正規化
$prompt = $input['default_prompt'] ?? 'construction_consultant';
$lang   = $input['default_lang'] ?? 'ja';
$model  = trim($input['default_model'] ?? $modelDefaults['main_model']);
$sub_model = trim($input['sub_model'] ?? $modelDefaults['sub_model']);
$embedding_model = trim($input['embedding_model'] ?? $modelDefaults['embedding_model']);
// URLの末尾の「/」を取り除く
$ollama_host = rtrim(trim($input['ollama_host'] ?? 'http://127.0.0.1:11434'), '/');
$hasEmbeddingModelColumn = UserSettingsSchema::hasEmbeddingModelColumn($pdo);

$missingRequiredFields = [];
if ($ollama_host === '') {
    $missingRequiredFields[] = 'Ollama 接続先 URL';
}
if ($model === '') {
    $missingRequiredFields[] = 'メイン使用モデル';
}
if ($sub_model === '') {
    $missingRequiredFields[] = 'サブモデル';
}
if ($embedding_model === '') {
    $missingRequiredFields[] = 'Embeddingモデル';
}

if (!empty($missingRequiredFields)) {
    echo json_encode([
        'success' => false,
        'error' => '未設定の項目があります: ' . implode(' / ', $missingRequiredFields) . '。LLM が表示されない場合は、接続先と Ollama 上のモデル配備状況をご確認ください。'
    ]);
    exit;
}

// =========================================================================
// ★安全対策: 入力されたOllama URLへ接続し、モデル名の存在も検証する
// =========================================================================
$catalogProbe = OllamaModelCatalog::probe($ollama_host, 3);
if (!$catalogProbe['success']) {
    echo json_encode([
        'success' => false,
        'error' => "指定された AIサーバー({$ollama_host}) と通信できません。URLやポート(通常11434)が正しいか、サーバーが起動しているか確認してください。"
    ]);
    exit;
}

$availableModels = $catalogProbe['models'];
if (empty($availableModels)) {
    echo json_encode([
        'success' => false,
        'error' => "指定された AIサーバー({$ollama_host}) には利用可能な LLM が見つかりませんでした。`ollama list` の結果や、対象ホストにモデルが配置されているかをご確認ください。"
    ]);
    exit;
}

$requestedModels = [
    'メイン使用モデル' => $model,
    'サブモデル' => $sub_model,
    'Embeddingモデル' => $embedding_model,
];
$missingModels = [];
foreach ($requestedModels as $label => $requestedModel) {
    if ($requestedModel !== '' && OllamaModelCatalog::resolveRequestedModel($requestedModel, $availableModels) === null) {
        $missingModels[] = "{$label}: {$requestedModel}";
    }
}

if (!empty($missingModels)) {
    $availablePreview = array_slice($availableModels, 0, 8);
    $availableMessage = empty($availablePreview) ? '取得できませんでした' : implode(', ', $availablePreview);
    echo json_encode([
        'success' => false,
        'error' => "指定されたモデルが Ollama に存在しません。 " . implode(' / ', $missingModels) . " | 利用可能モデル例: {$availableMessage}"
    ]);
    exit;
}

$model = OllamaModelCatalog::resolveRequestedModel($model, $availableModels) ?? $model;
$sub_model = OllamaModelCatalog::resolveRequestedModel($sub_model, $availableModels) ?? $sub_model;
$embedding_model = OllamaModelCatalog::resolveRequestedModel($embedding_model, $availableModels) ?? $embedding_model;

try {
    // 5. データベース(usersテーブル)の更新
    $sql = "
        UPDATE users
        SET default_prompt = ?,
            default_lang = ?,
            default_model = ?,
            sub_model = ?,
            ollama_host = ?, ";
    $params = [$prompt, $lang, $model, $sub_model, $ollama_host];
    if ($hasEmbeddingModelColumn) {
        $sql .= "
            embedding_model = ?, ";
        $params[] = $embedding_model;
    }
    $sql .= "
            updated_at = NOW()
        WHERE id = ?
    ";
    $params[] = $_SESSION['user_id'];
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // 6. セッションへ即時同期（再ログイン不要で最新設定を適用するため）
    $_SESSION['default_prompt'] = $prompt;
    $_SESSION['default_lang']   = $lang;
    $_SESSION['default_model']  = $model;
    $_SESSION['sub_model']      = $sub_model;
    $_SESSION['embedding_model'] = $embedding_model;
    $_SESSION['ollama_host']    = $ollama_host;

    echo json_encode([
        'success' => true,
        'embedding_model_persisted' => $hasEmbeddingModelColumn,
        'validated_model_count' => count($availableModels)
    ]);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DBエラー: ' . $e->getMessage()]);
}
