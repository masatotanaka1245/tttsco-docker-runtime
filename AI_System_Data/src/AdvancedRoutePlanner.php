<?php

final class AdvancedRoutePlanner
{
    private $ollamaHost;
    private $reasoningModel;
    private $projectId;
    private $reasoningId;
    private $originalMessage;
    private $searchQuery;
    private $schemaInfo;
    private $tablesBrief;
    private $composeMemoryAwarePrompt;
    private $inferOperationType;
    private $saveThoughtStep;
    private $logger;

    public function __construct(
        string $ollamaHost,
        string $reasoningModel,
        int $projectId,
        string $reasoningId,
        string $originalMessage,
        string $searchQuery,
        string $schemaInfo,
        array $tablesBrief,
        callable $composeMemoryAwarePrompt,
        callable $inferOperationType,
        callable $saveThoughtStep,
        ?callable $logger = null
    ) {
        $this->ollamaHost = $ollamaHost;
        $this->reasoningModel = $reasoningModel;
        $this->projectId = $projectId;
        $this->reasoningId = $reasoningId;
        $this->originalMessage = $originalMessage;
        $this->searchQuery = $searchQuery;
        $this->schemaInfo = $schemaInfo;
        $this->tablesBrief = $tablesBrief;
        $this->composeMemoryAwarePrompt = $composeMemoryAwarePrompt;
        $this->inferOperationType = $inferOperationType;
        $this->saveThoughtStep = $saveThoughtStep;
        $this->logger = $logger;
    }

    public function shouldUsePresetDocPlan(callable $shouldSkipSqlSequenceForDocOnlySubQueries): bool
    {
        return (bool)call_user_func($shouldSkipSqlSequenceForDocOnlySubQueries);
    }

    public function buildPresetDocPlan(): array
    {
        return [[
            'step' => 1,
            'table' => 'doc_chunks',
            'purpose' => 'アップロードされた資料PDFから、案件に関する主要な留意点や重要な情報を抽出する。',
            'operation_type' => 'semantic_extract',
        ]];
    }

    public function generateExecutionPlan(): array
    {
        $briefText = "【利用可能なデータベース・テーブル一覧】\n";
        foreach ($this->tablesBrief as $tableName => $description) {
            $briefText .= "- テーブル名: [{$tableName}] (概要: {$description})\n";
        }

        $fence = str_repeat("\x60", 3);

        $sysPrompt = "あなたは超一流のシステムアーキテクトおよびデータアナリストです。\n"
                   . "ユーザーから提示された質問を解決するために、どのテーブルから、どのような順番で情報を取得すべきか、1〜3つのステップで構成される論理的な「実行計画（手順書）」を策定してください。\n\n"
                   . "【絶対ルール】\n"
                   . "軽量LLMの混乱を防ぎレスポンス精度を最大化するため、プロンプトには詳細なカラム情報をあえて含めていません。テーブル名と概要から情報構造を推測し、ステップバイステップの手順を構築してください。\n"
                   . "回答は、必ず以下のJSON配列形式のブロック【のみ】を出力してください。Markdownの説明文や余計なプロースは一切禁止します。\n\n"
                   . $fence . "json\n"
                   . "[\n"
                   . "  {\"step\": 1, \"table\": \"テーブル名\", \"purpose\": \"このステップで検索・集計する具体的な目的（日本語）\", \"operation_type\": \"semantic_extract\"}\n"
                   . "]\n"
                   . $fence . "\n\n"
                   . $briefText;

        $sysPrompt = (string)call_user_func($this->composeMemoryAwarePrompt, $sysPrompt);
        $userPrompt = "【ユーザーの質問】\n{$this->searchQuery}";
        $thought = '';

        $res = callOllamaChat(
            $this->ollamaHost,
            $this->reasoningModel,
            $sysPrompt,
            $userPrompt,
            'json',
            ["temperature" => 0.0, "top_p" => 0.1, "num_ctx" => 4096],
            $thought
        );

        $this->log("[PLANNER-RAW-RESPONSE] Ollamaから受信した生の計画データ:\n" . $res);

        if (!empty($thought) && $this->reasoningId !== '') {
            call_user_func($this->saveThoughtStep, $thought);
        }

        $cleanJson = $res;
        $fencePattern = '/^' . preg_quote($fence, '/') . '(?:json)?\s*(\\[.*?\\])\s*' . preg_quote($fence, '/') . '/is';
        if (preg_match($fencePattern, $res, $matches)) {
            $cleanJson = $matches[1];
        } elseif (preg_match('/(\\[.*?\\])/is', $res, $matches)) {
            $cleanJson = $matches[1];
        }

        $plan = json_decode($cleanJson, true);
        if (is_array($plan) && isset($plan['plan']) && is_array($plan['plan'])) {
            $plan = $plan['plan'];
        } elseif (is_array($plan) && isset($plan['steps']) && is_array($plan['steps'])) {
            $plan = $plan['steps'];
        } elseif (is_array($plan) && isset($plan['table'])) {
            $this->log("[FORMAT-RECOVERY] 単一の計画オブジェクトを配列構造に自動ラップ救済しました。");
            $plan = [$plan];
        }

        if (!is_array($plan) || empty($plan) || !isset($plan[0]['table'])) {
            $this->log("[PLANNER-PARSE-FAILED] 計画JSONのパースに失敗したため、安全フォールバック回路が起動しました。対象質問: " . $this->searchQuery);

            if (preg_match('/(集計|件数|平均|合計|割合)/u', $this->searchQuery)) {
                $fallbackTable = 'project_csv_rows';
            } elseif (preg_match('/(会話|履歴|チャット|これまでの|まとめ)/u', $this->searchQuery)) {
                $fallbackTable = 'chat_history';
            } else {
                $fallbackTable = 'doc_chunks';
            }

            $fallbackPurpose = match ($fallbackTable) {
                'project_csv_rows' => 'CSV行データから質問に関連する集計・傾向を抽出する',
                'chat_history' => '過去の対話履歴から質問に関連する文脈を抽出する',
                default => '関連資料PDFの本文チャンクから主要な留意点・根拠を抽出する',
            };

            $plan = [[
                'step' => 1,
                'table' => $fallbackTable,
                'purpose' => $fallbackPurpose,
                'operation_type' => (string)call_user_func($this->inferOperationType, $this->searchQuery),
            ]];
        }

        $this->log("策定された実行計画ステップ数: " . count($plan));
        return $plan;
    }

    private function log(string $message): void
    {
        if ($this->logger !== null) {
            call_user_func($this->logger, $message);
        }
    }
}
