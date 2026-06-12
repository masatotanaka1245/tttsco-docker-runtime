<?php
/**
 * upload.php - PDFアップロード ＆ 汎用文書・図面・画像 高精度解析 RAGデータ化 API
 * [修正内容] 
 * - pdftotext と VLM を掛け合わせた「スマート・ルーティング機能」を実装。
 * - AIがページの種類を判定し、綺麗なテキスト文書の場合は重いVLM処理をスキップして高速化。
 * - アプローチB: 複数モードで抽出したテキストをLLMで「重複排除・整理」する機能。
 * - 整理された綺麗なテキストを「500文字程度のチャンク」に分割し、それぞれ別行として保存する機能を実装。
 * - 水平スライス解析の分割数を「4分割」から「8分割」へ拡張し、微細な横長テキストの認識精度を最大化。
 * ★[改善] ユーザー設定からのAIサーバーURL・モデル動的取得機能、および全APIでの num_gpu=999 強制オフロードを実装
 */

$basePath = realpath(__DIR__ . '/../../'); 
$logDir = $basePath . DIRECTORY_SEPARATOR . 'logs';
if (!is_dir($logDir)) @mkdir($logDir, 0777, true);
$debugLog = $logDir . DIRECTORY_SEPARATOR . 'upload_debug.log';

function logger($msg) {
    global $debugLog;
    $ts = date('Y-m-d H:i:s');
    file_put_contents($debugLog, "[$ts] $msg\n", FILE_APPEND);
}

// タイムアウトとメモリ制限の拡張
ignore_user_abort(true);
set_time_limit(0); 
ini_set('memory_limit', '2G'); 

ob_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/ProjectAccess.php';
require_once __DIR__ . '/../../src/AppLogger.php';
require_once __DIR__ . '/../../src/ModelRoleResolver.php';
require_once __DIR__ . '/../../src/UserSettingsSessionSynchronizer.php';

// 認証チェック
$auth = new Auth($pdo);
if (!$auth->isLoggedIn()) {
    ob_end_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => 'ログインが必要です']);
    exit;
}

UserSettingsSessionSynchronizer::sync($pdo, (int)$_SESSION['user_id']);

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (!verifyCsrfToken($csrfToken)) {
    ob_end_clean();
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => '不正なリクエストです（CSRF検証失敗）']);
    exit;
}

$sessionId = session_id();
$currentUserId = (int)$_SESSION['user_id'];
$currentRole = $_SESSION['role'] ?? 'user';
$progressFile = $logDir . DIRECTORY_SEPARATOR . 'progress_' . $sessionId . '.json';
$cancelFile = $logDir . DIRECTORY_SEPARATOR . 'cancel_' . $sessionId . '.flag';
$startTime = time(); 
$projectName = 'アップロード準備中...'; 

if (file_exists($cancelFile)) {
    @unlink($cancelFile);
}

function updateProgress($status, $stage, $current, $total, $message = '', $error = null) {
    global $progressFile, $startTime, $projectName; 
    $progress = ($total > 0) ? min(100, round(($current / $total) * 100)) : 0;
    $estimated_remaining = ($current > 0 && $total > $current) ? round(((time() - $startTime) / $current) * ($total - $current)) : null;

    $data = [
        'status' => $status, 'stage' => $stage, 'progress' => (int)$progress,
        'current' => (float)$current, 'total' => (int)$total, 'message' => $message,
        'error' => $error, 'estimated_remaining' => $estimated_remaining, 'updated_at' => time(),
        'project_name' => $projectName 
    ];
    file_put_contents($progressFile, json_encode($data), LOCK_EX);
    if ($message) logger("[Progress] $stage: $message");
}

function abortIfUploadCancelled($pdo, string $destPath, float $current, int $total): void {
    global $cancelFile;

    if (!file_exists($cancelFile)) {
        return;
    }

    @unlink($cancelFile);
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    if ($destPath !== '' && file_exists($destPath)) {
        @unlink($destPath);
    }
    updateProgress('cancelled', 'interrupted', $current, $total, "解析が中断されました。");
    logger("[CANCELLED] ユーザー操作によりPDF解析を安全に中断しました。current={$current}/{$total}");
    throw new Exception('USER_CANCELLED');
}

function callVLM($imagePath, $ollamaHost, $model, $prompt) {
    if (!file_exists($imagePath)) return "";
    $imageData = base64_encode(file_get_contents($imagePath));
    $payload = json_encode([
        'model' => $model, 'prompt' => $prompt, 'images' => [$imageData], 'stream' => false,
        // ★GPUフルオフロード指定を追加
        'options' => ['temperature' => 0.1, 'num_ctx' => 4096, 'num_gpu' => 999]
    ]);
    $curl = curl_init($ollamaHost . '/api/generate');
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($curl, CURLOPT_TIMEOUT, 240); 
    $res = curl_exec($curl);
    curl_close($curl);
    $json = json_decode($res, true);
    return trim((string)($json['response'] ?? ""));
}

function callLLM($ollamaHost, $model, $prompt) {
    $payload = json_encode([
        'model' => $model, 'prompt' => $prompt, 'stream' => false,
        // ★GPUフルオフロード指定を追加
        'options' => ['temperature' => 0.3, 'num_ctx' => 8192, 'num_gpu' => 999]
    ]);
    $curl = curl_init($ollamaHost . '/api/generate');
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $payload);
    curl_setopt($curl, CURLOPT_TIMEOUT, 300); 
    $res = curl_exec($curl);
    curl_close($curl);
    $json = json_decode($res, true);
    return trim((string)($json['response'] ?? ""));
}

/**
 * テキストをRAGに最適なチャンク（塊）サイズに分割する関数
 */
function splitTextIntoChunks(string $text, int $maxLength = 500): array {
    $chunks = [];
    $paragraphs = preg_split('/(\r\n|\n|\r)/', $text);
    $currentChunk = '';

    foreach ($paragraphs as $para) {
        $para = trim($para);
        if (empty($para)) continue;

        if (mb_strlen($currentChunk) + mb_strlen($para) > $maxLength) {
            if (!empty($currentChunk)) {
                $chunks[] = $currentChunk;
                $currentChunk = '';
            }
            if (mb_strlen($para) > $maxLength) {
                while (mb_strlen($para) > 0) {
                    $chunks[] = mb_substr($para, 0, $maxLength);
                    $para = mb_substr($para, $maxLength);
                }
            } else {
                $currentChunk = $para;
            }
        } else {
            $currentChunk .= (empty($currentChunk) ? '' : "\n") . $para;
        }
    }
    if (!empty($currentChunk)) {
        $chunks[] = $currentChunk;
    }
    return empty($chunks) ? [$text] : $chunks;
}

function generateTiles($sourcePath, $destDir, $prefix) {
    $tiles = [];
    if (class_exists('Imagick')) {
        try {
            $im = new Imagick($sourcePath);
            $w = $im->getImageWidth(); $h = $im->getImageHeight();
            $tileW = $w / 2; $tileH = $h / 2;
            for ($r = 0; $r < 2; $r++) {
                for ($c = 0; $c < 2; $c++) {
                    $tile = clone $im;
                    $tile->cropImage($tileW, $tileH, $c * $tileW, $r * $tileH);
                    $path = $destDir . DIRECTORY_SEPARATOR . "{$prefix}_tile_{$r}_{$c}.png";
                    $tile->writeImage($path);
                    $tiles[] = $path;
                    $tile->destroy();
                }
            }
            $im->destroy();
            return $tiles;
        } catch (Exception $e) { logger("Imagick Tile Error: " . $e->getMessage()); }
    }
    if (function_exists('imagecreatefrompng')) {
        try {
            $src = imagecreatefrompng($sourcePath);
            if (!$src) return [];
            $w = imagesx($src); $h = imagesy($src);
            $tileW = floor($w / 2); $tileH = floor($h / 2);
            for ($r = 0; $r < 2; $r++) {
                for ($c = 0; $c < 2; $c++) {
                    $dst = imagecreatetruecolor($tileW, $tileH);
                    imagecopy($dst, $src, 0, 0, $c * $tileW, $r * $tileH, $tileW, $tileH);
                    $path = $destDir . DIRECTORY_SEPARATOR . "{$prefix}_tile_{$r}_{$c}.png";
                    imagepng($dst, $path);
                    $tiles[] = $path;
                    imagedestroy($dst);
                }
            }
            imagedestroy($src);
            return $tiles;
        } catch (Exception $e) { logger("GD Tile Error: " . $e->getMessage()); }
    }
    return [];
}

function generateHorizontalSlices(string $sourcePath, string $destDir, string $prefix): array {
    $slices = [];
    if (class_exists('Imagick')) {
        try {
            $im = new Imagick($sourcePath);
            $w = $im->getImageWidth();
            $h = $im->getImageHeight();
            $sliceH = $h / 8; // 8分割の高さ計算
            for ($i = 0; $i < 8; $i++) {
                $slice = clone $im;
                $slice->cropImage($w, $sliceH, 0, $i * $sliceH);
                $path = $destDir . DIRECTORY_SEPARATOR . "{$prefix}_slice_{$i}.png";
                $slice->writeImage($path);
                $slices[] = $path;
                $slice->destroy();
            }
            $im->destroy();
            return $slices;
        } catch (Exception $e) { logger("Imagick Slice Error: " . $e->getMessage()); }
    }
    if (function_exists('imagecreatefrompng')) {
        try {
            $src = imagecreatefrompng($sourcePath);
            if (!$src) return [];
            $w = imagesx($src);
            $h = imagesy($src);
            $sliceH = floor($h / 8); // 8分割の高さ計算
            for ($i = 0; $i < 8; $i++) {
                $dst = imagecreatetruecolor($w, $sliceH);
                imagecopy($dst, $src, 0, 0, 0, $i * $sliceH, $w, $sliceH);
                $path = $destDir . DIRECTORY_SEPARATOR . "{$prefix}_slice_{$i}.png";
                imagepng($dst, $path);
                $slices[] = $path;
                imagedestroy($dst);
            }
            imagedestroy($src);
            return $slices;
        } catch (Exception $e) { logger("GD Slice Error: " . $e->getMessage()); }
    }
    return [];
}

function resolvePdfTool(string $binaryName, array $candidatePaths = []): string {
    $detectedPath = trim((string)@shell_exec('command -v ' . escapeshellarg($binaryName) . ' 2>&1'));
    if ($detectedPath !== '' && is_file($detectedPath)) {
        return $detectedPath;
    }

    foreach ($candidatePaths as $candidatePath) {
        if (is_file($candidatePath)) {
            return $candidatePath;
        }
    }

    return '';
}

function countPdfImagesOnPage(string $pdfimagesPath, string $pdfPath, int $pageNum): ?int {
    if ($pdfimagesPath === '' || !is_file($pdfimagesPath)) {
        return null;
    }

    $cmd = escapeshellarg($pdfimagesPath)
        . ' -list -f ' . $pageNum
        . ' -l ' . $pageNum . ' '
        . escapeshellarg($pdfPath)
        . ' 2>&1';
    $output = (string)shell_exec($cmd);
    if (trim($output) === '') {
        return null;
    }

    $lines = preg_split('/\r\n|\r|\n/', trim($output));
    $count = 0;
    foreach ($lines as $line) {
        if (preg_match('/^\s*' . preg_quote((string)$pageNum, '/') . '\s+\d+\s+/u', $line)) {
            $count++;
        }
    }
    return $count;
}

function textSuggestsVisualEvidence(string $rawText): bool {
    return (bool)preg_match('/(図|表|写真|画像|グラフ|チャート|断面|平面|縦断|横断|詳細図|配置図|系統図|凡例|寸法|Figure|Fig\.|Table|Photo|Chart)/iu', $rawText);
}

function isVisionUnavailableResponse(string $text): bool {
    $normalized = trim(preg_replace('/\s+/u', ' ', $text));
    if ($normalized === '') {
        return false;
    }

    return (bool)preg_match(
        '/(画像|写真|添付|分析対象).{0,40}(ない|ありません|おりません|未提供|見当たりません)|再度.{0,20}(アップロード|提供)/iu',
        $normalized
    );
}

function shouldUseVisualAnalysisForAuto(string $rawText, int $textCharCount, ?int $imageCount): bool {
    if ($textCharCount < 300) {
        return true;
    }
    if ($imageCount !== null && $imageCount > 0) {
        return true;
    }
    if (textSuggestsVisualEvidence($rawText)) {
        return true;
    }
    return false;
}

function chooseAutoVisualMode(string $overview, string $rawText, int $textCharCount, ?int $imageCount): string {
    $signalText = $overview . "\n" . mb_substr($rawText, 0, 1200);

    if (preg_match('/(図面|平面図|断面図|縦断図|横断図|詳細図|配置図|系統図|配筋|配管|凡例|寸法)/iu', $signalText)) {
        return 'tiles';
    }
    if (preg_match('/(テキスト文書|スキャン画像|報告書|章|節|項|目次|本文|表|一覧表|工程表|比較表|集計表|グラフ|チャート|Table|Fig\.|Figure)/iu', $signalText)) {
        return 'slices';
    }
    if (($imageCount ?? 0) > 0 && $textCharCount >= 800) {
        return 'full';
    }
    if (preg_match('/(スキャン画像|写真|画像)/u', $overview)) {
        return 'full';
    }

    return 'tiles';
}

function compactStorageText(string $text, int $limit = 120): string {
    $normalized = trim(preg_replace('/\s+/u', ' ', $text));
    if ($normalized === '') {
        return '';
    }
    return mb_strimwidth($normalized, 0, $limit, '...');
}

function detectOverviewCategory(string $overview): string {
    $categories = ['テキスト文書', '図面', 'スキャン画像', '写真', '表・グラフ', 'その他'];
    foreach ($categories as $category) {
        if (mb_strpos($overview, $category) !== false) {
            return $category;
        }
    }
    return '未分類';
}

function buildImageDescriptionForStorage(
    string $overview,
    string $mode = '',
    ?int $imageCount = null,
    string $fallbackKind = ''
): string {
    if ($fallbackKind === 'native') {
        return '本文中心ページ';
    }
    if ($fallbackKind === 'vlm_fallback') {
        return '本文中心ページ（VLMフォールバック）';
    }
    if ($fallbackKind === 'text_fallback') {
        return 'テキスト主体ページ（画像変換フォールバック）';
    }

    $category = detectOverviewCategory($overview);
    $summary = compactStorageText($overview, 100);

    $parts = ["ページ種別: {$category}"];
    if ($summary !== '' && $summary !== $category && !isVisionUnavailableResponse($summary)) {
        $parts[] = "概要: {$summary}";
    }
    if ($mode !== '') {
        $parts[] = "抽出モード: {$mode}";
    }
    if ($imageCount !== null) {
        $parts[] = "画像数: {$imageCount}";
    }

    return implode(' | ', $parts);
}

try {
    $projectId = filter_input(INPUT_POST, 'project_id', FILTER_VALIDATE_INT);
    $analysisMode = filter_input(INPUT_POST, 'analysis_mode', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'tiles';
    $uploadedFile = $_FILES['document'] ?? ($_FILES['pdf'] ?? null);
    if (!$projectId || !$uploadedFile) throw new Exception('パラメータ不足');
    if (!canAccessProject($pdo, (int)$projectId, $currentUserId, $currentRole)) {
        throw new RuntimeException('この案件へ資料を登録する権限がありません。');
    }
    if (($uploadedFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('ファイルアップロードに失敗しました。');
    }
    if (strtolower(pathinfo($uploadedFile['name'], PATHINFO_EXTENSION)) !== 'pdf') {
        throw new RuntimeException('アップロードできるのはPDFファイルのみです。');
    }

    session_write_close();

    $stmtProj = $pdo->prepare("SELECT project_name FROM projects WHERE id = ?");
    $stmtProj->execute([$projectId]);
    if ($projRow = $stmtProj->fetch(PDO::FETCH_ASSOC)) {
        $projectName = $projRow['project_name'];
    }

    $toolsDir = $basePath . DIRECTORY_SEPARATOR . 'tools';
    $pdfinfoPath = resolvePdfTool('pdfinfo', [
        $toolsDir . DIRECTORY_SEPARATOR . 'pdfinfo.exe',
    ]);
    $pdftotextPath = resolvePdfTool('pdftotext', [
        $toolsDir . DIRECTORY_SEPARATOR . 'pdftotext.exe',
    ]);
    $pdftopngPath = resolvePdfTool('pdftoppm', [
        $toolsDir . DIRECTORY_SEPARATOR . 'pdftoppm.exe',
        $toolsDir . DIRECTORY_SEPARATOR . 'pdftopng.exe',
    ]);
    $pdfimagesPath = resolvePdfTool('pdfimages', [
        $toolsDir . DIRECTORY_SEPARATOR . 'pdfimages.exe',
    ]);
    if ($pdfinfoPath === '' || $pdftotextPath === '' || $pdftopngPath === '') {
        throw new RuntimeException('PDF解析ツールが見つかりません。本番Windowsでは tools フォルダに pdfinfo.exe / pdftotext.exe / pdftoppm.exe（または pdftopng.exe）を配置してください。');
    }

    $storageBase = $basePath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . '01_RAG_Documents';
    $projectDir = $storageBase . DIRECTORY_SEPARATOR . $projectId;
    if (!is_dir($projectDir)) @mkdir($projectDir, 0777, true);

    $destPath = $projectDir . DIRECTORY_SEPARATOR . uniqid('doc_') . '.pdf';
    move_uploaded_file($uploadedFile['tmp_name'], $destPath);

    $infoCmd = escapeshellarg($pdfinfoPath) . ' ' . escapeshellarg($destPath) . ' 2>&1';
    $infoOutput = (string)shell_exec($infoCmd); 
    if (!preg_match('/Pages:\s+([0-9]+)/', $infoOutput, $matches)) throw new Exception("PDF解析失敗");
    $totalPages = (int)$matches[1];

    updateProgress('processing', 'init', 0.1, $totalPages, "解析を開始（全 {$totalPages} 頁 / モード: {$analysisMode}）");

    $pdo->beginTransaction();
    $stmtDoc = $pdo->prepare('INSERT INTO documents (project_id, title, file_path, created_at) VALUES (?, ?, ?, NOW())');
    $dbPath = '01_RAG_Documents/' . $projectId . '/' . basename($destPath);
    $stmtDoc->execute([$projectId, $uploadedFile['name'], $dbPath]);
    $docId = $pdo->lastInsertId();

    // =========================================================================
    // ★改善: ハードコード撤廃。セッションからOllamaのURLとモデルを動的に取得する
    // =========================================================================
    @session_start();
    $ollamaHost = rtrim($_SESSION['ollama_host'] ?? (getenv('OLLAMA_HOST') ?: 'http://127.0.0.1:11434'), '/');
    $resolvedModels = ModelRoleResolver::resolveUserSettings($_SESSION);
    $vlmModel   = $resolvedModels['vision_model'] ?? $resolvedModels['main_model'];
    $textModel  = $resolvedModels['sub_model']; // 重複排除と要約用
    session_write_close();
    logger("[PDF-IMAGE-MODEL] vision_model={$vlmModel} | text_model={$textModel} | host={$ollamaHost}");

    // ★GPUフル活用のため、EmbeddingEngineを使わずにここで直接cURLを用いて num_gpu=999 を強制指定
    $embed_model = $resolvedModels['embedding_model'];
    $embedWithRetry = function($text, $logTag) use ($ollamaHost, $embed_model) {
        $max_retries = 5;
        $delay = 1; // 秒
        $apiUrl = $ollamaHost . '/api/embeddings';
        
        for ($i = 0; $i < $max_retries; $i++) {
            try {
                // 文字列が極端に長い場合、Ollamaが500エラーになるのを防ぐ安全措置
                $safeText = mb_substr($text, 0, 300);
                
                $curl = curl_init($apiUrl);
                curl_setopt($curl, CURLOPT_POST, true);
                curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
                
                // ★VRAM保護・GPU最適化: options で num_gpu と num_ctx を明示的に指定
                curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode([
                    'model' => $embed_model,
                    'prompt' => $safeText,
                    'options' => [
                        'num_gpu' => 999,
                        'num_ctx' => 4096
                    ]
                ]));
                
                curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($curl, CURLOPT_TIMEOUT, 120); // タイムアウトを長めに設定
                
                $res = curl_exec($curl);
                
                if ($res === false) {
                    throw new RuntimeException("Ollama API通信エラー: " . curl_error($curl));
                }
                
                $httpCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
                curl_close($curl);
                
                if ($httpCode !== 200) {
                     throw new RuntimeException("Ollama HTTPエラー ({$httpCode}): " . $res);
                }
                
                $data = json_decode($res, true);
                if (!isset($data['embedding'])) {
                    throw new RuntimeException("ベクトルデータが返却されませんでした。");
                }
                
                return $data['embedding'];

            } catch (Exception $e) {
                if ($i === $max_retries - 1) {
                    throw $e;
                }
                logger("[Ollama-Embeddingリトライ警告] " . $logTag . " | 試行回数: " . ($i + 1) . " | 待機秒数: {$delay}s | エラー: " . $e->getMessage());
                sleep($delay);
                $delay *= 2;
            }
        }
        return null;
    };

    $stmtChunk = $pdo->prepare('INSERT INTO doc_chunks (doc_id, page_number, chunk_text, embedding, image_description) VALUES (?, ?, ?, ?, ?)');
    $allPageOverviews = [];

    for ($pageNum = 1; $pageNum <= $totalPages; $pageNum++) {
        abortIfUploadCancelled($pdo, $destPath, $pageNum - 1, $totalPages);

        updateProgress('processing', 'extraction', $pageNum - 0.9, $totalPages, "P.{$pageNum} ネイティブテキスト確認中...");

        $txtCmd = escapeshellarg($pdftotextPath) . ' -f ' . $pageNum . ' -l ' . $pageNum . ' -enc UTF-8 ' . escapeshellarg($destPath) . ' - 2>&1';
        $rawText = trim((string)shell_exec($txtCmd));
        $textCharCount = mb_strlen(preg_replace('/\s+/', '', $rawText));
        abortIfUploadCancelled($pdo, $destPath, $pageNum - 0.85, $totalPages);

        $finalImageDesc = "";
        $combinedText = "";

        $pageImageCount = null;
        $autoNeedsVisual = true;
        if ($analysisMode === 'auto') {
            $pageImageCount = countPdfImagesOnPage($pdfimagesPath, $destPath, $pageNum);
            $autoNeedsVisual = shouldUseVisualAnalysisForAuto($rawText, $textCharCount, $pageImageCount);
            $imageCountLabel = $pageImageCount === null ? '未取得' : (string)$pageImageCount;
            logger("[AUTO-PAGE-ROUTE] P.{$pageNum} textChars={$textCharCount} | images={$imageCountLabel} | visual=" . ($autoNeedsVisual ? 'yes' : 'no'));
        }

        if ($analysisMode === 'auto' && !$autoNeedsVisual && $textCharCount > 0) {
            updateProgress('processing', 'extraction', $pageNum - 0.5, $totalPages, "P.{$pageNum} 本文中心ページとして高速抽出を適用");
            $combinedText = "【電子テキスト】\n" . $rawText;
            $finalImageDesc = "Auto Native Text | textChars={$textCharCount}";
            $allPageOverviews[] = "P.{$pageNum}: 本文中心のページ（ネイティブテキスト抽出）";
        } else {
            $renderDpi = $analysisMode === 'auto' ? 200 : 300;
            updateProgress('processing', 'extraction', $pageNum - 0.8, $totalPages, "P.{$pageNum} 画像への変換中... (dpi={$renderDpi})");
            $tmpBase = $projectDir . DIRECTORY_SEPARATOR . "tmp_{$docId}_{$pageNum}";
            $pngOption = stripos(basename($pdftopngPath), 'pdftopng') !== false ? '' : ' -png';
            shell_exec(escapeshellarg($pdftopngPath) . $pngOption . ' -r ' . $renderDpi . ' -gray -f ' . $pageNum . ' -l ' . $pageNum . ' ' . escapeshellarg($destPath) . ' ' . escapeshellarg($tmpBase) . ' 2>&1');
            $imageFiles = glob($tmpBase . '-*.png');
            abortIfUploadCancelled($pdo, $destPath, $pageNum - 0.75, $totalPages);

            if (empty($imageFiles)) {
                $combinedText = $rawText;
                $finalImageDesc = buildImageDescriptionForStorage('', '', $pageImageCount, 'text_fallback');
                $allPageOverviews[] = "P.{$pageNum}: テキスト主体のページ";
            } else {
                $mainImg = $imageFiles[0];
                $imageMetaParts = [];

                updateProgress('processing', 'extraction', $pageNum - 0.6, $totalPages, "P.{$pageNum} ページ属性をAI判定中...");
                $overviewPrompt = "この画像（PDFの1ページ）の種類を、必ず【テキスト文書】【図面】【スキャン画像】【写真】【表・グラフ】【その他】のいずれかのタグで分類し、その後に全体の概要を100文字以内で説明してください。";
                $overview = callVLM($mainImg, $ollamaHost, $vlmModel, $overviewPrompt);
                abortIfUploadCancelled($pdo, $destPath, $pageNum - 0.55, $totalPages);
                $overviewRejected = isVisionUnavailableResponse($overview);
                if ($overviewRejected) {
                    logger("[VLM-FALLBACK] P.{$pageNum} overview rejected as vision-unavailable response. raw=" . mb_substr($overview, 0, 160));
                }
                $imageMetaParts[] = "種類と概要: " . $overview;
                $allPageOverviews[] = "P.{$pageNum}: " . $overview;

                $isTextDocument = (mb_strpos($overview, 'テキスト文書') !== false);
                $canUseNativeTextOnly = ($analysisMode !== 'auto' && $isTextDocument && $textCharCount > 100)
                    || ($analysisMode === 'auto' && $isTextDocument && $textCharCount >= 1200 && !textSuggestsVisualEvidence($rawText) && (($pageImageCount ?? 0) === 0));

                if ($overviewRejected && $textCharCount > 0) {
                    updateProgress('processing', 'extraction', $pageNum - 0.5, $totalPages, "P.{$pageNum} VLM応答不成立のためネイティブテキストへフォールバック");
                    $combinedText = "【電子テキスト】\n" . $rawText;
                    $finalImageDesc = buildImageDescriptionForStorage($overview, '', $pageImageCount, 'vlm_fallback');
                    $allPageOverviews[count($allPageOverviews) - 1] = "P.{$pageNum}: VLM応答不成立のためネイティブテキスト抽出へフォールバック";
                    @unlink($mainImg);
                } elseif ($canUseNativeTextOnly) {
                    updateProgress('processing', 'extraction', $pageNum - 0.5, $totalPages, "P.{$pageNum} 高速テキスト抽出を適用");
                    $combinedText = "【電子テキスト】\n" . $rawText;
                    $finalImageDesc = buildImageDescriptionForStorage($overview, '', $pageImageCount, 'native');
                    @unlink($mainImg);
                } else {
                    $effectiveMode = $analysisMode === 'auto'
                        ? chooseAutoVisualMode($overview, $rawText, $textCharCount, $pageImageCount)
                        : $analysisMode;
                    if ($analysisMode === 'auto') {
                        logger("[AUTO-VISUAL-MODE] P.{$pageNum} overview=" . mb_substr($overview, 0, 120) . " | mode={$effectiveMode}");
                    }

                    $tileText = "";
                    $sliceText = "";
                    $fullText = "";

                    if ($effectiveMode === 'all' || $effectiveMode === 'tiles') {
                        $tiles = generateTiles($mainImg, $projectDir, "tile_{$docId}_{$pageNum}");
                        $tileContents = [];
                        $totalTiles = count($tiles);
                        foreach ($tiles as $idx => $tPath) {
                            abortIfUploadCancelled($pdo, $destPath, $pageNum - 0.45, $totalPages);
                            updateProgress('processing', 'extraction', $pageNum - 0.4, $totalPages, "P.{$pageNum} タイル分割解析中... (" . ($idx+1) . "/{$totalTiles})");
                            $tileResult = callVLM($tPath, $ollamaHost, $vlmModel, "画像内の文字（文章、表データ、ラベル、図面注記、凡例）を正確に書き起こしてください。");
                            abortIfUploadCancelled($pdo, $destPath, $pageNum - 0.4, $totalPages);
                            if ($tileResult && !isVisionUnavailableResponse($tileResult)) {
                                $tileContents[] = "--- 領域" . ($idx+1) . " ---\n" . $tileResult;
                            } elseif ($tileResult) {
                                logger("[VLM-FALLBACK] P.{$pageNum} tile " . ($idx + 1) . " rejected as vision-unavailable response.");
                            }
                            @unlink($tPath);
                        }
                        $tileText = implode("\n\n", $tileContents);
                    }

                    if ($effectiveMode === 'all' || $effectiveMode === 'slices') {
                        $slices = generateHorizontalSlices($mainImg, $projectDir, "slice_{$docId}_{$pageNum}");
                        $sliceContents = [];
                        $totalSlices = count($slices);
                        foreach ($slices as $idx => $sPath) {
                            abortIfUploadCancelled($pdo, $destPath, $pageNum - 0.35, $totalPages);
                            updateProgress('processing', 'extraction', $pageNum - 0.3, $totalPages, "P.{$pageNum} スライス分割解析中... (" . ($idx+1) . "/{$totalSlices})");
                            $sliceResult = callVLM($sPath, $ollamaHost, $vlmModel, "この水平分割画像内の文字（横書きの表や文章など）を左から右へ正確に書き起こしてください。");
                            abortIfUploadCancelled($pdo, $destPath, $pageNum - 0.3, $totalPages);
                            if ($sliceResult && !isVisionUnavailableResponse($sliceResult)) {
                                $sliceContents[] = "--- 水平区画" . ($idx+1) . " ---\n" . $sliceResult;
                            } elseif ($sliceResult) {
                                logger("[VLM-FALLBACK] P.{$pageNum} slice " . ($idx + 1) . " rejected as vision-unavailable response.");
                            }
                            @unlink($sPath);
                        }
                        $sliceText = implode("\n\n", $sliceContents);
                    }

                    if ($effectiveMode === 'all' || $effectiveMode === 'full') {
                        abortIfUploadCancelled($pdo, $destPath, $pageNum - 0.25, $totalPages);
                        updateProgress('processing', 'extraction', $pageNum - 0.2, $totalPages, "P.{$pageNum} ページ全体のVLM解析中...");
                        $fullText = callVLM($mainImg, $ollamaHost, $vlmModel, "この画像に含まれる本文、図、表、写真、注記、凡例の要点をRAG検索に使える形で整理して書き起こしてください。");
                        abortIfUploadCancelled($pdo, $destPath, $pageNum - 0.2, $totalPages);
                        if (isVisionUnavailableResponse($fullText)) {
                            logger("[VLM-FALLBACK] P.{$pageNum} full-page analysis rejected as vision-unavailable response.");
                            $fullText = "";
                        }
                    }

                    @unlink($mainImg);

                    $combineParts = [];
                    if ($analysisMode === 'auto' && $textCharCount > 0) {
                        $combineParts[] = "【電子テキスト本文】\n" . $rawText;
                    }
                    if ($tileText)  $combineParts[] = "【2x2 タイル詳細】\n" . $tileText;
                    if ($sliceText) $combineParts[] = "【1x8 水平スライス詳細】\n" . $sliceText;
                    if ($fullText)  $combineParts[] = "【ページ全体要約・文字起こし】\n" . $fullText;

                    $rawCombinedText = implode("\n\n", $combineParts);
                    if ($rawCombinedText === '' && $textCharCount > 0) {
                        logger("[VLM-FALLBACK] P.{$pageNum} visual extraction yielded no usable text. Fallback to native text.");
                        $rawCombinedText = "【電子テキスト】\n" . $rawText;
                    }

                    if (count($combineParts) > 1) {
                        abortIfUploadCancelled($pdo, $destPath, $pageNum - 0.15, $totalPages);
                        updateProgress('processing', 'extraction', $pageNum - 0.1, $totalPages, "P.{$pageNum} LLMによる重複排除とテキスト整理中...");
                        $organizePrompt = "以下のテキストは、同一のページ画像を異なる分割方法でOCR読み取りした結果の集まりです。\n"
                                        . "内容を比較・統合し、重複している部分を排除して、抜け漏れがないように整理された1つの完全なマークダウンテキストを作成してください。\n\n"
                                        . $rawCombinedText;

                        $organizedText = callLLM($ollamaHost, $textModel, $organizePrompt);
                        abortIfUploadCancelled($pdo, $destPath, $pageNum - 0.1, $totalPages);
                        $combinedText = !empty($organizedText) ? $organizedText : $rawCombinedText;
                    } else {
                        $combinedText = $rawCombinedText;
                    }

                    $finalImageDesc = buildImageDescriptionForStorage($overview, $effectiveMode, $pageImageCount);
                }
            }
        }

        if (!empty($combinedText)) {
            updateProgress('processing', 'storing', $pageNum, $totalPages, "P.{$pageNum} テキストのベクトル化と保存を実行中...");
            
            $chunks = splitTextIntoChunks($combinedText, 500);
            $totalChunks = count($chunks);

            foreach ($chunks as $chunkIdx => $chunkText) {
                if (empty(trim($chunkText))) continue;
                abortIfUploadCancelled($pdo, $destPath, $pageNum, $totalPages);

                $safeTextForEmbedding = mb_substr(preg_replace('/\s+/', ' ', $chunkText), 0, 300);
                
                try {
                    // ★ 変更: GPUに完全オフロードする embedWithRetry 関数を使用
                    $emb = $embedWithRetry($safeTextForEmbedding, "P.{$pageNum} Chunk " . ($chunkIdx + 1));
                    abortIfUploadCancelled($pdo, $destPath, $pageNum, $totalPages);
                    updateProgress('processing', 'storing', $pageNum, $totalPages, "P.{$pageNum} データベースへ保存中... (" . ($chunkIdx + 1) . "/{$totalChunks})");
                    $stmtChunk->execute([$docId, $pageNum, $chunkText, json_encode($emb), $finalImageDesc]);
                } catch (Exception $e) {
                    logger("Embedding Skip P.{$pageNum} Chunk " . ($chunkIdx + 1) . ": " . $e->getMessage());
                    $stmtChunk->execute([$docId, $pageNum, $chunkText, json_encode([]), $finalImageDesc]);
                }
            }
        }
        gc_collect_cycles();
    }

    if (!empty($allPageOverviews)) {
        abortIfUploadCancelled($pdo, $destPath, $totalPages, $totalPages);
        updateProgress('processing', 'summary', $totalPages, $totalPages, "最終処理: 資料全体の要約をLLMで生成中...");
        $summaryPrompt = "以下の内容は、ある資料の各ページごとの概要です。これらを統合し、資料全体がどのような構成で、どのような目的・内容が書かれているか、全体の要約（目次情報や主要なトピック）を500文字程度で論理的にまとめてください。\n\n"
                       . implode("\n", $allPageOverviews);

        $summaryText = callLLM($ollamaHost, $textModel, $summaryPrompt);
        abortIfUploadCancelled($pdo, $destPath, $totalPages, $totalPages);
        if (!empty($summaryText)) {
            updateProgress('processing', 'summary', $totalPages, $totalPages, "最終処理: 要約のベクトル化と保存中...");
            $safeSummaryText = mb_substr($summaryText, 0, 300);
            try {
                // ★ 変更: GPUに完全オフロードする embedWithRetry 関数を使用
                $embSummary = $embedWithRetry($safeSummaryText, "Summary");
                abortIfUploadCancelled($pdo, $destPath, $totalPages, $totalPages);
                $stmtChunk->execute([$docId, 0, $summaryText, json_encode($embSummary), "資料全体の要約・構成情報"]);
            } catch (Exception $e) {
                $stmtChunk->execute([$docId, 0, $summaryText, json_encode([]), "資料全体の要約・構成情報"]);
            }
        }
    }

    $pdo->commit();
    updateProgress('completed', 'done', $totalPages, $totalPages, '解析完了');
    
    $junk = ob_get_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => true, 'pages' => $totalPages, 'mode' => $analysisMode]);
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) $pdo->rollBack();
    ob_get_clean();
    
    if ($e->getMessage() === 'USER_CANCELLED') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'error' => 'ユーザーにより解析が中断されました。']);
        exit;
    }
    
    logger("FATAL: " . $e->getMessage());
    appLog('app_error.log', $e->getMessage(), [
        'api' => 'upload',
        'exception' => get_class($e),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    $publicError = appDebugEnabled() ? $e->getMessage() : '解析中にエラーが発生しました。ログを確認してください。';
    updateProgress('error', 'error', 0, 0, '解析失敗', $publicError);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['success' => false, 'error' => $publicError]);
    exit;
}
