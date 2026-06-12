<?php
/**
 * backfill_doc_chunk_summaries.php
 * 既存 doc_chunks.chunk_summary を後追い生成する保守スクリプト
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$args = array_slice($argv, 1);
$runAll = in_array('--all', $args, true);
$dryRun = in_array('--dry-run', $args, true);

$projectId = null;
foreach ($args as $arg) {
    if (str_starts_with((string)$arg, '--')) {
        continue;
    }
    $projectId = filter_var($arg, FILTER_VALIDATE_INT);
    if ($projectId && $projectId > 0) {
        break;
    }
}

if (!$runAll && (!$projectId || $projectId <= 0)) {
    fwrite(STDERR, "project_id is required (or use --all)\n");
    exit(1);
}

$basePath = realpath(__DIR__ . '/..');
if ($basePath === false) {
    fwrite(STDERR, "base path not found\n");
    exit(1);
}

require_once $basePath . '/config/database.php';
require_once $basePath . '/src/AppLogger.php';
require_once $basePath . '/src/DocChunkSummaryBuilder.php';

$sql = "SELECT c.id, c.doc_id, c.page_number, c.chunk_text, c.chunk_summary, c.image_description, d.project_id, d.title
        FROM doc_chunks c
        JOIN documents d ON d.id = c.doc_id";
$params = [];
if (!$runAll) {
    $sql .= " WHERE d.project_id = ?";
    $params[] = (int)$projectId;
}
$sql .= " ORDER BY d.project_id ASC, c.doc_id ASC, c.page_number ASC, c.id ASC";

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = [
    'run_all' => $runAll,
    'project_id' => $runAll ? null : (int)$projectId,
    'dry_run' => $dryRun,
    'checked' => 0,
    'updated' => 0,
    'unchanged' => 0,
    'examples' => [],
];

$updateStmt = $pdo->prepare('UPDATE doc_chunks SET chunk_summary = ? WHERE id = ?');

appLog('doc_chunk_summary.log', '[DOC-CHUNK] chunk_summary backfill started', [
    'run_all' => $runAll,
    'project_id' => $runAll ? null : (int)$projectId,
    'dry_run' => $dryRun,
]);

foreach ($rows as $row) {
    $summary['checked']++;
    $current = (string)($row['chunk_summary'] ?? '');
    $normalized = DocChunkSummaryBuilder::build(
        (string)($row['chunk_text'] ?? ''),
        (string)($row['image_description'] ?? '')
    );

    if ($normalized === $current) {
        $summary['unchanged']++;
        continue;
    }

    if (!$dryRun) {
        $updateStmt->execute([$normalized, (int)$row['id']]);
    }
    $summary['updated']++;

    if (count($summary['examples']) < 10) {
        $summary['examples'][] = [
            'chunk_id' => (int)$row['id'],
            'project_id' => (int)$row['project_id'],
            'doc_id' => (int)$row['doc_id'],
            'page_number' => (int)$row['page_number'],
            'title' => (string)($row['title'] ?? ''),
            'before' => $current,
            'after' => $normalized,
        ];
    }
}

appLog('doc_chunk_summary.log', '[DOC-CHUNK] chunk_summary backfill finished', $summary);
fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
exit(0);
