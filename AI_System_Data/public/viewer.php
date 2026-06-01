<?php
/**
 * 高機能PDFビューアー (Page 0 ガード対応)
 * * 検索結果やAIチャットから渡される「page=0 (全体要約)」という仮想的なページ番号を
 * ブラウザが理解できる「page=1」に変換してPDFを表示します。
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../src/Auth.php';

$auth = new Auth($pdo);
if (!$auth->isLoggedIn()) {
    exit('Unauthorized');
}

// パラメータ取得
$id   = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
$page = filter_input(INPUT_GET, 'page', FILTER_VALIDATE_INT);

if (!$id) {
    exit('Invalid ID');
}

/**
 * 修正ポイント: Page 0 ガードロジック
 * データベース上の page_number = 0 は「全体要約」を指す仮想番号です。
 * PDFの実ファイルには0ページ目は存在しないため、1ページ目を表示するように補正します。
 */
$targetPage = ($page === null || $page < 1) ? 1 : $page;

// 既存の表示用APIのURLを構築
// #page=N を付与することで、ブラウザ内蔵ビューアーが自動で該当ページへスクロールします
$pdfUrl = "api/view_pdf.php?id={$id}#page={$targetPage}";
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>PDF Viewer</title>
    <style>
        body, html { margin: 0; padding: 0; height: 100%; overflow: hidden; background-color: #525659; }
        /* iframeを画面いっぱいに表示 */
        iframe { width: 100%; height: 100%; border: none; }
    </style>
</head>
<body>
    <!-- 補正済みのターゲットページを含めたURLをセット -->
    <iframe src="<?= htmlspecialchars($pdfUrl) ?>"></iframe>
</body>
</html>