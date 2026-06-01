/**
 * csv.js - CSVデータのアップロード、プレビュー、削除、およびリモートPostgreSQLデータ抽出インポート制御モジュール
 * (support.js からインポートされてグローバル空間にバインドされるモジュールファイルです)
 */
import { secureFetch, getConfig } from './api.js?v=4';

/**
 * HTMLエスケープヘルパー (テキストの安全なレンダリング用)
 */
const escapeHTML = (str) => String(str).replace(/[&<>'"]/g, tag => ({
    '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;'
}[tag] || tag));

/**
 * CSV関連バッジ（親タブ、インポート履歴など）の数値を即時同期させる
 */
function updateCsvBadge(offset) {
    const tabBtn = document.getElementById('btn-csv');
    if (tabBtn) {
        const match = tabBtn.textContent.match(/\((\d+)\)/);
        if (match) {
            const newCount = Math.max(0, parseInt(match[1], 10) + offset);
            tabBtn.textContent = `📊 CSVデータ (${newCount})`;
        }
    }
}

/**
 * A. CSVデータの非同期アップロード
 */
function handleCsvUpload(e) {
    e.preventDefault();
    const form = e.target;
    const fileInput = form.querySelector('input[type="file"]');
    const file = fileInput?.files[0];
    
    const { projectId } = getConfig();

    if (!projectId || !file) {
        alert('CSVファイルが選択されていないか、プロジェクト情報が不足しています。');
        return;
    }

    const formData = new FormData();
    formData.append('csv_file', file);
    formData.append('project_id', projectId);

    const submitBtn = form.querySelector('button[type="submit"]');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = '取り込み中...';
    }

    startStructuredImportTracking(true, 'api/upload_csv.php', formData);
}

/**
 * B. リモートPostgreSQLからのデータ抽出＆インポート
 */
function handlePostgresImport(e) {
    e.preventDefault();
    const form = e.target;
    const fd = new FormData(form);
    const payload = Object.fromEntries(fd);
    
    const { projectId } = getConfig();
    if (!projectId) return;

    payload.project_id = parseInt(projectId, 10);

    // インポートモーダルを安全に閉じてフォームをリセット
    const modal = document.getElementById('postgres-import-modal');
    if (modal) {
        modal.classList.replace('flex', 'hidden');
    }
    options.body = JSON.stringify(payload); // 安全マッピング維持
    form.reset();

    // RAG Pipeline の進捗追跡監視を起動
    startStructuredImportTracking(true, 'api/import_postgresql.php', payload);
}

/**
 * C. 構造化データのインポート進捗を追跡する共通プログレスヘルパー
 */
function startStructuredImportTracking(isNew = true, apiUrl, payloadData) {
    let statusOverlay = document.getElementById('upload-status-overlay');
    if (!statusOverlay) {
        statusOverlay = document.createElement('div');
        statusOverlay.id = 'upload-status-overlay';
        statusOverlay.className = 'fixed bottom-6 right-6 bg-slate-900 text-white p-5 rounded-2xl shadow-2xl z-50 text-sm flex flex-col gap-3 min-w-[360px] animate-fadeIn border border-white/10 transition-all duration-500 opacity-100';
        document.body.appendChild(statusOverlay);
    }

    statusOverlay.innerHTML = `
        <div class="flex justify-between items-start border-b border-white/10 pb-3">
            <div class="flex flex-col gap-1">
                <span class="text-[9px] text-[#00758F] uppercase tracking-widest font-black">Structured RAG Pipeline</span>
                <span id="status-project-name" class="text-xs font-bold text-slate-200 truncate max-w-[200px]" title="インポート先特定中...">📂 データベース構築中...</span>
            </div>
            <span id="status-percent" class="text-2xl font-black text-cyan-400 font-mono">0%</span>
        </div>
        <div class="mt-1 flex justify-between items-end">
            <div id="status-message" class="text-[11px] leading-snug flex items-center font-bold text-slate-100">データの要求 ＆ 解析準備中...</div>
            <div id="status-pages" class="text-[10px] text-slate-300 font-mono font-bold bg-white/10 px-2.5 py-0.5 rounded-full border border-white/5">-- / --</div>
        </div>
        <div class="w-full bg-white/10 h-2.5 rounded-full overflow-hidden shadow-inner mt-1"><div id="status-bar" class="bg-gradient-to-r from-[#00758F] to-cyan-500 h-full w-0 transition-all duration-700 ease-out"></div></div>
    `;

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
            if (msg) msg.textContent = d.message || 'データ処理を実行中...';

            if (projName && d.project_name) {
                projName.textContent = '📂 ' + d.project_name;
            }

            if (pages && d.total > 0) {
                pages.textContent = `${Math.ceil(d.current)} / ${d.total} 行`;
            }

            if (d.status === 'completed') {
                clearInterval(timer);
                statusOverlay.classList.replace('bg-slate-900', 'bg-emerald-900');
                if (msg) msg.innerHTML = '<span class="text-emerald-400 font-black">✨ 同期完了！スプレッドシートおよびRAGインデックスを再構成します。</span>';
                
                // ✨【修正ポイント1】ただのリロードではなく、現在の案件IDとCSVタブをガチッとホールドして安全着地
                const { projectId } = getConfig();
                setTimeout(() => {
                    location.href = `?project_id=${projectId}&tab=csv`;
                }, 2000);
            }

            if (d.status === 'error') {
                clearInterval(timer);
                if (msg) msg.innerHTML = `<span class="text-red-400 font-bold">Error: ${d.error || '同期失敗'}</span>`;
                statusOverlay.classList.replace('bg-slate-900', 'bg-red-950');
                setTimeout(() => statusOverlay.remove(), 10000);
            }
        } catch (e) { clearInterval(timer); }
    }, 1500);

    if (isNew && payloadData) {
        (async () => {
            try {
                const isForm = (payloadData instanceof FormData);
                const fetchOptions = {
                    method: 'POST',
                    body: isForm ? payloadData : JSON.stringify(payloadData),
                    headers: isForm ? {} : { 'Content-Type': 'application/json' }
                };

                const data = await secureFetch(apiUrl, fetchOptions);
                clearInterval(timer);

                if (data.success) {
                    const msg = document.getElementById('status-message');
                    if (msg) msg.innerHTML = '<span class="text-emerald-400 font-black">✨ 同期完了！まもなく自動リロードします</span>';
                    if (document.getElementById('status-bar')) document.getElementById('status-bar').style.width = '100%';
                    
                    // ✨【修正ポイント2】非同期の直受け成功時も、案件IDを絶対に手放さないURLへ安全着地
                    const { projectId } = getConfig();
                    setTimeout(() => {
                        location.href = `?project_id=${projectId}&tab=csv`;
                    }, 2000);
                } else {
                    const msg = document.getElementById('status-message');
                    if (msg) msg.innerHTML = `<span class="text-red-400 font-bold">❌ 失敗: ${data.error || '不明なエラー'}</span>`;
                    setTimeout(() => statusOverlay.remove(), 10000);
                }
            } catch (err) {
                clearInterval(timer);
                const msg = document.getElementById('status-message');
                if (msg) msg.innerHTML = `<span class="text-red-400 font-bold">❌ 通信障害: ${err.message}</span>`;
                setTimeout(() => statusOverlay.remove(), 10000);
            }
        })();
    }
}

/**
 * D. 指定されたCSVファイルのデータグリッドプレビュー描画 (監査クリア版)
 */
async function loadCsvData(csvFileId, fileName) {
    const container = document.getElementById('csv-viewer-container');
    if (!container) return;

    container.innerHTML = `
        <div class="flex items-center justify-center p-12 bg-white border border-dashed rounded-xl animate-pulse text-xs text-gray-400 font-bold">
            📊 ${escapeHTML(fileName)} を読み込み中...
        </div>
    `;

    document.querySelectorAll('[id^="csv-item-"]').forEach(el => el.classList.remove('border-[#00758F]', 'bg-[#00758F]/5'));
    const activeItem = document.getElementById(`csv-item-${csvFileId}`);
    if (activeItem) {
        activeItem.classList.add('border-[#00758F]', 'bg-[#00758F]/5');
    }

    try {
        const data = await secureFetch(`api/get_csv_data.php?csv_file_id=${csvFileId}`, { method: 'GET' });

        if (data && data.success) {
            const headers = data.headers;
            const rows = data.rows;

            if (headers.length === 0) {
                container.innerHTML = `<p class="text-xs text-gray-400 text-center py-10 bg-white border rounded-xl italic">表示可能なカラムがありませんでした。</p>`;
                return;
            }

            let tableHtml = `
                <div class="bg-white border rounded-xl shadow-sm overflow-hidden animate-fadeIn flex flex-col">
                    <div class="bg-teal-50/50 px-4 py-2 border-b flex justify-between items-center text-xs flex-shrink-0">
                        <span class="font-bold text-[#00758F]">📄 ${escapeHTML(fileName)} (${rows.length}行の構造化データ)</span>
                        <button onclick="handleDeleteCsv(${csvFileId})" class="text-red-500 hover:text-red-700 font-bold hover:underline">🗑️ CSVを全削除</button>
                    </div>
                    <div class="overflow-x-auto overflow-y-auto max-h-[calc(100vh-290px)] md:max-h-[calc(100vh-250px)] custom-scrollbar">
                        <table class="w-full text-[10px] text-left border-collapse">
                            <thead>
                                <tr class="bg-slate-100 text-slate-500 sticky top-0 border-b z-10 font-bold">
                                    <th class="p-2.5 border-r text-center w-12 bg-slate-100 sticky left-0 z-20 shadow-[1px_0_0_0_#cbd5e1]">No.</th>
                                    ${headers.map(h => `<th class="p-2.5 border-r bg-slate-100 whitespace-nowrap">${escapeHTML(h)}</th>`).join('')}
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100 font-mono">
                                ${rows.map((row, idx) => `
                                    <tr class="hover:bg-slate-100/70 odd:bg-slate-50/40 transition-colors">
                                        <td class="p-2 border-r text-center text-slate-400 bg-slate-50/50 sticky left-0 z-10 shadow-[1px_0_0_0_#e2e8f0]">${idx + 1}</td>
                                        ${headers.map(h => `<td class="p-2 border-r text-slate-700 whitespace-nowrap">${escapeHTML(row[h] !== null && row[h] !== undefined ? String(row[h]) : '')}</td>`).join('')}
                                    </tr>
                                `).join('')}
                            </tbody>
                        </table>
                    </div>
                </div>
            `;
            container.innerHTML = tableHtml;
        } else {
            container.innerHTML = `<p class="text-xs text-red-500 text-center py-10 bg-white border rounded-xl">データの読み込みに失敗しました: ${escapeHTML(data.error || '不明なエラー')}</p>`;
        }
    } catch (err) {
        container.innerHTML = `<p class="text-xs text-red-500 text-center py-10 bg-white border rounded-xl">通信エラーが発生しました: ${escapeHTML(err.message)}</p>`;
    }
}

/**
 * E. CSVファイルと関連RAGベクトルインデックスの完全削除 (監査クリア版)
 */
async function handleDeleteCsv(csvFileId) {
    if (!csvFileId || !confirm('このCSVデータテーブルと、連動するAIチャット用RAGインデックスデータを完全に削除しますか？')) return;

    try {
        const response = await secureFetch('api/delete_csv.php', {
            method: 'POST',
            body: JSON.stringify({ csv_file_id: csvFileId })
        });
        
        if (response && response.success) {
            const itemEl = document.getElementById(`csv-item-${csvFileId}`);
            if (itemEl) {
                itemEl.style.transition = 'all 0.3s ease-out';
                itemEl.style.opacity = '0';
                itemEl.style.transform = 'translateY(-10px)';
                setTimeout(() => {
                    itemEl.remove();
                    
                    const listContainer = document.querySelector('[id^="csv-item-"]');
                    if (!listContainer) {
                        const sidebarList = document.querySelector('#tab-csv .space-y-2');
                        if (sidebarList) {
                            sidebarList.innerHTML = '<p class="text-[10px] text-slate-400 text-center py-8 italic">登録済みのCSVはありません。</p>';
                        }
                    }
                }, 300);
            }

            const container = document.getElementById('csv-viewer-container');
            if (container) {
                container.innerHTML = `
                    <div class="text-center py-20 bg-white rounded-2xl border border-dashed border-slate-300 shadow-sm h-full flex flex-col justify-center items-center">
                        <p class="text-4xl mb-3 opacity-25">📊</p>
                        <p class="text-xs text-slate-500 font-bold leading-relaxed">CSVファイルが削除されました。<br>左側のインポート一覧から別のCSVファイルを選択してください。</p>
                    </div>
                `;
            }

            updateCsvBadge(-1);

        } else {
            alert('削除失敗: ' + (response.error || '不明なエラーが発生しました。'));
        }
    } catch (err) {
        alert('通信エラーが発生しました: ' + err.message);
    }
}

/**
 * F. AIチャットの「CSVバッジ」等から呼ばれる相互ジャンプ同期関数 (監査クリア版)
 */
async function openCsvPreviewByDocId(docId, cleanTitle) {
    try {
        typeof chatLogger === 'function' 
            ? chatLogger(`[RAG-SYNC] チャットバッジクリックを検知。親資料ID: ${docId} から csv_file_id を特定します...`)
            : console.log(`[RAG-SYNC] チャットバッジクリックを検知。親資料ID: ${docId} から csv_file_id を特定します...`);
        
        const res = await secureFetch(`api/get_csv_file_id.php?doc_id=${docId}`, { method: 'GET' });
        
        if (res && res.success && res.csv_file_id) {
            typeof chatLogger === 'function'
                ? chatLogger(`[RAG-SYNC] csv_file_id 特定成功: ${res.csv_file_id}。タブを切り替えてスプレッドシートを展開します。`)
                : console.log(`[RAG-SYNC] csv_file_id 特定成功: ${res.csv_file_id}。タブを切り替えてスプレッドシートを展開します。`);
            
            if (typeof window.switchTab === 'function') {
                window.switchTab('tab-csv');
            }
            
            await loadCsvData(res.csv_file_id, cleanTitle);
        } else {
            typeof chatLogger === 'function'
                ? chatLogger(`[RAG-SYNC] 物理CSVの逆算特定に失敗しました：${res?.error || '未定義'}`)
                : console.log(`[RAG-SYNC] 物理CSVの逆算特定に失敗しました：${res?.error || '未定義'}`);
            alert('参照元の実体CSVファイルが削除されているか、同期データが見つかりませんでした。');
        }
    } catch (err) {
        alert('CSVプレビュー同期中に通信障害が発生しました：' + err.message);
    }
}

// =========================================================================
// ★[究極の安全設計] グローバルへの確実なバインドとPDFバッジフック処理
// =========================================================================
(function initGlobalCsvBindings() {
    window.handleCsvUpload = handleCsvUpload;
    window.loadCsvData = loadCsvData;
    window.handleDeleteCsv = handleDeleteCsv;
    window.handlePostgresImport = handlePostgresImport;
    window.openCsvPreviewByDocId = openCsvPreviewByDocId;

    const originalOpenPdfTab = window.openPdfTab;
    window.openPdfTab = function(docId, title, page) {
        if (title && title.includes('[CSVデータ]')) {
            const cleanTitle = title.replace('[CSVデータ] ', '');
            openCsvPreviewByDocId(docId, cleanTitle);
        } else if (originalOpenPdfTab) {
            originalOpenPdfTab(docId, title, page);
        }
    };
})();

export {
    handleCsvUpload,
    handlePostgresImport,
    startStructuredImportTracking,
    loadCsvData,
    handleDeleteCsv,
    openCsvPreviewByDocId
};