<?php
/**
 * backfill_material_rag.php
 * 既存の案件資料メモ（Markdown）に対して、RAG用embeddingをバックフィルする保守スクリプト
 *
 * 使い方:
 *   php AI_System_Data/bin/backfill_material_rag.php 45
 *   php AI_System_Data/bin/backfill_material_rag.php --all
 *   php AI_System_Data/bin/backfill_material_rag.php 45 --csv-analysis-only
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

$args = array_slice($argv, 1);
$runAll = in_array('--all', $args, true);
$csvAnalysisOnly = in_array('--csv-analysis-only', $args, true);
$scope = $csvAnalysisOnly ? 'csv_analysis' : 'all';

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
require_once $basePath . '/src/ProjectMaterialDocumentService.php';

$service = new ProjectMaterialDocumentService($pdo, dirname($basePath));

try {
    appLog('material_rag.log', '[MATERIAL-RAG] manual backfill started', [
        'project_id' => $projectId,
        'run_all' => $runAll,
        'scope' => $scope,
    ]);

    $summary = $runAll
        ? $service->backfillAllProjectsEmbeddings($scope)
        : $service->backfillProjectEmbeddingsByScope((int)$projectId, $scope);

    appLog('material_rag.log', '[MATERIAL-RAG] manual backfill finished', $summary);
    fwrite(STDOUT, json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    exit(0);
} catch (Throwable $e) {
    appLog('material_rag.log', '[MATERIAL-RAG] manual backfill failed', [
        'project_id' => $projectId,
        'run_all' => $runAll,
        'scope' => $scope,
        'error' => $e->getMessage(),
    ]);
    fwrite(STDERR, $e->getMessage() . PHP_EOL);
    exit(1);
}
