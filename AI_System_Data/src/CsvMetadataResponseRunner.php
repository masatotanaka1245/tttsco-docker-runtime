<?php

class CsvMetadataResponseRunner
{
    private $statusSender;
    private $logger;
    private $setFinalResponse;
    private $insertReasoningStep;
    private $completeRoute;
    private $elapsedFormatter;

    public function __construct(
        callable $statusSender,
        callable $setFinalResponse,
        callable $insertReasoningStep,
        callable $completeRoute,
        callable $elapsedFormatter,
        ?callable $logger = null
    ) {
        $this->statusSender = $statusSender;
        $this->setFinalResponse = $setFinalResponse;
        $this->insertReasoningStep = $insertReasoningStep;
        $this->completeRoute = $completeRoute;
        $this->elapsedFormatter = $elapsedFormatter;
        $this->logger = $logger;
    }

    public function tryMetadataRoute(
        string $originalMessage,
        array $files,
        CsvQuestionRouter $questionRouter,
        CsvSummaryFormatter $summaryFormatter
    ): bool {
        if (!$questionRouter->shouldUseMetadataRoute($originalMessage)) {
            return false;
        }

        if (empty($files)) {
            return false;
        }

        $this->log("[CSV-METADATA] CSV項目メタデータ即答ルートを起動します。対象ファイル数: " . count($files));
        $this->sendStatus(2, '📋 CSVの項目一覧をメタデータから確認しています...');

        $finalResponse = $summaryFormatter->buildMetadataAnswer($files);
        ($this->setFinalResponse)($finalResponse);
        ($this->insertReasoningStep)(1, 'CSVファイルの項目メタデータ確認', $finalResponse);
        ($this->completeRoute)();
        $this->log("[CSV-METADATA] CSV項目メタデータ即答ルートが完了しました。");
        return true;
    }

    public function runNoHitRoute(
        array $terms,
        int $totalRows,
        float $routeStart,
        array $files,
        CsvEvidenceReader $evidenceReader
    ): bool {
        $this->log("[CSV-SEARCH] 検索ヒット0件のため、全件AI読解を行わずメタデータ回答へフォールバックします。terms: " . implode(', ', $terms));
        $this->sendStatus(2, '🔎 CSVを検索しましたが該当レコードがないため、登録済みCSVの範囲を整理しています...');

        $finalResponse = $evidenceReader->buildNoHitAnswer($terms, $files, $totalRows);
        ($this->setFinalResponse)($finalResponse);
        ($this->insertReasoningStep)(1, 'CSVキーワード検索', "検索語: " . implode(" / ", $terms) . "\n検索ヒット: 0件\n総CSVレコード数: {$totalRows}件");
        ($this->completeRoute)();
        $this->log("[CSV-SEARCH] 検索ヒット0件フォールバック完了。totalElapsed: " . $this->elapsed($routeStart));
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
