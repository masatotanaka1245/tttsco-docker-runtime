<?php
/**
 * ChatHistoryMaintenance.php - 案件単位のチャット履歴メンテナンス
 */

final class ChatHistoryMaintenance
{
    /**
     * 指定案件のチャット履歴と関連データを削除する。
     *
     * documents / doc_chunks / CSV / コメントは残し、
     * chat_history / chat_reasoning_steps / chat_evaluations / project_faqs を対象にする。
     *
     * @return array<string,int>
     */
    public static function clearProjectHistory(PDO $pdo, int $projectId): array
    {
        $counts = [
            'chat_history_deleted' => 0,
            'reasoning_steps_deleted' => 0,
            'evaluations_deleted' => 0,
            'faqs_deleted' => 0,
        ];

        $stmtChatIds = $pdo->prepare('SELECT id FROM chat_history WHERE project_id = ?');
        $stmtChatIds->execute([$projectId]);
        $chatIds = array_map('intval', $stmtChatIds->fetchAll(PDO::FETCH_COLUMN) ?: []);

        $pdo->beginTransaction();

        try {
            $stmtFaqs = $pdo->prepare('DELETE FROM project_faqs WHERE project_id = ?');
            $stmtFaqs->execute([$projectId]);
            $counts['faqs_deleted'] = $stmtFaqs->rowCount();

            if ($chatIds !== []) {
                $chatPlaceholders = implode(',', array_fill(0, count($chatIds), '?'));

                $stmtEval = $pdo->prepare("DELETE FROM chat_evaluations WHERE chat_id IN ($chatPlaceholders)");
                $stmtEval->execute($chatIds);
                $counts['evaluations_deleted'] = $stmtEval->rowCount();

                $stmtReasoning = $pdo->prepare(
                    "DELETE FROM chat_reasoning_steps WHERE project_id = ? OR chat_history_id IN ($chatPlaceholders)"
                );
                $stmtReasoning->execute(array_merge([$projectId], $chatIds));
                $counts['reasoning_steps_deleted'] = $stmtReasoning->rowCount();
            } else {
                $stmtReasoning = $pdo->prepare('DELETE FROM chat_reasoning_steps WHERE project_id = ?');
                $stmtReasoning->execute([$projectId]);
                $counts['reasoning_steps_deleted'] = $stmtReasoning->rowCount();
            }

            $stmtHistory = $pdo->prepare('DELETE FROM chat_history WHERE project_id = ?');
            $stmtHistory->execute([$projectId]);
            $counts['chat_history_deleted'] = $stmtHistory->rowCount();

            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $e;
        }

        return $counts;
    }
}
