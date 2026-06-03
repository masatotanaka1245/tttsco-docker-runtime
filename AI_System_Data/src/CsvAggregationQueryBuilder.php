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

    public function buildValueDistributionSql(int $csvFileId, string $column): string
    {
        $escapedKey = $this->escapeJsonPathKey($column);
        $jsonExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(r.row_data, '$.\"{$escapedKey}\"')), '')";

        return "SELECT {$jsonExpr} AS item, COUNT(*) AS record_count
                FROM project_csv_rows r
                WHERE r.csv_file_id = {$csvFileId}
                  AND {$jsonExpr} IS NOT NULL
                GROUP BY item
                ORDER BY record_count DESC, item ASC";
    }

    public function buildFilteredDistributionSql(int $csvFileId, string $sourceColumn, string $targetColumn, array $allowedValues): string
    {
        $sourceExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(r.row_data, '$.\"" . $this->escapeJsonPathKey($sourceColumn) . "\"')), '')";
        $targetExpr = "NULLIF(JSON_UNQUOTE(JSON_EXTRACT(r.row_data, '$.\"" . $this->escapeJsonPathKey($targetColumn) . "\"')), '')";
        $quotedValues = array_map(function (string $value): string {
            return "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'";
        }, $allowedValues);
        $inClause = implode(', ', $quotedValues);

        return "SELECT {$targetExpr} AS item, COUNT(*) AS record_count
                FROM project_csv_rows r
                WHERE r.csv_file_id = {$csvFileId}
                  AND {$sourceExpr} IN ({$inClause})
                  AND {$targetExpr} IS NOT NULL
                GROUP BY item
                ORDER BY record_count DESC, item ASC";
    }

    public function escapeJsonPathKey(string $key): string
    {
        $key = str_replace('\\', '\\\\', $key);
        return str_replace("'", "\\'", $key);
    }
}
