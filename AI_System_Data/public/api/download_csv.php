<?php
/**
 * download_csv.php - 登録済みCSVをデータベースから再構築してダウンロードさせるAPI
 */

ob_start();

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/ProjectAccess.php';

$auth = new Auth($pdo);
if (!$auth->isLoggedIn()) {
    ob_end_clean();
    http_response_code(401);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'ログインが必要です。';
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? 'member');

session_write_close();
ob_end_clean();

$csvFileId = filter_input(INPUT_GET, 'csv_file_id', FILTER_VALIDATE_INT);
if (!$csvFileId) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo '有効なCSVファイルIDが指定されていません。';
    exit;
}

try {
    $stmtFile = $pdo->prepare('SELECT id, project_id, file_name, column_headers FROM project_csv_files WHERE id = ? LIMIT 1');
    $stmtFile->execute([$csvFileId]);
    $csvFile = $stmtFile->fetch(PDO::FETCH_ASSOC);

    if (!$csvFile) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '指定されたCSVファイルが見つかりません。';
        exit;
    }

    $projectId = (int)($csvFile['project_id'] ?? 0);
    if ($projectId <= 0 || !canAccessProject($pdo, $projectId, $userId, $role)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'このCSVデータをダウンロードする権限がありません。';
        exit;
    }

    $headers = json_decode((string)($csvFile['column_headers'] ?? ''), true);
    if (!is_array($headers)) {
        $headers = [];
    }
    $headers = array_values(array_filter(array_map(static fn($header): string => trim((string)$header), $headers), static fn(string $header): bool => $header !== ''));

    $downloadName = trim((string)($csvFile['file_name'] ?? 'download.csv'));
    if ($downloadName === '') {
        $downloadName = 'download.csv';
    }
    if (!preg_match('/\.csv$/iu', $downloadName)) {
        $downloadName .= '.csv';
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . rawurlencode($downloadName) . '"; filename*=UTF-8\'\'' . rawurlencode($downloadName));
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');

    $out = fopen('php://output', 'wb');
    if ($out === false) {
        throw new RuntimeException('CSV出力ストリームを開けませんでした。');
    }

    fwrite($out, "\xEF\xBB\xBF");

    if ($headers !== []) {
        fputcsv($out, $headers);
    }

    $stmtRows = $pdo->prepare('SELECT row_data FROM project_csv_rows WHERE csv_file_id = ? ORDER BY row_index ASC');
    $stmtRows->execute([$csvFileId]);

    while ($row = $stmtRows->fetch(PDO::FETCH_ASSOC)) {
        $rowData = json_decode((string)($row['row_data'] ?? ''), true);
        if (!is_array($rowData)) {
            $rowData = [];
        }

        if ($headers === []) {
            $headers = array_keys($rowData);
            if ($headers !== []) {
                fputcsv($out, $headers);
            }
        }

        $ordered = [];
        foreach ($headers as $header) {
            $value = $rowData[$header] ?? '';
            if (is_array($value)) {
                $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            }
            $ordered[] = (string)$value;
        }
        fputcsv($out, $ordered);
    }

    fclose($out);
    exit;
} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
    }
    echo 'CSVのダウンロードに失敗しました。';
    exit;
}
