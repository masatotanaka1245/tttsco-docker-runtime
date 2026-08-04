<?php

$appRoot = getenv('TEPSCO_APP_ROOT') ?: __DIR__ . '/../AI_System_Data';
$source = file_get_contents($appRoot . '/public/api/chat.php');
if ($source === false) {
    throw new RuntimeException('chat.php could not be read');
}

foreach ([
    "http_response_code(409);",
    "'error_code' => 'CHAT_REQUEST_DUPLICATE'",
    "'status' => 'error'",
    'ChatRequestDuplicateGuard::acquire(',
    'register_shutdown_function('
] as $required) {
    if (!str_contains($source, $required)) {
        throw new RuntimeException('duplicate response contract missing: ' . $required);
    }
}

if (substr_count($source, 'ChatRequestDuplicateGuard::release(') < 3) {
    throw new RuntimeException('normal, exception, and shutdown release paths are required');
}

$lockPosition = strpos($source, 'ChatRequestDuplicateGuard::acquire(');
$dispatchPosition = strpos($source, '$dispatcher->dispatch(');
if ($lockPosition === false || $dispatchPosition === false || $lockPosition > $dispatchPosition) {
    throw new RuntimeException('duplicate guard must be acquired before route dispatch and history persistence');
}

$duplicateStart = strpos($source, "'error_code' => 'CHAT_REQUEST_DUPLICATE'");
$duplicateEnd = $duplicateStart === false ? false : strpos($source, 'exit;', $duplicateStart);
$duplicatePayload = ($duplicateStart === false || $duplicateEnd === false) ? '' : substr($source, $duplicateStart, $duplicateEnd - $duplicateStart);
if (str_contains($duplicatePayload, "'response' =>") || str_contains($duplicatePayload, '$requestId') || str_contains($duplicatePayload, "['file']")) {
    throw new RuntimeException('duplicate response must not expose a normal answer, request id, or lock path');
}

$frontend = file_get_contents($appRoot . '/public/assets/js/modules/chat.js');
$responseHandler = file_get_contents($appRoot . '/public/assets/js/modules/chatResponseHandler.js');
if ($frontend === false || $responseHandler === false
    || !str_contains($frontend, 'createChatResponseHandler')
    || !str_contains($frontend, 'responseHandler.handleHttpResponse(response)')
    || !str_contains($frontend, 'responseHandler.handleSsePayload(sseData)')
    || !str_contains($frontend, '!responseHandler.isDuplicateHandled()')
    || !str_contains($responseHandler, 'response?.status === 409')
    || !str_contains($responseHandler, "payload?.error_code === 'CHAT_REQUEST_DUPLICATE'")
    || !str_contains($responseHandler, 'onGateRelease?.()')) {
    throw new RuntimeException('frontend duplicate handling contract missing');
}

echo "ChatDuplicateResponseContractTest: ok\n";
