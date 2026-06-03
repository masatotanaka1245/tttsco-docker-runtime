<?php

class CsvAggregationAnswerFormatter
{
    public function buildSemanticCategoryAnswer(array $plan, array $target, array $rows, array $analysis, bool $diagramMode = false): string
    {
        $fileName = (string)($target['file_name'] ?? ($plan['target_file_name'] ?? '対象CSV'));
        $column = (string)($plan['target_column'] ?? '');
        $rowCount = (int)($target['row_count'] ?? 0);
        $uniqueCount = count($rows);
        $categories = is_array($analysis['categories'] ?? null) ? $analysis['categories'] : [];
        $summary = trim((string)($analysis['overall_summary'] ?? ''));
        $observations = is_array($analysis['observations'] ?? null) ? $analysis['observations'] : [];

        $lines = [];
        $lines[] = "{$fileName} の {$column} 列について、値の分布をもとにカテゴリ別の傾向を整理しました。";
        $lines[] = "";
        $lines[] = "- 対象CSV: {$fileName}";
        $lines[] = "- 分析列: {$column}";
        if ($rowCount > 0) {
            $lines[] = "- 元レコード数: {$rowCount}件";
        }
        $lines[] = "- ユニーク値数: {$uniqueCount}件";
        $lines[] = "";

        if ($summary !== '') {
            $lines[] = "### 分析サマリー";
            $lines[] = $summary;
            $lines[] = "";
        }

        if (!empty($categories)) {
            $lines[] = "### カテゴリ別の整理";
            foreach ($categories as $index => $category) {
                $name = trim((string)($category['name'] ?? 'カテゴリ' . ($index + 1)));
                $count = (int)($category['count'] ?? 0);
                $examples = array_values(array_filter(array_map('strval', (array)($category['examples'] ?? []))));
                $insight = trim((string)($category['insight'] ?? ''));

                $lines[] = "#### " . ($index + 1) . ". {$name}" . ($count > 0 ? " ({$count}件)" : '');
                if (!empty($examples)) {
                    $lines[] = "- 代表例: " . implode(' / ', array_slice($examples, 0, 4));
                }
                if ($insight !== '') {
                    $lines[] = "- 見立て: {$insight}";
                }
            }
            $lines[] = "";
        }

        if (!empty($observations)) {
            $lines[] = "### 補足";
            foreach (array_slice($observations, 0, 3) as $observation) {
                $observation = trim((string)$observation);
                if ($observation !== '') {
                    $lines[] = "- {$observation}";
                }
            }
            $lines[] = "";
        }

        if ($diagramMode && !empty($categories)) {
            $labels = [];
            $data = [];
            foreach ($categories as $category) {
                $name = trim((string)($category['name'] ?? 'カテゴリ'));
                $count = (int)($category['count'] ?? 0);
                if ($name === '' || $count <= 0) {
                    continue;
                }
                $labels[] = $name;
                $data[] = $count;
            }
            if (!empty($labels)) {
                $chart = [
                    'type' => count($labels) <= 5 ? 'pie' : 'bar',
                    'title' => "{$column} 列のカテゴリ別分布",
                    'labels' => $labels,
                    'datasets' => [[
                        'label' => '件数',
                        'data' => $data,
                    ]],
                ];
                $fence = str_repeat("\x60", 3);
                $lines[] = "### グラフ";
                $lines[] = $fence . "json:chart\n" . json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n" . $fence;
            }
        }

        return trim(implode("\n", $lines));
    }

    public function buildCategoryFilteredDistributionAnswer(array $plan, array $target, array $rows, array $matchedValues, bool $diagramMode = false): string
    {
        $fileName = (string)($target['file_name'] ?? ($plan['target_file_name'] ?? '対象CSV'));
        $targetColumn = (string)($plan['target_column'] ?? '');
        $sourceColumn = (string)($plan['source_column'] ?? '');
        $categoryLabel = (string)($plan['category_filter_label'] ?? '');
        $total = array_sum(array_map(fn($row) => (int)($row['record_count'] ?? 0), $rows));

        $lines = [];
        $lines[] = "{$fileName} の {$sourceColumn} 列をもとに「{$categoryLabel}」へ該当するレコードを抽出し、{$targetColumn} 別に件数を集計しました。";
        $lines[] = "";
        $lines[] = "- 対象CSV: {$fileName}";
        $lines[] = "- 判定カテゴリ: {$categoryLabel}";
        $lines[] = "- 判定に使った列: {$sourceColumn}";
        $lines[] = "- 集計列: {$targetColumn}";
        $lines[] = "- 該当タイトル数: " . count($matchedValues) . "件";
        $lines[] = "- 該当レコード数: {$total}件";
        $lines[] = "";

        if (!empty($matchedValues)) {
            $lines[] = "### 該当タイトルの例";
            foreach (array_slice($matchedValues, 0, 6) as $value) {
                $lines[] = "- {$value}";
            }
            $lines[] = "";
        }

        $lines[] = "### {$targetColumn} 別の件数";
        $lines[] = "| {$targetColumn} | 件数 |";
        $lines[] = "| --- | ---: |";
        foreach ($rows as $row) {
            $item = str_replace("\n", ' ', (string)($row['item'] ?? ''));
            $lines[] = "| {$item} | " . (int)($row['record_count'] ?? 0) . " |";
        }

        if ($diagramMode && !empty($rows)) {
            $chart = [
                'type' => count($rows) <= 6 ? 'pie' : 'bar',
                'title' => "{$categoryLabel} に該当する {$targetColumn} 別件数",
                'labels' => array_map(fn($row) => (string)($row['item'] ?? ''), $rows),
                'datasets' => [[
                    'label' => '件数',
                    'data' => array_map(fn($row) => (int)($row['record_count'] ?? 0), $rows),
                ]],
            ];
            $fence = str_repeat("\x60", 3);
            $lines[] = "";
            $lines[] = "### グラフ";
            $lines[] = $fence . "json:chart\n" . json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n" . $fence;
        }

        return implode("\n", $lines);
    }

    public function buildValueDistributionAnswer(array $plan, array $target, array $rows): string
    {
        $fileName = (string)($target['file_name'] ?? ($plan['target_file_name'] ?? '対象CSV'));
        $column = (string)($plan['target_column'] ?? '');
        $rowCount = (int)($target['row_count'] ?? 0);
        $uniqueCount = count($rows);
        $topCount = !empty($rows) ? (int)$rows[0]['record_count'] : 0;
        $allSingle = $uniqueCount > 0 && $topCount <= 1;
        $previewRows = array_slice($rows, 0, 15);

        $lines = [];
        $lines[] = "{$fileName} の {$column} 列について、値ごとの件数分布を集計しました。";
        $lines[] = "";
        $lines[] = "- 対象CSV: {$fileName}";
        $lines[] = "- 集計列: {$column}";
        if ($rowCount > 0) {
            $lines[] = "- 元レコード数: {$rowCount}件";
        }
        $lines[] = "- ユニーク値数: {$uniqueCount}件";
        if ($uniqueCount > 0) {
            $lines[] = "- 最大出現回数: {$topCount}件";
        }
        $lines[] = "";

        if ($allSingle) {
            $lines[] = "### 見立て";
            $lines[] = "{$column} 列は重複がほとんどなく、個別テーマや個別課題が幅広く登録されている状態です。";
            $lines[] = "";
        }

        $lines[] = "### 上位の値分布";
        $lines[] = "| 値 | 件数 |";
        $lines[] = "| --- | ---: |";
        foreach ($previewRows as $row) {
            $item = str_replace("\n", ' ', (string)($row['item'] ?? ''));
            $lines[] = "| {$item} | " . (int)($row['record_count'] ?? 0) . " |";
        }

        if ($uniqueCount > count($previewRows)) {
            $lines[] = "";
            $lines[] = "※ 件数が多いため、上位 " . count($previewRows) . " 件を表示しています。";
        }

        return implode("\n", $lines);
    }

    public function buildDistinctCountAnswer(array $plan, array $target, int $distinctCount): string
    {
        $fileName = (string)($target['file_name'] ?? ($plan['target_file_name'] ?? '対象CSV'));
        $column = (string)($plan['target_column'] ?? '');
        $rowCount = (int)($target['row_count'] ?? 0);
        $columns = $target['columns'] ?? [];

        $lines = [];
        $lines[] = "{$fileName} の {$column} 列を対象に、重複を除いた件数を集計しました。";
        $lines[] = "";
        $lines[] = "- 対象CSV: {$fileName}";
        $lines[] = "- 集計列: {$column}";
        $lines[] = "- ユニーク件数: {$distinctCount}件";
        if ($rowCount > 0) {
            $lines[] = "- 元レコード数: {$rowCount}件";
        }
        if (!empty($columns)) {
            $lines[] = "- 主な項目: " . implode(' / ', array_slice($columns, 0, 8));
        }

        return implode("\n", $lines);
    }

    public function buildStructuredAggregationAnswer(array $plan, array $aggregatedRows, array $targets, bool $diagramMode = false): string
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

        if ($diagramMode) {
            $chartBlock = $this->buildAggregationChartBlock($plan, $aggregatedRows);
            if ($chartBlock !== '') {
                $lines[] = "";
                $lines[] = "### グラフ";
                $lines[] = $chartBlock;
            }
        }

        return implode("\n", $lines);
    }

    private function buildAggregationChartBlock(array $plan, array $aggregatedRows): string
    {
        if (empty($aggregatedRows)) {
            return '';
        }

        $labels = array_values(array_unique(array_map(fn($row) => (string)$row['date'], $aggregatedRows)));
        sort($labels);
        if (empty($labels)) {
            return '';
        }

        $seriesMap = [];
        $isSingleFile = $this->isSingleFileScope($plan);
        foreach ($aggregatedRows as $row) {
            $datasetKey = $row['file_name'] . ' / ' . $row['date_column'];
            if (!isset($seriesMap[$datasetKey])) {
                $seriesMap[$datasetKey] = [
                    'label' => $this->buildDatasetLabel((string)$row['file_name'], (string)$row['date_column'], $isSingleFile),
                    'values' => [],
                ];
            }
            $seriesMap[$datasetKey]['values'][(string)$row['date']] = (int)$row['record_count'];
        }

        $datasets = [];
        foreach ($seriesMap as $series) {
            $data = [];
            foreach ($labels as $label) {
                $data[] = (int)($series['values'][$label] ?? 0);
            }
            $datasets[] = [
                'label' => $series['label'],
                'data' => $data,
            ];
        }

        if (empty($datasets)) {
            return '';
        }

        $chartType = $this->selectChartType($plan, $datasets);
        $chart = [
            'type' => $chartType,
            'title' => $this->buildChartTitle($plan, count($datasets)),
            'labels' => $labels,
            'datasets' => $datasets,
        ];

        $fence = str_repeat("\x60", 3);
        return $this->buildChartLeadText($chartType)
            . "\n" . $fence . "json:chart\n"
            . json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n" . $fence;
    }

    private function buildChartTitle(array $plan, int $datasetCount): string
    {
        $granularity = $this->granularityLabel((string)($plan['date_granularity'] ?? 'day'));
        $scope = $this->isSingleFileScope($plan)
            ? '単一CSV'
            : '複数CSV';
        $seriesLabel = $datasetCount > 1 ? '系列比較' : '件数推移';

        return "{$scope}の{$granularity}{$seriesLabel}";
    }

    private function buildChartLeadText(string $chartType): string
    {
        if ($chartType === 'bar') {
            return "日付列の種類が複数あるため、系列ごとの差を見比べやすいよう棒グラフで可視化しました。";
        }

        return "集計結果を時系列で確認できるよう、CSVごとに件数推移を可視化しました。";
    }

    private function selectChartType(array $plan, array $datasets): string
    {
        if ($this->isSingleFileScope($plan) && count($datasets) <= 3) {
            return 'line';
        }

        return 'bar';
    }

    private function buildDatasetLabel(string $fileName, string $dateColumn, bool $isSingleFile): string
    {
        if ($isSingleFile) {
            return $dateColumn;
        }

        return $this->compactFileLabel($fileName) . ' / ' . $dateColumn;
    }

    private function compactFileLabel(string $fileName): string
    {
        $label = preg_replace('/\.csv$/i', '', $fileName) ?? $fileName;
        $parenPos = strpos($label, '(');
        if ($parenPos !== false) {
            $head = trim(substr($label, 0, $parenPos));
            if ($head !== '') {
                $label = $head;
            }
        }

        if (strlen($label) > 36) {
            return substr($label, 0, 33) . '...';
        }

        return $label;
    }

    private function isSingleFileScope(array $plan): bool
    {
        return (($plan['scope'] ?? '') === 'single_file' || !empty($plan['target_file_name']));
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
