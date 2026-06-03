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
        for ($i = count($recentHistory) - 1; $i >= 0; $i--) {
            $historyMessage = trim((string)($recentHistory[$i]['message'] ?? ''));
            if ($historyMessage === '') {
                continue;
            }

            $mentionedCsv = $this->findMentionedCsvFileName($historyMessage);
            $mentionedColumnTarget = $this->findMentionedCsvColumnTarget($historyMessage);
            if ($mentionedCsv === null && !empty($mentionedColumnTarget['file_name'])) {
                $mentionedCsv = (string)$mentionedColumnTarget['file_name'];
            }

            if ($mentionedCsv !== null || $mentionedColumnTarget !== null) {
                return [
                    'target_file_name' => $mentionedCsv,
                    'target_column' => $mentionedColumnTarget['column_name'] ?? null,
                    'source_role' => (string)($recentHistory[$i]['role'] ?? ''),
                    'source_message' => $historyMessage,
                ];
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
