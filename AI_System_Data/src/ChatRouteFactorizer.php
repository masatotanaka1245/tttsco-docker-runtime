<?php

require_once __DIR__ . '/ChatHistoryContextResolver.php';

class ChatRouteFactorizer
{
    private $csvContextResolver;
    private $logger;
    private string $projectMemoryText;

    public function __construct(?ChatHistoryContextResolver $csvContextResolver = null, $logger = null, array $projectMemoryDocs = [])
    {
        $this->csvContextResolver = $csvContextResolver;
        $this->logger = is_callable($logger) ? $logger : null;
        $this->projectMemoryText = $this->buildProjectMemoryText($projectMemoryDocs);
    }

    public function factorize(string $message, array $recentHistory = []): array
    {
        $mentionedCsv = null;
        $mentionedColumnTarget = null;
        $explicitColumnReference = $this->findExplicitColumnReference($message);

        if ($this->csvContextResolver !== null) {
            try {
                $mentionedCsv = $this->csvContextResolver->findMentionedCsvFileName($message);
                $mentionedColumnTarget = $this->csvContextResolver->findMentionedCsvColumnTarget($message);
            } catch (Throwable $e) {
                $mentionedCsv = null;
                $mentionedColumnTarget = null;
            }
        }

        $hasAggregateIntent = preg_match('/(集計|件数|合計|平均|表に|一覧|推移|時系列|別に|グループ|何種類|ユニーク|distinct|重複なし|分布|分類|カテゴリ|抽出して件数|抽出して、件数|若い順|古い順|昇順|降順)/iu', $message) === 1;
        $hasSummaryIntent = preg_match('/(要約|まとめ|概要|内容を要約|内容をまとめ|どんな内容|内容を教えて)/u', $message) === 1;
        $hasDateIntent = preg_match('/(日付|日時|年月日|年月|月別|月ごと|年別|年ごと|日別|date|timestamp|時刻|日時は不要|月単位)/iu', $message) === 1;
        $hasHistorySummaryRequest = preg_match('/((これまで|今まで|過去|直近).*(会話|やりとり|チャット|履歴).*(まとめ|要約|整理)|((会話|やりとり|チャット|履歴).*(まとめ|要約|整理)))/u', $message) === 1;
        $hasHistoryReportRequest = preg_match('/((会話|やりとり|チャット|履歴).*(報告書|レポート|PDF).*(作成|作って|出力|生成)|((報告書|レポート|PDF).*(作成|作って|出力|生成).*(会話|やりとり|チャット|履歴)))/u', $message) === 1;
        $hasDocReference = preg_match('/(PDF|pdf|資料|図面|仕様書|文書|設計書|報告書)/u', $message) === 1;
        $hasDocActionIntent = preg_match('/(留意点|注意点|確認すべき|確認事項|法規|基準|安全面|設計上|施工前|不明点|見落とし|箇条書きで抽出|箇条書きで|抽出してください)/u', $message) === 1;
        $hasRecommendationIntent = preg_match('/(おすすめ|オススメ|提案|分析方法|集計方法|どう分析|どう集計|見るべき|観点|切り口|方針)/u', $message) === 1;
        $hasDistinctIntent = preg_match('/(何種類|ユニーク|distinct|重複なし|種類数)/iu', $message) === 1;
        $hasColumnExplainIntent = preg_match('/(どういう|どのような|説明|意味|何を表|どんなイベント|イベント.*説明|イベント.*意味|それぞれ.*説明)/u', $message) === 1;
        $hasNamingOrFramingIntent = preg_match('/(案件名|名前|名称|呼び方|強調したい|打ち出したい|表現|言い換え|一緒に検討|相談|どうでしょう|候補)/u', $message) === 1;
        $hasAppVerificationIntent = preg_match('/(動作確認|検証中|検証|デバッグ|テスト|試験|ログ確認|回帰確認)/u', $message) === 1;
        $hasCsvContext = preg_match('/(CSV|csv|ファイル|データ|レコード|行)/u', $message) === 1;
        $hasMixedDocumentAndCsvContext = $hasDocReference && $hasCsvContext;
        $memorySuggestsAppVerification = $this->projectMemoryText !== ''
            && preg_match('/(動作確認|検証中|検証|デバッグ|テスト|試験|ログ確認|回帰確認|アプリ|チャット|ルート|報告書|図解|Mermaid|CSV|PDF)/u', $this->projectMemoryText) === 1;
        $targetsAllCsv = preg_match('/(全て|すべて|全部|全件)/u', $message) === 1
            && $hasCsvContext;

        $targetColumn = $explicitColumnReference ?? ($mentionedColumnTarget['column_name'] ?? null);
        if ($mentionedCsv === null && !empty($mentionedColumnTarget['file_name'])) {
            $mentionedCsv = (string)$mentionedColumnTarget['file_name'];
        }

        if ($this->csvContextResolver !== null
            && ($mentionedCsv === null || $targetColumn === null)
            && $this->shouldInheritRecentCsvContext(
                $message,
                $hasAggregateIntent,
                $hasDateIntent,
                $hasColumnExplainIntent,
                $targetColumn
            )
            && !empty($recentHistory)
        ) {
            $recentContext = $this->csvContextResolver->findRecentCsvContext($recentHistory);
            if ($recentContext !== null) {
                if ($mentionedCsv === null) {
                    $mentionedCsv = $recentContext['target_file_name'] ?? null;
                }
                if ($targetColumn === null) {
                    $targetColumn = $recentContext['target_column'] ?? null;
                }
                if ($mentionedCsv !== null || $targetColumn !== null) {
                    $this->log("[SMART-ROUTER] 直前の会話履歴からCSV文脈を補完しました。file=" . ($mentionedCsv ?? 'none') . " | column=" . ($targetColumn ?? 'none'));
                }
            }
        }

        $intent = 'unknown';
        $target = 'unknown';
        $scope = 'unknown';
        $operation = 'unknown';
        $timeAxis = 'none';
        $outputFormat = preg_match('/(表に|表形式|テーブル|一覧で|一覧にして)/u', $message) === 1 ? 'table' : 'prose';
        $route = null;

        if ($hasHistoryReportRequest) {
            $intent = 'summarize';
            $target = 'chat_history';
            $scope = 'conversation_thread';
            $operation = 'report';
            $route = 'advanced_hybrid.history_report';
        } elseif ($hasNamingOrFramingIntent || ($hasAppVerificationIntent && $memorySuggestsAppVerification)) {
            $intent = 'consult';
            $target = 'project_memory';
            $scope = 'project_wide';
            $operation = $hasNamingOrFramingIntent ? 'framing' : 'status_alignment';
            $route = 'normal_rag.project_memory_consultation';
        } elseif ($hasMixedDocumentAndCsvContext && $hasRecommendationIntent) {
            $intent = 'analyze';
            $target = 'project_assets';
            $scope = 'project_wide';
            $operation = 'analysis_recommendation';
            $route = 'advanced_hybrid.multi_source_advice';
        } elseif ($hasHistorySummaryRequest) {
            $intent = 'summarize';
            $target = 'chat_history';
            $scope = 'conversation_thread';
            $operation = 'summarize';
            $route = 'history_summary';
        } elseif ($hasAggregateIntent && $hasDateIntent && $mentionedCsv !== null) {
            $intent = 'aggregate';
            $target = 'single_csv';
            $scope = 'records_with_date';
            $operation = 'count';
            $timeAxis = preg_match('/(月別|月ごと|年月|日時は不要|月単位)/u', $message) === 1
                ? 'month'
                : (preg_match('/(年別|年ごと)/u', $message) === 1 ? 'year' : 'day');
            $route = 'data_analysis.csv_agg';
        } elseif ($hasAggregateIntent && $hasDateIntent && $targetColumn !== null) {
            $intent = 'aggregate';
            $target = $mentionedCsv !== null ? 'single_csv' : 'all_csv';
            $scope = 'records_with_date';
            $operation = 'count';
            $timeAxis = preg_match('/(月別|月ごと|年月|日時は不要|月単位)/u', $message) === 1
                ? 'month'
                : (preg_match('/(年別|年ごと)/u', $message) === 1 ? 'year' : 'day');
            $route = 'data_analysis.csv_agg';
        } elseif ($hasAggregateIntent && $hasDateIntent && $hasCsvContext) {
            $intent = 'aggregate';
            $target = 'all_csv';
            $scope = 'records_with_date';
            $operation = 'count';
            $timeAxis = preg_match('/(月別|月ごと|年月|日時は不要|月単位)/u', $message) === 1
                ? 'month'
                : (preg_match('/(年別|年ごと)/u', $message) === 1 ? 'year' : 'day');
            $route = 'data_analysis.csv_agg';
        } elseif ($hasAggregateIntent && $hasDateIntent && $targetsAllCsv) {
            $intent = 'aggregate';
            $target = 'all_csv';
            $scope = 'records_with_date';
            $operation = 'count';
            $timeAxis = preg_match('/(月別|月ごと|年月|日時は不要|月単位)/u', $message) === 1
                ? 'month'
                : (preg_match('/(年別|年ごと)/u', $message) === 1 ? 'year' : 'day');
            $route = 'data_analysis.csv_agg';
        } elseif ($targetColumn !== null && $hasAggregateIntent && $hasDistinctIntent) {
            $intent = 'aggregate';
            $target = $mentionedCsv !== null ? 'single_csv' : 'all_csv';
            $scope = 'file_content';
            $operation = 'distinct_count';
            $route = 'data_analysis.csv_agg';
        } elseif ($targetColumn !== null && $hasColumnExplainIntent) {
            $intent = 'aggregate';
            $target = $mentionedCsv !== null ? 'single_csv' : 'all_csv';
            $scope = 'file_content';
            $operation = 'column_semantics';
            $route = 'data_analysis.csv_agg';
        } elseif ($targetColumn !== null && $hasAggregateIntent) {
            $intent = 'aggregate';
            $target = $mentionedCsv !== null ? 'single_csv' : 'all_csv';
            $scope = 'file_content';
            $operation = 'value_distribution';
            $route = 'data_analysis.csv_agg';
        } elseif ($hasSummaryIntent && $mentionedCsv !== null) {
            $intent = 'summarize';
            $target = 'single_csv';
            $scope = 'file_content';
            $operation = 'summarize';
            $route = 'data_analysis.csv_summary';
        } elseif ($hasSummaryIntent && preg_match('/(CSV|csv|ファイル|データ)/u', $message) === 1) {
            $intent = 'summarize';
            $target = 'all_csv';
            $scope = 'project_wide';
            $operation = 'summarize';
            $route = 'data_analysis.csv_summary';
        } elseif (!$targetsAllCsv && $mentionedCsv === null && ($hasDocReference || $hasDocActionIntent)) {
            $intent = 'extract_evidence';
            $target = 'pdf';
            $scope = 'project_wide';
            $operation = 'extract_evidence';
            $outputFormat = preg_match('/(箇条書き|3点|3つ|列挙|リスト)/u', $message) === 1 ? 'bullets' : $outputFormat;
            $route = 'advanced_hybrid.doc_extract';
        }

        return [
            'intent' => $intent,
            'target' => $target,
            'target_file_name' => $mentionedCsv,
            'target_column' => $targetColumn,
            'scope' => $scope,
            'operation' => $operation,
            'time_axis' => $timeAxis,
            'output_format' => $outputFormat,
            'route' => $route,
        ];
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }

    private function buildProjectMemoryText(array $projectMemoryDocs): string
    {
        $parts = [];
        foreach (['readme', 'agents', 'todo'] as $memoryType) {
            $content = trim((string)($projectMemoryDocs[$memoryType]['content'] ?? ''));
            if ($content !== '') {
                $parts[] = $content;
            }
        }

        return implode("\n\n", $parts);
    }

    private function findExplicitColumnReference(string $message): ?string
    {
        if (preg_match_all('/[「『"]([^」』"]+)[」』"]\s*(?:カラム|列|項目)/u', $message, $matches)) {
            foreach (array_reverse($matches[1]) as $candidate) {
                $candidate = trim((string)$candidate);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        if (preg_match_all('/(?:^|[\s　])([A-Za-z_][A-Za-z0-9_]*|[一-龠ぁ-んァ-ヶー]+)\s*(?:カラム|列|項目)/u', $message, $matches)) {
            foreach (array_reverse($matches[1]) as $candidate) {
                $candidate = trim((string)$candidate);
                if ($candidate !== '') {
                    return $candidate;
                }
            }
        }

        if (preg_match('/(?:^|[\s　])([A-Za-z_][A-Za-z0-9_]*|[一-龠ぁ-んァ-ヶー]+)\s*から\s*(?:年月|月別|年別|日別|日時|日付|時刻)/u', $message, $matches) === 1) {
            $candidate = trim((string)($matches[1] ?? ''));
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function shouldInheritRecentCsvContext(
        string $message,
        bool $hasAggregateIntent,
        bool $hasDateIntent,
        bool $hasColumnExplainIntent,
        ?string $targetColumn
    ): bool {
        if ($hasColumnExplainIntent) {
            return true;
        }

        if ($this->isAggregationFollowUpIntent($message)) {
            return true;
        }

        if (preg_match('/((この|その|同じ|前回|直前|先ほど|さっき|引き続き|続けて).*(CSV|ファイル|列|カラム|項目|集計|条件))|((CSV|ファイル|列|カラム|項目|集計|条件).*(この|その|同じ|前回|直前|先ほど|さっき))/u', $message) === 1) {
            return true;
        }

        if (
            $hasAggregateIntent
            && $hasDateIntent
            && $targetColumn === null
            && preg_match('/(\d{4}年\d{1,2}月(?:\d{1,2}日)?|\d{4}[\/\-]\d{1,2}(?:[\/\-]\d{1,2})?)/u', $message) === 1
        ) {
            return true;
        }

        return false;
    }

    private function isAggregationFollowUpIntent(string $message): bool
    {
        return preg_match('/(若い順|古い順|昇順|降順|新しい順|新しいものから|古いものから|グラフ|グラフ化|チャート|並び替え|並べ替え|ソート)/iu', $message) === 1;
    }
}
