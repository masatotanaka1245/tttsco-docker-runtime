<?php

require_once __DIR__ . '/AppLogger.php';
require_once __DIR__ . '/DocChunkSummaryBuilder.php';
require_once __DIR__ . '/EmbeddingEngine.php';
require_once __DIR__ . '/ModelRoleResolver.php';

class ProjectMaterialDocumentService
{
    private PDO $pdo;
    private string $basePath;

    public function __construct(PDO $pdo, string $basePath)
    {
        $this->pdo = $pdo;
        $this->basePath = rtrim($basePath, DIRECTORY_SEPARATOR);
    }

    public function findById(int $projectId, int $documentId): ?array
    {
        if ($projectId <= 0 || $documentId <= 0) {
            return null;
        }

        $stmt = $this->pdo->prepare('SELECT * FROM documents WHERE id = ? AND project_id = ? LIMIT 1');
        $stmt->execute([$documentId, $projectId]);
        $document = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$document) {
            return null;
        }

        $filePath = strtolower((string)($document['file_path'] ?? ''));
        if (!str_ends_with($filePath, '.md')) {
            return null;
        }

        return $document;
    }

    public function readContent(string $relativePath, ?int $documentId = null): string
    {
        $absolutePath = $this->toAbsolutePath($relativePath);
        if (is_file($absolutePath)) {
            $content = file_get_contents($absolutePath);
            if ($content !== false && trim((string)$content) !== '') {
                return (string)$content;
            }
        }

        if ($documentId && $documentId > 0) {
            $fallback = $this->readContentFromChunks($documentId);
            if ($fallback !== '') {
                return $fallback;
            }
        }

        return '';
    }

    public function getModifiedAt(string $relativePath): ?string
    {
        $absolutePath = $this->toAbsolutePath($relativePath);
        if (!is_file($absolutePath)) {
            return null;
        }

        $mtime = @filemtime($absolutePath);
        if ($mtime === false) {
            return null;
        }

        return date('Y-m-d H:i:s', $mtime);
    }

    public function listByProject(int $projectId): array
    {
        if ($projectId <= 0) {
            return [];
        }

        $stmt = $this->pdo->prepare("SELECT * FROM documents WHERE project_id = :project_id AND title NOT LIKE '[CSVデータ]%' ORDER BY created_at DESC");
        $stmt->execute(['project_id' => $projectId]);

        $materialDocuments = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $document) {
            $filePath = strtolower((string)($document['file_path'] ?? ''));
            if (!str_ends_with($filePath, '.md')) {
                continue;
            }

            $document['material_modified_at'] = $this->getModifiedAt((string)($document['file_path'] ?? ''));
            $materialDocuments[] = $document;
        }

        return $materialDocuments;
    }

    public function resolveSelectedDocument(array $materialDocuments, ?int $documentId = null): ?array
    {
        if ($documentId) {
            foreach ($materialDocuments as $materialDocument) {
                if ((int)($materialDocument['id'] ?? 0) === $documentId) {
                    return $materialDocument;
                }
            }
        }

        return $materialDocuments[0] ?? null;
    }

    public function buildDocumentsPayload(array $materialDocuments): array
    {
        return array_map(fn(array $document): array => $this->buildDocumentSummary($document), $materialDocuments);
    }

    public function buildSelectedPayload(?array $selectedDocument, string $content = '', string $previewHtml = ''): array
    {
        if (!$selectedDocument) {
            return [
                'id' => 0,
                'title' => '',
                'content' => '',
                'preview_html' => '',
                'modified_at' => '',
                'modified_label' => '更新時刻なし',
            ];
        }

        $modifiedAt = (string)($selectedDocument['material_modified_at'] ?? $selectedDocument['created_at'] ?? '');

        return [
            'id' => (int)($selectedDocument['id'] ?? 0),
            'title' => (string)($selectedDocument['title'] ?? ''),
            'content' => $content,
            'preview_html' => $previewHtml,
            'modified_at' => $modifiedAt,
            'modified_label' => $modifiedAt !== '' ? date('Y/m/d H:i', strtotime($modifiedAt)) : '更新時刻なし',
        ];
    }

    public function hasSearchableEmbeddings(int $documentId): bool
    {
        if ($documentId <= 0) {
            return false;
        }

        $stmt = $this->pdo->prepare('SELECT embedding FROM doc_chunks WHERE doc_id = ?');
        $stmt->execute([$documentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_COLUMN);
        if (!$rows) {
            return false;
        }

        foreach ($rows as $embeddingJson) {
            $embedding = json_decode((string)$embeddingJson, true);
            if (is_array($embedding) && !empty($embedding)) {
                return true;
            }
        }

        return false;
    }

    public function ensureRagIndexed(int $projectId, int $documentId): bool
    {
        $document = $this->findById($projectId, $documentId);
        if (!$document) {
            return false;
        }

        if ($this->hasSearchableEmbeddings($documentId)) {
            return false;
        }

        $content = $this->readContent((string)($document['file_path'] ?? ''), $documentId);
        if (trim($content) === '') {
            $this->logRag('rag reindex skipped because content is empty', [
                'project_id' => $projectId,
                'document_id' => $documentId,
                'title' => (string)($document['title'] ?? ''),
            ]);
            return false;
        }

        $this->save($projectId, (string)($document['title'] ?? ''), $content, $documentId);
        $this->logRag('legacy material note reindexed', [
            'project_id' => $projectId,
            'document_id' => $documentId,
            'title' => (string)($document['title'] ?? ''),
        ]);

        return true;
    }

    public function backfillProjectEmbeddings(int $projectId): array
    {
        return $this->backfillProjectEmbeddingsByScope($projectId, 'all');
    }

    public function backfillProjectEmbeddingsByScope(int $projectId, string $scope = 'all'): array
    {
        $documents = $this->listByProject($projectId);
        $summary = [
            'project_id' => $projectId,
            'scope' => $scope,
            'checked' => count($documents),
            'eligible' => 0,
            'reindexed' => 0,
            'already_indexed' => 0,
            'skipped_empty' => 0,
            'skipped_scope' => 0,
        ];

        foreach ($documents as $document) {
            $documentId = (int)($document['id'] ?? 0);
            if ($documentId <= 0) {
                continue;
            }

            if (!$this->matchesBackfillScope($document, $scope)) {
                $summary['skipped_scope']++;
                continue;
            }

            $summary['eligible']++;

            if ($this->hasSearchableEmbeddings($documentId)) {
                $summary['already_indexed']++;
                continue;
            }

            $content = $this->readContent((string)($document['file_path'] ?? ''), $documentId);
            if (trim($content) === '') {
                $summary['skipped_empty']++;
                $this->logRag('project backfill skipped empty material note', [
                    'project_id' => $projectId,
                    'document_id' => $documentId,
                    'title' => (string)($document['title'] ?? ''),
                ]);
                continue;
            }

            $this->save($projectId, (string)($document['title'] ?? ''), $content, $documentId);
            $summary['reindexed']++;
        }

        $this->logRag('project backfill completed', $summary);
        return $summary;
    }

    public function backfillAllProjectsEmbeddings(string $scope = 'all'): array
    {
        $stmt = $this->pdo->query("SELECT DISTINCT project_id FROM documents WHERE file_path LIKE '%.md' ORDER BY project_id ASC");
        $projectIds = array_map(static fn($value): int => (int)$value, $stmt->fetchAll(PDO::FETCH_COLUMN));

        $summary = [
            'scope' => $scope,
            'projects' => count($projectIds),
            'checked' => 0,
            'eligible' => 0,
            'reindexed' => 0,
            'already_indexed' => 0,
            'skipped_empty' => 0,
            'skipped_scope' => 0,
            'project_summaries' => [],
        ];

        foreach ($projectIds as $projectId) {
            if ($projectId <= 0) {
                continue;
            }

            $projectSummary = $this->backfillProjectEmbeddingsByScope($projectId, $scope);
            $summary['project_summaries'][] = $projectSummary;
            $summary['checked'] += (int)($projectSummary['checked'] ?? 0);
            $summary['eligible'] += (int)($projectSummary['eligible'] ?? 0);
            $summary['reindexed'] += (int)($projectSummary['reindexed'] ?? 0);
            $summary['already_indexed'] += (int)($projectSummary['already_indexed'] ?? 0);
            $summary['skipped_empty'] += (int)($projectSummary['skipped_empty'] ?? 0);
            $summary['skipped_scope'] += (int)($projectSummary['skipped_scope'] ?? 0);
        }

        $this->logRag('global backfill completed', [
            'scope' => $summary['scope'],
            'projects' => $summary['projects'],
            'eligible' => $summary['eligible'],
            'reindexed' => $summary['reindexed'],
            'already_indexed' => $summary['already_indexed'],
            'skipped_empty' => $summary['skipped_empty'],
            'skipped_scope' => $summary['skipped_scope'],
        ]);

        return $summary;
    }

    public function save(int $projectId, string $title, string $content, ?int $documentId = null): array
    {
        $title = $this->normalizeTitle($title, $content);
        $content = $this->normalizeContent($content, $title);
        $existingDocument = $documentId ? $this->findById($projectId, $documentId) : null;

        $relativePath = $existingDocument
            ? (string)$existingDocument['file_path']
            : $this->buildRelativePath($projectId, $title);
        $absolutePath = $this->toAbsolutePath($relativePath);
        $directory = dirname($absolutePath);

        if (!is_dir($directory) && !@mkdir($directory, 0777, true) && !is_dir($directory)) {
            throw new RuntimeException('資料メモの保存先ディレクトリを作成できませんでした。');
        }

        if (file_put_contents($absolutePath, $content, LOCK_EX) === false) {
            throw new RuntimeException('資料メモのMarkdownファイル保存に失敗しました。');
        }

        $chunks = $this->splitIntoChunks($content);
        if (empty($chunks)) {
            $chunks[] = '# ' . $title;
        }
        $chunkEmbeddings = $this->buildChunkEmbeddings($projectId, $title, $chunks, $existingDocument ? (int)$existingDocument['id'] : null);

        $this->pdo->beginTransaction();
        try {
            if ($existingDocument) {
                $savedDocumentId = (int)$existingDocument['id'];
                $stmtUpdate = $this->pdo->prepare('UPDATE documents SET title = ?, file_path = ? WHERE id = ? AND project_id = ?');
                $stmtUpdate->execute([$title, $relativePath, $savedDocumentId, $projectId]);
            } else {
                $stmtInsert = $this->pdo->prepare('INSERT INTO documents (project_id, title, file_path, created_at) VALUES (?, ?, ?, NOW())');
                $stmtInsert->execute([$projectId, $title, $relativePath]);
                $savedDocumentId = (int)$this->pdo->lastInsertId();
            }

            $stmtDeleteChunks = $this->pdo->prepare('DELETE FROM doc_chunks WHERE doc_id = ?');
            $stmtDeleteChunks->execute([$savedDocumentId]);

            $stmtInsertChunk = $this->pdo->prepare(
                'INSERT INTO doc_chunks (doc_id, page_number, chunk_text, chunk_summary, embedding, image_description, created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())'
            );
            foreach ($chunks as $index => $chunk) {
                $embedding = $chunkEmbeddings[$index] ?? [];
                $chunkSummary = DocChunkSummaryBuilder::build($chunk, '案件資料メモ（Markdown/RAG）: ' . $title);
                $stmtInsertChunk->execute([
                    $savedDocumentId,
                    1,
                    $chunk,
                    $chunkSummary,
                    json_encode($embedding, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    '案件資料メモ（Markdown/RAG）: ' . $title,
                ]);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $embeddedCount = count(array_filter($chunkEmbeddings, static fn(array $embedding): bool => !empty($embedding)));
        $this->logRag('material note indexed', [
            'project_id' => $projectId,
            'document_id' => $savedDocumentId,
            'title' => $title,
            'chunks' => count($chunks),
            'embedded_chunks' => $embeddedCount,
            'file_path' => $relativePath,
        ]);

        return [
            'document_id' => $savedDocumentId,
            'title' => $title,
            'file_path' => $relativePath,
            'content' => $content,
            'modified_at' => $this->getModifiedAt($relativePath),
        ];
    }

    public function delete(int $projectId, int $documentId): bool
    {
        $existingDocument = $this->findById($projectId, $documentId);
        if (!$existingDocument) {
            return false;
        }

        $absolutePath = $this->toAbsolutePath((string)$existingDocument['file_path']);

        $this->pdo->beginTransaction();
        try {
            $stmtDeleteChunks = $this->pdo->prepare('DELETE FROM doc_chunks WHERE doc_id = ?');
            $stmtDeleteChunks->execute([$documentId]);

            $stmtDeleteDoc = $this->pdo->prepare('DELETE FROM documents WHERE id = ? AND project_id = ?');
            $stmtDeleteDoc->execute([$documentId, $projectId]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }

        return true;
    }

    private function normalizeTitle(string $title, string $content): string
    {
        $title = trim($title);
        if ($title !== '') {
            return mb_substr($title, 0, 255);
        }

        if (preg_match('/^#\s+(.+)$/mu', $content, $matches)) {
            return mb_substr(trim((string)$matches[1]), 0, 255);
        }

        return '資料メモ_' . date('Ymd_His');
    }

    private function normalizeContent(string $content, string $title): string
    {
        $content = trim($content);
        if ($content === '') {
            throw new RuntimeException('資料メモの内容が空です。');
        }

        if (!preg_match('/^#\s+/mu', $content)) {
            $content = '# ' . $title . "\n\n" . $content;
        }

        return rtrim($content) . "\n";
    }

    private function buildRelativePath(int $projectId, string $title): string
    {
        $directory = '01_RAG_Documents/' . $projectId . '/materials';
        $slug = $this->slugify(pathinfo($title, PATHINFO_FILENAME));
        if ($slug === '') {
            $slug = 'material';
        }

        return $directory . '/' . $slug . '_' . substr(sha1(uniqid('', true)), 0, 8) . '.md';
    }

    private function toAbsolutePath(string $relativePath): string
    {
        $trimmed = ltrim(str_replace(['\\', '//'], ['/', '/'], $relativePath), '/');
        return $this->basePath . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $trimmed);
    }

    private function slugify(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '-', $value) ?? '';
        return trim($value, '-');
    }

    private function splitIntoChunks(string $content, int $chunkSize = 900): array
    {
        $sections = preg_split('/\R{2,}/u', trim($content)) ?: [];
        $chunks = [];
        $buffer = '';

        foreach ($sections as $section) {
            $section = trim((string)$section);
            if ($section === '') {
                continue;
            }

            $candidate = $buffer === '' ? $section : $buffer . "\n\n" . $section;
            if (mb_strlen($candidate) <= $chunkSize) {
                $buffer = $candidate;
                continue;
            }

            if ($buffer !== '') {
                $chunks[] = $buffer;
            }

            if (mb_strlen($section) <= $chunkSize) {
                $buffer = $section;
                continue;
            }

            $length = mb_strlen($section);
            for ($offset = 0; $offset < $length; $offset += $chunkSize) {
                $chunks[] = mb_substr($section, $offset, $chunkSize);
            }
            $buffer = '';
        }

        if ($buffer !== '') {
            $chunks[] = $buffer;
        }

        return $chunks;
    }

    private function buildChunkEmbeddings(int $projectId, string $title, array $chunks, ?int $documentId = null): array
    {
        $engine = $this->createEmbeddingEngine();
        if ($engine === null) {
            $this->logRag('embedding engine unavailable for material note', [
                'project_id' => $projectId,
                'document_id' => $documentId,
                'title' => $title,
                'chunks' => count($chunks),
            ]);
            return array_fill(0, count($chunks), []);
        }

        $embeddings = [];
        foreach ($chunks as $index => $chunk) {
            try {
                $payload = $this->buildEmbeddingPayload($title, (string)$chunk);
                $embedding = $engine->embed($payload);
                $embeddings[$index] = is_array($embedding) ? $embedding : [];
                $this->logRag('embedding generated for material chunk', [
                    'project_id' => $projectId,
                    'document_id' => $documentId,
                    'title' => $title,
                    'chunk_index' => $index + 1,
                    'chunk_chars' => mb_strlen((string)$chunk),
                    'vector_size' => is_array($embedding) ? count($embedding) : 0,
                ]);
            } catch (Throwable $e) {
                $embeddings[$index] = [];
                $this->logRag('embedding generation failed for material chunk', [
                    'project_id' => $projectId,
                    'document_id' => $documentId,
                    'title' => $title,
                    'chunk_index' => $index + 1,
                    'chunk_chars' => mb_strlen((string)$chunk),
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $embeddings;
    }

    private function createEmbeddingEngine(): ?EmbeddingEngine
    {
        try {
            if (session_status() === PHP_SESSION_NONE) {
                @session_start();
            }
            $settings = ModelRoleResolver::resolveUserSettings($_SESSION ?? []);
            if (session_status() === PHP_SESSION_ACTIVE) {
                session_write_close();
            }

            return new EmbeddingEngine(
                $settings['ollama_host'] ?? null,
                $settings['embedding_model'] ?? ModelRoleResolver::DEFAULT_EMBEDDING_MODEL,
                45,
                5
            );
        } catch (Throwable $e) {
            $this->logRag('embedding engine bootstrap failed', [
                'error' => $e->getMessage(),
            ]);
            return null;
        }
    }

    private function buildEmbeddingPayload(string $title, string $chunk): string
    {
        $chunk = trim($chunk);
        $headings = $this->extractMarkdownHeadings($chunk);
        if ($chunk === '') {
            return '案件資料メモ: ' . $title;
        }

        $payload = "【案件資料メモ】\nタイトル: {$title}\n";
        if ($headings !== []) {
            $payload .= "見出し: " . implode(' / ', $headings) . "\n";
        }
        $payload .= "\n{$chunk}";

        return $payload;
    }

    private function buildDocumentSummary(array $document): array
    {
        $modifiedAt = (string)($document['material_modified_at'] ?? $document['created_at'] ?? '');

        return [
            'id' => (int)($document['id'] ?? 0),
            'title' => (string)($document['title'] ?? '資料メモ'),
            'modified_at' => $modifiedAt,
            'modified_label' => $modifiedAt !== '' ? date('Y/m/d H:i', strtotime($modifiedAt)) : '更新時刻なし',
        ];
    }

    private function readContentFromChunks(int $documentId): string
    {
        if ($documentId <= 0) {
            return '';
        }

        $stmt = $this->pdo->prepare(
            'SELECT chunk_text FROM doc_chunks WHERE doc_id = ? ORDER BY page_number ASC, id ASC'
        );
        $stmt->execute([$documentId]);
        $chunks = $stmt->fetchAll(PDO::FETCH_COLUMN);

        if (!$chunks) {
            return '';
        }

        $content = trim(implode("\n\n", array_map(static fn($chunk): string => trim((string)$chunk), $chunks)));
        return $content !== '' ? $content . "\n" : '';
    }

    private function matchesBackfillScope(array $document, string $scope): bool
    {
        if ($scope === 'all') {
            return true;
        }

        $title = (string)($document['title'] ?? '');
        if ($scope === 'csv_analysis') {
            return str_starts_with($title, 'CSV読解メモ_') || str_contains($title, 'CSV読解メモ');
        }

        return true;
    }

    private function extractMarkdownHeadings(string $content, int $limit = 4): array
    {
        if ($content === '') {
            return [];
        }

        preg_match_all('/^\s{0,3}#{1,6}\s+(.+)$/mu', $content, $matches);
        $headings = [];
        foreach (($matches[1] ?? []) as $heading) {
            $heading = trim(strip_tags((string)$heading));
            if ($heading === '') {
                continue;
            }
            $headings[] = $heading;
            if (count($headings) >= $limit) {
                break;
            }
        }

        return $headings;
    }

    private function logRag(string $message, array $context = []): void
    {
        appLog('material_rag.log', '[MATERIAL-RAG] ' . $message, $context);
    }
}
