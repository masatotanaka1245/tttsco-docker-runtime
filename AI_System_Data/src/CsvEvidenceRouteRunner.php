<?php

class CsvEvidenceRouteRunner
{
    private $statusSender;
    private $logger;
    private $setFinalResponse;
    private $setSubAnswers;
    private $insertReasoningStep;
    private $completeRoute;
    private $elapsedFormatter;

    public function __construct(
        callable $statusSender,
        callable $setFinalResponse,
        callable $setSubAnswers,
        callable $insertReasoningStep,
        callable $completeRoute,
        callable $elapsedFormatter,
        ?callable $logger = null
    ) {
        $this->statusSender = $statusSender;
        $this->setFinalResponse = $setFinalResponse;
        $this->setSubAnswers = $setSubAnswers;
        $this->insertReasoningStep = $insertReasoningStep;
        $this->completeRoute = $completeRoute;
        $this->elapsedFormatter = $elapsedFormatter;
        $this->logger = $logger;
    }

    public function run(
        string $originalMessage,
        CsvQuestionRouter $questionRouter,
        CsvEvidenceReader $evidenceReader,
        CsvSearchService $searchService,
        callable $tryMetadataRoute,
        callable $tryNoHitRoute,
        callable $tryLargeOverviewRoute,
        callable $trySmallSummaryRoute
    ): bool {
        if (!$questionRouter->shouldUseEvidenceRoute($originalMessage)) {
            return false;
        }

        $routeStart = microtime(true);
        $totalRows = $evidenceReader->countRows();
        $searchTerms = $searchService->extractSearchTerms($originalMessage);
        $this->log("[CSV-SEARCH] CSV探索フェーズ開始 - totalRows: {$totalRows} | terms: " . ($searchTerms ? implode(', ', $searchTerms) : 'なし'));

        if (call_user_func($tryMetadataRoute)) {
            return true;
        }

        if ($totalRows <= 0) {
            return false;
        }

        $searchResult = [
            'rows' => [],
            'hit_count' => 0,
            'terms' => $searchTerms,
            'limited' => false,
            'mode' => 'broad_overview',
        ];

        if ($searchTerms) {
            $searchResult = $evidenceReader->loadRowsByKeywords($searchTerms, 300);
            $this->log("[CSV-SEARCH] キーワード検索完了 - hits: {$searchResult['hit_count']} | loaded: " . count($searchResult['rows']) . " | limited: " . ($searchResult['limited'] ? 'yes' : 'no') . " | elapsed: " . $this->elapsed($routeStart));

            if ($searchResult['hit_count'] === 0 && call_user_func($tryNoHitRoute, $searchTerms, $totalRows, $routeStart)) {
                return true;
            }
        } else {
            $this->log("[CSV-SEARCH] 有効な検索語がないため、広域概況ルート候補として処理します。");
        }

        if (!$searchTerms && $totalRows > 100 && call_user_func($tryLargeOverviewRoute, $totalRows, $routeStart)) {
            return true;
        }

        $rows = $searchTerms ? $searchResult['rows'] : $evidenceReader->loadAllRows();
        $this->log("[CSV-EVIDENCE] DBレコード収集完了 - rows: " . count($rows) . " | totalRows: {$totalRows} | elapsed: " . $this->elapsed($routeStart));
        if (empty($rows)) {
            return false;
        }

        if (call_user_func($trySmallSummaryRoute, $rows, $routeStart, $searchResult)) {
            return true;
        }

        $this->log("[CSV-EVIDENCE] CSV証拠読解ルートを起動します。対象行数: " . count($rows) . " | searchHits: {$searchResult['hit_count']}");
        $this->sendStatus(2, '📚 CSVデータベースレコードを検索で絞り込み、質問に関係する証拠を分割読解しています...');

        ($this->insertReasoningStep)(1, 'CSV証拠レコードの検索収集', $evidenceReader->buildCollectionSummary($rows, $searchResult));

        $batches = $evidenceReader->chunkRows($rows, 50, 9000);
        $this->log("[CSV-EVIDENCE] バッチ分割完了 - batches: " . count($batches) . " | maxRows: 50 | maxChars: 9000");
        $batchFindings = [];

        foreach ($batches as $idx => $batch) {
            $batchNo = $idx + 1;
            $total = count($batches);
            $batchStart = microtime(true);
            $batchChars = mb_strlen($evidenceReader->formatBatch($batch));
            $this->log("[CSV-EVIDENCE] バッチAI読解開始 - batch: {$batchNo}/{$total} | rows: " . count($batch) . " | chars: {$batchChars}");
            $this->sendStatus(3, "🔎 CSV証拠を読解中 ({$batchNo}/{$total})...");

            $finding = $evidenceReader->analyzeBatch($batch, $batchNo, $total);
            $this->log("[CSV-EVIDENCE] バッチAI読解完了 - batch: {$batchNo}/{$total} | responseChars: " . mb_strlen($finding) . " | elapsed: " . $this->elapsed($batchStart));
            $batchFindings[] = $finding;
            ($this->insertReasoningStep)(10 + $idx, "CSV証拠バッチ読解 {$batchNo}/{$total}", $finding);
        }

        $this->sendStatus(4, '🧾 保存済みのCSV読解結果を統合し、最終回答を生成しています...');

        $synthesisStart = microtime(true);
        $this->log("[CSV-EVIDENCE] 統合AI回答生成開始 - findingCount: " . count($batchFindings));
        $finalResponse = $evidenceReader->synthesizeAnswer($rows, $batchFindings);
        ($this->setFinalResponse)($finalResponse);
        ($this->setSubAnswers)($batchFindings);
        $this->log("[CSV-EVIDENCE] 統合AI回答生成完了 - responseChars: " . mb_strlen($finalResponse) . " | elapsed: " . $this->elapsed($synthesisStart));
        ($this->insertReasoningStep)(90, 'CSV証拠読解結果の統合', $finalResponse);
        $this->log("[CSV-EVIDENCE] 履歴保存開始 - totalElapsed: " . $this->elapsed($routeStart));
        ($this->completeRoute)();
        $this->log("[CSV-EVIDENCE] CSV証拠読解ルートが完了しました。totalElapsed: " . $this->elapsed($routeStart));
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
