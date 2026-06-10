/**
 * ui.js - 画面の見た目や動きに特化したUI制御モジュール
 * ★無限ループ回避・リサイズ永続化・安全弁フック 統合版
 */

// =========================================================================
// ★モジュール実行の瞬間に、support.php(HTML側)の生関数を安全に別名退避（無限ループ回避）
// =========================================================================
const phpCanvasSwitchTab = typeof window.switchTab === 'function' ? window.switchTab : null;

// =========================================================================
// 1. ドラッグ式リサイザブル・スプリットビュー
// =========================================================================

function initResizer() {
    const handle = document.getElementById('resize-handle');
    const rightPanel = document.getElementById('right-panel');
    
    if (!handle || !rightPanel) return;

    // ページロード時に保存された幅を自動復元するロジック
    const savedWidth = localStorage.getItem('support_panel_width');
    if (savedWidth) {
        rightPanel.style.width = savedWidth;
        rightPanel.style.minWidth = savedWidth;
        rightPanel.style.maxWidth = savedWidth;
        rightPanel.style.flexBasis = savedWidth;
        rightPanel.style.setProperty('--support-width', savedWidth);
    }

    handle.addEventListener('mousedown', function (e) {
        e.preventDefault();
        
        document.body.style.cursor = 'col-resize';
        document.body.classList.add('select-none');
        
        rightPanel.style.transition = 'none';

        const iframeShield = document.createElement('div');
        iframeShield.style.position = 'fixed';
        iframeShield.style.inset = '0';
        iframeShield.style.zIndex = '9999';
        iframeShield.style.cursor = 'col-resize';
        document.body.appendChild(iframeShield);

        let targetWidth = rightPanel.offsetWidth; 

        const doDrag = function (moveEvent) {
            const windowWidth = window.innerWidth;
            targetWidth = windowWidth - moveEvent.clientX;

            const minWidth = 280; 
            const maxWidth = windowWidth * 0.60; 

            if (targetWidth < minWidth) targetWidth = minWidth;
            if (targetWidth > maxWidth) targetWidth = maxWidth;

            rightPanel.style.width = `${targetWidth}px`;
            rightPanel.style.minWidth = `${targetWidth}px`;
            rightPanel.style.maxWidth = `${targetWidth}px`;
            rightPanel.style.flexBasis = `${targetWidth}px`;
            rightPanel.style.setProperty('--support-width', `${targetWidth}px`);
        };

        const stopDrag = function () {
            window.removeEventListener('mousemove', doDrag);
            window.removeEventListener('mouseup', stopDrag);
            
            document.body.style.cursor = '';
            document.body.classList.remove('select-none');
            
            rightPanel.style.transition = '';
            iframeShield.remove();
            
            localStorage.setItem('support_panel_width', targetWidth + 'px');
        };

        window.addEventListener('mousemove', doDrag);
        window.addEventListener('mouseup', stopDrag);
    });
}

// =========================================================================
// 2. UI・モーダル開閉・タブ遷移ロジック
// =========================================================================

function openAppModal(modalId, lat = null, lng = null, prefillData = null) {
    const modal = document.getElementById(modalId);
    if (modal) {
        modal.classList.replace('hidden', 'flex');

        // ★【直撃フォールバックシールド】ID名へ直接値を叩き込む確実な復元ロジック
        if (prefillData && modalId === 'edit-project-modal') {
            // 鍵名が project_name または name のどちらで届いても100%確実にIDへ直撃代入
            const nameValue = prefillData.project_name || prefillData.name || '';
            const nameInput = document.getElementById('edit-project-name');
            if (nameInput) nameInput.value = nameValue;

            // 他の主要フィールドもID直撃で2重に安全弁をホールド
            const idInput = document.getElementById('edit-project-id');
            if (idInput && prefillData.id) idInput.value = prefillData.id;

            const descInput = document.getElementById('edit-project-description');
            if (descInput && prefillData.description !== undefined) descInput.value = prefillData.description;

            const addrInput = document.getElementById('edit-project-address');
            if (addrInput && prefillData.address !== undefined) addrInput.value = prefillData.address;

            if (prefillData.start_date) {
                const startInput = document.getElementById('edit-project-start-date');
                if (startInput) startInput.value = prefillData.start_date.split(' ')[0];
            }
            if (prefillData.end_date) {
                const endInput = document.getElementById('edit-project-end-date');
                if (endInput) endInput.value = prefillData.end_date.split(' ')[0];
            }
        }

        // 既存の汎用ループによる流し込み（安全網として維持）
        if (prefillData) {
            Object.keys(prefillData).forEach(key => {
                const input = modal.querySelector(`[name="${key}"]`);
                if (input) {
                    if (input.type === 'date' && prefillData[key]) {
                        input.value = prefillData[key].split(' ')[0];
                    } else {
                        input.value = prefillData[key];
                    }
                }
            });
        }
        
        const mode = modalId === 'project-modal' ? 'new' : 'edit';
        if (lat !== null && lng !== null) {
            const latInput = document.getElementById(`${mode}-lat`);
            const lngInput = document.getElementById(`${mode}-lng`);
            if (latInput) latInput.value = lat;
            if (lngInput) lngInput.value = lng;
        }
        
        // ★【安全弁フック】親ファイルに残存する initModalMap を安全にコール
        if (typeof window.initModalMap === 'function') {
            window.initModalMap(`${mode}-map-container`, `${mode}-lat`, `${mode}-lng`, lat, lng);
        }
    }
}

// プロジェクト新規登録モーダルを閉じる
function closeProjectModal() { 
    const m = document.getElementById('project-modal'); 
    if(m) {
        m.classList.replace('flex', 'hidden'); 
        const form = document.getElementById('new-project-form');
        if (form) form.reset();
    }
}

// プロジェクト編集モーダルを閉じる
function closeEditModal() { 
    const m = document.getElementById('edit-project-modal'); 
    if(m) m.classList.replace('flex', 'hidden'); 
}

// モーダル背景クリックおよびEscキーによるクローズイベントのバインド
function bindModalEvents() {
    document.querySelectorAll('[role="dialog"]').forEach(modal => {
        modal.addEventListener('mousedown', (e) => {
            if (e.target === modal) {
                modal.classList.replace('flex', 'hidden');
            }
        });
    });
    
    document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') {
            document.querySelectorAll('.fixed.inset-0:not(.hidden)').forEach(m => m.classList.replace('flex', 'hidden'));
        }
    });
}

// 展開された動的ドキュメントタブを削除し、概要へ遷移
function closeTab(tabId, btnId, e) {
    if (e) e.stopPropagation();
    document.getElementById(tabId)?.remove();
    document.getElementById(btnId)?.remove();
    switchTab('tab-overview');
}

// チャット画面を最下部へスクロール
function scrollToBottom() {
    const box = document.getElementById('chat-box');
    if (box) {
        setTimeout(() => box.scrollTop = box.scrollHeight, 50);
    }
}

// ★精密ブラッシュアップ改修版: チャット入力エリアの自動伸縮およびEnter送信制御
function initChatInput() {
    const chatInput = document.getElementById('chat-input');
    if (!chatInput) return;
    let isImeComposing = false;
    let lastCompositionEndAt = 0;

    chatInput.addEventListener('compositionstart', () => {
        isImeComposing = true;
    });

    chatInput.addEventListener('compositionend', () => {
        isImeComposing = false;
        lastCompositionEndAt = Date.now();
    });

    // IME変換中のEnter誤送信を避けつつ、ShiftなしEnterだけを送信に使う
    chatInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            const justFinishedComposition = Date.now() - lastCompositionEndAt < 80;
            const isCompositionEnter = e.isComposing || isImeComposing || e.keyCode === 229 || e.which === 229 || justFinishedComposition;

            if (!e.shiftKey && !isCompositionEnter) {
                e.preventDefault(); // 通常サブミットによる暴発リロードを完全無効化

                // グローバル空間にバインドされているチャット送信メイン関数を安全に直接実行
                if (typeof window.handleChat === 'function') {
                    window.handleChat(e);
                }
            }
            // Shift+Enter時、またはIME変換中/変換直後は通常通り改行・変換確定を優先する。
        }
    });

    // 入力エリアの文字量に応じたリアルタイム高さを動的追従
    chatInput.addEventListener('input', function() {
        this.style.height = 'auto'; 
        const nextHeight = Math.min(this.scrollHeight, 180);
        this.style.height = nextHeight + 'px';
    });
    
    // コメント入力欄用の自動伸縮リスナー
    const commentTextarea = document.getElementById('comment-textarea');
    if (commentTextarea) {
        commentTextarea.addEventListener('input', function() {
            this.style.height = 'auto';
            this.style.height = this.scrollHeight + 'px';
        });
    }
}

// ★タイミングのズレを完全克服する、ダブルセーフティ搭載のローディングマスク
function injectPdfLoadingMask(container) {
    if (!container) return;

    const oldMask = container.querySelector('.pdf-loading-mask-overlay');
    if (oldMask) oldMask.remove();

    const mask = document.createElement('div');
    mask.className = 'pdf-loading-mask-overlay absolute inset-0 bg-white/90 backdrop-blur-xs flex flex-col items-center justify-center gap-3 z-50 transition-all duration-300 opacity-100';
    mask.innerHTML = `
        <div class="flex flex-col items-center gap-2.5 select-none animate-pulse-soft">
            <div class="w-5 h-5 border-2 border-[#4F5D95] border-t-transparent rounded-full animate-spin"></div>
            <span class="text-[11px] font-black text-slate-400 tracking-wide uppercase">📄 TEPSCO RAG 資料エンジンを高速レンダリング中...</span>
        </div>
    `;

    container.style.position = 'relative';
    container.appendChild(mask);

    const removeMaskSafe = () => {
        if (mask && mask.parentNode) {
            mask.classList.replace('opacity-100', 'opacity-0');
            setTimeout(() => { mask.remove(); }, 300);
        }
    };

    // 【安全弁：強制解除タイマー】1200ミリ秒（1.2秒）経過したら、何があっても幕を降ろす
    const safetyTimer = setTimeout(() => {
        console.log('PDF loading mask safety timer triggered.');
        removeMaskSafe();
    }, 1200);

    const iframe = container.querySelector('iframe');
    if (iframe) {
        const src = iframe.getAttribute('src') || '';
        
        let isAlreadyComplete = false;
        try {
            if (iframe.contentDocument && iframe.contentDocument.readyState === 'complete') {
                isAlreadyComplete = true;
            }
        } catch (e) {
            // クロスドメイン制限対策
        }

        if (!src || src === 'about:blank' || isAlreadyComplete) {
            clearTimeout(safetyTimer);
            removeMaskSafe();
            return;
        }

        iframe.onload = function () {
            clearTimeout(safetyTimer);
            removeMaskSafe();
        };
    } else {
        clearTimeout(safetyTimer);
        setTimeout(removeMaskSafe, 400);
    }
}

// メインワークスペースのタブ切り替え
function switchTab(tabId) {
    if (phpCanvasSwitchTab) {
        phpCanvasSwitchTab(tabId);
    } else {
        document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
        document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
        const tab = document.getElementById(tabId);
        if(tab) tab.classList.add('active');
        const btn = document.getElementById('btn-' + tabId.replace('tab-', ''));
        if(btn) btn.classList.add('active');
    }
    
    if (tabId === 'tab-pdf') {
        const targetContent = document.getElementById('tab-pdf');
        if (targetContent) {
            injectPdfLoadingMask(targetContent);
        }
    }
}

// 資料PDFタブまたはCSVデータプレビュー画面の動的展開
async function openPdfTab(docId, title, pageNumber = 1) {
    if (title.startsWith('[CSVデータ]') || title.includes('[CSVデータ]')) {
        try {
            const response = await fetch(`api/get_csv_file_id.php?doc_id=${docId}`);
            if (!response.ok) throw new Error(`HTTP Error: ${response.status}`);
            const data = await response.json();
            
            if (data && data.success && data.is_csv) {
                switchTab('tab-csv');
                // ★【安全弁フック】親ファイルに残存する loadCsvData を安全にコール
                if (typeof window.loadCsvData === 'function') {
                    await window.loadCsvData(data.csv_file_id, data.file_name);
                }
                return;
            }
        } catch (err) {
            alert('CSVデータとのプレビュー同期に失敗しました: ' + err.message);
            return;
        }
    }

    const tabId = 'tab-doc-' + docId;
    const pdfUrl = `api/view_pdf.php?id=${docId}&_=${Date.now()}#page=${Math.max(1, Number(pageNumber) || 1)}`;
    
    if (document.getElementById(tabId)) {
        const iframe = document.querySelector(`#${tabId} iframe`);
        if (iframe) iframe.src = pdfUrl;
        switchTab(tabId);
        return;
    }
    
    const btnId = 'btn-doc-' + docId;
    const btn = document.createElement('button'); 
    btn.id = btnId;
    btn.className = 'tab-btn flex items-center gap-2 px-4 py-1.5 text-[10px] rounded-t-lg border-x border-t border-slate-300 cursor-pointer bg-slate-200 text-slate-500 transition-all font-bold';
    btn.innerHTML = `<span onclick="switchTab('${tabId}')" class="truncate max-w-[120px]">📄 ${title}</span><div onclick="closeTab('${tabId}', '${btnId}', event)" class="hover:text-red-500 px-1 ml-1 cursor-pointer">×</div>`;
    document.getElementById('tab-header').appendChild(btn);
    
    const content = document.createElement('div');
    content.id = tabId;
    content.className = 'tab-content h-full w-full min-h-0 bg-slate-50 relative';
    content.innerHTML = `<iframe src="${pdfUrl}" class="w-full h-full min-h-0 border-none flex-1 bg-white"></iframe>`;
    document.getElementById('tab-container').appendChild(content);
    
    switchTab(tabId);
}

// ★最末尾エクスポートへ完全一本化一括出荷
export { 
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
