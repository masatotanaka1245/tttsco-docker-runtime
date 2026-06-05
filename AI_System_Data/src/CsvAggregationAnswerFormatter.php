<?php

class CsvAggregationAnswerFormatter
{
    public function buildMissingColumnAnswer(array $plan): string
    {
        $column = trim((string)($plan['target_column'] ?? ''));
        $targetFileName = trim((string)($plan['target_file_name'] ?? ''));
        $targetValue = trim((string)($plan['target_value'] ?? ''));
        $scopeLabel = $targetFileName !== '' ? $targetFileName : '登録済みCSV全体';

        $lines = [];
        $lines[] = "{$scopeLabel} から集計対象を確認しましたが、指定された列は見つかりませんでした。";
        $lines[] = "";
        if ($targetFileName !== '') {
            $lines[] = "- 対象CSV: {$targetFileName}";
        } else {
            $lines[] = "- 対象範囲: 登録済みCSV全体";
        }
        if ($column !== '') {
            $lines[] = "- 指定列: {$column}";
        }
        if ($targetValue !== '') {
            $lines[] = "- 指定値: {$targetValue}";
        }
        $lines[] = "- 結果: 該当列を確認できなかったため、集計は実行していません。";
        $lines[] = "";
        $lines[] = "列名の表記ゆれがないか、または対象CSVをもう少し具体的に指定していただければ再確認できます。";

        return implode("\n", $lines);
    }

    public function buildColumnSemanticsAnswer(array $plan, array $target, array $rows, array $analysis, bool $diagramMode = false): string
    {
        $fileName = (string)($target['file_name'] ?? ($plan['target_file_name'] ?? '対象CSV'));
        $column = (string)($plan['target_column'] ?? '');
        $rowCount = (int)($target['row_count'] ?? 0);
        $summary = trim((string)($analysis['overall_summary'] ?? ''));
        $items = is_array($analysis['items'] ?? null) ? $analysis['items'] : [];
        $observations = is_array($analysis['observations'] ?? null) ? $analysis['observations'] : [];
        $topRows = array_slice($rows, 0, 12);

        $lines = [];
        $lines[] = "{$fileName} の {$column} 列について、値の名前と件数分布からイベントの意味を整理しました。";
        $lines[] = "";
        $lines[] = "- 対象CSV: {$fileName}";
        $lines[] = "- 説明対象列: {$column}";
        if ($rowCount > 0) {
            $lines[] = "- 元レコード数: {$rowCount}件";
        }
        $lines[] = "- ユニーク値数: " . count($rows) . "件";
        $lines[] = "";

        if ($summary !== '') {
            $lines[] = "### 全体の見立て";
            $lines[] = $summary;
            $lines[] = "";
        }

        if (!empty($items)) {
            $lines[] = "### 主なイベントの説明";
            foreach ($items as $item) {
                $name = trim((string)($item['name'] ?? ''));
                if ($name === '') {
                    continue;
                }
                $count = (int)($item['count'] ?? 0);
                $group = trim((string)($item['group'] ?? ''));
                $meaning = trim((string)($item['inferred_meaning'] ?? ''));
                $lines[] = "- **{$name}**" . ($count > 0 ? " ({$count}件)" : '') . ($group !== '' ? " / {$group}" : '');
                if ($meaning !== '') {
                    $lines[] = "  - {$meaning}";
                }
            }
            $lines[] = "";
        }

        $lines[] = "### 件数の上位";
        $lines[] = "| 値 | 件数 |";
        $lines[] = "| --- | ---: |";
        foreach ($topRows as $row) {
            $item = str_replace("\n", ' ', (string)($row['item'] ?? ''));
            $lines[] = "| {$item} | " . (int)($row['record_count'] ?? 0) . " |";
        }

        if (!empty($observations)) {
            $lines[] = "";
            $lines[] = "### 補足";
            foreach (array_slice($observations, 0, 3) as $observation) {
                $observation = trim((string)$observation);
                if ($observation !== '') {
                    $lines[] = "- {$observation}";
                }
            }
        }

        if ($diagramMode && !empty($topRows)) {
            $chart = [
                'type' => count($topRows) <= 6 ? 'pie' : 'bar',
                'title' => "{$column} 列の主要イベント件数",
                'labels' => array_map(fn($row) => (string)($row['item'] ?? ''), $topRows),
                'datasets' => [[
                    'label' => '件数',
                    'data' => array_map(fn($row) => (int)($row['record_count'] ?? 0), $topRows),
                ]],
            ];
            $fence = str_repeat("\x60", 3);
            $lines[] = "";
            $lines[] = "### グラフ";
            $lines[] = $fence . "json:chart\n" . json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n" . $fence;
        }

        return trim(implode("\n", $lines));
    }

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

    public function buildValueDistributionAnswer(array $plan, array $target, array $rows, bool $diagramMode = false): string
    {
        $fileName = (string)($target['file_name'] ?? ($plan['target_file_name'] ?? '対象CSV'));
        $column = (string)($plan['target_column'] ?? '');
        $rowCount = (int)($target['row_count'] ?? 0);
        $matchedFiles = array_values(array_filter(array_map('strval', (array)($target['matched_files'] ?? []))));
        $uniqueCount = count($rows);
        $maxCount = !empty($rows) ? max(array_map(fn($row) => (int)($row['record_count'] ?? 0), $rows)) : 0;
        $allSingle = $uniqueCount > 0 && $maxCount <= 1;
        $showAllValues = (bool)($plan['wants_all_values'] ?? false) || $uniqueCount <= 15;
        $usesValueOrdering = !empty($plan['uses_value_ordering']);
        $previewRows = $showAllValues ? $rows : array_slice($rows, 0, 15);

        $lines = [];
        $lines[] = "{$fileName} の {$column} 列について、値ごとの件数分布を集計しました。";
        $lines[] = "";
        $lines[] = "- 対象CSV: {$fileName}";
        if (!empty($matchedFiles)) {
            $lines[] = "- 対象ファイル一覧: " . implode(' / ', $matchedFiles);
        }
        $lines[] = "- 集計列: {$column}";
        if ($rowCount > 0) {
            $lines[] = "- 元レコード数: {$rowCount}件";
        }
        $lines[] = "- ユニーク値数: {$uniqueCount}件";
        if ($uniqueCount > 0) {
            $lines[] = "- 最大出現回数: {$maxCount}件";
        }
        if ($usesValueOrdering) {
            $lines[] = "- 並び順: " . $this->distributionSortOrderLabel((string)($plan['sort_order'] ?? 'asc'));
        }
        $lines[] = "";

        if ($allSingle) {
            $lines[] = "### 見立て";
            $lines[] = "{$column} 列は重複がほとんどなく、個別テーマや個別課題が幅広く登録されている状態です。";
            $lines[] = "";
        }

        if ($showAllValues) {
            $lines[] = "### 値ごとの件数一覧";
        } elseif ($usesValueOrdering) {
            $lines[] = "### 並び順に沿った件数一覧";
        } else {
            $lines[] = "### 上位の値分布";
        }
        $lines[] = "| 値 | 件数 |";
        $lines[] = "| --- | ---: |";
        foreach ($previewRows as $row) {
            $item = str_replace("\n", ' ', (string)($row['item'] ?? ''));
            $lines[] = "| {$item} | " . (int)($row['record_count'] ?? 0) . " |";
        }

        if (!$showAllValues && $uniqueCount > count($previewRows)) {
            $lines[] = "";
            $lines[] = $usesValueOrdering
                ? "※ 件数が多いため、並び順に沿って先頭 " . count($previewRows) . " 件を表示しています。"
                : "※ 件数が多いため、上位 " . count($previewRows) . " 件を表示しています。";
        }

        if ($diagramMode && !empty($previewRows)) {
            $chartRows = count($previewRows) > 20 ? array_slice($previewRows, 0, 20) : $previewRows;
            $chart = [
                'type' => 'bar',
                'title' => $showAllValues
                    ? "{$column} 列の値ごとの件数"
                    : ($usesValueOrdering ? "{$column} 列の並び順に沿った件数" : "{$column} 列の上位件数分布"),
                'labels' => array_map(fn($row) => (string)($row['item'] ?? ''), $chartRows),
                'datasets' => [[
                    'label' => '件数',
                    'data' => array_map(fn($row) => (int)($row['record_count'] ?? 0), $chartRows),
                ]],
            ];
            $fence = str_repeat("\x60", 3);
            $lines[] = "";
            $lines[] = "### グラフ";
            $lines[] = $fence . "json:chart\n" . json_encode($chart, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n" . $fence;
        }

        return implode("\n", $lines);
    }

    public function buildDistinctCountAnswer(array $plan, array $target, int $distinctCount): string
    {
        $fileName = (string)($target['file_name'] ?? ($plan['target_file_name'] ?? '対象CSV'));
        $column = (string)($plan['target_column'] ?? '');
        $rowCount = (int)($target['row_count'] ?? 0);
        $columns = $target['columns'] ?? [];
        $matchedFiles = array_values(array_filter(array_map('strval', (array)($target['matched_files'] ?? []))));

        $lines = [];
        $lines[] = "{$fileName} の {$column} 列を対象に、重複を除いた件数を集計しました。";
        $lines[] = "";
        $lines[] = "- 対象CSV: {$fileName}";
        if (!empty($matchedFiles)) {
            $lines[] = "- 対象ファイル一覧: " . implode(' / ', $matchedFiles);
        }
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

    public function buildExactValueCountAnswer(array $plan, array $target, int $matchedCount): string
    {
        $fileName = (string)($target['file_name'] ?? ($plan['target_file_name'] ?? '対象CSV'));
        $column = (string)($plan['target_column'] ?? '');
        $targetValue = (string)($plan['target_value'] ?? '');
        $rowCount = (int)($target['row_count'] ?? 0);
        $matchedFiles = array_values(array_filter(array_map('strval', (array)($target['matched_files'] ?? []))));

        $lines = [];
        $lines[] = "{$fileName} の {$column} 列から「{$targetValue}」を抽出し、件数を集計しました。";
        $lines[] = "";
        $lines[] = "- 対象CSV: {$fileName}";
        if (!empty($matchedFiles)) {
            $lines[] = "- 対象ファイル一覧: " . implode(' / ', $matchedFiles);
        }
        $lines[] = "- 集計列: {$column}";
        $lines[] = "- 抽出値: {$targetValue}";
        $lines[] = "- 該当件数: {$matchedCount}件";
        if ($rowCount > 0) {
            $lines[] = "- 元レコード数: {$rowCount}件";
        }

        return implode("\n", $lines);
    }

    public function buildStructuredAggregationAnswer(array $plan, array $aggregatedRows, array $targets, bool $diagramMode = false): string
    {
        $targetFileCount = count(array_unique(array_map(fn($row) => $row['file_name'], $aggregatedRows)));
        $dateColumnCount = count(array_unique(array_map(fn($row) => $row['file_name'] . '|' . $row['date_column'], $aggregatedRows)));
        $totalCount = array_sum(array_map(fn($row) => (int)$row['record_count'], $aggregatedRows));
        $summaryLines = $this->buildDateAggregationSummaryLines($plan, $aggregatedRows);

        $lines = [];
        $lines[] = "日付に関する集計要求として解釈し、CSVサンプルから日付列を判定したうえで構造化集計を行いました。";
        $lines[] = "";
        $lines[] = "- 対象CSVファイル数: {$targetFileCount}件";
        $lines[] = "- 判定した日付列数: {$dateColumnCount}件";
        $lines[] = "- 集計対象レコード数: {$totalCount}件";
        $lines[] = "- 集計粒度: " . $this->granularityLabel((string)($plan['date_granularity'] ?? 'day'));
        $lines[] = "- 並び順: " . $this->sortOrderLabel((string)($plan['sort_order'] ?? 'asc'));
        $lines[] = "";
        $lines[] = "### 判定した日付列";
        foreach ($targets as $target) {
            $lines[] = "- {$target['file_name']}: " . implode(' / ', $target['date_columns']);
        }
        $lines[] = "";
        if (!empty($summaryLines)) {
            $lines[] = "### 集計サマリー";
            foreach ($summaryLines as $summaryLine) {
                $lines[] = "- {$summaryLine}";
            }
            $lines[] = "";
        }
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
        if ((string)($plan['sort_order'] ?? 'asc') === 'desc') {
            $labels = array_reverse($labels);
        }
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

    private function sortOrderLabel(string $sortOrder): string
    {
        return $sortOrder === 'desc' ? '新しい順 / 降順' : '古い順 / 昇順';
    }

    private function distributionSortOrderLabel(string $sortOrder): string
    {
        return $sortOrder === 'desc' ? '値の降順' : '値の昇順';
    }

    private function buildDateAggregationSummaryLines(array $plan, array $aggregatedRows): array
    {
        if (empty($aggregatedRows)) {
            return [];
        }

        $granularity = (string)($plan['date_granularity'] ?? 'day');
        $datasetGroups = [];
        foreach ($aggregatedRows as $row) {
            $datasetKey = (string)$row['file_name'] . '|' . (string)$row['date_column'];
            $datasetGroups[$datasetKey][] = $row;
        }

        if (count($datasetGroups) !== 1) {
            return [
                '複数のCSVまたは複数の日付列が含まれるため、件数推移は下表とグラフで比較してください。',
            ];
        }

        $rows = array_values(reset($datasetGroups));
        usort($rows, static function ($a, $b) {
            return [$a['date'], $a['file_name'], $a['date_column']] <=> [$b['date'], $b['file_name'], $b['date_column']];
        });

        $firstRow = $rows[0];
        $lastRow = $rows[count($rows) - 1];
        $maxRow = $rows[0];
        $minRow = $rows[0];

        foreach ($rows as $row) {
            if ((int)$row['record_count'] > (int)$maxRow['record_count']) {
                $maxRow = $row;
            }
            if ((int)$row['record_count'] < (int)$minRow['record_count']) {
                $minRow = $row;
            }
        }

        $label = match ($granularity) {
            'month' => '月',
            'year' => '年',
            default => '日付',
        };

        $lines = [];
        $lines[] = "対象列は {$firstRow['file_name']} / {$firstRow['date_column']} です。";
        $lines[] = "集計{$label}数は " . count($rows) . " 件です。";
        $lines[] = "最も古い{$label}は {$firstRow['date']} ({$firstRow['record_count']}件)、最も新しい{$label}は {$lastRow['date']} ({$lastRow['record_count']}件) です。";
        $lines[] = "最大件数の{$label}は {$maxRow['date']} ({$maxRow['record_count']}件)、最小件数の{$label}は {$minRow['date']} ({$minRow['record_count']}件) です。";

        if (count($rows) >= 2) {
            $delta = (int)$lastRow['record_count'] - (int)$firstRow['record_count'];
            if ($delta > 0) {
                $lines[] = "最初と最後を比べると、件数は {$delta} 件増えています。";
            } elseif ($delta < 0) {
                $lines[] = "最初と最後を比べると、件数は " . abs($delta) . " 件減っています。";
            } else {
                $lines[] = "最初と最後を比べると、件数は同水準です。";
            }
        }

        return $lines;
    }
}
