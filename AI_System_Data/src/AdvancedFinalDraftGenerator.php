<?php

require_once __DIR__ . '/LightweightFinalGuardRunner.php';

final class AdvancedFinalDraftGenerator
{
    private string $originalMessage;
    private string $ollamaHost;
    private string $model;
    private string $synthesisModel;
    private array $subAnswers;
    /** @var callable */
    private $buildLightweightDocFinalAnswer;
    /** @var callable */
    private $applyReportModeFinalPolish;
    /** @var callable */
    private $buildEvidenceDraft;
    /** @var callable */
    private $logPromptBudget;
    /** @var callable|null */
    private $logger;
    /** @var callable|null */
    private $statusEmitter;

    public function __construct(array $config)
    {
        $this->originalMessage = (string)($config['originalMessage'] ?? '');
        $this->ollamaHost = (string)($config['ollamaHost'] ?? '');
        $this->model = (string)($config['model'] ?? '');
        $this->synthesisModel = (string)($config['synthesisModel'] ?? '');
        $this->subAnswers = (array)($config['subAnswers'] ?? []);
        $this->buildLightweightDocFinalAnswer = $config['buildLightweightDocFinalAnswer'];
        $this->applyReportModeFinalPolish = $config['applyReportModeFinalPolish'];
        $this->buildEvidenceDraft = $config['buildEvidenceDraft'];
        $this->logPromptBudget = $config['logPromptBudget'];
        $this->logger = $config['logger'] ?? null;
        $this->statusEmitter = $config['statusEmitter'] ?? null;
    }

    public function generate(
        string $currentDraft,
        array $stepResults,
        string $baseSystemPrompt,
        string $chartInstruction,
        bool $preferLightweightDocRoute
    ): array {
        if ($preferLightweightDocRoute) {
            $lightweightResult = $this->tryLightweightDocRoute($currentDraft, $stepResults);
            if ($lightweightResult !== null) {
                return $lightweightResult;
            }
        }

        $mergedReasoningForDraft = implode("\n\n", $this->subAnswers);
        if (mb_strlen($mergedReasoningForDraft) > 7000) {
            $mergedReasoningForDraft = mb_substr($mergedReasoningForDraft, 0, 7000) . "\n...[制限超過による省略]";
        }

        $sysPrompt = $baseSystemPrompt . $chartInstruction;
        $promptUser = "【ユーザーの質問】\n{$this->originalMessage}\n\n"
            . "【サブクエリごとの回答・根拠】\n{$mergedReasoningForDraft}\n\n"
            . "【初期ドラフト】\n{$currentDraft}\n\n"
            . "上記を根拠として、ユーザーに提示する最終回答だけを日本語Markdownで作成してください。";

        call_user_func($this->logPromptBudget, 'final_generate', [
            'system' => $sysPrompt,
            'question' => $this->originalMessage,
            'reasoning' => $mergedReasoningForDraft,
            'draft' => $currentDraft,
        ], 4096);

        $draft = $this->streamFinalDraft("{$sysPrompt}\n\n{$promptUser}\n\n回答（日本語で詳細に）:");

        return [
            'draft' => trim($draft),
            'eval_result' => null,
            'finalized_early' => false,
        ];
    }

    private function tryLightweightDocRoute(string $currentDraft, array $stepResults): ?array
    {
        $this->log("[ADV-LIGHTWEIGHT-FINAL] 資料PDF向け軽量最終回答ルートを適用します。");
        $this->emitStatus('🪄 資料PDF向けの軽量最終整形を実行しています...', 5);

        try {
            $lightweightResponse = (string)call_user_func($this->buildLightweightDocFinalAnswer, $currentDraft, $stepResults);
            if ($lightweightResponse === '') {
                return null;
            }

            $draft = $lightweightResponse;
            $polished = (string)call_user_func($this->applyReportModeFinalPolish, $draft);
            if ($polished !== '') {
                $draft = $polished;
            }

            $guardRunner = new LightweightFinalGuardRunner($this->ollamaHost, $this->logger);
            $guardResult = $guardRunner->review(
                $this->originalMessage,
                (string)call_user_func($this->buildEvidenceDraft, $stepResults),
                $draft,
                $this->model,
                'advanced_lightweight_doc_final'
            );

            $this->emitStatus('✅ 軽量最終回答の確認が完了しました。', 6);

            return [
                'draft' => (string)($guardResult['response'] ?? $draft),
                'eval_result' => $guardResult['eval_result'] ?? null,
                'finalized_early' => true,
            ];
        } catch (Exception $e) {
            $this->log("[ADV-LIGHTWEIGHT-FINAL-FAILED] 軽量最終回答ルートに失敗したため、通常の品質審査へフォールバックします: " . $e->getMessage());
            return null;
        }
    }

    private function streamFinalDraft(string $prompt): string
    {
        $buffer = '';
        $response = '';
        $ollamaErrorMsg = '';

        $getCh = curl_init("{$this->ollamaHost}/api/generate");
        $writeCallback = function ($ch, $data) use (&$buffer, &$response, &$ollamaErrorMsg) {
            $buffer .= $data;
            $lines = explode("\n", $buffer);
            $buffer = array_pop($lines);

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '') {
                    continue;
                }

                $json = json_decode($line, true);
                if (!$json) {
                    continue;
                }
                if (isset($json['error'])) {
                    $ollamaErrorMsg = (string)$json['error'];
                    return 0;
                }
                $response .= (string)($json['response'] ?? '');
            }
            return strlen($data);
        };

        curl_setopt($getCh, CURLOPT_POST, true);
        curl_setopt($getCh, CURLOPT_POSTFIELDS, json_encode([
            'model' => $this->synthesisModel,
            'prompt' => $prompt,
            'stream' => true,
            'options' => ['temperature' => 0.0, 'top_p' => 0.1, 'num_ctx' => 4096],
        ]));
        curl_setopt($getCh, CURLOPT_WRITEFUNCTION, $writeCallback);
        curl_setopt($getCh, CURLOPT_TIMEOUT, 180);
        curl_exec($getCh);
        curl_close($getCh);

        if ($ollamaErrorMsg !== '') {
            $this->log("[ADV-FINAL-DRAFT-OLLAMA-ERROR] " . $ollamaErrorMsg);
        }

        return $response;
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }

    private function emitStatus(string $message, int $step): void
    {
        if ($this->statusEmitter !== null) {
            call_user_func($this->statusEmitter, $message, $step);
        }
    }
}
