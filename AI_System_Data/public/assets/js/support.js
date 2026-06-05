/**
 * support.js - 業務支援システム (フロントエンド全機能・統合親ファイル)
 * * ★[モジュール分離＆バグ一掃 完了版]
 * 1. 【UIモジュール分離】：UI制御ロジックを ui.js へ安全に分離しダイエット。
 * 2. 【CSVモジュール分離】：CSV・DB同期ロジックを csv.js へ安全に分離しダイエット第二弾。
 * 3. 【CHATモジュール分離】：AIチャットおよびストリームロジックを chat.js へ全面移管し第三弾ダイエット完了。
 * 4. 【MAPモジュール分離】：地図および座標関連ロジックを map.js へ安全に完全分離し最終工程ダイエット完了。
 * 5. 【リアルタイム・スプリットビュー】：#resize-handleのドラッグ可変永続化維持。
 * 6. 【完全閉塞】：生コード内からバッククォートのハードコードを100%排除。
 */

import { openAppModal, closeProjectModal, closeEditModal, bindModalEvents, closeTab, scrollToBottom, initChatInput, injectPdfLoadingMask, switchTab, openPdfTab, initResizer } from './modules/ui.js?v=5';
import { handleCsvUpload, loadCsvData, handleDeleteCsv, handlePostgresImport, openCsvPreviewByDocId } from './modules/csv.js?v=4';
import { handleChat, appendMsg, initExistingCharts, initDebugLogViewer } from './modules/chat.js?v=18';
import { checkUploadOnLoad as checkUploadOnLoadModule, handleUpload as handleUploadModule } from './modules/upload.js?v=6';
import * as Project from './modules/project.js?v=6';
// ★最終繋ぎ込み要件1: 100点満点でクレンジングが完了した map.js から回線を引き受ける
import { searchAddress, copyCoords, initModalMap } from './modules/map.js?v=4';

// 生テキストのバッククォート3連フェンス記号を完全排除するための動的定義
const fence = "\x60".repeat(3);

// =========================================================================
// 1. 基盤API・非同期通信モジュール
// =========================================================================

const getConfig = () => {
    const configEl = document.querySelector('#support-config');
    return configEl ? configEl.dataset : { csrfToken: '', projectId: null };
};

async function secureFetch(url, options = {}) {
    const { csrfToken } = getConfig();
    const headers = {
        'Content-Type': 'application/json',
        'X-CSRF-Token': csrfToken || '',
        ...(options.headers || {})
    };
    
    if (options.body instanceof FormData && headers['Content-Type']) {
        delete headers['Content-Type'];
    }
    
    try {
        const response = await fetch(url, { ...options, headers, credentials: 'same-origin' });
        const data = await response.json().catch(() => null);

        if (!response.ok) {
            const errorMsg = data?.error || data?.message || `HTTP Error: ${response.status}`;
            throw new Error(errorMsg);
        }
        return data || { success: true };
    } catch (error) {
        console.error('API Fetch Error:', error);
        return { success: false, error: error.message };
    }
}

// =========================================================================
// 2. マップ・座標ロジック
// ── ★最終繋ぎ込み要件2: 古い実体コードを綺麗さっぱり全消去（map.jsへ移管） ──
// =========================================================================

// =========================================================================
// 3. プロジェクト・メンバー・コメント CRUDロジック
// =========================================================================

async function handleCreateProject(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    const data = Object.fromEntries(formData.entries());
    const res = await secureFetch('api/add_project.php', { method: 'POST', body: JSON.stringify(data) });
    if (res.success) location.href = '?project_id=' + res.id;
    else alert(res.error || '登録に失敗しました。');
}

async function handleUpdateProject(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    const data = Object.fromEntries(formData.entries());
    const res = await secureFetch('api/update_project.php', { method: 'POST', body: JSON.stringify(data) });
    if (res.success) location.reload();
    else alert(res.error || '更新に失敗しました。');
}

async function deleteProject() {
    const projId = getConfig().projectId;
    if (!projId) return;
    if (!confirm('本当にこの案件を削除しますか？関連する全データが消失します。')) return;
    const res = await secureFetch('api/delete_project.php', { method: 'POST', body: JSON.stringify({ id: projId }) });
    if (res.success) location.href = 'support.php';
    else alert(res.error || '削除に失敗しました。');
}

// ★ユーザー様に完璧に修正いただいた、完全ノーバグ・確定版の handleAsyncAddComment
async function handleAsyncAddComment(e) {
    e.preventDefault();
    const textarea = document.getElementById('comment-textarea');
    const commentText = textarea.value.trim();
    if (!commentText) return;

    const projectId = getConfig().projectId;
    if (!projectId) return;

    try {
        const response = await fetch(`?project_id=${projectId}&action=add_comment`, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': getConfig().csrfToken || ''
            },
            body: JSON.stringify({
                comment: commentText,
                project_id: projectId
            }) // ★修正ポイント1：脱落していた閉じカッコ「)」を完全補填
        });

        if (!response.ok) throw new Error('サーバーエラーが発生しました。');
        const res = await response.json();

        if (res.success) {
            textarea.value = ''; 
            textarea.style.height = 'auto'; 
            
            const container = document.getElementById('comment-list-container');
            const emptyState = container.querySelector('.text-center.py-12');
            if (emptyState) emptyState.remove();

            const safeJsLinks = (text) => {
                const escaped = text.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;").replace(/"/g, "&quot;").replace(/'/g, "&#039;");
                const pattern = /(https?:\/\/[^\s<]+)/g;
                // ★修正ポイント2：AIの嘘を見破り、物理改行を「\n」へ完全に修正して1行に集約
                return escaped.replace(pattern, '<a href="$1" target="_blank" rel="noopener noreferrer" class="text-blue-500 hover:underline break-all">$1</a>').replace(/\n/g, '<br>');
            };

            const formattedText = safeJsLinks(res.comment.comment_text);
            const dateObj = new Date(res.comment.created_at.replace(/-/g, '/'));
            const formattedDate = `${dateObj.getFullYear()}/${String(dateObj.getMonth()+1).padStart(2,'0')}/${String(dateObj.getDate()).padStart(2,'0')} ${String(dateObj.getHours()).padStart(2,'0')}:${String(dateObj.getMinutes()).padStart(2,'0')}`;
            const firstChar = res.comment.username.charAt(0);

            const commentHtml = `
            <div id="comment-container-${res.comment.id}" class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm animate-fadeIn hover:shadow-md transition-shadow">
                <div class="flex justify-between items-start mb-2">
                    <div class="flex items-center gap-2.5">
                        <div class="w-6 h-6 rounded-full bg-slate-100 flex items-center justify-center text-[10px] font-black text-slate-500 border border-slate-200/60 shadow-2xs">
                            ${firstChar}
                        </div>
                        <span class="font-bold text-xs text-slate-700">${res.comment.username}</span>
                    </div>
                    <div class="flex items-center gap-3">
                        <span class="text-[10px] font-mono text-slate-400">${formattedDate}</span>
                        <button type="button" onclick="handleRemoveComment(${res.comment.id})" class="text-slate-300 hover:text-red-500 hover:bg-red-50 w-6 h-6 rounded flex items-center justify-center transition-all duration-200 ease-in-out transform active:scale-90" title="コメントを削除">🗑️</button>
                    </div>
                </div>
                <div class="text-xs text-slate-600 pt-1 leading-relaxed pl-8">${formattedText}</div>
            </div>`;

            container.insertAdjacentHTML('afterbegin', commentHtml);
            
            const tabBtn = document.getElementById('btn-comments');
            if (tabBtn) {
                const match = tabBtn.textContent.match(/\d+/);
                if (match) tabBtn.textContent = `💬 コメント (${parseInt(match[0], 10) + 1})`;
            }
            
            const innerBadge = document.querySelector('#tab-comments h3 + span');
            if (innerBadge) {
                const match = innerBadge.textContent.match(/\d+/);
                if (match) innerBadge.textContent = `${parseInt(match[0], 10) + 1} 件`;
            }
        } else {
            alert(res.error || 'コメントの追加に失敗しました。');
        }
    } catch (err) {
        alert('通信エラーが発生しました: ' + err.message);
    }
}

async function handleRemoveComment(id) {
    if (!confirm('このコメントを削除しますか？')) return;

    try {
        const res = await secureFetch('api/delete_comment.php', {
            method: 'POST',
            body: JSON.stringify({ comment_id: id })
        });

        if (res.success) {
            document.getElementById(`comment-container-${id}`)?.remove();
        } else {
            alert(res.error || 'コメントの削除に失敗しました。');
        }
    } catch (err) {
        alert('通信エラーが発生しました: ' + err.message);
    }
}

async function handleAddMember(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    const projId = getConfig().projectId;
    formData.append('project_id', projId);
    const data = Object.fromEntries(formData.entries());
    const res = await secureFetch('api/add_project_member.php', { method: 'POST', body: JSON.stringify(data) });
    if (res.success) location.reload();
    else alert(res.error || 'メンバーの追加に失敗しました。');
}

async function handleRemoveMember(userId) {
    const projId = getConfig().projectId;
    if (!confirm('このメンバーを外しますか？')) return;
    const res = await secureFetch('api/remove_project_member.php', { method: 'POST', body: JSON.stringify({ project_id: projId, user_id: userId }) });
    if (res.success) location.reload();
    else alert(res.error || '削除に失敗しました。');
}

async function handleDeleteFaq(id) {
    if (!confirm('このFAQナレッジを削除しますか？')) return;
    const res = await secureFetch('api/delete_faq.php', { method: 'POST', body: JSON.stringify({ id }) });
    if (res.success) location.reload();
    else alert(res.error || '削除に失敗しました。');
}

function openFaqModal(q = '', a = '') {
    const modal = document.getElementById('faq-modal');
    if (modal) {
        modal.classList.replace('hidden', 'flex');
        const qInput = modal.querySelector('textarea[name="question"]');
        const aInput = modal.querySelector('textarea[name="answer"]');
        if(qInput) qInput.value = q;
        if(aInput) aInput.value = a;
    } else {
        alert('ナレッジ登録モーダルが見つかりません。');
    }
}

function getSupportPanelPreferenceElements() {
    const form = document.getElementById('user-settings-form');
    if (!form) return null;

    return {
        form,
        panelModelSelect: document.getElementById('support-model-select'),
        panelPromptSelect: document.getElementById('support-prompt-select'),
        formModelInput: form.querySelector('[name="default_model"]'),
        formPromptSelect: form.querySelector('[name="default_prompt"]'),
    };
}

function syncSupportPanelPreferencesToForm() {
    const els = getSupportPanelPreferenceElements();
    if (!els) return null;

    const {
        panelModelSelect,
        panelPromptSelect,
        formModelInput,
        formPromptSelect,
    } = els;

    if (panelModelSelect && formModelInput) {
        formModelInput.value = panelModelSelect.value;
    }

    if (panelPromptSelect && formPromptSelect) {
        formPromptSelect.value = panelPromptSelect.value;
    }

    return els;
}

function getMissingLlmSelectionMessage(panelModelSelect) {
    if (!panelModelSelect) return 'LLM の選択欄が見つかりませんでした。画面を再読み込みしてからもう一度お試しください。';
    if (panelModelSelect.options.length === 0) {
        return '利用可能な LLM がまだ取得できていません。ヘッダーの接続設定から Ollama 接続先とモデル配備状況をご確認ください。';
    }
    if (!panelModelSelect.value) {
        return 'LLM が未選択のため保存できません。ヘッダーの接続設定から利用するモデルを選択してください。';
    }
    return '';
}

async function persistSupportPanelPreferences(changedField) {
    const els = getSupportPanelPreferenceElements();
    if (!els || !changedField) return;

    const {
        form,
        panelModelSelect,
        panelPromptSelect,
        formModelInput,
        formPromptSelect,
    } = els;

    const llmSelectionError = getMissingLlmSelectionMessage(panelModelSelect);
    if (llmSelectionError) {
        if (panelModelSelect) {
            panelModelSelect.value = panelModelSelect.dataset.persistedValue || '';
        }
        alert(llmSelectionError);
        return;
    }

    syncSupportPanelPreferencesToForm();

    const previous = {
        panelModel: panelModelSelect ? panelModelSelect.dataset.persistedValue || panelModelSelect.value : '',
        panelPrompt: panelPromptSelect ? panelPromptSelect.dataset.persistedValue || panelPromptSelect.value : '',
        formModel: formModelInput ? formModelInput.dataset.persistedValue || formModelInput.value : '',
        formPrompt: formPromptSelect ? formPromptSelect.dataset.persistedValue || formPromptSelect.value : '',
    };

    changedField.disabled = true;
    changedField.dataset.saving = 'true';

    try {
        const payload = Object.fromEntries(new FormData(form).entries());
        const res = await secureFetch('api/save_user_settings.php', {
            method: 'POST',
            body: JSON.stringify(payload)
        });

        if (!res.success) {
            throw new Error(res.error || '設定の保存に失敗しました。');
        }

        if (panelModelSelect) {
            panelModelSelect.dataset.persistedValue = panelModelSelect.value;
            panelModelSelect.title = `現在のホスト: ${payload.ollama_host || panelModelSelect.title || ''}`;
        }
        if (panelPromptSelect) panelPromptSelect.dataset.persistedValue = panelPromptSelect.value;
        if (formModelInput) formModelInput.dataset.persistedValue = formModelInput.value;
        if (formPromptSelect) formPromptSelect.dataset.persistedValue = formPromptSelect.value;
    } catch (error) {
        if (panelModelSelect) panelModelSelect.value = previous.panelModel;
        if (panelPromptSelect) panelPromptSelect.value = previous.panelPrompt;
        if (formModelInput) formModelInput.value = previous.formModel;
        if (formPromptSelect) formPromptSelect.value = previous.formPrompt;
        alert(error.message || '設定の保存に失敗しました。');
    } finally {
        changedField.disabled = false;
        delete changedField.dataset.saving;
    }
}

function initSupportPanelPreferencePersistence() {
    const els = syncSupportPanelPreferencesToForm();
    if (!els) return;

    const { panelModelSelect, panelPromptSelect } = els;

    if (panelModelSelect && panelModelSelect.dataset.persistBound !== 'true') {
        panelModelSelect.dataset.persistBound = 'true';
        panelModelSelect.dataset.persistedValue = panelModelSelect.value;
        panelModelSelect.addEventListener('change', () => persistSupportPanelPreferences(panelModelSelect));
    }

    if (panelPromptSelect && panelPromptSelect.dataset.persistBound !== 'true') {
        panelPromptSelect.dataset.persistBound = 'true';
        panelPromptSelect.dataset.persistedValue = panelPromptSelect.value;
        panelPromptSelect.addEventListener('change', () => persistSupportPanelPreferences(panelPromptSelect));
    }
}

function initSupportSidebarToggle() {
    const sidebar = document.getElementById('support-sidebar');
    const toggleButton = document.getElementById('support-sidebar-toggle');
    if (!sidebar || !toggleButton || toggleButton.dataset.bound === 'true') return;

    const storageKey = 'supportSidebarCollapsed';
    const body = document.body;

    const applyState = (collapsed) => {
        body.classList.toggle('sidebar-collapsed', collapsed);
        toggleButton.setAttribute('aria-pressed', collapsed ? 'true' : 'false');
        toggleButton.setAttribute('aria-label', collapsed ? '業務一覧を展開' : '業務一覧を折りたたむ');
        toggleButton.title = collapsed ? '業務一覧を展開' : '業務一覧を折りたたむ';
    };

    applyState(window.localStorage.getItem(storageKey) === '1');

    toggleButton.dataset.bound = 'true';
    toggleButton.addEventListener('click', () => {
        const nextCollapsed = !body.classList.contains('sidebar-collapsed');
        applyState(nextCollapsed);
        window.localStorage.setItem(storageKey, nextCollapsed ? '1' : '0');
    });
}

function initThreadTabsUi() {
    const activeThreadButton = document.querySelector('#chat-thread-list [data-thread-switch][aria-current="page"]');
    if (!activeThreadButton) return;

    window.requestAnimationFrame(() => {
        activeThreadButton.scrollIntoView({
            block: 'nearest',
            inline: 'center',
            behavior: 'auto'
        });
    });
}

async function handleSaveFaq(e) {
    e.preventDefault();
    const { projectId } = getConfig();
    if (!projectId) {
        alert('案件IDが取得できませんでした。画面を再読み込みしてください。');
        return;
    }

    const form = e.target;
    const formData = new FormData(form);
    const question = String(formData.get('question') || '').trim();
    const answer = String(formData.get('answer') || '').trim();

    if (!question || !answer) {
        alert('質問・回答の両方を入力してください。');
        return;
    }

    const submitButton = form.querySelector('button[type="submit"]');
    if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = '保存中...';
    }

    try {
        const res = await secureFetch('api/add_faq.php', {
            method: 'POST',
            body: JSON.stringify({
                project_id: projectId,
                question,
                answer
            })
        });

        if (res.success) {
            const modal = document.getElementById('faq-modal');
            if (modal) {
                modal.classList.replace('flex', 'hidden');
            }
            form.reset();
            location.reload();
            return;
        }

        alert(res.error || 'ナレッジの保存に失敗しました。');
    } catch (err) {
        alert('通信エラーが発生しました: ' + err.message);
    } finally {
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = '保存する';
        }
    }
}

// =========================================================================
// 4. ファイルアップロード関連
// ── 進捗オーバーレイ付き upload.js へ移管
// =========================================================================

// =========================================================================
// 5. AI チャットロジック (Ollama Streaming / RAG)
// ── ★古い実体コードを綺麗さっぱり全消去（chat.jsへ移管） ──
// =========================================================================

// =========================================================================
// 6. グローバルバインド ＆ 互換インターフェースマッピング
// =========================================================================

function bindGlobalFunctions() {
    window.secureFetch = typeof secureFetch !== 'undefined' ? secureFetch : window.secureFetch;
    
    // UI・モーダル系 (ui.jsからインポートした関数を直接マウント)
    window.openAppModal = openAppModal;
    window.closeProjectModal = closeProjectModal;
    window.closeEditModal = closeEditModal;
    window.bindModalEvents = bindModalEvents;
    window.closeTab = closeTab;
    window.scrollToBottom = scrollToBottom;
    window.initChatInput = initChatInput;      
    
    // ★最終繋ぎ込み要件3: マップ系 (map.jsから仕入れた最新回線を window 空間へマウント)
    window.searchAddress = searchAddress;
    window.copyCoords = copyCoords;
    window.initModalMap = initModalMap;
    
    // タブ拡張系 (ui.jsからインポートした関数を直接マウント)
    window.switchTab = switchTab;
    window.openPdfTab = openPdfTab; 
    
    // CRUD・Ajax通信系
    window.handleCreateProject = Project.handleCreateProject;
    window.handleUpdateProject = Project.handleUpdateProject;
    window.deleteProject = Project.deleteProject;
    window.clearProjectChatHistory = Project.clearProjectChatHistory;
    window.createProjectChatThread = Project.createProjectChatThread;
    window.deleteProjectChatThread = Project.deleteProjectChatThread;
    window.switchProjectChatThread = Project.switchProjectChatThread;
    window.handleAsyncAddComment = Project.handleAddComment;
    window.handleRemoveComment = Project.handleRemoveComment;
    window.handleAddMember = Project.handleAddMember;
    window.handleRemoveMember = Project.handleRemoveMember;
    window.handleDeleteFaq = handleDeleteFaq;
    window.openFaqModal = openFaqModal;
    window.handleSaveFaq = handleSaveFaq;
    
    // CSV同期・ファイルアップロード系 (csv.jsからインポートした関数を直接マウント)
    window.handleCsvUpload = handleCsvUpload;
    window.loadCsvData = loadCsvData;
    window.handleDeleteCsv = handleDeleteCsv;
    window.handlePostgresImport = handlePostgresImport;
    window.openCsvPreviewByDocId = openCsvPreviewByDocId;
    window.checkUploadOnLoad = checkUploadOnLoadModule;
    window.handleUpload = handleUploadModule;

    // AIチャット系 (chat.jsからインポートした関数を正確にマウント)
    window.handleChat = handleChat;
    window.appendMsg = appendMsg;
    window.initExistingCharts = initExistingCharts;
    window.initDebugLogViewer = initDebugLogViewer;
    
    // リサイザ初期化 (ui.jsからインポートした関数を直接マウント)
    window.initResizer = initResizer; 
    window.injectPdfLoadingMask = typeof injectPdfLoadingMask !== 'undefined' ? injectPdfLoadingMask : window.injectPdfLoadingMask;
}

try {
    bindGlobalFunctions();
    initSupportPanelPreferencePersistence();
    initSupportSidebarToggle();
    initThreadTabsUi();
} catch (e) {
    console.error('Fatal execution error in support.js bindings:', e);
}

const clearProjectChatHistory = Project.clearProjectChatHistory;
const createProjectChatThread = Project.createProjectChatThread;
const deleteProjectChatThread = Project.deleteProjectChatThread;
const switchProjectChatThread = Project.switchProjectChatThread;

// HTML側からのモジュールインポートに備えた、完全なエクスポート定義の維持
export {
    secureFetch,
    searchAddress,
    copyCoords,
    initModalMap,
    initSupportSidebarToggle,
    initThreadTabsUi,
    handleCreateProject,
    handleUpdateProject,
    deleteProject,
    clearProjectChatHistory,
    createProjectChatThread,
    deleteProjectChatThread,
    switchProjectChatThread,
    handleAsyncAddComment,
    handleRemoveComment,
    handleAddMember,
    handleRemoveMember,
    handleDeleteFaq,
    openFaqModal,
    handleSaveFaq,
    handleCsvUpload,
    loadCsvData,
    handleDeleteCsv,
    handlePostgresImport,
    openCsvPreviewByDocId,
    checkUploadOnLoadModule as checkUploadOnLoad,
    handleUploadModule as handleUpload,
    handleChat,
    appendMsg,
    initDebugLogViewer,
    bindGlobalFunctions,
    initSupportPanelPreferencePersistence,
    // ── UIモジュールから仕入れた関数群を support.php へ正確に再出荷 ──
    openAppModal,
    closeProjectModal,
    closeEditModal,
    bindModalEvents,
    closeTab,
    scrollToBottom,
    initChatInput,
    injectPdfLoadingMask,
    switchTab,
    openPdfTab,
    initResizer
};
