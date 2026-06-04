<?php

require_once __DIR__ . '/ChatEvaluator.php';

final class AdvancedCriticLoop
{
    private $pdo;
    private $projectId;
    private $ollamaHost;
    private $originalMessage;
    private $mainModel;
    private $synthesisModel;
    private $reportMode;
    private $diagramMode;
    private $generateAdditionalChunkQuery;
    private $applyReportModeFinalPolish;
    private $getSubAnswers;
    private $appendSubAnswer;
    private $registerAdditionalSource;
    private $logger;

    public function __construct(
        PDO $pdo,
        int $projectId,
        string $ollamaHost,
        string $originalMessage,
        string $mainModel,
        string $synthesisModel,
        bool $reportMode,
        bool $diagramMode,
        callable $generateAdditionalChunkQuery,
        callable $applyReportModeFinalPolish,
        callable $getSubAnswers,
        callable $appendSubAnswer,
        callable $registerAdditionalSource,
        ?callable $logger = null
    ) {
        $this->pdo = $pdo;
        $this->projectId = $projectId;
        $this->ollamaHost = $ollamaHost;
        $this->originalMessage = $originalMessage;
        $this->mainModel = $mainModel;
        $this->synthesisModel = $synthesisModel;
        $this->reportMode = $reportMode;
        $this->diagramMode = $diagramMode;
        $this->generateAdditionalChunkQuery = $generateAdditionalChunkQuery;
        $this->applyReportModeFinalPolish = $applyReportModeFinalPolish;
        $this->getSubAnswers = $getSubAnswers;
        $this->appendSubAnswer = $appendSubAnswer;
        $this->registerAdditionalSource = $registerAdditionalSource;
        $this->logger = $logger;
    }

    public function run(string $currentDraft, string $baseSystemPrompt, string $chartInstruction, int $maxEvalRetries): array
    {
        $retryCount = 0;
        $evalResult = null;
        $this->log("[EVAL-POLICY] maxEvalRetries={$maxEvalRetries} | report_mode=" . ($this->reportMode ? 'on' : 'off') . " | diagram_mode=" . ($this->diagramMode ? 'on' : 'off'));
        $evaluator = new ChatEvaluator($this->ollamaHost);

        while ($retryCount < $maxEvalRetries) {
            $mergedReasoningText = $this->buildMergedReasoningText(4000);

            sendSSE('status', [
                'step' => 4,
                'message' => "⚖️ レポートの最終品質監査（LLM-as-a-Judge）を実行中..." . ($retryCount > 0 ? " [反省周回: {$retryCount}/{$maxEvalRetries}]" : "")
            ]);

            $evalResult = $evaluator->evaluateDraft($this->originalMessage, $mergedReasoningText, $currentDraft, $this->mainModel);
            $evaluationMode = (string)($evalResult['evaluation_mode'] ?? 'unknown');
            $evaluationSource = (string)($evalResult['evaluation_source'] ?? 'unknown');
            $verdict = (string)($evalResult['verdict'] ?? 'unknown');
            $score = (int)($evalResult['total_score'] ?? 0);
            $relevance = (int)($evalResult['scores']['answer_relevance'] ?? 0);
            $faithfulness = (int)($evalResult['scores']['faithfulness'] ?? 0);
            $this->log("[DEBUG] ChatEvaluator 品質審査完了。");
            $this->log("[EVAL-" . strtoupper($evaluationMode) . "] source={$evaluationSource} | verdict={$verdict} | score={$score} | relevance={$relevance} | faithfulness={$faithfulness}");

            if (($evalResult['needs_revision'] ?? false) !== true) {
                $this->log("[CRITIC-PASS] 門番のスパルタ審査を105%完全クリアしました（総反省周回: {$retryCount}回）。");
                break;
            }

            $retryCount++;
            $feedback = (string)($evalResult['feedback'] ?? '要求要件の網羅性が不足しています。');
            $verdict = (string)($evalResult['verdict'] ?? 'need_more_data');
            $this->log("[CRITIC-NG] 門番による差し戻し執行。verdict={$verdict} | 作戦指示: {$feedback}");

            if (in_array($verdict, ['revise_text_only', 'reject'], true)) {
                sendSSE('status', [
                    'step' => 4,
                    'message' => "📝 追加再探索は行わず、既存根拠だけで回答文を修正しています... [反省周回: {$retryCount}/{$maxEvalRetries}]"
                ]);

                $forbiddenActions = $evalResult['forbidden_actions'] ?? [];
                if (!is_array($forbiddenActions)) {
                    $forbiddenActions = [$forbiddenActions];
                }

                $rewritten = $evaluator->reviseDraftTextOnly(
                    $this->originalMessage,
                    $mergedReasoningText,
                    $currentDraft,
                    $feedback,
                    $this->synthesisModel,
                    $forbiddenActions
                );

                if (!empty($rewritten)) {
                    $currentDraft = trim((string)$rewritten);
                    $evalResult['needs_revision'] = false;
                    $evalResult['feedback'] = $feedback . "\n[TEXT-ONLY-REWRITE] 既存根拠のみで最終回答を修正しました。";
                    $this->log("[CRITIC-TEXT-ONLY] verdict={$verdict} のためdoc_chunks追加探索を行わず最終回答を文章修正しました。");
                    break;
                }
            }

            sendSSE('status', [
                'step' => 4,
                'message' => "🔄 網羅性エラーを検知。資料（doc_chunks）へ巻き戻り追加再探索中... [試行: {$retryCount}/{$maxEvalRetries}]"
            ]);

            $additionalKeyword = (string)call_user_func($this->generateAdditionalChunkQuery, $feedback);
            $this->log("[RE-SEARCH-QUERY] 巻き戻り抽出キーワード: {$additionalKeyword}");

            $extractedChunkText = $this->searchAdditionalChunks($additionalKeyword);
            call_user_func(
                $this->appendSubAnswer,
                "◆ 巻き戻り反省巡回 [周回 {$retryCount}] (検索キー: {$additionalKeyword})\n" . $extractedChunkText
            );

            sendSSE('status', [
                'step' => 4,
                'message' => "🥞 【State-Saving】既出の真実をコンテキストに固定し、ドラフトを重ね書き精錬中..."
            ]);

            $shortReasoningHistory = mb_strlen($mergedReasoningText) > 2000 ? mb_substr($mergedReasoningText, -2000) : $mergedReasoningText;
            $shortDraft = mb_strlen($currentDraft) > 1500 ? mb_substr($currentDraft, 0, 1500) . "\n...[以降割愛]" : $currentDraft;

            $refineSystemPrompt = "【回答レポートの骨格（State-Saving）】\n" . $shortDraft . "\n\n"
                . "【直近の集計・データ考察の歴史】\n" . $shortReasoningHistory . "\n"
                . "--------------------------------------------------\n"
                . "お前は一歩も脱線を許されない丁寧なデータアナリストアシスタントAIである。品質審査責任者からの以下の【絶対修正命令】、およびデータベースから新たに引き抜いた【追加の資料本文断片】を熟読せよ。\n\n"
                . "【品質審査責任者からの絶対修正命令】\n" . $feedback . "\n\n"
                . "【新着の追加資料本文断片】\n" . $extractedChunkText . "\n\n"
                . "【課せられた絶対ルール】\n"
                . "既存のドラフトに記載されている重要な事実や検証結果、グラフ構造を決して破壊したり忘却して消去したりせず、新着の資料エビデンスを論理的にマージ（歴史を重ね書き）して、ドラフトを完璧に精錬・アップデートせよ。";

            $refineSystemPrompt = $baseSystemPrompt . "\n" . $refineSystemPrompt . $chartInstruction;
            if ($this->diagramMode) {
                $refineSystemPrompt .= "\n\n図解モードが有効です。必要な場合のみ、最後にChart.js仕様のJSONブロックを1つ含めてください。不要なら文章のみで構いません。";
            }

            $refineUserPrompt = "【最初の要求質問】\n{$this->originalMessage}\n\n"
                . "これまでの真実の歴史に新着エビデンスをマージし、指示を105%クリアした最新の回答レポート（マークダウン形式）を出力してください。";

            $refinedResponse = callOllamaChat(
                $this->ollamaHost,
                $this->synthesisModel,
                $refineSystemPrompt,
                $refineUserPrompt,
                null,
                ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 4096]
            );

            if (!empty($refinedResponse)) {
                $currentDraft = (string)$refinedResponse;
                $this->log("[REFINE-SUCCESS] ハイブリッド歴史の重ね書きアップデート成功 [周回: {$retryCount}]");
            }
        }

        if (($evalResult['needs_revision'] ?? false) === true) {
            $feedback = (string)($evalResult['feedback'] ?? '要求要件の網羅性が不足しています。');
            $this->log("[CRITIC-FINAL-REWRITE] 最大反省周回後も未合格のため、最新フィードバックを直接注入して最終リライトを実行します。");
            sendSSE('status', [
                'step' => 5,
                'message' => '🛠️ 品質監査の最終指摘を反映し、確定回答を再構成しています...'
            ]);

            $mergedReasoningText = $this->buildMergedReasoningText(6000);
            $forceSystemPrompt = $baseSystemPrompt . "\n"
                . "あなたは最終回答の品質保証リライト担当です。以下の品質監査フィードバックを必ず反映し、ユーザーの質問に直接答える最終版のみを出力してください。\n"
                . "拒否応答、質問未提示という誤認、監査手順の説明、内部ログの引用は禁止です。";
            $forceUserPrompt = "【ユーザーの質問】\n{$this->originalMessage}\n\n"
                . "【利用可能な根拠・中間考察】\n{$mergedReasoningText}\n\n"
                . "【現在のドラフト】\n{$currentDraft}\n\n"
                . "【品質監査フィードバック】\n{$feedback}\n\n"
                . "上記を反映し、ユーザーへ提示する最終回答だけを日本語Markdownで出力してください。";

            $forcedResponse = callOllamaChat(
                $this->ollamaHost,
                $this->synthesisModel,
                $forceSystemPrompt,
                $forceUserPrompt,
                null,
                ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 8192]
            );

            if (!empty($forcedResponse)) {
                $currentDraft = trim((string)$forcedResponse);
                $evalResult['needs_revision'] = false;
                $evalResult['feedback'] = '最大反省周回後、最新の品質監査フィードバックを直接反映して最終リライトしました。';
            }
        }

        if ($this->reportMode) {
            $this->log("[REPORT-POLISH] 報告書モード向けの最終整形を実行します。");
            sendSSE('status', [
                'step' => 6,
                'message' => '📄 報告書として読みやすい構成へ最終整形しています...'
            ]);
            $currentDraft = (string)call_user_func($this->applyReportModeFinalPolish, $currentDraft);
        }

        return [
            'draft' => $currentDraft,
            'eval_result' => $evalResult,
            'retry_count' => $retryCount,
        ];
    }

    private function buildMergedReasoningText(int $limit): string
    {
        $subAnswers = call_user_func($this->getSubAnswers);
        $mergedReasoningText = implode("\n\n", is_array($subAnswers) ? $subAnswers : []);
        if (mb_strlen($mergedReasoningText) > $limit) {
            $mergedReasoningText = mb_substr($mergedReasoningText, 0, $limit) . "\n...[制限超過による省略]";
        }

        return $mergedReasoningText;
    }

    private function searchAdditionalChunks(string $additionalKeyword): string
    {
        $stmtChunks = $this->pdo->prepare("
            SELECT id, doc_id, page_number, chunk_text
            FROM doc_chunks
            WHERE doc_id IN (SELECT id FROM documents WHERE project_id = ?)
              AND (chunk_text LIKE ? OR image_description LIKE ?)
            ORDER BY id ASC
            LIMIT 3
        ");
        $likeParam = '%' . $additionalKeyword . '%';
        $stmtChunks->execute([$this->projectId, $likeParam, $likeParam]);
        $newChunks = $stmtChunks->fetchAll(PDO::FETCH_ASSOC);

        if (empty($newChunks)) {
            return "（追加キーワードに部分一致する新たな資料チャンクは発見されませんでした）";
        }

        $extractedChunkText = '';
        foreach ($newChunks as $chunk) {
            $extractedChunkText .= "■ 資料ID: {$chunk['doc_id']} (P.{$chunk['page_number']}) 本文断片:\n{$chunk['chunk_text']}\n\n";
            call_user_func(
                $this->registerAdditionalSource,
                [
                    'title' => '追加反省抽出エビデンス',
                    'page' => $chunk['page_number'],
                    'doc_id' => $chunk['doc_id'],
                ]
            );
        }

        return $extractedChunkText;
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }
}
