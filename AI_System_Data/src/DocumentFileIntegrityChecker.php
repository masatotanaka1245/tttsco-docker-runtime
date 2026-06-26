<?php

declare(strict_types=1);

final class DocumentFileIntegrityChecker
{
    private PDO $pdo;
    private string $basePath;
    private string $publicBaseDir;
    private string $projectRoot;

    public function __construct(PDO $pdo, string $basePath)
    {
        $this->pdo = $pdo;
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
        $this->publicBaseDir = $this->basePath . DIRECTORY_SEPARATOR . 'public';
        $this->projectRoot = dirname($this->publicBaseDir);
    }

    public function scanPdfDocuments(): array
    {
        $stmt = $this->pdo->query("
            SELECT id, project_id, title, file_path, created_at
            FROM documents
            WHERE file_path LIKE '%.pdf'
            ORDER BY id ASC
        ");

        $records = [];
        $summary = [
            'total' => 0,
            'ok' => 0,
            'missing' => 0,
            'html_fallback' => 0,
        ];

        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $documentId = (int)($row['id'] ?? 0);
            $projectId = (int)($row['project_id'] ?? 0);
            $title = (string)($row['title'] ?? '');
            $filePath = (string)($row['file_path'] ?? '');
            $createdAt = (string)($row['created_at'] ?? '');
            $resolvedPath = $this->resolveDocumentAbsolutePath($filePath);
            $htmlFallbackPath = $this->detectHtmlFallback($filePath);
            $hasHtmlFallback = $htmlFallbackPath !== null;

            $summary['total']++;

            if ($resolvedPath !== null) {
                $summary['ok']++;
                $records[] = [
                    'status' => 'ok',
                    'document_id' => $documentId,
                    'project_id' => $projectId,
                    'title' => $title,
                    'file_path' => $filePath,
                    'created_at' => $createdAt,
                    'resolved_path' => $resolvedPath,
                    'size' => (int)(filesize($resolvedPath) ?: 0),
                    'html_fallback' => false,
                    'html_fallback_path' => null,
                ];
                continue;
            }

            $summary['missing']++;
            if ($hasHtmlFallback) {
                $summary['html_fallback']++;
            }

            $records[] = [
                'status' => 'missing',
                'document_id' => $documentId,
                'project_id' => $projectId,
                'title' => $title,
                'file_path' => $filePath,
                'created_at' => $createdAt,
                'resolved_path' => null,
                'size' => 0,
                'html_fallback' => $hasHtmlFallback,
                'html_fallback_path' => $htmlFallbackPath,
            ];
        }

        $missing = array_values(array_filter(
            $records,
            static fn(array $record): bool => ($record['status'] ?? '') === 'missing'
        ));

        return [
            'summary' => $summary,
            'records' => $records,
            'missing' => $missing,
        ];
    }

    private function normalizeDocumentPath(string $path): string
    {
        return str_replace('\\', '/', trim($path));
    }

    private function resolveDocumentAbsolutePath(string $filePath): ?string
    {
        $trimmed = trim($filePath);
        if ($trimmed === '') {
            return null;
        }

        $normalized = $this->normalizeDocumentPath($trimmed);
        $candidates = [];

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $trimmed) === 1 || str_starts_with($trimmed, '/')) {
            $candidates[] = $trimmed;
        } else {
            $relative = ltrim($normalized, '/');
            $candidates[] = $this->publicBaseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
            $candidates[] = $this->projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);

            if (str_starts_with($relative, 'public/')) {
                $withoutPublic = substr($relative, strlen('public/'));
                $candidates[] = $this->publicBaseDir . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $withoutPublic);
            } elseif (!str_starts_with($relative, '01_RAG_Documents/')) {
                $candidates[] = $this->publicBaseDir . DIRECTORY_SEPARATOR . '01_RAG_Documents' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative);
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
            $this->publicBaseDir . DIRECTORY_SEPARATOR . '01_RAG_Documents',
            $this->projectRoot . DIRECTORY_SEPARATOR . '01_RAG_Documents',
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

    private function detectHtmlFallback(string $filePath): ?string
    {
        $normalized = $this->normalizeDocumentPath($filePath);
        if (!str_ends_with(strtolower($normalized), '.pdf')) {
            return null;
        }

        $htmlPath = preg_replace('/\.pdf$/i', '.html', $normalized);
        if (!is_string($htmlPath) || $htmlPath === '') {
            return null;
        }

        return $this->resolveDocumentAbsolutePath($htmlPath);
    }
}
