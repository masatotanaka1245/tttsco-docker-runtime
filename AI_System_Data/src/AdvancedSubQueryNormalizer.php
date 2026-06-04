<?php

final class AdvancedSubQueryNormalizer
{
    private $searchQuery;
    private $originalMessage;
    private $allowedTables;
    private $logger;

    public function __construct(
        string $searchQuery,
        string $originalMessage,
        array $allowedTables,
        ?callable $logger = null
    ) {
        $this->searchQuery = $searchQuery;
        $this->originalMessage = $originalMessage;
        $this->allowedTables = array_values(array_unique(array_map('strval', $allowedTables)));
        $this->logger = $logger;
    }

    public function normalizeRawSubQueryItem($item): array
    {
        return $this->normalizeSubQueryItem(is_array($item) ? $item : ['query' => (string)$item]);
    }

    public function normalizeSubQueryItem(array $item): array
    {
        $query = trim((string)($item['query'] ?? $item['purpose'] ?? $this->searchQuery));
        if ($query === '') {
            $query = 'ユーザーの質問に関連する実データを取得して中間回答を作成する';
        }
        $contextText = $this->buildSubQueryContextText($query);

        $operationType = (string)($item['operation_type'] ?? '');
        $allowedTypes = ['metadata_lookup', 'simple_aggregate', 'record_search', 'semantic_extract'];
        if (!in_array($operationType, $allowedTypes, true)) {
            $operationType = $this->inferOperationType($contextText);
        }

        $targetTables = $item['target_tables'] ?? [];
        $targetTables = $this->normalizeTargetTables($targetTables, $contextText);

        if ($this->shouldForceDocSemanticExtract($query, $operationType, $targetTables)) {
            $operationType = 'semantic_extract';
            if (!in_array('doc_chunks', $targetTables, true)) {
                $targetTables[] = 'doc_chunks';
            }
            if (!in_array('documents', $targetTables, true)) {
                $targetTables[] = 'documents';
            }
            $targetTables = array_values(array_unique($targetTables));
            $this->log("[DOC-DECOMP-FORCE] 資料抽出質問のため semantic_extract + doc_chunks/documents に矯正しました。query=" . $query);
        }

        return [
            'query' => $query,
            'operation_type' => $operationType,
            'target_tables' => $targetTables,
            'answer_goal' => trim((string)($item['answer_goal'] ?? 'このサブクエリの取得結果から、ユーザー質問に必要な要点を短く回答する')),
        ];
    }

    public function shouldForceDocSemanticExtractByQuestion(): bool
    {
        return $this->isDocExtractiveQuestion($this->searchQuery);
    }

    public function inferOperationType(string $text): string
    {
        if (preg_match('/(留意点|注意点|要約|まとめ|概要|考察|示唆|課題|リスク|主要|重要|意味|内容を整理|本文)/u', $text)) {
            return 'semantic_extract';
        }
        if (preg_match('/(件数|何件|合計|平均|割合|比率|ランキング|集計|推移|分布|カウント)/u', $text)) {
            return 'simple_aggregate';
        }
        if (preg_match('/(一覧|項目|カラム|列|ファイル|資料名|登録済み|メタ|タイトル)/u', $text)) {
            return 'metadata_lookup';
        }
        if (preg_match('/(検索|含む|該当|キーワード|絞り込み|抽出)/u', $text)) {
            return 'record_search';
        }
        return 'semantic_extract';
    }

    public function inferTargetTables(string $text): array
    {
        if (preg_match('/(PDF|pdf|資料|文書|報告書|図面|仕様書|doc_chunks|チャンク)/u', $text)) {
            return ['documents', 'doc_chunks'];
        }
        if (preg_match('/(CSV|csv|行データ|row_data|カラム|列|項目|集計)/u', $text)) {
            return ['project_csv_files', 'project_csv_rows'];
        }
        if (preg_match('/(会話|履歴|チャット|これまで|過去)/u', $text)) {
            return ['chat_history'];
        }
        if (preg_match('/(FAQ|よくある質問)/iu', $text)) {
            return ['project_faqs'];
        }
        return ['doc_chunks'];
    }

    public function shouldRunCsvMapReduceForItem($item): bool
    {
        $normalizedItem = $this->normalizeRawSubQueryItem($item);
        return in_array('project_csv_rows', $normalizedItem['target_tables'], true)
            && in_array($normalizedItem['operation_type'], ['semantic_extract', 'record_search'], true);
    }

    public function shouldRunCsvFullMapReduce(array $subQueries): bool
    {
        $text = $this->buildSubQueryContextText($this->originalMessage);
        $hasCsvIntent = preg_match('/(CSV|csv|登録済み.*データ|データ.*(内容|概要|全件|すべて|全部)|全件.*(読解|分析|分類)|1件も漏らさず)/u', $text);
        if (!$hasCsvIntent) {
            return false;
        }

        foreach ($subQueries as $item) {
            if ($this->shouldRunCsvMapReduceForItem($item)) {
                return true;
            }
        }

        return false;
    }

    public function isDocOnlySemanticExtractSubQuery(array $item): bool
    {
        $normalizedItem = $this->normalizeRawSubQueryItem($item);
        if (($normalizedItem['operation_type'] ?? '') !== 'semantic_extract') {
            return false;
        }

        $targetTables = $normalizedItem['target_tables'] ?? [];
        if (empty($targetTables)) {
            return false;
        }

        $docTables = ['documents', 'doc_chunks'];
        foreach ($targetTables as $table) {
            if (!in_array($table, $docTables, true)) {
                return false;
            }
        }

        return true;
    }

    public function shouldSkipSqlSequenceForDocOnlySubQueries(array $subQueries): bool
    {
        if (empty($subQueries)) {
            return false;
        }

        foreach ($subQueries as $item) {
            if (!$this->isDocOnlySemanticExtractSubQuery($item)) {
                return false;
            }
        }

        return true;
    }

    private function buildSubQueryContextText(string $text): string
    {
        return trim($text . ' ' . $this->searchQuery);
    }

    private function normalizeTargetTables($targetTables, string $contextText): array
    {
        if (is_string($targetTables)) {
            $targetTables = [$targetTables];
        }
        if (!is_array($targetTables) || empty($targetTables)) {
            $targetTables = $this->inferTargetTables($contextText);
        }

        $targetTables = array_values(array_filter(array_map('strval', $targetTables), function (string $table): bool {
            return in_array($table, $this->allowedTables, true);
        }));
        if (empty($targetTables)) {
            $targetTables = $this->inferTargetTables($contextText);
        }

        return $targetTables;
    }

    private function shouldForceDocSemanticExtract(string $query, string $operationType, array $targetTables): bool
    {
        if (!$this->shouldForceDocSemanticExtractByQuestion()) {
            return false;
        }

        $docTables = ['documents', 'doc_chunks'];
        $hasDocTable = false;
        foreach ($targetTables as $table) {
            if (in_array($table, $docTables, true)) {
                $hasDocTable = true;
                break;
            }
        }

        if (!$hasDocTable && preg_match('/(PDF|pdf|資料|図面|仕様書|報告書|文書|ページ|本文|根拠)/u', $query) !== 1) {
            return false;
        }

        if ($operationType === 'semantic_extract' && in_array('doc_chunks', $targetTables, true)) {
            return false;
        }

        return true;
    }

    private function isDocExtractiveQuestion(string $text): bool
    {
        $hasDocReference = preg_match('/(PDF|pdf|資料|図面|仕様書|報告書|文書)/u', $text) === 1;
        $hasExtractiveIntent = preg_match('/(留意点|注意点|要点|主要|重要|根拠|本文|箇条書き|抽出|整理|確認事項|見落とし)/u', $text) === 1;
        return $hasDocReference && $hasExtractiveIntent;
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }
}
