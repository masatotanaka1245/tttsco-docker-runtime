<?php

class CsvAggregationAnswerFormatter
{
    public function buildStructuredAggregationAnswer(array $plan, array $aggregatedRows, array $targets): string
    {
        $targetFileCount = count(array_unique(array_map(fn($row) => $row['file_name'], $aggregatedRows)));
        $dateColumnCount = count(array_unique(array_map(fn($row) => $row['file_name'] . '|' . $row['date_column'], $aggregatedRows)));
        $totalCount = array_sum(array_map(fn($row) => (int)$row['record_count'], $aggregatedRows));

        $lines = [];
        $lines[] = "日付に関する集計要求として解釈し、CSVサンプルから日付列を判定したうえで構造化集計を行いました。";
        $lines[] = "";
        $lines[] = "- 対象CSVファイル数: {$targetFileCount}件";
        $lines[] = "- 判定した日付列数: {$dateColumnCount}件";
        $lines[] = "- 集計対象レコード数: {$totalCount}件";
        $lines[] = "- 集計粒度: " . $this->granularityLabel((string)($plan['date_granularity'] ?? 'day'));
        $lines[] = "";
        $lines[] = "### 判定した日付列";
        foreach ($targets as $target) {
            $lines[] = "- {$target['file_name']}: " . implode(' / ', $target['date_columns']);
        }
        $lines[] = "";
        $lines[] = "### 集計結果";
        $lines[] = "| CSVファイル | 日付列 | 日付 | 件数 | 主な項目 |";
        $lines[] = "| --- | --- | --- | ---: | --- |";

        foreach ($aggregatedRows as $row) {
            $majorColumns = array_values(array_filter($row['columns'], fn($column) => $column !== $row['date_column']));
            $majorColumns = array_slice($majorColumns, 0, 4);
            $lines[] = "| {$row['file_name']} | {$row['date_column']} | {$row['date']} | {$row['record_count']} | " . implode(' / ', $majorColumns) . " |";
        }

        $lines[] = "";
        $lines[] = "今回はAI読解ではなく、日付候補列を検出してから件数集計SQLを実行しています。";
        $lines[] = "そのため、`全ての` のような広い表現でも、まず日付列の有無を確認してから集計へ進む挙動になります。";

        return implode("\n", $lines);
    }

    private function granularityLabel(string $granularity): string
    {
        if ($granularity === 'month') {
            return '月別';
        }

        if ($granularity === 'year') {
            return '年別';
        }

        return '日付別';
    }
}
