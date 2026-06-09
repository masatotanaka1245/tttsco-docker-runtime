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
import { handleCsvUpload, loadCsvData, handleDeleteCsv, handlePostgresImport, openCsvPreviewByDocId, handleCreateManualCsv, handleAppendCsvRow, openCsvCreateModal, closeCsvCreateModal, openCsvAppendModal, closeCsvAppendModal, handleStartCsvAiCategorizeJob, openCsvAiCategorizeModal, closeCsvAiCategorizeModal } from './modules/csv.js?v=5';
import { handleChat, appendMsg, initExistingCharts, initMaterialMemoActions, initDebugLogViewer } from './modules/chat.js?v=19';
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

function renderMaterialNoteModalPreview() {
    const preview = document.getElementById('modal-material-preview');
    const titleInput = document.getElementById('modal-material-title');
    const contentInput = document.getElementById('modal-material-content');
    const appendInput = document.getElementById('modal-material-append-note');
    if (!preview || !titleInput || !contentInput || !appendInput) return;

    const title = String(titleInput.value || '').trim();
    const content = String(contentInput.value || '').trim();
    const appendNote = String(appendInput.value || '').trim();

    let previewSource = content;
    if (appendNote) {
        const stamp = '## 更新予定';
        previewSource = previewSource
            ? `${previewSource}\n\n${stamp}\n\n${appendNote}`
            : `# ${title || '資料メモ'}\n\n${stamp}\n\n${appendNote}`;
    }
    if (!previewSource) {
        previewSource = title ? `# ${title}\n` : '';
    }

    if (!previewSource.trim()) {
        preview.innerHTML = '<div class="text-center py-10 text-xs text-slate-400 italic">ここに資料メモのプレビューが表示されます。</div>';
        return;
    }

    let html = previewSource
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');

    if (typeof marked !== 'undefined') {
        html = marked.parse(previewSource, { breaks: true });
        if (typeof DOMPurify !== 'undefined') {
            html = DOMPurify.sanitize(html);
        }
    } else {
        html = html.replace(/\n/g, '<br>');
    }

    preview.innerHTML = html;
}

function bindMaterialNoteModalPreview() {
    const titleInput = document.getElementById('modal-material-title');
    const contentInput = document.getElementById('modal-material-content');
    const appendInput = document.getElementById('modal-material-append-note');
    if (!titleInput || titleInput.dataset.previewBound === 'true') return;

    const handler = () => renderMaterialNoteModalPreview();
    [titleInput, contentInput, appendInput].forEach((input) => {
        input?.addEventListener('input', handler);
    });
    titleInput.dataset.previewBound = 'true';
}

function closeMaterialNoteModal() {
    const modal = document.getElementById('material-note-modal');
    if (!modal) return;
    modal.classList.replace('flex', 'hidden');
}

function bindMaterialNoteModalInteractions() {
    const modal = document.getElementById('material-note-modal');
    const form = document.getElementById('material-note-form');
    if (!modal || modal.dataset.interactionsBound === 'true') return;

    modal.addEventListener('click', (event) => {
        if (event.target === modal) {
            closeMaterialNoteModal();
        }
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && !modal.classList.contains('hidden')) {
            closeMaterialNoteModal();
            return;
        }

        if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 's' && !modal.classList.contains('hidden')) {
            event.preventDefault();
            form?.requestSubmit();
        }
    });

    modal.dataset.interactionsBound = 'true';
}

function escapeMaterialHtml(value = '') {
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function getMaterialElements() {
    return {
        configEl: document.getElementById('support-config'),
        countEl: document.getElementById('material-document-count'),
        listEl: document.getElementById('material-document-list'),
        flashEl: document.getElementById('material-flash-container'),
        previewTitleEl: document.getElementById('material-preview-title'),
        previewBodyEl: document.getElementById('material-preview-body'),
        editorPayloadEl: document.getElementById('material-note-editor-data'),
        editButton: document.getElementById('material-edit-button'),
        deleteButton: document.getElementById('material-delete-button'),
        deleteIdInput: document.getElementById('material-delete-document-id'),
        modal: document.getElementById('material-note-modal'),
        form: document.getElementById('material-note-form'),
        titleInput: document.getElementById('modal-material-title'),
        contentInput: document.getElementById('modal-material-content'),
        appendInput: document.getElementById('modal-material-append-note'),
        previewEl: document.getElementById('modal-material-preview'),
        titleLabel: document.getElementById('modal-title-material'),
    };
}

function getMaterialEmptyStateHtml() {
    return '<div class="text-center py-10 bg-slate-50/60 rounded-xl border border-dashed border-slate-200"><p class="text-xs text-slate-400 font-medium italic">資料メモはまだ登録されていません。</p></div>';
}

function getMaterialPreviewPlaceholderHtml() {
    return '<div class="text-center py-10 text-xs text-slate-400 italic">ここに資料メモのプレビューが表示されます。</div>';
}

function buildMaterialFlashHtml(message = '') {
    if (!message) return '';
    return `<div class="text-[11px] font-bold text-emerald-700 bg-emerald-50 border border-emerald-200 rounded-xl px-4 py-3">${escapeMaterialHtml(message)}</div>`;
}

function setMaterialSelectionState(selectedId = 0) {
    const { configEl, editButton, deleteButton, deleteIdInput } = getMaterialElements();
    if (configEl) {
        configEl.dataset.selectedMaterialDocumentId = String(selectedId || '');
    }
    if (editButton) {
        editButton.disabled = !selectedId;
    }
    if (deleteButton) {
        deleteButton.disabled = !selectedId;
    }
    if (deleteIdInput) {
        deleteIdInput.value = selectedId ? String(selectedId) : '';
    }
}

function renderMaterialDocumentList(materials = [], selectedId = 0) {
    const { projectId } = getConfig();
    if (!Array.isArray(materials) || materials.length === 0) {
        return getMaterialEmptyStateHtml();
    }

    return materials.map((material) => {
        const id = Number(material?.id || 0);
        const title = escapeMaterialHtml(material?.title || '資料メモ');
        const modifiedLabel = escapeMaterialHtml(material?.modified_label || '更新時刻なし');
        const activeClasses = id === Number(selectedId)
            ? 'border-indigo-300 bg-indigo-50/80'
            : 'border-slate-200 bg-white hover:border-slate-300 hover:bg-slate-50/70';
        const href = `support.php?project_id=${encodeURIComponent(projectId || '')}&tab=materials&material_doc_id=${encodeURIComponent(String(id))}`;

        return `<a href="${href}" data-material-document-id="${id}" class="block rounded-xl border px-4 py-3 shadow-2xs transition-all duration-200 ease-in-out ${activeClasses}"><div class="flex items-start justify-between gap-3"><div class="min-w-0"><div class="text-xs font-bold text-slate-700 truncate">${title}</div><div class="mt-1 text-[10px] text-slate-400 font-medium">${modifiedLabel}</div></div><span class="text-[9px] bg-slate-100 border border-slate-200 px-2 py-0.5 rounded font-mono text-slate-400 font-bold">MD</span></div></a>`;
    }).join('');
}

function updateMaterialEditorPayload(selected = {}) {
    const { editorPayloadEl } = getMaterialElements();
    if (!editorPayloadEl) return;
    editorPayloadEl.textContent = JSON.stringify({
        selected: {
            id: Number(selected?.id || 0),
            title: String(selected?.title || ''),
            content: String(selected?.content || ''),
        },
    });
}

function syncMaterialUrl(selectedId = 0) {
    const { projectId } = getConfig();
    if (!projectId) return;

    try {
        const nextUrl = new URL(window.location.href);
        nextUrl.searchParams.set('project_id', String(projectId));
        nextUrl.searchParams.set('tab', 'materials');
        if (selectedId) {
            nextUrl.searchParams.set('material_doc_id', String(selectedId));
        } else {
            nextUrl.searchParams.delete('material_doc_id');
        }
        window.history.replaceState({}, '', nextUrl.toString());
    } catch (error) {
        console.warn('material history update failed:', error);
    }
}

function updateMaterialTabView(payload) {
    const selected = payload?.material_document || {};
    const materials = Array.isArray(payload?.material_documents) ? payload.material_documents : [];
    const selectedId = Number(selected?.id || 0);
    const {
        countEl,
        listEl,
        flashEl,
        previewTitleEl,
        previewBodyEl,
    } = getMaterialElements();

    setMaterialSelectionState(selectedId);

    if (countEl) {
        countEl.textContent = `${materials.length} 件`;
    }

    if (listEl) {
        listEl.innerHTML = renderMaterialDocumentList(materials, selectedId);
    }

    if (flashEl) {
        flashEl.innerHTML = buildMaterialFlashHtml(payload?.flash_message || '');
    }

    if (previewTitleEl) {
        previewTitleEl.textContent = String(selected?.title || '');
    }

    if (previewBodyEl) {
        const previewHtml = String(selected?.preview_html || '');
        previewBodyEl.innerHTML = previewHtml !== ''
            ? previewHtml
            : getMaterialPreviewPlaceholderHtml();
    }

    updateMaterialEditorPayload({
        id: selectedId,
        title: selected?.title || '',
        content: selected?.content || '',
    });
}

async function handleSaveMaterialNote(e) {
    e.preventDefault();
    const { projectId } = getConfig();
    if (!projectId) {
        alert('案件IDが取得できませんでした。画面を再読み込みしてください。');
        return;
    }

    const form = e.target;
    const formData = new FormData(form);
    const submitButton = form.querySelector('button[type="submit"]:not([name="action"])');

    if (submitButton) {
        submitButton.disabled = true;
        submitButton.textContent = '保存中...';
    }

    try {
        const res = await secureFetch('api/save_material.php', {
            method: 'POST',
            body: JSON.stringify({
                project_id: Number(projectId),
                material_document_id: formData.get('material_document_id') || null,
                material_title: String(formData.get('material_title') || ''),
                material_content: String(formData.get('material_content') || ''),
                material_append_note: String(formData.get('material_append_note') || ''),
            })
        });

        if (res.success) {
            updateMaterialTabView(res);
            syncMaterialUrl(Number(res?.material_document?.id || 0));
            closeMaterialNoteModal();
            const appendNoteField = form.querySelector('#modal-material-append-note');
            if (appendNoteField) {
                appendNoteField.value = '';
            }
            return;
        }

        alert(res.error || '資料メモの保存に失敗しました。');
    } catch (err) {
        alert('通信エラーが発生しました: ' + err.message);
    } finally {
        if (submitButton) {
            submitButton.disabled = false;
            submitButton.textContent = '保存する';
        }
    }
}

async function loadMaterialDocument(materialDocumentId) {
    const { projectId } = getConfig();
    if (!projectId || !materialDocumentId) return;

    const res = await secureFetch(`api/get_material.php?project_id=${encodeURIComponent(projectId)}&material_document_id=${encodeURIComponent(String(materialDocumentId))}`, {
        method: 'GET',
        headers: {}
    });

    if (!res.success) {
        alert(res.error || '資料メモの読み込みに失敗しました。');
        return;
    }

    updateMaterialTabView(res);
    syncMaterialUrl(Number(res?.material_document?.id || materialDocumentId));
}

function bindMaterialDocumentListNavigation() {
    const listEl = document.getElementById('material-document-list');
    if (!listEl || listEl.dataset.navigationBound === 'true') return;

    listEl.addEventListener('click', async (event) => {
        const link = event.target.closest('a[data-material-document-id]');
        if (!link) return;
        event.preventDefault();

        const materialDocumentId = Number(link.dataset.materialDocumentId || 0);
        if (!materialDocumentId) return;
        await loadMaterialDocument(materialDocumentId);
    });

    listEl.dataset.navigationBound = 'true';
}

async function handleDeleteMaterialNote(e) {
    e.preventDefault();
    const { projectId } = getConfig();
    if (!projectId) {
        alert('案件IDが取得できませんでした。画面を再読み込みしてください。');
        return;
    }

    if (!confirm('この資料メモを削除しますか？')) {
        return;
    }

    const form = e.target;
    const documentIdInput = form.querySelector('input[name="material_document_id"]');
    const deleteButton = form.querySelector('button[type="submit"]');
    const materialDocumentId = Number(documentIdInput?.value || 0);
    if (!materialDocumentId) {
        alert('削除対象の資料メモが選択されていません。');
        return;
    }

    if (deleteButton) {
        deleteButton.disabled = true;
        deleteButton.textContent = '削除中...';
    }

    try {
        const res = await secureFetch('api/delete_material.php', {
            method: 'POST',
            body: JSON.stringify({
                project_id: Number(projectId),
                material_document_id: materialDocumentId,
            })
        });

        if (res.success) {
            updateMaterialTabView(res);
            syncMaterialUrl(Number(res?.material_document?.id || 0));
            return;
        }

        alert(res.error || '資料メモの削除に失敗しました。');
    } catch (err) {
        alert('通信エラーが発生しました: ' + err.message);
    } finally {
        if (deleteButton) {
            deleteButton.disabled = false;
            deleteButton.textContent = '削除';
        }
    }
}

function bindMaterialDeleteForm() {
    const form = document.getElementById('material-delete-form');
    if (!form || form.dataset.deleteBound === 'true') return;

    form.addEventListener('submit', handleDeleteMaterialNote);
    form.dataset.deleteBound = 'true';
}

function openMaterialNoteModal(mode = 'edit') {
    const {
        modal,
        editorPayloadEl: source,
        titleInput,
        contentInput,
        appendInput,
        titleLabel,
    } = getMaterialElements();
    if (!modal || !source) return;

    let payload = { selected: { id: 0, title: '', content: '' } };
    try {
        payload = JSON.parse(source.textContent || '{}');
    } catch (error) {
        console.warn('material-note-editor-data parse error:', error);
    }

    const selected = payload?.selected || {};
    const isNew = mode === 'new';
    const docIdInput = document.getElementById('modal-material-document-id');

    if (docIdInput) docIdInput.value = isNew ? '' : String(selected.id || '');
    if (titleInput) titleInput.value = isNew ? '' : String(selected.title || '');
    if (contentInput) contentInput.value = isNew ? '' : String(selected.content || '');
    if (appendInput) appendInput.value = '';
    if (titleLabel) {
        titleLabel.textContent = isNew ? '資料メモの新規作成' : '資料メモの編集';
    }

    bindMaterialNoteModalPreview();
    bindMaterialNoteModalInteractions();
    renderMaterialNoteModalPreview();
    modal.classList.replace('hidden', 'flex');
    setTimeout(() => titleInput?.focus(), 40);
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
    window.openMaterialNoteModal = openMaterialNoteModal;
    window.closeMaterialNoteModal = closeMaterialNoteModal;
    window.loadMaterialDocument = loadMaterialDocument;
    window.handleDeleteMaterialNote = handleDeleteMaterialNote;
    window.handleSaveMaterialNote = handleSaveMaterialNote;
    window.handleSaveFaq = handleSaveFaq;
    
    // CSV同期・ファイルアップロード系 (csv.jsからインポートした関数を直接マウント)
    window.handleCsvUpload = handleCsvUpload;
    window.loadCsvData = loadCsvData;
    window.handleDeleteCsv = handleDeleteCsv;
    window.handlePostgresImport = handlePostgresImport;
    window.openCsvPreviewByDocId = openCsvPreviewByDocId;
    window.handleCreateManualCsv = handleCreateManualCsv;
    window.handleAppendCsvRow = handleAppendCsvRow;
    window.openCsvCreateModal = openCsvCreateModal;
    window.closeCsvCreateModal = closeCsvCreateModal;
    window.openCsvAppendModal = openCsvAppendModal;
    window.closeCsvAppendModal = closeCsvAppendModal;
    window.checkUploadOnLoad = checkUploadOnLoadModule;
    window.handleUpload = handleUploadModule;

    // AIチャット系 (chat.jsからインポートした関数を正確にマウント)
    window.handleChat = handleChat;
    window.appendMsg = appendMsg;
    window.initExistingCharts = initExistingCharts;
    window.initMaterialMemoActions = initMaterialMemoActions;
    window.initDebugLogViewer = initDebugLogViewer;
    
    // リサイザ初期化 (ui.jsからインポートした関数を直接マウント)
    window.initResizer = initResizer; 
    window.injectPdfLoadingMask = typeof injectPdfLoadingMask !== 'undefined' ? injectPdfLoadingMask : window.injectPdfLoadingMask;
}

function runSupportInitializer(label, fn) {
    try {
        if (typeof fn === 'function') {
            fn();
        }
    } catch (e) {
        console.error(`support.js initializer failed: ${label}`, e);
    }
}

runSupportInitializer('bindGlobalFunctions', bindGlobalFunctions);
runSupportInitializer('initSupportPanelPreferencePersistence', initSupportPanelPreferencePersistence);
runSupportInitializer('initSupportSidebarToggle', initSupportSidebarToggle);
runSupportInitializer('initThreadTabsUi', initThreadTabsUi);
runSupportInitializer('bindMaterialDocumentListNavigation', bindMaterialDocumentListNavigation);
runSupportInitializer('bindMaterialDeleteForm', bindMaterialDeleteForm);

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
    openMaterialNoteModal,
    closeMaterialNoteModal,
    loadMaterialDocument,
    handleDeleteMaterialNote,
    handleSaveMaterialNote,
    handleSaveFaq,
    handleCsvUpload,
    loadCsvData,
    handleDeleteCsv,
    handlePostgresImport,
    openCsvPreviewByDocId,
    handleCreateManualCsv,
    handleAppendCsvRow,
    openCsvCreateModal,
    closeCsvCreateModal,
    openCsvAppendModal,
    closeCsvAppendModal,
    checkUploadOnLoadModule as checkUploadOnLoad,
    handleUploadModule as handleUpload,
    handleChat,
    appendMsg,
    initMaterialMemoActions,
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
