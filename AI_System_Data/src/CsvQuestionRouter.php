<?php

class CsvQuestionRouter
{
    /** @var callable */
    private $fileNameResolver;

    /** @var callable */
    private $hasCsvRows;

    /** @var callable|null */
    private $logger;

    public function __construct(callable $fileNameResolver, callable $hasCsvRows, ?callable $logger = null)
    {
        $this->fileNameResolver = $fileNameResolver;
        $this->hasCsvRows = $hasCsvRows;
        $this->logger = $logger;
    }

    public function shouldUseMetadataRoute(string $question): bool
    {
        if (preg_match('/(「内容」|『内容』|内容列|内容カラム|内容項目)/u', $question)) {
            return false;
        }

        return preg_match('/(どんな|何|なに).*(項目|列|カラム|ヘッダ|ヘッダー)|((項目|列|カラム|ヘッダ|ヘッダー).*(入って|あります|一覧|教えて))/u', $question) === 1;
    }

    public function shouldUseSmallSummaryRoute(string $question, int $rowCount): bool
    {
        if ($rowCount > 100) {
            return false;
        }

        if ($this->isStructuredAggregationIntent($question)) {
            return false;
        }

        if (preg_match('/(平均|中央値|標準偏差|相関|回帰|推移|時系列|ランキング|多い順|少ない順|TOP|トップ|詳しく分析|条件|抽出|検索|該当)/iu', $question)) {
            return false;
        }

        $mentionsCsvFile = $this->findMentionedCsvFileName($question) !== null;
        $hasSummaryIntent = preg_match('/(内容|概要|まとめ|要約|どんな内容|内容を教えて|説明して)/u', $question) === 1;
        $hasChartIntent = preg_match('/(グラフ|チャート|chart)/iu', $question) === 1;

        if ($mentionsCsvFile && $hasSummaryIntent) {
            return true;
        }

        if ($hasChartIntent) {
            return true;
        }

        return preg_match('/(CSV|csv|データ).*(内容|概要|まとめ|要約|どんな|入って)|((内容|概要|まとめ|要約).*(CSV|csv|データ))/u', $question) === 1;
    }

    public function shouldUseEvidenceRoute(string $question): bool
    {
        if ($this->isStructuredAggregationIntent($question)) {
            return false;
        }

        $mentionsCsvFile = $this->findMentionedCsvFileName($question) !== null;
        if (!$mentionsCsvFile && !preg_match('/(CSV|csv|データ|レコード|行|列|カラム|項目|内容|概要|傾向|まとめ|集計|グラフ|チャート|chart)/iu', $question)) {
            return false;
        }

        if (preg_match('/(平均|中央値|標準偏差|相関|回帰|推移|時系列|ランキング|多い順|少ない順|TOP|トップ)/iu', $question)) {
            return false;
        }

        try {
            return (bool)call_user_func($this->hasCsvRows);
        } catch (Throwable $e) {
            $this->log("[CSV-EVIDENCE] CSV行数判定に失敗: " . $e->getMessage());
            return false;
        }
    }

    private function findMentionedCsvFileName(string $question): ?string
    {
        return call_user_func($this->fileNameResolver, $question);
    }

    private function isStructuredAggregationIntent(string $question): bool
    {
        $hasStructuredTarget = preg_match('/(csv|列|カラム|項目|datetime|timestamp|yearmonth|yearmonthdate|name|date|time|hour|日付|日時|年月|時間帯|時刻帯)/iu', $question) === 1;
        $hasAggregationIntent = preg_match('/(集計|件数|分布|一覧|表|グラフ|チャート|抽出|多い時間帯|ピーク時間|ピーク帯|何件|何種類|ユニーク|distinct|月別|年別|日別|時間ごと|時ごと)/iu', $question) === 1;

        return $hasStructuredTarget && $hasAggregationIntent;
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }
}
