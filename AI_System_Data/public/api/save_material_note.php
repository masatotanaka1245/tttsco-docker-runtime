<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/ProjectAccess.php';
require_once __DIR__ . '/../../src/ProjectMaterialDocumentService.php';

header('Content-Type: application/json; charset=utf-8');

function materialNoteNormalizeLine(string $line): string
{
    $line = trim($line);
    if ($line === '') {
        return '';
    }

    $line = preg_replace('/[`>*_~]/u', '', $line);
    $line = preg_replace('/\s+/u', ' ', (string)$line);
    return trim((string)$line);
}

function materialNoteExtractFileNames(string $question, string $answer, string $csvExportName = ''): array
{
    $sources = [$question, $answer];
    if ($csvExportName !== '') {
        $sources[] = $csvExportName;
    }

    $fileNames = [];
    foreach ($sources as $source) {
        if (preg_match_all('/([A-Za-z0-9._-]+\.csv|[^"\s、。]+\.csv)/u', $source, $matches)) {
            foreach (($matches[1] ?? []) as $match) {
                $normalized = trim((string)$match, " \t\n\r\0\x0B\"'“”‘’");
                if ($normalized === '' || in_array($normalized, $fileNames, true)) {
                    continue;
                }
                $fileNames[] = $normalized;
                if (count($fileNames) >= 3) {
                    break 2;
                }
            }
        }
    }

    return $fileNames;
}

function materialNoteBuildTags(string $question, string $answer, array $fileNames = []): array
{
    $text = mb_strtolower($question . "\n" . $answer);
    $tags = ['資料メモ'];

    $keywordMap = [
        'csv読解' => ['csv', '集計', '件数', 'ランキング', 'カラム', '列'],
        '要約' => ['要約', '概要', 'サマリー'],
        '提案' => ['提案', '改善', '案'],
        '月別' => ['月別', 'yearmonth', 'month'],
        '日別' => ['日別', 'date', '日次'],
        'カテゴリ' => ['カテゴリ', '分類', 'category'],
    ];

    foreach ($keywordMap as $tag => $keywords) {
        foreach ($keywords as $keyword) {
            if (mb_stripos($text, mb_strtolower($keyword)) !== false) {
                $tags[] = $tag;
                break;
            }
        }
    }

    foreach ($fileNames as $fileName) {
        $base = pathinfo($fileName, PATHINFO_FILENAME);
        $base = preg_replace('/[^A-Za-z0-9一-龠ぁ-んァ-ヶー]+/u', '_', (string)$base) ?? '';
        $base = trim((string)$base, '_');
        if ($base === '') {
            continue;
        }
        $tags[] = 'csv:' . mb_substr($base, 0, 24);
    }

    $tags = array_values(array_unique(array_filter(array_map(static fn($tag): string => trim((string)$tag), $tags))));
    return array_slice($tags, 0, 8);
}

function materialNoteBuildCsvTitle(string $question, array $fileNames = []): string
{
    $dateLabel = date('Ymd');
    $subject = '';

    if ($fileNames !== []) {
        $subject = pathinfo((string)$fileNames[0], PATHINFO_FILENAME);
    }
    if ($subject === '') {
        $subject = materialNoteNormalizeLine($question);
    }

    $subject = preg_replace('/[\\\/:*?"<>|#]+/u', ' ', (string)$subject) ?? (string)$subject;
    $subject = preg_replace('/\s+/u', ' ', (string)$subject) ?? (string)$subject;
    $subject = trim((string)$subject);
    if ($subject !== '') {
        $subject = mb_substr($subject, 0, 32);
        return "CSV読解メモ_{$dateLabel}_{$subject}";
    }

    return "CSV読解メモ_{$dateLabel}";
}

function materialNoteBuildGeneralTitle(string $question): string
{
    $dateLabel = date('Ymd');
    $subject = materialNoteNormalizeLine($question);
    $subject = preg_replace('/[\\\/:*?"<>|#]+/u', ' ', (string)$subject) ?? (string)$subject;
    $subject = preg_replace('/\s+/u', ' ', (string)$subject) ?? (string)$subject;
    $subject = trim((string)$subject);
    if ($subject !== '') {
        $subject = mb_substr($subject, 0, 32);
        return "AI資料メモ_{$dateLabel}_{$subject}";
    }

    return "AI資料メモ_{$dateLabel}";
}

$auth = new Auth($pdo);
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'ログインが必要です。']);
    exit;
}

$csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($csrfHeader) || !isset($_SESSION['csrf_token']) || $csrfHeader !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRFトークンが正しくありません。']);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? 'user');

if ($role !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => '資料メモを更新する権限がありません。']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$projectId = filter_var($input['project_id'] ?? null, FILTER_VALIDATE_INT);
$documentId = filter_var($input['material_document_id'] ?? null, FILTER_VALIDATE_INT) ?: null;
$question = trim((string)($input['question'] ?? ''));
$answer = trim((string)($input['answer'] ?? ''));
$title = trim((string)($input['title'] ?? ''));
$sourceKind = trim((string)($input['source_kind'] ?? 'general_ai_answer'));
$csvExportName = trim((string)($input['csv_export_name'] ?? ''));

if (!$projectId || !canAccessProject($pdo, (int)$projectId, $userId, $role)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'この案件へアクセスする権限がありません。']);
    exit;
}

if ($answer === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'error' => '保存対象の回答が空です。']);
    exit;
}

$service = new ProjectMaterialDocumentService($pdo, dirname(__DIR__, 2));

try {
    $existingDocument = $documentId ? $service->findById((int)$projectId, (int)$documentId) : null;
    $nowLabel = date('Y-m-d H:i');
    $sectionTitle = $sourceKind === 'csv_analysis' ? 'CSV読解メモ' : 'AI回答';
    $fileNames = $sourceKind === 'csv_analysis'
        ? materialNoteExtractFileNames($question, $answer, $csvExportName)
        : [];
    $appendBlock = "## {$sectionTitle} {$nowLabel}\n\n";
    if ($question !== '') {
        $appendBlock .= "### 質問\n\n{$question}\n\n";
    }
    $appendBlock .= "### 回答\n\n{$answer}\n";
    if ($sourceKind === 'csv_analysis') {
        $tags = materialNoteBuildTags($question, $answer, $fileNames);
        if ($fileNames !== []) {
            $appendBlock .= "\n### 対象CSV\n\n- " . implode("\n- ", array_map('materialNoteNormalizeLine', $fileNames)) . "\n";
        }
        if ($tags !== []) {
            $appendBlock .= "\n### 検索タグ\n\n" . implode(' ', array_map(static fn(string $tag): string => '#' . $tag, $tags)) . "\n";
        }
    }

    if ($existingDocument) {
        $baseContent = $service->readContent((string)$existingDocument['file_path'], (int)($existingDocument['id'] ?? 0));
        $nextContent = trim($baseContent) !== ''
            ? rtrim($baseContent) . "\n\n" . $appendBlock
            : '# ' . trim((string)$existingDocument['title']) . "\n\n" . $appendBlock;
        $saved = $service->save((int)$projectId, (string)$existingDocument['title'], $nextContent, (int)$existingDocument['id']);
        $created = false;
    } else {
        if ($title !== '') {
            $resolvedTitle = $title;
        } elseif ($sourceKind === 'csv_analysis') {
            $resolvedTitle = materialNoteBuildCsvTitle($question, $fileNames);
        } else {
            $resolvedTitle = materialNoteBuildGeneralTitle($question);
        }
        $nextContent = '# ' . $resolvedTitle . "\n\n" . $appendBlock;
        $saved = $service->save((int)$projectId, $resolvedTitle, $nextContent, null);
        $created = true;
    }

    echo json_encode([
        'success' => true,
        'created' => $created,
        'material_document' => [
            'document_id' => (int)$saved['document_id'],
            'title' => (string)$saved['title'],
            'file_path' => (string)$saved['file_path'],
            'modified_at' => (string)($saved['modified_at'] ?? ''),
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('save_material_note.php Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '資料メモの保存に失敗しました。']);
}
