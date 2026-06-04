<?php

final class AdvancedDraftComposer
{
    private $originalMessage;
    private $subAnswers;
    private $ollamaHost;
    private $reasoningModel;
    private $synthesisModel;
    private $reportMode;
    private $composeMemoryAwarePrompt;

    public function __construct(
        string $originalMessage,
        array $subAnswers,
        string $ollamaHost,
        string $reasoningModel,
        string $synthesisModel,
        bool $reportMode,
        callable $composeMemoryAwarePrompt
    ) {
        $this->originalMessage = $originalMessage;
        $this->subAnswers = $subAnswers;
        $this->ollamaHost = $ollamaHost;
        $this->reasoningModel = $reasoningModel;
        $this->synthesisModel = $synthesisModel;
        $this->reportMode = $reportMode;
        $this->composeMemoryAwarePrompt = $composeMemoryAwarePrompt;
    }

    public function applyReportModeFinalPolish(string $currentDraft): string
    {
        if (!$this->reportMode) {
            return trim($currentDraft);
        }

        $reasoningText = implode("\n\n", $this->subAnswers);
        if (mb_strlen($reasoningText) > 6500) {
            $reasoningText = mb_substr($reasoningText, 0, 6500) . "\n...[制限超過による省略]";
        }

        $systemPrompt = "あなたは技術報告書を仕上げる業務支援AIです。"
            . "与えられた根拠だけを使い、推測や一般論に逃げず、この案件の報告書本文としてそのまま使える最終版を日本語Markdownで出力してください。"
            . "必ず次の見出しをこの順で含めてください: "
            . "## 結論 / ## 分析対象 / ## 根拠 / ## 留意点 / ## 推奨アクション / ## 出典。"
            . "資料紹介や概要説明だけで終わらせず、ユーザーの質問に対する直接の答えを最初に述べてください。"
            . "根拠に書かれていない資料名、建物名、用途、数値、法規名、結論を推測で補ってはいけません。"
            . "根拠が弱い項目は断定せず、『要確認』として扱ってください。"
            . "留意点と推奨アクションは、根拠に結び付く具体的な項目を優先し、冗長な一般論は避けてください。"
            . "出典では、可能な限り資料名、ページ、CSV名、ステップ番号など識別できる情報を箇条書きで示してください。";

        $userPrompt = "【ユーザーの質問】\n{$this->originalMessage}\n\n"
            . "【利用可能な根拠】\n{$reasoningText}\n\n"
            . "【現在のドラフト】\n{$currentDraft}\n\n"
            . "上記だけを使い、報告書本文として完成した最終版のみを日本語Markdownで出力してください。";

        $response = callOllamaChat(
            $this->ollamaHost,
            $this->synthesisModel,
            (string)call_user_func($this->composeMemoryAwarePrompt, $systemPrompt),
            $userPrompt,
            null,
            ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 4096]
        );

        $response = trim((string)$response);
        return $response !== '' ? $response : trim($currentDraft);
    }

    public function buildEvidenceDraft(array $stepResults): string
    {
        $parts = [];
        foreach ($this->subAnswers as $answer) {
            $trimmed = trim((string)$answer);
            if ($trimmed !== '') {
                $parts[] = $trimmed;
            }
        }
        foreach ($stepResults as $result) {
            $purpose = trim((string)($result['purpose'] ?? ''));
            $body = trim((string)($result['result'] ?? ''));
            if ($body !== '') {
                $parts[] = "◆ 資料巡回: {$purpose}\n{$body}";
            }
        }

        if (empty($parts)) {
            return "ユーザーの質問に対して利用可能な根拠データを取得できませんでした。";
        }

        $evidence = implode("\n\n", $parts);
        if (mb_strlen($evidence) > 6000) {
            $evidence = mb_substr($evidence, 0, 6000) . "\n...[制限超過による省略]";
        }

        return "## サブクエリ別の中間回答\n\n" . $evidence;
    }

    public function generateAdditionalChunkQuery(string $feedback): string
    {
        $sysPrompt = "お前は超一流の検索エンジン最適化エージェントです。\n"
            . "品質審査責任者からの以下の【修正指示文】を読み、データベース（LIKE検索）から不足しているテキスト断片を探し出すために最も適切な「具体的な検索キーワード（単語1つのみ）」を自律抽出してください。\n\n"
            . "【修正指示文】\n{$feedback}\n\n"
            . "【出力absolute制約】\n出力は説明文、Markdown、句読点などを一切含めず、純粋な単語（例: 補強工法）を1つ【のみ】テキストとして出力してください。挨拶は完全禁止します。";

        $userPrompt = "抽出された検索キーワード:";
        $thoughtDummy = "";

        $keyword = callOllamaChat(
            $this->ollamaHost,
            $this->reasoningModel,
            $sysPrompt,
            $userPrompt,
            null,
            ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 2048],
            $thoughtDummy
        );

        return trim((string)$keyword, " \t\n\r\0\x0B\"'`.");
    }
}
