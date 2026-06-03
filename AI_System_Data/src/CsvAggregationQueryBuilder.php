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

    public function escapeJsonPathKey(string $key): string
    {
        $key = str_replace('\\', '\\\\', $key);
        return str_replace("'", "\\'", $key);
    }
}
