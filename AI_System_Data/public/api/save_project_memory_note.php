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
    $manualTodo = trim((string)($docs['todo']['content'] ?? ''));
    $nowLabel = date('Y-m-d H:i');
    $questionLine = csvMemoryNormalizeLine($question);
    $summaryLine = csvMemorySummarizeAnswer($answer);

    $appendLines = ["## CSV読解要点 {$nowLabel}"];
    if ($questionLine !== '') {
        $appendLines[] = "- 質問: {$questionLine}";
    }
    if ($summaryLine !== '') {
        $appendLines[] = "- 要点: {$summaryLine}";
    }
    if ($csvExportName !== '') {
        $appendLines[] = "- 生成CSV: " . csvMemoryNormalizeLine($csvExportName);
    }

    $appendBlock = implode("\n", $appendLines);
    $docs['todo']['content'] = $manualTodo !== ''
        ? rtrim($manualTodo) . "\n\n" . $appendBlock
        : $appendBlock;

    ProjectContextMemory::save($pdo, (int)$projectId, [
        'agents' => (string)($docs['agents']['content'] ?? ''),
        'readme' => (string)($docs['readme']['content'] ?? ''),
        'todo' => (string)$docs['todo']['content'],
    ]);

    echo json_encode([
        'success' => true,
        'saved_note' => $appendBlock,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('save_project_memory_note.php Error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => '案件運用メモへの反映に失敗しました。']);
}
