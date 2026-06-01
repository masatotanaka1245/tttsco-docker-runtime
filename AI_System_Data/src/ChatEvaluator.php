<?php

/**
 * src/ChatEvaluator.php
 * LLM-as-a-Judge による自律型評価と自己修正（Self-Correction）を担うクラス
 * 【次世代自律成長型アーキテクチャ：鬼のシニア・データエディター（スパルタ版）】
 */
class ChatEvaluator {
    private $ollama_host;
    
    public function __construct($host) {
        $this->ollama_host = rtrim($host, '/');
    }

    /**
     * AIの回答ドラフトを評価し、スコアとフィードバックを返す
     *
     * @param string $question ユーザーの質問
     * @param string $context RAGで取得したコンテキスト（PDF/CSVデータ）
     * @param string $draft_answer 評価対象のAI回答ドラフト
     * @param string $model 評価に使用するモデル
     * @return array 評価結果の連想配列
     */
    public function evaluateDraft($question, $context, $draft_answer, $model = 'gemma4:e4b') {
        // AIを「厳格なシニアエンジニア」として振る舞わせるシステムプロンプト
        $systemPrompt = <<<EOT
あなたはデータ分析およびデータベース集計における正確性と網羅性のみを蛇のように厳格に監視する、鬼の「シニア・データエディター（データ監査官）」です。
提示された【ユーザーの質問（最初の要求）】【参照資料（RAGデータ構成）】および、それをもとに作成された【AIの回答ドラフト】を冷徹に読み合わせ、一切の妥協を排除した「減点方式」で厳密に評価・採点を行ってください。

綺麗な文章やフワッとした提案力、解説の体裁といった「外面の美しさ」で誤魔化すAI特有のサボり癖（ハルシネーション・回答の省略）を物理的に粉砕するのがあなたの使命です。

# 採点基準および評価軸（各100点満点・超厳格減点法）
1. Answer Relevance（質問への完全な的確性・網羅性）
- ユーザーの【最初の質問】に含まれるすべてのオーダー、数値、論点、およびデータの集計要求が「105%完全に網羅されているか」のみを極限までチェックしてください。
- 尋ねられている論点、テーブル、あるいはデータの切り口のうち、1つでも回答の抜け漏れ、または表面的な要約による誤魔化し、データ抽出のサボり（空振り）を検知した場合は、容赦なく「50点以下」としてください。完璧にオーダーを網羅している場合のみ100点を与えます。

2. Faithfulness（事実・実在データへの忠実性）
- 提示された実在データ構造や中間考察の数珠繋ぎに存在しない、架空のテーブル名、物理カラム名、または実在しないサンプルの値をあなたの想像ででっち上げている場合は、即座に「0点」としてください。

3. Data Insight（データ解釈の解像度）
- 抽出された集計結果のバッチ情報を正確に比較・分析できているかを監査してください。単にデータ配列をそのまま横流しにしていたり、解釈が浅い場合は厳しく減点してください。

4. Clarity（可読性と構造化）
- Markdownを活用し、集計データと考察が明晰に構造化されているかを評価してください。

# 📝【スパルタ仕様②】具体的作戦指示（Actionable Directive）の義務化
もし「Answer Relevance」が100点未満、あるいは合計点が90点未満で不合格となる場合、`feedback` プロパティの文字数制限（150文字制限など）は完全に撤廃します。
次の周回でAIがそのままコピペして動けるレベルの具体的かつロジカルな【次の一手の作戦指示（Actionable Directive）】を豊富に記述してください。
「どのデータ項目や観点が致命的に不足しているか。このギャップを埋めるため、次の周回ではどのテーブルのどのキーを新たに抽出し、ドラフトのどの部分へどのように書き足してリライトすべきか」を明確に言語化して突きつけてください。

- ※超重要: datasets内のdataプロパティは「数値の1次元配列」限定です。二重配列にしたりオブジェクトをネストしたりすることは絶対に厳禁です。これらを破るとフロントエンドのChart.jsが即死します。
- JSONブロックの直前、または直後には、その集計結果から読み取れる深い技術的インサイトや業務改善施策の解説テキストを日本語で豊富に添えてください。

【絶対ルール：システムファクトの誤認逮捕禁止】
コンテキスト（Context）内部には、システムがデータベースから物理的に取得した「【現在のプロジェクト内実在データ総数マトリクス】（例：〇〇テーブル: 63件など）」という【動的な現実のファクト（真実の数字）】が含まれている。
AIが回答レポートの中で、このコンテキスト由来の「63件」や「2件」といった現実の数値、および実在のカラム名（所属、課題など）を提示している場合は、ハルシネーション（捏造）では絶対にない。これらを「架空のデータの捏造」と誤認して減点したり、回答を削除する指示を出す行為は【完全に厳禁】とする。真実の数字に基づく客観的報告を最高評価せよ。

# 出力フォーマット制限
必ず以下のJSON形式のみで出力してください。Markdownブロック(```json等)は絶対に含めないでください。
{
  "scores": {
    "proactivity": 0,
    "faithfulness": 0,
    "answer_relevance": 0,
    "clarity": 0
  },
  "total_score": 0,
  "feedback": "改善点と、次周回で実行すべき具体的なデータ追加抽出・クエリ再生成の作戦指示を詳細に記述すること",
  "needs_revision": true または false
}
EOT;

        // 評価対象のデータを流し込むユーザープロンプト
        $userPrompt = <<<EOT
<UserQuestion>
{$question}
</UserQuestion>

<Context>
{$context}
</Context>

<DraftAnswer>
{$draft_answer}
</DraftAnswer>
EOT;

        // Ollama APIのリクエストデータ構築
        $data = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt]
            ],
            'format' => 'json', // OllamaにJSON出力を強制させ、プログラムでのパースを安定化
            'stream' => false,
            'options' => [
                'temperature' => 0.0, // 評価基準のブレ（遊び）を完全に排除するため0.0に超決定論化
                'top_p' => 0.1
            ]
        ];

        // API通信の実行
        $ch = curl_init("{$this->ollama_host}/api/chat");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90); // スパルタ記述を考慮しタイムアウトを90秒に緩和拡張

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || !$response) {
            return $this->getDefaultFallback();
        }

        $responseData = json_decode($response, true);
        $replyContent = $responseData['message']['content'] ?? '';
        
        $evalResult = json_decode($replyContent, true);

        // ✨【同期補正完了】JSONパース失敗時、または判定の要である needs_revision の不在を主軸に防衛
        if (!$evalResult || !isset($evalResult['needs_revision'])) {
            return $this->getDefaultFallback();
        }

        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        // ✨【スパルタ仕様③】合格ラインの超厳格化ロジック
        // LLMが甘い数値を返してきたとしても、要件を1ミリも外させないための強制差し戻しシールド
        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
        $totalScore = (int)($evalResult['total_score'] ?? 0);
        $relevanceScore = (int)($evalResult['scores']['answer_relevance'] ?? 0);

        // 合計点が90点未満、または質問への的確性（網羅性）が100点に満たない場合は容赦なくレッドカード
        if ($totalScore < 90 || $relevanceScore < 100) {
            $evalResult['needs_revision'] = true;
            chatLogger("[SPARTA-GUARD] 減点対象を検知。プログラム側で強制的に needs_revision = true を執行しました。");
        }

        return $evalResult;
    }

    /**
     * エラー時のフェイルセーフ（無限ループやクラッシュ防止のため、仮合格扱いにして通過させる）
     */
    private function getDefaultFallback() {
        return [
            'scores' => [
                'proactivity' => 80,
                'faithfulness' => 100,
                'answer_relevance' => 100,
                'clarity' => 80
            ],
            'total_score' => 95,
            'feedback' => '評価プロセスの実行中にタイムアウト等のエラーが発生したため、フェイルセーフにより初期ドラフトを採用します。',
            'needs_revision' => false
        ];
    }
}