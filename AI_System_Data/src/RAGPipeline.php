<?php
require_once __DIR__ . '/EmbeddingEngine.php';
require_once __DIR__ . '/VectorSearch.php';

/**
 * RAG (Retrieval-Augmented Generation) パイプライン
 * 検索結果に「Page 0 (全体マップ)」が含まれる場合、それを目次として扱い
 * 資料全体の構成を把握した回答を生成できるように制御します。
 * ★[改善] ユーザー設定からのAIサーバーURL・モデル動的取得機能、および num_gpu=999 強制オフロードを実装
 */
class RAGPipeline {
    private $embeddingEngine;
    private $vectorSearch;
    private $ollamaHost;
    private $chatModel;

    /**
     * コンストラクタ
     * 引数でホストやモデルが明示的に渡されない場合は、ユーザーのセッション（設定）から動的に取得します。
     */
    public function __construct($pdo, ?string $ollamaHost = null, ?string $chatModel = null) {
        // セッションが開始されていない場合は安全に開始して設定を取得
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
        
        // 引数がなければセッションから、セッションにもなければデフォルト値を使用
        $this->ollamaHost = rtrim($ollamaHost ?? $_SESSION['ollama_host'] ?? 'http://127.0.0.1:11434', '/');
        $this->chatModel  = $chatModel ?? $_SESSION['default_model'] ?? 'gemma4:e4b';
        
        // セッションロックの解放
        session_write_close();

        // 動的に取得したホストURLをEmbeddingEngineに渡す
        $this->embeddingEngine = new EmbeddingEngine($this->ollamaHost);
        $this->vectorSearch = new VectorSearch($pdo);
    }

    /**
     * 質問に対して資料から回答を生成する
     * @param string $question ユーザーの質問
     * @param int $projectId 検索対象のプロジェクトID
     * @return array ['answer' => 回答本文, 'sources' => 参照元リスト]
     */
    public function generateAnswer(string $question, int $projectId): array {
        // ① 質問をベクトル化
        $qEmb = $this->embeddingEngine->embed(mb_substr($question, 0, 500));

        // ② ベクトル検索で関連する「ページ」を抽出 (上位 5〜6件)
        // ヒット数を少し増やすことで、全体マップが含まれる確率を高めます
        $hits = $this->vectorSearch->search($qEmb, $projectId, 6);

        // ③ コンテキスト（参考資料）の構築
        $context = "";
        $sourceDocs = [];
        foreach ($hits as $hit) {
            $pNum = $hit['page_number'];
            
            // Page 0 を特別なラベルでAIに提示する
            if ($pNum == 0 || $pNum === '0') {
                $label = "【資料全体の構成・要約（目次情報）】";
            } else {
                $label = "【参考資料: {$hit['title']} (P.{$pNum})】";
            }

            $context .= "{$label}\n内容: " . $hit['content'] . "\n\n";
            
            $sourceDocs[] = [
                'title' => $hit['title'],
                'page' => $pNum,
                'doc_id' => $hit['document_id']
            ];
        }

        // ④ AIへの指示（システムプロンプト）
        // 「全体構成を把握せよ」という指示を明確に追加します
        $instructions = "あなたは建設コンサルタントの実務を支援する専門AIです。\n"
                      . "1. 提供された資料の中に「資料全体の構成・要約」がある場合、それを資料の全体マップとして理解してください。\n"
                      . "2. ユーザーから「全体を説明して」「1ページ目から順に」等の依頼があった場合、その構成情報と個別のページ内容を組み合わせて、論理的に順序立てて回答してください。\n"
                      . "3. 回答の際は、根拠となる資料名とページ番号を必ず明記してください。\n"
                      . "4. 資料にない情報は勝手に推測せず、「資料に記載がありません」と答えてください。";

        $fullPrompt = "【参考情報】\n" . $context . "\n\n【質問】\n" . $question;

        // ⑤ Ollamaで回答生成 (Chat APIを使用)
        $aiResponse = $this->callOllamaChat($instructions, $fullPrompt);

        return [
            'answer' => $aiResponse,
            'sources' => $sourceDocs
        ];
    }

    /**
     * Ollama Chat API を呼び出す（GPUフル活用・安定化版）
     */
    private function callOllamaChat(string $system, string $user): string {
        // Gemma 4 系などの推論モデルが設定されている場合、思考トリガーを自動付与
        if (strpos(strtolower($this->chatModel), 'gemma') !== false) {
            if (strpos($system, '<|think|>') !== 0) {
                $system = "<|think|>\n" . $system;
            }
        }

        $curl = curl_init($this->ollamaHost . '/api/chat');
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        
        $payload = [
            'model' => $this->chatModel,
            'messages' => [
                ['role' => 'system', 'content' => $system],
                ['role' => 'user', 'content' => $user]
            ],
            'stream' => false,
            'options' => [
                'num_ctx' => 8192, // 長い要約データを扱うためにコンテキスト長を確保
                'temperature' => 0.4,
                'num_gpu' => 999   // ★追加: 可能な限り全レイヤーをGPUにオフロードさせる
            ]
        ];
        
        curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($payload));
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_TIMEOUT, 150);
        
        $res = curl_exec($curl);
        if ($res === false) {
            $err = curl_error($curl);
            curl_close($curl);
            return "AIサーバーとの通信に失敗しました。詳細: {$err}";
        }
        
        curl_close($curl);
        
        $data = json_decode($res, true);
        $content = $data['message']['content'] ?? '回答の生成に失敗しました。';
        
        // 思考タグのクレンジング
        $content = preg_replace('/<\|channel>thought.*?<channel\|>/s', '', $content);
        $content = preg_replace('/<think>.*?<\/think>/s', '', $content);
        
        return trim($content);
    }
}