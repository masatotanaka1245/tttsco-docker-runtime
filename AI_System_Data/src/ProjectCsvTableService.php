<?php
/**
 * ProjectCsvTableService.php - 手作業CSV台帳の作成・追記サービス
 */

class ProjectCsvTableService
{
    private const MERGE_ADD_AS_NEW = '__ADD_AS_NEW__';
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

    public function updateColumns(int $projectId, int $csvFileId, array $headers): array
    {
        $file = $this->findById($projectId, $csvFileId);
        if (!$file) {
            throw new RuntimeException('対象のCSVファイルが見つかりません。');
        }

        $newHeaders = $this->normalizeHeaders($headers);
        if ($newHeaders === []) {
            throw new InvalidArgumentException('列名を1つ以上残してください。');
        }

        $currentHeaders = $this->decodeHeaders((string)($file['column_headers'] ?? ''));
        $removedHeaders = array_values(array_diff($currentHeaders, $newHeaders));
        $addedHeaders = array_values(array_diff($newHeaders, $currentHeaders));

        $this->pdo->beginTransaction();
        try {
            $stmtRows = $this->pdo->prepare("SELECT id, row_data FROM project_csv_rows WHERE csv_file_id = ?");
            $stmtRows->execute([$csvFileId]);
            $rows = $stmtRows->fetchAll(PDO::FETCH_ASSOC);

            if ($rows !== []) {
                $stmtUpdateRow = $this->pdo->prepare("UPDATE project_csv_rows SET row_data = ? WHERE id = ?");
                foreach ($rows as $row) {
                    $rowData = json_decode((string)($row['row_data'] ?? ''), true);
                    if (!is_array($rowData)) {
                        $rowData = [];
                    }

                    foreach ($removedHeaders as $header) {
                        unset($rowData[$header]);
                    }
                    foreach ($addedHeaders as $header) {
                        if (!array_key_exists($header, $rowData)) {
                            $rowData[$header] = '';
                        }
                    }

                    $normalizedRowData = [];
                    foreach ($newHeaders as $header) {
                        $value = $rowData[$header] ?? '';
                        if (is_array($value)) {
                            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
                        }
                        $normalizedRowData[$header] = trim((string)$value);
                    }

                    $stmtUpdateRow->execute([
                        json_encode($normalizedRowData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        (int)$row['id'],
                    ]);
                }
            }

            $stmtUpdateFile = $this->pdo->prepare("UPDATE project_csv_files SET column_headers = ? WHERE id = ? AND project_id = ?");
            $stmtUpdateFile->execute([
                json_encode($newHeaders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                $csvFileId,
                $projectId,
            ]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $updated = $this->findById($projectId, $csvFileId);
        $updated['headers'] = $newHeaders;
        return $updated;
    }

    public function mergeIntoMain(int $projectId, int $mainCsvFileId, array $subCsvFileIds, string $outputFileName = '', array $columnMappings = [], bool $createNewCsv = true): array
    {
        $mainFile = $this->findById($projectId, $mainCsvFileId);
        if (!$mainFile) {
            throw new RuntimeException('メインCSVが見つかりません。');
        }

        $normalizedSubIds = [];
        foreach ($subCsvFileIds as $subCsvFileId) {
            $id = (int)$subCsvFileId;
            if ($id <= 0 || $id === $mainCsvFileId || in_array($id, $normalizedSubIds, true)) {
                continue;
            }
            $normalizedSubIds[] = $id;
        }

        if ($normalizedSubIds === []) {
            throw new InvalidArgumentException('サブCSVを1件以上選択してください。');
        }

        $mainHeaders = $this->decodeHeaders((string)($mainFile['column_headers'] ?? ''));
        if ($mainHeaders === []) {
            throw new RuntimeException('メインCSVに有効な列定義がありません。');
        }

        $subFiles = [];
        foreach ($normalizedSubIds as $subCsvFileId) {
            $subFile = $this->findById($projectId, $subCsvFileId);
            if (!$subFile) {
                throw new RuntimeException('サブCSVの一部が見つかりません。');
            }
            $subFiles[] = $subFile;
        }

        $normalizedMappings = $this->normalizeColumnMappings($mainHeaders, $subFiles, $columnMappings);

        $mergedHeaders = $mainHeaders;
        foreach ($subFiles as $subFile) {
            foreach ($this->decodeHeaders((string)($subFile['column_headers'] ?? '')) as $header) {
                $mappedHeader = $normalizedMappings[(int)$subFile['id']][$header] ?? '';
                if ($mappedHeader === '') {
                    continue;
                }
                $header = $mappedHeader === self::MERGE_ADD_AS_NEW ? $header : $mappedHeader;
                if (!in_array($header, $mergedHeaders, true)) {
                    $mergedHeaders[] = $header;
                }
            }
        }

        $cleanOutputFileName = $this->normalizeFileName($outputFileName);
        if ($createNewCsv && $cleanOutputFileName === '') {
            $mainBaseName = pathinfo((string)($mainFile['file_name'] ?? 'main.csv'), PATHINFO_FILENAME);
            $cleanOutputFileName = $this->normalizeFileName($mainBaseName . '_merged.csv');
        }

        $this->pdo->beginTransaction();
        try {
            if ($createNewCsv) {
                $orderedFileIds = array_merge([$mainCsvFileId], $normalizedSubIds);
                $rowIndex = 1;

                $stmtInsertFile = $this->pdo->prepare("
                    INSERT INTO project_csv_files (project_id, file_name, column_headers, row_count, created_at)
                    VALUES (?, ?, ?, 0, NOW())
                ");
                $stmtInsertFile->execute([
                    $projectId,
                    $cleanOutputFileName,
                    json_encode($mergedHeaders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                ]);

                $mergedCsvFileId = (int)$this->pdo->lastInsertId();
                $stmtInsertRow = $this->pdo->prepare("
                    INSERT INTO project_csv_rows (csv_file_id, row_index, row_data, created_at)
                    VALUES (?, ?, ?, NOW())
                ");

                foreach ($orderedFileIds as $sourceCsvFileId) {
                    foreach ($this->loadRowsForFile($sourceCsvFileId) as $sourceRow) {
                        $preparedRow = $sourceCsvFileId === $mainCsvFileId
                            ? $sourceRow
                            : $this->applyColumnMappingsToRow($sourceRow, $normalizedMappings[$sourceCsvFileId] ?? []);
                        $normalizedRow = $this->normalizeRowForHeaders($preparedRow, $mergedHeaders);
                        $stmtInsertRow->execute([
                            $mergedCsvFileId,
                            $rowIndex,
                            json_encode($normalizedRow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]);
                        $rowIndex++;
                    }
                }

                $totalRows = max(0, $rowIndex - 1);
                $stmtUpdateCount = $this->pdo->prepare('UPDATE project_csv_files SET row_count = ? WHERE id = ?');
                $stmtUpdateCount->execute([$totalRows, $mergedCsvFileId]);
            } else {
                $mergedCsvFileId = $mainCsvFileId;
                $this->syncExistingRowsToHeaders($mergedCsvFileId, $mergedHeaders);

                $stmtNext = $this->pdo->prepare("SELECT COALESCE(MAX(row_index), 0) + 1 FROM project_csv_rows WHERE csv_file_id = ?");
                $stmtNext->execute([$mergedCsvFileId]);
                $rowIndex = (int)$stmtNext->fetchColumn();

                $stmtInsertRow = $this->pdo->prepare("
                    INSERT INTO project_csv_rows (csv_file_id, row_index, row_data, created_at)
                    VALUES (?, ?, ?, NOW())
                ");

                foreach ($normalizedSubIds as $sourceCsvFileId) {
                    foreach ($this->loadRowsForFile($sourceCsvFileId) as $sourceRow) {
                        $preparedRow = $this->applyColumnMappingsToRow($sourceRow, $normalizedMappings[$sourceCsvFileId] ?? []);
                        $normalizedRow = $this->normalizeRowForHeaders($preparedRow, $mergedHeaders);
                        $stmtInsertRow->execute([
                            $mergedCsvFileId,
                            $rowIndex,
                            json_encode($normalizedRow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                        ]);
                        $rowIndex++;
                    }
                }

                $totalRows = max(0, $rowIndex - 1);
                $stmtUpdateFile = $this->pdo->prepare('UPDATE project_csv_files SET column_headers = ?, row_count = ? WHERE id = ? AND project_id = ?');
                $stmtUpdateFile->execute([
                    json_encode($mergedHeaders, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    $totalRows,
                    $mergedCsvFileId,
                    $projectId,
                ]);
            }

            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            throw $e;
        }

        $merged = $this->findById($projectId, $mergedCsvFileId);
        if (!$merged) {
            throw new RuntimeException('統合後CSVの取得に失敗しました。');
        }

        $merged['headers'] = $mergedHeaders;
        $merged['source_csv_ids'] = $createNewCsv ? array_merge([$mainCsvFileId], $normalizedSubIds) : $normalizedSubIds;
        return $merged;
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

    private function loadRowsForFile(int $csvFileId): array
    {
        $stmt = $this->pdo->prepare('SELECT row_data FROM project_csv_rows WHERE csv_file_id = ? ORDER BY row_index ASC');
        $stmt->execute([$csvFileId]);

        $rows = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $decoded = json_decode((string)($row['row_data'] ?? ''), true);
            $rows[] = is_array($decoded) ? $decoded : [];
        }

        return $rows;
    }

    private function normalizeColumnMappings(array $mainHeaders, array $subFiles, array $columnMappings): array
    {
        $allowedMainHeaders = array_fill_keys($mainHeaders, true);
        $normalized = [];

        foreach ($subFiles as $subFile) {
            $subFileId = (int)($subFile['id'] ?? 0);
            $subHeaders = $this->decodeHeaders((string)($subFile['column_headers'] ?? ''));
            $allowedSubHeaders = array_fill_keys($subHeaders, true);
            $rawMappings = $columnMappings[(string)$subFileId] ?? $columnMappings[$subFileId] ?? [];
            if (!is_array($rawMappings)) {
                $normalized[$subFileId] = [];
                continue;
            }

            $normalized[$subFileId] = [];
            foreach ($rawMappings as $subHeader => $mainHeader) {
                $subHeader = trim((string)$subHeader);
                $mainHeader = trim((string)$mainHeader);
                if ($subHeader === '' || $mainHeader === '') {
                    continue;
                }
                if (!isset($allowedSubHeaders[$subHeader])) {
                    continue;
                }
                if ($mainHeader !== self::MERGE_ADD_AS_NEW && !isset($allowedMainHeaders[$mainHeader])) {
                    continue;
                }
                $normalized[$subFileId][$subHeader] = $mainHeader;
            }
        }

        return $normalized;
    }

    private function applyColumnMappingsToRow(array $sourceRow, array $mappings): array
    {
        $preparedRow = [];
        foreach ($sourceRow as $header => $value) {
            $header = trim((string)$header);
            if ($header === '') {
                continue;
            }

            $mappedHeader = trim((string)($mappings[$header] ?? ''));
            if ($mappedHeader === '') {
                continue;
            }
            $targetHeader = $mappedHeader === self::MERGE_ADD_AS_NEW ? $header : $mappedHeader;

            $stringValue = is_array($value)
                ? json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                : trim((string)$value);

            if (!array_key_exists($targetHeader, $preparedRow) || ($preparedRow[$targetHeader] === '' && $stringValue !== '')) {
                $preparedRow[$targetHeader] = $stringValue;
            }
        }

        return $preparedRow;
    }

    private function normalizeRowForHeaders(array $rowData, array $headers): array
    {
        $normalizedRow = [];
        foreach ($headers as $header) {
            $value = $rowData[$header] ?? '';
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $normalizedRow[$header] = trim((string)$value);
        }

        return $normalizedRow;
    }

    private function syncExistingRowsToHeaders(int $csvFileId, array $headers): void
    {
        $stmtRows = $this->pdo->prepare('SELECT id, row_data FROM project_csv_rows WHERE csv_file_id = ? ORDER BY row_index ASC');
        $stmtRows->execute([$csvFileId]);
        $rows = $stmtRows->fetchAll(PDO::FETCH_ASSOC);
        if ($rows === []) {
            return;
        }

        $stmtUpdateRow = $this->pdo->prepare('UPDATE project_csv_rows SET row_data = ? WHERE id = ?');
        foreach ($rows as $row) {
            $decoded = json_decode((string)($row['row_data'] ?? ''), true);
            $rowData = is_array($decoded) ? $decoded : [];
            $normalizedRow = $this->normalizeRowForHeaders($rowData, $headers);
            $stmtUpdateRow->execute([
                json_encode($normalizedRow, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                (int)$row['id'],
            ]);
        }
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
