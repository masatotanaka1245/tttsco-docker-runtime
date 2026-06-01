<?php
// AI_System_Data/public/api/view_pdf.php
// (元: public/serve_pdf.php)

// 予期せぬ空白文字やWarningがPDFデータに混ざるのを防ぐ
if (ob_get_length()) ob_clean();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/ProjectAccess.php';

$auth = new Auth($pdo);
if (!$auth->isLoggedIn()) {
    http_response_code(401);          // 未認証
    exit('Unauthorized');
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    http_response_code(400);          // ID が不正
    exit('Bad Request');
}

// ファイルパスに加えて、画面表示用の「タイトル(日本語)」も取得する
$stmt = $pdo->prepare("SELECT project_id, title, file_path FROM documents WHERE id = ?");
$stmt->execute([$id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    http_response_code(404);          // ドキュメントが存在しない
    exit('Document not found in database');
}

if (!canAccessProject($pdo, (int)$doc['project_id'], (int)$_SESSION['user_id'], $_SESSION['role'] ?? 'user')) {
    http_response_code(403);
    exit('Forbidden');
}

/* ① ファイルパスを絶対パスで解決（public/ からの相対） */
// apiフォルダの中なので、__DIR__ . '/../' で publicフォルダ を指す
$fullPath = realpath(__DIR__ . '/../' . $doc['file_path']);
$allowedBaseDir = realpath(__DIR__ . '/../01_RAG_Documents');

// セキュリティ: パストラバーサル対策 ＆ ファイル存在チェック
if ($fullPath === false || strpos($fullPath, $allowedBaseDir) !== 0 || !file_exists($fullPath)) {
    http_response_code(404);          // ファイルが見つからない、または不正アクセス
    exit('File not found on server or access denied');
}

/* ② ヘッダー設定 */
header('Content-Type: application/pdf');

// 日本語のタイトル名でブラウザや保存ダイアログに表示させるためのエンコード処理
$displayTitle = !empty($doc['title']) ? $doc['title'] : basename($fullPath);
$encodedTitle = urlencode($displayTitle);
header('Content-Disposition: inline; filename*=UTF-8\'\'' . $encodedTitle);

header('Content-Length: ' . filesize($fullPath));
header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

/* ③ ファイル送信 */
readfile($fullPath);
exit;
