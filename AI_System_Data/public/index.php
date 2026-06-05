<?php
// =========================================================================
// ★【最重要：変数隔離シールド方式】出力システムの自動パース干渉を100%物理遮断
// =========================================================================
$URL_OLLAMA_HOST = 'ht' . 'tp' . '://' . '127' . '.' . '0.0.1' . ':11434';
$URL_TAILWIND    = 'ht' . 'tps' . '://' . 'cdn' . '.tailwindcss.com';
$URL_MARKED      = 'ht' . 'tps' . '://' . 'cdn' . '.jsdelivr.net/npm/marked/marked.min.js';
$URL_DOMPURIFY   = 'ht' . 'tps' . '://' . 'cdnjs' . '.cloudflare.com/ajax/libs/dompurify/3.0.9/purify.min.js';
$URL_LEAFLET_CSS = 'ht' . 'tps' . '://' . 'unpkg' . '.com/leaflet@1.9.4/dist/leaflet.css';
$URL_LEAFLET_JS  = 'ht' . 'tps' . '://' . 'unpkg' . '.com/leaflet@1.9.4/dist/leaflet.js';
$URL_SVG_XMLNS   = 'ht' . 'tp' . '://' . 'www' . '.w3.org/2000/svg';

/**
 * index.php - 新・統合ダッシュボード画面 (SPA風・非同期画面遷移・インラインプレビュー版)
 * 左側のプロジェクトをクリックした際に、画面をリロードせず右側のみをシームレスに更新します。
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/ProjectAccess.php';
require_once __DIR__ . '/../src/ModelRoleResolver.php';
require_once __DIR__ . '/../src/UserSettingsSessionSynchronizer.php';

// === 1. セキュリティ & 認証 ===
$auth = new Auth($pdo);
if (!$auth->isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$csrfToken = getCsrfToken();
$userId    = $_SESSION['user_id'] ?? null;
$username  = $_SESSION['username'] ?? 'ゲスト';
$userRole  = $_SESSION['role'] ?? 'user';

UserSettingsSessionSynchronizer::sync($pdo, (int)$userId);

// === 2. リクエストパラメータ取得 ===
$selected_project_id = filter_input(INPUT_GET, 'project_id', FILTER_VALIDATE_INT);
$active_tab = filter_input(INPUT_GET, 'tab', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'overview';

// === 3. ダッシュボード用 統計 & 案件一覧の取得 ===
$queryCond = "p.status = 'active'";
$queryParams = [];
$totalCond = "1=1";
$totalParams = [];

if ($userRole !== 'admin') {
    $queryCond .= " AND (p.created_by = :owner_user_id OR EXISTS (
        SELECT 1 FROM project_members pm
        WHERE pm.project_id = p.id AND pm.user_id = :member_user_id
    ))";
    $queryParams['owner_user_id'] = $userId;
    $queryParams['member_user_id'] = $userId;
    $totalCond = "created_by = :total_owner_user_id OR EXISTS (
        SELECT 1 FROM project_members pm
        WHERE pm.project_id = projects.id AND pm.user_id = :total_member_user_id
    )";
    $totalParams['total_owner_user_id'] = $userId;
    $totalParams['total_member_user_id'] = $userId;
}

// 進行中案件数
$stmtActive = $pdo->prepare("SELECT COUNT(*) FROM projects p WHERE {$queryCond}");
$stmtActive->execute($queryParams);
$activeCount = $stmtActive->fetchColumn();

// 全案件数
$stmtTotal = $pdo->prepare("SELECT COUNT(*) FROM projects WHERE {$totalCond}");
$stmtTotal->execute($totalParams);
$totalProjects = $stmtTotal->fetchColumn();
$progressRatio = $totalProjects ? ($activeCount / $totalProjects) * 100 : 0;

// 進行中案件リスト (最大10件)
$sqlProjects = "SELECT p.id, p.project_name, p.status, p.updated_at, p.description, u.username as owner_name
                FROM projects p LEFT JOIN users u ON p.created_by = u.id
                WHERE {$queryCond} ORDER BY p.updated_at DESC LIMIT 10";
$stmtProj = $pdo->prepare($sqlProjects);
$stmtProj->execute($queryParams);
$activeProjects = $stmtProj->fetchAll(PDO::FETCH_ASSOC);

// インデックス登録済み資料の総数
$sqlDocs = "SELECT COUNT(*) FROM documents d JOIN projects p ON d.project_id = p.id WHERE p.status = 'active'";
if ($userRole !== 'admin') {
    $sqlDocs .= " AND (p.created_by = :doc_owner_user_id OR EXISTS (
        SELECT 1 FROM project_members pm
        WHERE pm.project_id = p.id AND pm.user_id = :doc_member_user_id
    ))";
}
$stmtDocs = $pdo->prepare($sqlDocs);
$stmtDocs->execute($userRole !== 'admin' ? [
    'doc_owner_user_id' => $userId,
    'doc_member_user_id' => $userId,
] : []);
$totalDocs = $stmtDocs->fetchColumn();

// 全ユーザーリスト (アサイン機能用)
$all_users = $pdo->query("SELECT id, username, department FROM users ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);

// 隔離環境変数から安全マッピング代入
$resolvedModels     = ModelRoleResolver::resolveUserSettings($_SESSION);
$ollama_host        = $resolvedModels['ollama_host'] ?: rtrim($URL_OLLAMA_HOST, '/');
$default_chat_model = $resolvedModels['main_model'];
$default_model      = ModelRoleResolver::DEFAULT_MAIN_MODEL;
$installed_models = [];
try {
    $ch = curl_init("{$ollama_host}/api/tags");
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 3]);
    $res = curl_exec($ch);
    if ($res && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200) {
        $data = json_decode($res, true);
        foreach ($data['models'] ?? [] as $m) { $installed_models[] = $m['name']; }
    }
    curl_close($ch);
} catch (Exception $e) {}
if (empty($installed_models)) $installed_models = [$default_model];
$active_model = in_array($default_chat_model, $installed_models) ? $default_chat_model : $default_model;
if (!in_array($active_model, $installed_models)) array_unshift($installed_models, $active_model);

// === 4. 選択中プロジェクト (右ペイン) の詳細データ・プレビュー取得 ===
$focused_project = null;
$focused_docs = $comments = $members = $faqs = $csv_files = [];

if ($selected_project_id) {
    $stmtFocus = $pdo->prepare("SELECT * FROM projects WHERE id = ?");
    $stmtFocus->execute([$selected_project_id]);
    $focused_project = $stmtFocus->fetch(PDO::FETCH_ASSOC);

    if ($focused_project) {
        if (!canAccessProject($pdo, (int)$selected_project_id, (int)$userId, $userRole)) {
            $focused_project = null;
        } else {
            try {
                $stmtFocusedDocs = $pdo->prepare("SELECT id, title, file_path FROM documents WHERE project_id = :project_id AND file_path NOT LIKE 'csv_db_record_%' ORDER BY created_at DESC");
                $stmtFocusedDocs->execute(['project_id' => $selected_project_id]);
                $focused_docs = $stmtFocusedDocs->fetchAll(PDO::FETCH_ASSOC);

                $stmtComments = $pdo->prepare("SELECT pc.*, u.username FROM project_comments pc JOIN users u ON pc.user_id = u.id WHERE pc.project_id = :project_id ORDER BY pc.created_at DESC");
                $stmtComments->execute(['project_id' => $selected_project_id]);
                $comments = $stmtComments->fetchAll(PDO::FETCH_ASSOC);

                $stmtMembers = $pdo->prepare("SELECT pm.*, u.username, u.department FROM project_members pm JOIN users u ON pm.user_id = u.id WHERE pm.project_id = :project_id ORDER BY pm.assigned_at ASC");
                $stmtMembers->execute(['project_id' => $selected_project_id]);
                $members = $stmtMembers->fetchAll(PDO::FETCH_ASSOC);

                $stmtFaqs = $pdo->prepare("SELECT pf.*, u.username FROM project_faqs pf LEFT JOIN users u ON pf.created_by = u.id WHERE pf.project_id = :project_id ORDER BY pf.created_at DESC");
                $stmtFaqs->execute(['project_id' => $selected_project_id]);
                $faqs = $stmtFaqs->fetchAll(PDO::FETCH_ASSOC);

                // CSV情報の取得と、プレビュー用先頭5行データの動的フェッチ
                $stmtCsvFiles = $pdo->prepare("SELECT id, file_name, row_count, column_headers, created_at FROM project_csv_files WHERE project_id = :project_id ORDER BY created_at DESC");
                $stmtCsvFiles->execute(['project_id' => $selected_project_id]);
                $csv_files = $stmtCsvFiles->fetchAll(PDO::FETCH_ASSOC);

                $stmtPreviewRows = $pdo->prepare("SELECT row_data FROM project_csv_rows WHERE csv_file_id = :csv_file_id ORDER BY row_index ASC LIMIT 5");
                foreach ($csv_files as &$cf) {
                    $stmtPreviewRows->execute(['csv_file_id' => $cf['id']]);
                    $cf['preview_rows'] = $stmtPreviewRows->fetchAll(PDO::FETCH_ASSOC);
                }
                unset($cf); // 参照渡し解除
            } catch (PDOException $e) {
                error_log("Dashboard query error: " . $e->getMessage());
            }
        }
    }
}

// === 5. HTMLエスケープ用ヘルパー関数 ===
if (!function_exists('h')) {
    function h(?string $str): string {
        return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
    }
}

// プロジェクト全体の情報をシステムプロンプト的なコンテキストとして文字列化
$projectsContextText = "【現在進行中のプロジェクト一覧】\n" . implode("\n", array_map(function($p) {
    $desc = mb_substr(strip_tags((string)$p['description']), 0, 50);
    $owner = $p['owner_name'] ?? 'System';
    return "- {$p['project_name']} (担当: {$owner}, 状態: {$p['status']}) - 概要: {$desc}...";
}, $activeProjects)) . "\n\n【ユーザーの質問】\n";

// === 6. 右パネル描画用クロージャ (Ajax応答と初回レンダリングで共有) ===
$renderRightPanel = function() use (&$focused_project, &$focused_docs, &$csv_files, &$comments, &$faqs, &$members, &$installed_models, &$active_model, &$selected_project_id, &$userId, &$userRole, &$ollama_host, &$URL_SVG_XMLNS) {
?>
    <div id="right-panel-content" class="flex-1 flex flex-col h-full overflow-hidden <?= $selected_project_id ? '' : 'hidden' ?> animate-fadeIn opacity-100 transition-all duration-300">
        <input type="hidden" id="chat-project-id" value="<?= h((string)$selected_project_id) ?>">
        
        <div class="bg-slate-50/80 border-b border-slate-200/60 flex items-end gap-1 px-3 pt-3 flex-shrink-0 overflow-x-auto whitespace-nowrap" id="tab-header" style="scrollbar-width: none;">
            <button onclick="window.switchTab('tab-overview')" id="btn-overview" class="tab-btn active px-4 py-2 text-[11px] font-bold text-slate-500 rounded-t-xl transition-all duration-200 ease-in-out flex-shrink-0 transform active:scale-98">🏠 概要</button>
            <button onclick="window.switchTab('tab-pdf')" id="btn-pdf" class="tab-btn px-4 py-2 text-[11px] font-bold text-slate-500 rounded-t-xl transition-all duration-200 ease-in-out flex-shrink-0 transform active:scale-98">📄 PDF (<?= count($focused_docs) ?>)</button>
            <button onclick="window.switchTab('tab-csv')" id="btn-csv" class="tab-btn px-4 py-2 text-[11px] font-bold text-slate-500 rounded-t-xl transition-all duration-200 ease-in-out flex-shrink-0 transform active:scale-98">📊 CSV (<?= count($csv_files) ?>)</button>
            <button onclick="window.switchTab('tab-comments')" id="btn-comments" class="tab-btn px-4 py-2 text-[11px] font-bold text-slate-500 rounded-t-xl transition-all duration-200 ease-in-out flex-shrink-0 transform active:scale-98">💬 コメント (<?= count($comments) ?>)</button>
            <button onclick="window.switchTab('tab-faqs')" id="btn-faqs" class="tab-btn px-4 py-2 text-[11px] font-bold text-slate-500 rounded-t-xl transition-all duration-200 ease-in-out flex-shrink-0 transform active:scale-98">📚 ナレッジ</button>
            <button onclick="window.switchTab('tab-members')" id="btn-members" class="tab-btn px-4 py-2 text-[11px] font-bold text-slate-500 rounded-t-xl transition-all duration-200 ease-in-out flex-shrink-0 transform active:scale-98">👥 アサイン (<?= count($members) ?>)</button>
        </div>

        <div class="flex-1 overflow-hidden relative bg-[#f8fafc]" id="tab-container">
            
            <div id="tab-overview" class="tab-content active h-full overflow-y-auto p-5 space-y-4 text-xs">
                <?php if ($focused_project): ?>
                <div class="bg-white border border-slate-200/80 rounded-2xl overflow-hidden shadow-sm transition-all duration-300 ease-in-out hover:shadow-md">
                    <div class="bg-slate-50/70 p-3.5 px-5 font-bold text-slate-700 flex justify-between items-center text-xs border-b border-slate-100">
                        <span class="font-extrabold tracking-wide text-slate-600">🏢 業務詳細情報</span>
                        <div class="flex items-center gap-2">
                            <button
                                type="button"
                                onclick="if(typeof window.createProjectChatThread === 'function') window.createProjectChatThread()"
                                class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-[10px] font-extrabold text-slate-500 shadow-2xs transition-all duration-200 ease-in-out hover:border-indigo-300 hover:bg-indigo-50 hover:text-indigo-700 active:scale-95"
                                title="この案件に新しい会話スレッドを作成"
                                aria-label="この案件に新しい会話スレッドを作成"
                            >
                                <span class="text-[12px]" aria-hidden="true">＋</span>
                                <span>新しい会話</span>
                            </button>
                            <button
                                type="button"
                                onclick="if(typeof window.clearProjectChatHistory === 'function') window.clearProjectChatHistory()"
                                class="inline-flex items-center gap-1.5 rounded-xl border border-slate-200 bg-white px-3 py-1.5 text-[10px] font-extrabold text-slate-500 shadow-2xs transition-all duration-200 ease-in-out hover:border-amber-300 hover:bg-amber-50 hover:text-amber-700 active:scale-95"
                                title="この案件のAI会話履歴を削除"
                                aria-label="この案件のAI会話履歴を削除"
                            >
                                <svg xmlns="<?= h((string)$URL_SVG_XMLNS) ?>" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-3.5 h-3.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18M8.25 6V4.875A1.875 1.875 0 0 1 10.125 3h3.75A1.875 1.875 0 0 1 15.75 4.875V6m-8.25 0v11.625A2.25 2.25 0 0 0 9.75 19.875h4.5A2.25 2.25 0 0 0 16.5 17.625V6m-6 3.75v6.75m3-6.75v6.75" />
                                </svg>
                                <span>会話をクリア</span>
                            </button>
                            <a href="support.php?project_id=<?= h((string)$selected_project_id) ?>&tab=overview" class="text-[10px] bg-white border border-slate-200 text-[#4F5D95] hover:bg-indigo-50 border-slate-200/80 hover:border-indigo-300 px-3 py-1.5 rounded-xl shadow-2xs font-extrabold transition-all duration-200 ease-in-out transform active:scale-95">業務支援へ &rarr;</a>
                        </div>
                    </div>
                    <table class="w-full text-left border-collapse text-xs">
                        <tbody class="divide-y divide-slate-100">
                            <tr>
                                <th class="p-4 bg-slate-50/40 w-32 font-bold text-slate-400 text-[11px] tracking-wider uppercase">状態</th>
                                <td class="p-4">
                                    <span class="px-2.5 py-1 text-[9px] font-black rounded-md border tracking-wider bg-emerald-50 text-emerald-700 border-emerald-200">
                                        <?= h(strtoupper($focused_project['status'] ?? '')) ?>
                                    </span>
                                </td>
                            </tr>
                            <tr><th class="p-4 bg-slate-50/40 font-bold text-slate-400 text-[11px] tracking-wider uppercase">業務名</th><td class="p-4 font-black text-slate-800 text-sm"><?= h($focused_project['project_name']) ?></td></tr>
                            <tr><th class="p-4 bg-slate-50/40 font-bold text-slate-400 text-[11px] tracking-wider uppercase">期間</th><td class="p-4 font-semibold text-slate-600"><?= (!empty($focused_project['start_date']) || !empty($focused_project['end_date'])) ? h($focused_project['start_date']) . " 〜 " . h($focused_project['end_date']) : '<span class="text-gray-400 italic font-normal">未設定</span>' ?></td></tr>
                            <tr><th class="p-4 bg-slate-50/40 font-bold text-slate-400 text-[11px] tracking-wider uppercase">概要</th><td class="p-4 leading-relaxed font-medium text-slate-600 whitespace-pre-wrap"><?= h($focused_project['description'] ?: '未入力') ?></td></tr>
                            <tr>
                                <th class="p-4 bg-slate-50/40 font-bold text-slate-400 text-[11px] tracking-wider uppercase align-top">場所</th>
                                <td class="p-4">
                                    <div class="mb-3 font-semibold text-slate-700"><?= h($focused_project['address'] ?: '未登録') ?></div>
                                    <?php if (!empty($focused_project['latitude']) && !empty($focused_project['longitude'])): ?>
                                        <div id="overview-map" class="w-full h-48 rounded-xl border border-slate-200 overview-map-container mt-2 shadow-inner"></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>

            <div id="tab-pdf" class="tab-content h-full overflow-y-auto p-5 space-y-3">
                <h4 class="text-xs font-black text-slate-500 border-b border-slate-200/60 pb-2 flex items-center gap-1.5">📄 登録済み資料PDF プレビュー</h4>
                <div class="space-y-2.5">
                    <?php foreach ($focused_docs as $doc): ?>
                        <details class="bg-white border border-slate-200 rounded-2xl shadow-2xs group overflow-hidden transition-all duration-300 ease-in-out hover:shadow-sm">
                            <summary class="p-3 px-5 flex justify-between items-center cursor-pointer hover:bg-slate-50/50 transition-colors duration-200 ease-in-out outline-none select-none">
                                <div class="flex items-center gap-2 overflow-hidden pr-2">
                                    <span class="group-open:rotate-90 transition-transform duration-200 ease-in-out text-slate-400 text-[10px]">▶</span>
                                    <span class="text-xs font-bold text-slate-700 truncate">📄 <?= h($doc['title']) ?></span>
                                </div>
                                <div class="flex items-center gap-2 flex-shrink-0">
                                    <span class="text-[9px] bg-slate-100 border border-slate-200 px-2 py-0.5 rounded font-mono text-slate-400 font-bold">PDF</span>
                                    <a href="support.php?project_id=<?= $selected_project_id ?>" class="text-[9px] text-blue-600 bg-blue-50 border border-blue-100 hover:bg-blue-100 hover:border-blue-300 px-2.5 py-1 rounded-lg font-bold shadow-2xs transition-all duration-200 ease-in-out transform active:scale-95" onclick="event.stopPropagation()">AI分析へ &rarr;</a>
                                </div>
                            </summary>
                            <div class="h-[450px] border-t border-slate-100 bg-slate-50 p-1.5">
                                <iframe src="viewer.php?id=<?= h((string)$doc['id']) ?>&page=1" class="w-full h-full border-none rounded-xl shadow-inner bg-white" loading="lazy"></iframe>
                            </div>
                        </details>
                    <?php endforeach; ?>
                    <?php if (empty($focused_docs)): ?>
                        <div class="text-center py-12 bg-white rounded-2xl border border-dashed border-slate-200 shadow-2xs">
                            <p class="text-xs text-slate-400 font-medium italic">登録されているPDF資料はありません。</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div id="tab-csv" class="tab-content h-full overflow-y-auto p-5 space-y-3">
                <h4 class="text-xs font-black text-slate-500 border-b border-slate-200/60 pb-2 flex items-center gap-1.5">📊 構造化CSVデータ プレビュー</h4>
                <div class="space-y-2.5">
                    <?php foreach ($csv_files as $cf): ?>
                        <details class="bg-white border border-slate-200 rounded-2xl shadow-2xs group overflow-hidden transition-all duration-300 ease-in-out hover:shadow-sm" 
                                 ontoggle="if(this.open) window.loadCsvDataDashboard(<?= $cf['id'] ?>, 'csv-container-<?= $cf['id'] ?>')">
                            <summary class="p-3 px-5 flex justify-between items-center cursor-pointer hover:bg-slate-50/50 transition-colors duration-200 ease-in-out outline-none select-none">
                                <div class="flex items-center gap-2 overflow-hidden pr-2">
                                    <span class="group-open:rotate-90 transition-transform duration-200 ease-in-out text-slate-400 text-[10px]">▶</span>
                                    <span class="text-xs font-bold text-slate-700 truncate">📊 <?= h($cf['file_name']) ?></span>
                                </div>
                                <div class="flex items-center gap-2 flex-shrink-0">
                                    <span class="text-[9px] text-slate-400 font-mono bg-slate-50 px-2 py-0.5 rounded border border-slate-100 font-bold"><?= number_format($cf['row_count']) ?> 行</span>
                                    <a href="support.php?project_id=<?= $selected_project_id ?>&tab=csv" class="text-[9px] text-emerald-600 bg-emerald-50 border border-emerald-100 hover:bg-emerald-100 hover:border-emerald-300 px-2.5 py-1 rounded-lg font-bold shadow-2xs transition-all duration-200 ease-in-out transform active:scale-95" onclick="event.stopPropagation()">集計・グラフ化 &rarr;</a>
                                </div>
                            </summary>
                            <div class="border-t border-slate-100 overflow-x-auto overflow-y-auto max-h-[400px] no-scrollbar bg-slate-50">
                                <div id="csv-container-<?= $cf['id'] ?>" class="p-6 text-center text-[10px] text-slate-400 font-bold">
                                    データを読み込み中...
                                </div>
                            </div>
                        </details>
                    <?php endforeach; ?>
                    <?php if (empty($csv_files)): ?>
                        <div class="text-center py-12 bg-white rounded-2xl border border-dashed border-slate-200 shadow-2xs">
                            <p class="text-xs text-slate-400 font-medium italic">取り込まれたデータテーブルはありません。</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div id="tab-comments" class="tab-content h-full overflow-y-auto p-5 space-y-4">
                <h4 class="text-xs font-black text-slate-500 border-b border-slate-200/60 pb-2 flex items-center gap-1.5">💬 プロジェクト進捗コメント</h4>
                <form id="comment-form" onsubmit="window.handleAddComment(event)" class="bg-white p-4 rounded-2xl shadow-2xs border border-slate-200/80 transition-all duration-200 ease-in-out focus-within:shadow-sm">
                    <textarea name="comment" rows="2" class="w-full border border-slate-200 rounded-xl p-3 text-xs outline-none bg-slate-50/40 focus:bg-white focus:border-indigo-400/80 transition-all duration-200 font-medium text-slate-700 placeholder-slate-400" placeholder="進捗や参考リンクを書き込む..." required></textarea>
                    <div class="flex justify-end mt-2.5"><button type="submit" class="bg-[#4F5D95] text-white px-5 py-1.5 rounded-xl text-xs font-bold shadow-2xs hover:bg-[#3f4a7a] hover:shadow-xs transition-all duration-200 ease-in-out transform active:scale-[0.98]">送信</button></div>
                </form>
                <div id="comments-container" class="space-y-3">
                    <?php foreach ($comments as $c): ?>
                        <div id="comment-container-<?= $c['id'] ?>" class="bg-white p-4 px-5 rounded-2xl border border-slate-200 shadow-2xs text-xs animate-fadeIn hover:shadow-2xs transition-shadow duration-200">
                            <div class="flex justify-between text-[10px] text-slate-400 border-b border-slate-100 pb-2 mb-2 font-bold">
                                <span class="font-extrabold text-slate-700 flex items-center gap-2"><span class="w-4 h-4 bg-slate-100 border border-slate-200 rounded-full text-center text-[8px] flex items-center justify-center">👤</span> <?= h($c['username']) ?></span>
                                <div class="flex items-center gap-2">
                                    <span class="font-mono font-medium"><?= date('Y/m/d H:i', strtotime($c['created_at'])) ?></span>
                                    <?php if ($c['user_id'] == $userId || $userRole === 'admin'): ?>
                                        <button type="button" onclick="window.handleRemoveComment(<?= $c['id'] ?>)" class="text-slate-300 hover:text-red-500 hover:bg-red-50 w-5 h-5 rounded flex items-center justify-center transition-colors duration-200">🗑️</button>
                                    <?php endif; ?>
                                </div>
                            </div>
                            <div class="text-[11px] text-slate-600 font-medium whitespace-pre-wrap leading-relaxed pl-6"><?= h($c['comment_text']) ?></div>
                        </div>
                    <?php endforeach; ?>
                    <?php if (empty($comments)): ?>
                        <p class="text-[10px] text-slate-400 italic text-center py-8 empty-msg font-medium">コメントはまだありません。</p>
                    <?php endif; ?>
                </div>
            </div>

            <div id="tab-faqs" class="tab-content h-full overflow-y-auto p-5 space-y-3">
                <h4 class="text-xs font-black text-slate-500 border-b border-slate-200/60 pb-2 flex items-center gap-1.5">📚 アサイン済みナレッジ</h4>
                <div class="space-y-3">
                    <?php foreach ($faqs as $f): ?>
                        <details class="bg-white border border-slate-200 rounded-2xl shadow-2xs group outline-none overflow-hidden transition-all duration-200 ease-in-out hover:shadow-sm">
                            <summary class="p-3.5 px-5 text-xs font-extrabold text-slate-800 cursor-pointer hover:bg-slate-50/50 transition-colors duration-200 ease-in-out flex items-start gap-2.5 outline-none select-none">
                                <span class="group-open:rotate-90 transition-transform duration-200 ease-in-out text-slate-400 text-[10px] mt-0.5">▶</span>
                                <span class="leading-snug">Q. <?= h($f['question_summary']) ?></span>
                            </summary>
                            <div class="p-4 pt-1 border-t border-slate-100 bg-slate-50/30 font-medium text-slate-600 leading-relaxed text-[11px] whitespace-pre-wrap"><?= h($f['answer_summary']) ?></div>
                        </details>
                    <?php endforeach; ?>
                    <?php if (empty($faqs)): ?>
                        <div class="text-center py-12 bg-white rounded-2xl border border-dashed border-slate-200 shadow-2xs">
                            <p class="text-xs text-slate-400 font-medium italic">登録済みのナレッジ・FAQはありません。</p>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div id="tab-members" class="tab-content h-full overflow-y-auto p-5 space-y-3">
                <div class="flex justify-between items-center border-b border-slate-200/60 pb-2 mb-2">
                    <h4 class="text-xs font-black text-slate-500 uppercase tracking-wider flex items-center gap-1.5">👥 アサインメンバー (<?= count($members) ?>)</h4>
                    <button onclick="window.openAppModal('add-member-modal')" class="text-[10px] bg-indigo-50 text-indigo-700 border border-indigo-200 px-3 py-1.5 rounded-xl font-bold shadow-2xs hover:bg-indigo-100 border-indigo-300 transition-colors duration-200 ease-in-out transform active:scale-95"><span>➕</span> 追加</button>
                </div>
                <div class="bg-white border border-slate-200 rounded-2xl overflow-hidden shadow-2xs">
                    <table class="w-full text-xs text-left">
                        <thead><tr class="bg-slate-50 text-slate-500 border-b border-slate-200/60"><th class="p-3.5 font-extrabold tracking-wide uppercase text-[10px]">名前</th><th class="p-3.5 font-extrabold tracking-wide uppercase text-[10px]">役割</th><th class="p-3.5 font-extrabold tracking-wide uppercase text-[10px] w-16 text-center">操作</th></tr></thead>
                        <tbody class="divide-y divide-slate-100">
                            <?php foreach ($members as $m): ?>
                                <tr class="hover:bg-slate-50/50 transition-colors duration-200 ease-in-out group">
                                    <td class="p-3.5 font-bold text-slate-700 flex items-center gap-2.5">
                                        <div class="w-6 h-6 rounded-full bg-slate-100 border border-slate-200/50 flex items-center justify-center text-[10px]">👤</div>
                                        <?= h($m['username']) ?>
                                    </td>
                                    <td class="p-3.5 whitespace-nowrap text-slate-500 font-medium"><?= h($m['department'] ?? '未設定') ?></td>
                                    <td class="p-3.5">
                                        <span class="px-2 py-0.5 rounded text-[8px] font-black uppercase tracking-wider <?= $m['role'] === 'manager' ? 'bg-purple-50 text-purple-700 border border-purple-200' : 'bg-emerald-50 text-emerald-700 border border-emerald-200' ?>">
                                            <?= h($m['role']) ?>
                                        </span>
                                    </td>
                                    <td class="p-3.5 text-center">
                                        <button type="button" onclick="window.handleRemoveMember(<?= $m['user_id'] ?>)" class="text-slate-300 hover:text-red-500 hover:bg-red-50 w-6 h-6 rounded-lg flex items-center justify-center mx-auto opacity-0 group-hover:opacity-100 transition-all duration-200 ease-in-out" title="メンバーから外す">🗑️</button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                            <?php if (empty($members)): ?>
                                <tr><td colspan="4" class="p-6 text-center text-slate-400 font-medium italic">アサインメンバーはいません。</td></tr>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>

    <div id="right-panel-placeholder" class="flex-1 flex flex-col items-center justify-center p-8 text-center bg-slate-50/40 <?= $selected_project_id ? 'hidden' : '' ?> animate-fadeIn">
        <div class="w-16 h-16 rounded-full bg-slate-100 flex items-center justify-center text-2xl mb-4 border border-slate-200/60 shadow-inner select-none">🏗️</div>
        <h3 class="text-xs font-black text-slate-700 mb-1">業務詳細プレビュー</h3>
        <p class="text-[11px] text-slate-400 max-w-[320px] leading-relaxed mb-5 font-medium">
            左側のリストから対象の案件をクリックしてください。<br>
            該当案件の進捗や地図、資料、各種データを一撃で展開し、ダッシュボード上で直接内容を確認できます。
        </p>
        <button onclick="window.location.href='support.php'" class="mt-4 px-6 py-2.5 bg-gradient-to-br from-[#4F5D95] to-indigo-600 text-white font-bold rounded-xl text-xs shadow-sm hover:shadow-md transition-all duration-200 ease-in-out transform active:scale-[0.98]">業務支援コンソールを起動</button>
    </div>

    <div id="right-panel-chat" class="flex-1 flex flex-col h-full overflow-hidden hidden bg-slate-50">
        <div class="p-4 bg-white/90 backdrop-blur-md border-b border-slate-200/60 flex justify-between items-center gap-2 shadow-2xs relative z-10">
            <div class="flex-shrink-0">
                <p class="font-black text-slate-700 text-[10px] uppercase tracking-widest">AI Consultant Console</p>
                <p class="text-[8px] text-[#0f766e] font-bold uppercase tracking-widest mt-1 flex items-center gap-1.5"><span class="w-1.5 h-1.5 rounded-full bg-teal-500 animate-pulse"></span> GLOBAL SEARCH ACTIVE</p>
            </div>
            <div class="flex items-center gap-1 overflow-hidden">
                <select id="global-model-select" class="text-[10px] border border-slate-200 rounded-xl px-2 py-1.5 bg-slate-50/50 hover:bg-white font-mono max-w-[120px] truncate outline-none transition-all duration-200 ease-in-out cursor-pointer text-slate-600 shadow-2xs">
                    <?php foreach ($installed_models as $m): ?>
                        <option value="<?= h($m) ?>" <?= $m == $active_model ? 'selected' : '' ?>><?= h($m) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div id="global-chat-box" class="flex-1 p-4 space-y-4 overflow-y-auto no-scrollbar bg-slate-50/40">
            <div class="flex gap-3 items-start animate-fadeIn">
                <div class="w-7 h-7 rounded-full bg-amber-50 text-amber-700 border border-amber-200/40 flex items-center justify-center text-xs font-bold flex-shrink-0 shadow-2xs select-none">🤖</div>
                <div class="flex flex-col items-start max-w-[85%] gap-0.5">
                    <span class="text-[9px] font-black text-slate-400 uppercase tracking-tight ml-1">AI Assistant</span>
                    <div class="bg-white border border-slate-100 p-3.5 rounded-2xl rounded-tl-none shadow-2xs text-xs font-medium text-slate-700 leading-relaxed">
                        システム全体の情報を横断して質問にお答えします。何について知りたいですか？
                    </div>
                </div>
            </div>
        </div>

        <div class="p-4 border-t border-slate-100 bg-white relative z-10">
            <form id="global-chat-form" onsubmit="window.handleGlobalChat(event)" class="flex items-end gap-2 bg-slate-50 rounded-2xl px-4 py-2.5 border border-slate-200/80 focus-within:border-teal-400/80 focus-within:bg-white focus-within:shadow-xl focus-within:shadow-teal-500/5 transition-all duration-300 ease-in-out">
                <textarea id="global-chat-input" class="flex-1 bg-transparent border-none outline-none focus:outline-none focus:ring-0 text-xs px-0.5 py-1 resize-none overflow-y-auto leading-relaxed text-slate-700 placeholder-slate-400/80" 
                          rows="1" placeholder="システム全体に関する質問... (Enterで送信 / Shift+Enterで改行)" required></textarea>
                <button type="submit" class="text-[#0f766e] hover:scale-102 active:scale-[0.98] transition-all duration-200 ease-in-out mb-0.5 flex-shrink-0 p-1 bg-white rounded-xl shadow-2xs border border-slate-200 w-7 h-7 flex items-center justify-center group" title="送信">
                    <svg xmlns="<?= $URL_SVG_XMLNS ?>" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-3.5 h-3.5 transform group-hover:-translate-y-0.5 transition-transform duration-200 ease-in-out text-[#0f766e]">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 10.5 12 3m0 0 7.5 7.5M12 3v18" />
                    </svg>
                </button>
            </form>
        </div>
    </div>
<?php
}; // End of $renderRightPanel

// === 7. Ajax (Fetch API) リクエスト処理 ===
$isAjax = !empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) == 'xmlhttprequest';

if ($isAjax) {
    ob_start();
    $renderRightPanel();
    $html = ob_get_clean();
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'html' => $html,
        'lat' => $focused_project && !empty($focused_project['latitude']) ? (float)$focused_project['latitude'] : null,
        'lng' => $focused_project && !empty($focused_project['longitude']) ? (float)$focused_project['longitude'] : null,
        'projName' => $focused_project ? $focused_project['project_name'] : ''
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>ダッシュボード | TEPSCO Routines</title>
    <meta name="csrf-token" content="<?= h($csrfToken) ?>">
    
    <div id="support-config" 
         data-csrf-token="<?= h($csrfToken) ?>" 
         data-project-id="<?= h((string)$selected_project_id) ?>"
         data-focused-lat="<?= $focused_project && !empty($focused_project['latitude']) ? h($focused_project['latitude']) : '' ?>"
         data-focused-lng="<?= $focused_project && !empty($focused_project['longitude']) ? h($focused_project['longitude']) : '' ?>"
         data-focused-name="<?= $focused_project ? h($focused_project['project_name']) : '' ?>"
         data-projects-context="<?= h($projectsContextText) ?>">
    </div>

    <script src="<?= $URL_TAILWIND ?>"></script>
    <script src="<?= $URL_MARKED ?>"></script>
    <script src="<?= $URL_DOMPURIFY ?>"></script>
    <link rel="stylesheet" href="<?= $URL_LEAFLET_CSS ?>" />
    <script src="<?= $URL_LEAFLET_JS ?>"></script>

    <link rel="stylesheet" href="assets/css/styles.css">
    <style>
        #right-panel { transition: width 0.3s ease, min-width 0.3s ease, max-width 0.3s ease, flex-basis 0.3s ease; }
        #right-panel.collapsed { width: 0px !important; min-width: 0px !important; max-width: 0px !important; flex-basis: 0px !important; border-left-width: 0px !important; }
        
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
        
        details > summary { list-style: none; }
        details > summary::-webkit-details-marker { display: none; }
        .overview-map-container { position: relative; z-index: 0; }
        
        :root { --support-width: 550px; }

        .markdown-body {
            font-size: 13px;
            line-height: 1.7;
            color: #334155;
            word-wrap: break-word;
        }
        .markdown-body h1, .markdown-body h2, .markdown-body h3, .markdown-body h4 {
            color: #0f766e;
            font-weight: 700;
            margin-top: 1.2em;
            margin-bottom: 0.6em;
        }
        .markdown-body h1, .markdown-body h2 {
            border-bottom: 1px solid #e2e8f0;
            padding-bottom: 0.3em;
        }
        .markdown-body h1 { font-size: 1.35em; }
        .markdown-body h2 { font-size: 1.25em; }
        .markdown-body h3 { font-size: 1.15em; }
        
        .markdown-body p { margin-bottom: 0.8em; }
        .markdown-body p:last-child { margin-bottom: 0; }
        
        .markdown-body ul, .markdown-body ol {
            padding-left: 1.5em;
            margin-bottom: 0.8em;
        }
        .markdown-body ul { list-style-type: disc; }
        .markdown-body ol { list-style-type: decimal; }
        .markdown-body li { margin-bottom: 0.3em; }
        
        .markdown-body table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 1em;
            font-size: 12px;
        }
        .markdown-body th, .markdown-body td {
            border: 1px solid #cbd5e1;
            padding: 6px 10px;
        }
        .markdown-body th { background-color: #f8fafc; font-weight: bold; }
        .markdown-body tr:nth-child(even) { background-color: #f1f5f9; }
        
        .markdown-body code {
            font-family: Consolas, Monaco, monospace;
            background-color: #f1f5f9;
            padding: 0.2em 0.4em;
            border-radius: 4px;
            font-size: 0.9em;
            color: #db2777;
        }
        .markdown-body pre {
            background-color: #1e293b;
            color: #f8fafc;
            padding: 1em;
            border-radius: 8px;
            overflow-x: auto;
            margin-bottom: 1em;
        }
        .markdown-body pre code {
            background-color: transparent;
            color: inherit;
            padding: 0;
        }
        
        .markdown-body blockquote {
            border-left: 4px solid #cbd5e1;
            padding-left: 1em;
            color: #64748b;
            font-style: italic;
            margin-bottom: 1em;
        }
    </style>
</head>
<body class="bg-[#f8fafc] min-h-screen flex flex-col overflow-hidden text-slate-800">

<?php include __DIR__ . '/templates/header.php'; ?>

<main class="flex-1 flex overflow-hidden h-[calc(100vh-72px)] gap-px bg-slate-200/50 w-full">
    
    <div class="flex-1 bg-[#f8fafc] p-6 overflow-y-auto no-scrollbar" role="region" aria-label="Dashboard Overview">
        <div class="flex items-center justify-between border-b border-slate-200/60 pb-3 mb-6">
            <h2 class="text-[11px] font-black text-slate-400 uppercase tracking-widest">Dashboard Overview</h2>
            <span class="text-[11px] text-slate-400 font-medium">ようこそ、安定稼働中環境です</span>
        </div>

        <div id="ai-concierge-board" class="bg-white border border-slate-200/60 rounded-2xl shadow-2xs mb-6 flex overflow-hidden min-h-[76px] transition-all duration-300 ease-in-out">
            <div class="w-1 bg-[#0f766e] self-stretch flex-shrink-0"></div>
            <div class="p-4.5 flex flex-col justify-center w-full">
                <div id="ai-concierge-text" class="text-xs font-semibold text-slate-600 leading-relaxed animate-pulse flex items-center gap-2">
                    <span class="inline-block w-3 h-3 border-2 border-[#0f766e] border-t-transparent rounded-full animate-spin flex-shrink-0"></span>
                    <span>🧠 本日の業務状況をAIコンシェルジュが自律走査・分析中...</span>
                </div>
                <div id="ai-concierge-actions" class="mt-3 flex flex-wrap gap-2 hidden opacity-0 transition-all duration-200 ease-in-out">
                    <a href="support.php" class="text-[10px] bg-slate-50 hover:bg-slate-100 border border-slate-200/80 rounded-full px-3 py-1 font-extrabold text-slate-600 shadow-2xs transition-all duration-200 ease-in-out transform active:scale-95 flex items-center gap-1">📊 業務支援コンソールを即座に起動</a>
                    <button type="button" onclick="window.openGlobalChat()" class="text-[10px] bg-slate-50 hover:bg-slate-100 border border-slate-200/80 rounded-full px-3 py-1 font-extrabold text-slate-600 shadow-2xs transition-all duration-200 ease-in-out transform active:scale-95 flex items-center gap-1">💬 全体に横断質問する</button>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-6">
            <div onclick="window.location.href='support.php'" class="bg-gradient-to-br from-blue-50/30 to-transparent p-5 rounded-2xl border border-slate-200/60 bg-white shadow-2xs flex flex-col justify-between group cursor-pointer transition-all duration-200 ease-in-out hover:scale-[1.01] hover:shadow-md hover:border-indigo-200/80 active:scale-[0.99]">
                <div class="flex justify-between items-start mb-3">
                    <div>
                        <p class="text-[10px] font-black text-blue-600 uppercase tracking-widest flex items-center gap-1.5">
                            <span class="relative flex h-1.5 w-1.5">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-blue-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-blue-500"></span>
                            </span>
                            進行中業務
                        </p>
                        <p class="text-sm text-slate-500 font-bold mt-1.5">
                            <span class="text-3xl text-slate-800 font-black mr-1 group-hover:text-blue-600 transition-colors duration-200"><?= number_format($activeCount) ?></span> 件
                        </p>
                    </div>
                    <button class="text-[10px] bg-white text-blue-600 border border-slate-200 font-extrabold px-2.5 py-1.5 rounded-xl shadow-2xs group-hover:bg-blue-600 group-hover:text-white group-hover:border-blue-600 transition-all duration-200 ease-in-out transform active:scale-95 cursor-pointer">業務支援へ &rarr;</button>
                </div>
                <div class="mt-2">
                    <div class="w-full bg-slate-100 rounded-full h-1.5 overflow-hidden shadow-inner">
                        <div class="bg-gradient-to-r from-blue-500 to-indigo-600 h-1.5 rounded-full transition-all duration-500" style="width: <?= $progressRatio ?>%;"></div>
                    </div>
                    <div class="flex justify-between items-center text-[9px] text-slate-400 mt-2 font-bold tracking-tight">
                        <span>全 <?= $totalProjects ?> 件中</span>
                        <span class="font-mono text-blue-600"><?= round($progressRatio, 1) ?>%</span>
                    </div>
                </div>
            </div>

            <div class="bg-white p-5 rounded-2xl border border-slate-200/60 shadow-2xs flex flex-col justify-between transition-all duration-200 ease-in-out hover:scale-[1.01] hover:shadow-md hover:border-indigo-200/80 active:scale-[0.99]">
                <div>
                    <p class="text-slate-400 font-bold mb-1 text-[10px] uppercase tracking-wider">Indexed Documents</p>
                    <p class="text-3xl font-black text-slate-800"><?= number_format($totalDocs) ?> <span class="text-xs font-normal text-slate-400">件</span></p>
                </div>
                <div class="pt-3 border-t border-slate-100 flex items-center justify-between text-[9px] text-emerald-600 font-bold">
                    <span class="flex items-center gap-1.5"><span class="w-1.5 h-1.5 bg-emerald-500 rounded-full animate-pulse"></span> オンプレミスRAG稼働中</span>
                    <span class="text-slate-400 font-mono font-normal">v1.2.5</span>
                </div>
            </div>

            <div class="bg-gradient-to-br from-emerald-50/30 to-transparent p-5 rounded-2xl border border-slate-200/60 bg-white shadow-2xs flex flex-col justify-between transition-all duration-200 ease-in-out hover:scale-[1.01] hover:shadow-md hover:border-indigo-200/80 active:scale-[0.99]">
                <div class="flex justify-between items-start mb-3">
                    <div>
                        <p class="text-[10px] font-black text-emerald-600 uppercase tracking-widest flex items-center gap-1.5">
                            <span class="relative flex h-1.5 w-1.5">
                                <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                                <span class="relative inline-flex rounded-full h-1.5 w-1.5 bg-emerald-500"></span>
                            </span>
                            AIアシスタント
                        </p>
                        <p class="text-sm text-slate-500 font-bold mt-1.5">
                            <span class="text-2xl text-slate-800 font-black mr-1">横断検索</span>
                        </p>
                    </div>
                    <button onclick="window.openGlobalChat()" class="text-[10px] bg-white text-emerald-600 border border-slate-200 font-extrabold px-2.5 py-1.5 rounded-xl hover:bg-emerald-600 hover:text-white hover:border-emerald-600 shadow-2xs transition-all duration-200 ease-in-out transform active:scale-95 cursor-pointer">チャット開始</button>
                </div>
                <div class="mt-2 pt-3 border-t border-emerald-100/60">
                    <div class="flex justify-between items-center text-[9px] text-slate-400 font-bold">
                        <span>システム全体の全ナレッジを自律巡回解析</span>
                    </div>
                </div>
            </div>
        </div>

        <div class="bg-white rounded-2xl border border-slate-200/60 shadow-2xs overflow-hidden mb-6 transition-all duration-200 ease-in-out">
            <div class="p-3.5 px-5 bg-slate-50/70 border-b border-slate-200/60 flex justify-between items-center">
                <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Active Projects</span>
                <span class="text-[10px] text-[#4F5D95] font-black bg-indigo-50/60 px-2.5 py-0.5 rounded-lg border border-indigo-100">💡 クリックで詳細コンソールを非同期展開</span>
            </div>
            
            <div class="divide-y divide-slate-100" id="project-list">
                <?php if (empty($activeProjects)): ?>
                    <div class="p-12 text-center text-xs text-slate-400 italic">進行中のプロジェクトはありません。</div>
                <?php else: ?>
                    <?php foreach ($activeProjects as $proj): ?>
                        <?php $selected_project_id_int = isset($selected_project_id) ? (int)$selected_project_id : 0; ?>
                        <?php $isFocused = ($selected_project_id_int === (int)$proj['id']); ?>
                        <div class="project-list-item p-4 flex flex-col md:flex-row md:items-center justify-between cursor-pointer transition-all duration-200 ease-in-out group <?= $isFocused ? 'bg-slate-100 text-slate-900 font-extrabold shadow-2xs pl-3' : 'bg-white hover:bg-slate-100/70' ?>"
                             data-project-id="<?= (int)$proj['id'] ?>"
                             onclick="window.loadProjectDetails(<?= $proj['id'] ?>, this)">
                            
                            <div class="flex items-start gap-4 flex-1 pr-4">
                                <div class="project-icon w-9 h-9 rounded-xl flex items-center justify-center text-xs flex-shrink-0 shadow-2xs transition-all duration-200 ease-in-out <?= $isFocused ? 'bg-blue-600 text-white' : 'bg-slate-100 text-slate-500 group-hover:bg-blue-50 group-hover:text-blue-600' ?>">
                                    🏗️
                                </div>
                                <div class="space-y-1">
                                    <h3 class="text-xs font-bold text-slate-800 group-hover:text-blue-600 transition-colors duration-200 ease-in-out flex items-center gap-2">
                                        <?= h($proj['project_name']) ?>
                                    </h3>
                                    <?php if (!empty($proj['description'])): ?>
                                        <div class="text-[10px] text-slate-400 font-medium leading-relaxed max-w-3xl">
                                            <?= h(mb_substr(strip_tags($proj['description']), 0, 120)) ?>...
                                        </div>
                                    <?php else: ?>
                                        <p class="text-[10px] text-slate-400 italic font-medium">（業務概要は未入力です）</p>
                                    <?php endif; ?>
                                </div>
                            </div>
                            
                            <div class="flex items-center gap-4 mt-3 md:mt-0 text-[10px] text-slate-400 flex-shrink-0 font-mono">
                                <div class="flex items-center gap-2 font-sans">
                                    <span class="px-2 py-0.5 text-[8px] font-black rounded-md border tracking-wider bg-emerald-50 text-emerald-700 border-emerald-200">
                                        <?= h(strtoupper($proj['status'])) ?>
                                    </span>
                                    <span class="text-slate-500 bg-slate-100 px-1.5 py-0.5 rounded font-bold">担当: <?= h($proj['owner_name'] ?? 'System') ?></span>
                                </div>
                                <span class="font-medium">更新: <?= date('Y/m/d H:i', strtotime($proj['updated_at'])) ?></span>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div id="resize-handle" class="h-full w-1 bg-transparent hover:bg-indigo-500/20 active:bg-indigo-500/40 cursor-col-resize z-20 relative flex items-center justify-center transition-colors duration-200">
        <button id="toggle-panel-btn" class="absolute w-5 h-8 bg-white border border-slate-200 rounded-lg shadow-2xs flex items-center justify-center text-[9px] font-bold text-slate-400 outline-none z-30 transition-all duration-200 ease-in-out transform active:scale-95">▶</button>
    </div>

    <div id="right-panel" class="w-[var(--support-width)] bg-white flex flex-col h-full border-l border-slate-200/60 overflow-hidden shadow-2xs flex-shrink-0 relative transition-all duration-200 ease-in-out">
        <?php $renderRightPanel(); ?>
    </div>
</main>

<?php include_once __DIR__ . '/templates/modals.php'; ?>

<div id="chart-max-modal" class="fixed inset-0 bg-slate-950/40 hidden items-center justify-center z-[110] p-4 animate-fadeIn backdrop-blur-xs transition-all duration-200 ease-in-out">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-5xl h-[85vh] flex flex-col overflow-hidden">
        <div class="bg-slate-50/80 px-6 py-4 border-b flex justify-between items-center flex-shrink-0">
            <h3 class="text-xs font-black text-slate-600 uppercase tracking-widest flex items-center gap-2">📊 集計・分析グラフ 拡大プレビュー</h3>
            <button id="btn-close-chart-modal" class="text-slate-400 hover:text-slate-600 text-2xl font-bold px-2 transition-colors duration-150 ease-in-out">&times;</button>
        </div>
        <div class="flex-1 overflow-auto p-8 bg-slate-50/20 flex items-center justify-center relative" id="chart-modal-content">
            <canvas id="max-chart-canvas" class="max-w-full max-h-full"></canvas>
        </div>
    </div>
</div>

<script>
    // === UI制御ロジックの完全同期・維持 ===
    window.switchTab = function(tabId) {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
        const tab = document.getElementById(tabId);
        if(tab) tab.classList.add('active');
        const btn = document.getElementById('btn-' + tabId.replace('tab-', ''));
        if(btn) btn.classList.add('active');
        
        const projectId = document.getElementById('chat-project-id')?.value;
        if (projectId) {
            history.replaceState(null, '', `index.php?project_id=${projectId}&tab=${tabId.replace('tab-', '')}`);
        }
    };

    window.openAppModal = function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }
    };

    window.closeAppModal = function(modalId) {
        const modal = document.getElementById(modalId);
        if (modal) {
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        }
    };

    window.openGlobalChat = function() {
        const rightPanel = document.getElementById('right-panel');
        const toggleBtn = document.getElementById('toggle-panel-btn');
        
        if (rightPanel && rightPanel.classList.contains('collapsed')) {
            rightPanel.classList.remove('collapsed');
            document.documentElement.style.setProperty('--support-width', (localStorage.getItem('dashboard-width') || 550) + 'px');
            rightPanel.style.width = 'var(--support-width)';
            if (toggleBtn) toggleBtn.textContent = '▶';
            localStorage.setItem('dashboard-collapsed', 'false');
            window.dispatchEvent(new Event('resize'));
        }
        
        document.querySelectorAll('.project-list-item').forEach(el => {
            el.classList.remove('bg-slate-100', 'text-slate-900', 'font-extrabold', 'shadow-2xs', 'pl-3');
            el.classList.add('bg-white', 'hover:bg-slate-50/70');
            el.removeAttribute('aria-current');
            const icon = el.querySelector('.project-icon');
            if (icon) {
                icon.classList.remove('bg-blue-600', 'text-white');
                icon.classList.add('bg-slate-100', 'text-slate-500', 'group-hover:bg-blue-50', 'group-hover:text-blue-600');
            }
        });
        
        document.getElementById('right-panel-content')?.classList.add('hidden');
        document.getElementById('right-panel-placeholder')?.classList.add('hidden');
        document.getElementById('right-panel-chat')?.classList.remove('hidden');
        
        history.pushState(null, '', 'index.php');
        
        const pidInput = document.getElementById('chat-project-id');
        if (pidInput) pidInput.value = '';
        
        setTimeout(() => { document.getElementById('global-chat-input')?.focus(); }, 100);
    };

    window.handleGlobalChat = async function(e) {
        e.preventDefault();
        const input = document.getElementById('global-chat-input');
        if (!input) return;
        const msg = input.value.trim();
        if (!msg) return;

        const chatBox = document.getElementById('global-chat-box');
        const model = document.getElementById('global-model-select')?.value || 'gemma4:e4b';
        
        const uDiv = document.createElement('div');
        uDiv.className = 'flex flex-col items-end gap-1 animate-fadeIn';
        uDiv.innerHTML = `<span class="text-[10px] text-slate-500 font-bold uppercase tracking-tighter mr-2">You</span>
                          <div class="bg-[#0f766e] text-white p-3.5 rounded-2xl rounded-tr-none shadow-md text-[13px] max-w-[85%] leading-relaxed">${msg.replace(/\n/g, '<br>')}</div>`;
        chatBox.appendChild(uDiv);
        input.value = '';
        chatBox.scrollTop = chatBox.scrollHeight;

        const tempId = 'loading-' + Date.now();
        const lDiv = document.createElement('div');
        lDiv.id = tempId;
        lDiv.className = 'flex flex-col items-start gap-1 animate-pulse';
        lDiv.innerHTML = `<span class="text-[10px] text-slate-500 font-bold uppercase tracking-tighter ml-2">AI Assistant</span>
                          <div class="bg-slate-100 border border-slate-200 p-4 rounded-2xl rounded-tl-none text-[13px] font-bold loading-text text-slate-600 shadow-sm">生成中...</div>`;
        chatBox.appendChild(lDiv);
        chatBox.scrollTop = chatBox.scrollHeight;

        const config = document.getElementById('support-config');
        const projectsContext = config ? config.getAttribute('data-projects-context') : '';
        
        const payloadMessage = projectsContext + msg;
        const csrfTokenVal = document.querySelector('meta[name="csrf-token"]')?.content || '';

        try {
            const response = await fetch('api/chat.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfTokenVal },
                body: JSON.stringify({ message: payloadMessage, project_id: null, model: model, prompt_mode: 'general_chat' })
            });
            
            if (!response.ok) throw new Error(`HTTP Error: ${response.status}`);

            const reader = response.body.getReader();
            const decoder = new TextDecoder('utf-8');
            let buffer = '';

            while (true) {
                const { value, done } = await reader.read();
                if (done) break;

                buffer += decoder.decode(value, { stream: true });
                const lines = buffer.split('\n');
                buffer = lines.pop() || '';

                for (const line of lines) {
                    const trimmed = line.trim();
                    if (!trimmed || !trimmed.startsWith('data:')) continue;

                    try {
                        const jsonStr = trimmed.substring(5).trim();
                        const sseData = JSON.parse(jsonStr);

                        if (sseData.type === 'status') {
                            const textEl = document.getElementById(tempId)?.querySelector('.loading-text');
                            if (textEl) textEl.innerHTML = sseData.message;
                        } else if (sseData.type === 'result') {
                            document.getElementById(tempId)?.remove();
                            
                            if (sseData.status === 'success') {
                                const aDiv = document.createElement('div');
                                aDiv.className = 'flex flex-col items-start gap-1 animate-fadeIn';
                                
                                let reasoningHtml = '';
                                if (sseData.reasoning_steps && sseData.reasoning_steps.length > 0) {
                                    reasoningHtml = `
                                        <details class="mb-3 border border-indigo-100 rounded-lg bg-indigo-50/30 overflow-hidden group w-full transition-all duration-200 ease-in-out">
                                            <summary class="text-[11px] font-bold text-indigo-600 p-2.5 cursor-pointer hover:bg-indigo-50 transition-colors duration-200 ease-in-out select-none outline-none flex items-center gap-1">
                                                <span class="group-open:rotate-90 transition-transform duration-200 ease-in-out">▶</span>
                                                🧠 AIの検証プロセス (${sseData.reasoning_steps.length}件の検索)
                                            </summary>
                                            <div class="p-3 pt-0 space-y-2 border-t border-indigo-100 bg-white/50">
                                                ${sseData.reasoning_steps.map(step => `
                                                    <div class="text-[11px] bg-white p-2.5 rounded border border-indigo-50 shadow-sm transition-all duration-200 ease-in-out hover:shadow-md">
                                                        <p class="font-bold text-indigo-700 mb-1">Q. ${step.sub_query}</p>
                                                        <div class="text-gray-600 leading-relaxed">${typeof DOMPurify !== 'undefined' ? DOMPurify.sanitize(marked.parse(step.sub_answer || '')) : marked.parse(step.sub_answer || '')}</div>
                                                    </div>
                                                `).join('')}
                                            </div>
                                        </details>
                                    `;
                                }

                                let rawResp = sseData.response;
                                if (typeof rawResp === 'object' && rawResp !== null) {
                                    rawResp = rawResp.text || rawResp.content || JSON.stringify(rawResp);
                                }

                                const cleanHtml = typeof DOMPurify !== 'undefined' ? DOMPurify.sanitize(marked.parse(String(rawResp))) : marked.parse(String(rawResp));
                                
                                aDiv.innerHTML = `<span class="text-[10px] text-slate-400 font-black uppercase tracking-tight ml-2">AI Assistant</span>
                                                  <div class="bg-white border border-slate-200 p-4 rounded-2xl rounded-tl-none shadow-2xs max-w-[95%] markdown-body leading-relaxed font-medium">
                                                      ${reasoningHtml}
                                                      ${cleanHtml}
                                                  </div>`;
                                chatBox.appendChild(aDiv);
                            } else {
                                alert('エラー: ' + sseData.error);
                            }
                        }
                    } catch (parseErr) {
                        console.warn('SSE Chunk Parse Error:', parseErr, trimmed);
                    }
                }
            }
        } catch(err) {
            document.getElementById(tempId)?.remove();
            alert('通信エラーが発生しました: ' + err.message);
        }
        chatBox.scrollTop = chatBox.scrollHeight;
    };

    window.handleAddComment = function(e) {
        e.preventDefault();
        alert('コメントを送信しました（簡易モード）。詳細はsupport.phpで確認してください。');
    };

    window.handleRemoveComment = function(id) {
        if (confirm('コメントを削除しますか？')) {
            alert('削除しました（簡易モード）。');
        }
    };

    window.handleAddMember = function(e) { e.preventDefault(); };
    window.handleRemoveMember = function(id) {};
    window.bindModalEvents = function() {};

    window.loadCsvDataDashboard = async function(csvFileId, containerId) {
        const container = document.getElementById(containerId);
        if (!container || container.dataset.loaded === 'true') return;

        try {
            const response = await fetch(`api/get_csv_data.php?csv_file_id=${csvFileId}`);
            const decline = await response.json();

            if (decline && decline.success) {
                const headers = decline.headers;
                const rows = decline.rows;

                if (headers.length === 0) {
                    container.innerHTML = `<p class="text-xs text-slate-400 text-center py-4 font-bold">データがありません。</p>`;
                    return;
                }

                const escapeHTML = (str) => String(str).replace(/[&<>'"]/g, tag => ({
                    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
                }[tag] || tag));

                let html = `
                    <table class="w-full text-[10px] text-left border-collapse whitespace-nowrap bg-white">
                        <thead class="bg-slate-50 text-slate-400 border-b border-slate-200/60">
                            <tr>
                                <th class="p-2 px-3 border-b border-r border-slate-200/60 font-extrabold sticky top-0 bg-slate-50 shadow-sm z-10 text-center uppercase tracking-wider">No.</th>
                                ${headers.map(h => `<th class="p-2 px-3 border-b border-r border-slate-200/60 font-extrabold sticky top-0 bg-slate-50 shadow-sm z-10 uppercase tracking-wider">${escapeHTML(h)}</th>`).join('')}
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-slate-100 font-mono font-medium text-slate-600">
                            ${rows.map((row, idx) => `
                                <tr class="hover:bg-slate-50/50 transition-colors duration-150 ease-in-out">
                                    <td class="p-1.5 px-3 border-r border-slate-100 text-slate-400 text-center bg-slate-50/30 font-sans">${idx + 1}</td>
                                    ${headers.map(h => `<td class="p-1.5 px-3 border-r border-slate-100 truncate max-w-[160px]" title="${escapeHTML(row[h] ?? '')}">${escapeHTML(row[h] ?? '')}</td>`).join('')}
                                </tr>
                            `).join('')}
                        </tbody>
                    </table>
                `;
                
                container.innerHTML = html;
                container.dataset.loaded = 'true';
            } else {
                container.innerHTML = `<p class="text-xs text-red-500 text-center py-4 font-bold">エラー: ${decline.error}</p>`;
            }
        } catch (err) {
            container.innerHTML = `<p class="text-xs text-red-500 text-center py-4 font-bold">通信エラー: ${err.message}</p>`;
        }
    };

    const initPanelResizeAndToggle = () => {
        const DEFAULT_WIDTH = 550; 
        const MIN_WIDTH = 300;     
        const MAX_WIDTH = 900;     

        const handle = document.getElementById('resize-handle');
        const toggleBtn = document.getElementById('toggle-panel-btn');
        const rightPanel = document.getElementById('right-panel');
        
        if (!handle || !toggleBtn || !rightPanel) return;

        let savedWidth = parseInt(localStorage.getItem('dashboard-width'), 10) || DEFAULT_WIDTH;
        let isCollapsed = localStorage.getItem('dashboard-collapsed') === 'true';

        const setPanelWidth = (w) => {
            document.documentElement.style.setProperty('--support-width', `${w}px`);
            rightPanel.style.width = `${w}px`;
            rightPanel.style.minWidth = `${w}px`;
            rightPanel.style.maxWidth = `${w}px`;
            rightPanel.style.flexBasis = `${w}px`;
        };

        if (isCollapsed) {
            rightPanel.classList.add('collapsed');
            toggleBtn.textContent = '◀';
            toggleBtn.title = '詳細パネルを展開';
        } else {
            setPanelWidth(savedWidth);
            toggleBtn.textContent = '▶';
            toggleBtn.title = '詳細パネルを格納';
        }

        toggleBtn.addEventListener('click', (e) => {
            e.stopPropagation();
            if (rightPanel.classList.contains('collapsed')) {
                rightPanel.classList.remove('collapsed');
                setPanelWidth(savedWidth);
                toggleBtn.textContent = '▶';
                toggleBtn.title = '詳細パネルを格納';
                localStorage.setItem('dashboard-collapsed', 'false');
                window.dispatchEvent(new Event('resize'));
            } else {
                rightPanel.classList.add('collapsed');
                toggleBtn.textContent = '◀';
                toggleBtn.title = '詳細パネルを展開';
                localStorage.setItem('dashboard-collapsed', 'true');
            }
        });

        handle.addEventListener('mousedown', (e) => {
            if (e.target === toggleBtn) return;
            e.preventDefault();
            
            if (rightPanel.classList.contains('collapsed')) {
                rightPanel.classList.remove('collapsed');
                toggleBtn.textContent = '▶';
                localStorage.setItem('dashboard-collapsed', 'false');
            }

            const startX = e.clientX;
            const startWidth = rightPanel.getBoundingClientRect().width || savedWidth;

            document.body.style.cursor = 'col-resize';
            const iframes = document.querySelectorAll('iframe');
            iframes.forEach(f => f.style.pointerEvents = 'none');

            const onMouseMove = (ev) => {
                let w = startWidth - (ev.clientX - startX);
                w = Math.max(MIN_WIDTH, Math.min(MAX_WIDTH, w));
                setPanelWidth(w);
                savedWidth = w;
            };

            const onMouseUp = () => {
                document.removeEventListener('mousemove', onMouseMove);
                document.removeEventListener('mouseup', onMouseUp);
                document.body.style.cursor = '';
                iframes.forEach(f => f.style.pointerEvents = '');
                localStorage.setItem('dashboard-width', savedWidth);
                window.dispatchEvent(new Event('resize'));
            };

            document.addEventListener('mousemove', onMouseMove);
            document.addEventListener('mouseup', onMouseUp);
        });
    };

    const initOverviewMap = (lat, lng, projName) => {
        const mapContainer = document.getElementById('overview-map');
        if (!mapContainer) return;

        if (mapContainer._leaflet_id) {
            mapContainer._leaflet_id = null;
            mapContainer.innerHTML = '';
        }

        const map = L.map('overview-map').setView([lat, lng], 13);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap'
        }).addTo(map);

        L.marker([lat, lng]).addTo(map).bindPopup(`<b class="text-xs">${projName}</b>`).openPopup();

        const tabOverview = document.getElementById('tab-overview');
        if (tabOverview) {
            new MutationObserver((mutations) => {
                mutations.forEach((m) => {
                    if (m.target.classList.contains('active')) {
                        setTimeout(() => map.invalidateSize(), 150);
                    }
                });
            }).observe(tabOverview, { attributes: true, attributeFilter: ['class'] });
        }

        window.addEventListener('resize', () => {
            setTimeout(() => map.invalidateSize(), 150);
        });
    };

    /**
     * 【仕様1】Notion風スケルトンローディングへの刷新と流麗なフェードインUXの結合マウント
     */
    window.loadProjectDetails = async function(projectId, element, tabToActivate = 'tab-overview') {
        document.querySelectorAll('.project-list-item').forEach(el => {
            el.className = "project-list-item p-4 flex flex-col md:flex-row md:items-center justify-between cursor-pointer transition-all duration-200 ease-in-out group bg-white hover:bg-slate-100/70";
            const icon = el.querySelector('.project-icon');
            if (icon) {
                icon.className = "project-icon w-9 h-9 rounded-xl flex items-center justify-center text-xs flex-shrink-0 transition-all duration-200 ease-in-out shadow-2xs bg-slate-100 text-slate-500 group-hover:bg-blue-50 group-hover:text-blue-600";
            }
        });

        if (element) {
            element.className = "project-list-item p-4 flex flex-col md:flex-row md:items-center justify-between cursor-pointer transition-all duration-200 ease-in-out group bg-slate-100 text-slate-900 font-extrabold shadow-2xs pl-3";
            const icon = element.querySelector('.project-icon');
            if (icon) {
                icon.className = "project-icon w-9 h-9 rounded-xl flex items-center justify-center text-xs flex-shrink-0 transition-all duration-200 ease-in-out shadow-2xs bg-blue-600 text-white";
            }
        }

        history.pushState(null, '', `index.php?project_id=${projectId}&tab=${tabToActivate.replace('tab-', '')}`);

        const rightPanel = document.getElementById('right-panel');
        if (!rightPanel) return;

        if (rightPanel.classList.contains('collapsed')) {
            rightPanel.classList.remove('collapsed');
            const toggleBtn = document.getElementById('toggle-panel-btn');
            if (toggleBtn) toggleBtn.textContent = '▶';
            localStorage.setItem('dashboard-collapsed', 'false');
        }

        // ─── 無骨な白背景スピナーを「完全撤去」し、流麗なNotion風スケルトンスクリーンを構築・注入 ───
        const skeletonContainer = document.createElement('div');
        skeletonContainer.className = 'absolute inset-0 bg-white z-50 p-6 flex flex-col h-full overflow-hidden select-none animate-fadeIn duration-200';
        skeletonContainer.innerHTML = `
            <div class="flex gap-2 border-b border-slate-100 pb-2.5 mb-6 overflow-hidden flex-shrink-0 animate-pulse">
                <div class="w-16 h-6 bg-slate-200/70 rounded-lg"></div>
                <div class="w-20 h-6 bg-slate-200/70 rounded-lg"></div>
                <div class="w-16 h-6 bg-slate-200/70 rounded-lg"></div>
                <div class="w-24 h-6 bg-slate-200/70 rounded-lg"></div>
            </div>
            <div class="flex-1 space-y-6 overflow-y-auto no-scrollbar animate-pulse">
                <div class="bg-slate-50/50 border border-slate-100 rounded-2xl p-5 space-y-4">
                    <div class="flex justify-between items-center border-b border-slate-100 pb-3">
                        <div class="w-28 h-4 bg-slate-200/80 rounded-md"></div>
                        <div class="w-20 h-6 bg-slate-200/80 rounded-xl"></div>
                    </div>
                    <div class="space-y-4 pt-2">
                        <div class="flex items-center gap-4"><div class="w-20 h-3.5 bg-slate-200/60 rounded"></div><div class="w-48 h-4 bg-slate-200/80 rounded-md"></div></div>
                        <div class="flex items-center gap-4"><div class="w-20 h-3.5 bg-slate-200/60 rounded"></div><div class="w-36 h-4 bg-slate-200/80 rounded-md"></div></div>
                        <div class="flex items-center gap-4"><div class="w-20 h-3.5 bg-slate-200/60 rounded"></div><div class="w-full h-12 bg-slate-200/50 rounded-xl"></div></div>
                    </div>
                </div>
                <div class="w-full h-44 bg-slate-200/50 border border-slate-100 rounded-xl"></div>
            </div>
        `;
        rightPanel.style.position = 'relative';
        rightPanel.appendChild(skeletonContainer);

        try {
            const response = await fetch(`index.php?project_id=${projectId}`, {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });
            const data = await response.json();

            if (data && data.html) {
                // スケルトン領域をフェードアウトさせてから消去し、新DOMをフワッとドラマチックにマウント描画
                skeletonContainer.classList.replace('opacity-100', 'opacity-0');
                setTimeout(() => {
                    rightPanel.innerHTML = data.html;
                    
                    const newContent = document.getElementById('right-panel-content');
                    if (newContent) {
                        newContent.classList.add('opacity-0');
                        window.switchTab(tabToActivate);
                        // 次フレームでフェードマウントを実行
                        requestAnimationFrame(() => {
                            newContent.classList.remove('opacity-0');
                            newContent.classList.add('opacity-100', 'transition-all', 'duration-300', 'ease-in-out');
                        });
                    }
                    if (data.lat !== null && data.lng !== null) {
                        initOverviewMap(data.lat, data.lng, data.projName);
                    }
                }, 150);
            }
        } catch (error) {
            console.error('Error loading project details:', error);
            skeletonContainer.remove();
            alert('業務詳細の読み込みに失敗しました。');
        }
    };

    window.afterProjectHistoryCleared = async function(projectId, result = null) {
        const activeTabId = document.querySelector('.tab-btn.active')?.id || 'tab-overview';
        const activeProjectItem = document.querySelector(`.project-list-item[data-project-id="${projectId}"]`);

        if (result?.counts) {
            console.info('Project chat history cleared', result.counts);
        }

        await window.loadProjectDetails(projectId, activeProjectItem, activeTabId);
    };

    document.addEventListener('DOMContentLoaded', () => {
        initPanelResizeAndToggle();

        // ─── 【仕様維持】初期ロード時のAIコンシェルジュ・自律RAGストリーミングブリーフィング ───
        const triggerAiConcierge = async () => {
            const boardText = document.getElementById('ai-concierge-text');
            const boardActions = document.getElementById('ai-concierge-actions');
            const configEl = document.getElementById('support-config');
            if (!boardText || !configEl) return;

            const projectsContextText = configEl.getAttribute('data-projects-context') || '';
            const csrfTokenVal = document.querySelector('meta[name="csrf-token"]')?.content || '';
            
            const initialPrompt = "現在の全プロジェクト状況を150文字程度で、毎朝のビジネスブリーフィング風に、本日の要約と次の推奨アクションを日本語で簡潔に1つの段落で述べてください。挨拶は不要です。\n\n" + projectsContextText;

            try {
                const response = await fetch('api/chat.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': csrfTokenVal },
                    body: JSON.stringify({ message: initialPrompt, project_id: null, model: 'gemma4:e4b', prompt_mode: 'general_chat' })
                });

                if (!response.ok) throw new Error();

                const reader = response.body.getReader();
                const decoder = new TextDecoder('utf-8');
                let buffer = '';
                let hasTypingStarted = false;

                while (true) {
                    const { value, done } = await reader.read();
                    if (done) break;

                    buffer += decoder.decode(value, { stream: true });
                    const lines = buffer.split('\n');
                    buffer = lines.pop() || '';

                    for (const line of lines) {
                        const trimmed = line.trim();
                        if (!trimmed || !trimmed.startsWith('data:')) continue;

                        try {
                            const jsonStr = trimmed.substring(5).trim();
                            const sseData = JSON.parse(jsonStr);

                            if (sseData.type === 'chunk') {
                                const word = sseData.text || sseData.word || '';
                                if (!hasTypingStarted) {
                                    boardText.classList.remove('animate-pulse', 'flex', 'items-center', 'gap-2');
                                    boardText.innerHTML = '';
                                    hasTypingStarted = true;
                                }
                                boardText.innerHTML += word;
                            } else if (sseData.type === 'result') {
                                if (boardActions) {
                                    boardActions.classList.remove('hidden');
                                    boardActions.classList.add('animate-fadeIn');
                                    boardActions.style.opacity = '1';
                                }
                            }
                        } catch (innerErr) {}
                    }
                }
            } catch (err) {
                boardText.classList.remove('animate-pulse');
                boardText.innerHTML = '⚠️ 本日の自律業務ブリーフィング生成に一時的な遅延が発生しました。サイドバーまたは業務支援コンソールより直接データを確認してください。';
            }
        };

        triggerAiConcierge();

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                document.querySelectorAll('.fixed.inset-0:not(.hidden)').forEach(m => window.closeAppModal(m.id));
            }
        });

        document.querySelectorAll('.fixed.inset-0').forEach(m => {
            m.addEventListener('click', (e) => {
                if (e.target === m) window.closeAppModal(m.id);
            });
        });

        const config = document.getElementById('support-config');
        if (config) {
            const latStr = config.getAttribute('data-focused-lat');
            const lngStr = config.getAttribute('data-focused-lng');
            const projName = config.getAttribute('data-focused-name');
            
            if (latStr && lngStr) {
                const lat = parseFloat(latStr);
                const lng = parseFloat(lngStr);
                if (!isNaN(lat) && !isNaN(lng)) {
                    initOverviewMap(lat, lng, projName);
                }
            }
        }

        const chatInput = document.getElementById('global-chat-input');
        const chatForm = document.getElementById('global-chat-form');
        if (chatInput && chatForm) {
            const minHeight = 28;
            chatInput.style.height = minHeight + 'px';
            
            chatInput.addEventListener('input', function() {
                this.style.height = minHeight + 'px';
                this.style.height = Math.min(this.scrollHeight, 240) + 'px';
            });
            
            chatInput.addEventListener('keydown', function(e) {
                if (e.isComposing || e.keyCode === 229) return;
                
                if (e.key === 'Enter' || e.keyCode === 13) {
                    if (!e.shiftKey) {
                        e.preventDefault();
                        if (this.value.trim() !== '') {
                            const btn = chatForm.querySelector('button[type="submit"]');
                            if (btn) {
                                btn.click();
                            } else if (typeof chatForm.requestSubmit === 'function') {
                                chatForm.requestSubmit();
                            } else {
                                chatForm.dispatchEvent(new Event('submit', { cancelable: true, bubbles: true }));
                            }
                        }
                    }
                }
            });
            
            chatForm.addEventListener('submit', () => {
                setTimeout(() => {
                    chatInput.style.height = minHeight + 'px';
                    chatInput.value = '';
                }, 10);
            });
        }
    });
</script>
<script type="module">
    import * as Support from './assets/js/support.js?v=6';

    if (typeof Support.bindGlobalFunctions === 'function') {
        Support.bindGlobalFunctions();
    }
    if (typeof Support.bindModalEvents === 'function') {
        Support.bindModalEvents();
    }
</script>
</body>
</html>
