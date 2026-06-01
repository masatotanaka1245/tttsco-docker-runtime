<?php
/**
 * chat_history.php - 特定プロジェクト、または全社横断（グローバル）の過去チャット履歴を取得するAPI
 * 配置場所: public/api/chat_history.php
 *
 * ★[改善点・完全クレンジング版]
 * 1. 司令塔セッションロック早期解放の仕様を完全適用。session_write_close() で即座に解放。
 * 2. セッション解放後の $_SESSION 直接ゾンビ参照スコープバグを完全排除。
 * 3. 閲覧セキュリティの適正化：一般ユーザーに対し「自分が起案者」または「アサインメンバー」のいずれかでアクセスを許可。
 * 4. 【グローバル履歴対応】project_id が未指定（null）の場合、全社横断対話の本人履歴を安全にロードする機能を追加。
 * 5. 【DRY原則完全準拠】：酷似していた配列詰め替え処理を一元集約し、コードの保守性を極大化。
 * 6. 【フロント完全バインド】：chat.js側の「📌ナレッジ登録」やDOM特定に必須となる「履歴ID (id)」をレスポンスに完全追加。
 */

// 不要な警告がJSONレスポンスを破壊するのを防ぐためにバッファリング
ob_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';

// 1. 認証チェック
$auth = new Auth($pdo);
if (!$auth->isLoggedIn()) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    http_response_code(401);
    echo json_encode([
        'status' => 'error',
        'error'  => 'Unauthorized: ログインセッションが切れました。再ログインしてください。',
        'history'=> []
    ]);
    exit;
}

// ★セッション情報を速やかにローカル退避させ、ロックを即座に解放
$user_id = $_SESSION['user_id'];
$role    = $_SESSION['role'] ?? 'member';
$session_csrf = $_SESSION['csrf_token'] ?? '';

session_write_close();
ob_end_clean();

header('Content-Type: application/json; charset=utf-8');

try {
    // 2. パラメータの取得と厳格なバリデーション
    $project_id_input = filter_input(INPUT_GET, 'project_id', FILTER_DEFAULT);
    $project_id = null;

    if ($project_id_input !== null && $project_id_input !== '' && $project_id_input !== '0') {
        $validated_id = filter_var($project_id_input, FILTER_VALIDATE_INT);
        if ($validated_id === false) {
            throw new Exception('Bad Request: 不正なプロジェクトIDが指定されました。');
        }
        $project_id = $validated_id;
    }

    // クエリ用変数の初期化
    $sql = "";
    $params = [];

    if ($project_id === null) {
        // ━━━━【全社横断（グローバル）チャット履歴の取得】━━━━
        // セキュリティ制限: 他人のグローバルチャット履歴を覗き見できないよう、本人のみ (user_id = ?) にロック
        $sql = "
            SELECT id, role, message, created_at 
            FROM chat_history 
            WHERE project_id IS NULL AND user_id = ? 
            ORDER BY created_at ASC
        ";
        $params = [$user_id];

    } else {
        // ━━━━【プロジェクト別チャット履歴の取得】━━━━
        // 3. セキュリティ制限：一般ユーザー（admin以外）は作成者、またはアサインメンバーに限定
        if ($role !== 'admin') {
            $stmtCheck = $pdo->prepare("
                SELECT COUNT(*) 
                FROM projects p
                LEFT JOIN project_members pm ON p.id = pm.project_id AND pm.user_id = ?
                WHERE p.id = ? AND (p.created_by = ? OR pm.id IS NOT NULL)
            ");
            $stmtCheck->execute([$user_id, $project_id, $user_id]);
            if ($stmtCheck->fetchColumn() == 0) {
                http_response_code(403);
                throw new Exception('Forbidden: このプロジェクトに対するアクセス権限またはアサイン情報がありません。');
            }
        }

        // 4. プロジェクト別チャット履歴の取得クエリ定義
        $sql = "
            SELECT id, role, message, created_at 
            FROM chat_history 
            WHERE project_id = ? 
            ORDER BY created_at ASC
        ";
        $params = [$project_id];
    }

    // データの抽出（条件分岐から完全に独立させ、DRYを確立）
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $history = [];
    foreach ($rows as $row) {
        $history[] = [
            'id'         => (int)$row['id'], // ★追加：フロントエンドでのDOM制御、ナレッジ化処理(📌)に必須のキー
            'role'       => $row['role'],
            'message'    => $row['message'],
            'created_at' => $row['created_at']
        ];
    }

    // 正常レスポンスの出力
    echo json_encode([
        'status'  => 'success',
        'history' => $history
    ], JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    if (http_response_code() === 200) {
        http_response_code(500);
    }
    echo json_encode([
        'status'  => 'error',
        'error'   => $e->getMessage(),
        'history' => [] 
    ], JSON_UNESCAPED_UNICODE);
}