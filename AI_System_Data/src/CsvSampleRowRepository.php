<?php

class CsvSampleRowRepository
{
    private $pdo;

    public function __construct(PDO $pdo)
    {
        $this->pdo = $pdo;
    }

    public function loadRowsForFile(int $csvFileId, int $limit = 12): array
    {
        $stmt = $this->pdo->prepare("
            SELECT row_index, row_data
            FROM project_csv_rows
            WHERE csv_file_id = ?
            ORDER BY row_index ASC
            LIMIT " . max(1, $limit) . "
        ");
        $stmt->execute([$csvFileId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
