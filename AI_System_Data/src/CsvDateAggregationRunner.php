<?php

class CsvDateAggregationRunner
{
    private $statusSender;
    private $logger;
    private $setFinalResponse;
    private $appendSubAnswer;
    private $insertReasoningStep;
    private $completeRoute;
    private $elapsedFormatter;

    public function __construct(
        callable $statusSender,
        callable $setFinalResponse,
        callable $appendSubAnswer,
        callable $insertReasoningStep,
        callable $completeRoute,
        callable $elapsedFormatter,
        ?callable $logger = null
    ) {
        $this->statusSender = $statusSender;
        $this->setFinalResponse = $setFinalResponse;
        $this->appendSubAnswer = $appendSubAnswer;
        $this->insertReasoningStep = $insertReasoningStep;
        $this->completeRoute = $completeRoute;
        $this->elapsedFormatter = $elapsedFormatter;
        $this->logger = $logger;
    }

    public function run(
        array $plan,
        float $routeStart,
        CsvAggregationTargetResolver $targetResolver,
        CsvAggregationAnswerFormatter $formatter,
        bool $diagramMode
    ): bool {
        $this->sendStatus(2, '🧭 集計意図を判定し、CSVサンプルから日付列候補を探索しています...');

        $targets = $targetResolver->detectDateTargets($plan);
        $this->log("[CSV-AGG] サンプル判定完了 - target_files: " . count($targets));
        if (empty($targets)) {
            $this->log("[CSV-AGG] サンプル判定の結果、集計に使える日付列候補を検出できませんでした。CSV証拠読解ルートへフォールバックします。");
            return false;
        }

        $targetSummary = [];
        foreach ($targets as $target) {
            $targetSummary[] = [
                'csv_file_id' => $target['csv_file_id'],
                'file_name' => $target['file_name'],
                'date_columns' => $target['date_columns'],
                'sample_rows' => $target['sample_rows_checked'],
            ];
        }
        ($this->insertReasoningStep)(
            1,
            'CSV集計プリフライト',
            "【集計計画】\n" . json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\n【日付列候補】\n" . json_encode($targetSummary, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        $this->sendStatus(3, '📊 日付候補列ごとにSQL集計を実行しています...');

        $aggregatedRows = [];
        $executedSqls = [];
        foreach ($targets as $target) {
            foreach ($target['date_columns'] as $dateColumn) {
                $sqlInfo = $targetResolver->executeDateAggregationQuery($target, $dateColumn, $plan);
                if (!empty($sqlInfo['rows'])) {
                    $aggregatedRows = array_merge($aggregatedRows, $sqlInfo['rows']);
                }
                if (!empty($sqlInfo['sql'])) {
                    $executedSqls[] = [
                        'file_name' => $target['file_name'],
                        'date_column' => $dateColumn,
                        'sql' => $sqlInfo['sql'],
                        'raw_group_count' => $sqlInfo['raw_group_count'] ?? 0,
                    ];
                }
            }
        }

        if (empty($aggregatedRows)) {
            $this->log("[CSV-AGG] 日付列候補は見つかりましたが、集計結果が0件でした。CSV証拠読解ルートへフォールバックします。");
            return false;
        }

        $sortOrder = (string)($plan['sort_order'] ?? 'asc');
        usort($aggregatedRows, function ($a, $b) use ($sortOrder) {
            $comparison = [$a['date'], $a['file_name'], $a['date_column']] <=> [$b['date'], $b['file_name'], $b['date_column']];
            return $sortOrder === 'desc' ? -$comparison : $comparison;
        });

        $finalResponse = $formatter->buildStructuredAggregationAnswer($plan, $aggregatedRows, $targets, $diagramMode);
        ($this->setFinalResponse)($finalResponse);
        ($this->appendSubAnswer)($finalResponse);

        $sqlLogLines = [];
        foreach ($executedSqls as $sqlInfo) {
            $sqlLogLines[] = "### {$sqlInfo['file_name']} / {$sqlInfo['date_column']}\n"
                . "- raw groups: {$sqlInfo['raw_group_count']}\n"
                . "```sql\n{$sqlInfo['sql']}\n```";
        }
        ($this->insertReasoningStep)(90, 'CSV日付集計SQLの実行結果', implode("\n\n", $sqlLogLines));
        $this->log("[CSV-AGG] 構造化集計ルート完了 - rows: " . count($aggregatedRows) . " | sqls: " . count($executedSqls) . " | elapsed: " . $this->elapsed($routeStart));
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
}
