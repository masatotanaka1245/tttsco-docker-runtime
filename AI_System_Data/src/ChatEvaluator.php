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
        $questionType = $this->classifyQuestionType((string)$question);
        $questionPolicy = $this->buildQuestionPolicy($questionType);

        // AIを「厳格だが、質問意図に応じて修正手段を選ぶ評価者」として振る舞わせるシステムプロンプト
        $systemPrompt = <<<EOT
あなたはデータ分析およびデータベース集計における正確性と網羅性を厳格に監視する「シニア・データエディター（データ監査官）」です。
提示された【ユーザーの質問（最初の要求）】【参照資料（RAGデータ構成）】および、それをもとに作成された【AIの回答ドラフト】を読み合わせ、減点方式で厳密に評価・採点してください。

綺麗な文章やフワッとした提案力、解説の体裁で誤魔化す回答は減点してください。ただし、質問が単一の事実確認である場合、余計な分析・グラフ・再検索を要求してはいけません。

# 質問タイプ
{$questionType}

# この質問タイプの評価方針
{$questionPolicy}

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

# 修正方針 verdict の選び方
- pass: そのまま採用できる。
- revise_text_only: 既にあるContextだけで直せる。追加SQL、追加検索、追加グラフ生成は禁止。
- need_more_data: Contextに本当に必要な根拠が不足しており、追加検索または追加SQLが必要。
- reject: 架空テーブル・架空カラム・根拠のない断定・質問無視など、回答として成立していない。

もし不合格の場合、feedbackには「次に何を直すべきか」を具体的に書いてください。ただし、必ず追加抽出を指示するのではなく、verdictに合わせてください。
単一事実確認の質問では、答えがContextにあるなら「revise_text_only」を優先し、余計な図表化や再検索は禁止してください。

- ※超重要: datasets内のdataプロパティは「数値の1次元配列」限定です。二重配列にしたりオブジェクトをネストしたりすることは絶対に厳禁です。これらを破るとフロントエンドのChart.jsが即死します。
- ユーザーが求めていない場合、グラフ・図・ランキング・追加分析を要求しないでください。

【絶対ルール：システムファクトの誤認逮捕禁止】
コンテキスト（Context）内部には、システムがデータベースから物理的に取得した「【現在のプロジェクト内実在データ総数マトリクス】（例：〇〇テーブル: 63件など）」という【動的な現実のファクト（真実の数字）】が含まれている。
AIが回答レポートの中で、このコンテキスト由来の「63件」や「2件」といった現実の数値、および実在のカラム名（所属、課題など）を提示している場合は、ハルシネーション（捏造）では絶対にない。これらを「架空のデータの捏造」と誤認して減点したり、回答を削除する指示を出す行為は【完全に厳禁】とする。真実の数字に基づく客観的報告を最高評価せよ。

# 出力フォーマット制限
必ず以下のJSON形式のみで出力してください。Markdownブロック(```json等)は絶対に含めないでください。
{
  "question_type": "{$questionType}",
  "verdict": "pass / revise_text_only / need_more_data / reject のいずれか",
  "scores": {
    "proactivity": 0,
    "faithfulness": 0,
    "answer_relevance": 0,
    "clarity": 0
  },
  "total_score": 0,
  "feedback": "改善点と、verdictに応じた具体的な修正指示を記述すること",
  "next_action": "次に取るべき行動を短く記述。追加不要なら空文字",
  "sql_hint": "追加SQLや集計軸の具体ヒント。不要なら空文字",
  "must_fix": ["修正すべき点"],
  "forbidden_actions": ["禁止すべき追加行動"],
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

        if (!$evalResult || !is_array($evalResult)) {
            return $this->getDefaultFallback($questionType);
        }

        return $this->normalizeEvaluationResult($evalResult, $questionType);
    }

    public function reviseDraftTextOnly($question, $context, $draft_answer, $feedback, $model = 'gemma4:e4b', array $forbiddenActions = []) {
        $questionType = $this->classifyQuestionType((string)$question);
        $forbiddenText = empty($forbiddenActions) ? '追加SQL、追加検索、未要求の図表、根拠のない新情報' : implode('、', $forbiddenActions);

        $systemPrompt = <<<EOT
あなたは最終回答の文章リライト担当です。
必ず既存のContextと現在のドラフトだけを使って、ユーザーに提示する最終回答を日本語Markdownで書き直してください。

# 質問タイプ
{$questionType}

# 禁止事項
{$forbiddenText}

# 重要ルール
- 新しいSQL、追加検索、追加データ取得が必要であるかのような内部説明を書かない。
- Contextにない数字、テーブル、カラム、固有名詞を作らない。
- 単一事実確認なら、結論を先に短く書き、必要最小限の根拠だけ添える。
- グラフや図は、ユーザーが求めていて、かつ既存ドラフトに正しいJSONデータがある場合だけ残す。
- 評価者・門番・内部ログ・リライト処理について説明しない。
EOT;

        $userPrompt = <<<EOT
【ユーザーの質問】
{$question}

【利用可能なContext】
{$context}

【現在のドラフト】
{$draft_answer}

【品質評価フィードバック】
{$feedback}

上記だけを根拠に、ユーザーへ提示する最終回答のみを書いてください。
EOT;

        $data = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userPrompt]
            ],
            'stream' => false,
            'options' => [
                'temperature' => 0.0,
                'top_p' => 0.1,
                'num_ctx' => 4096
            ]
        ];

        $ch = curl_init("{$this->ollama_host}/api/chat");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($error || !$response) {
            if (function_exists('chatLogger')) {
                chatLogger("[TEXT-ONLY-REWRITE-FAILED] cURLエラーまたは空レスポンス: {$error}");
            }
            return trim((string)$draft_answer);
        }

        $responseData = json_decode($response, true);
        $replyContent = trim((string)($responseData['message']['content'] ?? ''));

        return $replyContent !== '' ? $replyContent : trim((string)$draft_answer);
    }

    /**
     * エラー時のフェイルセーフ（無限ループやクラッシュ防止のため、仮合格扱いにして通過させる）
     */
    private function getDefaultFallback($questionType = 'general') {
        return [
            'question_type' => $questionType,
            'verdict' => 'pass',
            'evaluation_mode' => 'fallback',
            'evaluation_source' => 'judge_fallback',
            'scores' => [
                'proactivity' => 80,
                'faithfulness' => 100,
                'answer_relevance' => 100,
                'clarity' => 80
            ],
            'total_score' => 95,
            'feedback' => '評価プロセスの実行中にタイムアウト等のエラーが発生したため、フェイルセーフにより初期ドラフトを採用します。',
            'next_action' => '',
            'sql_hint' => '',
            'must_fix' => [],
            'forbidden_actions' => [],
            'needs_revision' => false
        ];
    }

    private function classifyQuestionType(string $question): string {
        $q = mb_strtolower($question);

        $isSingleFact = preg_match('/(何件|件数|総件数|いくつ|何個|何名|何行|ありますか|わかりますか|教えてください)/u', $q);
        $isBroad = preg_match('/(月別|日別|年別|推移|比較|グラフ|図|内訳|ランキング|一覧|全て|すべて|全件|傾向|分析|まとめ|要約|分類|カテゴライズ)/u', $q);

        if ($isSingleFact && !$isBroad) {
            return 'single_fact';
        }
        if (preg_match('/(全て|すべて|全件|全体|分類|カテゴライズ|カテゴリ|どんな項目|どのような項目|どんな内容|どのような内容)/u', $q)) {
            return 'full_read_categorize';
        }
        if (preg_match('/(月別|日別|年別|推移|比較|グラフ|図|内訳|ランキング|集計|平均|合計|割合|比率|最大|最小)/u', $q)) {
            return 'aggregate_compare';
        }
        if (preg_match('/(提案|改善|留意点|考察|分析|方針|課題|どうすれば|おすすめ)/u', $q)) {
            return 'proposal_analysis';
        }

        return 'general';
    }

    private function buildQuestionPolicy(string $questionType): string {
        $policies = [
            'single_fact' => "- 目的は単一の答えを正確に返すこと。\n- 余計なグラフ、比較、ランキング、追加SQL、追加検索は原則禁止。\n- Contextに答えがあるのに文章が悪いだけなら verdict は revise_text_only。\n- Contextに答えそのものが存在しない場合だけ need_more_data。",
            'aggregate_compare' => "- 集計軸、数値、比較対象が質問意図と一致しているかを重視。\n- グラフはユーザーが求めた場合、または集計比較の理解に有益で正しいデータがある場合のみ許可。\n- 必要な集計結果がContextにない場合は need_more_data。",
            'full_read_categorize' => "- 質問意図に該当するデータ全体を対象にした説明・分類・要約を重視。\n- 件数が多い場合でも、検索・抽出済みContextの範囲と限界を明示できていれば評価する。\n- 不足が検索条件や対象範囲の問題なら need_more_data。",
            'proposal_analysis' => "- 根拠データに基づく洞察、提案、次アクションを評価。\n- 根拠のない一般論や架空の事実は厳しく減点。\n- 根拠はあるが構成が悪い場合は revise_text_only。"
        ];

        return $policies[$questionType] ?? "- 質問に直接答えているか、Contextに忠実かを評価。\n- 追加データが本当に必要な場合だけ need_more_data。\n- 文章構成の問題だけなら revise_text_only。";
    }

    private function normalizeEvaluationResult(array $evalResult, string $questionType): array {
        $scores = $evalResult['scores'] ?? [];
        $normalizedScores = [
            'proactivity' => $this->normalizeScore($scores['proactivity'] ?? 80),
            'faithfulness' => $this->normalizeScore($scores['faithfulness'] ?? 80),
            'answer_relevance' => $this->normalizeScore($scores['answer_relevance'] ?? 80),
            'clarity' => $this->normalizeScore($scores['clarity'] ?? 80),
        ];

        $totalScore = (int)round(array_sum($normalizedScores) / 4);
        $feedback = trim((string)($evalResult['feedback'] ?? ''));
        $verdict = (string)($evalResult['verdict'] ?? '');
        $allowedVerdicts = ['pass', 'revise_text_only', 'need_more_data', 'reject'];

        if (!in_array($verdict, $allowedVerdicts, true)) {
            $needsRevision = (bool)($evalResult['needs_revision'] ?? false);
            $verdict = $needsRevision ? $this->inferRevisionVerdict($feedback, $questionType) : 'pass';
        }

        if ($verdict === 'pass' && (
            $normalizedScores['faithfulness'] < 90 ||
            $normalizedScores['answer_relevance'] < 90 ||
            $totalScore < 85
        )) {
            $verdict = $this->inferRevisionVerdict($feedback, $questionType);
            if (function_exists('chatLogger')) {
                chatLogger("[EVAL-GUARD] スコア不足を検知し、verdict={$verdict} に補正しました。");
            }
        }

        if ($questionType === 'single_fact' && $verdict === 'need_more_data' && !$this->feedbackRequiresMoreData($feedback)) {
            $verdict = 'revise_text_only';
        }

        $forbiddenActions = $evalResult['forbidden_actions'] ?? [];
        if (!is_array($forbiddenActions)) {
            $forbiddenActions = [$forbiddenActions];
        }
        if ($questionType === 'single_fact') {
            $forbiddenActions = array_values(array_unique(array_merge($forbiddenActions, [
                '未要求のグラフ生成',
                '未要求のランキング作成',
                '答えが既にある場合の追加SQL',
                '答えが既にある場合の追加検索'
            ])));
        }

        $mustFix = $evalResult['must_fix'] ?? [];
        if (!is_array($mustFix)) {
            $mustFix = [$mustFix];
        }

        $nextAction = trim((string)($evalResult['next_action'] ?? ''));
        $sqlHint = trim((string)($evalResult['sql_hint'] ?? ''));

        return [
            'question_type' => $questionType,
            'verdict' => $verdict,
            'evaluation_mode' => 'real',
            'evaluation_source' => 'judge',
            'scores' => $normalizedScores,
            'total_score' => $totalScore,
            'feedback' => $feedback !== '' ? $feedback : '質問意図とContextに合わせて、過不足のない最終回答に調整してください。',
            'next_action' => $nextAction,
            'sql_hint' => $sqlHint,
            'must_fix' => array_values(array_filter(array_map('strval', $mustFix))),
            'forbidden_actions' => array_values(array_filter(array_map('strval', $forbiddenActions))),
            'needs_revision' => $verdict !== 'pass',
        ];
    }

    private function normalizeScore($score): int {
        $score = (int)$score;
        if ($score > 100) {
            $score = 100;
        }
        if ($score < 0) {
            $score = 0;
        }
        return $score;
    }

    private function inferRevisionVerdict(string $feedback, string $questionType): string {
        if ($this->feedbackRequiresMoreData($feedback) && $questionType !== 'single_fact') {
            return 'need_more_data';
        }
        if (preg_match('/(架空|捏造|存在しない|質問無視|回答として成立|不正)/u', $feedback)) {
            return 'reject';
        }
        return 'revise_text_only';
    }

    private function feedbackRequiresMoreData(string $feedback): bool {
        return (bool)preg_match('/(データ不足|根拠不足|追加抽出|追加検索|追加SQL|Contextに.*ない|資料不足|全件不足)/u', $feedback);
    }
}
