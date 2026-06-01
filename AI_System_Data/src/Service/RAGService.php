<?php
namespace App\Service;

use PDO;

class RAGService {
    private PDO $pdo;

    public function __construct(PDO $pdo) {
        $this->pdo = $pdo;
    }

    public function getContext(int $projectId, string $queryVectorJson): array {
        // 注: ベクトル検索を将来実装する際もここを修正するだけで済みます
        $sql = "SELECT c.chunk_text, c.page_number, d.title, d.id as doc_id 
                FROM doc_chunks c
                JOIN documents d ON c.doc_id = d.id
                WHERE d.project_id = ?
                LIMIT 3";
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$projectId]);
        $chunks = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $text = "";
        $sources = [];
        foreach ($chunks as $chunk) {
            $text .= "【参考: {$chunk['title']} (P.{$chunk['page_number']})】\n{$chunk['chunk_text']}\n\n";
            $sources[] = ["title" => $chunk['title'], "page" => $chunk['page_number'], "doc_id" => $chunk['doc_id']];
        }
        return ['text' => $text, 'sources' => $sources];
    }
}