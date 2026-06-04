<?php

require_once __DIR__ . '/../src/SupportController.php';

// ★要件1: エスケープ関数 h() の完全二重定義防止ガードの最上部インジェクト
if (!function_exists('h')) {
    function h($str) {
        return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
    }
}
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
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        
        .tab-btn.active { 
            background-color: #ffffff; 
            color: #4F5D95; 
            box-shadow: 0 1px 3px 0 rgba(0, 0, 0, 0.05); 
            border-bottom: 2px solid #4F5D95;
            font-weight: 800; 
            opacity: 1; 
        }
        
        :root {--support-width: 410px;}
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
    </style>

    <script>
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

<?php include_once __DIR__ . '/templates/header.php'; ?>

<div id="support-config"
     data-csrf-token="<?= h($csrfToken) ?>"
     data-project-id="<?= h((string)$selected_project_id) ?>"
     data-can-debug-log="<?= $role === 'admin' ? '1' : '0' ?>"></div>

<main class="flex-1 flex overflow-hidden h-[calc(100vh-72px)] gap-px bg-slate-200/50 w-full" role="region" aria-label="Support System Console">
    
    <div class="w-64 bg-white flex flex-col p-4 border-r border-slate-200/60 flex-shrink-0" role="navigation">
        <h2 class="text-[11px] font-black text-slate-400 px-1 pb-3 mb-2 uppercase tracking-widest border-b border-slate-100">業務一覧</h2>
        <div class="flex-1 overflow-y-auto space-y-1.5 pr-1 no-scrollbar" role="list">
            <?php foreach ($projects as $p): ?>
                <?php $isProjFocused = ($selected_project_id == $p['id']); ?>
                <a href="?project_id=<?= h((string)$p['id']) ?>" class="block p-3 rounded-xl transition-all duration-200 ease-in-out <?= $isProjFocused ? 'bg-slate-100 text-slate-900 font-extrabold shadow-2xs' : 'hover:bg-slate-50 text-slate-600 border-transparent' ?>">
                    <p class="text-xs leading-snug <?= $isProjFocused ? 'text-slate-900 font-black' : 'text-slate-600 font-medium' ?>"><?= h($p['project_name']) ?></p>
                    <p class="text-[9px] text-slate-400 mt-1.5 font-medium tracking-tight">Update: <?= date('m/d H:i', strtotime($p['updated_at'])) ?></p>
                </a>
            <?php endforeach; ?>
        </div>
        <button onclick="if(typeof window.openAppModal === 'function') window.openAppModal('project-modal')" class="mt-4 p-3 bg-slate-50 text-slate-600 rounded-xl text-xs font-bold border border-slate-200 hover:bg-indigo-50/50 hover:text-[#4F5D95] hover:border-indigo-200 transition-all duration-200 shadow-2xs transform active:scale-98">+ 新規案件登録</button>
    </div>

    <?php if ($current_project): ?>
    <div class="flex-1 bg-white flex flex-col overflow-hidden" role="region">
        
        <div class="bg-slate-50/80 border-b border-slate-200/60 flex items-end gap-1 px-3 pt-3 flex-shrink-0" id="tab-header" role="tablist">
            <button onclick="if(typeof window.switchTab === 'function') window.switchTab('tab-overview'); history.replaceState(null, '', '?project_id=<?= h((string)$selected_project_id) ?>&tab=overview')" id="btn-overview" role="tab" class="tab-btn <?= $active_tab === 'overview' ? 'active' : '' ?> px-4 py-2 text-[11px] font-bold text-slate-500 rounded-t-xl transition-all duration-200 ease-in-out transform active:scale-98">🏠 概要</button>
            <button onclick="if(typeof window.switchTab === 'function') window.switchTab('tab-pdf'); history.replaceState(null, '', '?project_id=<?= h((string)$selected_project_id) ?>&tab=pdf')" id="btn-pdf" role="tab" class="tab-btn <?= $active_tab === 'pdf' ? 'active' : '' ?> px-4 py-2 text-[11px] font-bold text-slate-500 rounded-t-xl transition-all duration-200 ease-in-out transform active:scale-98">📄 PDF (<?= count($documents) ?>)</button>
            <button onclick="if(typeof window.switchTab === 'function') window.switchTab('tab-comments'); history.replaceState(null, '', '?project_id=<?= h((string)$selected_project_id) ?>&tab=comments')" id="btn-comments" role="tab" class="tab-btn <?= $active_tab === 'comments' ? 'active' : '' ?> px-4 py-2 text-[11px] font-bold text-slate-500 rounded-t-xl transition-all duration-200 ease-in-out transform active:scale-98">💬 コメント (<?= count($comments) ?>)</button>
            <button onclick="if(typeof window.switchTab === 'function') window.switchTab('tab-csv'); history.replaceState(null, '', '?project_id=<?= h((string)$selected_project_id) ?>&tab=csv')" id="btn-csv" role="tab" class="tab-btn <?= $active_tab === 'csv' ? 'active' : '' ?> px-4 py-2 text-[11px] font-bold text-slate-500 rounded-t-xl transition-all duration-200 ease-in-out transform active:scale-98">📊 CSVデータ (<?= count($csv_files) ?>)</button>
            <button onclick="if(typeof window.switchTab === 'function') window.switchTab('tab-faqs'); history.replaceState(null, '', '?project_id=<?= h((string)$selected_project_id) ?>&tab=faqs')" id="btn-faqs" role="tab" class="tab-btn <?= $active_tab === 'faqs' ? 'active' : '' ?> px-4 py-2 text-[11px] font-bold text-slate-500 rounded-t-xl transition-all duration-200 ease-in-out transform active:scale-98">📚 AIナレッジ・FAQ</button>
            <button onclick="if(typeof window.switchTab === 'function') window.switchTab('tab-members'); history.replaceState(null, '', '?project_id=<?= h((string)$selected_project_id) ?>&tab=members')" id="btn-members" role="tab" class="tab-btn <?= $active_tab === 'members' ? 'active' : '' ?> px-4 py-2 text-[11px] font-bold text-slate-500 rounded-t-xl transition-all duration-200 ease-in-out transform active:scale-98">👥 メンバー設定</button>
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
                                <tr><th class="p-4 bg-slate-50/40 w-36 font-bold text-slate-400 text-[11px] tracking-wider uppercase">業務名</th><td class="p-4 font-black text-slate-800 text-sm"><?= h($current_project['project_name']) ?></td></tr>
                                <tr><th class="p-4 bg-slate-50/40 font-bold text-slate-400 text-[11px] tracking-wider uppercase">業務期間</th><td class="p-4 font-semibold text-slate-600"><?= (!empty($current_project['start_date']) || !empty($current_project['end_date'])) ? h((string)$current_project['start_date']) . " ～ " . h((string)$current_project['end_date']) : '<span class="text-slate-400 italic font-normal">未設定</span>' ?></td></tr>
                                <tr><th class="p-4 bg-slate-50/40 font-bold text-slate-400 text-[11px] tracking-wider uppercase">業務概要</th><td class="p-4 leading-relaxed whitespace-pre-wrap font-medium text-slate-600"><?= h((string)$current_project['description'] ?: '未入力') ?></td></tr>
                                <tr>
                                    <th class="p-4 bg-slate-50/40 align-top font-bold text-slate-400 text-[11px] tracking-wider uppercase">場所・住所</th>
                                    <td class="p-4">
                                        <div class="mb-3 font-semibold text-slate-700"><?= h((string)$current_project['address'] ?: '未登録') ?></div>
                                        <?php if (!empty($current_project['latitude']) && !empty($current_project['longitude'])): ?>
                                            <div id="overview-map" class="w-full h-52 rounded-xl border border-slate-200 overview-map-container mt-2 shadow-inner"></div>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="bg-white border border-slate-200/80 rounded-2xl overflow-hidden shadow-sm transition-all duration-300 hover:shadow-md">
                    <div class="bg-slate-50/70 p-3.5 px-5 font-bold text-slate-700 flex justify-between items-center text-xs border-b border-slate-100">
                        <span class="font-extrabold tracking-wide text-slate-600">案件運用メモ</span>
                        <span class="text-[10px] text-slate-400 font-bold uppercase tracking-wider">AGENTS / README / TODO</span>
                    </div>
                    <div class="p-5 space-y-4">
                        <?php if ($memory_flash === '1'): ?>
                            <div class="text-[11px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3">案件運用メモを更新しました。</div>
                        <?php elseif ($memory_flash === 'error'): ?>
                            <div class="text-[11px] font-bold text-red-700 bg-red-50 border border-red-200 rounded-xl px-4 py-3">案件運用メモの保存に失敗しました。</div>
                        <?php elseif ($memory_flash === 'csrf_error' || $memory_flash === 'forbidden'): ?>
                            <div class="text-[11px] font-bold text-amber-700 bg-amber-50 border border-amber-200 rounded-xl px-4 py-3">案件運用メモを更新する権限がありません。</div>
                        <?php endif; ?>

                        <p class="text-[11px] text-slate-500 leading-relaxed">
                            この案件に特有の回答方針、背景、既知の論点をメモとして保持し、AIが回答を組み立てる前に参照します。
                        </p>

                        <?php if ($can_manage_project_memory): ?>
                            <form method="post" class="space-y-4">
                                <input type="hidden" name="action" value="save_project_memory">
                                <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
                                <input type="hidden" name="project_id" value="<?= h((string)$selected_project_id) ?>">

                                <div class="space-y-1.5">
                                    <label for="memory-agents" class="block text-[10px] font-black text-slate-400 uppercase tracking-wider">AGENTS</label>
                                    <textarea id="memory-agents" name="memory_agents" rows="6" class="w-full border border-slate-200 rounded-xl p-3 text-xs bg-slate-50/50 focus:bg-white focus:border-indigo-400/80 transition-all duration-200 resize-y font-medium text-slate-700 outline-none" placeholder="回答方針、禁止事項、優先ルールなど"><?= h((string)($project_memory_docs['agents']['content'] ?? '')) ?></textarea>
                                </div>

                                <div class="space-y-1.5">
                                    <label for="memory-readme" class="block text-[10px] font-black text-slate-400 uppercase tracking-wider">README</label>
                                    <textarea id="memory-readme" name="memory_readme" rows="6" class="w-full border border-slate-200 rounded-xl p-3 text-xs bg-slate-50/50 focus:bg-white focus:border-indigo-400/80 transition-all duration-200 resize-y font-medium text-slate-700 outline-none" placeholder="案件の背景、用語、前提、構成など"><?= h((string)($project_memory_docs['readme']['content'] ?? '')) ?></textarea>
                                </div>

                                <div class="space-y-1.5">
                                    <label for="memory-todo" class="block text-[10px] font-black text-slate-400 uppercase tracking-wider">TODO</label>
                                    <textarea id="memory-todo" name="memory_todo" rows="6" class="w-full border border-slate-200 rounded-xl p-3 text-xs bg-slate-50/50 focus:bg-white focus:border-indigo-400/80 transition-all duration-200 resize-y font-medium text-slate-700 outline-none" placeholder="既知の課題、次に見るべき点、現在の運用メモなど"><?= h((string)($project_memory_docs['todo']['content'] ?? '')) ?></textarea>
                                </div>

                                <div class="flex justify-end">
                                    <button type="submit" class="text-[11px] bg-[#4F5D95] text-white border border-[#4F5D95] px-4 py-2 rounded-xl font-bold shadow-sm hover:bg-[#3f4a7a] transition-all duration-200 ease-in-out transform active:scale-95">メモを保存</button>
                                </div>
                            </form>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach (['agents', 'readme', 'todo'] as $memoryType): ?>
                                    <?php $memoryContent = trim((string)($project_memory_docs[$memoryType]['content'] ?? '')); ?>
                                    <?php if ($memoryContent === '') continue; ?>
                                    <div class="border border-slate-200 rounded-xl overflow-hidden">
                                        <div class="px-4 py-2 bg-slate-50 text-[10px] font-black text-slate-400 uppercase tracking-wider"><?= h((string)($project_memory_docs[$memoryType]['label'] ?? strtoupper($memoryType))) ?></div>
                                        <div class="px-4 py-3 text-xs text-slate-600 leading-relaxed whitespace-pre-wrap"><?= h($memoryContent) ?></div>
                                    </div>
                                <?php endforeach; ?>
                                <?php if (
                                    trim((string)($project_memory_docs['agents']['content'] ?? '')) === '' &&
                                    trim((string)($project_memory_docs['readme']['content'] ?? '')) === '' &&
                                    trim((string)($project_memory_docs['todo']['content'] ?? '')) === ''
                                ): ?>
                                    <div class="text-center py-10 bg-slate-50/60 rounded-xl border border-dashed border-slate-200">
                                        <p class="text-xs text-slate-400 font-medium italic">案件運用メモはまだ登録されていません。</p>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
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
                                    <option value="auto" selected>⚡ 自動判定 (本文高速 + 図表補足)</option>
                                    <option value="tiles">🔲 標準 (2x2タイル分割)</option>
                                    <option value="slices">🥞 水平スライス (8分割)</option>
                                    <option value="full">📄 全体のみ (高速・軽量)</option>
                                    <option value="all">🧠 フル解析 (高精度)</option>
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
                        <?php foreach ($documents as $doc): ?>
                            <details class="bg-white border border-slate-200 rounded-2xl shadow-2xs group overflow-hidden transition-all duration-300 ease-in-out hover:shadow-sm">
                                <summary class="p-3.5 px-5 flex justify-between items-center cursor-pointer hover:bg-slate-50/50 transition-colors duration-200 ease-in-out outline-none select-none">
                                    <div class="flex items-center gap-2.5 overflow-hidden pr-2">
                                        <span class="group-open:rotate-90 transition-transform duration-200 ease-in-out text-slate-400 text-[10px] w-4 text-center">▶</span>
                                        <span class="text-xs font-bold text-slate-700 group-hover:text-[#4F5D95] transition-colors duration-200 truncate">📄 <?= h($doc['title']) ?></span>
                                    </div>
                                    <div class="flex items-center gap-2 flex-shrink-0">
                                        <button onclick="event.stopPropagation(); if(typeof window.openPdfTab === 'function') { window.openPdfTab(<?= (int)$doc['id'] ?>, '<?= h(str_replace("'", "\\'", $doc['title'])) ?>', 1); }" class="text-[9px] text-[#4F5D95] hover:bg-indigo-50 border border-slate-200 px-2.5 py-1 rounded-lg font-bold transition-all duration-200 ease-in-out mr-1 shadow-2xs transform active:scale-95">↗ 別タブで開く</button>
                                        <span class="text-[9px] bg-slate-100 border border-slate-200 px-2 py-0.5 rounded font-mono text-slate-400 font-bold">PDF</span>
                                        <button data-doc-id="<?= (int)$doc['id'] ?>" class="btn-delete-pdf text-slate-440 hover:text-red-500 hover:bg-red-50 w-7 h-7 rounded-lg flex items-center justify-center transition-all duration-200 ease-in-out transform active:scale-90" title="この資料を完全に削除">🗑️</button>
                                    </div>
                                </summary>
                                <div class="h-[580px] border-t border-slate-100 bg-slate-50 p-2">
                                    <iframe src="viewer.php?id=<?= h((string)$doc['id']) ?>&page=1" class="w-full h-full border-none rounded-xl shadow-inner bg-white" loading="lazy"></iframe>
                                </div>
                            </details>
                        <?php endforeach; ?>
                        
                        <?php if (empty($documents)): ?>
                            <div id="pdf-empty-state" class="text-center py-12 bg-white rounded-2xl border border-dashed border-slate-200 shadow-2xs">
                                <p class="text-3xl mb-2 opacity-35">📭</p>
                                <p class="text-xs text-slate-400 font-medium italic">登録されているPDF資料はありません。<br>上部のアプローダーから資料を追加してください。</p>
                            </div>
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
                    <textarea name="comment" id="comment-textarea" rows="1" class="w-full border border-slate-200 rounded-xl p-3.5 text-xs outline-none bg-slate-50/50 focus:bg-white focus:border-indigo-400/80 transition-all duration-200 ease-in-out resize-none font-medium text-slate-700 placeholder-slate-400" style="max-height: 220px; overflow-y: auto; height: auto;" placeholder="プロジェクトの進捗や、参考リンク of URL(http...)を入力... (Shift+Enterで改行)"></textarea>
                    <div class="flex justify-between items-center mt-3">
                        <span class="text-[10px] text-slate-400 font-medium ml-1">URLは自動でリンクに変換されます</span>
                        <button type="submit" class="bg-[#4F5D95] text-white px-6 py-2 rounded-xl text-xs font-bold shadow-md hover:bg-[#3f4a7a] hover:shadow-lg transition-all duration-200 ease-in-out transform active:scale-95">送信する</button>
                    </div>
                </form>

                <div class="space-y-4" id="comment-list-container">
                    <?php foreach($comments as $c): ?>
                    <div id="comment-container-<?= (int)$c['id'] ?>" class="bg-white p-4 px-5 rounded-2xl border border-slate-200 shadow-2xs animate-fadeIn hover:shadow-sm transition-shadow duration-200">
                        <div class="flex justify-between items-start mb-2">
                            <div class="flex items-center gap-2.5">
                                <div class="w-6 h-6 rounded-full bg-slate-100 flex items-center justify-center text-[10px] font-black text-slate-500 border border-slate-200/60 shadow-2xs">
                                    <?= mb_substr(h($c['username']), 0, 1) ?>
                                </div>
                                <span class="font-bold text-xs text-slate-700"><?= h($c['username']) ?></span>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="text-[10px] font-mono font-medium text-slate-400"><?= date('Y/m/d H:i', strtotime($c['created_at'])) ?></span>
                                <?php if ((int)$c['user_id'] === (int)$user_id || $role === 'admin'): ?>
                                    <button type="button" onclick="if(typeof window.handleRemoveComment === 'function') window.handleRemoveComment(<?= (int)$c['id'] ?>)" class="text-slate-300 hover:text-red-500 hover:bg-red-50 w-6 h-6 rounded-lg flex items-center justify-center transition-all duration-200 ease-in-out transform active:scale-90" title="コメントを削除">🗑️</button>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="text-xs text-slate-600 font-medium pt-1 leading-relaxed pl-8"><?= makeClickableLinks($c['comment_text']) ?></div>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php if(empty($comments)): ?>
                        <div class="text-center py-12 bg-white rounded-2xl border border-dashed border-slate-200 shadow-2xs">
                            <p class="text-3xl mb-2 opacity-30">💭</p>
                            <p class="text-xs text-slate-400 font-medium italic">まだコメントはありません。<br>プロジェクトに関するメモや進捗を共有しましょう。</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div id="tab-csv" role="tabpanel" class="tab-content <?= $active_tab === 'csv' ? 'active' : '' ?> h-full overflow-y-auto p-6 space-y-6">
                <div class="flex justify-between items-center border-b border-slate-200/60 pb-2 mb-4">
                    <h3 class="text-xs font-black text-slate-500 uppercase tracking-wider flex items-center gap-2"><span>Structured Datasets</span> 構造化CSVデータテーブル</h3>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="if(typeof window.openAppModal === 'function') window.openAppModal('postgres-import-modal')" class="bg-teal-50 hover:bg-teal-100 text-[#00758F] border border-teal-200 px-3 py-1.5 rounded-xl font-bold text-[10px] shadow-2xs flex items-center gap-1 transition-all duration-200 ease-in-out transform active:scale-95">
                            <span>🐘 PostgreSQLから取得</span>
                        </button>

                        <form id="csv-upload-form" onsubmit="window.handleCsvUpload(event);" class="flex items-center gap-2">
                            <label class="bg-white hover:bg-slate-50 text-slate-600 px-3 py-1.5 rounded-xl border border-slate-200 font-bold text-[10px] cursor-pointer shadow-2xs transition-all duration-200 ease-in-out hover:border-[#00758F] transform active:scale-95">
                                <span id="csv-file-label-text">📎 CSVファイルを選択</span>
                                <input type="file" name="csv_file" accept=".csv" class="hidden" onchange="const form = document.getElementById('csv-upload-form'); form.querySelector('button[type=\'submit\']').classList.remove('hidden'); document.getElementById('csv-file-label-text').textContent = '📄 ' + this.files[0].name;">
                            </label>
                            <button type="submit" class="hidden bg-[#00758F] hover:bg-[#005a6e] text-white px-4 py-1.5 rounded-xl font-bold text-[10px] shadow-md transition-all duration-200 ease-in-out transform active:scale-95">インポート開始</button>
                        </form>
                    </div>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-4 gap-4 items-start">
                    <div class="col-span-1 border border-slate-200 rounded-2xl bg-slate-50/60 p-4 space-y-3 shadow-sm">
                        <h4 class="text-[10px] font-black text-slate-400 uppercase tracking-widest pb-1.5 border-b border-slate-200/60">インポート履歴 (<?= count($csv_files) ?>)</h4>
                        <div class="space-y-2 max-h-[400px] overflow-y-auto pr-1 no-scrollbar">
                            <?php foreach ($csv_files as $cf): ?>
                                <div id="csv-item-<?= (int)$cf['id'] ?>" onclick="if(typeof window.loadCsvData === 'function') window.loadCsvData(<?= (int)$cf['id'] ?>, '<?= h(str_replace("'", "\\'", $cf['file_name'])) ?>')" class="p-3 bg-white border border-slate-200 rounded-xl hover:border-[#00758F] hover:shadow-md cursor-pointer shadow-2xs transition-all duration-200 ease-in-out group transform hover:-translate-y-0.5 active:scale-98">
                                    <div class="text-xs font-bold text-slate-700 truncate group-hover:text-[#00758F] transition-colors duration-150 mb-1.5" title="📄 <?= h($cf['file_name']) ?>">📄 <?= h($cf['file_name']) ?></div>
                                    <div class="flex justify-between items-center text-[9px] text-slate-400 font-medium">
                                        <span class="font-mono bg-slate-50 px-1.5 py-0.5 rounded border border-slate-100 font-bold"><?= number_format($cf['row_count']) ?> rows</span>
                                        <span><?= date('m/d H:i', strtotime($cf['created_at'])) ?></span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                            <?php if (empty($csv_files)): ?>
                                <p class="text-[10px] text-slate-400 text-center py-8 italic font-medium">登録済みのCSVはありません。</p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="col-span-1 md:col-span-3 h-full">
                        <div id="csv-viewer-container" class="h-full min-h-[400px]">
                            <div class="text-center py-20 bg-white rounded-2xl border border-dashed border-slate-200 shadow-2xs h-full flex flex-col justify-center items-center">
                                <p class="text-4xl mb-3 opacity-25">📊</p>
                                <p class="text-xs text-slate-400 font-bold leading-relaxed">左側のインポート一覧からCSVファイルを選択するか、<br>上部の接続メニューからデータを取り込んでください。</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <div id="tab-faqs" role="tabpanel" class="tab-content <?= $active_tab === 'faqs' ? 'active' : '' ?> h-full overflow-y-auto p-6 space-y-4">
                <div class="flex justify-between items-center border-b border-slate-200/60 pb-2 mb-4">
                    <h3 class="text-xs font-black text-slate-500 uppercase tracking-wider flex items-center gap-2"><span>Knowledge Base</span> AIナレッジ・FAQ</h3>
                    <button onclick="if(typeof window.openFaqModal === 'function') window.openFaqModal('', '')" class="text-[10px] bg-amber-50 text-amber-700 border border-amber-200 px-3 py-1.5 rounded-xl font-bold shadow-2xs hover:bg-amber-100/80 transition-all duration-200 ease-in-out transform active:scale-95">➕ 手動でナレッジを追加</button>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <?php foreach($faqs as $f): ?>
                        <div class="bg-white p-5 rounded-2xl border border-slate-200 shadow-2xs relative group hover:shadow-sm transition-shadow duration-200">
                            <?php if ((int)$f['created_by'] === (int)$user_id || $role === 'admin'): ?>
                                <button type="button" onclick="if(typeof window.handleDeleteFaq === 'function') window.handleDeleteFaq(<?= (int)$f['id'] ?>)" class="absolute top-3 right-3 text-slate-300 hover:text-red-500 hover:bg-red-50 w-7 h-7 rounded-lg flex items-center justify-center opacity-0 group-hover:opacity-100 transition-all duration-200 ease-in-out transform active:scale-90" title="ナレッジを削除">🗑️</button>
                            <?php endif; ?>
                            
                            <div class="font-extrabold text-slate-800 text-xs mb-3 pb-2 border-b border-slate-100 pr-8 leading-relaxed">Q. <?= h($f['question_summary']) ?></div>
                            <div class="text-xs text-slate-600 font-medium leading-loose whitespace-pre-wrap"><?= h($f['answer_summary']) ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
                <?php if(empty($faqs)): ?>
                    <div class="text-center py-16 bg-white rounded-2xl border border-dashed border-slate-200 shadow-2xs">
                        <p class="text-3xl mb-3 opacity-40">💡</p>
                        <p class="text-xs text-slate-400 font-bold leading-relaxed">チャットの回答にある「📌 ナレッジとして共有」ボタンから、<br>得られた有益な知見をチーム全体へシェアできます。</p>
                    </div>
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
                        <?php foreach($members as $m): ?>
                            <tr class="hover:bg-slate-50/50 transition-colors duration-150 group">
                                <td class="p-4 font-bold text-slate-700 flex items-center gap-2.5">
                                    <div class="w-6 h-6 rounded-full bg-slate-100 border border-slate-200/50 flex items-center justify-center text-[10px]">👤</div>
                                    <?= h($m['username']) ?>
                                </td>
                                <td class="p-4 text-slate-500 font-medium"><?= h($m['department'] ?? '未設定') ?></td>
                                <td class="p-4">
                                    <span class="px-2.5 py-1 rounded-md text-[9px] font-black uppercase tracking-wider <?= $m['role'] === 'manager' ? 'bg-purple-50 text-purple-700 border border-purple-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200' ?>">
                                        <?= h($m['role']) ?>
                                    </span>
                                </td>
                                <td class="p-4 text-center">
                                    <button type="button" onclick="if(typeof window.handleRemoveMember === 'function') window.handleRemoveMember(<?= (int)$m['user_id'] ?>)" class="text-slate-300 hover:text-red-500 hover:bg-red-50 w-7 h-7 rounded-lg flex items-center justify-center mx-auto opacity-0 group-hover:opacity-100 transition-all duration-200 ease-in-out transform active:scale-90" title="プロジェクトから外す">🗑️</button>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if(empty($members)): ?>
                            <tr><td colspan="4" class="p-12 text-center text-slate-400 italic font-medium">アサインされたメンバーはいません。</td></tr>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>

        </div>
    </div>

    <div id="resize-handle" class="h-full w-1 bg-transparent hover:bg-indigo-500/20 active:bg-indigo-500/40 cursor-col-resize z-10 relative transition-colors duration-200"></div>

    <div id="right-panel" class="w-[var(--support-width)] bg-white flex flex-col h-full border-l border-slate-200/60 flex-shrink-0 relative transition-all duration-200">
        <div class="p-4 bg-white/90 backdrop-blur-md border-b border-slate-200/60 flex justify-between items-center gap-2 shadow-2xs relative z-10">
            <div class="flex-shrink-0">
                <p class="font-black text-slate-700 text-[10px] uppercase tracking-widest" title="接続先: <?= h($ollama_host) ?>">AI Assistant Panel</p>
                <p class="text-[8px] text-emerald-600 font-bold uppercase tracking-widest mt-1 flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-emerald-500 animate-pulse"></span> Agentic RAG Pipeline</p>
            </div>
            
            <div class="flex items-center gap-1.5 overflow-hidden">
                <select id="support-prompt-select" class="text-[10px] border border-slate-200 rounded-xl px-2 py-1.5 bg-slate-50/50 hover:bg-white font-bold text-slate-600 tracking-wide max-w-[90px] truncate outline-none transition-all duration-200 ease-in-out cursor-pointer shadow-2xs relative shadow-inner focus:border-indigo-400">
                    <option value="construction_consultant" <?= $default_prompt_mode == 'construction_consultant' ? 'selected' : '' ?>>🏗️ 建設</option>
                    <option value="technical_expert" <?= $default_prompt_mode == 'technical_expert' ? 'selected' : '' ?>>🔬 技術</option>
                    <option value="proofreader" <?= $default_prompt_mode == 'proofreader' ? 'selected' : '' ?>>📝 校正</option>
                    <option value="general_chat" <?= $default_prompt_mode == 'general_chat' ? 'selected' : '' ?>>💬 会話</option>
                </select>
                
                <select id="support-model-select" class="text-[10px] border border-slate-200 rounded-xl px-2 py-1.5 bg-slate-50/50 hover:bg-white font-mono max-w-[100px] truncate outline-none transition-all duration-200 ease-in-out cursor-pointer text-slate-600 shadow-2xs focus:border-indigo-400" title="現在のホスト: <?= h($ollama_host) ?>">
                    <?php foreach ($installed_models as $m): ?>
                        <option value="<?= h($m) ?>" <?= $m == $active_model ? 'selected' : '' ?>><?= h($m) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div id="chat-box" class="flex-1 p-4 space-y-5 overflow-y-auto bg-slate-50/40 no-scrollbar">
            <?php foreach ($chat_history as $chat): ?>
                <?php $timeStr = date('Y/m/d H:i', strtotime($chat['created_at'])); ?>
                <div class="flex gap-3 items-start <?= $chat['role'] === 'assistant' ? '' : 'flex-row-reverse' ?> animate-fadeIn">
                    <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0 shadow-2xs border select-none
                        <?= $chat['role'] === 'assistant' ? 'bg-amber-50 text-amber-700 border-amber-200/40' : 'bg-indigo-50 text-indigo-700 border-indigo-200/40' ?>">
                        <?= $chat['role'] === 'assistant' ? '🤖' : '👤' ?>
                    </div>

                    <div class="chat-message-stack flex flex-col <?= $chat['role'] === 'assistant' ? 'items-start' : 'items-end' ?> gap-0.5 w-full">
                        <div class="flex items-center gap-1.5 px-1">
                            <span class="text-[9px] font-black text-slate-400 uppercase tracking-tight">
                                <?= $chat['role'] === 'assistant' ? 'AI Assistant' : 'You' ?>
                            </span>
                            <span class="text-[8px] text-slate-400 font-mono tracking-tighter"><?= $timeStr ?></span>
                        </div>
                        <div class="chat-message-bubble chat-message-body p-4 markdown-body chat-markdown shadow-2xs border
                            <?= $chat['role'] === 'assistant' ? 'chat-assistant rounded-tl-none border-slate-100' : 'chat-user rounded-tr-none border-[#3b4773] shadow-xs' ?>">
                            
                            <?php if ($chat['role'] === 'assistant'): ?>
                                <?php
                                    $reasoningSteps = $chat_reasoning_steps_by_chat_id[(int)$chat['id']] ?? [];
                                    $reasoningJson = json_encode(
                                        $reasoningSteps,
                                        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
                                    );
                                ?>
                                <div class="chat-raw-message-source hidden"><?= h($chat['message']) ?></div>
                                <script type="application/json" class="chat-reasoning-source"><?= $reasoningJson ?: '[]' ?></script>
                                <div class="ai-text-body markdown-body chat-markdown w-full"></div>
                            <?php else: ?>
                                <div class="chat-raw-message-source hidden"><?= h($chat['message']) ?></div>
                                <div class="user-text-body w-full break-words"><?= h($chat['message']) ?></div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="p-4 pt-2 border-t border-slate-100 bg-white relative z-10 space-y-3">
            <div class="flex flex-wrap gap-1.5 overflow-x-auto no-scrollbar" id="quick-actions-bar">
                <button type="button" onclick="const input=document.getElementById('chat-input'); input.value='登録済みのCSVデータを集計して概要を教えてください。'; input.dispatchEvent(new Event('input')); input.focus();" class="text-[9px] bg-slate-50 hover:bg-slate-100 border border-slate-200/80 rounded-full px-3 py-1 font-bold text-slate-500 shadow-2xs transition-all duration-200 ease-in-out transform hover:border-[#4F5D95] hover:text-[#4F5D95] hover:-translate-y-0.5 hover:shadow-xs hover:scale-[1.02] active:scale-95 active:shadow-inner">📊 データ集計</button>
                <button type="button" onclick="const input=document.getElementById('chat-input'); input.value='この案件に関連する資料PDFから主要な留意点を抽出してください。'; input.dispatchEvent(new Event('input')); input.focus();" class="text-[9px] bg-slate-50 hover:bg-slate-100 border border-slate-200/80 rounded-full px-3 py-1 font-bold text-slate-500 shadow-2xs transition-all duration-200 ease-in-out transform hover:border-[#4F5D95] hover:text-[#4F5D95] hover:-translate-y-0.5 hover:shadow-xs hover:scale-[1.02] active:scale-95 active:shadow-inner">🔍 資料抽出</button>
                <button type="button" onclick="const input=document.getElementById('chat-input'); input.value='これまでの会話内容を簡潔にまとめてください。'; input.dispatchEvent(new Event('input')); input.focus();" class="text-[9px] bg-slate-50 hover:bg-slate-100 border border-slate-200/80 rounded-full px-3 py-1 font-bold text-slate-500 shadow-2xs transition-all duration-200 ease-in-out transform hover:border-[#4F5D95] hover:text-[#4F5D95] hover:-translate-y-0.5 hover:shadow-xs hover:scale-[1.02] active:scale-95 active:shadow-inner">📝 文脈総括</button>
            </div>

            <div class="space-y-1.5 px-1">
                <div class="flex items-center justify-between gap-2">
                    <span class="text-[9px] font-black text-slate-400 uppercase tracking-wider">回答モード</span>
                    <span class="text-[9px] text-slate-400 font-medium truncate">必要な時だけ切替</span>
                </div>
                <div class="grid grid-cols-3 gap-1.5" role="group" aria-label="回答モード">
                    <label class="chat-mode-switch" title="AIが質問を要素分解し、個別に資料を精読してから統合回答を作成します">
                        <input type="checkbox" id="advanced-reasoning-mode" class="sr-only peer">
                        <span class="chat-mode-pill peer-checked:border-indigo-300 peer-checked:bg-indigo-50 peer-checked:text-indigo-700 peer-checked:shadow-sm">
                            <span class="text-[12px]" aria-hidden="true">🧠</span>
                            <span>フル思考</span>
                        </span>
                    </label>
                    <label class="chat-mode-switch" title="必要に応じてMermaidやChart.jsの図表を回答に含めます">
                        <input type="checkbox" id="diagram-mode" class="sr-only peer">
                        <span class="chat-mode-pill peer-checked:border-emerald-300 peer-checked:bg-emerald-50 peer-checked:text-emerald-700 peer-checked:shadow-sm">
                            <span class="text-[12px]" aria-hidden="true">📈</span>
                            <span>図解</span>
                        </span>
                    </label>
                    <label class="chat-mode-switch" title="回答をHTML/CSS報告書としてPDF化し、PDFタブと検索対象へ登録します">
                        <input type="checkbox" id="report-mode" class="sr-only peer">
                        <span class="chat-mode-pill peer-checked:border-amber-300 peer-checked:bg-amber-50 peer-checked:text-amber-700 peer-checked:shadow-sm">
                            <span class="text-[12px]" aria-hidden="true">📄</span>
                            <span>報告書</span>
                        </span>
                    </label>
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
    import * as Support from './assets/js/support.js?v=18';

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

    const initApp = () => {
        if (typeof Support.bindGlobalFunctions === 'function') {
            Support.bindGlobalFunctions();
        }
        if (typeof Support.bindModalEvents === 'function') {
            Support.bindModalEvents();
        }
        if (typeof Support.initResizer === 'function') {
            Support.initResizer();
        }
        if (typeof Support.initChatInput === 'function') {
            Support.initChatInput();
        }
        if (typeof Support.initDebugLogViewer === 'function') {
            Support.initDebugLogViewer();
        }
        if (typeof Support.checkUploadOnLoad === 'function') {
            Support.checkUploadOnLoad();
        }
        if (typeof Support.scrollToBottom === 'function') {
            Support.scrollToBottom();
        }

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
