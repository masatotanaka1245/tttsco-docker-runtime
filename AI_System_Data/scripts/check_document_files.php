<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/AppLogger.php';
require_once __DIR__ . '/../src/DocumentFileIntegrityChecker.php';

$basePath = realpath(__DIR__ . '/..');
if ($basePath === false) {
    fwrite(STDERR, "Base path could not be resolved.\n");
    exit(1);
}

$checker = new DocumentFileIntegrityChecker($pdo, $basePath);
$result = $checker->scanPdfDocuments();

foreach ($result['records'] as $record) {
    $documentId = (int)($record['document_id'] ?? 0);
    $projectId = (int)($record['project_id'] ?? 0);
    $filePath = (string)($record['file_path'] ?? '');

    if (($record['status'] ?? '') === 'ok') {
        $size = (int)($record['size'] ?? 0);
        $line = "[OK] document_id={$documentId} project_id={$projectId} file_path={$filePath} size={$size}";
        echo $line . PHP_EOL;
        appLog('chat_debug.log', '[DOC-INTEGRITY] ok', [
            'document_id' => $documentId,
            'project_id' => $projectId,
            'path' => $filePath,
            'resolved_path' => $record['resolved_path'] ?? null,
            'size' => $size,
        ]);
        continue;
    }

    $hasHtmlFallback = !empty($record['html_fallback']);
    $line = "[MISSING] document_id={$documentId} project_id={$projectId} file_path={$filePath} html_fallback=" . ($hasHtmlFallback ? '1' : '0');
    echo $line . PHP_EOL;
    appLog('chat_debug.log', '[DOC-INTEGRITY] missing_file', [
        'document_id' => $documentId,
        'project_id' => $projectId,
        'path' => $filePath,
        'html_fallback' => $hasHtmlFallback ? 1 : 0,
        'html_path' => $record['html_fallback_path'] ?? null,
    ]);
}

$summary = sprintf(
    'Summary: ok=%d missing=%d html_fallback=%d',
    (int)($result['summary']['ok'] ?? 0),
    (int)($result['summary']['missing'] ?? 0),
    (int)($result['summary']['html_fallback'] ?? 0)
);
echo $summary . PHP_EOL;
appLog('chat_debug.log', '[DOC-INTEGRITY] summary', [
    'ok' => (int)($result['summary']['ok'] ?? 0),
    'missing' => (int)($result['summary']['missing'] ?? 0),
    'html_fallback' => (int)($result['summary']['html_fallback'] ?? 0),
]);
