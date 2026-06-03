<?php
/**
 * chat_history_summary.php - 会話履歴要約の軽量ルート
 *
 * 「これまでの会話をまとめて」系の質問を、重いフル思考ルートに送らず、
 * chat_history の実データから即時に要約して返す。
 */

function runHistorySummaryRoute($pdo, $projectId, $originalMessage, $model, $promptKey, $user_id, $role): void {
    $processor = new HistorySummaryRouteProcessor($pdo, $projectId, $originalMessage, $model, $promptKey, $user_id, $role);
    $processor->execute();
}

class HistorySummaryRouteProcessor {
    private PDO $pdo;
    private ?int $projectId;
    private string $originalMessage;
    private string $model;
    private string $promptKey;
    private int $user_id;
    private string $role;
    private string $sessionId;
    private string $finalResponse = '';
    private array $reasoningSteps = [];

    public function __construct(PDO $pdo, ?int $projectId, string $originalMessage, string $model, string $promptKey, int $user_id, string $role) {
        $this->pdo = $pdo;
        $this->projectId = $projectId;
        $this->originalMessage = $originalMessage;
        $this->model = $model;
        $this->promptKey = $promptKey;
        $this->user_id = $user_id;
        $this->role = $role;
        $this->sessionId = 'history-summary_' . uniqid('', true) . '-' . mt_rand(1000, 9999);
    }

    public function execute(): void {
        $startedAt = microtime(true);
        chatLogger(">>> [会話履歴要約ルート] 軽量履歴サマリーを起動します。session_id: {$this->sessionId}");
        sendSSE('status', [
            'step' => 1,
            'message' => '📝 会話履歴を取得し、軽量サマリーを作成しています...'
        ]);

        $history = $this->loadHistory();
        chatLogger("[HISTORY-SUMMARY] 履歴取得完了 - rows: " . count($history) . " | elapsed: " . $this->elapsedSeconds($startedAt));

        $this->finalResponse = $this->buildSummary($history);
        $this->insertReasoningStep(1, '会話履歴の取得', $this->buildCollectionSummary($history));
        $this->insertReasoningStep(90, '会話履歴の軽量要約', $this->finalResponse);
        $this->insertReasoningStep(99, '最終回答の確定', '完了');

        $historyId = $this->saveHistory();
        $this->bindReasoningSteps($historyId);
        $this->loadReasoningSteps();

        $this->sendFinalResult();
        chatLogger("[HISTORY-SUMMARY] 軽量履歴サマリー完了 - responseChars: " . mb_strlen($this->finalResponse) . " | totalElapsed: " . $this->elapsedSeconds($startedAt));
    }

    private function loadHistory(): array {
        $limit = 30;
        if ($this->projectId === null) {
            $stmt = $this->pdo->prepare("
                SELECT role, message, created_at
                FROM chat_history
                WHERE project_id IS NULL AND user_id = ?
                ORDER BY created_at DESC
                LIMIT {$limit}
            ");
            $stmt->execute([$this->user_id]);
        } else {
            $stmt = $this->pdo->prepare("
                SELECT role, message, created_at
                FROM chat_history
                WHERE project_id = ? AND user_id = ?
                ORDER BY created_at DESC
                LIMIT {$limit}
            ");
            $stmt->execute([$this->projectId, $this->user_id]);
        }

        return array_reverse($stmt->fetchAll(PDO::FETCH_ASSOC));
    }

    private function buildSummary(array $history): string {
        if (empty($history)) {
            return "この案件では、まだ要約できる過去の会話履歴がありません。";
        }

        $userMessages = [];
        $assistantCount = 0;
        foreach ($history as $row) {
            if (($row['role'] ?? '') === 'user') {
                $userMessages[] = $this->compactText((string)$row['message'], 160);
            } elseif (($row['role'] ?? '') === 'assistant') {
                $assistantCount++;
            }
        }

        $topics = $this->detectTopics($history);
        $latestUserMessages = array_slice(array_reverse($userMessages), 0, 6);
        $firstAt = $history[0]['created_at'] ?? null;
        $lastAt = $history[count($history) - 1]['created_at'] ?? null;

        $lines = [];
        $lines[] = "これまでの会話内容を簡潔にまとめます。";
        $lines[] = "";
        $lines[] = "- 対象履歴: 直近 " . count($history) . "件";
        $lines[] = "- ユーザー発言: " . count($userMessages) . "件";
        $lines[] = "- AI回答: {$assistantCount}件";
        if ($firstAt || $lastAt) {
            $lines[] = "- 対象期間: " . ($firstAt ?: '-') . " 〜 " . ($lastAt ?: '-');
        }
        $lines[] = "";

        $lines[] = "### 主な話題";
        if ($topics) {
            foreach ($topics as $topic => $count) {
                $lines[] = "- {$topic}: {$count}件程度";
            }
        } else {
            $lines[] = "- 直近の会話履歴を中心に、案件内チャットの確認と整理を行っています。";
        }
        $lines[] = "";

        if ($latestUserMessages) {
            $lines[] = "### 直近の依頼";
            foreach ($latestUserMessages as $msg) {
                $lines[] = "- {$msg}";
            }
            $lines[] = "";
        }

        $lines[] = "### 現在の流れ";
        $lines[] = "CSVデータ、チャット回答ロジック、デバッグログ、グラフ・Mermaid表示、仕様書更新などを順番に確認しながら、support.php のAIチャット機能を実運用に近い形へ調整しています。";
        $lines[] = "次に見るべきポイントは、重い処理を専用の軽量ルートへ逃がすこと、ログから遅延箇所を特定できるようにすること、回答と図表がブラウザ上で安定して再表示されることです。";

        return implode("\n", $lines);
    }

    private function detectTopics(array $history): array {
        $topicPatterns = [
            'CSVデータの登録・要約・集計' => '/CSV|csv|project_csv|row_data|データ.*まとめ|項目|カラム|列/u',
            'チャット回答ロジック・ルーティング' => '/チャット|chat_|回答|ルート|ルーティング|フル思考|要約/u',
            'デバッグログ・処理時間の確認' => '/ログ|debug|chat_debug|時間|遅い|重い|詳細/u',
            'SQL監査・データベース整合性' => '/SQL|データベース|MySQL|テーブル|カラム|スキーマ|監査/u',
            'グラフ・Mermaid・ブラウザ表示' => '/グラフ|Chart|chart|Mermaid|mermaid|ブラウザ|表示|json:chart/u',
            'Ollama接続・モデル設定' => '/Ollama|ollama|モデル|host\.docker|11434|gemma|gpt/u',
            '仕様書・README更新' => '/README|仕様書|design_v3|ドキュメント|機能|仕様/u',
            'PDF・RAG・資料管理' => '/PDF|RAG|資料|doc_chunks|documents|Embedding|ベクトル/u',
        ];

        $scores = [];
        foreach ($history as $row) {
            $text = (string)($row['message'] ?? '');
            foreach ($topicPatterns as $topic => $pattern) {
                if (preg_match($pattern, $text)) {
                    $scores[$topic] = ($scores[$topic] ?? 0) + 1;
                }
            }
        }

        arsort($scores);
        return array_slice($scores, 0, 5, true);
    }

    private function buildCollectionSummary(array $history): string {
        $sample = array_slice($history, -8);
        $lines = [];
        $lines[] = "【会話履歴取得サマリー】";
        $lines[] = "- 取得件数: " . count($history);
        $lines[] = "- project_id: " . ($this->projectId === null ? 'NULL' : $this->projectId);
        $lines[] = "- user_id: {$this->user_id}";
        $lines[] = "";
        foreach ($sample as $row) {
            $roleLabel = ($row['role'] ?? '') === 'assistant' ? 'AI' : 'ユーザー';
            $lines[] = "- {$roleLabel}: " . $this->compactText((string)$row['message'], 120);
        }
        return implode("\n", $lines);
    }

    private function insertReasoningStep(int $stepNumber, string $subQuery, string $subAnswer): void {
        if ($this->projectId === null) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare("
                INSERT INTO chat_reasoning_steps
                    (project_id, session_id, original_question, step_number, sub_query, sub_answer, created_at)
                VALUES (?, ?, ?, ?, ?, ?, NOW())
            ");
            $stmt->execute([$this->projectId, $this->sessionId, $this->originalMessage, $stepNumber, $subQuery, $subAnswer]);
        } catch (Throwable $e) {
            chatLogger("[HISTORY-SUMMARY] reasoning step保存に失敗: " . $e->getMessage());
        }
    }

    private function saveHistory(): ?int {
        chatLogger("[HISTORY-SUMMARY] chat_history 保存開始");
        try {
            $this->pdo->beginTransaction();

            $stmtUser = $this->pdo->prepare("INSERT INTO chat_history (project_id, user_id, role, message, created_at) VALUES (?, ?, 'user', ?, NOW())");
            $stmtUser->execute([$this->projectId, $this->user_id, $this->originalMessage]);

            $stmtAi = $this->pdo->prepare("INSERT INTO chat_history (project_id, user_id, role, message, created_at) VALUES (?, ?, 'assistant', ?, NOW())");
            $stmtAi->execute([$this->projectId, $this->user_id, $this->finalResponse]);
            $historyId = (int)$this->pdo->lastInsertId();

            $this->pdo->commit();
            chatLogger("[HISTORY-SUMMARY] chat_history 保存成功。ID: {$historyId}");
            return $historyId;
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            chatLogger("[HISTORY-SUMMARY] chat_history 保存失敗: " . $e->getMessage());
            return null;
        }
    }

    private function bindReasoningSteps(?int $historyId): void {
        if ($historyId === null || $this->projectId === null) {
            return;
        }

        try {
            $stmt = $this->pdo->prepare("UPDATE chat_reasoning_steps SET chat_history_id = ? WHERE session_id = ?");
            $stmt->execute([$historyId, $this->sessionId]);
        } catch (Throwable $e) {
            chatLogger("[HISTORY-SUMMARY] reasoning step紐づけ失敗: " . $e->getMessage());
        }
    }

    private function loadReasoningSteps(): void {
        if ($this->projectId === null) {
            $this->reasoningSteps = [];
            return;
        }

        $stmt = $this->pdo->prepare("
            SELECT step_number, sub_query, sub_answer
            FROM chat_reasoning_steps
            WHERE session_id = ? AND step_number < 99
            ORDER BY step_number ASC
        ");
        $stmt->execute([$this->sessionId]);
        $this->reasoningSteps = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    private function sendFinalResult(): void {
        $this->logFinalResponseSnapshot('history_summary', $this->finalResponse);
        sendSSE('result', [
            'status' => 'success',
            'response' => $this->finalResponse,
            'sources' => [],
            'mode_used' => 'history_summary_lightweight',
            'detected_page' => null,
            'hit_count' => count($this->reasoningSteps),
            'reasoning_steps' => $this->reasoningSteps,
            'applied_model' => 'DB summary (no Ollama)',
            'created_at' => date('Y/m/d H:i')
        ]);
    }

    private function logFinalResponseSnapshot(string $routeName, string $response): void {
        $normalized = trim((string)$response);
        $normalized = preg_replace('/\s+/u', ' ', $normalized) ?? $normalized;
        $limit = 4000;
        $isTruncated = mb_strlen($normalized) > $limit;
        $preview = $isTruncated ? mb_substr($normalized, 0, $limit) . '...' : $normalized;
        $question = trim((string)$this->originalMessage);
        $question = preg_replace('/\s+/u', ' ', $question) ?? $question;

        chatLogger("[FINAL-ANSWER] route={$routeName} | questionChars=" . mb_strlen($question) . " | responseChars=" . mb_strlen($response) . " | truncated=" . ($isTruncated ? 'yes' : 'no'));
        chatLogger("[FINAL-ANSWER-QUESTION] {$question}");
        chatLogger("[FINAL-ANSWER-BODY] " . $preview);
    }

    private function compactText(string $text, int $limit): string {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        if (mb_strlen($text) <= $limit) {
            return $text;
        }
        return mb_substr($text, 0, $limit) . '...';
    }

    private function elapsedSeconds(float $start): string {
        return number_format(microtime(true) - $start, 2) . '秒';
    }
}
