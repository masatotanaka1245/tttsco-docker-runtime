/**
 * upload.js - ファイルアップロードおよび非同期解析進捗の監視モジュール
 */
import { secureFetch, getConfig } from './api.js?v=4';

export async function checkUploadOnLoad() {
    const { projectId } = getConfig();
    if (!projectId) return;

    try {
        const d = await secureFetch('api/get_upload_status.php');
        if (d && d.status === 'processing') {
            startUploadTracking(false);
        }
    } catch (e) {
        console.warn('Upload status check failed', e);
    }
}

function startUploadTracking(isNewUpload = false, formData = null) {
    let statusOverlay = document.getElementById('upload-status-overlay');
    if (!statusOverlay) {
        statusOverlay = document.createElement('div');
        statusOverlay.id = 'upload-status-overlay';
        statusOverlay.className = 'fixed bottom-6 right-6 bg-slate-900 text-white p-5 rounded-2xl shadow-2xl z-50 text-sm flex flex-col gap-3 min-w-[360px] animate-fadeIn border border-white/10 transition-all duration-500 opacity-100';
        document.body.appendChild(statusOverlay);
    }
    
    let isCancelling = false;
    
    statusOverlay.innerHTML = `
        <div class="flex justify-between items-start border-b border-white/10 pb-3">
            <div class="flex flex-col gap-1">
                <span class="text-[9px] text-blue-400 uppercase tracking-widest font-black">RAG Pipeline</span>
                <span id="status-project-name" class="text-xs font-bold text-slate-200 truncate max-w-[200px]" title="アップロード先を特定中...">📂 アップロード先を特定中...</span>
            </div>
            <span id="status-percent" class="text-2xl font-black text-cyan-400 font-mono">0%</span>
        </div>
        <div class="mt-1 flex justify-between items-end">
            <div id="status-message" class="text-[11px] leading-snug flex items-center font-bold text-slate-100">${isNewUpload ? '🚀 サーバーへ安全に送信中...' : '解析状況を復元しています...'}</div>
            <div id="status-pages" class="text-[10px] text-slate-300 font-mono font-bold bg-white/10 px-2.5 py-0.5 rounded-full border border-white/5">-- / --</div>
        </div>
        <div class="w-full bg-white/10 h-2.5 rounded-full overflow-hidden shadow-inner mt-1"><div id="status-bar" class="bg-gradient-to-r from-blue-600 to-cyan-500 h-full w-0 transition-all duration-700 ease-out"></div></div>
        <div id="cancel-btn-container" class="flex justify-end mt-3 pt-2 border-t border-white/5">
            <button id="btn-cancel-upload" class="bg-red-500/80 hover:bg-red-600 text-white text-[9px] font-black uppercase px-4 py-2 rounded shadow transition-colors duration-150 ease-in-out">⏹️ 解析を中断</button>
        </div>
    `;

    document.getElementById('btn-cancel-upload')?.addEventListener('click', async () => {
        if (!confirm('現在実行中の解析を安全に中断しますか？\n（データベースの不整合を防ぐため、保存途中のデータは安全に破棄されます）')) return;
        
        isCancelling = true;
        
        const cancelBtn = document.getElementById('btn-cancel-upload');
        if (cancelBtn) {
            cancelBtn.disabled = true;
            cancelBtn.className = 'bg-slate-700 text-slate-400 text-[10px] font-black uppercase px-4 py-2 rounded cursor-not-allowed transition-colors duration-150';
            cancelBtn.textContent = '中断要求中...';
        }

        try {
            const data = await secureFetch('api/cancel_upload.php', { method: 'POST' });
            if (data && data.success) {
                const msg = document.getElementById('status-message');
                if (msg) msg.innerHTML = '<span class="text-amber-400 font-bold animate-pulse">⏳ 中断リクエスト送信済。キリの良いところで安全に停止します...</span>';
            } else {
                isCancelling = false;
                if (cancelBtn) {
                    cancelBtn.disabled = false;
                    cancelBtn.textContent = '⏹️ 解析を中断';
                    cancelBtn.className = 'bg-red-500/80 hover:bg-red-600 text-white text-[9px] font-black uppercase px-4 py-2 rounded shadow transition-colors duration-150 ease-in-out';
                }
                alert(data?.error || '中断処理に失敗しました。');
            }
        } catch (e) {
            isCancelling = false;
            if (cancelBtn) {
                cancelBtn.disabled = false;
                cancelBtn.textContent = '⏹️ 解析を中断';
                cancelBtn.className = 'bg-red-500/80 hover:bg-red-600 text-white text-[9px] font-black uppercase px-4 py-2 rounded shadow transition-colors duration-150 ease-in-out';
            }
            alert('中断要求の通信に失敗しました。');
        }
    });

    const timer = setInterval(async () => {
        try {
            const d = await secureFetch('api/get_upload_status.php');
            if (!d || d.status === 'idle') return;
            
            const bar = document.getElementById('status-bar'), 
                  pct = document.getElementById('status-percent'), 
                  msg = document.getElementById('status-message'),
                  projName = document.getElementById('status-project-name'),
                  pages = document.getElementById('status-pages');
                  
            if (bar) bar.style.width = d.progress + '%';
            if (pct) pct.textContent = d.progress + '%';
            
            if (isCancelling && d.status === 'processing') {
                if (msg) msg.innerHTML = `<span class="text-amber-400 font-bold animate-pulse">⏳ 中断処理中... (現在のステップ: ${d.message})</span>`;
            } else if (!isCancelling) {
                if (msg) msg.textContent = d.message || '解析を実行中...';
            }
            
            if (projName && d.project_name) {
                projName.textContent = '📂 ' + d.project_name;
                projName.title = d.project_name;
            }
            
            if (pages && d.total > 0) {
                let currentPage = Math.ceil(d.current);
                if (currentPage > d.total) currentPage = d.total;
                if (currentPage < 1) currentPage = 1;
                pages.textContent = `P. ${currentPage} / ${d.total}`;
            }
            
            if (d.status === 'completed') { 
                clearInterval(timer);
                statusOverlay.classList.replace('bg-slate-900', 'bg-emerald-900');
                const cancelContainer = document.getElementById('cancel-btn-container');
                if (cancelContainer) cancelContainer.remove();
                
                if (!isNewUpload) {
                    msg.innerHTML = '<span class="text-emerald-400 font-black">✨ 解析が完了しました。画面を更新します。</span>';
                    setTimeout(() => location.reload(), 2000);
                }
            }
            
            if (d.status === 'cancelled') {
                clearInterval(timer);
                statusOverlay.classList.replace('bg-slate-900', 'bg-slate-800');
                if (msg) msg.innerHTML = '<span class="text-amber-400 font-black">⏹️ 解析を正常に中断・破棄しました。画面を閉じます。</span>';
                
                const cancelContainer = document.getElementById('cancel-btn-container');
                if (cancelContainer) cancelContainer.remove();
                
                setTimeout(() => {
                    statusOverlay.classList.replace('opacity-100', 'opacity-0');
                    setTimeout(() => statusOverlay.remove(), 500);
                }, 3000);
            }
            
            if (d.status === 'error') { 
                clearInterval(timer);
                if (msg) msg.innerHTML = `<span class="text-red-400">Error: ${d.error || '解析失敗'}</span>`;
                statusOverlay.classList.replace('bg-slate-900', 'bg-red-950');
                const cancelContainer = document.getElementById('cancel-btn-container');
                if (cancelContainer) cancelContainer.remove();
                setTimeout(() => statusOverlay.remove(), 10000);
            }
        } catch (e) { clearInterval(timer); }
    }, 1500);

    if (isNewUpload && formData) {
        setTimeout(() => {
            (async () => {
                try {
                    const data = await secureFetch('api/upload.php', { method: 'POST', body: formData });
                    
                    clearInterval(timer);
                    if (data.success) {
                        const msg = document.getElementById('status-message');
                        if (msg) msg.innerHTML = '<span class="text-emerald-400 font-black">✨ 解析完了！まもなくリロードします</span>';
                        if (document.getElementById('status-bar')) document.getElementById('status-bar').style.width = '100%';
                        setTimeout(() => location.reload(), 2000);
                    } else {
                        const errReason = data.error || '不明なエラー';
                        if (!errReason.includes('中断')) {
                            const msg = document.getElementById('status-message');
                            if (msg) msg.innerHTML = `<span class="text-red-400">❌ 失敗: ${errReason}</span>`;
                            setTimeout(() => statusOverlay.remove(), 10000);
                        }
                    }
                } catch (e) { 
                    clearInterval(timer); 
                    const currentMsg = document.getElementById('status-message')?.textContent || '';
                    if (!currentMsg.includes('中断')) {
                        statusOverlay?.remove(); 
                        alert('アップロード中に通信エラーが発生しました: ' + e.message); 
                    }
                }
            })();
        }, 50);
    }
}

export async function handleUpload() {
    const { projectId } = getConfig();
    const fileInput = document.getElementById('file-upload');
    const file = fileInput?.files[0]; if (!file || !projectId) return;
    
    const analysisMode = document.getElementById('analysis-mode')?.value || 'tiles';
    const formData = new FormData(); 
    
    formData.append('pdf', file); 
    formData.append('project_id', projectId);
    formData.append('analysis_mode', analysisMode);
    
    fileInput.value = '';
    
    startUploadTracking(true, formData);
}