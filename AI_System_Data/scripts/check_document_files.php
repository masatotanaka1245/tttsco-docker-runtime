<?php

declare(strict_types=1);

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../src/AppLogger.php';

function normalizeDocumentPath(string $path): string
{
    return str_replace('\\', '/', trim($path));
}

function resolveDocumentAbsolutePath(string $filePath, string $publicBaseDir): ?string
{
    $trimmed = trim($filePath);
    if ($trimmed === '') {
        return null;
    }

    $normalized = normalizeDocumentPath($trimmed);
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

    $basename = basename($normalized);
    if ($basename === '' || $basename === '.' || $basename === '..') {
        return null;
    }

    $searchRoots = [
        $publicBaseDir . DIRECTORY_SEPARATOR . '01_RAG_Documents',
        $projectRoot . DIRECTORY_SEPARATOR . '01_RAG_Documents',
    ];

    foreach (array_unique($searchRoots) as $root) {
        $rootReal = realpath($root);
        if ($rootReal === false || !is_dir($rootReal)) {
            continue;
        }

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($rootReal, FilesystemIterator::SKIP_DOTS)
            );
            foreach ($iterator as $fileInfo) {
                if ($fileInfo->isFile() && strcasecmp($fileInfo->getFilename(), $basename) === 0) {
                    return $fileInfo->getRealPath() ?: null;
                }
            }
        } catch (Throwable $e) {
            appLog('chat_debug.log', '[DOC-INTEGRITY] resolver_error', [
                'root' => $root,
                'error' => $e->getMessage(),
            ]);
        }
    }

    return null;
}

function detectHtmlFallback(string $filePath, string $publicBaseDir): ?string
{
    $normalized = normalizeDocumentPath($filePath);
    if (!str_ends_with(strtolower($normalized), '.pdf')) {
        return null;
    }

    $htmlPath = preg_replace('/\.pdf$/i', '.html', $normalized);
    if (!is_string($htmlPath) || $htmlPath === '') {
        return null;
    }

    return resolveDocumentAbsolutePath($htmlPath, $publicBaseDir);
}

$basePath = realpath(__DIR__ . '/..');
if ($basePath === false) {
    fwrite(STDERR, "Base path could not be resolved.\n");
    exit(1);
}

$publicBaseDir = $basePath . DIRECTORY_SEPARATOR . 'public';
$stmt = $pdo->query("
    SELECT id, project_id, title, file_path, created_at
    FROM documents
    WHERE file_path LIKE '%.pdf'
    ORDER BY id ASC
");

$okCount = 0;
$missingCount = 0;
$htmlFallbackCount = 0;

foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $documentId = (int)($row['id'] ?? 0);
    $projectId = (int)($row['project_id'] ?? 0);
    $filePath = (string)($row['file_path'] ?? '');
    $resolvedPath = resolveDocumentAbsolutePath($filePath, $publicBaseDir);

    if ($resolvedPath !== null) {
        $size = filesize($resolvedPath) ?: 0;
        $okCount++;
        $line = "[OK] document_id={$documentId} project_id={$projectId} file_path={$filePath} size={$size}";
        echo $line . PHP_EOL;
        appLog('chat_debug.log', '[DOC-INTEGRITY] ok', [
            'document_id' => $documentId,
            'project_id' => $projectId,
            'path' => $filePath,
            'resolved_path' => $resolvedPath,
            'size' => $size,
        ]);
        continue;
    }

    $htmlFallback = detectHtmlFallback($filePath, $publicBaseDir);
    $hasHtmlFallback = $htmlFallback !== null;
    if ($hasHtmlFallback) {
        $htmlFallbackCount++;
    }
    $missingCount++;

    $line = "[MISSING] document_id={$documentId} project_id={$projectId} file_path={$filePath} html_fallback=" . ($hasHtmlFallback ? '1' : '0');
    echo $line . PHP_EOL;
    appLog('chat_debug.log', '[DOC-INTEGRITY] missing_file', [
        'document_id' => $documentId,
        'project_id' => $projectId,
        'path' => $filePath,
        'html_fallback' => $hasHtmlFallback ? 1 : 0,
        'html_path' => $htmlFallback,
    ]);
}

$summary = "Summary: ok={$okCount} missing={$missingCount} html_fallback={$htmlFallbackCount}";
echo $summary . PHP_EOL;
appLog('chat_debug.log', '[DOC-INTEGRITY] summary', [
    'ok' => $okCount,
    'missing' => $missingCount,
    'html_fallback' => $htmlFallbackCount,
]);
