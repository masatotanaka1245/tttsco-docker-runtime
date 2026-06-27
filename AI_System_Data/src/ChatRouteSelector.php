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
        $recentHistory = (array)($context['recent_history'] ?? []);
        $factorizedQuery = (array)($context['factorized_query'] ?? []);
        $conversationIntentProfile = (array)($context['conversation_intent_profile'] ?? []);
        $factorizedRoute = (string)($factorizedQuery['route'] ?? '');
        $factorizedOperation = (string)($factorizedQuery['operation'] ?? '');
        $factorizedConfidence = (string)($factorizedQuery['route_confidence'] ?? 'low');
        $factorizedReasonCodes = array_values(array_filter((array)($factorizedQuery['route_reason_codes'] ?? []), 'is_string'));
        $factorizedEvidence = array_values(array_filter((array)($factorizedQuery['route_evidence'] ?? []), 'is_string'));
        $explicitAdvanced = (bool)($context['explicit_advanced'] ?? false);
        $reportMode = (bool)($context['report_mode'] ?? false);

        $advancedReasoning = false;
        $isAnalysisMode = false;
        $isHistorySummaryMode = false;
        $preferNormalRag = false;
        $routeDetailOverride = null;
        $recentArtifactState = null;
        $selectorReasonCodes = [];
        $selectorEvidence = [];
        $selectedRouteConfidence = $factorizedConfidence !== '' ? $factorizedConfidence : 'low';
        $selectedRouteReasonCodes = $factorizedReasonCodes;
        $selectedRouteEvidence = $factorizedEvidence;

        $appendUnique = static function (array &$items, ?string $value): void {
            $value = trim((string)$value);
            if ($value === '' || in_array($value, $items, true)) {
                return;
            }
            $items[] = $value;
        };

        $complexPattern  = '/(比較|違い|相違|対比|網羅|分析|解析|詳細|詳しく|まとめ|総括|検討|留意点|評価|影響|検証|整合性|关系|どう違う|解説して)/u';
        $analysisPattern = '/(集計|何種類|割合|平均|カウント|件数|グラフ|チャート|分布|推移|合計)/u';
        $csvEvidencePattern = '/(CSV|csv|登録済み.*データ|データ.*(内容|概要|項目|列|カラム|入って)|列には|カラムには|項目には)/u';
        $historySummaryPattern = '/((これまで|今まで|過去|直近).*(会話|やりとり|チャット|履歴).*(まとめ|要約|整理)|((会話|やりとり|チャット|履歴).*(まとめ|要約|整理)))/u';
        $historyReportPattern = '/((会話|やりとり|チャット|履歴).*(報告書|レポート|PDF))|((報告書|レポート|PDF).*(会話|やりとり|チャット|履歴))|((会話|やりとり|チャット|履歴).*(報告書|レポート|PDF).*(作成|作って|出力|生成|にして|化して))|((報告書|レポート|PDF).*(作成|作って|出力|生成|にして|化して).*(会話|やりとり|チャット|履歴))/u';
        $structuredAnalysisPattern = '/(transaction_uid|login_seconds|row_data|APP_\d+|ユーザー.*(操作|時間)|操作.*(時間|秒|秒数)|ログイン秒|利用時間|滞在時間|実行時間)/iu';
        $normalRagPreferredPattern = '/(良い案|よい案|方法|支援する方法|設計書案|仕様書案|要件定義|システム.*構築|提案|企画|たたき台|ドラフト)/u';
        $csvOperationPattern = '/((転記|統合|結合|マージ|取り込|追加|反映|上書き).*(できますか|可能|したい|方法|手順|どうやって|どうすれば))|((できますか|可能|したい|方法|手順|どうやって|どうすれば).*(転記|統合|結合|マージ|取り込|追加|反映|上書き))/u';
        $hasHistorySummaryRequest = preg_match($historySummaryPattern, $message) === 1;
        $hasHistoryReportRequest = preg_match($historyReportPattern, $message) === 1;
        $intentRelation = trim((string)($conversationIntentProfile['conversation_relation'] ?? ''));
        $intentRequestType = trim((string)($conversationIntentProfile['request_type'] ?? ''));
        $intentTodoPolicyHint = trim((string)($conversationIntentProfile['todo_policy_hint'] ?? ''));
        $hasReadOnlyIntentOverride = $this->shouldUseReadOnlyIntentConsultationRoute(
            $conversationIntentProfile,
            $factorizedRoute,
            $factorizedOperation,
            $message,
            $reportMode,
            $explicitAdvanced
        );

        if (($hasHistoryReportRequest || ($hasHistorySummaryRequest && $reportMode)) && $projectId !== null) {
            $explicitAdvanced = true;
            $appendUnique($selectorReasonCodes, 'selector_history_report');
            $appendUnique($selectorEvidence, $hasHistoryReportRequest ? 'history_report_keyword' : 'report_mode_with_history_summary');
            $selectedRouteConfidence = 'high';
            if ($hasHistoryReportRequest) {
                $this->log("[SMART-ROUTER] 会話履歴の報告書化要求を検知。軽量履歴要約ではなく報告書向けフル思考ルートを優先します。");
            } else {
                $this->log("[SMART-ROUTER] 会話履歴要約要求に report_mode=on が付与されているため、軽量履歴要約ではなく報告書向けフル思考ルートを優先します。");
            }

        } elseif ($hasHistorySummaryRequest) {
            $isHistorySummaryMode = true;
            $appendUnique($selectorReasonCodes, 'selector_history_summary');
            $appendUnique($selectorEvidence, 'history_summary_keyword');
            $selectedRouteConfidence = 'high';
            $this->log("[SMART-ROUTER] 会話履歴要約要求を検知。report_mode より軽量履歴サマリールートを優先します。");

        } elseif ($reportMode && $projectId !== null) {
            $explicitAdvanced = true;
            $appendUnique($selectorReasonCodes, 'selector_report_mode_advanced');
            $appendUnique($selectorEvidence, 'report_mode_enabled');
            $selectedRouteConfidence = 'medium';
            $this->log("[SMART-ROUTER] 報告書モードを検知。PDF生成・検索登録のためフル思考ルートへ寄せます。");
        }

        if (
            !$isHistorySummaryMode &&
            !$reportMode &&
            $projectId !== null &&
            $this->csvContextResolver !== null &&
            !($explicitAdvanced && $factorizedRoute !== 'data_analysis.csv_agg')
        ) {
            try {
                $recentArtifactState = $this->csvContextResolver->findRecentArtifactState($recentHistory);
                if ($this->shouldLockCsvAggregationRoute($message, $factorizedRoute, $factorizedQuery, $recentArtifactState)) {
                    $isAnalysisMode = true;
                    $advancedReasoning = false;
                    $preferNormalRag = false;
                    $routeDetailOverride = 'data_analysis.csv_agg.route_lock';
                    $appendUnique($selectorReasonCodes, 'selector_csv_route_lock');
                    $appendUnique($selectorEvidence, 'recent_artifact_state');
                    if (!empty($recentArtifactState['target_file_name'])) {
                        $appendUnique($selectorEvidence, 'recent_artifact_file');
                    }
                    if (!empty($recentArtifactState['target_column'])) {
                        $appendUnique($selectorEvidence, 'recent_artifact_column');
                    }
                    $selectedRouteConfidence = 'high';
                    $this->log(
                        "[SMART-ROUTER] 直前の成果品ステートを検知したため CSV集計ルートを継続固定します。"
                        . " file=" . ($recentArtifactState['target_file_name'] ?? 'unknown')
                        . " | column=" . ($recentArtifactState['target_column'] ?? 'unknown')
                        . " | aggregation_mode=" . ($recentArtifactState['aggregation_mode'] ?? 'unknown')
                        . " | sort_order=" . ($recentArtifactState['sort_order'] ?? 'unknown')
                        . " | wants_chart=" . (!empty($recentArtifactState['wants_chart']) ? 'yes' : 'no')
                    );
                }
            } catch (Throwable $artifactStateEx) {
                $this->log("[SMART-ROUTER] 成果品ステート継続判定に失敗: " . $artifactStateEx->getMessage());
            }
        }

        $csvSummaryOrAggRoute = in_array(($factorizedQuery['route'] ?? null), ['data_analysis.csv_agg', 'data_analysis.csv_summary', 'data_analysis.csv_export_request'], true);
        $allowCsvRouteOverride = $projectId !== null
            && !$reportMode
            && $csvSummaryOrAggRoute;

        if (!$isHistorySummaryMode) {
            if ($projectId === null && $explicitAdvanced) {
                $this->log("[SMART-ROUTER] 案件未選択のため、フル思考指定よりも汎用・全社横断ルートを優先します。");

            } elseif ($projectId !== null && $hasReadOnlyIntentOverride) {
                $preferNormalRag = true;
                $advancedReasoning = false;
                $isAnalysisMode = false;
                $routeDetailOverride = 'normal_rag.project_memory_consultation';
                $isMultiSourceAdviceOverride = $factorizedRoute === 'advanced_hybrid.multi_source_advice';
                $appendUnique(
                    $selectorReasonCodes,
                    $isMultiSourceAdviceOverride
                        ? 'selector_intent_read_only_consultation_multi_source_advice_override'
                        : 'selector_intent_read_only_consultation'
                );
                $appendUnique($selectorEvidence, 'intent_profile_read_only');
                if ($intentRelation !== '') {
                    $appendUnique($selectorEvidence, 'intent_relation_' . $intentRelation);
                }
                if ($intentRequestType !== '') {
                    $appendUnique($selectorEvidence, 'intent_request_type_' . $intentRequestType);
                }
                if ($intentTodoPolicyHint !== '') {
                    $appendUnique($selectorEvidence, 'intent_todo_policy_' . $intentTodoPolicyHint);
                }
                if ($isMultiSourceAdviceOverride) {
                    $appendUnique($selectorEvidence, 'factorized_multi_source_advice');
                }
                $selectedRouteConfidence = $factorizedConfidence !== 'low' ? $factorizedConfidence : 'medium';
                $this->log(
                    "[SMART-ROUTER] conversation_intent_profile により read-only 相談ルートを優先します。"
                    . " route=normal_rag.project_memory_consultation"
                    . " | factorized_route=" . ($factorizedRoute !== '' ? $factorizedRoute : 'none')
                    . " | relation=" . ($intentRelation !== '' ? $intentRelation : 'unknown')
                    . " | request_type=" . ($intentRequestType !== '' ? $intentRequestType : 'unknown')
                    . " | todo_policy_hint=" . ($intentTodoPolicyHint !== '' ? $intentTodoPolicyHint : 'unknown')
                );

            } elseif ($projectId === null && (
                preg_match($complexPattern, $message) ||
                mb_strlen($message) >= 50
            )) {
                $this->log("[SMART-ROUTER] 案件未選択の汎用質問を検知。ハイブリッド多重推論ではなくグローバルルートを優先します。");

            } elseif ($allowCsvRouteOverride && ($factorizedQuery['route'] ?? null) === 'data_analysis.csv_agg') {
                $isAnalysisMode = true;
                $appendUnique($selectorReasonCodes, 'selector_data_analysis_csv_agg');
                $appendUnique($selectorEvidence, 'factorized_csv_agg');
                $selectedRouteConfidence = $factorizedConfidence !== 'low' ? $factorizedConfidence : 'medium';
                $this->log("[SMART-ROUTER] CSV集計系の質問は軽量分析を優先します。explicit_advanced=" . ($explicitAdvanced ? 'on' : 'off') . " | file=" . ($factorizedQuery['target_file_name'] ?? 'all'));

            } elseif ($allowCsvRouteOverride && ($factorizedQuery['route'] ?? null) === 'data_analysis.csv_export_request') {
                $isAnalysisMode = true;
                $appendUnique($selectorReasonCodes, 'selector_data_analysis_csv_export');
                $appendUnique($selectorEvidence, 'factorized_csv_export_request');
                $selectedRouteConfidence = $factorizedConfidence !== 'low' ? $factorizedConfidence : 'medium';
                $this->log("[SMART-ROUTER] CSV生成系の質問は軽量分析を優先します。explicit_advanced=" . ($explicitAdvanced ? 'on' : 'off') . " | target=" . ($factorizedQuery['target'] ?? 'unknown'));

            } elseif ($allowCsvRouteOverride && ($factorizedQuery['route'] ?? null) === 'data_analysis.csv_summary') {
                $isAnalysisMode = true;
                $appendUnique($selectorReasonCodes, 'selector_data_analysis_csv_summary');
                $appendUnique($selectorEvidence, 'factorized_csv_summary');
                $selectedRouteConfidence = $factorizedConfidence !== 'low' ? $factorizedConfidence : 'medium';
                $this->log("[SMART-ROUTER] CSV要約系の質問は軽量分析を優先します。explicit_advanced=" . ($explicitAdvanced ? 'on' : 'off') . " | target=" . ($factorizedQuery['target'] ?? 'unknown'));

            } elseif ($projectId !== null && $this->shouldPreferProjectMemoryConsultation($message, $factorizedRoute, $factorizedOperation)) {
                $preferNormalRag = true;
                $advancedReasoning = false;
                $isAnalysisMode = false;
                $appendUnique($selectorReasonCodes, 'selector_project_memory_consultation');
                $appendUnique($selectorEvidence, $factorizedRoute === 'normal_rag.project_memory_consultation' ? 'factorized_project_memory_consultation' : 'project_memory_consultation_override');
                $selectedRouteConfidence = in_array($factorizedOperation, ['material_workflow', 'status_alignment'], true) ? 'high' : ($factorizedConfidence !== 'low' ? $factorizedConfidence : 'medium');
                $this->log("[SMART-ROUTER] 成果品相談・現在地確認の意図を検知。案件運用メモを踏まえた相談ルートを優先します。route=normal_rag.project_memory_consultation | operation=" . ($factorizedOperation !== '' ? $factorizedOperation : 'unknown'));

            } elseif ($explicitAdvanced && $projectId !== null) {
                $advancedReasoning = true;
                $isAnalysisMode = false;
                $appendUnique($selectorReasonCodes, 'selector_explicit_advanced');
                $appendUnique($selectorEvidence, 'explicit_advanced_flag');
                $selectedRouteConfidence = $factorizedConfidence !== 'low' ? $factorizedConfidence : 'medium';
                $this->log("[SMART-ROUTER] フル思考モードの明示指定を検知。ハイブリッド多重推論統合ハブをキックします。");

            } elseif ($projectId !== null && preg_match($structuredAnalysisPattern, $message)) {
                $isAnalysisMode = true;
                $appendUnique($selectorReasonCodes, 'selector_structured_analysis');
                $appendUnique($selectorEvidence, 'structured_analysis_pattern');
                $selectedRouteConfidence = 'medium';
                $this->log("[SMART-ROUTER] 構造化データ参照に適した質問を検知。データ分析ルートを優先します。");

            } elseif ($projectId !== null && ($factorizedQuery['route'] ?? null) === 'data_analysis.csv_agg') {
                $isAnalysisMode = true;
                $appendUnique($selectorReasonCodes, 'selector_factorized_csv_agg');
                $appendUnique($selectorEvidence, 'factorized_csv_agg');
                $selectedRouteConfidence = $factorizedConfidence !== 'low' ? $factorizedConfidence : 'medium';
                $this->log("[SMART-ROUTER] 質問因数分解によりCSV集計ルートを優先します。target=" . ($factorizedQuery['target'] ?? 'unknown') . " | file=" . ($factorizedQuery['target_file_name'] ?? 'all'));

            } elseif ($projectId !== null && ($factorizedQuery['route'] ?? null) === 'data_analysis.csv_export_request') {
                $isAnalysisMode = true;
                $appendUnique($selectorReasonCodes, 'selector_factorized_csv_export');
                $appendUnique($selectorEvidence, 'factorized_csv_export_request');
                $selectedRouteConfidence = $factorizedConfidence !== 'low' ? $factorizedConfidence : 'medium';
                $this->log("[SMART-ROUTER] 質問因数分解によりCSV生成ルートを優先します。target=" . ($factorizedQuery['target'] ?? 'unknown'));

            } elseif ($projectId !== null && ($factorizedQuery['route'] ?? null) === 'data_analysis.csv_summary') {
                $isAnalysisMode = true;
                $appendUnique($selectorReasonCodes, 'selector_factorized_csv_summary');
                $appendUnique($selectorEvidence, 'factorized_csv_summary');
                $selectedRouteConfidence = $factorizedConfidence !== 'low' ? $factorizedConfidence : 'medium';
                $this->log("[SMART-ROUTER] 質問因数分解によりCSV要約ルートを優先します。file=" . ($factorizedQuery['target_file_name'] ?? 'unknown'));

            } elseif ($projectId !== null && $factorizedRoute === 'advanced_hybrid.doc_extract') {
                $advancedReasoning = true;
                $isAnalysisMode = false;
                $appendUnique($selectorReasonCodes, 'selector_doc_extract');
                $appendUnique($selectorEvidence, 'factorized_doc_extract');
                $selectedRouteConfidence = $factorizedConfidence !== 'low' ? $factorizedConfidence : 'medium';
                $this->log("[SMART-ROUTER] 質問因数分解により資料PDF抽出ルートを優先します。target=" . ($factorizedQuery['target'] ?? 'unknown'));

            } elseif ($projectId !== null && $factorizedRoute !== '' && str_starts_with($factorizedRoute, 'advanced_hybrid.')) {
                $advancedReasoning = true;
                $isAnalysisMode = false;
                $appendUnique($selectorReasonCodes, 'selector_factorized_advanced');
                $appendUnique($selectorEvidence, 'factorized_advanced_route');
                $selectedRouteConfidence = $factorizedConfidence !== 'low' ? $factorizedConfidence : 'medium';
                $this->log("[SMART-ROUTER] 質問因数分解によりハイブリッド分析ルートを優先します。route={$factorizedRoute} | target=" . ($factorizedQuery['target'] ?? 'unknown'));

            } elseif ($projectId !== null && $factorizedRoute !== '' && str_starts_with($factorizedRoute, 'normal_rag.')) {
                $preferNormalRag = true;
                $appendUnique($selectorReasonCodes, 'selector_prefer_normal_rag');
                $appendUnique($selectorEvidence, 'factorized_normal_rag_route');
                $selectedRouteConfidence = $factorizedConfidence !== 'low' ? $factorizedConfidence : 'medium';
                $this->log("[SMART-ROUTER] 質問因数分解により案件運用メモを踏まえた相談ルートを優先します。route={$factorizedRoute}");

            } elseif ($projectId !== null && preg_match($csvEvidencePattern, $message)) {
                $isAnalysisMode = true;
                $appendUnique($selectorReasonCodes, 'selector_csv_evidence');
                $appendUnique($selectorEvidence, 'csv_evidence_pattern');
                $selectedRouteConfidence = 'medium';
                $this->log("[SMART-ROUTER] CSV証拠読解に適した質問を検知。CSV全件証拠収集ルートを優先します。");

            } elseif (preg_match($normalRagPreferredPattern, $message)) {
                $preferNormalRag = true;
                $appendUnique($selectorReasonCodes, 'selector_normal_rag_preferred');
                $appendUnique($selectorEvidence, 'normal_rag_preferred_pattern');
                $selectedRouteConfidence = 'medium';
                $this->log("[SMART-ROUTER] 提案・設計書作成系の質問を検知。通常RAGルートを優先します。");

            } elseif ($projectId !== null && !$preferNormalRag && (
                preg_match($complexPattern, $message) ||
                mb_strlen($message) >= 50
            )) {
                $advancedReasoning = true;
                $isAnalysisMode = false;
                $appendUnique($selectorReasonCodes, 'selector_complex_advanced');
                $appendUnique($selectorEvidence, preg_match($complexPattern, $message) ? 'complex_pattern' : 'long_message');
                $selectedRouteConfidence = 'medium';
                $this->log("[SMART-ROUTER] 高度なマルチタスク文脈を検知。最優先で「ハイブリッド多重推論統合ハブ(chat_advanced.php Colonial)」をキックします。");

            } elseif ($projectId !== null && preg_match($analysisPattern, $message)) {
                $isAnalysisMode = true;
                $appendUnique($selectorReasonCodes, 'selector_data_analysis');
                $appendUnique($selectorEvidence, 'analysis_pattern');
                $selectedRouteConfidence = 'medium';
                $this->log("[SMART-ROUTER] 純粋なデータ集計要求を検知。単発の「データ分析エージェント(chat_analysis.php)」を起動します。");
            }
        }

        if (
            $projectId !== null &&
            !$advancedReasoning &&
            !$preferNormalRag &&
            !$isHistorySummaryMode &&
            preg_match($csvOperationPattern, $message) !== 1 &&
            $factorizedRoute !== 'advanced_hybrid.doc_extract' &&
            ($factorizedRoute === '' || (!str_starts_with($factorizedRoute, 'advanced_hybrid.') && !str_starts_with($factorizedRoute, 'normal_rag.')))
        ) {
            try {
                $mentionedCsv = $this->csvContextResolver !== null
                    ? $this->csvContextResolver->findMentionedCsvFileName($message)
                    : null;
                if ($mentionedCsv !== null) {
                    $isAnalysisMode = true;
                    $preferNormalRag = false;
                    $appendUnique($selectorReasonCodes, 'selector_csv_filename_fallback');
                    $appendUnique($selectorEvidence, 'mentioned_csv_filename');
                    $selectedRouteConfidence = 'medium';
                    $this->log("[SMART-ROUTER] 登録済みCSVファイル名への言及を検知。CSV分析ルートへ切替: {$mentionedCsv}");
                }
            } catch (Throwable $csvRouteEx) {
                $this->log("[SMART-ROUTER] CSVファイル名ルーティング確認に失敗: " . $csvRouteEx->getMessage());
            }
        }

        if (empty($selectedRouteReasonCodes) && !empty($factorizedReasonCodes)) {
            $selectedRouteReasonCodes = $factorizedReasonCodes;
        }
        if (empty($selectedRouteEvidence) && !empty($factorizedEvidence)) {
            $selectedRouteEvidence = $factorizedEvidence;
        }

        foreach ($selectorReasonCodes as $selectorReasonCode) {
            $appendUnique($selectedRouteReasonCodes, $selectorReasonCode);
        }
        foreach ($selectorEvidence as $selectorEvidenceItem) {
            $appendUnique($selectedRouteEvidence, $selectorEvidenceItem);
        }

        if (empty($selectorReasonCodes)) {
            $appendUnique($selectorReasonCodes, 'selector_no_override');
        }
        if (empty($selectorEvidence)) {
            $appendUnique($selectorEvidence, 'selector_pass_through');
        }

        return [
            'advanced_reasoning' => $advancedReasoning,
            'is_analysis_mode' => $isAnalysisMode,
            'is_history_summary_mode' => $isHistorySummaryMode,
            'prefer_normal_rag' => $preferNormalRag,
            'explicit_advanced' => $explicitAdvanced,
            'route_detail_override' => $routeDetailOverride,
            'recent_artifact_state' => $recentArtifactState,
            'selected_route_confidence' => $selectedRouteConfidence,
            'selected_route_reason_codes' => array_slice($selectedRouteReasonCodes, 0, 8),
            'selected_route_evidence' => array_slice($selectedRouteEvidence, 0, 8),
            'selector_reason_codes' => array_slice($selectorReasonCodes, 0, 8),
            'selector_evidence' => array_slice($selectorEvidence, 0, 8),
        ];
    }

    private function shouldLockCsvAggregationRoute(
        string $message,
        string $factorizedRoute,
        array $factorizedQuery,
        ?array $recentArtifactState
    ): bool {
        if ($recentArtifactState === null) {
            return false;
        }

        if (($recentArtifactState['last_success_route'] ?? '') !== 'data_analysis.csv_agg') {
            return false;
        }

        if (!$this->csvContextResolver->isLikelyStateContinuationDirective($message)) {
            return false;
        }

        if ($factorizedRoute === 'history_summary' || $factorizedRoute === 'advanced_hybrid.history_report') {
            return false;
        }

        if (str_starts_with($factorizedRoute, 'advanced_hybrid.') && $factorizedRoute !== 'advanced_hybrid.history_report') {
            return false;
        }

        if (str_starts_with($factorizedRoute, 'normal_rag.')) {
            return false;
        }

        if ($this->isExplicitReportOrDocumentRequest($message)) {
            return false;
        }

        if ($this->isExplicitArtifactSwitch($message)) {
            return false;
        }

        $currentCsv = $this->csvContextResolver->findMentionedCsvFileName($message);
        $lockedCsv = (string)($recentArtifactState['target_file_name'] ?? '');
        if ($currentCsv !== null && $lockedCsv !== '' && $currentCsv !== $lockedCsv) {
            return false;
        }

        if (($factorizedQuery['target'] ?? null) === 'pdf' || ($factorizedQuery['target'] ?? null) === 'history') {
            return false;
        }

        return true;
    }

    private function isExplicitArtifactSwitch(string $message): bool
    {
        return preg_match('/(別の|他の|新しい|違う).*(CSV|csv|ファイル|資料|PDF|文書|列|カラム|項目)|((CSV|csv|ファイル|資料|PDF|文書|列|カラム|項目).*(別の|他の|新しい|違う))/u', $message) === 1;
    }

    private function isExplicitReportOrDocumentRequest(string $message): bool
    {
        return preg_match('/(報告書|レポート|PDF|資料|文書).*(作成|作って|出力|生成|まとめ)|((作成|作って|出力|生成|まとめ).*(報告書|レポート|PDF|資料|文書))/u', $message) === 1;
    }

    private function shouldPreferProjectMemoryConsultation(
        string $message,
        string $factorizedRoute,
        string $factorizedOperation
    ): bool {
        if ($factorizedRoute === 'advanced_hybrid.history_report' || $factorizedRoute === 'data_analysis.csv_agg' || $factorizedRoute === 'advanced_hybrid.doc_extract') {
            return false;
        }

        if ($this->hasStrongCsvAggregationIntent($message) || $this->hasStrongHistoryReportIntent($message) || $this->hasStrongDocExtractIntent($message)) {
            return false;
        }

        if ($factorizedRoute === 'normal_rag.project_memory_consultation') {
            return true;
        }

        return in_array($factorizedOperation, ['material_workflow', 'status_alignment'], true);
    }

    private function shouldUseReadOnlyIntentConsultationRoute(
        array $conversationIntentProfile,
        string $factorizedRoute,
        string $factorizedOperation,
        string $message,
        bool $reportMode,
        bool $explicitAdvanced
    ): bool {
        $requestType = trim((string)($conversationIntentProfile['request_type'] ?? ''));
        $relation = trim((string)($conversationIntentProfile['conversation_relation'] ?? ''));
        $todoPolicyHint = trim((string)($conversationIntentProfile['todo_policy_hint'] ?? ''));

        if ($requestType !== 'consultation' || $todoPolicyHint !== 'read_only') {
            return false;
        }

        if (!in_array($relation, ['new_request', 'status_check', 'rollback', 'correction'], true) && $relation !== '') {
            return false;
        }

        if ($reportMode || $explicitAdvanced) {
            return false;
        }

        if ($this->hasStrongCsvAggregationIntent($message) || $this->hasStrongHistoryReportIntent($message) || $this->hasStrongDocExtractIntent($message)) {
            return false;
        }

        if (in_array($factorizedRoute, ['data_analysis.csv_agg', 'data_analysis.csv_summary', 'data_analysis.csv_export_request', 'advanced_hybrid.history_report', 'advanced_hybrid.doc_extract'], true)) {
            return false;
        }

        if (str_starts_with($factorizedRoute, 'advanced_hybrid.') && $factorizedRoute !== 'advanced_hybrid.multi_source_advice') {
            return false;
        }

        if ($factorizedOperation === 'export_csv' || $factorizedOperation === 'report') {
            return false;
        }

        return true;
    }

    private function hasStrongCsvAggregationIntent(string $message): bool
    {
        return preg_match('/(集計|件数|グラフ|チャート|ランキング)/u', $message) === 1;
    }

    private function hasStrongHistoryReportIntent(string $message): bool
    {
        return preg_match('/(会話履歴|会話の履歴|これまでの会話|チャット履歴|報告書化|構成案)/u', $message) === 1;
    }

    private function hasStrongDocExtractIntent(string $message): bool
    {
        return preg_match('/(PDF|pdf|資料|文書).*(抽出|抜き出|引用|根拠|ページ)|((抽出|抜き出|引用|根拠|ページ).*(PDF|pdf|資料|文書))/u', $message) === 1;
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }
}
