<?php

require_once __DIR__ . '/OllamaChatHelper.php';
require_once __DIR__ . '/AnswerAlignmentChecker.php';
require_once __DIR__ . '/EvaluationResultHelper.php';
require_once __DIR__ . '/QuestionTypeClassifier.php';
require_once __DIR__ . '/ClarificationQuestionBuilder.php';

/**
 * src/ChatEvaluator.php
 * LLM-as-a-Judge による自律型評価と自己修正（Self-Correction）を担うクラス
 * 【次世代自律成長型アーキテクチャ：鬼のシニア・データエディター（スパルタ版）】
 */
class ChatEvaluator {
    private $ollama_host;

    private function logPhaseTiming(string $phase, array $fields = []): void
    {
        if (!function_exists('chatLogger')) {
            return;
        }

        $parts = ['[EVAL-PHASE-TIMING]', "phase={$phase}"];
        foreach ($fields as $key => $value) {
            if ($value === null || $value === '') {
                continue;
            }
            $parts[] = "{$key}={$value}";
        }

        chatLogger(implode(' | ', $parts));
    }

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
        $startedAt = microtime(true);
        $questionType = QuestionTypeClassifier::classify((string)$question);
        $questionPolicy = QuestionTypeClassifier::buildPolicy($questionType);

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
        $data = OllamaChatHelper::buildChatPayload(
            (string)$model,
            $systemPrompt,
            $userPrompt,
            'json',
            [
                'temperature' => 0.0, // 評価基準のブレ（遊び）を完全に排除するため0.0に超決定論化
                'top_p' => 0.1
            ]
        );

        // API通信の実行
        $ch = curl_init("{$this->ollama_host}/api/chat");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90); // スパルタ記述を考慮しタイムアウトを90秒に緩和拡張
        $this->logPhaseTiming('evaluate_start', [
            'timeout' => 90,
            'model' => (string)$model,
            'question_type' => $questionType,
            'response_chars' => mb_strlen((string)$draft_answer),
            'context_chars' => mb_strlen((string)$context),
            'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error || !$response) {
            $this->logPhaseTiming('evaluate_error', [
                'timeout' => 90,
                'http_code' => $httpCode,
                'error' => mb_substr((string)$error, 0, 160),
                'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
            ]);
            return $this->getDefaultFallback();
        }

        $responseData = json_decode($response, true);
        $replyContent = OllamaChatHelper::extractVisibleContent((string)($responseData['message']['content'] ?? ''));

        $evalResult = json_decode($replyContent, true);

        if (!$evalResult || !is_array($evalResult)) {
            $this->logPhaseTiming('evaluate_error', [
                'timeout' => 90,
                'http_code' => $httpCode,
                'error' => 'invalid_or_empty_json',
                'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
            ]);
            return $this->getDefaultFallback($questionType);
        }

        $normalized = $this->normalizeEvaluationResult($evalResult, $questionType, (string)$question, (string)$draft_answer);
        $this->logPhaseTiming('evaluate_end', [
            'timeout' => 90,
            'http_code' => $httpCode,
            'result' => (string)($normalized['verdict'] ?? 'unknown'),
            'evaluation_mode' => (string)($normalized['evaluation_mode'] ?? 'unknown'),
            'total_score' => (int)($normalized['total_score'] ?? 0),
            'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
        ]);

        return $normalized;
    }

    public function reviseDraftTextOnly($question, $context, $draft_answer, $feedback, $model = 'gemma4:e4b', array $forbiddenActions = []) {
        $startedAt = microtime(true);
        $questionType = QuestionTypeClassifier::classify((string)$question);
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

        $data = OllamaChatHelper::buildChatPayload(
            (string)$model,
            $systemPrompt,
            $userPrompt,
            null,
            [
                'temperature' => 0.0,
                'top_p' => 0.1,
                'num_ctx' => 4096
            ]
        );

        $ch = curl_init("{$this->ollama_host}/api/chat");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
        curl_setopt($ch, CURLOPT_TIMEOUT, 90);
        $this->logPhaseTiming('rewrite_start', [
            'timeout' => 90,
            'model' => (string)$model,
            'question_type' => $questionType,
            'draft_chars' => mb_strlen((string)$draft_answer),
            'context_chars' => mb_strlen((string)$context),
            'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
        ]);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($error || !$response) {
            if (function_exists('chatLogger')) {
                chatLogger("[TEXT-ONLY-REWRITE-FAILED] cURLエラーまたは空レスポンス: {$error}");
            }
            $this->logPhaseTiming('rewrite_error', [
                'timeout' => 90,
                'http_code' => $httpCode,
                'error' => mb_substr((string)$error, 0, 160),
                'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
            ]);
            return trim((string)$draft_answer);
        }

        $responseData = json_decode($response, true);
        $replyContent = OllamaChatHelper::extractVisibleContent((string)($responseData['message']['content'] ?? ''));

        $finalText = $replyContent !== '' ? $replyContent : trim((string)$draft_answer);
        $this->logPhaseTiming('rewrite_end', [
            'timeout' => 90,
            'http_code' => $httpCode,
            'result_chars' => mb_strlen($finalText),
            'used_fallback' => ($replyContent === '' ? 'yes' : 'no'),
            'elapsed_ms' => (int)round((microtime(true) - $startedAt) * 1000),
        ]);

        return $finalText;
    }

    public function shouldAskUserClarification(array $evalResult, string $question = ''): bool {
        return ClarificationQuestionBuilder::shouldAsk($evalResult, $question);
    }

    public function buildClarificationQuestion(string $question, array $evalResult): string {
        return ClarificationQuestionBuilder::build($question, $evalResult);
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
                'proactivity' => 60,
                'faithfulness' => 100,
                'answer_relevance' => 70,
                'clarity' => 60
            ],
            'total_score' => 73,
            'feedback' => '評価プロセスの実行中にタイムアウト等のエラーが発生したため、フェイルセーフにより初期ドラフトを採用します。',
            'next_action' => '',
            'sql_hint' => '',
            'must_fix' => [],
            'forbidden_actions' => [],
            'needs_revision' => false,
            'allow_memory_refresh' => false
        ];
    }

    private function normalizeEvaluationResult(array $evalResult, string $questionType, string $question, string $draftAnswer): array {
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

        $forbiddenActions = EvaluationResultHelper::normalizeStringList($evalResult['forbidden_actions'] ?? []);
        if ($questionType === 'single_fact') {
            $forbiddenActions = EvaluationResultHelper::normalizeStringList(array_merge($forbiddenActions, [
                '未要求のグラフ生成',
                '未要求のランキング作成',
                '答えが既にある場合の追加SQL',
                '答えが既にある場合の追加検索'
            ]));
        }

        $mustFix = EvaluationResultHelper::normalizeStringList($evalResult['must_fix'] ?? []);

        $alignment = AnswerAlignmentChecker::analyze($question, $draftAnswer);
        $mismatchPair = is_array($alignment['mismatch_pair'] ?? null) ? $alignment['mismatch_pair'] : null;
        if (($alignment['has_mismatch'] ?? false) === true) {
            $verdict = 'reject';
            $normalizedScores['answer_relevance'] = min($normalizedScores['answer_relevance'], 35);
            $normalizedScores['clarity'] = min($normalizedScores['clarity'], 55);
            $normalizedScores['proactivity'] = min($normalizedScores['proactivity'], 60);
            $totalScore = min($totalScore, 48);
            $feedback = EvaluationResultHelper::appendFeedback((string)($alignment['feedback'] ?? '質問と回答の対象が一致していません。'), $feedback);
            $mustFix[] = '質問で求められた対象に回答対象を揃える';
            $forbiddenActions[] = '別ルートの要約や別ソースの説明で質問を代用する';
            if (function_exists('chatLogger')) {
                chatLogger("[EVAL-ALIGNMENT-MISMATCH] expected=" . implode(',', (array)($alignment['expected_targets'] ?? [])) . " | answer=" . implode(',', (array)($alignment['answer_targets'] ?? [])));
            }
        }
        $mustFix = EvaluationResultHelper::normalizeStringList($mustFix);
        $forbiddenActions = EvaluationResultHelper::normalizeStringList($forbiddenActions);

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
            'must_fix' => $mustFix,
            'forbidden_actions' => $forbiddenActions,
            'mismatch_pair' => $mismatchPair,
            'needs_revision' => $verdict !== 'pass',
            'allow_memory_refresh' => $verdict === 'pass' && $totalScore >= 85,
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
