<?php

final class ReportAnswerPolisher
{
    private string $ollamaHost;
    private string $model;
    private $composeSystemPrompt;
    private $logger;

    public function __construct(
        string $ollamaHost,
        string $model,
        ?callable $composeSystemPrompt = null,
        ?callable $logger = null
    ) {
        $this->ollamaHost = rtrim($ollamaHost, '/');
        $this->model = $model;
        $this->composeSystemPrompt = $composeSystemPrompt;
        $this->logger = $logger;
    }

    public function polish(string $question, string $evidenceText, string $currentDraft): string
    {
        $question = trim($question);
        $currentDraft = trim($currentDraft);
        $evidenceText = trim($evidenceText);

        if ($currentDraft === '') {
            return '';
        }

        if ($evidenceText === '') {
            $evidenceText = '利用可能な根拠は限定的です。現在のドラフトだけを基に、断定を避けて整形してください。';
        }

        if (mb_strlen($evidenceText) > 6500) {
            $evidenceText = mb_substr($evidenceText, 0, 6500) . "\n...[制限超過による省略]";
        }

        $systemPrompt = "あなたは技術報告書を仕上げる業務支援AIです。"
            . "与えられた根拠だけを使い、推測や一般論に逃げず、この案件の報告書本文としてそのまま使える最終版を日本語Markdownで出力してください。"
            . "必ず次の見出しをこの順で含めてください: "
            . "## 結論 / ## 分析対象 / ## 根拠 / ## 留意点 / ## 推奨アクション / ## 出典。"
            . "最初の『## 結論』では、ユーザーの質問への直接の答えを簡潔に述べてください。"
            . "根拠に書かれていない資料名、建物名、用途、数値、法規名、結論を推測で補ってはいけません。"
            . "根拠が弱い項目は断定せず、『要確認』として扱ってください。"
            . "現在のドラフトに ```json:chart や ```mermaid のコードブロックが含まれていて、根拠に基づく有効な図表であれば保持してください。"
            . "留意点と推奨アクションは、根拠に結び付く具体的な項目を優先し、冗長な一般論は避けてください。"
            . "出典では、可能な限り資料名、ページ、CSV名、ステップ番号など識別できる情報を箇条書きで示してください。";

        if (is_callable($this->composeSystemPrompt)) {
            $systemPrompt = (string)call_user_func($this->composeSystemPrompt, $systemPrompt);
        }

        $userPrompt = "【ユーザーの質問】\n{$question}\n\n"
            . "【利用可能な根拠】\n{$evidenceText}\n\n"
            . "【現在のドラフト】\n{$currentDraft}\n\n"
            . "上記だけを使い、報告書本文として完成した最終版のみを日本語Markdownで出力してください。";

        $this->log('[REPORT-POLISH] 共通整形を実行します。questionChars=' . mb_strlen($question)
            . ' | evidenceChars=' . mb_strlen($evidenceText)
            . ' | draftChars=' . mb_strlen($currentDraft));

        $response = callOllamaChat(
            $this->ollamaHost,
            $this->model,
            $systemPrompt,
            $userPrompt,
            null,
            ['temperature' => 0.0, 'top_p' => 0.1, 'num_ctx' => 8192]
        );

        $response = trim((string)$response);
        if ($response === '') {
            $this->log('[REPORT-POLISH] 共通整形の応答が空だったため、ドラフトをそのまま返します。');
            return $currentDraft;
        }

        return $response;
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }
}
