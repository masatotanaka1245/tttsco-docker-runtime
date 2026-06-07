<?php

class CsvAggregationTargetResolver
{
    private $pdo;
    private $metadataCatalog;
    private $sampleRowRepository;
    private $dateColumnDetector;
    private $queryBuilder;
    private $logger;

    public function __construct(
        PDO $pdo,
        CsvMetadataCatalog $metadataCatalog,
        CsvSampleRowRepository $sampleRowRepository,
        CsvDateColumnDetector $dateColumnDetector,
        CsvAggregationQueryBuilder $queryBuilder,
        ?callable $logger = null
    ) {
        $this->pdo = $pdo;
        $this->metadataCatalog = $metadataCatalog;
        $this->sampleRowRepository = $sampleRowRepository;
        $this->dateColumnDetector = $dateColumnDetector;
        $this->queryBuilder = $queryBuilder;
        $this->logger = $logger;
    }

    public function detectDateTargets(array $plan): array
    {
        $files = $this->metadataCatalog->loadFiles();
        if (!empty($plan['target_file_name'])) {
            $files = array_values(array_filter($files, fn($file) => $file['file_name'] === $plan['target_file_name']));
        }

        $requestedColumn = trim((string)($plan['target_column'] ?? ''));
        $requestedGranularity = (string)($plan['date_granularity'] ?? 'day');
        $targets = [];
        foreach ($files as $file) {
            $sampleRows = $this->sampleRowRepository->loadRowsForFile((int)$file['id'], 12);
            $dateColumns = $this->dateColumnDetector->detectDateColumnsForFile($file, $sampleRows);
            if ($requestedColumn !== '' && in_array($requestedColumn, $dateColumns, true)) {
                $dateColumns = [$requestedColumn];
            } elseif ($requestedGranularity === 'hour') {
                $timeBearingColumns = array_values(array_filter($dateColumns, fn(string $column): bool => $this->isTimeBearingColumnName($column)));
                if (!empty($timeBearingColumns)) {
                    $dateColumns = $timeBearingColumns;
                }
            }
            if (empty($dateColumns)) {
                continue;
            }
            $targets[] = [
                'csv_file_id' => (int)$file['id'],
                'file_name' => (string)$file['file_name'],
                'columns' => $file['columns'],
                'date_columns' => $dateColumns,
                'sample_rows_checked' => count($sampleRows),
            ];
        }

        return $targets;
    }

    public function findFileTarget(string $targetFileName): ?array
    {
        if ($targetFileName === '') {
            return null;
        }

        foreach ($this->metadataCatalog->loadFiles() as $file) {
            if ((string)$file['file_name'] !== $targetFileName) {
                continue;
            }

            return [
                'csv_file_id' => (int)$file['id'],
                'file_name' => (string)$file['file_name'],
                'row_count' => (int)$file['row_count'],
                'columns' => $file['columns'],
            ];
        }

        return null;
    }

    public function findColumnTargets(?string $targetFileName, string $targetColumn): array
    {
        if ($targetColumn === '') {
            return [];
        }

        $targets = [];
        foreach ($this->metadataCatalog->loadFiles() as $file) {
            $fileName = (string)($file['file_name'] ?? '');
            if ($targetFileName !== null && $targetFileName !== '' && $fileName !== $targetFileName) {
                continue;
            }

            $columns = array_map('strval', (array)($file['columns'] ?? []));
            if (!in_array($targetColumn, $columns, true)) {
                continue;
            }

            $targets[] = [
                'csv_file_id' => (int)$file['id'],
                'file_name' => $fileName,
                'row_count' => (int)($file['row_count'] ?? 0),
                'columns' => $columns,
            ];
        }

        return $targets;
    }

    public function executeDateAggregationQuery(array $target, string $dateColumn, array $plan): array
    {
        $csvFileId = (int)$target['csv_file_id'];
        $sql = $this->queryBuilder->buildDateAggregationSql($csvFileId, $dateColumn);

        $this->log("[CSV-AGG-SQL] file={$target['file_name']} | column={$dateColumn} | sql={$sql}");

        $stmt = $this->pdo->query($sql);
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        $normalized = [];
        foreach ($rows as $row) {
            $bucket = $this->dateColumnDetector->normalizeDateBucket((string)($row['raw_date'] ?? ''), $plan['date_granularity']);
            if ($bucket === null) {
                continue;
            }
            $key = $target['file_name'] . '|' . $dateColumn . '|' . $bucket;
            if (!isset($normalized[$key])) {
                $normalized[$key] = [
                    'file_name' => $target['file_name'],
                    'date_column' => $dateColumn,
                    'date' => $bucket,
                    'record_count' => 0,
                    'columns' => $target['columns'],
                ];
            }
            $normalized[$key]['record_count'] += (int)$row['record_count'];
        }

        return [
            'sql' => $sql,
            'raw_group_count' => count($rows),
            'rows' => array_values($normalized),
        ];
    }

    public function executeDistinctCountQuery(array $target, string $targetColumn): array
    {
        $csvFileId = (int)$target['csv_file_id'];
        $sql = $this->queryBuilder->buildDistinctCountSql($csvFileId, $targetColumn);

        $this->log("[CSV-AGG-SQL] file={$target['file_name']} | column={$targetColumn} | sql={$sql}");

        $stmt = $this->pdo->query($sql);
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

        return [
            'sql' => $sql,
            'distinct_count' => (int)($row['distinct_count'] ?? 0),
        ];
    }

    public function executeValueDistributionQuery(array $target, string $targetColumn, ?string $itemSortOrder = null): array
    {
        $csvFileId = (int)$target['csv_file_id'];
        $sql = $this->queryBuilder->buildValueDistributionSql($csvFileId, $targetColumn, $itemSortOrder);

        $this->log("[CSV-AGG-SQL] file={$target['file_name']} | column={$targetColumn} | item_sort=" . ($itemSortOrder ?? 'count_desc') . " | sql={$sql}");

        $stmt = $this->pdo->query($sql);
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        return [
            'sql' => $sql,
            'rows' => $rows,
        ];
    }

    public function executeExactValueCountQuery(array $target, string $targetColumn, string $targetValue): array
    {
        $csvFileId = (int)$target['csv_file_id'];
        $sql = $this->queryBuilder->buildExactValueCountSql($csvFileId, $targetColumn, $targetValue);

        $this->log("[CSV-AGG-SQL] file={$target['file_name']} | column={$targetColumn} | target_value={$targetValue} | sql={$sql}");

        $stmt = $this->pdo->query($sql);
        $row = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;

        return [
            'sql' => $sql,
            'matched_count' => (int)($row['matched_count'] ?? 0),
        ];
    }

    public function executeFilteredDistributionQuery(array $target, string $sourceColumn, string $targetColumn, array $matchedValues, ?string $itemSortOrder = null): array
    {
        $csvFileId = (int)$target['csv_file_id'];
        $sql = $this->queryBuilder->buildFilteredDistributionSql($csvFileId, $sourceColumn, $targetColumn, $matchedValues, $itemSortOrder);

        $this->log("[CSV-AGG-SQL] file={$target['file_name']} | source={$sourceColumn} | filter_values=" . count($matchedValues) . " | column={$targetColumn} | item_sort=" . ($itemSortOrder ?? 'count_desc') . " | sql={$sql}");

        $stmt = $this->pdo->query($sql);
        $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];

        return [
            'sql' => $sql,
            'rows' => $rows,
        ];
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }

    private function isTimeBearingColumnName(string $column): bool
    {
        return preg_match('/(datetime|timestamp|time|時刻|日時)/iu', $column) === 1;
    }
}
