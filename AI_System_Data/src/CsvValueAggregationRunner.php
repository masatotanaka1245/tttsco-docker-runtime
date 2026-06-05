<?php

class CsvValueAggregationRunner
{
    private $statusSender;
    private $logger;
    private $setFinalResponse;
    private $appendSubAnswer;
    private $insertReasoningStep;
    private $completeRoute;
    private $elapsedFormatter;
    private $diagramMode;

    public function __construct(
        callable $statusSender,
        callable $setFinalResponse,
        callable $appendSubAnswer,
        callable $insertReasoningStep,
        callable $completeRoute,
        callable $elapsedFormatter,
        bool $diagramMode = false,
        ?callable $logger = null
    ) {
        $this->statusSender = $statusSender;
        $this->setFinalResponse = $setFinalResponse;
        $this->appendSubAnswer = $appendSubAnswer;
        $this->insertReasoningStep = $insertReasoningStep;
        $this->completeRoute = $completeRoute;
        $this->elapsedFormatter = $elapsedFormatter;
        $this->diagramMode = $diagramMode;
        $this->logger = $logger;
    }

    public function runDistinctCount(
        array $plan,
        float $routeStart,
        CsvAggregationTargetResolver $targetResolver,
        CsvAggregationAnswerFormatter $formatter
    ): bool {
        $targetFileName = (string)($plan['target_file_name'] ?? '');
        $targetColumn = (string)($plan['target_column'] ?? '');
        $targets = $targetResolver->findColumnTargets($targetFileName !== '' ? $targetFileName : null, $targetColumn);
        if (count($targets) !== 1 || $targetColumn === '') {
            if ($targetColumn !== '' && $this->shouldReturnMissingColumnAnswer($plan)) {
                $finalResponse = $formatter->buildMissingColumnAnswer($plan);
                ($this->setFinalResponse)($finalResponse);
                ($this->appendSubAnswer)($finalResponse);
                $this->log('[CSV-AGG] distinct_count の対象列が見つからないため deterministic に終了します。');
                ($this->completeRoute)();
                return true;
            }
            $this->log('[CSV-AGG] distinct_count の対象ファイルまたは列を解決できませんでした。CSV証拠読解ルートへフォールバックします。');
            return false;
        }
        $target = $targets[0];

        $this->sendStatus(2, '🧭 集計対象のCSVと列を特定しています...');
        ($this->insertReasoningStep)(
            1,
            'CSV distinct count プリフライト',
            "【集計計画】\n" . json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\n【対象CSV】\n" . json_encode($target, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        $this->sendStatus(3, '📊 対象列の重複除外件数を集計しています...');
        $result = $targetResolver->executeDistinctCountQuery($target, $targetColumn);
        $distinctCount = (int)($result['distinct_count'] ?? 0);

        $finalResponse = $formatter->buildDistinctCountAnswer($plan, $target, $distinctCount);
        ($this->setFinalResponse)($finalResponse);
        ($this->appendSubAnswer)($finalResponse);
        ($this->insertReasoningStep)(
            90,
            'CSV distinct count SQL の実行結果',
            "### {$target['file_name']} / {$targetColumn}\n```sql\n{$result['sql']}\n```\n- distinct_count: {$distinctCount}"
        );
        $this->log("[CSV-AGG] distinct_count ルート完了 - count: {$distinctCount} | elapsed: " . $this->elapsed($routeStart));
        ($this->completeRoute)();
        return true;
    }

    public function runValueDistribution(
        array $plan,
        float $routeStart,
        CsvAggregationTargetResolver $targetResolver,
        CsvAggregationAnswerFormatter $formatter,
        ?bool $diagramMode = null
    ): bool {
        $targetFileName = (string)($plan['target_file_name'] ?? '');
        $targetColumn = (string)($plan['target_column'] ?? '');
        $targets = $targetResolver->findColumnTargets($targetFileName !== '' ? $targetFileName : null, $targetColumn);
        if (empty($targets) || $targetColumn === '') {
            if ($targetColumn !== '' && $this->shouldReturnMissingColumnAnswer($plan)) {
                $finalResponse = $formatter->buildMissingColumnAnswer($plan);
                ($this->setFinalResponse)($finalResponse);
                ($this->appendSubAnswer)($finalResponse);
                $this->log('[CSV-AGG] value_distribution の対象列が見つからないため deterministic に終了します。');
                ($this->completeRoute)();
                return true;
            }
            $this->log('[CSV-AGG] value_distribution の対象ファイルまたは列を解決できませんでした。CSV証拠読解ルートへフォールバックします。');
            return false;
        }

        $this->sendStatus(2, '🧭 集計対象のCSVと列を特定しています...');
        ($this->insertReasoningStep)(
            1,
            'CSV value distribution プリフライト',
            "【集計計画】\n" . json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\n【対象CSV】\n" . json_encode($targets, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        $this->sendStatus(3, '📊 対象列の値分布を集計しています...');
        $itemSortOrder = !empty($plan['uses_value_ordering'])
            ? (string)($plan['sort_order'] ?? 'asc')
            : null;
        $combinedRows = [];
        $sqlLogs = [];
        $totalRowCount = 0;
        foreach ($targets as $target) {
            $result = $targetResolver->executeValueDistributionQuery($target, $targetColumn, $itemSortOrder);
            $sqlLogs[] = "### {$target['file_name']} / {$targetColumn}\n```sql\n{$result['sql']}\n```\n- groups: " . count($result['rows'] ?? []);
            $totalRowCount += (int)($target['row_count'] ?? 0);
            foreach (($result['rows'] ?? []) as $row) {
                $item = (string)($row['item'] ?? '');
                if (!isset($combinedRows[$item])) {
                    $combinedRows[$item] = [
                        'item' => $item,
                        'record_count' => 0,
                    ];
                }
                $combinedRows[$item]['record_count'] += (int)($row['record_count'] ?? 0);
            }
        }
        $rows = array_values($combinedRows);
        if (empty($rows)) {
            $this->log('[CSV-AGG] value_distribution の集計結果が0件でした。CSV証拠読解ルートへフォールバックします。');
            return false;
        }

        usort($rows, function (array $a, array $b) use ($itemSortOrder): int {
            if ($itemSortOrder === 'asc' || $itemSortOrder === 'desc') {
                $cmp = strcmp((string)($a['item'] ?? ''), (string)($b['item'] ?? ''));
                return $itemSortOrder === 'desc' ? -$cmp : $cmp;
            }

            $countCmp = (int)($b['record_count'] ?? 0) <=> (int)($a['record_count'] ?? 0);
            if ($countCmp !== 0) {
                return $countCmp;
            }

            return strcmp((string)($a['item'] ?? ''), (string)($b['item'] ?? ''));
        });

        $effectiveDiagramMode = $diagramMode ?? $this->diagramMode;
        $summaryTarget = count($targets) === 1
            ? $targets[0]
            : [
                'file_name' => '複数CSV',
                'row_count' => $totalRowCount,
                'columns' => [],
                'matched_files' => array_map(fn($target) => (string)$target['file_name'], $targets),
            ];
        $finalResponse = $formatter->buildValueDistributionAnswer($plan, $summaryTarget, $rows, $effectiveDiagramMode);
        ($this->setFinalResponse)($finalResponse);
        ($this->appendSubAnswer)($finalResponse);
        ($this->insertReasoningStep)(
            90,
            'CSV value distribution SQL の実行結果',
            implode("\n\n", $sqlLogs)
        );
        $this->log("[CSV-AGG] value_distribution ルート完了 - targets: " . count($targets) . " | groups: " . count($rows) . " | elapsed: " . $this->elapsed($routeStart));
        ($this->completeRoute)();
        return true;
    }

    public function runExactValueCount(
        array $plan,
        float $routeStart,
        CsvAggregationTargetResolver $targetResolver,
        CsvAggregationAnswerFormatter $formatter
    ): bool {
        $targetFileName = (string)($plan['target_file_name'] ?? '');
        $targetColumn = (string)($plan['target_column'] ?? '');
        $targetValue = (string)($plan['target_value'] ?? '');
        $targets = $targetResolver->findColumnTargets($targetFileName !== '' ? $targetFileName : null, $targetColumn);
        if (empty($targets) || $targetColumn === '' || $targetValue === '') {
            if ($targetColumn !== '' && $this->shouldReturnMissingColumnAnswer($plan)) {
                $finalResponse = $formatter->buildMissingColumnAnswer($plan);
                ($this->setFinalResponse)($finalResponse);
                ($this->appendSubAnswer)($finalResponse);
                $this->log('[CSV-AGG] exact_value_count の対象列が見つからないため deterministic に終了します。');
                ($this->completeRoute)();
                return true;
            }
            $this->log('[CSV-AGG] exact_value_count の対象ファイル・列・値を解決できませんでした。CSV証拠読解ルートへフォールバックします。');
            return false;
        }

        $this->sendStatus(2, '🧭 集計対象のCSV・列・抽出値を特定しています...');
        ($this->insertReasoningStep)(
            1,
            'CSV exact value count プリフライト',
            "【集計計画】\n" . json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\n【対象CSV】\n" . json_encode($targets, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        $this->sendStatus(3, '📊 指定値に一致する件数を集計しています...');
        $matchedCount = 0;
        $sqlLogs = [];
        $totalRowCount = 0;
        foreach ($targets as $target) {
            $result = $targetResolver->executeExactValueCountQuery($target, $targetColumn, $targetValue);
            $matchedCount += (int)($result['matched_count'] ?? 0);
            $totalRowCount += (int)($target['row_count'] ?? 0);
            $sqlLogs[] = "### {$target['file_name']} / {$targetColumn}\n```sql\n{$result['sql']}\n```\n- target_value: {$targetValue}\n- matched_count: " . (int)($result['matched_count'] ?? 0);
        }

        $summaryTarget = count($targets) === 1
            ? $targets[0]
            : [
                'file_name' => '複数CSV',
                'row_count' => $totalRowCount,
                'columns' => [],
                'matched_files' => array_map(fn($target) => (string)$target['file_name'], $targets),
            ];
        $finalResponse = $formatter->buildExactValueCountAnswer($plan, $summaryTarget, $matchedCount);
        ($this->setFinalResponse)($finalResponse);
        ($this->appendSubAnswer)($finalResponse);
        ($this->insertReasoningStep)(
            90,
            'CSV exact value count SQL の実行結果',
            implode("\n\n", $sqlLogs)
        );
        $this->log("[CSV-AGG] exact_value_count ルート完了 - targets: " . count($targets) . " | count: {$matchedCount} | elapsed: " . $this->elapsed($routeStart));
        ($this->completeRoute)();
        return true;
    }

    private function sendStatus(int $step, string $message): void
    {
        call_user_func($this->statusSender, 'status', [
            'step' => $step,
            'message' => $message,
        ]);
    }

    private function elapsed(float $start): string
    {
        return (string)call_user_func($this->elapsedFormatter, $start);
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }

    private function shouldReturnMissingColumnAnswer(array $plan): bool
    {
        $contextSource = (string)($plan['context_source'] ?? '');
        if (str_starts_with($contextSource, 'explicit')) {
            return true;
        }

        return !empty($plan['target_value']);
    }
}
