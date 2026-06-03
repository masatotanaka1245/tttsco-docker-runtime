<?php

class CsvMetadataCatalog
{
    private $pdo;
    private $projectId;

    public function __construct(PDO $pdo, int $projectId)
    {
        $this->pdo = $pdo;
        $this->projectId = $projectId;
    }

    public function loadFiles(): array
    {
        $stmt = $this->pdo->prepare("
            SELECT id, file_name, column_headers, row_count
            FROM project_csv_files
            WHERE project_id = ?
            ORDER BY id ASC
        ");
        $stmt->execute([$this->projectId]);

        $files = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $headers = $this->parseHeaders((string)$row['column_headers']);
            $files[] = [
                'id' => (int)$row['id'],
                'file_name' => (string)$row['file_name'],
                'row_count' => (int)$row['row_count'],
                'columns' => $headers,
            ];
        }

        return $files;
    }

    public function parseHeaders(string $rawHeaders): array
    {
        $decoded = json_decode($rawHeaders, true);
        $candidates = is_array($decoded) ? $decoded : [$rawHeaders];
        $headers = [];

        foreach ($candidates as $candidate) {
            foreach (preg_split('/[,;]\s*/u', (string)$candidate) ?: [] as $header) {
                $header = trim($header, " \t\n\r\0\x0B\"'");
                if ($header !== '') {
                    $headers[] = $header;
                }
            }
        }

        return array_values(array_unique($headers));
    }
}
