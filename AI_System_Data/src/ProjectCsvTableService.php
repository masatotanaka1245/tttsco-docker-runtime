<?php
/**
 * ProjectCsvTableService.php - 手作業CSV台帳の作成・追記サービス
 */

class ProjectCsvTableService
{
    private PDO $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function createManualCsv(int $projectId, string $fileName, array $headers): array
    {
        $normalizedHeaders = $this->normalizeHeaders($headers);
        if ($normalizedHeaders === []) {
            throw new InvalidArgumentException('列名を1つ以上入力してください。');
        }

        $cleanFileName = $this->normalizeFileName($fileName);
        if ($cleanFileName === '') {
            $cleanFileName = 'manual_csv_' . date('Ymd_His') . '.csv';
        }

        $stmt = $this->pdo->prepare("
            INSERT INTO project_csv_files (project_id, file_name, column_headers, row_count, created_at)
            VALUES (?, ?, ?, 0, NOW())
        ");
        $stmt->execute([
            $projectId,
            $cleanFileName,
            json_encode($normalizedHeaders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return $this->findById($projectId, (int)$this->pdo->lastInsertId());
    }

    public function appendRow(int $projectId, int $csvFileId, array $rowData): array
    {
        $file = $this->findById($projectId, $csvFileId);
        if (!$file) {
            throw new RuntimeException('対象のCSVファイルが見つかりません。');
        }

        $headers = $this->decodeHeaders((string)($file['column_headers'] ?? ''));
        if ($headers === []) {
            throw new RuntimeException('このCSVには有効な列定義がありません。');
        }

        $normalizedRow = [];
        foreach ($headers as $header) {
            $value = $rowData[$header] ?? '';
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $normalizedRow[$header] = trim((string)$value);
        }

        $stmtNext = $this->pdo->prepare("SELECT COALESCE(MAX(row_index), 0) + 1 FROM project_csv_rows WHERE csv_file_id = ?");
        $stmtNext->execute([$csvFileId]);
        $nextRowIndex = (int)$stmtNext->fetchColumn();

        $stmtInsert = $this->pdo->prepare("
            INSERT INTO project_csv_rows (csv_file_id, row_index, row_data, created_at)
            VALUES (?, ?, ?, NOW())
        ");
        $stmtInsert->execute([
            $csvFileId,
            $nextRowIndex,
            json_encode($normalizedRow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $stmtUpdate = $this->pdo->prepare("UPDATE project_csv_files SET row_count = ? WHERE id = ?");
        $stmtUpdate->execute([$nextRowIndex, $csvFileId]);

        $updated = $this->findById($projectId, $csvFileId);
        $updated['last_appended_row_index'] = $nextRowIndex;
        $updated['last_row_data'] = $normalizedRow;

        return $updated;
    }

    public function findById(int $projectId, int $csvFileId): ?array
    {
        $stmt = $this->pdo->prepare("
            SELECT *
            FROM project_csv_files
            WHERE id = ? AND project_id = ?
            LIMIT 1
        ");
        $stmt->execute([$csvFileId, $projectId]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ?: null;
    }

    private function normalizeHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $header) {
            $header = trim((string)$header);
            if ($header === '') {
                continue;
            }
            if (!in_array($header, $normalized, true)) {
                $normalized[] = $header;
            }
        }

        return $normalized;
    }

    private function decodeHeaders(string $columnHeaders): array
    {
        $decoded = json_decode($columnHeaders, true);
        if (!is_array($decoded)) {
            return [];
        }

        return $this->normalizeHeaders($decoded);
    }

    private function normalizeFileName(string $fileName): string
    {
        $fileName = trim($fileName);
        $fileName = preg_replace('/[\r\n\t]+/u', ' ', $fileName ?? '');
        $fileName = preg_replace('/[\\\\\\/]+/u', '_', $fileName ?? '');
        $fileName = trim((string)$fileName);

        if ($fileName !== '' && !preg_match('/\.csv$/iu', $fileName)) {
            $fileName .= '.csv';
        }

        return $fileName;
    }
}
