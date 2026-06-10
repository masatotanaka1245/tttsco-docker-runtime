<?php
// AI_System_Data/public/api/view_pdf.php
// (元: public/serve_pdf.php)

// 予期せぬ空白文字やWarningがPDFデータに混ざるのを防ぐ
if (ob_get_length()) ob_clean();

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

if (!function_exists('streamPdfFile')) {
    function streamPdfFile(string $fullPath): void
    {
        $fileSize = filesize($fullPath);
        if ($fileSize === false) {
            http_response_code(500);
            exit('Failed to determine file size');
        }

        $start = 0;
        $end = $fileSize - 1;
        $length = $fileSize;

        header('Accept-Ranges: bytes');

        $rangeHeader = $_SERVER['HTTP_RANGE'] ?? '';
        if ($rangeHeader !== '' && preg_match('/bytes=(\d*)-(\d*)/i', $rangeHeader, $matches)) {
            $rangeStart = $matches[1];
            $rangeEnd = $matches[2];

            if ($rangeStart === '' && $rangeEnd === '') {
                http_response_code(416);
                header("Content-Range: bytes */{$fileSize}");
                exit;
            }

            if ($rangeStart === '') {
                $suffixLength = (int)$rangeEnd;
                if ($suffixLength > 0) {
                    $start = max(0, $fileSize - $suffixLength);
                }
            } else {
                $start = (int)$rangeStart;
            }

            if ($rangeEnd !== '') {
                $end = min((int)$rangeEnd, $fileSize - 1);
            }

            if ($start > $end || $start >= $fileSize) {
                http_response_code(416);
                header("Content-Range: bytes */{$fileSize}");
                exit;
            }

            $length = $end - $start + 1;
            http_response_code(206);
            header("Content-Range: bytes {$start}-{$end}/{$fileSize}");
        }

        header('Content-Length: ' . $length);

        $handle = fopen($fullPath, 'rb');
        if ($handle === false) {
            http_response_code(500);
            exit('Failed to open PDF file');
        }

        if ($start > 0) {
            fseek($handle, $start);
        }

        $remaining = $length;
        $chunkSize = 8192;

        while (!feof($handle) && $remaining > 0) {
            $readLength = min($chunkSize, $remaining);
            $buffer = fread($handle, $readLength);
            if ($buffer === false) {
                break;
            }
            echo $buffer;
            flush();
            $remaining -= strlen($buffer);
        }

        fclose($handle);
    }
}

if (!function_exists('viewPdfLog')) {
    function viewPdfLog(string $message, array $context = []): void
    {
        appLog('pdf_view.log', $message, $context);
    }
}

$auth = new Auth($pdo);
if (!$auth->isLoggedIn()) {
    viewPdfLog('unauthorized access', [
        'id' => filter_input(INPUT_GET, 'id', FILTER_UNSAFE_RAW),
        'user_id' => $_SESSION['user_id'] ?? null,
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'range' => $_SERVER['HTTP_RANGE'] ?? '',
    ]);
    http_response_code(401);          // 未認証
    exit('Unauthorized');
}

$id = filter_input(INPUT_GET, 'id', FILTER_VALIDATE_INT);
if (!$id) {
    viewPdfLog('invalid id', [
        'raw_id' => $_GET['id'] ?? null,
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
    ]);
    http_response_code(400);          // ID が不正
    exit('Bad Request');
}

// ファイルパスに加えて、画面表示用の「タイトル(日本語)」も取得する
$stmt = $pdo->prepare("SELECT project_id, title, file_path FROM documents WHERE id = ?");
$stmt->execute([$id]);
$doc = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$doc) {
    viewPdfLog('document not found in database', [
        'id' => $id,
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
    ]);
    http_response_code(404);          // ドキュメントが存在しない
    exit('Document not found in database');
}

if (!canAccessProject($pdo, (int)$doc['project_id'], (int)$_SESSION['user_id'], $_SESSION['role'] ?? 'user')) {
    viewPdfLog('forbidden document access', [
        'id' => $id,
        'project_id' => (int)$doc['project_id'],
        'user_id' => (int)($_SESSION['user_id'] ?? 0),
        'role' => $_SESSION['role'] ?? '',
    ]);
    http_response_code(403);
    exit('Forbidden');
}

/* ① ファイルパスを絶対パスで解決（複数の保存流儀を吸収） */
$publicBaseDir = realpath(__DIR__ . '/..');
$allowedBaseDir = $publicBaseDir !== false ? realpath($publicBaseDir . DIRECTORY_SEPARATOR . '01_RAG_Documents') : false;
$fullPath = $publicBaseDir !== false
    ? resolveDocumentAbsolutePath((string)$doc['file_path'], $publicBaseDir)
    : null;

// セキュリティ: パストラバーサル対策 ＆ ファイル存在チェック
if ($fullPath === null || $allowedBaseDir === false || strpos($fullPath, $allowedBaseDir) !== 0 || !file_exists($fullPath)) {
    viewPdfLog('file path resolution failed', [
        'id' => $id,
        'file_path' => (string)$doc['file_path'],
        'resolved_path' => $fullPath,
        'public_base' => $publicBaseDir,
        'allowed_base' => $allowedBaseDir,
        'uri' => $_SERVER['REQUEST_URI'] ?? '',
        'host' => $_SERVER['HTTP_HOST'] ?? '',
        'range' => $_SERVER['HTTP_RANGE'] ?? '',
    ]);
    http_response_code(404);          // ファイルが見つからない、または不正アクセス
    exit('File not found on server or access denied');
}

viewPdfLog('stream started', [
    'id' => $id,
    'title' => (string)($doc['title'] ?? ''),
    'file_path' => (string)$doc['file_path'],
    'resolved_path' => $fullPath,
    'host' => $_SERVER['HTTP_HOST'] ?? '',
    'uri' => $_SERVER['REQUEST_URI'] ?? '',
    'range' => $_SERVER['HTTP_RANGE'] ?? '',
]);

/* ② ヘッダー設定 */
header('Content-Type: application/pdf');

// 日本語のタイトル名でブラウザや保存ダイアログに表示させるためのエンコード処理
$displayTitle = !empty($doc['title']) ? $doc['title'] : basename($fullPath);
$encodedTitle = urlencode($displayTitle);
header('Content-Disposition: inline; filename*=UTF-8\'\'' . $encodedTitle);

header('Cache-Control: private, max-age=0, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');
header('X-Content-Type-Options: nosniff');

/* ③ ファイル送信 */
streamPdfFile($fullPath);
exit;
