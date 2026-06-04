<?php

class CsvQuickResponseRunner
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

    public function trySmallSummaryRoute(
        string $originalMessage,
        array $rows,
        float $routeStart,
        array $searchResult,
        CsvQuestionRouter $questionRouter,
        CsvEvidenceReader $evidenceReader,
        CsvSummaryFormatter $summaryFormatter,
        bool $diagramMode
    ): bool {
        if (!$questionRouter->shouldUseSmallSummaryRoute($originalMessage, count($rows))) {
            return false;
        }

        $this->log("[CSV-SUMMARY] 小規模CSV即答ルートを起動します。rows: " . count($rows));
        $this->sendStatus(2, '📊 CSVの内容をデータベースレコードから直接要約しています...');

        $summaryStart = microtime(true);
        $includeChart = $diagramMode || $this->hasChartIntent($originalMessage);
        $finalResponse = $summaryFormatter->buildSmallSummaryAnswer($rows, $searchResult, $includeChart);
        ($this->setFinalResponse)($finalResponse);
        $this->log("[CSV-SUMMARY] PHPサマリー生成完了 - responseChars: " . mb_strlen($finalResponse) . " | elapsed: " . $this->elapsed($summaryStart));

        $collectionSummary = $evidenceReader->buildCollectionSummary($rows, $searchResult);
        ($this->insertReasoningStep)(1, 'CSVレコードの検索収集', $collectionSummary);
        ($this->insertReasoningStep)(90, '小規模CSVサマリー即時生成', $finalResponse);
        call_user_func($this->appendSubAnswer, $collectionSummary);
        call_user_func($this->appendSubAnswer, $finalResponse);

        $this->log("[CSV-SUMMARY] 履歴保存開始 - totalElapsed: " . $this->elapsed($routeStart));
        ($this->completeRoute)();
        $this->log("[CSV-SUMMARY] 小規模CSV即答ルートが完了しました。totalElapsed: " . $this->elapsed($routeStart));
        return true;
    }

    public function runLargeOverviewRoute(
        int $totalRows,
        float $routeStart,
        array $files,
        array $sampleRows,
        CsvEvidenceReader $evidenceReader,
        bool $diagramMode
    ): bool {
        $this->log("[CSV-OVERVIEW] 広域質問かつ大規模CSVのため、全件AI読解を行わず概況ルートを起動します。totalRows: {$totalRows}");
        $this->sendStatus(2, '📊 CSV件数が多いため、メタデータと代表サンプルから概況を整理しています...');

        $finalResponse = $evidenceReader->buildLargeOverviewAnswer($files, $sampleRows, $totalRows, $diagramMode);
        ($this->setFinalResponse)($finalResponse);
        $collectionSummary = $evidenceReader->buildCollectionSummary($sampleRows, [
            'terms' => [],
            'hit_count' => $totalRows,
            'limited' => true,
            'mode' => 'broad_overview',
        ]);
        ($this->insertReasoningStep)(1, 'CSV広域探索', $collectionSummary);
        call_user_func($this->appendSubAnswer, $collectionSummary);
        call_user_func($this->appendSubAnswer, $finalResponse);
        ($this->completeRoute)();
        $this->log("[CSV-OVERVIEW] 大規模CSV概況ルートが完了しました。totalElapsed: " . $this->elapsed($routeStart));
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

    private function hasChartIntent(string $message): bool
    {
        return preg_match('/(グラフ|チャート|chart|可視化|図にして)/iu', $message) === 1;
    }
}
