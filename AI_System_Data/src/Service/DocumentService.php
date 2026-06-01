<?php
namespace App\Service;

use PDO;
use Exception;

class DocumentService {
    private PDO $pdo;
    private ChatService $chatService;

    public function __construct(PDO $pdo, ChatService $chatService) {
        $this->pdo = $pdo;
        $this->chatService = $chatService;
    }

    /**
     * 日本語対応の安全なチャンク分割
     */
    public function splitText(string $text, int $size = 500, int $overlap = 100): array {
        $chunks = [];
        $textLength = mb_strlen($text, "UTF-8");
        for ($i = 0; $i < $textLength; $i += ($size - $overlap)) {
            $chunks[] = mb_substr($text, $i, $size, "UTF-8");
            if ($i + $size >= $textLength) break;
        }
        return $chunks;
    }

    /**
     * PDFからテキストを抽出してベクトル保存まで一括実行
     */
    public function processPdf(int $projectId, string $filePath, string $originalName): int {
        // 1. PDF解析 (pdftotext)
        $txtPath = $filePath . '.txt';
        $cmd = "pdftotext -layout " . escapeshellarg($filePath) . " " . escapeshellarg($txtPath);
        exec($cmd, $out, $ret);
        if ($ret !== 0) throw new Exception("PDFのテキスト抽出に失敗しました。");
        
        $text = file_get_contents($txtPath);
        unlink($txtPath); // 一時ファイル削除

        // 2. チャンク作成
        $chunks = $this->splitText($text);

        // 3. DB登録 (Document)
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare("INSERT INTO documents (project_id, title, file_path) VALUES (?, ?, ?)");
            $stmt->execute([$projectId, $originalName, $filePath]);
            $documentId = (int)$this->pdo->lastInsertId();

            // 4. ベクトル化と保存
            // ※APIリクエストが多いため、本来はループの外でAPIを叩くか、時間を考慮する
            foreach ($chunks as $index => $content) {
                $vector = $this->chatService->embedText($content); // 内部でOllama呼び出し
                $stmt = $this->pdo->prepare("INSERT INTO doc_chunks (doc_id, page_number, chunk_text, embedding) VALUES (?, ?, ?, ?)");
                $stmt->execute([$documentId, max(1, $index + 1), $content, $vector ?: json_encode([])]);
            }
            $this->pdo->commit();
            return $documentId;
        } catch (Exception $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
