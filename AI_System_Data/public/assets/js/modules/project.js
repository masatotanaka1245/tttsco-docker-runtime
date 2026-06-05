/**
 * project.js - 案件データの登録・更新・削除・コメント・メンバー管理 モジュール
 * (support.js からインポートされてグローバル空間にバインドされるモジュールファイルです)
 */
import { secureFetch, getConfig } from './api.js?v=4';

/**
 * コメントテキスト内のURLを検出し、安全にハイパーリンク（aタグ）に自動変換するヘルパー
 */
function makeClickableLinksJs(text) {
    const escaped = text
        .replace(/&/g, "&amp;")
        .replace(/</g, "&lt;")
        .replace(/>/g, "&gt;")
        .replace(/"/g, "&quot;")
        .replace(/'/g, "&#039;");
    
    const urlPattern = /(https?:\/\/[^\s<]+)/g;
    return escaped
        .replace(urlPattern, '<a href="$1" target="_blank" rel="noopener noreferrer" class="text-blue-500 hover:underline break-all">$1</a>')
        .replace(/\n/g, '<br>');
}

/**
 * コメント数の表示バッジ（親タブ、内部ヘッダー）の数値を即時同期・増減させるヘルパー
 */
function updateCommentBadge(offset) {
    // 1. 親タブヘッダーバッジ "💬 コメント (X)"
    const tabBtn = document.getElementById('btn-comments');
    if (tabBtn) {
        const match = tabBtn.textContent.match(/\((\d+)\)/);
        if (match) {
            const newCount = Math.max(0, parseInt(match[1], 10) + offset);
            tabBtn.textContent = `💬 コメント (${newCount})`;
        }
    }
    
    // 2. タブ内部のヘッダー横バッジ "X 件"
    const innerBadge = document.querySelector('#tab-comments h3 + span');
    if (innerBadge) {
        const match = innerBadge.textContent.match(/(\d+)/);
        if (match) {
            const newCount = Math.max(0, parseInt(match[1], 10) + offset);
            innerBadge.textContent = `${newCount} 件`;
        }
    }
}

/**
 * A. 新規案件の登録処理
 */
export async function handleCreateProject(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const payload = Object.fromEntries(fd);
    
    try {
        const data = await secureFetch('api/add_project.php', { 
            method: 'POST', 
            body: JSON.stringify(payload) 
        });
        if (data.success) {
            window.location.href = `?project_id=${data.project_id}`;
        } else {
            alert(`登録エラー: ${data.error || '不明なエラー'}`);
        }
    } catch (err) { 
        alert(`通信エラーが発生しました: ${err.message}`); 
    }
}

/**
 * B. 案件情報の更新処理
 */
export async function handleUpdateProject(e) {
    e.preventDefault();
    const fd = new FormData(e.target);
    const payload = Object.fromEntries(fd);

    try {
        const data = await secureFetch('api/update_project.php', { 
            method: 'POST', 
            body: JSON.stringify(payload) 
        });
        if (data.success) {
            location.reload();
        } else {
            alert(`更新エラー: ${data.error || '不明なエラー'}`);
        }
    } catch (err) { 
        alert(`通信エラーが発生しました: ${err.message}`); 
    }
}

/**
 * C. 案件の物理削除処理
 */
export async function deleteProject() {
    const { projectId } = getConfig();
    if (!projectId || !confirm('この案件を削除しますか？関連資料やチャット履歴も完全に削除されます。')) return;

    try {
        const data = await secureFetch('api/delete_project.php', {
            method: 'POST',
            body: JSON.stringify({ id: projectId })
        });
        if (data.success) {
            window.location.href = 'support.php';
        } else {
            alert(`削除エラー: ${data.error}`);
        }
    } catch (err) {
        alert(`通信エラー: ${err.message}`);
    }
}

function getActiveTabId() {
    return document.querySelector('.tab-btn.active')?.id?.replace('btn-', '') || 'overview';
}

function buildSupportUrl(projectId, tab = 'overview', threadId = null) {
    const params = new URLSearchParams({
        project_id: String(projectId),
        tab: String(tab)
    });
    if (threadId) {
        params.set('thread_id', String(threadId));
    }
    return `support.php?${params.toString()}`;
}

/**
 * C-2. 案件のチャット履歴削除処理
 */
export async function clearProjectChatHistory() {
    const { projectId } = getConfig();
    if (!projectId) return;

    const confirmed = confirm(
        'この案件のチャット履歴を削除しますか？\n\n'
        + '削除対象: チャット履歴 / 推論ログ / 評価 / FAQ参照\n'
        + '保持対象: PDF / CSV / コメント / 案件本体'
    );
    if (!confirmed) return;

    try {
        const data = await secureFetch('api/clear_project_chat_history.php', {
            method: 'POST',
            body: JSON.stringify({ project_id: projectId })
        });

        if (data.success) {
            if (typeof window.afterProjectHistoryCleared === 'function') {
                await window.afterProjectHistoryCleared(projectId, data);
                return;
            }
            location.reload();
        } else {
            alert(`削除エラー: ${data.error || '不明なエラー'}`);
        }
    } catch (err) {
        alert(`通信エラー: ${err.message}`);
    }
}

export async function createProjectChatThread() {
    const { projectId } = getConfig();
    if (!projectId) return;

    try {
        const data = await secureFetch('api/create_chat_thread.php', {
            method: 'POST',
            body: JSON.stringify({ project_id: projectId })
        });
        if (!data.success || !data.thread?.id) {
            alert(`作成エラー: ${data.error || '不明なエラー'}`);
            return;
        }

        const activeTab = getActiveTabId();
        window.location.href = buildSupportUrl(projectId, activeTab, data.thread.id);
    } catch (err) {
        alert(`通信エラー: ${err.message}`);
    }
}

export async function deleteProjectChatThread(threadId) {
    const { projectId, threadId: currentThreadId } = getConfig();
    if (!projectId || !threadId) return;

    const confirmed = confirm('この会話スレッドを削除しますか？このスレッド内の会話履歴と紐づくFAQ参照も削除されます。');
    if (!confirmed) return;

    try {
        const data = await secureFetch('api/delete_chat_thread.php', {
            method: 'POST',
            body: JSON.stringify({
                project_id: projectId,
                thread_id: threadId
            })
        });
        if (!data.success) {
            alert(`削除エラー: ${data.error || '不明なエラー'}`);
            return;
        }

        const activeTab = getActiveTabId();
        const nextThreadId = data.fallback_thread_id || currentThreadId || null;
        window.location.href = buildSupportUrl(projectId, activeTab, nextThreadId);
    } catch (err) {
        alert(`通信エラー: ${err.message}`);
    }
}

export function switchProjectChatThread(threadId) {
    const { projectId } = getConfig();
    if (!projectId || !threadId) return;

    const activeTab = getActiveTabId();
    window.location.href = buildSupportUrl(projectId, activeTab, threadId);
}

/**
 * D. コメントの非同期追加処理
 */
export async function handleAddComment(e) {
    e.preventDefault();
    const { projectId } = getConfig();
    const form = e.target;
    const input = form.querySelector('textarea[name="comment"]');
    const commentText = input.value.trim();
    
    if (!projectId || !commentText) return;
    
    const submitBtn = form.querySelector('button[type="submit"]');
    submitBtn.disabled = true;
    submitBtn.textContent = '送信中...';
    
    try {
        const data = await secureFetch('api/add_comment.php', {
            method: 'POST',
            body: JSON.stringify({ project_id: projectId, comment: commentText })
        });
        
        if (data && data.success && data.comment) {
            const c = data.comment;
            
            // support.php 側の最新コンテナ（#comment-list-container）を優先的に取得
            let commentList = document.getElementById('comment-list-container');
            if (!commentList) {
                commentList = document.querySelector('#tab-comments .space-y-3') || document.querySelector('#tab-comments .space-y-4');
            }
            
            if (commentList) {
                // 「まだコメントはありません」のプレースホルダー表示があれば消去
                const noCommentMsg = commentList.querySelector('.text-center') || commentList.querySelector('.italic');
                if (noCommentMsg) noCommentMsg.remove();

                const dt = new Date(c.created_at.replace(/-/g, '/'));
                const yyyy = dt.getFullYear();
                const mm = String(dt.getMonth() + 1).padStart(2, '0');
                const dd = String(dt.getDate()).padStart(2, '0');
                const hh = String(dt.getHours()).padStart(2, '0');
                const min = String(dt.getMinutes()).padStart(2, '0');
                const timeStr = `${yyyy}/${mm}/${dd} ${hh}:${min}`;

                const escapeHTML = (str) => str.replace(/[&<>'"]/g, 
                    tag => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'}[tag])
                );
                
                const safeText = makeClickableLinksJs(c.comment_text);
                const safeName = escapeHTML(c.username);
                const firstChar = safeName.charAt(0);

                // コメント表示用HTMLの組み立て (既存デザインと完全同一)
                const commentHtml = `
                    <div id="comment-container-${c.id}" class="bg-white p-4 rounded-2xl border border-slate-200 shadow-sm animate-fadeIn hover:shadow-md transition-shadow">
                        <div class="flex justify-between items-start mb-2">
                            <div class="flex items-center gap-2">
                                <div class="w-6 h-6 rounded-full bg-indigo-100 flex items-center justify-center text-xs font-bold text-indigo-700">
                                    ${firstChar}
                                </div>
                                <span class="font-bold text-sm text-slate-700">${safeName}</span>
                            </div>
                            <div class="flex items-center gap-3">
                                <span class="text-[10px] font-mono text-slate-400">${timeStr}</span>
                                <button type="button" onclick="handleRemoveComment(${c.id})" class="text-slate-300 hover:text-red-500 hover:bg-red-50 w-6 h-6 rounded flex items-center justify-center transition-all" title="コメントを削除">🗑️</button>
                            </div>
                        </div>
                        <div class="text-xs text-slate-600 pt-1 leading-relaxed pl-8">${safeText}</div>
                    </div>
                `;
                
                commentList.insertAdjacentHTML('afterbegin', commentHtml);
                
                // バッジ数の即時インクリメント同期
                updateCommentBadge(1);

                // フォームのクリアと高さを初期（1行分）へ綺麗にリセット
                form.reset();
                input.style.height = 'auto';
            }
            
            submitBtn.disabled = false;
            submitBtn.textContent = '送信する';

        } else {
            alert(`エラー: ${data?.error || '不明なエラー'}`);
            submitBtn.disabled = false;
            submitBtn.textContent = '送信する';
        }
    } catch (err) {
        alert(`通信エラー: ${err.message}`);
        submitBtn.disabled = false;
        submitBtn.textContent = '送信する';
    }
}

/**
 * E. コメントの非同期物理削除処理
 */
export async function handleRemoveComment(commentId) {
    if (!commentId || !confirm('このコメントを削除してよろしいですか？')) return;

    try {
        const data = await secureFetch('api/delete_comment.php', {
            method: 'POST',
            body: JSON.stringify({ comment_id: commentId })
        });

        if (data && data.success) {
            const commentEl = document.getElementById(`comment-container-${commentId}`);
            if (commentEl) {
                // フワッと消えるフェードアウトアニメーション
                commentEl.style.transition = 'all 0.3s ease-out';
                commentEl.style.opacity = '0';
                commentEl.style.transform = 'translateY(-10px)';
                setTimeout(() => {
                    commentEl.remove();
                    
                    // リストが空になった場合にプレースホルダー（空メッセージ）を動的に復元
                    let commentList = document.getElementById('comment-list-container');
                    if (!commentList) {
                        commentList = document.querySelector('#tab-comments .space-y-3') || document.querySelector('#tab-comments .space-y-4');
                    }
                    if (commentList && commentList.querySelectorAll('[id^="comment-container-"]').length === 0) {
                        commentList.innerHTML = `
                            <div class="text-center py-12 bg-white rounded-2xl border border-dashed border-slate-200 shadow-sm">
                                <p class="text-3xl mb-2 opacity-30">💭</p>
                                <p class="text-xs text-slate-400 italic">まだコメントはありません。<br>プロジェクトに関するメモや進捗を共有しましょう。</p>
                            </div>
                        `;
                    }
                }, 300);
            }

            // バッジ数の即時デクリメント同期
            updateCommentBadge(-1);
            
        } else {
            alert(`エラー: ${data?.error || '不明なエラー'}`);
        }
    } catch (err) {
        alert(`通信エラー: ${err.message}`);
    }
}

/**
 * F. プロジェクトメンバーの非同期追加処理
 */
export async function handleAddMember(e) {
    e.preventDefault();
    const { projectId } = getConfig();
    const form = e.target;
    const userIdInput = form.querySelector('[name="user_id"]');
    const roleInput = form.querySelector('[name="role"]');
    
    const userId = userIdInput?.value;
    const role = roleInput?.value || 'member';
    
    if (!projectId || !userId) {
        alert('ユーザーを選択してください');
        return;
    }
    
    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = '追加中...';
    }
    
    try {
        const data = await secureFetch('api/add_project_member.php', {
            method: 'POST',
            body: JSON.stringify({ project_id: projectId, user_id: userId, role: role })
        });
        
        if (data && data.success) {
            location.reload();
        } else {
            alert(`エラー: ${data?.error || '不明なエラー'}`);
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = '追加する';
            }
        }
    } catch (err) {
        alert(`通信エラー: ${err.message}`);
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = '追加する';
        }
    }
}

/**
 * G. プロジェクトメンバーの非同期除外処理
 */
export async function handleRemoveMember(userId) {
    const { projectId } = getConfig();
    if (!projectId || !userId || !confirm('このメンバーを案件から外しますか？')) return;
    
    try {
        const data = await secureFetch('api/remove_project_member.php', {
            method: 'POST',
            body: JSON.stringify({ project_id: projectId, user_id: userId })
        });
        
        if (data && data.success) {
            location.reload();
        } else {
            alert(`エラー: ${data?.error || '不明なエラー'}`);
        }
    } catch (err) {
        alert(`通信エラー: ${err.message}`);
    }
}
