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
        $routeConfidence = 'low';
        $routeReasonCodes = [];
        $routeEvidence = [];

        $setRouteMeta = static function (string $confidence, array $reasonCodes, array $evidence) use (&$routeConfidence, &$routeReasonCodes, &$routeEvidence): void {
            $routeConfidence = $confidence;
            $routeReasonCodes = array_values(array_unique(array_filter(array_map('strval', $reasonCodes), static function (string $value): bool {
                return $value !== '';
            })));
            $routeEvidence = array_values(array_unique(array_filter(array_map('strval', $evidence), static function (string $value): bool {
                return $value !== '';
            })));
        };

        if ($this->csvContextResolver !== null) {
            try {
                $mentionedCsv = $this->csvContextResolver->findMentionedCsvFileName($message);
                $mentionedColumnTarget = $this->csvContextResolver->findMentionedCsvColumnTarget($message);
            } catch (Throwable $e) {
                $mentionedCsv = null;
                $mentionedColumnTarget = null;
            }
        }

        $hasAggregateIntent = preg_match('/(集計|件数|合計|平均|表に|一覧|推移|時系列|別に|グループ|何種類|ユニーク|distinct|重複なし|分布|分類|カテゴリ|抽出して件数|抽出して、件数|若い順|古い順|昇順|降順|多い時間帯|ピーク時間|ピーク帯|ランキング|多い順|少ない順|上位|TOP|トップ|全件|すべて表示|続きを表示)/iu', $message) === 1;
        $hasSummaryIntent = preg_match('/(要約|まとめ|概要|内容を要約|内容をまとめ|どんな内容|内容を教えて)/u', $message) === 1;
        $hasDateIntent = preg_match('/(日付|日時|年月日|年月|月別|月ごと|年別|年ごと|日別|date|timestamp|時刻|日時は不要|月単位)/iu', $message) === 1;
        $hasTimeBandIntent = preg_match('/(時間帯|時刻帯|時間ごと|時ごと|hour|何時台|時台|ピーク時間|多い時間帯)/iu', $message) === 1;
        $hasAggregationFollowUpIntent = $this->isAggregationFollowUpIntent($message);
        $hasHistorySummaryRequest = preg_match('/((これまで|今まで|過去|直近).*(会話|やりとり|チャット|履歴).*(まとめ|要約|整理)|((会話|やりとり|チャット|履歴).*(まとめ|要約|整理)))/u', $message) === 1;
        $hasHistoryReportRequest = preg_match('/((会話|やりとり|チャット|履歴).*(報告書|レポート|PDF))|((報告書|レポート|PDF).*(会話|やりとり|チャット|履歴))|((会話|やりとり|チャット|履歴).*(報告書|レポート|PDF).*(作成|作って|出力|生成|にして|化して))|((報告書|レポート|PDF).*(作成|作って|出力|生成|にして|化して).*(会話|やりとり|チャット|履歴))/u', $message) === 1;
        $hasDocReference = preg_match('/(PDF|pdf|資料|図面|仕様書|文書|設計書|報告書)/u', $message) === 1;
        $hasDocActionIntent = preg_match('/(留意点|注意点|確認すべき|確認事項|法規|基準|安全面|設計上|施工前|不明点|見落とし|箇条書きで抽出|箇条書きで|抽出してください)/u', $message) === 1;
        $hasRecommendationIntent = preg_match('/(おすすめ|オススメ|提案|良い案|よい案|案はありますか|どう書|どう表現|言い換え|追加したい|追加する項目|分析方法|集計方法|どう分析|どう集計|どのように.*分析|分析したら.*よい|どう進め|まずどこから見れば|どこから見れば|どこから手を付け|何から見れば|最初に見るべき|優先して見るべき|見る順番|確認する順番|着手順|分析の入口|分析の進め方|見るべき|観点|切り口|方針|分析してください|分析して|傾向を教えて|傾向を見て|問題点を教えて|どう見れば|どの列を見れば|このデータをどう見る|CSVを分析)/u', $message) === 1;
        $hasProjectSummaryIntent = preg_match('/(案件|プロジェクト).*(内容|概要|全体像|まとめ|要約|詳細)|((内容|概要|全体像|まとめ|要約|詳細).*(案件|プロジェクト))/u', $message) === 1;
        $hasProjectAdviceAnchor = preg_match('/(この案件|このプロジェクト|案件で|プロジェクトで)/u', $message) === 1;
        $hasMaterialReference = preg_match('/(資料メモ|markdown|Markdown|mdファイル|MDファイル|(?:^|[^A-Za-z])md(?:$|[^A-Za-z]))/u', $message) === 1;
        $hasMaterialActionIntent = preg_match('/(開いて|開く|追加|追記|追記ポイント|作成|作って|作っていく|育て|更新|修正|整理|まとめ|章立て|見出し|ドラフト|たたき台|下書き|構成)/u', $message) === 1;
        $hasMaterialWorkflowIntent = $hasMaterialReference && $hasMaterialActionIntent;
        $hasTaskStatusIntent = preg_match('/(進行中タスク|現在のタスク|現在の主成果品|次に何をすべき|次に何をすれば|現在地|今の状況|優先タスク|次アクション)/u', $message) === 1;
        $hasCsvOperationIntent = preg_match('/((転記|統合|結合|マージ|取り込|追加|反映|上書き).*(できますか|可能|したい|方法|手順|どうやって|どうすれば))|((できますか|可能|したい|方法|手順|どうやって|どうすれば).*(転記|統合|結合|マージ|取り込|追加|反映|上書き))/u', $message) === 1;
        $hasBroadDetailIntent = preg_match('/(詳細|詳しく|内訳|全体像|全体の傾向|どんなデータ|何がある)/u', $message) === 1;
        $hasCsvExportIntent = preg_match('/(csv化|CSV化|csvにしてください|CSVにしてください|csvファイルにしてください|CSVファイルにしてください|csvファイルを作成|CSVファイルを作成|csvで出力|CSVで出力|一つのcsv|1つのcsv|一つのCSV|1つのCSV)/u', $message) === 1;
        $hasDistinctIntent = preg_match('/(何種類|ユニーク|distinct|重複なし|種類数)/iu', $message) === 1;
        $hasColumnExplainIntent = !$hasMaterialWorkflowIntent
            && preg_match('/(どういう|どのような|説明|意味|何を表|どんなイベント|イベント.*説明|イベント.*意味|それぞれ.*説明)/u', $message) === 1;
        $hasColumnExistsIntent = preg_match('/(ありますよね|ありますか|存在しますか|入っていますか|含まれていますか|ありますよね。?)/u', $message) === 1;
        $hasNamingOrFramingIntent = preg_match('/(案件名|名前|名称|呼び方|強調したい|打ち出したい|表現|言い換え|一緒に検討|相談|どうでしょう|候補|良い案|よい案|案はありますか|追加したい)/u', $message) === 1;
        $hasAppVerificationIntent = preg_match('/(動作確認|検証中|検証|デバッグ|テスト|試験|ログ確認|回帰確認)/u', $message) === 1;
        $hasCsvContext = preg_match('/(CSV|csv|ファイル|データ|レコード|行)/u', $message) === 1;
        $hasCsvAdvisoryContext = $hasCsvContext
            || $mentionedCsv !== null
            || $explicitColumnReference !== null
            || preg_match('/(表|テーブル|列|カラム)/u', $message) === 1;
        $hasCsvLogMetadataContext = preg_match('/(ログ|記録|項目|構造|キー|フィールド|ToolSource|Timestamp|UserID|LogID)/u', $message) === 1;
        $hasCsvLogMetadataIntent = preg_match('/(比較|共通|差異|統合|整理|どのような情報|何が記録|統合できますか|見るべき項目|確認すべき列)/u', $message) === 1;
        $hasCsvLogMultiToolHint = preg_match('/(Alli|SecureMemo|サテライトAI|各AIツール|各ツール|複数ツール|複数の生成AI)/u', $message) === 1;
        $hasCsvLogKeyHint = preg_match('/(ToolSource|Timestamp|UserID|LogID)/u', $message) === 1;
        $hasCsvLogMetadataRouteHint = $hasCsvLogMultiToolHint
            || $hasCsvLogKeyHint
            || ($hasCsvContext && preg_match('/ログ/u', $message) === 1);
        $hasStrongCsvAggregateIntent = preg_match('/(集計|件数|月別|日別|カテゴリ別|ランキング|グラフ|合計|平均|何種類)/u', $message) === 1;
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
            && !$hasHistorySummaryRequest
            && !$hasHistoryReportRequest
            && ($mentionedCsv === null || $targetColumn === null)
            && $this->shouldInheritRecentCsvContext(
                $message,
                $hasAggregateIntent,
                $hasDateIntent,
                $hasColumnExplainIntent,
                $hasMaterialWorkflowIntent,
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
                    $recentTargetColumn = trim((string)($recentContext['target_column'] ?? ''));
                    if ($recentTargetColumn !== '' && $this->shouldUseImplicitColumnMatch($recentTargetColumn)) {
                        $targetColumn = $recentTargetColumn;
                    }
                }
                if ($mentionedCsv !== null || $targetColumn !== null) {
                    $this->log("[SMART-ROUTER] 直前の会話履歴からCSV文脈を補完しました。file=" . ($mentionedCsv ?? 'none') . " | column=" . ($targetColumn ?? 'none'));
                    $routeEvidence[] = 'recent_csv_context';
                }
            }
        }

        $intent = 'unknown';
        $target = 'unknown';
        $scope = 'unknown';
        $operation = 'unknown';
        $timeAxis = 'none';
        $outputFormat = 'prose';
        if (preg_match('/(グラフ|グラフ化|チャート|棒グラフ|折れ線|円グラフ|可視化)/u', $message) === 1) {
            $outputFormat = 'chart';
        } elseif (preg_match('/(表に|表形式|テーブル|一覧で|一覧にして)/u', $message) === 1) {
            $outputFormat = 'table';
        }
        $route = null;

        if ($hasHistoryReportRequest) {
            $intent = 'summarize';
            $target = 'chat_history';
            $scope = 'conversation_thread';
            $operation = 'report';
            $route = 'advanced_hybrid.history_report';
            $setRouteMeta('high', ['history_report_request'], ['history_keywords', 'report_keywords']);
        } elseif ($hasProjectSummaryIntent && ($hasCsvContext || $hasDocReference || $this->hasRecentProjectAssetContext($recentHistory))) {
            $intent = 'summarize';
            $target = 'project_assets';
            $scope = 'project_wide';
            $operation = 'project_summary';
            $route = 'advanced_hybrid.multi_source_advice';
            $setRouteMeta('medium', ['project_summary_request'], array_values(array_filter([
                'project_summary_keywords',
                $hasCsvContext ? 'csv_context_keyword' : null,
                $hasDocReference ? 'document_context_keyword' : null,
                $this->hasRecentProjectAssetContext($recentHistory) ? 'recent_project_asset_context' : null,
            ])));
        } elseif ($hasMixedDocumentAndCsvContext && $hasRecommendationIntent) {
            $intent = 'analyze';
            $target = 'project_assets';
            $scope = 'project_wide';
            $operation = 'analysis_recommendation';
            $route = 'advanced_hybrid.multi_source_advice';
            $setRouteMeta('medium', ['mixed_asset_analysis_recommendation'], ['document_context_keyword', 'csv_context_keyword', 'recommendation_keyword']);
        } elseif ($hasMaterialWorkflowIntent) {
            $intent = 'consult';
            $target = 'project_memory';
            $scope = 'project_wide';
            $operation = 'material_workflow';
            $route = 'normal_rag.project_memory_consultation';
            $setRouteMeta('high', ['material_reference_with_action'], ['material_keyword', 'material_action_keyword']);
        } elseif ($hasTaskStatusIntent) {
            $intent = 'consult';
            $target = 'project_memory';
            $scope = 'project_wide';
            $operation = 'status_alignment';
            $route = 'normal_rag.project_memory_consultation';
            $setRouteMeta('high', ['project_status_question'], ['task_status_keyword']);
        } elseif (
            $hasCsvLogMetadataContext
            && $hasCsvLogMetadataIntent
            && $hasCsvLogMetadataRouteHint
            && !$hasStrongCsvAggregateIntent
            && !$hasDocReference
            && !$hasHistorySummaryRequest
            && !$hasHistoryReportRequest
            && !$hasMaterialWorkflowIntent
        ) {
            $intent = 'analyze';
            $target = 'project_assets';
            $scope = 'project_wide';
            $operation = 'csv_log_metadata_compare';
            $route = 'advanced_hybrid.multi_source_advice';
            $setRouteMeta(
                ($hasCsvLogMultiToolHint || $hasCsvLogKeyHint) ? 'high' : 'medium',
                array_values(array_filter([
                    'csv_log_metadata_compare_request',
                    $hasCsvLogMultiToolHint ? 'multi_tool_hint' : null,
                    $hasCsvLogKeyHint ? 'csv_key_hint' : null,
                ])),
                array_values(array_filter([
                    'log_context_keyword',
                    'metadata_compare_intent_keyword',
                    $hasCsvLogMultiToolHint ? 'multi_tool_hint' : null,
                    $hasCsvLogKeyHint ? 'csv_key_hint' : null,
                    $hasCsvContext ? 'csv_context_keyword' : null,
                ]))
            );
        } elseif (
            $hasRecommendationIntent
            && (
                (
                    !$hasStrongCsvAggregateIntent
                    && $hasCsvAdvisoryContext
                )
                || $hasDocReference
                || $hasProjectAdviceAnchor
                || $this->hasRecentProjectAssetContext($recentHistory)
            )
        ) {
            $intent = 'analyze';
            $target = 'project_assets';
            $scope = 'project_wide';
            $operation = 'analysis_recommendation';
            $route = 'advanced_hybrid.multi_source_advice';
            $setRouteMeta('medium', array_values(array_filter([
                $hasCsvAdvisoryContext && !$hasStrongCsvAggregateIntent ? 'csv_advisory_request' : null,
                'analysis_advice_request',
                'analysis_recommendation_request',
            ])), array_values(array_filter([
                'recommendation_keyword',
                $hasCsvAdvisoryContext ? 'csv_context' : null,
                $hasDocReference ? 'document_context_keyword' : null,
                $hasProjectAdviceAnchor ? 'project_advice_anchor' : null,
                $this->hasRecentProjectAssetContext($recentHistory) ? 'recent_project_asset_context' : null,
            ])));
        } elseif ($hasCsvOperationIntent && $hasCsvContext) {
            $intent = 'consult';
            $target = 'project_assets';
            $scope = 'project_wide';
            $operation = 'csv_workflow';
            $route = 'normal_rag.project_memory_consultation';
            $setRouteMeta('medium', ['csv_workflow_consultation'], ['csv_operation_keyword', 'csv_context_keyword']);
        } elseif ($hasNamingOrFramingIntent || ($hasAppVerificationIntent && $memorySuggestsAppVerification)) {
            $intent = 'consult';
            $target = 'project_memory';
            $scope = 'project_wide';
            $operation = $hasNamingOrFramingIntent ? 'framing' : 'status_alignment';
            $route = 'normal_rag.project_memory_consultation';
            $setRouteMeta(
                'medium',
                [$hasNamingOrFramingIntent ? 'framing_consultation' : 'app_verification_consultation'],
                array_values(array_filter([
                    $hasNamingOrFramingIntent ? 'framing_keyword' : null,
                    $hasAppVerificationIntent ? 'app_verification_keyword' : null,
                    $memorySuggestsAppVerification ? 'project_memory_app_context' : null,
                ]))
            );
        } elseif ($hasHistorySummaryRequest) {
            $intent = 'summarize';
            $target = 'chat_history';
            $mentionedCsv = null;
            $targetColumn = null;
            $scope = 'conversation_thread';
            $operation = 'summarize';
            $route = 'history_summary';
            $setRouteMeta('high', ['history_summary_request'], ['history_keywords']);
        } elseif ($hasAggregateIntent && ($hasDateIntent || $hasTimeBandIntent) && $mentionedCsv !== null) {
            $intent = 'aggregate';
            $target = 'single_csv';
            $scope = 'records_with_date';
            $operation = 'count';
            $timeAxis = $this->detectTimeAxis($message, $hasTimeBandIntent);
            $route = 'data_analysis.csv_agg';
            $setRouteMeta('high', ['csv_aggregation_request', 'explicit_csv_file', $hasTimeBandIntent ? 'time_band_request' : 'date_histogram_request'], array_values(array_filter([
                'aggregate_keyword',
                $hasTimeBandIntent ? 'time_band_keyword' : 'date_keyword',
                $mentionedCsv !== null ? 'explicit_csv_file' : null,
                $targetColumn !== null ? 'explicit_csv_column' : null,
                in_array('recent_csv_context', $routeEvidence, true) ? 'recent_csv_context' : null,
            ])));
        } elseif ($hasAggregateIntent && ($hasDateIntent || $hasTimeBandIntent) && $targetColumn !== null) {
            $intent = 'aggregate';
            $target = $mentionedCsv !== null ? 'single_csv' : 'all_csv';
            $scope = 'records_with_date';
            $operation = 'count';
            $timeAxis = $this->detectTimeAxis($message, $hasTimeBandIntent);
            $route = 'data_analysis.csv_agg';
            $setRouteMeta('high', ['csv_aggregation_request', $hasTimeBandIntent ? 'time_band_request' : 'date_histogram_request'], array_values(array_filter([
                'aggregate_keyword',
                $hasTimeBandIntent ? 'time_band_keyword' : 'date_keyword',
                $targetColumn !== null ? 'explicit_or_resolved_column' : null,
                $mentionedCsv !== null ? 'explicit_csv_file' : null,
                in_array('recent_csv_context', $routeEvidence, true) ? 'recent_csv_context' : null,
            ])));
        } elseif ($hasAggregateIntent && ($hasDateIntent || $hasTimeBandIntent) && $hasCsvContext) {
            $intent = 'aggregate';
            $target = 'all_csv';
            $scope = 'records_with_date';
            $operation = 'count';
            $timeAxis = $this->detectTimeAxis($message, $hasTimeBandIntent);
            $route = 'data_analysis.csv_agg';
            $setRouteMeta('medium', ['csv_aggregation_request', $hasTimeBandIntent ? 'time_band_request' : 'date_histogram_request'], ['aggregate_keyword', $hasTimeBandIntent ? 'time_band_keyword' : 'date_keyword', 'csv_context_keyword']);
        } elseif ($hasAggregateIntent && ($hasDateIntent || $hasTimeBandIntent) && $targetsAllCsv) {
            $intent = 'aggregate';
            $target = 'all_csv';
            $scope = 'records_with_date';
            $operation = 'count';
            $timeAxis = $this->detectTimeAxis($message, $hasTimeBandIntent);
            $route = 'data_analysis.csv_agg';
            $setRouteMeta('high', ['csv_aggregation_request', 'all_csv_request', $hasTimeBandIntent ? 'time_band_request' : 'date_histogram_request'], ['aggregate_keyword', 'all_csv_keyword', $hasTimeBandIntent ? 'time_band_keyword' : 'date_keyword']);
        } elseif ($hasAggregationFollowUpIntent && ($targetColumn !== null || $mentionedCsv !== null)) {
            $intent = 'aggregate';
            $target = $mentionedCsv !== null ? 'single_csv' : 'all_csv';
            $scope = ($hasDateIntent || $hasTimeBandIntent) ? 'records_with_date' : 'file_content';
            $operation = ($hasDateIntent || $hasTimeBandIntent) ? 'count' : ($targetColumn !== null ? 'value_distribution' : 'summarize');
            $timeAxis = ($hasDateIntent || $hasTimeBandIntent) ? $this->detectTimeAxis($message, $hasTimeBandIntent) : 'none';
            $route = 'data_analysis.csv_agg';
            $setRouteMeta('medium', ['csv_followup_request'], array_values(array_filter([
                'aggregation_followup_keyword',
                $mentionedCsv !== null ? 'explicit_csv_file' : null,
                $targetColumn !== null ? 'explicit_or_resolved_column' : null,
                in_array('recent_csv_context', $routeEvidence, true) ? 'recent_csv_context' : null,
            ])));
        } elseif ($targetColumn !== null && $hasAggregateIntent && $hasDistinctIntent) {
            $intent = 'aggregate';
            $target = $mentionedCsv !== null ? 'single_csv' : 'all_csv';
            $scope = 'file_content';
            $operation = 'distinct_count';
            $route = 'data_analysis.csv_agg';
            $setRouteMeta('high', ['csv_distinct_request'], ['aggregate_keyword', 'distinct_keyword', 'explicit_or_resolved_column']);
        } elseif ($targetColumn !== null && $hasColumnExistsIntent) {
            $intent = 'aggregate';
            $target = $mentionedCsv !== null ? 'single_csv' : 'all_csv';
            $scope = 'file_schema';
            $operation = 'column_exists';
            $route = 'data_analysis.csv_agg';
            $setRouteMeta('high', ['csv_column_exists_request'], ['column_exists_keyword', 'explicit_or_resolved_column']);
        } elseif ($targetColumn !== null && $hasColumnExplainIntent) {
            $intent = 'aggregate';
            $target = $mentionedCsv !== null ? 'single_csv' : 'all_csv';
            $scope = 'file_content';
            $operation = 'column_semantics';
            $route = 'data_analysis.csv_agg';
            $setRouteMeta('high', ['csv_column_semantics_request'], ['column_explain_keyword', 'explicit_or_resolved_column']);
        } elseif ($targetColumn !== null && $hasAggregateIntent) {
            $intent = 'aggregate';
            $target = $mentionedCsv !== null ? 'single_csv' : 'all_csv';
            $scope = 'file_content';
            $operation = 'value_distribution';
            $route = 'data_analysis.csv_agg';
            $setRouteMeta('high', ['csv_value_distribution_request'], ['aggregate_keyword', 'explicit_or_resolved_column']);
        } elseif ($hasCsvExportIntent) {
            $intent = 'export';
            $target = $mentionedCsv !== null ? 'single_csv' : 'all_csv';
            $scope = $mentionedCsv !== null ? 'file_content' : 'project_wide';
            $operation = 'export_csv';
            $outputFormat = 'table';
            $route = 'data_analysis.csv_export_request';
            $setRouteMeta('high', ['csv_export_request'], array_values(array_filter([
                'csv_export_keyword',
                $mentionedCsv !== null ? 'explicit_csv_file' : null,
            ])));
        } elseif ($hasSummaryIntent && $mentionedCsv !== null) {
            $intent = 'summarize';
            $target = 'single_csv';
            $scope = 'file_content';
            $operation = 'summarize';
            $route = 'data_analysis.csv_summary';
            $setRouteMeta('high', ['csv_summary_request', 'explicit_csv_file'], ['summary_keyword', 'explicit_csv_file']);
        } elseif ($hasSummaryIntent && preg_match('/(CSV|csv|ファイル|データ)/u', $message) === 1) {
            $intent = 'summarize';
            $target = 'all_csv';
            $scope = 'project_wide';
            $operation = 'summarize';
            $route = 'data_analysis.csv_summary';
            $setRouteMeta('medium', ['csv_summary_request'], ['summary_keyword', 'csv_context_keyword']);
        } elseif ($hasAggregateIntent && $hasCsvContext && $hasBroadDetailIntent && !$hasDateIntent && !$hasTimeBandIntent && $targetColumn === null) {
            $intent = 'summarize';
            $target = $mentionedCsv !== null ? 'single_csv' : 'all_csv';
            $scope = $mentionedCsv !== null ? 'file_content' : 'project_wide';
            $operation = 'summarize';
            $route = 'data_analysis.csv_summary';
            $setRouteMeta('medium', ['csv_summary_fallback'], ['aggregate_keyword', 'csv_context_keyword', 'broad_detail_keyword']);
        } elseif (!$targetsAllCsv && $mentionedCsv === null && ($hasDocReference || $hasDocActionIntent)) {
            $intent = 'extract_evidence';
            $target = 'pdf';
            $scope = 'project_wide';
            $operation = 'extract_evidence';
            $outputFormat = preg_match('/(箇条書き|3点|3つ|列挙|リスト)/u', $message) === 1 ? 'bullets' : $outputFormat;
            $route = 'advanced_hybrid.doc_extract';
            $setRouteMeta('medium', [$hasDocActionIntent ? 'extract_evidence_request' : 'broad_document_reference'], array_values(array_filter([
                $hasDocReference ? 'document_context_keyword' : null,
                $hasDocActionIntent ? 'document_action_keyword' : null,
            ])));
        } elseif ($route === null) {
            $setRouteMeta('low', ['fallback_normal_rag'], ['no_strong_route_signal']);
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
            'route_confidence' => $routeConfidence,
            'route_reason_codes' => $routeReasonCodes,
            'route_evidence' => array_slice($routeEvidence, 0, 6),
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
            $autoContent = trim((string)($projectMemoryDocs[$memoryType]['auto_content'] ?? ''));
            $content = trim((string)($projectMemoryDocs[$memoryType]['content'] ?? ''));
            if ($autoContent !== '') {
                $parts[] = $autoContent;
            }
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

        if (preg_match('/(?:^|[\s　])([A-Za-z_][A-Za-z0-9_]*|[一-龠ぁ-んァ-ヶー]+)\s*から\s*(?:年月|月別|年別|日別|日時|日付|時刻|時間帯|時刻帯|時間ごと|時ごと)/u', $message, $matches) === 1) {
            $candidate = trim((string)($matches[1] ?? ''));
            if ($candidate !== '') {
                return $candidate;
            }
        }

        return null;
    }

    private function shouldUseImplicitColumnMatch(string $column): bool
    {
        return mb_strlen(trim($column), 'UTF-8') > 1;
    }

    private function shouldInheritRecentCsvContext(
        string $message,
        bool $hasAggregateIntent,
        bool $hasDateIntent,
        bool $hasColumnExplainIntent,
        bool $hasMaterialWorkflowIntent,
        ?string $targetColumn
    ): bool {
        if ($hasMaterialWorkflowIntent) {
            return false;
        }

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
        return preg_match('/(若い順|古い順|昇順|降順|新しい順|新しいものから|古いものから|グラフ|グラフ化|チャート|棒グラフ|折れ線|円グラフ|並び替え|並べ替え|ソート|時間帯ごと|時間ごと|時刻帯|すべて表示|全件|全部|続きを表示|すべてのランキング|ランキングを表示)/iu', $message) === 1;
    }

    private function detectTimeAxis(string $message, bool $hasTimeBandIntent): string
    {
        if ($hasTimeBandIntent) {
            return 'hour';
        }
        if (preg_match('/(月別|月ごと|年月|日時は不要|月単位)/u', $message) === 1) {
            return 'month';
        }
        if (preg_match('/(年別|年ごと)/u', $message) === 1) {
            return 'year';
        }
        return 'day';
    }

    private function hasRecentProjectAssetContext(array $recentHistory): bool
    {
        if (empty($recentHistory)) {
            return false;
        }

        for ($i = count($recentHistory) - 1; $i >= 0; $i--) {
            $historyMessage = trim((string)($recentHistory[$i]['message'] ?? ''));
            if ($historyMessage === '') {
                continue;
            }

            if (preg_match('/(CSV|csv|PDF|pdf|資料|図面|データ|集計|分析|ファイル|カラム|列)/u', $historyMessage) === 1) {
                return true;
            }
        }

        return false;
    }
}
