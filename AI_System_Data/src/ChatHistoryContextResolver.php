<?php

require_once __DIR__ . '/CsvMetadataCatalog.php';
require_once __DIR__ . '/CsvSearchTermExtractor.php';

class ChatHistoryContextResolver
{
    private $pdo;
    private $projectId;
    private $metadataCatalog;
    private $extractor;
    private $files = null;

    public function __construct(PDO $pdo, int $projectId)
    {
        $this->pdo = $pdo;
        $this->projectId = $projectId;
        $this->metadataCatalog = new CsvMetadataCatalog($pdo, $projectId);
        $this->extractor = new CsvSearchTermExtractor(static function (string $text): string {
            return (string)$text;
        });
    }

    public function findMentionedCsvFileName(string $message): ?string
    {
        return $this->extractor->findMentionedCsvFileName($message, $this->loadFiles());
    }

    public function findMentionedCsvColumnTarget(string $message): ?array
    {
        $matches = [];

        foreach ($this->loadFiles() as $file) {
            $fileName = (string)($file['file_name'] ?? '');
            foreach ((array)($file['columns'] ?? []) as $column) {
                $column = (string)$column;
                if ($column === '') {
                    continue;
                }
                if (mb_stripos($message, $column, 0, 'UTF-8') !== false) {
                    $matches[$fileName . '|' . $column] = [
                        'file_name' => $fileName,
                        'column_name' => $column,
                    ];
                }
            }
        }

        return count($matches) === 1 ? array_values($matches)[0] : null;
    }

    public function findRecentCsvContext(array $recentHistory): ?array
    {
        $userContext = $this->scanRecentCsvContext($recentHistory, 'user');
        if ($userContext !== null) {
            return $userContext;
        }

        return $this->scanRecentCsvContext($recentHistory, 'assistant');
    }

    private function scanRecentCsvContext(array $recentHistory, ?string $roleFilter): ?array
    {
        for ($i = count($recentHistory) - 1; $i >= 0; $i--) {
            $role = (string)($recentHistory[$i]['role'] ?? '');
            if ($roleFilter !== null && $role !== $roleFilter) {
                continue;
            }

            $historyMessage = trim((string)($recentHistory[$i]['message'] ?? ''));
            if ($historyMessage === '') {
                continue;
            }

            $mentionedCsv = $this->findMentionedCsvFileName($historyMessage);
            $mentionedColumnTarget = $this->findMentionedCsvColumnTarget($historyMessage);
            $explicitColumnReference = $this->findExplicitColumnReference($historyMessage);
            $globalColumn = $explicitColumnReference ?? $this->findMentionedCsvColumnNameAcrossFiles($historyMessage);
            if ($mentionedCsv === null && !empty($mentionedColumnTarget['file_name'])) {
                $mentionedCsv = (string)$mentionedColumnTarget['file_name'];
            }

            $targetColumn = $mentionedColumnTarget['column_name'] ?? $globalColumn;

            if ($mentionedCsv !== null || $targetColumn !== null) {
                return [
                    'target_file_name' => $mentionedCsv,
                    'target_column' => $targetColumn,
                    'source_role' => $role,
                    'source_message' => $historyMessage,
                ];
            }
        }

        return null;
    }

    private function findMentionedCsvColumnNameAcrossFiles(string $message): ?string
    {
        $matchedColumns = [];
        foreach ($this->loadFiles() as $file) {
            foreach ((array)($file['columns'] ?? []) as $column) {
                $column = (string)$column;
                if ($column === '') {
                    continue;
                }
                if (mb_stripos($message, $column, 0, 'UTF-8') !== false) {
                    $matchedColumns[$column] = true;
                }
            }
        }

        if (count($matchedColumns) === 1) {
            return array_key_first($matchedColumns);
        }

        return null;
    }

    private function findExplicitColumnReference(string $message): ?string
    {
        if (preg_match_all('/[「『"]([^」』"]+)[」』"]\s*(?:カラム|列|項目)/u', $message, $matches)) {
            foreach (array_reverse($matches[1]) as $candidate) {
                $candidate = trim((string)$candidate);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        if (preg_match_all('/(?:^|[\s　])([A-Za-z_][A-Za-z0-9_]*|[一-龠ぁ-んァ-ヶー]+)\s*(?:カラム|列|項目)/u', $message, $matches)) {
            foreach (array_reverse($matches[1]) as $candidate) {
                $candidate = trim((string)$candidate);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        if (preg_match('/(?:^|[\s　])([A-Za-z_][A-Za-z0-9_]*|[一-龠ぁ-んァ-ヶー]+)\s*から\s*(?:年月|月別|年別|日別|日時|日付|時刻|時間帯|時刻帯|時間ごと|時ごと)/u', $message, $matches) === 1) {
            $candidate = trim((string)($matches[1] ?? ''));
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function loadFiles(): array
    {
        if ($this->files === null) {
            $this->files = $this->metadataCatalog->loadFiles();
        }

        return $this->files;
    }
}
