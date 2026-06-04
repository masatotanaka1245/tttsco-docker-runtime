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

    public function runDistinctCount(
        array $plan,
        float $routeStart,
        CsvAggregationTargetResolver $targetResolver,
        CsvAggregationAnswerFormatter $formatter
    ): bool {
        $target = $targetResolver->findFileTarget((string)($plan['target_file_name'] ?? ''));
        $targetColumn = (string)($plan['target_column'] ?? '');
        if ($target === null || $targetColumn === '') {
            $this->log('[CSV-AGG] distinct_count の対象ファイルまたは列を解決できませんでした。CSV証拠読解ルートへフォールバックします。');
            return false;
        }

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
        CsvAggregationAnswerFormatter $formatter
    ): bool {
        $target = $targetResolver->findFileTarget((string)($plan['target_file_name'] ?? ''));
        $targetColumn = (string)($plan['target_column'] ?? '');
        if ($target === null || $targetColumn === '') {
            $this->log('[CSV-AGG] value_distribution の対象ファイルまたは列を解決できませんでした。CSV証拠読解ルートへフォールバックします。');
            return false;
        }

        $this->sendStatus(2, '🧭 集計対象のCSVと列を特定しています...');
        ($this->insertReasoningStep)(
            1,
            'CSV value distribution プリフライト',
            "【集計計画】\n" . json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\n【対象CSV】\n" . json_encode($target, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        $this->sendStatus(3, '📊 対象列の値分布を集計しています...');
        $result = $targetResolver->executeValueDistributionQuery($target, $targetColumn);
        $rows = $result['rows'] ?? [];
        if (empty($rows)) {
            $this->log('[CSV-AGG] value_distribution の集計結果が0件でした。CSV証拠読解ルートへフォールバックします。');
            return false;
        }

        $finalResponse = $formatter->buildValueDistributionAnswer($plan, $target, $rows);
        ($this->setFinalResponse)($finalResponse);
        ($this->appendSubAnswer)($finalResponse);
        ($this->insertReasoningStep)(
            90,
            'CSV value distribution SQL の実行結果',
            "### {$target['file_name']} / {$targetColumn}\n```sql\n{$result['sql']}\n```\n- groups: " . count($rows)
        );
        $this->log("[CSV-AGG] value_distribution ルート完了 - groups: " . count($rows) . " | elapsed: " . $this->elapsed($routeStart));
        ($this->completeRoute)();
        return true;
    }

    public function runExactValueCount(
        array $plan,
        float $routeStart,
        CsvAggregationTargetResolver $targetResolver,
        CsvAggregationAnswerFormatter $formatter
    ): bool {
        $target = $targetResolver->findFileTarget((string)($plan['target_file_name'] ?? ''));
        $targetColumn = (string)($plan['target_column'] ?? '');
        $targetValue = (string)($plan['target_value'] ?? '');
        if ($target === null || $targetColumn === '' || $targetValue === '') {
            $this->log('[CSV-AGG] exact_value_count の対象ファイル・列・値を解決できませんでした。CSV証拠読解ルートへフォールバックします。');
            return false;
        }

        $this->sendStatus(2, '🧭 集計対象のCSV・列・抽出値を特定しています...');
        ($this->insertReasoningStep)(
            1,
            'CSV exact value count プリフライト',
            "【集計計画】\n" . json_encode($plan, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
            . "\n\n【対象CSV】\n" . json_encode($target, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );

        $this->sendStatus(3, '📊 指定値に一致する件数を集計しています...');
        $result = $targetResolver->executeExactValueCountQuery($target, $targetColumn, $targetValue);
        $matchedCount = (int)($result['matched_count'] ?? 0);

        $finalResponse = $formatter->buildExactValueCountAnswer($plan, $target, $matchedCount);
        ($this->setFinalResponse)($finalResponse);
        ($this->appendSubAnswer)($finalResponse);
        ($this->insertReasoningStep)(
            90,
            'CSV exact value count SQL の実行結果',
            "### {$target['file_name']} / {$targetColumn}\n```sql\n{$result['sql']}\n```\n- target_value: {$targetValue}\n- matched_count: {$matchedCount}"
        );
        $this->log("[CSV-AGG] exact_value_count ルート完了 - count: {$matchedCount} | elapsed: " . $this->elapsed($routeStart));
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
