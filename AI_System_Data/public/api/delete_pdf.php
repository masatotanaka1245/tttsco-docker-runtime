<?php
/**
 * delete_pdf.php - PDF削除 ＆ 関連ベクトル・履歴クリーンアップ API (CSRF対応修正版)
 */

// 不要な警告がJSONレスポンスを破壊するのを防ぐためにバッファリング
ob_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/ProjectAccess.php';
require_once __DIR__ . '/../../src/AppLogger.php';

if (!function_exists('resolveDocumentAbsolutePath')) {
    function resolveDocumentAbsolutePath(string $filePath, string $publicBaseDir): ?string
    {
        $trimmed = trim($filePath);
        if ($trimmed === '') {
            return null;
        }

        $normalized = str_replace('\\', '/', $trimmed);
        $projectRoot = dirname($publicBaseDir);
        $candidates = [];

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $trimmed) === 1 || str_starts_with($trimmed, '/')) {
            $candidates[] = $trimmed;
        } else {
            $relative = ltrim($normalized, '/');
            $candidates[] = $publicBaseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $candidates[] = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if (str_starts_with($relative, 'public/')) {
                $withoutPublic = substr($relative, strlen('public/'));
                $candidates[] = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
                $candidates[] = $publicBaseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $withoutPublic);
            } elseif (!str_starts_with($relative, '01_RAG_Documents/')) {
                $candidates[] = $publicBaseDir . DIRECTORY_SEPARATOR . '01_RAG_Documents' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            }
        }

        foreach (array_unique($candidates) as $candidate) {
            $resolved = realpath($candidate);
            if ($resolved !== false && is_file($resolved)) {
                return $resolved;
            }
        }

        return null;
    }
}

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

// 1. 認証チェック
$auth = new Auth($pdo);
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'ログインが必要です']);
    exit;
}

// 2. CSRFトークンの検証 (HTTPヘッダー X-CSRF-Token を検証)
$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCsrfToken($csrfHeader)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF検証に失敗しました。不正な操作の可能性があります。']);
    exit;
}

// JSからのfetch(JSON形式)データを受け取る
$input = json_decode(file_get_contents('php://input'), true);
$docId = isset($input['id']) ? filter_var($input['id'], FILTER_VALIDATE_INT) : false;

if (!$docId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => '不正なリクエストです']);
    exit;
}

try {
    // 3. 削除対象のファイルパスを取得
    $stmt = $pdo->prepare("SELECT project_id, file_path FROM documents WHERE id = ?");
    $stmt->execute([$docId]);
    $doc = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$doc) {
        http_response_code(404);
        echo json_encode(['success' => false, 'error' => '指定されたドキュメントが見つかりません']);
        exit;
    }

    if (!canManageProject($pdo, (int)$doc['project_id'], (int)$_SESSION['user_id'], $_SESSION['role'] ?? 'user')) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'この資料を削除する権限がありません']);
        exit;
    }

    // 4. 実体ファイルの物理削除 (セキュリティ対策含む)
    $baseDir = realpath(__DIR__ . '/../');
    $realPath = $baseDir !== false
        ? resolveDocumentAbsolutePath((string)$doc['file_path'], $baseDir)
        : null;
    $allowedDir = $baseDir !== false ? realpath($baseDir . '/01_RAG_Documents') : false;

    // 指定ディレクトリ（01_RAG_Documents）内のファイルであることの厳密な確認
    if ($realPath !== null && $allowedDir !== false && strpos($realPath, $allowedDir) === 0 && file_exists($realPath)) {
        @unlink($realPath); // サーバーから物理ファイルを削除
    }

    // 5. データベースからレコードを削除
    // (外部キー制約 ON DELETE CASCADE が結ばれているため、doc_chunks も自動で連動削除されます)
    $delStmt = $pdo->prepare("DELETE FROM documents WHERE id = ?");
    $delStmt->execute([$docId]);

    echo json_encode(['success' => true]);

} catch (PDOException $e) {
    jsonApiError('資料の削除に失敗しました', 500, $e, ['api' => 'delete_pdf', 'doc_id' => $docId]);
}
