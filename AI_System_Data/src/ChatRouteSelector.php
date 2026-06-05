<?php

require_once __DIR__ . '/ChatHistoryContextResolver.php';

class ChatRouteSelector
{
    private $csvContextResolver;
    private $logger;

    public function __construct(?ChatHistoryContextResolver $csvContextResolver = null, $logger = null)
    {
        $this->csvContextResolver = $csvContextResolver;
        $this->logger = is_callable($logger) ? $logger : null;
    }

    public function select(array $context): array
    {
        $message = (string)($context['message'] ?? '');
        $projectId = $context['project_id'] ?? null;
        $factorizedQuery = (array)($context['factorized_query'] ?? []);
        $factorizedRoute = (string)($factorizedQuery['route'] ?? '');
        $explicitAdvanced = (bool)($context['explicit_advanced'] ?? false);
        $reportMode = (bool)($context['report_mode'] ?? false);

        $advancedReasoning = false;
        $isAnalysisMode = false;
        $isHistorySummaryMode = false;
        $preferNormalRag = false;

        $complexPattern  = '/(比較|違い|相違|対比|網羅|分析|解析|詳細|詳しく|まとめ|総括|検討|留意点|評価|影響|検証|整合性|关系|どう違う|解説して)/u';
        $analysisPattern = '/(集計|何種類|割合|平均|カウント|件数|グラフ|チャート|分布|推移|合計)/u';
        $csvEvidencePattern = '/(CSV|csv|登録済み.*データ|データ.*(内容|概要|項目|列|カラム|入って)|列には|カラムには|項目には)/u';
        $historySummaryPattern = '/((これまで|今まで|過去|直近).*(会話|やりとり|チャット|履歴).*(まとめ|要約|整理)|((会話|やりとり|チャット|履歴).*(まとめ|要約|整理)))/u';
        $historyReportPattern = '/((会話|やりとり|チャット|履歴).*(報告書|レポート|PDF).*(作成|作って|出力|生成)|((報告書|レポート|PDF).*(作成|作って|出力|生成).*(会話|やりとり|チャット|履歴)))/u';
        $structuredAnalysisPattern = '/(transaction_uid|login_seconds|row_data|APP_\d+|ユーザー.*(操作|時間)|操作.*(時間|秒|秒数)|ログイン秒|利用時間|滞在時間|実行時間)/iu';
        $normalRagPreferredPattern = '/(良い案|よい案|方法|支援する方法|設計書案|仕様書案|要件定義|システム.*構築|提案|企画|たたき台|ドラフト)/u';
        $hasHistorySummaryRequest = preg_match($historySummaryPattern, $message) === 1;
        $hasHistoryReportRequest = preg_match($historyReportPattern, $message) === 1;

        if (($hasHistoryReportRequest || ($hasHistorySummaryRequest && $reportMode)) && $projectId !== null) {
            $explicitAdvanced = true;
            if ($hasHistoryReportRequest) {
                $this->log("[SMART-ROUTER] 会話履歴の報告書化要求を検知。軽量履歴要約ではなく報告書向けフル思考ルートを優先します。");
            } else {
                $this->log("[SMART-ROUTER] 会話履歴要約要求に report_mode=on が付与されているため、軽量履歴要約ではなく報告書向けフル思考ルートを優先します。");
            }

        } elseif ($hasHistorySummaryRequest) {
            $isHistorySummaryMode = true;
            $this->log("[SMART-ROUTER] 会話履歴要約要求を検知。report_mode より軽量履歴サマリールートを優先します。");

        } elseif ($reportMode && $projectId !== null) {
            $explicitAdvanced = true;
            $this->log("[SMART-ROUTER] 報告書モードを検知。PDF生成・検索登録のためフル思考ルートへ寄せます。");
        }

        $csvSummaryOrAggRoute = in_array(($factorizedQuery['route'] ?? null), ['data_analysis.csv_agg', 'data_analysis.csv_summary'], true);
        $allowCsvRouteOverride = $projectId !== null
            && !$reportMode
            && $csvSummaryOrAggRoute;

        if (!$isHistorySummaryMode) {
            if ($projectId === null && $explicitAdvanced) {
                $this->log("[SMART-ROUTER] 案件未選択のため、フル思考指定よりも汎用・全社横断ルートを優先します。");

            } elseif ($projectId === null && (
                preg_match($complexPattern, $message) ||
                mb_strlen($message) >= 50
            )) {
                $this->log("[SMART-ROUTER] 案件未選択の汎用質問を検知。ハイブリッド多重推論ではなくグローバルルートを優先します。");

            } elseif ($allowCsvRouteOverride && ($factorizedQuery['route'] ?? null) === 'data_analysis.csv_agg') {
                $isAnalysisMode = true;
                $this->log("[SMART-ROUTER] CSV集計系の質問は軽量分析を優先します。explicit_advanced=" . ($explicitAdvanced ? 'on' : 'off') . " | file=" . ($factorizedQuery['target_file_name'] ?? 'all'));

            } elseif ($allowCsvRouteOverride && ($factorizedQuery['route'] ?? null) === 'data_analysis.csv_summary') {
                $isAnalysisMode = true;
                $this->log("[SMART-ROUTER] CSV要約系の質問は軽量分析を優先します。explicit_advanced=" . ($explicitAdvanced ? 'on' : 'off') . " | target=" . ($factorizedQuery['target'] ?? 'unknown'));

            } elseif ($explicitAdvanced && $projectId !== null) {
                $advancedReasoning = true;
                $isAnalysisMode = false;
                $this->log("[SMART-ROUTER] フル思考モードの明示指定を検知。ハイブリッド多重推論統合ハブをキックします。");

            } elseif ($projectId !== null && preg_match($structuredAnalysisPattern, $message)) {
                $isAnalysisMode = true;
                $this->log("[SMART-ROUTER] 構造化データ参照に適した質問を検知。データ分析ルートを優先します。");

            } elseif ($projectId !== null && ($factorizedQuery['route'] ?? null) === 'data_analysis.csv_agg') {
                $isAnalysisMode = true;
                $this->log("[SMART-ROUTER] 質問因数分解によりCSV集計ルートを優先します。target=" . ($factorizedQuery['target'] ?? 'unknown') . " | file=" . ($factorizedQuery['target_file_name'] ?? 'all'));

            } elseif ($projectId !== null && ($factorizedQuery['route'] ?? null) === 'data_analysis.csv_summary') {
                $isAnalysisMode = true;
                $this->log("[SMART-ROUTER] 質問因数分解によりCSV要約ルートを優先します。file=" . ($factorizedQuery['target_file_name'] ?? 'unknown'));

            } elseif ($projectId !== null && $factorizedRoute === 'advanced_hybrid.doc_extract') {
                $advancedReasoning = true;
                $isAnalysisMode = false;
                $this->log("[SMART-ROUTER] 質問因数分解により資料PDF抽出ルートを優先します。target=" . ($factorizedQuery['target'] ?? 'unknown'));

            } elseif ($projectId !== null && $factorizedRoute !== '' && str_starts_with($factorizedRoute, 'advanced_hybrid.')) {
                $advancedReasoning = true;
                $isAnalysisMode = false;
                $this->log("[SMART-ROUTER] 質問因数分解によりハイブリッド分析ルートを優先します。route={$factorizedRoute} | target=" . ($factorizedQuery['target'] ?? 'unknown'));

            } elseif ($projectId !== null && $factorizedRoute !== '' && str_starts_with($factorizedRoute, 'normal_rag.')) {
                $preferNormalRag = true;
                $this->log("[SMART-ROUTER] 質問因数分解により案件運用メモを踏まえた相談ルートを優先します。route={$factorizedRoute}");

            } elseif ($projectId !== null && preg_match($csvEvidencePattern, $message)) {
                $isAnalysisMode = true;
                $this->log("[SMART-ROUTER] CSV証拠読解に適した質問を検知。CSV全件証拠収集ルートを優先します。");

            } elseif (preg_match($normalRagPreferredPattern, $message)) {
                $preferNormalRag = true;
                $this->log("[SMART-ROUTER] 提案・設計書作成系の質問を検知。通常RAGルートを優先します。");

            } elseif ($projectId !== null && !$preferNormalRag && (
                preg_match($complexPattern, $message) ||
                mb_strlen($message) >= 50
            )) {
                $advancedReasoning = true;
                $isAnalysisMode = false;
                $this->log("[SMART-ROUTER] 高度なマルチタスク文脈を検知。最優先で「ハイブリッド多重推論統合ハブ(chat_advanced.php Colonial)」をキックします。");

            } elseif ($projectId !== null && preg_match($analysisPattern, $message)) {
                $isAnalysisMode = true;
                $this->log("[SMART-ROUTER] 純粋なデータ集計要求を検知。単発の「データ分析エージェント(chat_analysis.php)」を起動します。");
            }
        }

        if (
            $projectId !== null &&
            !$advancedReasoning &&
            !$isHistorySummaryMode &&
            $factorizedRoute !== 'advanced_hybrid.doc_extract'
        ) {
            try {
                $mentionedCsv = $this->csvContextResolver !== null
                    ? $this->csvContextResolver->findMentionedCsvFileName($message)
                    : null;
                if ($mentionedCsv !== null) {
                    $isAnalysisMode = true;
                    $preferNormalRag = false;
                    $this->log("[SMART-ROUTER] 登録済みCSVファイル名への言及を検知。CSV分析ルートへ切替: {$mentionedCsv}");
                }
            } catch (Throwable $csvRouteEx) {
                $this->log("[SMART-ROUTER] CSVファイル名ルーティング確認に失敗: " . $csvRouteEx->getMessage());
            }
        }

        return [
            'advanced_reasoning' => $advancedReasoning,
            'is_analysis_mode' => $isAnalysisMode,
            'is_history_summary_mode' => $isHistorySummaryMode,
            'prefer_normal_rag' => $preferNormalRag,
            'explicit_advanced' => $explicitAdvanced,
        ];
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }
}
