<?php

require_once __DIR__ . '/../AI_System_Data/src/PromptManager.php';

function assertCompactHistory(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
}

function compactRows(array $rows): array
{
    return PromptManager::compactHistoryRows($rows, 8, 5, 3, 2, 180, 110, 900);
}

$fourPairs = [];
for ($index = 1; $index <= 4; $index++) {
    $fourPairs[] = ['role' => 'user', 'message' => "user{$index}"];
    $fourPairs[] = ['role' => 'assistant', 'message' => "assistant{$index}"];
}
$packet = compactRows($fourPairs);
assertCompactHistory($packet['selected_pair_count'] === 2, 'latest two pairs must be selected');
assertCompactHistory($packet['selected_message_count'] === 4, 'complete pairs must use four messages');
assertCompactHistory(str_contains($packet['summary'], 'user3') && str_contains($packet['summary'], 'assistant4'), 'latest pairs must remain chronological');

$unanswered = array_slice($fourPairs, 0, 6);
$unanswered[] = ['role' => 'user', 'message' => 'user4'];
$packet = compactRows($unanswered);
assertCompactHistory($packet['unanswered_user_count'] === 1, 'latest unanswered user must be detected');
assertCompactHistory($packet['selected_message_count'] === 5, 'two pairs and unanswered user must fit five messages');
assertCompactHistory(str_ends_with($packet['summary'], 'ユーザー: user4'), 'unanswered user must remain latest');

$packet = compactRows([
    ['role' => 'user', 'message' => 'user1'],
    ['role' => 'user', 'message' => 'user2'],
    ['role' => 'assistant', 'message' => 'assistant2'],
]);
assertCompactHistory($packet['complete_pair_count'] === 1 && $packet['unanswered_user_count'] === 1, 'consecutive users must not share an assistant');

$packet = compactRows([
    ['role' => 'assistant', 'message' => 'orphan'],
    ['role' => 'user', 'message' => 'user2'],
    ['role' => 'assistant', 'message' => 'assistant2'],
]);
assertCompactHistory($packet['complete_pair_count'] === 1 && str_contains($packet['summary'], 'assistant2'), 'orphan assistant must not prevent the following pair');

$longPacket = compactRows([
    ['role' => 'user', 'message' => str_repeat('u', 500)],
    ['role' => 'assistant', 'message' => str_repeat('a', 500)],
]);
assertCompactHistory($longPacket['compact_chars'] <= 900 && $longPacket['selected_pair_count'] === 1, 'long pair must stay complete within the limit');

$packet = compactRows([
    ['role' => 'user', 'message' => 'same'],
    ['role' => 'assistant', 'message' => 'answer-a'],
    ['role' => 'user', 'message' => 'same'],
    ['role' => 'assistant', 'message' => 'answer-b'],
]);
assertCompactHistory($packet['selected_pair_count'] === 2 && str_contains($packet['summary'], 'answer-a') && str_contains($packet['summary'], 'answer-b'), 'identical user text must remain separate pairs');

echo "PromptManagerCompactHistoryTest: ok\n";
