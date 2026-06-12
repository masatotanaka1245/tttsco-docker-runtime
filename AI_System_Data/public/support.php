<?php

require_once __DIR__ . '/../src/SupportController.php';

// ★要件1: エスケープ関数 h() の完全二重定義防止ガードの最上部インジェクト
if (!function_exists('h')) {
    function h($str) {
        return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('supportPublicBasePath')) {
    function supportPublicBasePath(): string {
        $requestPath = (string)(parse_url((string)($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH) ?? '');
        $scriptName = (string)($_SERVER['SCRIPT_NAME'] ?? '/support.php');
        $candidate = $requestPath !== '' ? $requestPath : $scriptName;
        $basePath = rtrim(str_replace('\\', '/', dirname($candidate)), '/');

        if ($basePath === '/' || $basePath === '.') {
            return '';
        }

        return $basePath;
    }
}

if (!function_exists('formatChatThreadMeta')) {
    function formatChatThreadMeta($threadMetaAt, $messageCount) {
        $timestamp = trim((string)$threadMetaAt);
        $count = (int)$messageCount;
        $label = $timestamp !== '' ? date('m/d H:i', strtotime($timestamp)) : '履歴なし';
        return $label . ' ・ ' . $count . '件';
    }
}

if (!function_exists('getChatThreadButtonClasses')) {
    function getChatThreadButtonClasses(bool $isActiveThread): string {
        $base = 'chat-thread-tab flex min-w-0 items-start justify-between text-left transition-all duration-200 ease-in-out';
        $state = $isActiveThread
            ? 'border-slate-200 bg-white text-indigo-700 shadow-sm cursor-default'
            : 'border-slate-200 bg-slate-100/90 text-slate-600 hover:bg-slate-50 hover:text-slate-700';
        return $base . ' ' . $state;
    }
}

if (!function_exists('getChatThreadMetaClasses')) {
    function getChatThreadMetaClasses(bool $isActiveThread): string {
        $base = 'chat-thread-tab__meta text-[9px] font-medium';
        $state = $isActiveThread ? 'text-indigo-500' : 'text-slate-400';
        return $base . ' ' . $state;
    }
}

if (!function_exists('renderChatModeSwitches')) {
    function renderChatModeSwitches(array $options): void {
        foreach ($options as $option) {
            ?>
            <label class="chat-mode-switch" title="<?= h((string)$option['title']) ?>">
                <input type="checkbox" id="<?= h((string)$option['id']) ?>" class="sr-only peer">
                <span class="chat-mode-pill w-full justify-start <?= h((string)$option['checkedClass']) ?>">
                    <span class="text-[12px]" aria-hidden="true"><?= h((string)$option['icon']) ?></span>
                    <span><?= h((string)$option['label']) ?></span>
                </span>
            </label>
            <?php
        }
    }
}

if (!function_exists('renderPromptModeOptions')) {
    function renderPromptModeOptions(array $options, string $selectedMode): void {
        foreach ($options as $modeValue => $modeLabel) {
            ?>
            <option value="<?= h((string)$modeValue) ?>" <?= $selectedMode === (string)$modeValue ? 'selected' : '' ?>><?= h((string)$modeLabel) ?></option>
            <?php
        }
    }
}

if (!function_exists('renderModelOptions')) {
    function renderModelOptions(array $installedModels, string $activeModel): void {
        if (empty($installedModels)) {
            ?>
            <option value="">LLM未取得</option>
            <?php
            return;
        }

        foreach ($installedModels as $modelName) {
            ?>
            <option value="<?= h((string)$modelName) ?>" <?= $activeModel === (string)$modelName ? 'selected' : '' ?>><?= h((string)$modelName) ?></option>
            <?php
        }
    }
}

if (!function_exists('getChatMessageRowClasses')) {
    function getChatMessageRowClasses(string $role): string {
        return 'flex gap-3 items-start ' . ($role === 'assistant' ? '' : 'flex-row-reverse') . ' animate-fadeIn';
    }
}

if (!function_exists('getChatAvatarClasses')) {
    function getChatAvatarClasses(string $role): string {
        $base = 'w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0 shadow-2xs border select-none';
        $state = $role === 'assistant'
            ? 'bg-amber-50 text-amber-700 border-amber-200/40'
            : 'bg-indigo-50 text-indigo-700 border-indigo-200/40';
        return $base . ' ' . $state;
    }
}

if (!function_exists('getChatAvatarIcon')) {
    function getChatAvatarIcon(string $role): string {
        return $role === 'assistant' ? '🤖' : '👤';
    }
}

if (!function_exists('getChatMessageStackClasses')) {
    function getChatMessageStackClasses(string $role): string {
        return 'chat-message-stack flex flex-col ' . ($role === 'assistant' ? 'items-start' : 'items-end') . ' gap-0.5 w-full';
    }
}

if (!function_exists('getChatRoleLabel')) {
    function getChatRoleLabel(string $role): string {
        return $role === 'assistant' ? 'AI Assistant' : 'You';
    }
}

if (!function_exists('getChatBubbleClasses')) {
    function getChatBubbleClasses(string $role): string {
        $base = 'chat-message-bubble chat-message-body p-4 markdown-body chat-markdown shadow-2xs border';
        $state = $role === 'assistant'
            ? 'chat-assistant rounded-tl-none border-slate-100'
            : 'chat-user rounded-tr-none border-[#3b4773] shadow-xs';
        return $base . ' ' . $state;
    }
}

if (!function_exists('getChatReasoningJson')) {
    function getChatReasoningJson(array $reasoningSteps): string {
        return json_encode(
            $reasoningSteps,
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
        ) ?: '[]';
    }
}

if (!function_exists('renderSavedChatMessageContent')) {
    function renderSavedChatMessageContent(array $chat, array $reasoningStepsByChatId): void {
        $role = (string)($chat['role'] ?? '');
        $message = (string)($chat['message'] ?? '');

        if ($role === 'assistant') {
            $reasoningSteps = $reasoningStepsByChatId[(int)($chat['id'] ?? 0)] ?? [];
            $reasoningJson = getChatReasoningJson($reasoningSteps);
            ?>
            <div class="chat-raw-message-source hidden"><?= h($message) ?></div>
            <script type="application/json" class="chat-reasoning-source"><?= $reasoningJson ?></script>
            <div class="ai-text-body markdown-body chat-markdown w-full"></div>
            <?php
            return;
        }
        ?>
        <div class="chat-raw-message-source hidden"><?= h($message) ?></div>
        <div class="user-text-body w-full break-words"><?= h($message) ?></div>
        <?php
    }
}

if (!function_exists('renderChatThreadTabs')) {
    function renderChatThreadTabs(array $chatThreads, int $selectedThreadId): void {
        foreach ($chatThreads as $thread) {
            $threadId = (int)($thread['id'] ?? 0);
            $isActiveThread = $threadId === $selectedThreadId;
            $threadTitle = (string)($thread['title'] ?? ('会話 ' . $threadId));
            $threadMetaAt = (string)($thread['last_message_at'] ?: $thread['updated_at'] ?: $thread['created_at'] ?: '');
            ?>
            <div class="chat-thread-tab-group group">
                <button
                    type="button"
                    data-thread-switch
                    data-thread-id="<?= $threadId ?>"
                    aria-current="<?= $isActiveThread ? 'page' : 'false' ?>"
                    <?= $isActiveThread ? 'disabled' : '' ?>
                    onclick="<?= $isActiveThread ? 'return false;' : "if(typeof window.switchProjectChatThread === 'function') window.switchProjectChatThread($threadId)" ?>"
                    class="<?= h(getChatThreadButtonClasses($isActiveThread)) ?>"
                >
                    <span class="min-w-0">
                        <span data-thread-title class="chat-thread-tab__title text-[11px] font-bold"><?= h($threadTitle) ?></span>
                        <span class="<?= h(getChatThreadMetaClasses($isActiveThread)) ?>">
                            <?= h(formatChatThreadMeta($threadMetaAt, $thread['message_count'] ?? 0)) ?>
                        </span>
                    </span>
                </button>
                <?php if (count($chatThreads) > 1): ?>
                    <button
                        type="button"
                        onclick="if(typeof window.deleteProjectChatThread === 'function') window.deleteProjectChatThread(<?= $threadId ?>)"
                        class="chat-thread-tab-delete inline-flex items-center justify-center border border-transparent text-[13px] font-bold leading-none text-slate-300 transition-all duration-200 ease-in-out hover:border-red-100 hover:bg-red-50 hover:text-red-500"
                        title="このスレッドを削除"
                        aria-label="このスレッドを削除"
                    >×</button>
                <?php endif; ?>
            </div>
            <?php
        }
        ?>
        <button
            type="button"
            onclick="if(typeof window.createProjectChatThread === 'function') window.createProjectChatThread()"
            class="chat-thread-tab-create inline-flex items-center justify-center border border-dashed border-slate-300 px-3 text-[11px] font-black text-slate-500 transition-all duration-200 ease-in-out hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-700 active:scale-95"
            title="新しい会話スレッドを作成"
            aria-label="新しい会話スレッドを作成"
        >＋</button>
        <?php
    }
}

if (!function_exists('renderProjectMemoryFlash')) {
    function renderProjectMemoryFlash(string $memoryFlash): void {
        if ($memoryFlash === '1') {
            echo '<div class="text-[11px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3">案件運用メモを更新しました。</div>';
            return;
        }
        if ($memoryFlash === 'refreshed') {
            echo '<div class="text-[11px] font-bold text-indigo-700 bg-indigo-50 border border-indigo-200 rounded-xl px-4 py-3">自動生成メモを再生成しました。</div>';
            return;
        }
        if ($memoryFlash === 'error') {
            echo '<div class="text-[11px] font-bold text-red-700 bg-red-50 border border-red-200 rounded-xl px-4 py-3">案件運用メモの保存に失敗しました。</div>';
            return;
        }
        if ($memoryFlash === 'csrf_error' || $memoryFlash === 'forbidden') {
            echo '<div class="text-[11px] font-bold text-amber-700 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">案件運用メモを更新する権限がありません。</div>';
        }
    }
}

if (!function_exists('renderProjectMemoryEditors')) {
    function renderProjectMemoryEditors(array $projectMemoryDocs): void {
        $fields = [
            ['type' => 'readme', 'id' => 'memory-readme', 'name' => 'memory_readme', 'label' => '案件内容', 'placeholder' => '案件の背景、用語、前提、構成など'],
            ['type' => 'agents', 'id' => 'memory-agents', 'name' => 'memory_agents', 'label' => 'AIエージェント', 'placeholder' => '回答方針、禁止事項、優先ルールなど'],
            ['type' => 'todo', 'id' => 'memory-todo', 'name' => 'memory_todo', 'label' => 'タスク一覧', 'placeholder' => '既知の課題、次に見るべき点、現在の運用メモなど'],
        ];

        foreach ($fields as $field) {
            $content = (string)($projectMemoryDocs[$field['type']]['content'] ?? '');
            $autoContent = (string)($projectMemoryDocs[$field['type']]['auto_content'] ?? '');
            ?>
            <div class="space-y-1.5">
                <label for="<?= h($field['id']) ?>" class="block text-[10px] font-black text-slate-400 tracking-wider"><?= h($field['label']) ?></label>
                <textarea id="<?= h($field['id']) ?>" name="<?= h($field['name']) ?>" rows="8" class="w-full min-h-[11rem] border border-slate-200 rounded-xl p-3 text-xs leading-5 bg-slate-50/50 focus:bg-white focus:border-indigo-400/80 transition-all duration-200 resize-y font-mono text-slate-700 outline-none" placeholder="<?= h($field['placeholder']) ?>"><?= h($content) ?></textarea>
                <?php if (trim($autoContent) !== ''): ?>
                    <div class="border border-dashed border-slate-200 rounded-xl bg-slate-50/70 overflow-hidden">
                        <div class="px-3 py-2 bg-slate-100/70 text-[10px] font-black text-slate-400 tracking-wider">自動生成メモ（参考表示）</div>
                        <div class="px-3 py-2.5 text-[11px] leading-5 text-slate-500 whitespace-pre-wrap max-h-48 overflow-y-auto custom-scrollbar"><?= h($autoContent) ?></div>
                    </div>
                <?php endif; ?>
            </div>
            <?php
        }
    }
}

if (!function_exists('renderProjectMemoryReadonly')) {
    function renderProjectMemoryReadonly(array $projectMemoryDocs): void {
        $hasContent = false;
        foreach (['readme', 'agents', 'todo'] as $memoryType) {
            $memoryContent = trim((string)($projectMemoryDocs[$memoryType]['content'] ?? ''));
            $autoMemoryContent = trim((string)($projectMemoryDocs[$memoryType]['auto_content'] ?? ''));
            if ($memoryContent === '' && $autoMemoryContent === '') {
                continue;
            }
            $hasContent = true;
            ?>
            <div class="border border-slate-200 rounded-xl overflow-hidden">
                <div class="px-4 py-2 bg-slate-50 text-[10px] font-black text-slate-400 tracking-wider"><?= h((string)($projectMemoryDocs[$memoryType]['label'] ?? strtoupper($memoryType))) ?></div>
                <?php if ($memoryContent !== ''): ?>
                    <div class="px-4 py-2 border-b border-slate-100 bg-white">
                        <div class="text-[10px] font-black text-slate-400 tracking-wider mb-1">手動メモ</div>
                        <div class="text-xs text-slate-600 leading-relaxed whitespace-pre-wrap"><?= h($memoryContent) ?></div>
                    </div>
                <?php endif; ?>
                <?php if ($autoMemoryContent !== ''): ?>
                    <div class="px-4 py-3 bg-slate-50/60">
                        <div class="text-[10px] font-black text-slate-400 tracking-wider mb-1">自動生成メモ</div>
                        <div class="text-xs text-slate-500 leading-relaxed whitespace-pre-wrap"><?= h($autoMemoryContent) ?></div>
                    </div>
                <?php endif; ?>
            </div>
            <?php
        }

        if (!$hasContent) {
            ?>
            <div class="text-center py-10 bg-slate-50/60 rounded-xl border border-dashed border-slate-200">
                <p class="text-xs text-slate-400 font-medium italic">案件運用メモはまだ登録されていません。</p>
            </div>
            <?php
        }
    }
}

if (!function_exists('renderProjectMaterialFlash')) {
    function renderProjectMaterialFlash(string $materialFlash): void {
        if ($materialFlash === '1') {
            echo '<div class="text-[11px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3">資料メモを更新しました。</div>';
            return;
        }
        if ($materialFlash === 'deleted') {
            echo '<div class="text-[11px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3">資料メモを削除しました。</div>';
            return;
        }
        if ($materialFlash === 'empty') {
            echo '<div class="text-[11px] font-bold text-amber-700 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">資料メモの内容が空です。</div>';
            return;
        }
        if ($materialFlash === 'not_found') {
            echo '<div class="text-[11px] font-bold text-amber-700 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">対象の資料メモが見つかりませんでした。</div>';
            return;
        }
        if ($materialFlash === 'error') {
            echo '<div class="text-[11px] font-bold text-red-700 bg-red-50 border border-red-200 rounded-xl px-4 py-3">資料メモの保存に失敗しました。</div>';
            return;
        }
        if ($materialFlash === 'csrf_error' || $materialFlash === 'forbidden') {
            echo '<div class="text-[11px] font-bold text-amber-700 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">資料メモを更新する権限がありません。</div>';
        }
    }
}

if (!function_exists('renderProjectMaterialItems')) {
    function renderProjectMaterialItems(array $materialDocuments, int $projectId, ?int $selectedDocumentId): void {
        if (empty($materialDocuments)) {
            ?>
            <div class="text-center py-10 bg-slate-50/60 rounded-xl border border-dashed border-slate-200">
                <p class="text-xs text-slate-400 font-medium italic">資料メモはまだ登録されていません。</p>
            </div>
            <?php
            return;
        }

        foreach ($materialDocuments as $document) {
            $documentId = (int)($document['id'] ?? 0);
            $isActive = $selectedDocumentId !== null && $documentId === $selectedDocumentId;
            $href = 'support.php?project_id=' . urlencode((string)$projectId) . '&tab=materials&material_doc_id=' . urlencode((string)$documentId);
            $modifiedAt = (string)($document['material_modified_at'] ?? $document['created_at'] ?? '');
            ?>
            <a
                href="<?= h($href) ?>"
                data-material-document-id="<?= $documentId ?>"
                class="block rounded-xl border px-4 py-3 shadow-2xs transition-all duration-200 ease-in-out <?= $isActive ? 'border-indigo-300 bg-indigo-50/80' : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50/70' ?>"
            >
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="text-xs font-bold text-slate-700 truncate"><?= h((string)($document['title'] ?? '資料メモ')) ?></div>
                        <div class="mt-1 text-[10px] text-slate-400 font-medium">
                            <?= $modifiedAt !== '' ? h(date('Y/m/d H:i', strtotime($modifiedAt))) : '更新時刻なし' ?>
                        </div>
                    </div>
                    <span class="text-[9px] bg-slate-100 border border-slate-200 px-2 py-0.5 rounded font-mono text-slate-400 font-bold">MD</span>
                </div>
            </a>
            <?php
        }
    }
}

if (!function_exists('renderProjectOverviewRows')) {
    function renderProjectOverviewRows(array $currentProject): void {
        $projectPeriod = (!empty($currentProject['start_date']) || !empty($currentProject['end_date']))
            ? h((string)$currentProject['start_date']) . ' ～ ' . h((string)$currentProject['end_date'])
            : '<span class="text-slate-400 italic font-normal">未設定</span>';
        ?>
        <tr>
            <th class="p-4 bg-slate-50/40 w-36 font-bold text-slate-400 text-[11px] tracking-wider uppercase">業務名</th>
            <td class="p-4 font-black text-slate-800 text-sm"><?= h((string)$currentProject['project_name']) ?></td>
        </tr>
        <tr>
            <th class="p-4 bg-slate-50/40 font-bold text-slate-400 text-[11px] tracking-wider uppercase">業務期間</th>
            <td class="p-4 font-semibold text-slate-600"><?= $projectPeriod ?></td>
        </tr>
        <tr>
            <th class="p-4 bg-slate-50/40 font-bold text-slate-400 text-[11px] tracking-wider uppercase">業務概要</th>
            <td class="p-4 leading-relaxed whitespace-pre-wrap font-medium text-slate-600"><?= h((string)($currentProject['description'] ?? '') ?: '未入力') ?></td>
        </tr>
        <tr>
            <th class="p-4 bg-slate-50/40 align-top font-bold text-slate-400 text-[11px] tracking-wider uppercase">場所・住所</th>
            <td class="p-4">
                <div class="mb-3 font-semibold text-slate-700"><?= h((string)($currentProject['address'] ?? '') ?: '未登録') ?></div>
                <?php if (!empty($currentProject['latitude']) && !empty($currentProject['longitude'])): ?>
                    <div id="overview-map" class="w-full h-52 rounded-xl border border-slate-200 overview-map-container mt-2 shadow-inner"></div>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
}

if (!function_exists('getMemberRoleBadgeClasses')) {
    function getMemberRoleBadgeClasses(string $memberRole): string {
        return $memberRole === 'manager'
            ? 'bg-purple-50 text-purple-700 border border-purple-200'
            : 'bg-emerald-50 text-emerald-700 border border-emerald-200';
    }
}

if (!function_exists('renderProjectMemberRows')) {
    function renderProjectMemberRows(array $members): void {
        if (empty($members)) {
            ?>
            <tr><td colspan="4" class="p-12 text-center text-slate-400 italic font-medium">アサインされたメンバーはいません。</td></tr>
            <?php
            return;
        }

        foreach ($members as $member) {
            $memberRole = (string)($member['role'] ?? '');
            ?>
            <tr class="hover:bg-slate-50/50 transition-colors duration-150 group">
                <td class="p-4 font-bold text-slate-700 flex items-center gap-2.5">
                    <div class="w-6 h-6 rounded-full bg-slate-100 border border-slate-200/50 flex items-center justify-center text-[10px]">👤</div>
                    <?= h((string)($member['username'] ?? '')) ?>
                </td>
                <td class="p-4 text-slate-500 font-medium"><?= h((string)($member['department'] ?? '未設定')) ?></td>
                <td class="p-4">
                    <span class="px-2.5 py-1 rounded-md text-[9px] font-black uppercase tracking-wider <?= h(getMemberRoleBadgeClasses($memberRole)) ?>">
                        <?= h($memberRole) ?>
                    </span>
                </td>
                <td class="p-4 text-center">
                    <button type="button" onclick="if(typeof window.handleRemoveMember === 'function') window.handleRemoveMember(<?= (int)($member['user_id'] ?? 0) ?>)" class="text-slate-300 hover:text-red-500 hover:bg-red-50 w-7 h-7 rounded-lg flex items-center justify-center mx-auto opacity-0 group-hover:opacity-100 transition-all duration-200 ease-in-out transform active:scale-90" title="プロジェクトから外す">🗑️</button>
                </td>
            </tr>
            <?php
        }
    }
}

if (!function_exists('renderProjectCommentItems')) {
    function renderProjectCommentItems(array $comments, int $userId, string $role): void {
        if (empty($comments)) {
            ?>
            <div class="text-center py-12 bg-white rounded-2xl border border-dashed border-slate-200 shadow-2xs">
                <p class="text-3xl mb-2 opacity-30">💭</p>
                <p class="text-xs text-slate-400 font-medium italic">まだコメントはありません。<br>プロジェクトに関するメモや進捗を共有しましょう。</p>
            </div>
            <?php
            return;
        }

        foreach ($comments as $comment) {
            ?>
            <div id="comment-container-<?= (int)$comment['id'] ?>" class="bg-white p-4 px-5 rounded-2xl border border-slate-200 shadow-2xs animate-fadeIn hover:shadow-sm transition-shadow duration-200">
                <div class="flex justify-between items-start mb-2">
                    <div class="flex items-center gap-2.5">
                        <div class="w-6 h-6 rounded-full bg-slate-100 flex items-center justify-center text-[10px] font-black text-slate-500 border border-slate-200/60 shadow-2xs">
                            <?= mb_substr(h((string)$comment['username']), 0, 1) ?>
                        </div>
                        <span class="font-bold text-xs text-slate-700"><?= h((string)$comment['username']) ?></span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-[10px] font-mono font-medium text-slate-400"><?= date('Y/m/d H:i', strtotime((string)$comment['created_at'])) ?></span>
                        <?php if ((int)$comment['user_id'] === $userId || $role === 'admin'): ?>
                            <button type="button" onclick="if(typeof window.handleRemoveComment === 'function') window.handleRemoveComment(<?= (int)$comment['id'] ?>)" class="text-slate-300 hover:text-red-500 hover:bg-red-50 w-6 h-6 rounded-lg flex items-center justify-center transition-all duration-200 ease-in-out transform active:scale-90" title="コメントを削除">🗑️</button>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="text-xs text-slate-600 font-medium pt-1 leading-relaxed pl-8"><?= makeClickableLinks((string)$comment['comment_text']) ?></div>
            </div>
            <?php
        }
    }
}

if (!function_exists('renderFaqCards')) {
    function renderFaqCards(array $faqs, int $userId, string $role): void {
        foreach ($faqs as $faq) {
            ?>
            <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-2xs relative group hover:shadow-sm transition-shadow duration-200">
                <?php if ((int)$faq['created_by'] === $userId || $role === 'admin'): ?>
                    <button type="button" onclick="if(typeof window.handleDeleteFaq === 'function') window.handleDeleteFaq(<?= (int)$faq['id'] ?>)" class="absolute top-3 right-3 text-slate-300 hover:text-red-500 hover:bg-red-50 w-7 h-7 rounded-lg flex items-center justify-center opacity-0 group-hover:opacity-100 transition-all duration-200 ease-in-out transform active:scale-90" title="ナレッジを削除">🗑️</button>
                <?php endif; ?>
                <div class="font-extrabold text-slate-800 text-xs mb-3 pb-2 border-b border-slate-100 pr-8 leading-relaxed">Q. <?= h((string)$faq['question_summary']) ?></div>
                <div class="text-xs text-slate-600 font-medium leading-loose whitespace-pre-wrap"><?= h((string)$faq['answer_summary']) ?></div>
            </div>
            <?php
        }
    }
}

if (!function_exists('renderFaqEmptyState')) {
    function renderFaqEmptyState(): void {
        ?>
        <div id="faq-empty-state" class="text-center py-16 bg-white rounded-2xl border border-dashed border-slate-200 shadow-2xs">
            <p class="text-3xl mb-3 opacity-40">💡</p>
            <p class="text-xs text-slate-400 font-bold leading-relaxed">チャットの回答にある「📌 ナレッジとして共有」ボタンから、<br>得られた有益な知見をチーム全体へシェアできます。</p>
        </div>
        <?php
    }
}

if (!function_exists('renderPdfAnalysisModeOptions')) {
    function renderPdfAnalysisModeOptions(): void {
        $options = [
            ['value' => 'auto', 'label' => '⚡ 自動判定 (本文高速 + 図表補足)'],
            ['value' => 'tiles', 'label' => '🔲 標準 (2x2タイル分割)'],
            ['value' => 'slices', 'label' => '🥞 水平スライス (8分割)'],
            ['value' => 'full', 'label' => '📄 全体のみ (高速・軽量)'],
            ['value' => 'all', 'label' => '🧠 フル解析 (高精度)'],
        ];

        foreach ($options as $option) {
            ?>
            <option value="<?= h((string)$option['value']) ?>" <?= $option['value'] === 'auto' ? 'selected' : '' ?>><?= h((string)$option['label']) ?></option>
            <?php
        }
    }
}

if (!function_exists('renderPdfDocumentItems')) {
    function renderPdfDocumentItems(array $documents): void {
        $publicBasePath = supportPublicBasePath();
        foreach ($documents as $doc) {
            $docId = (int)($doc['id'] ?? 0);
            $docTitle = (string)($doc['title'] ?? '');
            $viewerCacheKey = urlencode((string)strtotime((string)($doc['created_at'] ?? 'now')));
            ?>
            <details class="bg-white border border-slate-200 rounded-2xl shadow-2xs group overflow-hidden transition-all duration-300 ease-in-out hover:shadow-sm">
                <summary class="p-3.5 px-5 flex items-center gap-2.5 overflow-hidden pr-2 cursor-pointer hover:bg-slate-50/50 transition-colors duration-200 ease-in-out outline-none select-none">
                    <span class="group-open:rotate-90 transition-transform duration-200 ease-in-out text-slate-400 text-[10px] w-4 text-center">▶</span>
                    <span class="text-xs font-bold text-slate-700 group-hover:text-[#4F5D95] transition-colors duration-200 truncate">📄 <?= h($docTitle) ?></span>
                </summary>
                <div class="px-5 pb-3 flex justify-end items-center gap-2 flex-wrap bg-white">
                    <button type="button" onclick="if(typeof window.openPdfTab === 'function') { window.openPdfTab(<?= $docId ?>, '<?= h(str_replace("'", "\\'", $docTitle)) ?>', 1); }" class="text-[9px] text-[#4F5D95] hover:bg-indigo-50 border border-slate-200 px-2.5 py-1 rounded-lg font-bold transition-all duration-200 ease-in-out shadow-2xs transform active:scale-95">↗ 別タブで開く</button>
                    <span class="text-[9px] bg-slate-100 border border-slate-200 px-2 py-0.5 rounded font-mono text-slate-400 font-bold">PDF</span>
                    <button type="button" data-doc-id="<?= $docId ?>" class="btn-delete-pdf text-slate-440 hover:text-red-500 hover:bg-red-50 w-7 h-7 rounded-lg flex items-center justify-center transition-all duration-200 ease-in-out transform active:scale-90" title="この資料を完全に削除">🗑️</button>
                </div>
                <div class="h-[580px] border-t border-slate-100 bg-slate-50 p-2">
                    <iframe src="<?= h($publicBasePath) ?>/api/view_pdf.php?id=<?= h((string)$docId) ?>&_=<?= h($viewerCacheKey) ?>#page=1" class="w-full h-full border-none rounded-xl shadow-inner bg-white" loading="lazy"></iframe>
                </div>
            </details>
            <?php
        }
    }
}

if (!function_exists('renderPdfEmptyState')) {
    function renderPdfEmptyState(): void {
        ?>
        <div id="pdf-empty-state" class="text-center py-12 bg-white rounded-2xl border border-dashed border-slate-200 shadow-2xs">
            <p class="text-3xl mb-2 opacity-35">📭</p>
            <p class="text-xs text-slate-400 font-medium italic">登録されているPDF資料はありません。<br>上部のアプローダーから資料を追加してください。</p>
        </div>
        <?php
    }
}

if (!function_exists('renderCsvHistoryItems')) {
    function renderCsvHistoryItems(array $csvFiles): void {
        if (empty($csvFiles)) {
            ?>
            <p class="text-[10px] text-slate-400 text-center py-8 italic font-medium">登録済みのCSVはありません。</p>
            <?php
            return;
        }

        foreach ($csvFiles as $csvFile) {
            $csvId = (int)($csvFile['id'] ?? 0);
            $csvName = (string)($csvFile['file_name'] ?? '');
            ?>
            <div id="csv-item-<?= $csvId ?>" onclick="if(typeof window.loadCsvData === 'function') window.loadCsvData(<?= $csvId ?>, '<?= h(str_replace("'", "\\'", $csvName)) ?>')" class="p-3 bg-white border border-slate-200 rounded-xl hover:border-[#00758F] hover:shadow-md cursor-pointer shadow-2xs transition-all duration-200 ease-in-out group transform hover:-translate-y-0.5 active:scale-98">
                <div class="text-xs font-bold text-slate-700 truncate group-hover:text-[#00758F] transition-colors duration-150 mb-1.5" title="📄 <?= h($csvName) ?>">📄 <?= h($csvName) ?></div>
                <div class="flex justify-between items-center text-[9px] text-slate-400 font-medium">
                    <span class="font-mono bg-slate-50 px-1.5 py-0.5 rounded border border-slate-100 font-bold"><?= number_format((int)($csvFile['row_count'] ?? 0)) ?> rows</span>
                    <span><?= date('m/d H:i', strtotime((string)$csvFile['created_at'])) ?></span>
                </div>
            </div>
            <?php
        }
    }
}

if (!function_exists('renderCsvViewerPlaceholder')) {
    function renderCsvViewerPlaceholder(): void {
        ?>
        <div class="text-center py-20 bg-white rounded-2xl border border-dashed border-slate-200 shadow-2xs h-full flex flex-col justify-center items-center">
            <p class="text-4xl mb-3 opacity-25">📊</p>
            <p class="text-xs text-slate-400 font-bold leading-relaxed">左側のインポート一覧からCSVファイルを選択するか、<br>上部の接続メニューからデータを取り込んでください。</p>
        </div>
        <?php
    }
}

$chatQuickActions = [
    [
        'icon' => '📊',
        'label' => 'データ集計',
        'prompt' => 'CSVデータを集計して詳細を教えてください。',
    ],
    [
        'icon' => '🔍',
        'label' => '資料抽出',
        'prompt' => 'PDFから概要を抽出し、詳細をまとめてください。',
    ],
    [
        'icon' => '📝',
        'label' => '文脈総括',
        'prompt' => 'これまでの会話内容を詳しくまとめてください。',
    ],
];

$promptModeOptions = [
    'construction_consultant' => '🏗️ 建設',
    'technical_expert' => '🔬 技術',
    'proofreader' => '📝 校正',
    'general_chat' => '💬 会話',
];

$chatOutputOptions = [
    [
        'id' => 'diagram-mode',
        'icon' => '📈',
        'label' => '図解',
        'title' => '必要に応じてMermaidやChart.jsの図表を回答に含めます',
        'checkedClass' => 'peer-checked:border-emerald-300 peer-checked:bg-emerald-50 peer-checked:text-emerald-700 peer-checked:shadow-sm',
    ],
    [
        'id' => 'report-mode',
        'icon' => '📄',
        'label' => '報告書',
        'title' => '回答をHTML/CSS報告書としてPDF化し、PDFタブと検索対象へ登録します',
        'checkedClass' => 'peer-checked:border-amber-300 peer-checked:bg-amber-50 peer-checked:text-amber-700 peer-checked:shadow-sm',
    ],
    [
        'id' => 'csv-export-mode',
        'icon' => '🧾',
        'label' => 'CSV化',
        'title' => '回答内の表を生成CSVとして保存し、CSVタブへ登録します',
        'checkedClass' => 'peer-checked:border-cyan-300 peer-checked:bg-cyan-50 peer-checked:text-cyan-700 peer-checked:shadow-sm',
    ],
];

$adminOutputOptions = [
    [
        'id' => 'advanced-reasoning-mode',
        'icon' => '🧠',
        'label' => 'フル思考',
        'title' => 'AIが質問を要素分解し、個別に資料を精読してから統合回答を作成します',
        'checkedClass' => 'peer-checked:border-indigo-300 peer-checked:bg-indigo-50 peer-checked:text-indigo-700 peer-checked:shadow-sm',
    ],
];

$projectCenterTabs = [
    ['key' => 'overview', 'icon' => '🏠', 'label' => '概要', 'full_label' => '概要', 'count' => null],
    ['key' => 'materials', 'icon' => '📝', 'label' => '資料', 'full_label' => '資料メモ', 'count' => count($material_documents ?? [])],
    ['key' => 'pdf', 'icon' => '📄', 'label' => 'PDF', 'full_label' => '資料PDF', 'count' => count($documents ?? [])],
    ['key' => 'comments', 'icon' => '💬', 'label' => 'コメント', 'full_label' => 'コメント', 'count' => count($comments ?? [])],
    ['key' => 'csv', 'icon' => '📊', 'label' => 'CSV', 'full_label' => 'CSVデータ', 'count' => count($csv_files ?? [])],
    ['key' => 'faqs', 'icon' => '📚', 'label' => 'ナレッジ', 'full_label' => 'AIナレッジ・FAQ', 'count' => null],
    ['key' => 'members', 'icon' => '👥', 'label' => 'メンバー', 'full_label' => 'メンバー設定', 'count' => null],
    ['key' => 'memory', 'icon' => '🗂️', 'label' => '運用メモ', 'full_label' => '案件運用メモ', 'count' => null],
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>業務支援 | TEPSCO Routines</title>
    <meta name="csrf-token" content="<?= h($csrfToken) ?>">
    
    <script src="<?= !empty($URL_TAILWIND) ? h($URL_TAILWIND) : 'https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4' ?>"></script>
    <script src="<?= !empty($URL_MARKED) ? h($URL_MARKED) : 'https://cdn.jsdelivr.net/npm/marked/marked.min.js' ?>"></script>
    <script src="<?= !empty($URL_CHART_JS) ? h($URL_CHART_JS) : 'https://cdn.jsdelivr.net/npm/chart.js' ?>"></script>
    <script src="<?= !empty($URL_MERMAID) ? h($URL_MERMAID) : 'https://cdn.jsdelivr.net/npm/mermaid@10.9.5/dist/mermaid.min.js' ?>"></script>
    <script src="<?= !empty($URL_DOMPURIFY) ? h($URL_DOMPURIFY) : 'https://cdn.jsdelivr.net/npm/dompurify/dist/purify.min.js' ?>"></script>
    <script>
        window.__tepscoMermaidReady = false;
        window.__tepscoInitMermaid = function() {
            if (typeof window.mermaid === 'undefined') return false;
            if (window.__tepscoMermaidReady) return true;

            window.mermaid.parseError = function(err) {
                console.warn('[Mermaid skipped]', err && err.message ? err.message : err);
            };

            window.mermaid.initialize({
                startOnLoad: false,
                securityLevel: 'strict',
                theme: 'default',
                logLevel: 'fatal',
                suppressErrorRendering: true
            });
            window.__tepscoMermaidReady = true;
            return true;
        };
    </script>
    
    <link rel="stylesheet" href="<?= !empty($URL_LEAFLET_CSS) ? h($URL_LEAFLET_CSS) : 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css' ?>" />
    <script src="<?= !empty($URL_LEAFLET_JS) ? h($URL_LEAFLET_JS) : 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js' ?>"></script>
    <link rel="stylesheet" href="assets/css/styles.css?v=11">
    <style>
        .tab-content {
            display: none;
            width: 100%;
            height: 100%;
            min-height: 0;
        }
        .tab-content.active {
            display: block;
        }
        #tab-materials.active {
            display: flex;
            flex-direction: column;
            min-height: 0;
        }
        
        .tab-btn.active { 
            background-color: #ffffff; 
            color: #4F5D95; 
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05); 
            border-bottom: 2px solid #4F5D95;
            font-weight: 800; 
            opacity: 1; 
        }
        
        :root {
            --support-width: 410px;
            --support-sidebar-width: 16rem;
            --support-sidebar-collapsed-width: 4.75rem;
        }
        .overview-map-container { position: relative; z-index: 0; }
        details > summary { list-style: none; }
        details > summary::-webkit-details-marker { display: none; }

        .ai-think-block {
            background-color: #f8fafc;
            border-left: 3px solid #cbd5e1;
            padding: 8px 12px;
            border-radius: 8px;
            font-size: 11px;
            color: #475569;
            margin-bottom: 8px;
        }
        .no-scrollbar::-webkit-scrollbar { display: none; }
        .no-scrollbar { -ms-overflow-style: none; scrollbar-width: none; }

        #chat-input:focus,
        input:focus,
        select:focus {
            outline: none !important;
            box-shadow: none !important;
            border-color: transparent !important;
        }

        #support-sidebar {
            width: var(--support-sidebar-width);
            transition: width 0.24s ease, padding 0.24s ease;
        }
        html:not(.support-sidebar-ready) #support-sidebar,
        html:not(.support-sidebar-ready) #support-sidebar-toggle-icon {
            transition: none !important;
        }
        #support-sidebar .support-sidebar-heading {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
        }
        #support-sidebar .support-project-link {
            display: flex;
            align-items: flex-start;
            gap: 0.75rem;
        }
        #support-sidebar .support-project-badge {
            width: 2rem;
            height: 2rem;
            border-radius: 0.9rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #475569;
            font-size: 11px;
            font-weight: 900;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.04);
        }
        #support-sidebar .support-project-copy {
            min-width: 0;
            flex: 1;
        }
        #support-sidebar .support-sidebar-footer-icon {
            display: none;
        }
        #support-sidebar-toggle {
            width: 2rem;
            height: 2rem;
            border-radius: 0.9rem;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
        }
        #support-sidebar-toggle-icon {
            transition: transform 0.24s ease;
        }

        html.sidebar-collapsed #support-sidebar,
        body.sidebar-collapsed #support-sidebar {
            width: var(--support-sidebar-collapsed-width);
            padding-left: 0.75rem;
            padding-right: 0.75rem;
        }
        html.sidebar-collapsed #support-sidebar .support-sidebar-title,
        html.sidebar-collapsed #support-sidebar .support-project-copy,
        html.sidebar-collapsed #support-sidebar .support-sidebar-footer-label,
        body.sidebar-collapsed #support-sidebar .support-sidebar-title,
        body.sidebar-collapsed #support-sidebar .support-project-copy,
        body.sidebar-collapsed #support-sidebar .support-sidebar-footer-label {
            display: none;
        }
        html.sidebar-collapsed #support-sidebar .support-sidebar-heading,
        body.sidebar-collapsed #support-sidebar .support-sidebar-heading {
            justify-content: center;
        }
        html.sidebar-collapsed #support-sidebar .support-project-link,
        body.sidebar-collapsed #support-sidebar .support-project-link {
            justify-content: center;
            padding-left: 0.45rem;
            padding-right: 0.45rem;
        }
        html.sidebar-collapsed #support-sidebar .support-project-badge,
        body.sidebar-collapsed #support-sidebar .support-project-badge {
            width: 2.25rem;
            height: 2.25rem;
            font-size: 12px;
        }
        html.sidebar-collapsed #support-sidebar .support-project-create-btn,
        body.sidebar-collapsed #support-sidebar .support-project-create-btn {
            justify-content: center;
            padding-left: 0.5rem;
            padding-right: 0.5rem;
        }
        html.sidebar-collapsed #support-sidebar .support-sidebar-footer-icon,
        body.sidebar-collapsed #support-sidebar .support-sidebar-footer-icon {
            display: inline-flex;
        }
        html.sidebar-collapsed #support-sidebar-toggle-icon,
        body.sidebar-collapsed #support-sidebar-toggle-icon {
            transform: rotate(180deg);
        }

        #tab-header {
            flex-wrap: nowrap;
            overflow-x: auto;
            overflow-y: hidden;
            scrollbar-width: none;
        }
        #tab-header::-webkit-scrollbar { display: none; }
        #tab-header .tab-btn {
            display: inline-flex;
            align-items: center;
            gap: 0.45rem;
            flex: 0 0 auto;
            min-width: 0;
            max-width: clamp(78px, 12vw, 126px);
        }
        #tab-header .tab-btn__icon {
            flex: 0 0 auto;
            width: 0.9rem;
            text-align: center;
        }
        #tab-header .tab-btn__label {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        #tab-header .tab-btn__count {
            flex: 0 0 auto;
            min-width: 1.15rem;
            padding: 0 0.25rem;
            border-radius: 999px;
            border: 1px solid #e2e8f0;
            background: rgba(255, 255, 255, 0.72);
            color: #94a3b8;
            font-size: 9px;
            font-weight: 800;
            line-height: 1.35;
            text-align: center;
        }

        .chat-thread-tabs {
            display: flex;
            align-items: flex-end;
            gap: 0.15rem;
            overflow-x: auto;
            overflow-y: hidden;
            padding: 0 0.15rem;
            scrollbar-width: none;
        }
        .chat-thread-tabs::-webkit-scrollbar { display: none; }
        .chat-thread-tab-group {
            position: relative;
            flex: 0 0 auto;
            min-width: 0;
        }
        .chat-thread-tab {
            min-width: 0;
            max-width: 184px;
            padding: 0.45rem 1.85rem 0.4rem 0.75rem;
            border-radius: 0.9rem 0.9rem 0 0;
            border: 1px solid #e2e8f0;
            border-bottom: none;
            background: #eef2f7;
            box-shadow: inset 0 -1px 0 rgba(226, 232, 240, 0.85);
        }
        .chat-thread-tab__title {
            display: block;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }
        .chat-thread-tab__meta {
            display: block;
            margin-top: 0.1rem;
            white-space: nowrap;
        }
        .chat-thread-tab-delete,
        .chat-thread-tab-create {
            flex: 0 0 auto;
        }
        .chat-thread-tab-delete {
            position: absolute;
            top: 0.22rem;
            right: 0.4rem;
            width: 1.15rem;
            height: 1.15rem;
            border-radius: 999px;
        }
        .chat-thread-tab-create {
            height: 1.95rem;
            border-radius: 0.9rem 0.9rem 0 0;
            border-bottom: none;
            background: #f8fafc;
        }
        .chat-output-options {
            position: relative;
            overflow: visible;
        }
        .chat-output-options > summary {
            list-style: none;
        }
        .chat-output-options > summary::-webkit-details-marker {
            display: none;
        }
        .chat-output-options-panel {
            position: absolute;
            right: 0;
            bottom: calc(100% + 0.5rem);
            width: min(19rem, calc(100vw - 3rem));
            border: 1px solid #e2e8f0;
            border-radius: 1rem;
            background: rgba(255, 255, 255, 0.98);
            box-shadow: 0 16px 40px rgba(15, 23, 42, 0.12);
            backdrop-filter: blur(8px);
            z-index: 25;
        }
        .chat-footer-toolbar {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 0.75rem;
        }
        .chat-footer-toolbar__left,
        .chat-footer-toolbar__right {
            min-width: 0;
        }
        .chat-footer-toolbar__left {
            flex: 1 1 auto;
        }
        .chat-footer-toolbar__right {
            flex: 0 0 auto;
            display: flex;
            justify-content: flex-end;
        }
        .chat-output-options-trigger {
            display: inline-flex;
            align-items: center;
            gap: 0.5rem;
            border-radius: 999px;
            border: 1px solid #cbd5e1;
            background: linear-gradient(180deg, #ffffff 0%, #f8fafc 100%);
            padding: 0.375rem 0.875rem;
            color: #475569;
            box-shadow: 0 1px 2px rgba(15, 23, 42, 0.06);
        }
        .chat-output-options-group {
            display: flex;
            flex-direction: column;
            gap: 0.5rem;
        }
        .chat-box-shell {
            position: relative;
            flex: 1 1 auto;
            min-height: 0;
        }
        .chat-clear-overlay-btn {
            position: absolute;
            top: 0.75rem;
            right: 0.75rem;
            z-index: 12;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 0.35rem;
            min-height: 1.9rem;
            padding: 0 0.6rem;
            border-radius: 0.7rem;
            border: 1px solid rgba(148, 163, 184, 0.45);
            background: rgba(255, 255, 255, 0.88);
            color: #94a3b8;
            box-shadow: 0 4px 14px rgba(15, 23, 42, 0.08);
            backdrop-filter: blur(6px);
        }
        .chat-clear-overlay-btn:hover {
            border-color: rgba(239, 68, 68, 0.25);
            background: rgba(254, 242, 242, 0.95);
            color: #ef4444;
        }

        @media (max-width: 1280px) {
            #tab-header .tab-btn {
                max-width: 112px;
            }
            .chat-thread-tab {
                max-width: 148px;
            }
        }

        @media (max-width: 1024px) {
            #tab-header .tab-btn {
                max-width: 88px;
                padding-left: 0.65rem;
                padding-right: 0.65rem;
            }
            #tab-header .tab-btn__count {
                display: none;
            }
            .chat-thread-tab {
                max-width: 126px;
            }
            .chat-thread-tab__meta {
                display: none;
            }
        }

        @media (max-width: 860px) {
            #tab-header .tab-btn {
                max-width: 54px;
                font-size: 10px;
                justify-content: center;
                padding-left: 0.55rem;
                padding-right: 0.55rem;
                gap: 0;
            }
            #tab-header .tab-btn__label {
                display: none;
            }
            .chat-footer-toolbar {
                flex-direction: column;
                align-items: stretch;
            }
            .chat-footer-toolbar__right {
                justify-content: flex-end;
            }
        }
    </style>

    <script>
        (function() {
            try {
                if (window.localStorage.getItem('supportSidebarCollapsed') === '1') {
                    document.documentElement.classList.add('sidebar-collapsed');
                }
            } catch (e) {}
        })();

        window.switchTab = function(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
            const tab = document.getElementById(tabId);
            if(tab) tab.classList.add('active');
            const btn = document.getElementById('btn-' + tabId.replace('tab-', ''));
            if(btn) btn.classList.add('active');
        };
    </script>
</head>
<body class="bg-[#f8fafc] min-h-screen flex flex-col overflow-hidden text-slate-800">

<div id="support-toast-container" class="fixed top-4 right-4 z-[120] flex w-full max-w-sm flex-col gap-2 px-4 sm:px-0 pointer-events-none"></div>

<?php include_once __DIR__ . '/templates/header.php'; ?>

<div id="support-config"
     data-csrf-token="<?= h($csrfToken) ?>"
     data-project-id="<?= h((string)$selected_project_id) ?>"
     data-public-base="<?= h(supportPublicBasePath()) ?>"
     data-selected-material-document-id="<?= h((string)($selected_material_document['id'] ?? '')) ?>"
     data-thread-id="<?= h((string)$selected_thread_id) ?>"
     data-can-manage-material="<?= $can_manage_material_documents ? '1' : '0' ?>"
     data-can-manage-memory="<?= $can_manage_project_memory ? '1' : '0' ?>"
     data-can-debug-log="<?= $role === 'admin' ? '1' : '0' ?>"></div>

<main class="flex-1 flex overflow-hidden h-[calc(100vh-72px)] gap-px bg-slate-200/50 w-full" role="region" aria-label="Support System Console">
    
    <div id="support-sidebar" class="bg-white flex flex-col p-4 border-r border-slate-200/60 flex-shrink-0" role="navigation">
        <div class="support-sidebar-heading px-1 pb-3 mb-2 border-b border-slate-100">
            <h2 class="support-sidebar-title text-[11px] font-black text-slate-400 uppercase tracking-widest">業務一覧</h2>
            <button
                id="support-sidebar-toggle"
                type="button"
                class="border border-slate-200 bg-slate-50 text-slate-500 hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-700 transition-all duration-200 shadow-2xs"
                aria-label="業務一覧を折りたたむ"
                title="業務一覧を折りたたむ"
            >
                <span id="support-sidebar-toggle-icon" aria-hidden="true">◀</span>
            </button>
        </div>
        <div class="flex-1 overflow-y-auto space-y-1.5 pr-1 no-scrollbar" role="list">
            <?php foreach ($projects as $p): ?>
                <?php $isProjFocused = ($selected_project_id == $p['id']); ?>
                <?php $projectBadge = mb_substr((string)$p['project_name'], 0, 1); ?>
                <a href="?project_id=<?= h((string)$p['id']) ?>" title="<?= h($p['project_name']) ?>" class="support-project-link block p-3 rounded-xl transition-all duration-200 ease-in-out <?= $isProjFocused ? 'bg-slate-100 text-slate-900 font-extrabold shadow-2xs' : 'hover:bg-slate-50 text-slate-600 border-transparent' ?>">
                    <span class="support-project-badge <?= $isProjFocused ? 'bg-indigo-50 border-indigo-200 text-indigo-700' : '' ?>"><?= h($projectBadge) ?></span>
                    <span class="support-project-copy">
                        <p class="text-xs leading-snug truncate <?= $isProjFocused ? 'text-slate-900 font-black' : 'text-slate-600 font-medium' ?>"><?= h($p['project_name']) ?></p>
                        <p class="text-[9px] text-slate-400 mt-1.5 font-medium tracking-tight">Update: <?= date('m/d H:i', strtotime($p['updated_at'])) ?></p>
                    </span>
                </a>
            <?php endforeach; ?>
        </div>
        <button onclick="if(typeof window.openAppModal === 'function') window.openAppModal('project-modal')" class="support-project-create-btn mt-4 p-3 bg-slate-50 text-slate-600 rounded-xl text-xs font-bold border border-slate-200 hover:bg-indigo-50/50 hover:text-[#4F5D95] hover:border-indigo-200 transition-all duration-200 shadow-2xs transform active:scale-98">
            <span class="support-sidebar-footer-icon" aria-hidden="true">＋</span>
            <span class="support-sidebar-footer-label">+ 新規案件登録</span>
        </button>
    </div>

    <?php if ($current_project): ?>
    <div class="flex-1 bg-white flex flex-col overflow-hidden" role="region">
        <?php $threadQuery = $selected_thread_id ? '&thread_id=' . urlencode((string)$selected_thread_id) : ''; ?>

        <div class="bg-slate-50/80 border-b border-slate-200/60 flex items-end gap-0.5 px-2.5 pt-2.5 flex-shrink-0" id="tab-header" role="tablist">
            <?php foreach ($projectCenterTabs as $tabItem): ?>
                <?php
                    $tabKey = (string)$tabItem['key'];
                    $tabCount = $tabItem['count'];
                    $isActiveProjectTab = $active_tab === $tabKey;
                    $tabFullLabel = (string)($tabItem['full_label'] ?? $tabItem['label']);
                ?>
                <button
                    onclick="if(typeof window.switchTab === 'function') window.switchTab('tab-<?= h($tabKey) ?>'); history.replaceState(null, '', '?project_id=<?= h((string)$selected_project_id) ?>&tab=<?= h($tabKey) ?><?= $threadQuery ?>')"
                    id="btn-<?= h($tabKey) ?>"
                    role="tab"
                    class="tab-btn <?= $isActiveProjectTab ? 'active' : '' ?> px-3 py-2 text-[11px] font-bold text-slate-500 rounded-t-xl transition-all duration-200 ease-in-out transform active:scale-98"
                    title="<?= h($tabFullLabel) ?>"
                    aria-label="<?= h($tabFullLabel) ?>"
                >
                    <span class="tab-btn__icon" aria-hidden="true"><?= h((string)$tabItem['icon']) ?></span>
                    <span class="tab-btn__label"><?= h((string)$tabItem['label']) ?></span>
                    <?php if ($tabCount !== null): ?>
                        <span class="tab-btn__count"><?= (int)$tabCount ?></span>
                    <?php endif; ?>
                </button>
            <?php endforeach; ?>
        </div>

        <div class="flex-1 overflow-hidden relative bg-[#f8fafc]" id="tab-container">
            
            <div id="tab-overview" role="tabpanel" class="tab-content <?= $active_tab === 'overview' ? 'active' : '' ?> h-full overflow-y-auto p-6 space-y-6">
                <div class="bg-white border border-slate-200/80 rounded-2xl overflow-hidden shadow-sm transition-all duration-300 hover:shadow-md">
                    <div class="bg-slate-50/70 p-3.5 px-5 font-bold text-slate-700 flex justify-between items-center text-xs border-b border-slate-100">
                        <span class="font-extrabold tracking-wide text-slate-600">業務基本情報</span>
                        <div class="flex gap-2">
                            <?php
                                $prefillData = [
                                    'id' => (int)$current_project['id'],
                                    'name' => $current_project['project_name'],
                                    'description' => $current_project['description'] ?? '',
                                    'address' => $current_project['address'] ?? '',
                                    'start_date' => $current_project['start_date'] ?? '',
                                    'end_date' => $current_project['end_date'] ?? ''
                                ];
                            ?>
                            <script type="application/json" id="prefill-project-data"><?= json_encode($prefillData, JSON_UNESCAPED_UNICODE) ?></script>
                            <button onclick="if(typeof window.openProjectEditModal === 'function') window.openProjectEditModal(<?= (float)($current_project['latitude'] ?: 0) ?>, <?= (float)($current_project['longitude'] ?: 0) ?>)"
                                class="text-[#4F5D95] hover:bg-indigo-50 border border-slate-200 px-3 py-1.5 rounded-xl text-[10px] font-bold bg-white shadow-2xs transition-all duration-200 ease-in-out transform active:scale-95">📝 編集</button>
                            <button id="btn-delete-project" class="text-red-500 hover:bg-red-50 border border-slate-200 px-3 py-1.5 rounded-xl text-[10px] font-bold bg-white shadow-2xs transition-all duration-200 ease-in-out transform active:scale-95">🗑️ 案件を削除</button>
                        </div>
                    </div>
                    <div class="p-2 text-xs text-slate-800">
                        <table class="w-full text-left border-collapse">
                            <tbody class="divide-y divide-slate-100">
                                <?php renderProjectOverviewRows($current_project); ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>

            <div id="tab-materials" role="tabpanel" class="tab-content <?= $active_tab === 'materials' ? 'active' : '' ?> h-full min-h-0 overflow-hidden p-6">
                <div class="bg-white border border-slate-200/80 rounded-2xl overflow-hidden shadow-sm transition-all duration-300 hover:shadow-md h-full min-h-0 flex flex-col">
                    <div class="bg-slate-50/70 p-3.5 px-5 font-bold text-slate-700 flex justify-between items-center text-xs border-b border-slate-100">
                        <div class="flex items-center gap-3">
                            <span class="font-extrabold tracking-wide text-slate-600">資料メモ</span>
                            <span class="text-[10px] text-slate-400 font-bold tracking-wider">Markdownで保存 / documents連携 / RAG対象</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span id="material-document-count" class="text-[10px] font-black text-slate-400 bg-white border border-slate-200 px-2.5 py-0.5 rounded-full shadow-2xs"><?= count($material_documents) ?> 件</span>
                            <?php if ($can_manage_material_documents): ?>
                                <button type="button" id="material-new-button" onclick="if(typeof window.openMaterialNoteModal === 'function') window.openMaterialNoteModal('new')" class="text-[10px] bg-white text-slate-600 border border-slate-200 px-3 py-1.5 rounded-xl font-bold shadow-2xs hover:bg-slate-50 transition-all duration-200 ease-in-out">＋ 新規資料</button>
                                <button type="button" id="material-edit-button" onclick="if(typeof window.openMaterialNoteModal === 'function') window.openMaterialNoteModal('edit')" class="text-[10px] bg-[#4F5D95] text-white border border-[#4F5D95] px-3 py-1.5 rounded-xl font-bold shadow-2xs hover:bg-[#3f4a7a] transition-all duration-200 ease-in-out disabled:opacity-40 disabled:cursor-not-allowed" <?= empty($selected_material_document['id']) ? 'disabled' : '' ?>>編集</button>
                                <form method="post" id="material-delete-form" class="contents">
                                    <input type="hidden" name="action" value="delete_project_material">
                                    <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                    <input type="hidden" name="project_id" value="<?= h((string)$selected_project_id) ?>">
                                    <input type="hidden" id="material-delete-document-id" name="material_document_id" value="<?= h((string)($selected_material_document['id'] ?? '')) ?>">
                                    <button type="submit" id="material-delete-button" class="text-[10px] bg-white text-red-600 border border-red-200 px-3 py-1.5 rounded-xl font-bold shadow-2xs hover:bg-red-50 transition-all duration-200 ease-in-out disabled:opacity-40 disabled:cursor-not-allowed" <?= empty($selected_material_document['id']) ? 'disabled' : '' ?>>削除</button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="p-5 space-y-5 flex-1 min-h-0 flex flex-col">
                        <div id="material-flash-container" class="flex-shrink-0"><?php renderProjectMaterialFlash((string)$material_flash); ?></div>
                        <script type="application/json" id="material-note-editor-data"><?= json_encode([
                            'selected' => [
                                'id' => (int)($selected_material_document['id'] ?? 0),
                                'title' => (string)($selected_material_document['title'] ?? ''),
                                'content' => (string)$selected_material_content,
                            ],
                        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>

                        <p class="text-[11px] text-slate-500 leading-relaxed flex-shrink-0">
                            PDFの報告書と別に、案件の補足資料や途中メモを Markdown で蓄積します。保存すると資料ファイルとして登録され、以後の検索対象にも含められます。
                        </p>

                        <div class="grid grid-cols-1 xl:grid-cols-[18rem_minmax(0,1fr)] gap-5 items-stretch flex-1 min-h-0">
                            <div class="space-y-3 min-h-0 h-full flex flex-col">
                                <div class="text-[10px] font-black text-slate-400 uppercase tracking-widest">資料一覧</div>
                                <div id="material-document-list" class="space-y-2.5 flex-1 min-h-0 overflow-y-auto pr-1 custom-scrollbar">
                                    <?php renderProjectMaterialItems($material_documents, (int)$selected_project_id, $selected_material_document ? (int)$selected_material_document['id'] : null); ?>
                                </div>
                            </div>

                            <div class="space-y-4 min-h-0 h-full flex flex-col">
                                <div class="border border-slate-200 rounded-2xl overflow-hidden bg-white shadow-2xs flex-1 min-h-0 flex flex-col">
                                    <div class="px-4 py-2.5 bg-slate-50 text-[10px] font-black text-slate-400 tracking-widest uppercase flex items-center justify-between">
                                        <span>Preview</span>
                                        <?php if ($selected_material_document): ?>
                                            <span id="material-preview-title" class="normal-case tracking-normal text-slate-400 font-bold"><?= h((string)$selected_material_document['title']) ?></span>
                                        <?php else: ?>
                                            <span id="material-preview-title" class="normal-case tracking-normal text-slate-400 font-bold"></span>
                                        <?php endif; ?>
                                    </div>
                                    <div id="material-preview-body" class="p-5 markdown-body chat-markdown prose prose-slate max-w-none text-sm flex-1 min-h-0 overflow-y-auto custom-scrollbar">
                                        <?php if ($selected_material_preview_html !== ''): ?>
                                            <?= $selected_material_preview_html ?>
                                        <?php elseif ($selected_material_content !== ''): ?>
                                            <pre class="whitespace-pre-wrap break-words text-sm leading-6 text-slate-700 font-sans"><?= h((string)$selected_material_content) ?></pre>
                                        <?php else: ?>
                                            <div class="text-center py-10 text-xs text-slate-400 italic">ここに資料メモのプレビューが表示されます。</div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <?php if (!$can_manage_material_documents): ?>
                                    <div class="rounded-xl border border-slate-200 bg-slate-50/50 px-4 py-3 text-[11px] text-slate-500">
                                        この案件では資料メモの閲覧のみ可能です。
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="tab-pdf" role="tabpanel" class="tab-content <?= $active_tab === 'pdf' ? 'active' : '' ?> h-full overflow-y-auto p-6 space-y-6">
                <div class="bg-white border border-slate-200/80 rounded-2xl p-6 shadow-sm relative overflow-hidden transition-all duration-300 hover:shadow-md">
                    <div class="absolute top-0 left-0 w-1.5 h-full bg-[#4F5D95]"></div>
                    
                    <div class="flex flex-col lg:flex-row items-center justify-between gap-6">
                        <div id="upload-trigger" class="flex-1 w-full border-2 border-dashed border-slate-200 rounded-2xl p-8 text-center hover:bg-slate-50/80 hover:border-[#4F5D95] transition-all duration-300 group cursor-pointer ease-in-out">
                            <div class="text-3xl mb-2.5 group-hover:scale-105 group-hover:-translate-y-0.5 transition-all duration-300">📤</div>
                            <h4 class="text-xs font-black text-slate-700 mb-1">RAG資料PDFのアップロード・解析</h4>
                            <p class="text-[10px] text-slate-400 font-medium">クリックしてファイルを選択（またはドラッグ＆ドロップ）</p>
                        </div>

                        <div class="w-full lg:w-72 space-y-3.5 bg-slate-50/50 p-4 rounded-xl border border-slate-200/60">
                            <div>
                                <span class="text-[9px] font-black text-[#4F5D95] uppercase tracking-wider block mb-1">AI Engine Analysis Mode</span>
                                <label for="analysis-mode" class="block text-[10px] font-bold text-slate-400 mb-1.5">解析モード（OCR分割数設定）</label>
                                <select id="analysis-mode" class="w-full text-[11px] border border-slate-200 rounded-xl px-2.5 py-2 bg-white outline-none focus:ring-4 focus:ring-indigo-500/5 shadow-2xs font-bold text-slate-700 transition-all duration-200 ease-in-out cursor-pointer">
                                    <?php renderPdfAnalysisModeOptions(); ?>
                                </select>
                            </div>
                            <div class="text-[9px] text-slate-400 leading-normal flex items-start gap-1 font-medium">
                                <span class="text-amber-500 flex-shrink-0">💡</span>
                                <span>通常は自動判定がおすすめです。A4報告書やスキャン文書は水平スライス、図面はタイル分割を自動選択します。</span>
                            </div>
                        </div>
                    </div>
                    <input type="file" id="file-upload" class="hidden" accept=".pdf">
                </div>

                <div class="space-y-3">
                    <div class="flex items-center justify-between border-b border-slate-200/60 pb-2">
                        <h4 class="text-xs font-black text-slate-500 uppercase tracking-wider flex items-center gap-1.5">
                            <span>Document Repository</span> 資料PDF プレビュー (<span id="pdf-document-count"><?= count($documents) ?></span>)
                        </h4>
                    </div>

                    <div id="pdf-document-list" class="space-y-2.5" role="list">
                        <?php renderPdfDocumentItems($documents); ?>
                        <?php if (empty($documents)): ?>
                            <?php renderPdfEmptyState(); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div id="tab-comments" role="tabpanel" class="tab-content <?= $active_tab === 'comments' ? 'active' : '' ?> h-full overflow-y-auto p-6 space-y-6">
                <div class="flex justify-between items-center border-b border-slate-200/60 pb-2 mb-2">
                    <h3 class="text-xs font-black text-slate-500 uppercase tracking-wider flex items-center gap-2"><span>Timeline Logs</span> プロジェクト・コメント</h3>
                    <span class="text-[10px] font-black text-slate-400 bg-white border border-slate-200 px-2.5 py-0.5 rounded-full shadow-2xs"><?= count($comments) ?> 件</span>
                </div>
                
                <form id="comment-form" onsubmit="window.handleAsyncAddComment(event);" class="bg-white p-5 rounded-2xl shadow-sm border border-slate-200 mb-6 relative overflow-hidden transition-all duration-300 ease-in-out focus-within:shadow-md">
                    <div class="absolute top-0 left-0 w-1 h-full bg-[#4F5D95]"></div>
                    <textarea name="comment" id="comment-textarea" rows="4" class="w-full border border-slate-200 rounded-xl p-3.5 text-xs outline-none bg-slate-50/50 focus:bg-white focus:border-indigo-400/80 transition-all duration-200 ease-in-out resize-none font-medium text-slate-700 placeholder-slate-400" style="min-height: 112px; max-height: 220px; overflow-y: auto; height: auto;" placeholder="プロジェクトの進捗や、参考リンク of URL(http...)を入力... (Shift+Enterで改行)"></textarea>
                    <div class="flex justify-between items-center mt-3">
                        <span class="text-[10px] text-slate-400 font-medium ml-1">URLは自動でリンクに変換されます</span>
                        <button type="submit" class="bg-[#4F5D95] text-white px-6 py-2 rounded-xl text-xs font-bold shadow-md hover:bg-[#3f4a7a] hover:shadow-lg transition-all duration-200 ease-in-out transform active:scale-95">送信する</button>
                    </div>
                </form>

                <div class="space-y-4" id="comment-list-container">
                    <?php renderProjectCommentItems($comments, (int)$user_id, (string)$role); ?>
                </div>
            </div>

            <div id="tab-csv" role="tabpanel" class="tab-content <?= $active_tab === 'csv' ? 'active' : '' ?> h-full overflow-y-auto md:overflow-hidden p-6 space-y-6">
                <div class="border-b border-slate-200/60 pb-2 mb-4">
                    <div class="flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2 flex-nowrap overflow-x-auto no-scrollbar min-w-0">
                            <form id="csv-upload-form" onsubmit="window.handleCsvUpload(event);" class="flex items-center gap-2 flex-nowrap">
                            <label class="bg-white hover:bg-slate-50 text-slate-600 px-3 py-1.5 rounded-xl border border-slate-200 font-bold text-[10px] cursor-pointer shadow-2xs transition-all duration-200 ease-in-out hover:border-[#00758F] transform active:scale-95">
                                <span id="csv-file-label-text">📎 CSVファイルを選択</span>
                                <input type="file" name="csv_file" accept=".csv" class="hidden" onchange="const form = document.getElementById('csv-upload-form'); form.querySelector('button[type=\'submit\']').classList.remove('hidden'); document.getElementById('csv-file-label-text').textContent = '📄 ' + this.files[0].name;">
                            </label>
                            <button type="submit" class="hidden bg-[#00758F] hover:bg-[#005a6e] text-white px-4 py-1.5 rounded-xl font-bold text-[10px] shadow-md transition-all duration-200 ease-in-out transform active:scale-95">インポート開始</button>
                            </form>

                            <button type="button" onclick="if(typeof window.openAppModal === 'function') window.openAppModal('postgres-import-modal')" class="bg-teal-50 hover:bg-teal-100 text-[#00758F] border border-teal-200 px-3 py-1.5 rounded-xl font-bold text-[10px] shadow-2xs flex items-center gap-1 transition-all duration-200 ease-in-out transform active:scale-95 whitespace-nowrap">
                                <span>🐘 PostgreSQLから取得</span>
                            </button>

                            <button id="csv-merge-trigger" type="button" onclick="window.openCsvMergeModal && window.openCsvMergeModal()" class="bg-white hover:bg-slate-50 text-[#00758F] border border-teal-200 px-3 py-1.5 rounded-xl font-bold text-[10px] shadow-2xs flex items-center gap-1 transition-all duration-200 ease-in-out transform active:scale-95 whitespace-nowrap">
                                <span>🔗 CSV統合</span>
                            </button>
                        </div>

                        <div class="flex items-center gap-2 flex-nowrap overflow-x-auto no-scrollbar justify-end">
                            <button id="csv-create-trigger" type="button" onclick="window.openCsvCreateModal && window.openCsvCreateModal()" class="bg-[#00758F] hover:bg-[#005a6e] text-white border border-[#00758F] px-3 py-1.5 rounded-xl font-bold text-[10px] shadow-2xs flex items-center gap-1 transition-all duration-200 ease-in-out transform active:scale-95 whitespace-nowrap">
                                <span>➕ CSV台帳を作成</span>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-stretch md:h-[calc(100vh-360px)]">
                    <div class="col-span-1 min-h-0">
                        <div class="border border-slate-200 rounded-2xl bg-slate-50/60 p-4 shadow-sm flex flex-col gap-4 h-[calc(100vh-360px)] md:h-full min-h-0 overflow-hidden">
                            <div class="flex flex-col min-h-0 flex-[1.15]">
                                <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest pb-1.5 border-b border-slate-200/60">インポート履歴 (<span id="csv-history-count"><?= count($csv_files) ?></span>)</h4>
                                <div id="csv-history-list" class="space-y-2 flex-1 min-h-0 overflow-y-auto overscroll-contain pr-1 pt-3 custom-scrollbar">
                                    <?php renderCsvHistoryItems($csv_files); ?>
                                </div>
                            </div>

                            <div class="pt-2 border-t border-slate-200/60 flex flex-col min-h-0 flex-1">
                                <div class="flex items-center justify-between gap-2">
                                <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest">AI行解析ジョブ</h4>
                                    <span id="csv-ai-job-count" class="text-[9px] font-black text-slate-400 bg-white border border-slate-200 px-2 py-0.5 rounded-full shadow-2xs">0 件</span>
                                </div>
                                <div id="csv-ai-job-list" class="space-y-2 flex-1 min-h-0 overflow-y-auto overscroll-contain pr-1 pt-3 custom-scrollbar">
                                    <p class="text-[10px] text-slate-400 text-center py-4 italic font-medium">AI行解析ジョブはまだありません。</p>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-span-1 md:col-span-3 min-h-0 md:h-full">
                        <div id="csv-viewer-container" class="min-h-[400px] h-[calc(100vh-360px)] md:h-full overflow-hidden">
                            <?php renderCsvViewerPlaceholder(); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div id="tab-faqs" role="tabpanel" class="tab-content <?= $active_tab === 'faqs' ? 'active' : '' ?> h-full overflow-y-auto p-6 space-y-4">
                <div class="flex justify-between items-center border-b border-slate-200/60 pb-2 mb-4">
                    <h3 class="text-xs font-black text-slate-500 uppercase tracking-wider flex items-center gap-2"><span>Knowledge Base</span> AIナレッジ・FAQ</h3>
                    <button onclick="if(typeof window.openFaqModal === 'function') window.openFaqModal('', '')" class="text-[10px] bg-amber-50 text-amber-700 border border-amber-200 px-3 py-1.5 rounded-xl font-bold shadow-2xs hover:bg-amber-100/80 transition-all duration-200 ease-in-out transform active:scale-95">➕ 手動でナレッジを追加</button>
                </div>
                <div id="faq-list-container" class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php renderFaqCards($faqs, (int)$user_id, (string)$role); ?>
                </div>
                <?php if(empty($faqs)): ?>
                    <?php renderFaqEmptyState(); ?>
                <?php endif; ?>
            </div>

            <div id="tab-members" role="tabpanel" class="tab-content <?= $active_tab === 'members' ? 'active' : '' ?> h-full overflow-y-auto p-6 space-y-6">
                <div class="flex justify-between items-center border-b border-slate-200/60 pb-2 mb-2">
                    <h3 class="text-xs font-black text-slate-500 uppercase tracking-wider flex items-center gap-2"><span>Access Management</span> アサインメンバー管理</h3>
                    <button type="button" onclick="if(typeof window.openAppModal === 'function') window.openAppModal('add-member-modal')" class="text-[10px] bg-indigo-50 text-indigo-700 border border-indigo-200 px-3 py-1.5 rounded-xl font-bold shadow-2xs hover:bg-indigo-100 transition-all duration-200 ease-in-out transform active:scale-95">➕ メンバーを追加</button>
                </div>
                <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-2xs">
                    <table class="w-full text-xs text-left">
                        <thead>
                            <tr class="bg-slate-50 text-slate-500 border-b border-slate-200/60">
                                <th class="p-4 font-extrabold tracking-wide uppercase text-[10px]">名前</th>
                                <th class="p-4 font-extrabold tracking-wide uppercase text-[10px]">部門</th>
                                <th class="p-4 font-extrabold tracking-wide uppercase text-[10px]">役割</th>
                                <th class="p-4 font-extrabold tracking-wide uppercase text-[10px] w-20 text-center">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100">
                        <?php renderProjectMemberRows($members); ?>
                        </tbody>
                    </table>
                </div>
            </div>

            <div id="tab-memory" role="tabpanel" class="tab-content <?= $active_tab === 'memory' ? 'active' : '' ?> h-full overflow-y-auto p-6 space-y-6">
                <div class="bg-white border border-slate-200/80 rounded-2xl overflow-hidden shadow-sm transition-all duration-300 hover:shadow-md">
                    <?php if ($can_manage_project_memory): ?>
                        <form method="post">
                            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                            <input type="hidden" name="project_id" value="<?= h((string)$selected_project_id) ?>">
                    <?php endif; ?>
                    <div class="bg-slate-50/70 p-3.5 px-5 font-bold text-slate-700 flex justify-between items-center text-xs border-b border-slate-100">
                        <div class="flex items-center gap-3">
                            <span class="font-extrabold tracking-wide text-slate-600">案件運用メモ</span>
                            <?php if ($can_manage_project_memory): ?>
                                <div class="flex items-center gap-2">
                                    <button type="submit" name="action" value="save_project_memory" class="text-[11px] bg-[#4F5D95] text-white border border-[#4F5D95] px-4 py-2 rounded-xl font-bold shadow-sm hover:bg-[#3f4a7a] transition-all duration-200 ease-in-out transform active:scale-95">メモを保存</button>
                                    <button type="submit" name="action" value="refresh_project_memory" class="text-[11px] bg-white text-slate-700 border border-slate-200 px-4 py-2 rounded-xl font-bold shadow-sm hover:bg-slate-50 transition-all duration-200 ease-in-out transform active:scale-95">自動生成メモを再生成</button>
                                </div>
                            <?php endif; ?>
                        </div>
                        <span class="text-[10px] text-slate-400 font-bold tracking-wider">案件内容 / AIエージェント / タスク一覧</span>
                    </div>
                    <div class="p-5 space-y-4">
                        <div id="memory-flash-container"><?php renderProjectMemoryFlash((string)$memory_flash); ?></div>

                        <p class="text-[11px] text-slate-500 leading-relaxed">
                            この案件に特有の回答方針、背景、既知の論点をメモとして保持し、AIが回答を組み立てる前に参照します。上の入力欄は手動メモ、下の参考欄は案件状態と最近の会話から作られた自動生成メモです。手動メモは次回の会話保存で上書きされません。
                        </p>

                        <?php if ($can_manage_project_memory): ?>
                            <div class="space-y-4">
                                <?php renderProjectMemoryEditors($project_memory_docs); ?>
                            </div>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php renderProjectMemoryReadonly($project_memory_docs); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    <?php if ($can_manage_project_memory): ?>
                        </form>
                    <?php endif; ?>
                </div>
            </div>

        </div>
    </div>

    <div id="resize-handle" class="h-full w-1 bg-transparent hover:bg-indigo-500/20 active:bg-indigo-500/40 cursor-col-resize z-10 relative transition-colors duration-200"></div>

    <div id="right-panel" class="w-[var(--support-width)] bg-white flex flex-col h-full border-l border-slate-200/60 flex-shrink-0 relative transition-all duration-200">
        <div class="px-4 py-3 bg-white/90 backdrop-blur-md border-b border-slate-200/60 flex justify-between items-center gap-2 shadow-2xs relative z-10">
            <div class="flex-shrink-0">
                <p class="font-black text-slate-700 text-[10px] uppercase tracking-widest flex items-center gap-1.5" title="接続先: <?= h($ollama_host) ?>">
                    <span>AI Assistant Panel</span>
                    <span class="inline-flex items-center gap-1 text-[8px] text-emerald-600 font-bold uppercase tracking-widest">
                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span>
                        <span>Ready</span>
                    </span>
                </p>
            </div>

            <div class="flex items-center gap-1.5 overflow-hidden">
                <select id="support-prompt-select" class="text-[10px] border border-slate-200 rounded-xl px-2 py-1.5 bg-slate-50/50 hover:bg-white font-bold text-slate-600 tracking-wide max-w-[90px] truncate outline-none transition-all duration-200 ease-in-out cursor-pointer shadow-2xs relative shadow-inner focus:border-indigo-400">
                    <?php renderPromptModeOptions($promptModeOptions, (string)$default_prompt_mode); ?>
                </select>
                
                <select id="support-model-select" class="text-[10px] border border-slate-200 rounded-xl px-2 py-1.5 bg-slate-50/50 hover:bg-white font-mono max-w-[100px] truncate outline-none transition-all duration-200 ease-in-out cursor-pointer text-slate-600 shadow-2xs focus:border-indigo-400" title="現在のホスト: <?= h($ollama_host) ?>" <?= empty($installed_models) ? 'disabled' : '' ?>>
                    <?php renderModelOptions($installed_models, (string)$active_model); ?>
                </select>
            </div>
        </div>

        <div class="sr-only" aria-hidden="true">
            <p id="active-thread-title"><?= h((string)($selected_thread['title'] ?? '会話 1')) ?></p>
        </div>

        <div class="border-b border-slate-200/70 bg-white px-3 pt-2">
            <div id="chat-thread-list" class="chat-thread-tabs">
                <?php renderChatThreadTabs($chat_threads, (int)$selected_thread_id); ?>
            </div>
        </div>

        <div class="chat-box-shell">
            <button
                id="btn-clear-chat-history"
                type="button"
                class="chat-clear-overlay-btn text-[10px] font-bold transition-all duration-200 ease-in-out active:scale-95"
                title="この案件のAI会話履歴を削除"
                aria-label="この案件のAI会話履歴を削除"
            >
                <span class="text-[13px] font-black leading-none" aria-hidden="true">×</span>
                <span>履歴クリア</span>
            </button>

            <div id="chat-box" class="h-full p-4 space-y-5 overflow-y-auto bg-slate-50/40 no-scrollbar">
                <?php foreach ($chat_history as $chat): ?>
                    <?php $timeStr = date('Y/m/d H:i', strtotime($chat['created_at'])); ?>
                    <div class="<?= h(getChatMessageRowClasses((string)$chat['role'])) ?>" data-chat-role="<?= h((string)$chat['role']) ?>">
                        <div class="<?= h(getChatAvatarClasses((string)$chat['role'])) ?>">
                            <?= h(getChatAvatarIcon((string)$chat['role'])) ?>
                        </div>

                        <div class="<?= h(getChatMessageStackClasses((string)$chat['role'])) ?>">
                            <div class="flex items-center gap-1.5 px-1">
                                <span class="text-[9px] font-black text-slate-400 uppercase tracking-tight">
                                    <?= h(getChatRoleLabel((string)$chat['role'])) ?>
                                </span>
                                <span class="text-[8px] text-slate-400 font-mono tracking-tighter"><?= $timeStr ?></span>
                            </div>
                            <div class="<?= h(getChatBubbleClasses((string)$chat['role'])) ?>">
                                <?php renderSavedChatMessageContent($chat, $chat_reasoning_steps_by_chat_id); ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <div class="p-4 pt-2 border-t border-slate-100 bg-white relative z-10 space-y-3">
            <div class="chat-footer-toolbar">
                <div class="chat-footer-toolbar__left">
                    <div class="flex flex-wrap gap-1.5 overflow-x-auto no-scrollbar" id="quick-actions-bar">
                        <?php foreach ($chatQuickActions as $action): ?>
                            <button
                                type="button"
                                onclick="const input=document.getElementById('chat-input'); input.value='<?= h($action['prompt']) ?>'; input.dispatchEvent(new Event('input')); input.focus();"
                                class="text-[9px] bg-slate-50 hover:bg-indigo-50 border border-slate-200/80 rounded-full px-3 py-1 font-bold text-slate-500 shadow-2xs transition-colors duration-200 ease-in-out hover:border-indigo-200 hover:text-[#4F5D95]"
                            ><?= h($action['icon']) ?> <?= h($action['label']) ?></button>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="chat-footer-toolbar__right">
                    <details class="chat-output-options">
                        <summary class="chat-output-options-trigger cursor-pointer text-[10px] font-black transition-all duration-200 ease-in-out hover:border-indigo-300 hover:text-indigo-700 select-none">
                            <span aria-hidden="true">⚙️</span>
                            <span>出力オプション</span>
                        </summary>
                        <div class="chat-output-options-panel px-3 py-3 space-y-3">
                            <div class="chat-output-options-group" role="group" aria-label="出力オプション">
                                <?php renderChatModeSwitches($chatOutputOptions); ?>
                            </div>

                            <?php if ($role === 'admin'): ?>
                            <div class="space-y-1.5 border-t border-slate-100 pt-2">
                                <p class="text-[9px] font-black text-slate-400 uppercase tracking-wider px-1">管理者向け高度推論</p>
                                <div class="chat-output-options-group">
                                    <?php renderChatModeSwitches($adminOutputOptions); ?>
                                </div>
                            </div>
                            <?php endif; ?>
                        </div>
                    </details>
                </div>
            </div>

            <?php if ($role === 'admin'): ?>
            <details id="chat-debug-panel" class="border border-slate-200 rounded-xl bg-slate-50/80 overflow-hidden">
                <summary class="px-3 py-2 cursor-pointer flex items-center justify-between select-none">
                    <span class="text-[10px] font-black text-slate-600 uppercase tracking-wide">chat_debug.log ライブ追記</span>
                    <span id="chat-debug-status" class="text-[9px] text-slate-400 font-bold">停止中</span>
                </summary>
                <div class="border-t border-slate-200 bg-slate-950 text-slate-100">
                    <div id="chat-debug-viewer" class="h-36 overflow-y-auto p-3 font-mono text-[10px] leading-relaxed whitespace-pre-wrap"></div>
                </div>
            </details>
            <?php endif; ?>
            
            <form id="chat-form" onsubmit="if(typeof window.handleChat === 'function') { window.handleChat(event); } else { event.preventDefault(); }" class="flex items-end gap-2 bg-slate-50 rounded-2xl px-4 py-2.5 border border-slate-200/80 shadow-2xs transition-all duration-300 ease-in-out focus-within:ring-4 focus-within:ring-[#4F5D95]/10 focus-within:bg-white focus-within:border-[#4F5D95]/60 focus-within:shadow-md">
                <textarea id="chat-input" class="flex-1 bg-transparent border-none outline-none focus:outline-none focus:ring-0 text-xs px-0.5 py-1 resize-none overflow-y-auto leading-relaxed text-slate-700 placeholder-slate-400/80 transition-all duration-200" 
                          rows="1" placeholder="資料やデータについて質問... (Shift+Enterで改行)" required></textarea>
                <button type="submit" class="text-[#4F5D95] mb-0.5 flex-shrink-0 p-1 bg-white rounded-xl shadow-2xs border border-slate-200/60 w-7 h-7 flex items-center justify-center transition-all duration-200 ease-in-out transform hover:-translate-y-0.5 hover:shadow-md hover:bg-indigo-50 active:scale-95 active:shadow-inner active:bg-slate-100 group" title="送信">
                    <svg xmlns="<?= !empty($URL_SVG_XMLNS) ? h($URL_SVG_XMLNS) : 'http://www.w3.org/2000/svg' ?>" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-3.5 h-3.5 text-[#4F5D95] group-hover:text-indigo-700 transform group-hover:-translate-y-0.5 transition-all duration-150 ease-in-out">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 10.5 12 3m0 0 7.5 7.5M12 3v18" />
                    </svg>
                </button>
            </form>
        </div>
    </div>
    <?php endif; ?>
</main>

<?php include_once __DIR__ . '/templates/modals.php'; ?>

<div id="chart-max-modal" class="fixed inset-0 bg-slate-950/40 hidden items-center justify-center z-[110] p-4 animate-fadeIn backdrop-blur-xs">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-5xl h-[85vh] flex flex-col overflow-hidden">
        <div class="bg-slate-50/80 px-6 py-4 border-b flex justify-between items-center flex-shrink-0">
            <h3 class="text-xs font-black text-slate-600 uppercase tracking-widest flex items-center gap-2">📊 集計・分析グラフ 拡大プレビュー</h3>
            <button id="btn-close-chart-modal" class="text-slate-400 hover:text-slate-600 text-2xl font-bold px-2 transition-colors">&times;</button>
        </div>
        <div class="flex-1 overflow-auto p-8 bg-slate-50/20 flex items-center justify-center relative" id="chart-modal-content">
            <canvas id="max-chart-canvas" class="max-w-full max-h-full"></canvas>
        </div>
    </div>
</div>

<script type="module">
    // ★遅延バグの元凶であるモジュール内の switchTab を完全撤去（グローバルスコープのみで運用）

    // ★ 究極の安全設計: import * as 構文を使用し、1096エラー(SyntaxError)を原理的に100%防止
    // ✨ ここを ?v=4 から ?v=5 へ書き換えてキャッシュを強制粉砕！
    import * as Support from './assets/js/support.js?v=38';

    // ★要件4: 隔離コンテナ内のJSONデータを仲介して安全にマウント・パースするイベントハンドラの実装
    window.openProjectEditModal = (lat, lng) => {
        const dataEl = document.getElementById('prefill-project-data');
        if (!dataEl) return;
        try {
            const prefill = JSON.parse(dataEl.textContent);
            if (typeof window.openAppModal === 'function') {
                window.openAppModal("edit-project-modal", lat, lng, prefill);
            }
        } catch(e) { console.error("Prefill data parse error:", e); }
    };

    const safeSupportInit = (label, fn) => {
        try {
            if (typeof fn === 'function') {
                fn();
            }
        } catch (e) {
            console.error(`support.php init failed: ${label}`, e);
        }
    };

    const initApp = () => {
        safeSupportInit('bindGlobalFunctions', Support.bindGlobalFunctions);
        safeSupportInit('bindModalEvents', Support.bindModalEvents);
        safeSupportInit('initResizer', Support.initResizer);
        safeSupportInit('initChatInput', Support.initChatInput);
        safeSupportInit('initDebugLogViewer', Support.initDebugLogViewer);
        safeSupportInit('checkUploadOnLoad', Support.checkUploadOnLoad);
        safeSupportInit('scrollToBottom', Support.scrollToBottom);

        const activeTab = '<?= h($active_tab) ?>';
        if (activeTab && activeTab !== 'overview') {
            if (typeof window.switchTab === 'function') {
                window.switchTab('tab-' + activeTab);
            }
        }

        // ★安全弁ロジック維持: 最下部 initApp() 内での過去ログ自動一斉パース回路のインジェクト
        setTimeout(() => {
            const parseMarkdown = (text) => {
                return (typeof marked !== 'undefined' && typeof DOMPurify !== 'undefined')
                    ? DOMPurify.sanitize(marked.parse(String(text || '')))
                    : String(text || '');
            };

            const appendSavedReasoning = (textBody, bubble) => {
                const source = bubble.querySelector('.chat-reasoning-source');
                if (!source || !textBody) return;

                let steps = [];
                try {
                    steps = JSON.parse(source.textContent || '[]');
                } catch (e) {
                    console.warn('reasoning source parse error:', e);
                }
                if (!Array.isArray(steps) || steps.length === 0) return;

                if (typeof window.buildReasoningProcessDetailsHtml === 'function') {
                    const richHtml = window.buildReasoningProcessDetailsHtml(steps);
                    if (richHtml) {
                        const fragment = document.createRange().createContextualFragment(richHtml);
                        textBody.insertBefore(fragment, textBody.firstChild);
                        return;
                    }
                }

                const detailsBox = document.createElement('details');
                detailsBox.className = 'mb-3 border border-indigo-100 rounded-xl bg-indigo-50/20 overflow-hidden group w-full';
                const bodyHtml = steps.map(step => `
                    <div class="text-[10px] bg-white p-3 rounded-xl border border-indigo-50 shadow-xs">
                        <p class="font-bold text-indigo-700 mb-1.5">Q. ${String(step.sub_query || '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]))}</p>
                        <div class="text-slate-600 markdown-body chat-markdown chat-reasoning-body">${parseMarkdown(step.sub_answer || '')}</div>
                    </div>
                `).join('');

                detailsBox.innerHTML = `
                    <summary class="text-[10px] font-bold text-indigo-600 p-2.5 cursor-pointer hover:bg-indigo-50/50 transition-colors select-none outline-none flex items-center gap-1.5">
                        <span class="group-open:rotate-90 transition-transform text-[8px] w-3 text-center block">▶</span>
                        🧠 AIの思考・検証プロセスを表示 (${steps.length}件のサブクエリ)
                    </summary>
                    <div class="p-3.5 pt-0 space-y-3 border-t border-indigo-100/60 max-h-[300px] overflow-y-auto custom-scrollbar bg-white/50">
                        ${bodyHtml}
                    </div>
                `;
                textBody.insertBefore(detailsBox, textBody.firstChild);
            };

            // 過去の全吹き出しをスキャンし、JS側の高機能レンダラーで一斉JITパース
            document.querySelectorAll('#chat-box > div').forEach(bubble => {
                const sourceEl = bubble.querySelector('.chat-raw-message-source');
                if (!sourceEl) return;
                const rawText = sourceEl.textContent.trim();
                const avatarIcon = bubble.querySelector('.w-7');
                if (!avatarIcon) return;
                
                const isAssistant = avatarIcon.classList.contains('text-amber-700');
                
                if (isAssistant) {
                    const textBody = bubble.querySelector('.ai-text-body');
                    if (textBody) {
                        // 過去のマークダウン、detailsアコーディオン、グラフを安全に自動再起動
                        textBody.innerHTML = parseMarkdown(rawText);
                        appendSavedReasoning(textBody, bubble);
                    }
                }
            });
            if (typeof window.initExistingCharts === 'function') {
                window.initExistingCharts();
            }
            if (typeof window.scrollToBottom === 'function') {
                window.scrollToBottom();
            }
        }, 50);

        <?php if (!empty($current_project['latitude']) && !empty($current_project['longitude'])): ?>
        const mapContainer = document.getElementById('overview-map');
        if (mapContainer && typeof L !== 'undefined') {
            const mapLat = <?= (float)$current_project['latitude'] ?>;
            const mapLng = <?= (float)$current_project['longitude'] ?>;
            const overviewMap = L.map('overview-map').setView([mapLat, mapLng], 14);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '&copy; OpenStreetMap contributors'
            }).addTo(overviewMap);
            
            L.marker([mapLat, mapLng]).addTo(overviewMap)
             .bindPopup('<?= h(str_replace("'", "\\'", $current_project['project_name'])) ?>')
             .openPopup();
             
            const tabOverview = document.getElementById('tab-overview');
            if (tabOverview) {
                // 【仕様2維持】幕引きMutationObserverによるJIT地図再描画ロジックの最適維持
                const observer = new MutationObserver((mutations) => {
                    mutations.forEach((mutation) => {
                        if (mutation.target.classList.contains('active')) {
                            setTimeout(() => { overviewMap.invalidateSize(); }, 150);
                        }
                    });
                });
                observer.observe(tabOverview, { attributes: true, attributeFilter: ['class'] });
            }
            
            window.addEventListener('resize', () => {
                setTimeout(() => { overviewMap.invalidateSize(); }, 150);
            });
        }
        <?php endif; ?>

        const deleteProjBtn = document.getElementById('btn-delete-project');
        if (deleteProjBtn) {
            deleteProjBtn.addEventListener('click', () => {
                if (typeof window.deleteProject === 'function') {
                    window.deleteProject();
                }
            });
        }

        const clearHistoryBtn = document.getElementById('btn-clear-chat-history');
        if (clearHistoryBtn) {
            clearHistoryBtn.addEventListener('click', () => {
                if (typeof window.clearProjectChatHistory === 'function') {
                    window.clearProjectChatHistory();
                }
            });
        }

        window.afterProjectHistoryCleared = async function (_projectId, result = null) {
            const chatBox = document.getElementById('chat-box');
            const faqList = document.getElementById('faq-list-container');
            let faqEmptyState = document.getElementById('faq-empty-state');
            const threadMetaEls = document.querySelectorAll('#chat-thread-list .chat-thread-tab__meta');

            if (window.chartInstances && typeof window.chartInstances === 'object') {
                Object.values(window.chartInstances).forEach((instance) => {
                    if (instance && typeof instance.destroy === 'function') {
                        instance.destroy();
                    }
                });
                window.chartInstances = {};
            }
            if (window.modalChartInstance && typeof window.modalChartInstance.destroy === 'function') {
                window.modalChartInstance.destroy();
                window.modalChartInstance = null;
            }

            if (chatBox) {
                chatBox.innerHTML = `
                    <div class="h-full flex items-center justify-center">
                        <div class="text-center py-12 px-6 bg-white rounded-2xl border border-dashed border-slate-200 shadow-2xs max-w-md">
                            <p class="text-3xl mb-3 opacity-40">🧹</p>
                            <p class="text-xs text-slate-400 font-bold leading-relaxed">この案件のチャット履歴は削除されました。<br>新しい検証をここから始められます。</p>
                        </div>
                    </div>
                `;
            }

            if (threadMetaEls.length > 0) {
                threadMetaEls.forEach((metaEl) => {
                    metaEl.textContent = '履歴なし ・ 0件';
                });
            }

            if (faqList) {
                faqList.innerHTML = '';
            }
            if (!faqEmptyState) {
                faqEmptyState = document.createElement('div');
                faqEmptyState.id = 'faq-empty-state';
                faqEmptyState.className = 'text-center py-16 bg-white rounded-2xl border border-dashed border-slate-200 shadow-2xs';
                faqEmptyState.innerHTML = '<p class="text-3xl mb-3 opacity-40">💡</p><p class="text-xs text-slate-400 font-bold leading-relaxed">チャットの回答にある「📌 ナレッジとして共有」ボタンから、<br>得られた有益な知見をチーム全体へシェアできます。</p>';
                faqList?.insertAdjacentElement('afterend', faqEmptyState);
            } else {
                faqEmptyState.classList.remove('hidden');
            }

            if (result?.counts) {
                console.info('Project chat history cleared', result.counts);
            }

            if (typeof window.scrollToBottom === 'function') {
                window.scrollToBottom();
            }
        };

        document.addEventListener('click', async (e) => {
            const btn = e.target.closest('.btn-delete-pdf');
            if (!btn) return;
            e.stopPropagation();
            const id = btn.dataset.docId;
            if (!confirm('この資料を完全に削除しますか？')) return;

            try {
                let fetchFn = window.secureFetch || Support.secureFetch;
                if (!fetchFn) {
                    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content;
                    fetchFn = (url, opts) => fetch(url, {
                        ...opts,
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfToken, ...opts.headers }
                    }).then(r => r.json());
                }

                const res = await fetchFn('api/delete_pdf.php', { method: 'POST', body: JSON.stringify({ id }) });
                if (res.success) {
                    location.reload();
                } else {
                    alert(res.error || '削除に失敗しました。');
                }
            } catch (err) { alert('通信エラーが発生しました: ' + err.message); }
        });

        const uploadTrigger = document.getElementById('upload-trigger');
        if (uploadTrigger) {
            uploadTrigger.addEventListener('click', () => {
                document.getElementById('file-upload')?.click();
            });
        }
        
        const fileUploadInput = document.getElementById('file-upload');
        if (fileUploadInput) {
            fileUploadInput.addEventListener('change', (e) => {
                if (typeof window.handleUpload === 'function') {
                    window.handleUpload(e);
                }
            });
        }

        const commentTextarea = document.getElementById('comment-textarea');
        if (commentTextarea) {
            commentTextarea.addEventListener('input', function() {
                this.style.height = 'auto';
                this.style.height = this.scrollHeight + 'px';
            });
        }

        const chatInput = document.getElementById('chat-input');
        if (chatInput) {
            chatInput.addEventListener('input', function() {
                this.style.height = 'auto';
                const nextHeight = Math.min(this.scrollHeight, 180);
                this.style.height = nextHeight + 'px';
            });
        }

        const cModal = document.getElementById('chart-max-modal');
        document.getElementById('btn-close-chart-modal')?.addEventListener('click', () => {
            if (cModal) cModal.classList.replace('flex', 'hidden');
            if (window.modalChartInstance) {
                window.modalChartInstance.destroy();
                window.modalChartInstance = null;
            }
        });
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initApp);
    } else {
        initApp();
    }
</script>
</body>
</html>
