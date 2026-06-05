<?php

class CsvAggregationQueryBuilder
{
    public function buildDateAggregationSql(int $csvFileId, string $dateColumn): string
    {
        $escapedKey = $this->escapeJsonPathKey($dateColumn);
        $jsonExpr = "JSON_UNQUOTE(JSON_EXTRACT(r.row_data, '$.\"{$escapedKey}\"'))";

        return "SELECT {$jsonExpr} AS raw_date, COUNT(*) AS record_count
                FROM project_csv_rows r
                WHERE r.csv_file_id = {$csvFileId}
                GROUP BY raw_date
                ORDER BY raw_date ASC";
    }

    public function buildDistinctCountSql(int $csvFileId, string $column): string
    {
        $escapedKey = $this->escapeJsonPathKey($column);
        $jsonExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(r.row_data, '$.\"{$escapedKey}\"')), '')";

        return "SELECT COUNT(DISTINCT {$jsonExpr}) AS distinct_count
                FROM project_csv_rows r
                WHERE r.csv_file_id = {$csvFileId}";
    }

    public function buildValueDistributionSql(int $csvFileId, string $column, ?string $itemSortOrder = null): string
    {
        $escapedKey = $this->escapeJsonPathKey($column);
        $jsonExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(r.row_data, '$.\"{$escapedKey}\"')), '')";
        $orderBy = $itemSortOrder === 'desc'
            ? 'item DESC'
            : ($itemSortOrder === 'asc' ? 'item ASC' : 'record_count DESC, item ASC');

        return "SELECT {$jsonExpr} AS item, COUNT(*) AS record_count
                FROM project_csv_rows r
                WHERE r.csv_file_id = {$csvFileId}
                  AND {$jsonExpr} IS NOT NULL
                GROUP BY item
                ORDER BY {$orderBy}";
    }

    public function buildExactValueCountSql(int $csvFileId, string $column, string $targetValue): string
    {
        $escapedKey = $this->escapeJsonPathKey($column);
        $escapedValue = str_replace(["\\", "'"], ["\\\\", "\\'"], $targetValue);
        $jsonExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(r.row_data, '$.\"{$escapedKey}\"')), '')";

        return "SELECT COUNT(*) AS matched_count
                FROM project_csv_rows r
                WHERE r.csv_file_id = {$csvFileId}
                  AND {$jsonExpr} = '{$escapedValue}'";
    }

    public function buildFilteredDistributionSql(int $csvFileId, string $sourceColumn, string $targetColumn, array $allowedValues, ?string $itemSortOrder = null): string
    {
        $sourceExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(r.row_data, '$.\"" . $this->escapeJsonPathKey($sourceColumn) . "\"')), '')";
        $targetExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(r.row_data, '$.\"" . $this->escapeJsonPathKey($targetColumn) . "\"')), '')";
        $quotedValues = array_map(function (string $value): string {
            return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
        }, $allowedValues);
        $inClause = implode(', ', $quotedValues);
        $orderBy = $itemSortOrder === 'desc'
            ? 'item DESC'
            : ($itemSortOrder === 'asc' ? 'item ASC' : 'record_count DESC, item ASC');

        return "SELECT {$targetExpr} AS item, COUNT(*) AS record_count
                FROM project_csv_rows r
                WHERE r.csv_file_id = {$csvFileId}
                  AND {$sourceExpr} IN ({$inClause})
                  AND {$targetExpr} IS NOT NULL
                GROUP BY item
                ORDER BY {$orderBy}";
    }

    public function escapeJsonPathKey(string $key): string
    {
        $key = str_replace('\\', '\\\\', $key);
        return str_replace("'", "\\'", $key);
    }
}
