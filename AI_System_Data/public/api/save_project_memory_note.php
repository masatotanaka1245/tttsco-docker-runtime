<?php

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/ProjectAccess.php';
require_once __DIR__ . '/../../src/ProjectContextMemory.php';

header('Content-Type: application/json; charset=utf-8');

function csvMemoryNormalizeLine(string $line): string
{
    $line = trim($line);
    if ($line === '') {
        return '';
    }

    $line = preg_replace('/^#{1,6}\s*/u', '', $line);
    $line = preg_replace('/^\s*[-*+]\s+/u', '', $line);
    $line = preg_replace('/^\s*\d+[.)]\s+/u', '', $line);
    $line = preg_replace('/[`>*_~]/u', '', $line);
    $line = preg_replace('/\s+/u', ' ', (string)$line);

    return trim((string)$line);
}

function csvMemorySummarizeAnswer(string $answer): string
{
    $answer = preg_replace('/```[\s\S]*?```/u', ' ', $answer);
    $lines = preg_split('/\R/u', $answer) ?: [];
    $picked = [];

    foreach ($lines as $line) {
        $trimmed = trim((string)$line);
        if ($trimmed === '') {
            continue;
        }
        if (str_starts_with($trimmed, '|')) {
            continue;
        }
        if (preg_match('/^\|(?:\s*:?-+:?\s*\|)+$/u', $trimmed)) {
            continue;
        }
        if (preg_match('/^(📊|📑|###?\s*【|---+$)/u', $trimmed)) {
            continue;
        }

        $normalized = csvMemoryNormalizeLine($trimmed);
        if ($normalized === '') {
            continue;
        }

        $picked[] = $normalized;
        if (count($picked) >= 4) {
            break;
        }
    }

    $summary = trim(implode(' / ', $picked));
    if ($summary === '') {
        $summary = csvMemoryNormalizeLine(mb_substr(trim($answer), 0, 320));
    }

    return mb_strlen($summary) > 320 ? mb_substr($summary, 0, 320) . '...' : $summary;
}

function csvMemoryExtractFileNames(string $question, string $answer, string $csvExportName = ''): array
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

function csvMemoryBuildTags(string $question, string $answer, array $fileNames = []): array
{
    $text = mb_strtolower($question . "\n" . $answer);
    $tags = ['csv読解'];

    $keywordMap = [
        '集計' => ['集計', 'count', '件数'],
        '要約' => ['要約', '概要', 'サマリー'],
        '月別' => ['月別', 'yearmonth', 'month'],
        '日別' => ['日別', 'date', '日次'],
        'ランキング' => ['ランキング', '上位', '順位'],
        'カテゴリ' => ['カテゴリ', '分類', 'category'],
        '時系列' => ['推移', '時系列', 'trend'],
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

function csvMemorySuggestNextAction(string $question, string $answerSummary): string
{
    $text = mb_strtolower($question . "\n" . $answerSummary);

    if (preg_match('/(列|カラム).*(指定|選択)|グラフ/u', $text)) {
        return '対象列やグラフ化条件を先に確定してから再実行します。';
    }
    if (preg_match('/(統合|転記|結合|マージ|取り込)/u', $text)) {
        return '統合先の列定義と対応関係を確認したうえで、CSV統合モーダルまたは転記手順へ進みます。';
    }
    if (preg_match('/(pdf|資料|ページ|図表|画像)/u', $text)) {
        return '資料本文とページ根拠を確認し、必要なら対象ページを絞って再確認します。';
    }

    return '今回の要点をもとに、必要な追加条件や確認事項を整理します。';
}

function csvMemoryDetectTargetDoc(string $question, string $answerSummary, array $tags, array $fileNames): string
{
    $text = mb_strtolower($question . "\n" . $answerSummary . "\n" . implode(' ', $tags));

    if (preg_match('/(回答方針|優先|禁止|ルール|扱う|参照方針|相談モード|確認質問)/u', $question . "\n" . $answerSummary)) {
        return 'agents';
    }

    if (
        $fileNames !== []
        || preg_match('/(対象csv|対象pdf|列構成|カラム構成|スキーマ|前提|用語|保有データ|資料構成|データ構成)/u', $question . "\n" . $answerSummary)
    ) {
        return 'readme';
    }

    if (preg_match('/(次アクション|確認|対応|修正|実行|統合|転記|グラフ|集計|要約)/u', $text)) {
        return 'todo';
    }

    return 'todo';
}

function csvMemoryBuildStructuredNote(string $question, string $answer, string $csvExportName, array $fileNames, array $tags): string
{
    $nowLabel = date('Y-m-d H:i');
    $questionLine = csvMemoryNormalizeLine($question);
    $summaryLine = csvMemorySummarizeAnswer($answer);
    $nextAction = csvMemorySuggestNextAction($question, $summaryLine);

    $appendLines = ["## 運用メモ {$nowLabel}"];
    if ($questionLine !== '') {
        $appendLines[] = "- 質問: {$questionLine}";
    }
    if ($summaryLine !== '') {
        $appendLines[] = "- 要点: {$summaryLine}";
    }
    if ($nextAction !== '') {
        $appendLines[] = "- 次アクション: {$nextAction}";
    }
    if ($csvExportName !== '') {
        $appendLines[] = "- 生成CSV: " . csvMemoryNormalizeLine($csvExportName);
    }
    if ($fileNames !== []) {
        $appendLines[] = "- 対象CSV: " . implode(' / ', array_map('csvMemoryNormalizeLine', $fileNames));
    }
    if ($tags !== []) {
        $appendLines[] = "- タグ: " . implode(' ', array_map(static fn(string $tag): string => '#' . $tag, $tags));
    }

    return implode("\n", $appendLines);
}

function csvMemoryBuildSignature(string $question, array $fileNames = [], string $summary = ''): string
{
    $base = mb_strtolower(csvMemoryNormalizeLine($question));
    $base .= '|' . implode('|', array_map(static fn(string $name): string => mb_strtolower(csvMemoryNormalizeLine($name)), $fileNames));
    if ($summary !== '') {
        $base .= '|' . mb_strtolower(csvMemoryNormalizeLine($summary));
    }
    $base = preg_replace('/\s+/u', '', $base) ?? $base;
    return trim((string)$base);
}

function csvMemoryAlreadyCaptured(string $existingContent, string $signature): bool
{
    if ($signature === '') {
        return false;
    }

    $normalized = mb_strtolower($existingContent);
    $normalized = preg_replace('/\s+/u', '', $normalized) ?? $normalized;
    return str_contains($normalized, $signature);
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
    echo json_encode(['success' => false, 'error' => '案件運用メモを更新する権限がありません。']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$projectId = filter_var($input['project_id'] ?? null, FILTER_VALIDATE_INT);
$question = trim((string)($input['question'] ?? ''));
$answer = trim((string)($input['answer'] ?? ''));
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

try {
    $docs = ProjectContextMemory::load($pdo, (int)$projectId);
    $fileNames = csvMemoryExtractFileNames($question, $answer, $csvExportName);
    $tags = csvMemoryBuildTags($question, $answer, $fileNames);
    $summaryLine = csvMemorySummarizeAnswer($answer);
    $targetDoc = csvMemoryDetectTargetDoc($question, $summaryLine, $tags, $fileNames);
    $appendBlock = csvMemoryBuildStructuredNote($question, $answer, $csvExportName, $fileNames, $tags);
    $signature = csvMemoryBuildSignature($question, $fileNames, $summaryLine);
    $manualContent = trim((string)($docs[$targetDoc]['content'] ?? ''));

    if (csvMemoryAlreadyCaptured($manualContent, $signature)) {
        echo json_encode([
            'success' => true,
            'saved_note' => '',
            'skipped_duplicate' => true,
            'target_doc' => $targetDoc,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $docs[$targetDoc]['content'] = $manualContent !== ''
        ? rtrim($manualContent) . "\n\n" . $appendBlock
        : $appendBlock;

    ProjectContextMemory::save($pdo, (int)$projectId, [
        'agents' => (string)($docs['agents']['content'] ?? ''),
        'readme' => (string)($docs['readme']['content'] ?? ''),
        'todo' => (string)$docs['todo']['content'],
    ]);

    echo json_encode([
        'success' => true,
        'saved_note' => $appendBlock,
        'target_doc' => $targetDoc,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('save_project_memory_note.php Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '案件運用メモへの反映に失敗しました。']);
}
