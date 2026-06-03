<?php
/**
 * chat_normal.php - 一問一答型・通常RAGストリーミング処理ルート
 * (chat.php から安全に呼び出されるコントローラーファイルです)
 *
 * ★[品質評価（LLM-as-a-Judge）一元トランザクション保護 ＆ 13引数インターフェース拡張版]
 */

function runNormalStreamingRoute($pdo, $ollama_host, $projectId, $originalMessage, $searchQuery, $model, $promptKey, $projectContext, $historySummaryText, $vectorSearch, $engine, $user_id, $role, bool $reportMode = false, bool $diagramMode = false) {
    $processor = new NormalStreamingRouteProcessor(
        $pdo, $ollama_host, $projectId, $originalMessage, $searchQuery,
        $model, $promptKey, $projectContext, $historySummaryText,
        $vectorSearch, $engine, $user_id, $role, $reportMode, $diagramMode
    );
    $processor->execute();
}

class NormalStreamingRouteProcessor {
    private $pdo;
    private $ollama_host;
    private $projectId;
    private $originalMessage;
    private $searchQuery;
    private $model;
    private $promptKey;
    private $projectContext;
    private $historySummaryText;
    private $vectorSearch;
    private $engine;
    private $user_id;
    private $role;
    private $reportMode = false;
    private $diagramMode = false;
    private $reportDocument = null;

    private $targetPage = null;
    private $referAllMode = false;
    private $contextText = "";
    private $sourceDocs = [];
    private $fullResponse = "";
    private $evalResult = null;
    private $retryCount = 0;

    private $tokenCount = 0;
    private $lastLoggedLength = 0;
    private $buffer = "";
    private $ollamaErrorMsg = "";

    public function __construct($pdo, $ollama_host, $projectId, $originalMessage, $searchQuery, $model, $promptKey, $projectContext, $historySummaryText, $vectorSearch, $engine, $user_id, $role, bool $reportMode = false, bool $diagramMode = false) {
        $this->pdo                = $pdo;
        $this->ollama_host        = $ollama_host;
        $this->projectId          = $projectId;
        $this->originalMessage    = $this->normalizeUtf8((string)$originalMessage);
        $this->searchQuery        = $this->normalizeUtf8((string)$searchQuery);
        $this->model              = $model;
        $this->promptKey          = $promptKey;
        $this->projectContext     = $this->normalizeUtf8((string)$projectContext);
        $this->historySummaryText = $this->normalizeUtf8((string)$historySummaryText);
        $this->vectorSearch       = $vectorSearch;
        $this->engine             = $engine;
        $this->user_id            = $user_id;
        $this->role               = $role;
        $this->reportMode         = $reportMode;
        $this->diagramMode        = $diagramMode;
    }

    private function normalizeUtf8(string $text): string {
        if ($text === '') {
            return '';
        }

        if (!mb_check_encoding($text, 'UTF-8')) {
            $converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
            if ($converted !== false) {
                $text = $converted;
            } else {
                $text = mb_convert_encoding($text, 'UTF-8', 'UTF-8');
            }
        }

        $cleaned = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $text);
        return $cleaned !== null ? $cleaned : $text;
    }

    public function execute(): void {
        chatLogger(">>> [通常ルート] 通常ストリーミングルートを起動します");
        sendSSE('status', ['message' => '🔍 関連ドキュメントのベクトル類似度検索を実行しています...']);
        $this->parseQuery();
        $this->buildRagContext();
        sendSSE('status', ['message' => '⚙️ 関連資料の抽出が完了しました。回答の生成処理（推論）を開始します...']);
        if (!$this->streamOllamaGeneration()) {
            return;
        }

        $this->runQualityEvaluationIfNeeded();
        // 履歴永続化処理の一元トランザクション保護近代化へ委譲
        $this->saveHistoryAndEvaluations();
        $this->sendFinalResult();
    }

    private function runQualityEvaluationIfNeeded(): void {
        require_once __DIR__ . '/../../src/ChatEvaluationPolicy.php';
        $policy = ChatEvaluationPolicy::shouldEvaluateNormalRag(
            $this->originalMessage,
            $this->fullResponse,
            $this->contextText,
            count($this->sourceDocs),
            $this->reportMode,
            $this->diagramMode
        );

        if (($policy['evaluate'] ?? false) !== true) {
            chatLogger("[JUDGE-NORMAL-SKIP] 通常RAG品質評価をスキップしました。reason={$policy['reason']} | responseChars=" . mb_strlen($this->fullResponse) . " | contextChars=" . mb_strlen($this->contextText) . " | sources=" . count($this->sourceDocs));
            return;
        }

        try {
            require_once __DIR__ . '/../../src/ChatEvaluator.php';
            $evaluator = new ChatEvaluator($this->ollama_host);

            sendSSE('status', ['message' => '⚖️ 回答の品質確認を実行中...']);
            $contextForEval = trim((string)$this->contextText);
            if ($contextForEval === '') {
                $contextForEval = "通常RAG検索（中間ステップなし）";
            }
            chatLogger("[JUDGE-NORMAL-START] 通常RAG品質評価を開始します。reason={$policy['reason']} | responseChars=" . mb_strlen($this->fullResponse) . " | contextChars=" . mb_strlen($contextForEval) . " | sources=" . count($this->sourceDocs));
            $this->evalResult = $evaluator->evaluateDraft($this->originalMessage, $contextForEval, $this->fullResponse, $this->model);

            if (($this->evalResult['needs_revision'] ?? false) === true) {
                $verdict = $this->evalResult['verdict'] ?? 'revise_text_only';
                $feedback = $this->evalResult['feedback'] ?? '既存根拠に基づいて回答を修正してください。';
                $forbiddenActions = $this->evalResult['forbidden_actions'] ?? [];
                if (!is_array($forbiddenActions)) {
                    $forbiddenActions = [$forbiddenActions];
                }

                sendSSE('status', ['message' => '📝 品質確認の指摘を反映し、既存根拠だけで回答を整えています...']);
                $rewritten = $evaluator->reviseDraftTextOnly(
                    $this->originalMessage,
                    $contextForEval,
                    $this->fullResponse,
                    $feedback,
                    $this->model,
                    $forbiddenActions
                );

                if (!empty($rewritten)) {
                    $this->fullResponse = $rewritten;
                    $this->evalResult['needs_revision'] = false;
                    $this->evalResult['feedback'] = $feedback . "\n[TEXT-ONLY-REWRITE] 通常RAGルートでは追加検索を行わず、既存根拠のみで最終回答を修正しました。";
                    chatLogger("[JUDGE-NORMAL-REWRITE] verdict={$verdict} のため通常RAG回答を文章修正しました。");
                }
            }

            chatLogger("[JUDGE-NORMAL-EVAL] 通常RAG回答品質管理審査結果マトリクス:\n" . print_r($this->evalResult, true));
            chatLogger("[DEBUG] ChatEvaluator による通常RAG最終回答品質審査が正常開通しました。");
        } catch (Exception $evalEx) {
            chatLogger("品質評価エージェントキック中に例外検出(スキップ保護): " . $evalEx->getMessage());
        }
    }

    private function parseQuery(): void {
        if (preg_match('/([0-9]+)\s*ページ/u', $this->searchQuery, $matches)) {
            $this->targetPage = (int)$matches[1];
        } elseif (preg_match('/(?:p(?:age)?\.?\s*)\s*([0-9]+)/i', $this->searchQuery, $matches)) {
            $this->targetPage = (int)$matches[1];
        }
        if ($this->targetPage === null && preg_match('/(全体|すべての資料|全資料|すべてのページ|全ページ|要約|まとめ|比較|一覧|鳥瞰|全体マップ|総合)/u', $this->searchQuery)) {
            $this->referAllMode = true;
        }
    }

    private function buildRagContext(): void {
        if ($this->projectId === null) {
            return;
        }
        try {
            $qEmb = $this->engine->embed(mb_substr($this->searchQuery, 0, 500));
            $all_hits = [];

            if ($this->referAllMode) {
                $stmtSummary = $this->pdo->prepare("SELECT c.id, c.doc_id, d.title, c.chunk_text, c.page_number, c.image_description FROM doc_chunks c JOIN documents d ON c.doc_id = d.id WHERE d.project_id = ? AND c.page_number = 0");
                $stmtSummary->execute([$this->projectId]);
                $summaries = $stmtSummary->fetchAll(PDO::FETCH_ASSOC);

                foreach ($summaries as $sum) {
                    $all_hits[] = [
                        'document_id' => $sum['doc_id'], 
                        'title' => $sum['title'], 
                        'content' => $sum['chunk_text'], 
                        'page_number' => 0, 
                        'image_description' => $sum['image_description'], 
                        'score' => 1.0
                    ];
                }
                $semanticHits = $this->vectorSearch->search($qEmb, $this->projectId, 12, null, $this->searchQuery);
                foreach ($semanticHits as $sHit) {
                    if ($sHit['page_number'] == 0 || $sHit['page_number'] === '0') continue;
                    $all_hits[] = $sHit;
                }
            } else {
                $all_hits = $this->vectorSearch->search($qEmb, $this->projectId, 9999, $this->targetPage, $this->searchQuery);
            }

            $pdf_hits = [];
            $csv_hits_by_doc = [];

            foreach ($all_hits as $hit) {
                $imageDescription = (string)($hit['image_description'] ?? '');
                $is_csv = mb_strpos($imageDescription, 'CSVデータ行レコード') === 0;
                if ($is_csv) {
                    if ($hit['score'] >= 0.50) { 
                        $csv_hits_by_doc[$hit['document_id']][] = $hit;
                    }
                } else {
                    if ($hit['score'] >= 0.35) { 
                        $pdf_hits[] = $hit;
                    }
                }
            }

            $pdf_hits = array_slice($pdf_hits, 0, 6);
            chatLogger("トリアージ完了。PDF適合チャンク(score>=0.35): " . count($pdf_hits) . "件 | 適合CSVファイル(score>=0.50): " . count($csv_hits_by_doc) . "件");

            foreach ($pdf_hits as $hit) {
                $pNum = $hit['page_number'];
                $label = ($pNum == 0) ? "【資料全体の構成・要約（目次情報）】" : "【参考資料: {$hit['title']} P.{$pNum}】";
                $this->contextText .= "{$label}\n[本文テキスト]:\n{$hit['content']}\n";
                if (!empty($hit['image_description'])) {
                    $this->contextText .= "[このページに含まれる画像/図表の説明]:\n{$hit['image_description']}\n";
                }
                $this->contextText .= "\n";
                $this->sourceDocs[] = ["title" => $hit['title'], "page" => $pNum, "doc_id" => $hit['document_id']];
            }

            foreach ($csv_hits_by_doc as $doc_id => $c_hits) {
                if (empty($c_hits)) continue;
                $fileName = str_replace('[CSVデータ] ', '', $c_hits[0]['title']);
                chatLogger("  CSVバルクアグリゲーション実行: {$fileName} (合致数: " . count($c_hits) . "行)");
                $tableMarkdown = aggregateCsvChunksToMarkdown($c_hits, $fileName);
                $this->contextText .= "【構造化データテーブル: {$fileName}】\n{$tableMarkdown}\n\n";
                $this->sourceDocs[] = ["title" => "[CSVデータ] " . $fileName, "page" => 1, "doc_id" => $doc_id];
            }

            $max_pdf_ctx_length = 4000;
            if (mb_strlen($this->contextText) > $max_pdf_ctx_length) {
                $truncated_pdf_length = mb_strlen($this->contextText) - $max_pdf_ctx_length;
                $this->contextText = mb_substr($this->contextText, 0, $max_pdf_ctx_length) . "\n\n...[⚠️システム安全セーフガード：Token制限保護のため、以降のデータは省略されました。解決には詳細な指定を添えてください]";
                chatLogger("[CONTEXT-GUARD] コンテキスト合計文字数が限界を超過したため、後半の {$truncated_pdf_length} 文字を自動的に切り詰めました。");
            }

            // ⭕ 構築とセーフガードが完全に完了した真実の文字数を完璧にダンプ！
            chatLogger("[RAG-CONTEXT-TOTAL] 最終構築されたコンテキスト総文字数: " . mb_strlen($this->contextText) . "文字");

        } catch (Exception $e) {
            chatLogger("RAG検索例外: " . $e->getMessage());
            $this->contextText = "";
        }
    }

    private function buildSystemPrompt(): string {
        $system_prompt = PromptManager::getBasePrompt($this->promptKey) . "\n" 
                       . PromptManager::getCommonInstructions() . "\n" 
                       . PromptManager::getDashboardLinkInstruction($this->projectId ?? 0);
        if ($this->targetPage !== null) {
            $system_prompt .= "\n【超重要】ユーザーは明示的に「{$this->targetPage}ページ」を指定して質問しています。必ず提供された参考資料のうち「P.{$this->targetPage}」と書かれたブロックの情報のみを根拠として回答してください。関係のない他のページの情報を混ぜて答えてはなりません。";
        } elseif ($this->referAllMode) {
            $system_prompt .= "\n【超重要・マクロ分析ルール】ユーザーは資料全体の横断的な要約・総括を求めています。\n1. 各資料 of 全体マップ（目次情報）を骨格にしてください。\n2. 各ページの詳細情報（P.1〜）を肉付けして全体を解説してください。\n3. すべての登録済み資料に平等に言及し大局的な知見を提示してください。";
        }
        if ($this->diagramMode) {
            $system_prompt .= "\n【図解モード】説明の理解に役立つ場合のみ、Mermaidコードブロック（```mermaid）またはChart.js用JSONコードブロック（```json:chart）を1つまで添えてください。図表が不要な場合は文章のみで構いません。";
        }
        if ($this->reportMode) {
            $system_prompt .= "\n【報告書モード】回答は後続処理でPDF報告書化されます。結論、分析対象、根拠、留意点、推奨アクション、出典の順に、報告書として読みやすい見出し構成で作成してください。";
        }
        return $system_prompt;
    }

    private function streamOllamaGeneration(): bool {
        $system_prompt = $this->buildSystemPrompt();
        $dialogue_context_prompt = !empty($this->historySummaryText) ? "【これまでの会話の文脈】\n{$this->historySummaryText}\n\n" : "";
        $prompt_user = $this->projectContext . "\n"
                     . $dialogue_context_prompt
                     . "【参考資料情報】\n" . (!empty($this->contextText) ? $this->contextText : "（指定された資料データは見つかりませんでした）") . "\n"
                     . "質問：" . $this->originalMessage;

        chatLogger("Ollama接続開始。モデル: {$this->model} | プロンプト総文字数: " . mb_strlen($prompt_user) . "文字");

        // 📢 【推論プロンプト送信フェーズ】合体プロンプトの完全ダンプ
        chatLogger("[OLLAMA-RAW-PROMPT] Ollamaへ最終投入される生の合体ユーザープロンプト:\n" . $prompt_user);

        $ch = curl_init("{$this->ollama_host}/api/generate");

        // cURL内部スレッド実行時のオブジェクトコンテキスト消失を防ぐため、インスタンス参照をローカル変数へキャプチャ
        $me = $this;

        // 🔄 通常ストリーミングルートの cURL コールバック関数（$me コンテキスト完全維持・ネジ締め版）
        $writeCallback = function($ch, $data) use ($me) {
            $me->buffer .= $data;
            $lines = explode("\n", $me->buffer);
            $me->buffer = array_pop($lines);

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) continue;

                $json = json_decode($line, true);
                if ($json) {
                    if (isset($json['error'])) {
                        $me->ollamaErrorMsg = $json['error'];
                        return 0;
                    }
                    $word = $json['response'] ?? $json['message']['content'] ?? '';
                    $me->fullResponse .= $word;
                    $me->tokenCount++;
                    
                    // 🔥【UXガラス張り化】裏で生成されている1文字ずつのトークンを、せき止めずにリアルタイムでフロントの『実況コンソール』へ横流し射撃する！
                    sendSSE('chunk', [
                        'text' => $word, 
                        'word' => $word
                    ]);
                }
            }
            $current_len = mb_strlen($me->fullResponse);
            if ($current_len - $me->lastLoggedLength >= 50) {
                chatLogger("  [推論進行中] 累積文字数: {$current_len}文字 | 受信チャンク数: {$me->tokenCount}回");
                $me->lastLoggedLength = $current_len;
            }
            return strlen($data);
        };

        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode([
            'model' => $this->model,
            'prompt' => "<system>{$system_prompt}</system>\n\n{$prompt_user}\n\n回答（日本語で詳細に）:",
            'stream' => true,
            // 🛠️【通常RAGストリーミング】限界引き締めオプション配列書き換え ＆ 8192文脈拡張（超決定論・拡張仕様化）
            'options' => [
                'temperature' => 0.0,
                'top_p' => 0.1,
                'num_ctx' => 8192
            ]
        ]));
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, $writeCallback);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 15);
        curl_setopt($ch, CURLOPT_TIMEOUT, 300);
        
        $success    = curl_exec($ch);
        $curl_error = curl_error($ch);
        $http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if (!empty($this->ollamaErrorMsg)) {
            chatLogger("CRITICAL: Ollama内部システムエラーを検知しました: {$this->ollamaErrorMsg}");
            sendSSE('error', ['status' => 'error', 'error' => "⚠️ Ollama AIサーバーエラー: {$this->ollamaErrorMsg}"]);
            return false;
        }
        if (!$success) {
            chatLogger("CRITICAL: Ollama推論ストリーム通信失敗 (cURL Error: {$curl_error})");
            sendSSE('error', ['status' => 'error', 'error' => 'AIサーバーとのストリーミング通信に失敗しました: ' . $curl_error]);
            return false;
        }
        if ($http_code !== 200) {
            chatLogger("CRITICAL: AIサーバーがエラーコード {$http_code} を返しました。");
            sendSSE('error', ['status' => 'error', 'error' => "⚠️ AIサーバー通信エラー (HTTPステータス: {$http_code})"]);
            return false;
        }
        $this->fullResponse = trim($this->fullResponse);
        if (empty($this->fullResponse)) {
            $this->fullResponse = "⚠️ **[システム安全ガードレールによる技術案内]**\n\n大変申し訳ありません。検索に合致したデータ量が多すぎるか、AIサーバーが一時的に極めて高負荷なため、処理能力限界（Token Limit）を超過し回答を構成できませんでした。";
        }
        return true;
    }

    /**
     * 【修正要件2】履歴永続化処理の一元トランザクション保護 ＆ スコアキー・物理カラム不整合の解消
     */
    private function saveHistoryAndEvaluations(): void {
        if ($this->projectId === null) {
            return;
        }
        sendSSE('status', ['message' => '💾 回答生成が完了しました。会話履歴と評価結果を保存しています...']);
        chatLogger("[DEBUG] DBトランザクションを開始し、対話ログ・評価スコアを一元コミットします...");
        try {
            // 全書き込み処理を完璧な単一トランザクションスコープへ完全格納
            $this->pdo->beginTransaction();

            // 1. ユーザー履歴保存
            $stmtUser = $this->pdo->prepare("INSERT INTO chat_history (project_id, user_id, role, message, created_at) VALUES (?, ?, 'user', ?, NOW())");
            $stmtUser->execute([$this->projectId, $this->user_id, $this->normalizeUtf8($this->originalMessage)]);

            // 2. AI履歴保存
            $stmtAi = $this->pdo->prepare("INSERT INTO chat_history (project_id, user_id, role, message, created_at) VALUES (?, ?, 'assistant', ?, NOW())");
            $stmtAi->execute([$this->projectId, $this->user_id, $this->normalizeUtf8($this->fullResponse)]);
            $historyId = $this->pdo->lastInsertId();
            chatLogger("[DEBUG] chat_history 登録成功。ID: {$historyId}");

            // 3. 品質評価スコア（LLM-as-a-Judge）の保存（マッピング不整合を100%解消してバインド）
            if (isset($this->evalResult) && $this->evalResult) {
                // 返却JSONキー「answer_relevance」を実際のDB物理カラム名「relevance_score」へ完璧にアライン・バインド
                $stmtEval = $this->pdo->prepare("
                    INSERT INTO chat_evaluations 
                    (chat_id, proactivity_score, faithfulness_score, relevance_score, clarity_score, total_score, feedback, retry_count) 
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmtEval->execute([
                    $historyId,
                    $this->evalResult['scores']['proactivity'] ?? 0,
                    $this->evalResult['scores']['faithfulness'] ?? 0,
                    $this->evalResult['scores']['answer_relevance'] ?? 0, // ★JSONキー「answer_relevance」から等価抽出して :relevance_score 側へバインド
                    $this->evalResult['scores']['clarity'] ?? 0,
                    $this->evalResult['total_score'] ?? 0,
                    $this->normalizeUtf8((string)($this->evalResult['feedback'] ?? '')),
                    $this->retryCount ?? 0
                ]);
                chatLogger("[DEBUG] chat_evaluations へ通常RAG品質審査スコアを正常に登録・同期しました。");
            }

            require_once __DIR__ . '/../../src/FaqAutoRegistrar.php';
            sendSSE('status', ['message' => '📚 高評価回答のFAQ自動登録条件を確認しています...']);
            $faqRegistrar = new FaqAutoRegistrar($this->pdo);
            $faqRegistrar->registerIfQualified(
                $this->projectId,
                (int)$historyId,
                (int)$this->user_id,
                $this->originalMessage,
                $this->fullResponse,
                $this->evalResult
            );

            // すべてのインサートが完全に成功したため一括コミットを執行
            $this->pdo->commit();
            chatLogger("[DEBUG] DBトランザクションコミット成功。通常RAGのデータ整合性を完全保護しました。");
            $this->createReportDocumentIfRequested((int)$historyId);
        } catch (Exception $e) {
            // 障害発生時は一斉ロールバックを執行
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
                chatLogger("[WARN] DBトランザクション内で例外エラーを検知したため、一斉ロールバックを執行しました。");
            }
            chatLogger("DB履歴・評価保存例外: " . $e->getMessage());
        }
    }

    private function createReportDocumentIfRequested(int $historyId): void {
        if (!$this->reportMode || $this->projectId === null) {
            return;
        }
        if (($this->evalResult['verdict'] ?? '') === 'reject') {
            chatLogger('[REPORT] 品質評価がrejectのため、報告書PDF生成をスキップしました。chat_history_id=' . $historyId);
            sendSSE('status', ['message' => '⚠️ 回答が報告書として成立しない判定のため、PDF生成はスキップしました。']);
            return;
        }
        try {
            require_once __DIR__ . '/../../src/ReportGenerator.php';
            sendSSE('status', ['message' => '📄 報告書モード: HTML/CSS報告書をPDF化し、資料PDFへ登録しています...']);
            $generator = new ReportGenerator(
                $this->pdo,
                realpath(__DIR__ . '/../../') ?: (__DIR__ . '/../..'),
                $this->ollama_host,
                function ($msg) { chatLogger($msg); }
            );
            $this->reportDocument = $generator->createFromChat(
                (int)$this->projectId,
                $historyId,
                (int)$this->user_id,
                $this->originalMessage,
                $this->fullResponse,
                $this->evalResult,
                null
            );
            sendSSE('status', ['message' => '✅ 報告書PDFをPDFタブへ登録し、検索対象化しました。']);
        } catch (Throwable $e) {
            chatLogger('[REPORT] 報告書PDF登録に失敗: ' . $e->getMessage());
            sendSSE('status', ['message' => '⚠️ 報告書PDFの登録に失敗しました。管理者ログを確認してください。']);
        }
    }

    private function sendFinalResult(): void {
        $fence = str_repeat("\x60", 3);
        sendSSE('result', [
            'status'          => 'success', 
            'response'        => $this->fullResponse, 
            'sources'         => $this->sourceDocs,
            'mode_used'       => $this->promptKey,
            'detected_page'   => $this->targetPage,
            'hit_count'       => count($this->sourceDocs),
            'reasoning_steps' => [],
            'applied_model'   => $this->model,
            'created_at'      => date('Y/m/d H:i'),
            'report_document' => $this->reportDocument
        ]);
        chatLogger("=== 通常RAGストリーミングパイプライン完了 ===");
    }
}

/**
 * CSV自然言語チャンクの Markdown テーブル自動再集約（VRAM保護版）
 */
if (!function_exists('aggregateCsvChunksToMarkdown')) {
    function aggregateCsvChunksToMarkdown(array $csvChunks, string $fileName): string {
        if (empty($csvChunks)) return "";
        
        $rows = [];
        $all_headers = ['行番号'];
        $current_text_length = 0;
        $max_guard_length = 4000; 
        $truncated_count = 0;
        
        foreach ($csvChunks as $chunk) {
            $text = $chunk['content'] ?? $chunk['chunk_text'] ?? '';
            if (empty($text)) continue;

            $temp_len = mb_strlen($text);
            if (($current_text_length + $temp_len) > $max_guard_length) {
                $truncated_count++;
                continue;
            }

            $cleaned = preg_replace('/^CSV「[^」]+」の第(\d+)行のデータ：/', '', $text);
            $cleaned = preg_replace('/amp;です。$/', '', $cleaned); 
            $cleaned = preg_replace('/です。$/', '', $cleaned);
            
            $row_data_match = [];
            preg_match('/第(\d+)行/', $text, $row_data_match);
            $row_idx = $row_data_match[1] ?? '?';
            
            $row_data = ['行番号' => $row_idx];
            
            $parts = explode('、', $cleaned);
            foreach ($parts as $part) {
                if (preg_match('/^(.+?)は「(.*?)」$/', trim($part), $m)) {
                    $col = trim($m[1]);
                    $val = trim($m[2]);
                    $row_data[$col] = $val;
                    if (!in_array($col, $all_headers)) {
                        $all_headers[] = $col;
                    }
                }
            }
            $rows[] = $row_data;
            $current_text_length += $temp_len;
        }
        
        if (empty($rows)) return "";
        
        $md = "以下の表は、類似検索に合致した「{$fileName}」のデータ行レコード一覧です。\n\n";
        $md .= "| " . implode(" | ", $all_headers) . " |\n";
        $md .= "| " . implode(" | ", array_map(function() { return ":---"; }, $all_headers)) . " |\n";
        
        foreach ($rows as $row) {
            $cols = [];
            foreach ($all_headers as $h) {
                $cell_val = $row[$h] ?? '';
                if (mb_strlen($cell_val) > 50) {
                    $cell_val = mb_substr($cell_val, 0, 50) . '...';
                }
                $cols[] = $cell_val;
            }
            $md .= "| " . implode(" | ", $cols) . " |\n";
        }

        if ($truncated_count > 0) {
            $md .= "\n*（※他、類似スコアの低い {$truncated_count} 件のデータは、AIメモリ保護のため省略されました）*\n";
            $md .= "\n";
            chatLogger("[CONTEXT-GUARD] CSVデータ結合を {$current_text_length} 文字で制限。{$truncated_count} 件を省略。");
        }
        
        return $md;
    }
}
