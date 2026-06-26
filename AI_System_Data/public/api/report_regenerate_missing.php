<?php

declare(strict_types=1);

ob_start();

require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../src/Auth.php';
require_once __DIR__ . '/../../src/AppLogger.php';
require_once __DIR__ . '/../../src/DocumentFileIntegrityChecker.php';
require_once __DIR__ . '/../../src/ReportGenerator.php';
require_once __DIR__ . '/../../src/ReportRegenerationCandidateFinder.php';

$auth = new Auth($pdo);
$role = $_SESSION['role'] ?? 'user';
$sessionCsrf = $_SESSION['csrf_token'] ?? '';
$sessionUserId = (int)($_SESSION['user_id'] ?? 0);
session_write_close();

ob_end_clean();
header('Content-Type: application/json; charset=utf-8');

$jsonFlags = JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;

$sendJson = static function (array $payload, int $statusCode = 200) use ($jsonFlags): void {
    http_response_code($statusCode);
    $json = json_encode($payload, $jsonFlags);
    if ($json === false) {
        $payload = [
            'ok' => false,
            'success' => false,
            'error' => '再生成応答のJSON化に失敗しました: ' . json_last_error_msg(),
            'checked_at' => time(),
        ];
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
    echo $json;
    exit;
};

$fail = static function (string $error, int $statusCode, array $extra = []) use ($sendJson): void {
    $sendJson(array_merge([
        'ok' => false,
        'success' => false,
        'error' => $error,
    ], $extra), $statusCode);
};

if (!$auth->isLoggedIn()) {
    $fail('login_required', 401);
}

if ($role !== 'admin') {
    $fail('admin_required', 403);
}

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    $fail('post_required', 405);
}

$csrfToken = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
if ($sessionCsrf === '' || !hash_equals($sessionCsrf, $csrfToken)) {
    $fail('csrf_invalid', 403);
}

$rawBody = file_get_contents('php://input');
$decodedBody = json_decode(is_string($rawBody) ? $rawBody : '', true);
$documentId = (int)($_POST['document_id'] ?? (($decodedBody['document_id'] ?? 0)));

if ($documentId <= 0) {
    $fail('document_id_required', 400);
}

appLog('chat_debug.log', "[REPORT-REGENERATE] start old_document_id={$documentId}", [
    'old_document_id' => $documentId,
    'requested_by' => $sessionUserId,
]);

try {
    $basePath = realpath(__DIR__ . '/../../');
    if ($basePath === false) {
        throw new RuntimeException('base_path_not_resolved');
    }

    $finder = new ReportRegenerationCandidateFinder($pdo);
    $candidate = $finder->findByDocumentId($documentId);

    appLog('chat_debug.log', "[REPORT-REGENERATE] candidate old_document_id={$documentId}", [
        'old_document_id' => $documentId,
        'confidence' => (string)($candidate['confidence'] ?? 'none'),
        'mode' => (string)($candidate['mode'] ?? 'none'),
        'exact_replay' => !empty($candidate['exact_replay']) ? 1 : 0,
        'reason' => (string)($candidate['reason'] ?? ''),
    ]);

    if (empty($candidate['applicable'])) {
        $error = (string)($candidate['reason'] ?? 'candidate_not_applicable');
        $statusCode = $error === 'document_not_found' ? 404 : 422;
        $fail($error, $statusCode, [
            'old_document_id' => $documentId,
        ]);
    }

    if (($candidate['mode'] ?? '') !== 'near_rebuild') {
        $fail('candidate_mode_not_supported', 422, [
            'old_document_id' => $documentId,
            'mode' => (string)($candidate['mode'] ?? 'none'),
        ]);
    }

    if (!empty($candidate['exact_replay'])) {
        $fail('exact_replay_not_supported', 422, [
            'old_document_id' => $documentId,
        ]);
    }

    if (($candidate['confidence'] ?? '') !== 'high') {
        $fail('candidate_confidence_not_high', 422, [
            'old_document_id' => $documentId,
            'confidence' => (string)($candidate['confidence'] ?? 'none'),
        ]);
    }

    $checker = new DocumentFileIntegrityChecker($pdo, $basePath);
    $integrityResult = $checker->scanPdfDocuments();
    $integrityRecord = null;
    foreach ((array)($integrityResult['records'] ?? []) as $record) {
        if ((int)($record['document_id'] ?? 0) === $documentId) {
            $integrityRecord = $record;
            break;
        }
    }

    if (!is_array($integrityRecord)) {
        $fail('document_integrity_record_not_found', 404, [
            'old_document_id' => $documentId,
        ]);
    }

    if ((string)($integrityRecord['status'] ?? '') !== 'missing') {
        $fail('document_is_not_missing', 409, [
            'old_document_id' => $documentId,
            'status' => (string)($integrityRecord['status'] ?? 'unknown'),
        ]);
    }

    $projectId = (int)($candidate['project_id'] ?? 0);
    $assistantHistoryId = (int)($candidate['assistant_chat_history_id'] ?? 0);
    $userHistoryId = (int)($candidate['user_chat_history_id'] ?? 0);
    $reasoningSessionId = (string)($candidate['reasoning_session_id'] ?? '');

    if ($projectId <= 0 || $assistantHistoryId <= 0 || $userHistoryId <= 0) {
        $fail('candidate_history_link_missing', 422, [
            'old_document_id' => $documentId,
        ]);
    }

    $stmtAssistant = $pdo->prepare('
        SELECT id, project_id, thread_id, user_id, role, message, created_at
        FROM chat_history
        WHERE id = ? AND project_id = ? AND role = "assistant"
        LIMIT 1
    ');
    $stmtAssistant->execute([$assistantHistoryId, $projectId]);
    $assistantRow = $stmtAssistant->fetch(PDO::FETCH_ASSOC);
    if (!is_array($assistantRow)) {
        $fail('assistant_chat_history_not_found', 404, [
            'old_document_id' => $documentId,
            'assistant_chat_history_id' => $assistantHistoryId,
        ]);
    }

    $stmtUser = $pdo->prepare('
        SELECT id, project_id, thread_id, user_id, role, message, created_at
        FROM chat_history
        WHERE id = ? AND project_id = ? AND role = "user"
        LIMIT 1
    ');
    $stmtUser->execute([$userHistoryId, $projectId]);
    $userRow = $stmtUser->fetch(PDO::FETCH_ASSOC);
    if (!is_array($userRow)) {
        $fail('user_chat_history_not_found', 404, [
            'old_document_id' => $documentId,
            'user_chat_history_id' => $userHistoryId,
        ]);
    }

    $question = trim((string)($userRow['message'] ?? ''));
    $answer = trim((string)($assistantRow['message'] ?? ''));
    if ($question === '' || $answer === '') {
        $fail('chat_history_message_empty', 422, [
            'old_document_id' => $documentId,
        ]);
    }

    $reportUserId = (int)($assistantRow['user_id'] ?? 0);
    if ($reportUserId <= 0) {
        $reportUserId = (int)($userRow['user_id'] ?? 0);
    }
    if ($reportUserId <= 0) {
        $reportUserId = $sessionUserId;
    }

    $stmtEval = $pdo->prepare('
        SELECT
            proactivity_score,
            faithfulness_score,
            relevance_score,
            clarity_score,
            total_score,
            feedback,
            retry_count
        FROM chat_evaluations
        WHERE chat_id = ?
        ORDER BY id DESC
        LIMIT 1
    ');
    $stmtEval->execute([$assistantHistoryId]);
    $evalRow = $stmtEval->fetch(PDO::FETCH_ASSOC);

    $evaluation = null;
    if (is_array($evalRow)) {
        $evaluation = [
            'scores' => [
                'proactivity' => (int)($evalRow['proactivity_score'] ?? 0),
                'faithfulness' => (int)($evalRow['faithfulness_score'] ?? 0),
                'answer_relevance' => (int)($evalRow['relevance_score'] ?? 0),
                'clarity' => (int)($evalRow['clarity_score'] ?? 0),
            ],
            'total_score' => (int)($evalRow['total_score'] ?? 0),
            'feedback' => (string)($evalRow['feedback'] ?? ''),
            'retry_count' => (int)($evalRow['retry_count'] ?? 0),
            'evaluation_mode' => 'rebuild_from_history',
            'evaluation_source' => 'chat_evaluations',
        ];
    }

    $generator = new ReportGenerator(
        $pdo,
        $basePath,
        rtrim((string)(getenv('OLLAMA_HOST') ?: 'http://host.docker.internal:11434'), '/'),
        static function (string $message): void {
            appLog('chat_debug.log', $message);
        }
    );

    $reportDocument = $generator->createFromChat(
        $projectId,
        $assistantHistoryId,
        $reportUserId,
        $question,
        $answer,
        $evaluation,
        $reasoningSessionId !== '' ? $reasoningSessionId : null
    );

    $newDocumentId = (int)($reportDocument['document_id'] ?? 0);
    if ($newDocumentId <= 0) {
        throw new RuntimeException('report_generator_did_not_return_document_id');
    }

    appLog('chat_debug.log', "[REPORT-REGENERATE] created old_document_id={$documentId} new_document_id={$newDocumentId}", [
        'old_document_id' => $documentId,
        'new_document_id' => $newDocumentId,
        'mode' => 'near_rebuild',
        'exact_replay' => 0,
        'assistant_chat_history_id' => $assistantHistoryId,
        'user_chat_history_id' => $userHistoryId,
        'reasoning_session_id' => $reasoningSessionId,
    ]);

    $sendJson([
        'ok' => true,
        'success' => true,
        'old_document_id' => $documentId,
        'new_document_id' => $newDocumentId,
        'mode' => 'near_rebuild',
        'exact_replay' => false,
        'message' => 'missing AI報告書を新規documentsとして再生成しました。旧missing recordは保持されています。',
        'report_document' => [
            'document_id' => $newDocumentId,
            'title' => (string)($reportDocument['title'] ?? ''),
            'file_path' => (string)($reportDocument['file_path'] ?? ''),
            'converter' => (string)($reportDocument['converter'] ?? ''),
        ],
    ]);
} catch (Throwable $e) {
    appLog('chat_debug.log', "[REPORT-REGENERATE] failed old_document_id={$documentId} error=" . $e->getMessage(), [
        'old_document_id' => $documentId,
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine(),
    ]);
    $fail('report_regeneration_failed', 500, [
        'old_document_id' => $documentId,
    ]);
}
