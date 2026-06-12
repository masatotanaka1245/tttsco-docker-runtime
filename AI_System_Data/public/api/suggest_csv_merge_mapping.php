<?php
/**
 * suggest_csv_merge_mapping.php - CSV統合時の列名ゆれ候補を提案するAPI
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/ProjectAccess.php';
require_once __DIR__ . '/../../src/ModelRoleResolver.php';
require_once __DIR__ . '/../../src/OllamaChatHelper.php';

header('Content-Type: application/json; charset=utf-8');

function csvMergeNormalizeHeader(string $value): string
{
    $value = mb_strtolower(trim($value));
    $value = preg_replace('/[\s\-_\/()（）【】\[\]「」『』・,.，、:：]+/u', '', $value) ?? $value;
    return trim((string)$value);
}

function csvMergeLoadFile(PDO $pdo, int $projectId, int $csvFileId): ?array
{
    $stmt = $pdo->prepare('SELECT id, file_name, column_headers FROM project_csv_files WHERE id = ? AND project_id = ? LIMIT 1');
    $stmt->execute([$csvFileId, $projectId]);
    $file = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$file) {
        return null;
    }

    $headers = json_decode((string)($file['column_headers'] ?? '[]'), true);
    $file['headers'] = is_array($headers)
        ? array_values(array_filter(array_map(static fn($header): string => trim((string)$header), $headers), static fn(string $header): bool => $header !== ''))
        : [];

    return $file;
}

function csvMergeHeuristicMappings(array $mainHeaders, array $subHeaders): array
{
    $mainIndex = [];
    foreach ($mainHeaders as $mainHeader) {
        $mainIndex[csvMergeNormalizeHeader((string)$mainHeader)] = (string)$mainHeader;
    }

    $mappings = [];
    foreach ($subHeaders as $subHeader) {
        $subHeader = (string)$subHeader;
        $normalizedSub = csvMergeNormalizeHeader($subHeader);
        if ($normalizedSub === '') {
            continue;
        }

        if (isset($mainIndex[$normalizedSub])) {
            $mappings[] = [
                'sub_header' => $subHeader,
                'main_header' => $mainIndex[$normalizedSub],
                'reason' => '正規化後に完全一致',
            ];
            continue;
        }

        $bestMatch = null;
        foreach ($mainHeaders as $mainHeader) {
            $normalizedMain = csvMergeNormalizeHeader((string)$mainHeader);
            if ($normalizedMain === '') {
                continue;
            }

            if (
                (mb_strlen($normalizedSub) >= 2 && mb_strpos($normalizedMain, $normalizedSub) !== false) ||
                (mb_strlen($normalizedMain) >= 2 && mb_strpos($normalizedSub, $normalizedMain) !== false)
            ) {
                $bestMatch = (string)$mainHeader;
                break;
            }
        }

        if ($bestMatch !== null) {
            $mappings[] = [
                'sub_header' => $subHeader,
                'main_header' => $bestMatch,
                'reason' => '文字列の包含一致',
            ];
        }
    }

    return $mappings;
}

function csvMergeSuggestWithAi(string $ollamaHost, string $model, array $mainFile, array $subFiles, array $heuristicSuggestions): ?array
{
    if ($ollamaHost === '' || $model === '') {
        return null;
    }

    $system = <<<PROMPT
あなたはCSV統合補助の専門家です。
メインCSVの列名に対して、サブCSVの列名ゆれ候補を提案してください。
次のルールを守ってください。
- JSONのみ返す
- 実在しない列名は作らない
- main_header は必ずメインCSVの列名から選ぶ
- 対応不要な列は suggestions に含めない
- reason は20文字以内の短い日本語
- 返却形式:
{
  "suggestions": [
    {
      "sub_file_id": 1,
      "sub_file_name": "sample.csv",
      "mappings": [
        {"sub_header":"会社名","main_header":"顧客名","reason":"同義の可能性"}
      ]
    }
  ]
}
PROMPT;

    $user = json_encode([
        'main_file' => [
            'id' => (int)$mainFile['id'],
            'file_name' => (string)$mainFile['file_name'],
            'headers' => array_values($mainFile['headers'] ?? []),
        ],
        'sub_files' => array_map(static fn(array $file): array => [
            'id' => (int)$file['id'],
            'file_name' => (string)$file['file_name'],
            'headers' => array_values($file['headers'] ?? []),
        ], $subFiles),
        'heuristic_suggestions' => $heuristicSuggestions,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $payload = OllamaChatHelper::buildChatPayload($model, $system, $user, 'json', [
        'temperature' => 0.0,
        'top_p' => 0.1,
        'num_ctx' => 4096,
    ]);

    $ch = curl_init(rtrim($ollamaHost, '/') . '/api/chat');
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 4);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $httpCode !== 200 || $curlError !== '') {
        return null;
    }

    $decoded = json_decode((string)$response, true);
    $content = (string)($decoded['message']['content'] ?? '');
    if ($content === '') {
        return null;
    }

    $json = json_decode($content, true);
    return is_array($json) ? $json : null;
}

function csvMergeSanitizeSuggestions(array $rawSuggestions, array $mainFile, array $subFiles): array
{
    $mainHeaders = array_values($mainFile['headers'] ?? []);
    $subHeaderMap = [];
    foreach ($subFiles as $subFile) {
        $subHeaderMap[(int)$subFile['id']] = array_values($subFile['headers'] ?? []);
    }

    $normalized = [];
    foreach ($rawSuggestions as $fileSuggestion) {
        $subFileId = (int)($fileSuggestion['sub_file_id'] ?? 0);
        if ($subFileId <= 0 || !isset($subHeaderMap[$subFileId])) {
            continue;
        }

        $rows = [];
        foreach ((array)($fileSuggestion['mappings'] ?? []) as $mapping) {
            $subHeader = trim((string)($mapping['sub_header'] ?? ''));
            $mainHeader = trim((string)($mapping['main_header'] ?? ''));
            if ($subHeader === '' || $mainHeader === '') {
                continue;
            }
            if (!in_array($subHeader, $subHeaderMap[$subFileId], true) || !in_array($mainHeader, $mainHeaders, true)) {
                continue;
            }
            $rows[] = [
                'sub_header' => $subHeader,
                'main_header' => $mainHeader,
                'reason' => mb_substr(trim((string)($mapping['reason'] ?? '')), 0, 40),
            ];
        }

        $normalized[] = [
            'sub_file_id' => $subFileId,
            'sub_file_name' => (string)($fileSuggestion['sub_file_name'] ?? ''),
            'mappings' => $rows,
        ];
    }

    return $normalized;
}

$auth = new Auth($pdo);
if (!$auth->isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'ログインが必要です。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if (empty($token) || !isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRFトークンが正しくありません。'], JSON_UNESCAPED_UNICODE);
    exit;
}

$payload = json_decode(file_get_contents('php://input'), true);
$projectId = (int)($payload['project_id'] ?? 0);
$mainCsvFileId = (int)($payload['main_csv_file_id'] ?? 0);
$subCsvFileIds = $payload['sub_csv_file_ids'] ?? [];
$userId = (int)($_SESSION['user_id'] ?? 0);
$role = (string)($_SESSION['role'] ?? 'user');

if (!$projectId || !$mainCsvFileId || !canManageProject($pdo, $projectId, $userId, $role)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'この案件で列名候補を取得する権限がありません。'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!is_array($subCsvFileIds) || $subCsvFileIds === []) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'サブCSVを1件以上選択してください。'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $mainFile = csvMergeLoadFile($pdo, $projectId, $mainCsvFileId);
    if (!$mainFile) {
        throw new RuntimeException('メインCSVが見つかりません。');
    }

    $subFiles = [];
    foreach ($subCsvFileIds as $subCsvFileId) {
        $id = (int)$subCsvFileId;
        if ($id <= 0 || $id === $mainCsvFileId) {
            continue;
        }
        $file = csvMergeLoadFile($pdo, $projectId, $id);
        if ($file) {
            $subFiles[] = $file;
        }
    }

    if ($subFiles === []) {
        throw new RuntimeException('有効なサブCSVが見つかりません。');
    }

    $heuristicSuggestions = [];
    foreach ($subFiles as $subFile) {
        $heuristicSuggestions[] = [
            'sub_file_id' => (int)$subFile['id'],
            'sub_file_name' => (string)$subFile['file_name'],
            'mappings' => csvMergeHeuristicMappings($mainFile['headers'], $subFile['headers']),
        ];
    }

    $settings = ModelRoleResolver::resolveUserSettings($_SESSION);
    $aiResult = csvMergeSuggestWithAi(
        (string)($settings['ollama_host'] ?? ''),
        (string)($settings['sub_model'] ?? ''),
        $mainFile,
        $subFiles,
        $heuristicSuggestions
    );

    $suggestions = $heuristicSuggestions;
    $source = 'heuristic';
    if (is_array($aiResult) && isset($aiResult['suggestions']) && is_array($aiResult['suggestions'])) {
        $sanitized = csvMergeSanitizeSuggestions($aiResult['suggestions'], $mainFile, $subFiles);
        if ($sanitized !== []) {
            $suggestions = $sanitized;
        }
        $source = 'ai';
    }

    echo json_encode([
        'success' => true,
        'source' => $source,
        'suggestions' => $suggestions,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
