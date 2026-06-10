/**
 * csv.js - CSVデータのアップロード、プレビュー、削除、およびリモートPostgreSQLデータ抽出インポート制御モジュール
 * (support.js からインポートされてグローバル空間にバインドされるモジュールファイルです)
 */
import { secureFetch, getConfig } from './api.js?v=5';

let selectedCsvContext = null;
let csvColumnDraftHeaders = [];
let activeCsvAiJobTimer = null;
let activeCsvAiJobId = null;
let activeCsvAiJobRequestController = null;
let csvAiLifecycleBound = false;
let csvAiJobPausedByVisibility = false;
let csvAiJobHistoryRequestSeq = 0;

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

function setCsvHistoryCount(count) {
    const countEl = document.getElementById('csv-history-count');
    if (countEl) {
        countEl.textContent = String(Math.max(0, count));
    }
}

function buildCsvHistoryItemHtml(csvFile) {
    const id = Number(csvFile.id || 0);
    const fileName = String(csvFile.file_name || '');
    const rowCount = Number(csvFile.row_count || 0);
    const createdAt = String(csvFile.created_at || '');
    const displayDate = createdAt ? new Date(createdAt.replace(/-/g, '/')) : null;
    const formattedDate = displayDate && !Number.isNaN(displayDate.getTime())
        ? `${String(displayDate.getMonth() + 1).padStart(2, '0')}/${String(displayDate.getDate()).padStart(2, '0')} ${String(displayDate.getHours()).padStart(2, '0')}:${String(displayDate.getMinutes()).padStart(2, '0')}`
        : '--/-- --:--';
    const escapedName = escapeHTML(fileName);
    const jsSafeName = fileName.replace(/\\/g, '\\\\').replace(/'/g, "\\'");

    return `
        <div id="csv-item-${id}" onclick="if(typeof window.loadCsvData === 'function') window.loadCsvData(${id}, '${jsSafeName}')" class="p-3 bg-white border border-slate-200 rounded-xl hover:border-[#00758F] hover:shadow-md cursor-pointer shadow-2xs transition-all duration-200 ease-in-out group transform hover:-translate-y-0.5 active:scale-98">
            <div class="text-xs font-bold text-slate-700 truncate group-hover:text-[#00758F] transition-colors duration-150 mb-1.5" title="📄 ${escapedName}">📄 ${escapedName}</div>
            <div class="flex justify-between items-center text-[9px] text-slate-400 font-medium">
                <span class="font-mono bg-slate-50 px-1.5 py-0.5 rounded border border-slate-100 font-bold">${rowCount.toLocaleString()} rows</span>
                <span>${formattedDate}</span>
            </div>
        </div>
    `;
}

function ensureCsvHistoryHasContent() {
    const list = document.getElementById('csv-history-list');
    if (!list) return;
    if (list.querySelector('[id^="csv-item-"]')) return;
    list.innerHTML = '<p class="text-[10px] text-slate-400 text-center py-8 italic font-medium">登録済みのCSVはありません。</p>';
}

function setCsvAiJobCount(count) {
    const countEl = document.getElementById('csv-ai-job-count');
    if (countEl) {
        countEl.textContent = `${Math.max(0, count)} 件`;
    }
}

function formatCsvAiJobTime(isoString) {
    if (!isoString) return '--/-- --:--';
    const date = new Date(String(isoString).replace(/-/g, '/'));
    if (Number.isNaN(date.getTime())) return '--/-- --:--';
    return `${String(date.getMonth() + 1).padStart(2, '0')}/${String(date.getDate()).padStart(2, '0')} ${String(date.getHours()).padStart(2, '0')}:${String(date.getMinutes()).padStart(2, '0')}`;
}

function getCsvAiJobStatusStyle(status) {
    switch (status) {
        case 'completed':
            return 'text-emerald-700 bg-emerald-50 border-emerald-200';
        case 'canceled':
            return 'text-amber-700 bg-amber-50 border-amber-200';
        case 'error':
            return 'text-red-700 bg-red-50 border-red-200';
        case 'processing':
            return 'text-cyan-700 bg-cyan-50 border-cyan-200';
        default:
            return 'text-slate-600 bg-slate-100 border-slate-200';
    }
}

function getCsvAiJobStatusLabel(status) {
    switch (status) {
        case 'completed':
            return '完了';
        case 'canceled':
            return '停止';
        case 'error':
            return '失敗';
        case 'processing':
            return '実行中';
        default:
            return '待機中';
    }
}

function buildCsvAiJobItemHtml(item) {
    const job = item?.job || {};
    const status = item?.status || {};
    const jobId = String(job.job_id || '');
    const analysisMode = String(job.analysis_mode || 'categorize');
    const sourceFileName = escapeHTML(String(job.source_file_name || 'CSV'));
    const targetColumn = escapeHTML(String(job.target_column || ''));
    const outputFileName = escapeHTML(String(job.output_file_name || ''));
    const rawOutputFileName = String(job.output_file_name || '');
    const outputCsvFileId = Number(status.output_csv_file_id || job.output_csv_file_id || 0);
    const state = String(status.status || job.status || 'pending');
    const progress = Math.max(0, Math.min(100, Number(status.progress || 0)));
    const current = Number(status.current || 0);
    const total = Number(status.total || 0);
    const message = escapeHTML(String(status.message || ''));
    const createdAt = formatCsvAiJobTime(job.created_at || '');
    const statusClass = getCsvAiJobStatusStyle(state);
    const statusLabel = getCsvAiJobStatusLabel(state);
    const canCancel = state === 'pending' || state === 'processing';
    const modeLabel = analysisMode === 'summarize' ? '行要約' : 'カテゴリ分け';

    return `
        <div id="csv-ai-job-${escapeHTML(jobId)}" class="p-3 bg-white border border-slate-200 rounded-xl shadow-2xs space-y-2">
            <div class="flex items-start justify-between gap-2">
                <div class="min-w-0">
                    <div class="text-[10px] font-bold text-slate-700 truncate" title="${sourceFileName}">${sourceFileName}</div>
                    <div class="text-[9px] text-slate-400 mt-0.5 truncate" title="${outputFileName}">→ ${outputFileName || '結果CSVを作成中'}</div>
                </div>
                <span class="text-[9px] font-bold border rounded-full px-2 py-0.5 whitespace-nowrap ${statusClass}">${statusLabel}</span>
            </div>
            <div class="flex items-center justify-between gap-2 text-[9px] text-slate-500 font-medium">
                <span>対象列: ${targetColumn || '未指定'}</span>
                <span class="text-[9px] font-bold text-slate-500 bg-slate-100 border border-slate-200 rounded-full px-2 py-0.5 whitespace-nowrap">${modeLabel}</span>
            </div>
            <div class="w-full bg-slate-100 h-1.5 rounded-full overflow-hidden">
                <div class="bg-gradient-to-r from-cyan-500 to-teal-400 h-full transition-all duration-300" style="width:${progress}%"></div>
            </div>
            <div class="flex items-center justify-between gap-2 text-[9px] text-slate-400 font-medium">
                <span class="truncate" title="${message}">${message || 'ジョブを準備しています。'}</span>
                <span class="font-mono whitespace-nowrap">${current}/${total || '--'}</span>
            </div>
            <div class="flex items-center justify-between gap-2">
                <div class="text-[9px] text-slate-400 font-mono">${createdAt}</div>
                <div class="flex items-center gap-2">
                    ${outputCsvFileId > 0 ? `<button type="button" onclick="window.openCsvAiJobResult && window.openCsvAiJobResult(${outputCsvFileId}, '${rawOutputFileName.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}')" class="text-[9px] font-bold text-cyan-700 bg-cyan-50 border border-cyan-200 rounded-full px-2 py-0.5 hover:bg-cyan-100 transition-all">結果を開く</button>` : ''}
                    ${canCancel ? `<button type="button" onclick="window.handleCancelCsvAiJob && window.handleCancelCsvAiJob('${escapeHTML(jobId)}')" class="text-[9px] font-bold text-amber-700 bg-amber-50 border border-amber-200 rounded-full px-2 py-0.5 hover:bg-amber-100 transition-all">キャンセル</button>` : ''}
                </div>
            </div>
        </div>
    `;
}

function renderCsvAiJobHistory(items) {
    const list = document.getElementById('csv-ai-job-list');
    if (!list) return;

    const normalized = Array.isArray(items) ? items : [];
    setCsvAiJobCount(normalized.length);

    if (normalized.length === 0) {
        list.innerHTML = '<p class="text-[10px] text-slate-400 text-center py-4 italic font-medium">AI行解析ジョブはまだありません。</p>';
        return;
    }

    list.innerHTML = normalized.map((item) => buildCsvAiJobItemHtml(item)).join('');
}

async function loadCsvAiJobHistory() {
    const list = document.getElementById('csv-ai-job-list');
    if (!list) return;

    const { projectId } = getConfig();
    if (!projectId) {
        renderCsvAiJobHistory([]);
        return;
    }

    const requestSeq = ++csvAiJobHistoryRequestSeq;
    const data = await secureFetch(`api/get_csv_ai_job_list.php?project_id=${encodeURIComponent(projectId)}&limit=8&_=${Date.now()}`, { method: 'GET' });
    if (requestSeq !== csvAiJobHistoryRequestSeq) return;
    if (!data?.success) return;
    renderCsvAiJobHistory(data.items || []);
}

function prependCsvHistoryItem(csvFile) {
    const list = document.getElementById('csv-history-list');
    if (!list) return;
    const empty = list.querySelector('p');
    if (empty) empty.remove();
    list.insertAdjacentHTML('afterbegin', buildCsvHistoryItemHtml(csvFile));
}

function setSelectedCsvContext(context) {
    selectedCsvContext = context;
    renderCsvAppendFields();
    renderCsvAiCategorizeForm();
    renderCsvColumnEditForm();
}

function renderCsvAppendFields() {
    const label = document.getElementById('modal-csv-selected-label');
    const badge = document.getElementById('modal-csv-selected-badge');
    const fields = document.getElementById('modal-csv-row-append-fields');
    const hiddenInput = document.querySelector('#csv-row-append-form input[name="csv_file_id"]');
    const submitBtn = document.getElementById('modal-csv-row-append-submit');

    if (!label || !badge || !fields || !hiddenInput || !submitBtn) return;

    if (!selectedCsvContext || !Array.isArray(selectedCsvContext.headers) || selectedCsvContext.headers.length === 0) {
        label.textContent = '左の一覧から CSV を選ぶと、ここに追記フォームが出ます。';
        badge.textContent = '未選択';
        badge.className = 'text-[9px] text-slate-500 bg-slate-100 border border-slate-200 rounded-full px-2 py-0.5 font-bold';
        hiddenInput.value = '';
        submitBtn.disabled = true;
        submitBtn.className = 'bg-slate-300 text-white px-4 py-2 rounded-xl font-bold text-[11px] shadow-sm cursor-not-allowed';
        fields.innerHTML = `
            <div class="text-[10px] text-slate-400 italic bg-slate-50 border border-dashed border-slate-200 rounded-xl px-3 py-4 text-center">
                追記先の CSV を選択してください。
            </div>
        `;
        return;
    }

    label.textContent = `現在の追記先: ${selectedCsvContext.fileName}`;
    badge.textContent = `${selectedCsvContext.headers.length} 列`;
    badge.className = 'text-[9px] text-[#00758F] bg-teal-50 border border-teal-200 rounded-full px-2 py-0.5 font-bold';
    hiddenInput.value = String(selectedCsvContext.id);
    submitBtn.disabled = false;
    submitBtn.className = 'bg-[#00758F] hover:bg-[#005a6e] text-white px-4 py-2 rounded-xl font-bold text-[11px] shadow-md transition-all duration-200 ease-in-out transform active:scale-95';
    fields.innerHTML = selectedCsvContext.headers.map((header) => `
        <label class="block space-y-1.5 rounded-xl border border-slate-200/80 bg-slate-50/40 px-3 py-3">
            <span class="block text-[10px] font-bold text-slate-500">${escapeHTML(header)}</span>
            <input type="text" data-header="${escapeHTML(header)}" class="w-full border border-slate-200 rounded-xl px-3 py-2.5 text-xs bg-white focus:bg-white focus:border-[#00758F] outline-none transition-all" placeholder="${escapeHTML(header)}">
        </label>
    `).join('');
}

function closeCsvCreateModal() {
    const modal = document.getElementById('csv-create-modal');
    if (!modal) return;
    modal.classList.replace('flex', 'hidden');
    const form = document.getElementById('csv-manual-create-form');
    form?.reset();
}

function openCsvCreateModal() {
    const modal = document.getElementById('csv-create-modal');
    if (!modal) {
        console.warn('csv-create-modal not found');
        alert('CSV作成モーダルが見つかりません。画面を再読み込みしてください。');
        return;
    }
    modal.classList.replace('hidden', 'flex');
}

function closeCsvAppendModal() {
    const modal = document.getElementById('csv-row-append-modal');
    if (!modal) return;
    modal.classList.replace('flex', 'hidden');
}

function closeCsvAiCategorizeModal() {
    const modal = document.getElementById('csv-ai-categorize-modal');
    if (!modal) return;
    modal.classList.replace('flex', 'hidden');
    const submitBtn = document.getElementById('modal-csv-ai-submit');
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.textContent = '非同期で開始する';
        submitBtn.className = selectedCsvContext && Array.isArray(selectedCsvContext.headers) && selectedCsvContext.headers.length > 0
            ? 'px-7 py-2 bg-[#00758F] text-white rounded-xl font-bold shadow-2xs hover:shadow-md hover:bg-[#005a6e] transition-all duration-200 ease-in-out transform active:scale-98'
            : 'px-7 py-2 bg-slate-300 text-white rounded-xl font-bold shadow-sm cursor-not-allowed';
    }
    updateCsvAiCategorizeModeUi();
}

function closeCsvColumnEditModal() {
    const modal = document.getElementById('csv-column-edit-modal');
    if (!modal) return;
    modal.classList.replace('flex', 'hidden');
    csvColumnDraftHeaders = [];
    const input = document.getElementById('modal-csv-new-column-name');
    if (input) {
        input.value = '';
    }
}

function renderCsvColumnDraftList() {
    const list = document.getElementById('modal-csv-column-edit-list');
    if (!list) return;

    if (!selectedCsvContext) {
        list.innerHTML = `
            <div class="text-[10px] text-slate-400 italic bg-white border border-dashed border-slate-200 rounded-xl px-3 py-4 text-center">
                編集対象の CSV を選択してください。
            </div>
        `;
        return;
    }

    if (csvColumnDraftHeaders.length === 0) {
        list.innerHTML = `
            <div class="text-[10px] text-slate-400 italic bg-white border border-dashed border-slate-200 rounded-xl px-3 py-4 text-center">
                少なくとも1列は残してください。
            </div>
        `;
        return;
    }

    list.innerHTML = csvColumnDraftHeaders.map((header, index) => `
        <div class="flex items-center justify-between gap-3 rounded-xl border border-slate-200 bg-white px-3 py-2">
            <span class="text-[11px] font-bold text-slate-700 break-all">${escapeHTML(header)}</span>
            <button type="button" onclick="window.handleRemoveCsvColumnDraft && window.handleRemoveCsvColumnDraft(${index})" class="text-[10px] font-bold text-red-500 hover:text-red-700 hover:underline whitespace-nowrap">削除</button>
        </div>
    `).join('');
}

function renderCsvColumnEditForm() {
    const label = document.getElementById('modal-csv-column-edit-label');
    const badge = document.getElementById('modal-csv-column-edit-badge');
    const hiddenInput = document.querySelector('#csv-column-edit-form input[name="csv_file_id"]');
    const submitBtn = document.getElementById('modal-csv-column-edit-submit');

    if (!label || !badge || !hiddenInput || !submitBtn) return;

    if (!selectedCsvContext || !Array.isArray(selectedCsvContext.headers) || selectedCsvContext.headers.length === 0) {
        label.textContent = '左の一覧から CSV を選ぶと、ここで列を編集できます。';
        badge.textContent = '未選択';
        badge.className = 'text-[9px] text-slate-500 bg-slate-100 border border-slate-200 rounded-full px-2 py-0.5 font-bold';
        hiddenInput.value = '';
        csvColumnDraftHeaders = [];
        submitBtn.disabled = true;
        submitBtn.className = 'px-7 py-2 bg-slate-300 text-white rounded-xl font-bold shadow-sm cursor-not-allowed';
        renderCsvColumnDraftList();
        return;
    }

    if (!Array.isArray(csvColumnDraftHeaders) || csvColumnDraftHeaders.length === 0) {
        csvColumnDraftHeaders = [...selectedCsvContext.headers];
    }

    label.textContent = `現在の対象: ${selectedCsvContext.fileName}`;
    badge.textContent = `${csvColumnDraftHeaders.length} 列`;
    badge.className = 'text-[9px] text-[#00758F] bg-teal-50 border border-teal-200 rounded-full px-2 py-0.5 font-bold';
    hiddenInput.value = String(selectedCsvContext.id);
    submitBtn.disabled = csvColumnDraftHeaders.length === 0;
    submitBtn.className = csvColumnDraftHeaders.length > 0
        ? 'px-7 py-2 bg-[#00758F] text-white rounded-xl font-bold shadow-2xs hover:shadow-md hover:bg-[#005a6e] transition-all duration-200 ease-in-out transform active:scale-98'
        : 'px-7 py-2 bg-slate-300 text-white rounded-xl font-bold shadow-sm cursor-not-allowed';
    renderCsvColumnDraftList();
}

function renderCsvAiCategorizeForm() {
    const label = document.getElementById('modal-csv-ai-selected-label');
    const badge = document.getElementById('modal-csv-ai-selected-badge');
    const modeSelect = document.getElementById('modal-csv-ai-analysis-mode');
    const select = document.getElementById('modal-csv-ai-target-column');
    const hiddenInput = document.querySelector('#csv-ai-categorize-form input[name="csv_file_id"]');
    const outputFileName = document.getElementById('modal-csv-ai-output-file-name');
    const submitBtn = document.getElementById('modal-csv-ai-submit');

    if (!label || !badge || !modeSelect || !select || !hiddenInput || !outputFileName || !submitBtn) return;

    if (!selectedCsvContext || !Array.isArray(selectedCsvContext.headers) || selectedCsvContext.headers.length === 0) {
        label.textContent = '左の一覧から解析したい CSV を選択してください。';
        badge.textContent = '未選択';
        badge.className = 'text-[9px] text-slate-500 bg-slate-100 border border-slate-200 rounded-full px-2 py-0.5 font-bold';
        hiddenInput.value = '';
        select.innerHTML = '<option value="">列を選択してください</option>';
        outputFileName.value = '';
        submitBtn.disabled = true;
        submitBtn.className = 'px-7 py-2 bg-slate-300 text-white rounded-xl font-bold shadow-sm cursor-not-allowed';
        return;
    }

    const sourceName = String(selectedCsvContext.fileName || 'ai_analyze_target.csv');

    label.textContent = `現在の対象: ${sourceName}`;
    badge.textContent = `${selectedCsvContext.headers.length} 列`;
    badge.className = 'text-[9px] text-[#00758F] bg-teal-50 border border-teal-200 rounded-full px-2 py-0.5 font-bold';
    hiddenInput.value = String(selectedCsvContext.id);
    select.innerHTML = `
        <option value="">列を選択してください</option>
        ${selectedCsvContext.headers.map((header) => `<option value="${escapeHTML(header)}">${escapeHTML(header)}</option>`).join('')}
    `;
    updateCsvAiCategorizeModeUi();
    submitBtn.disabled = false;
    submitBtn.className = 'px-7 py-2 bg-[#00758F] text-white rounded-xl font-bold shadow-2xs hover:shadow-md hover:bg-[#005a6e] transition-all duration-200 ease-in-out transform active:scale-98';
}

function updateCsvAiCategorizeModeUi() {
    const modeSelect = document.getElementById('modal-csv-ai-analysis-mode');
    const outputFileName = document.getElementById('modal-csv-ai-output-file-name');
    const categorizeColumns = document.getElementById('modal-csv-ai-categorize-columns');
    const summaryColumn = document.getElementById('modal-csv-ai-summary-column');
    const instructionsLabel = document.getElementById('modal-csv-ai-instructions-label');
    const instructionsField = document.getElementById('modal-csv-ai-instructions');
    const helpText = document.getElementById('modal-csv-ai-help-text');
    const title = document.getElementById('modal-title-csv-ai-categorize');
    if (!modeSelect || !outputFileName || !categorizeColumns || !summaryColumn || !instructionsLabel || !instructionsField || !helpText || !title) return;

    const sourceName = String(selectedCsvContext?.fileName || 'ai_analyze_target.csv');
    const baseName = sourceName.replace(/\.csv$/i, '');
    const mode = String(modeSelect.value || 'categorize');
    const currentValue = String(outputFileName.value || '').trim();
    const canReplaceDefault = !currentValue || /_ai_(categorized|summarized)\.csv$/i.test(currentValue);

    if (mode === 'summarize') {
        title.textContent = 'AI行要約CSVを作成';
        categorizeColumns.classList.add('hidden');
        summaryColumn.classList.remove('hidden');
        instructionsLabel.textContent = '要約ルール・補足指示';
        instructionsField.placeholder = '例: 1〜2文で、業務で使える短い要点を書いてください。曖昧な表現は避けてください。';
        helpText.textContent = '元のCSVは変更せず、各行の要約列を追加した新しい結果CSVを作成します。';
        if (canReplaceDefault) {
            outputFileName.value = `${baseName}_ai_summarized.csv`;
        }
        return;
    }

    title.textContent = 'AIカテゴリ分けCSVを作成';
    categorizeColumns.classList.remove('hidden');
    summaryColumn.classList.add('hidden');
    instructionsLabel.textContent = '分類ルール・補足指示';
    instructionsField.placeholder = '例: 製品名から「保守」「点検」「レポート」「その他」に分類してください。短い理由も添えてください。';
    helpText.textContent = '元のCSVは変更せず、新しい結果CSVを作成します。';
    if (canReplaceDefault) {
        outputFileName.value = `${baseName}_ai_categorized.csv`;
    }
}

function openCsvAiCategorizeModal() {
    if (!selectedCsvContext || !Array.isArray(selectedCsvContext.headers) || selectedCsvContext.headers.length === 0) {
        alert('先に左の一覧から解析対象のCSVを選択してください。');
        return;
    }

    renderCsvAiCategorizeForm();
    const modal = document.getElementById('csv-ai-categorize-modal');
    if (!modal) {
        console.warn('csv-ai-categorize-modal not found');
        alert('AI行解析モーダルが見つかりません。画面を再読み込みしてください。');
        return;
    }
    modal.classList.replace('hidden', 'flex');
}

function openCsvColumnEditModal() {
    if (!selectedCsvContext || !Array.isArray(selectedCsvContext.headers) || selectedCsvContext.headers.length === 0) {
        alert('先に左の一覧から編集対象のCSVを選択してください。');
        return;
    }

    csvColumnDraftHeaders = [...selectedCsvContext.headers];
    renderCsvColumnEditForm();
    const input = document.getElementById('modal-csv-new-column-name');
    if (input) {
        input.value = '';
    }
    const modal = document.getElementById('csv-column-edit-modal');
    if (!modal) {
        console.warn('csv-column-edit-modal not found');
        alert('CSV列編集モーダルが見つかりません。画面を再読み込みしてください。');
        return;
    }
    modal.classList.replace('hidden', 'flex');
}

function openCsvAppendModal() {
    if (!selectedCsvContext || !Array.isArray(selectedCsvContext.headers) || selectedCsvContext.headers.length === 0) {
        alert('先に左の一覧から追記先のCSVを選択してください。');
        return;
    }

    renderCsvAppendFields();
    const modal = document.getElementById('csv-row-append-modal');
    if (!modal) {
        console.warn('csv-row-append-modal not found');
        alert('CSV追記モーダルが見つかりません。画面を再読み込みしてください。');
        return;
    }
    modal.classList.replace('hidden', 'flex');
}

function handleAddCsvColumnDraft() {
    if (!selectedCsvContext) {
        alert('先に編集対象のCSVを選択してください。');
        return;
    }

    const input = document.getElementById('modal-csv-new-column-name');
    const rawValue = String(input?.value || '').trim();
    if (!rawValue) {
        alert('追加する列名を入力してください。');
        return;
    }
    if (csvColumnDraftHeaders.includes(rawValue)) {
        alert('同じ列名は追加できません。');
        return;
    }

    csvColumnDraftHeaders.push(rawValue);
    if (input) {
        input.value = '';
        input.focus();
    }
    renderCsvColumnEditForm();
}

function handleRemoveCsvColumnDraft(index) {
    if (!Array.isArray(csvColumnDraftHeaders)) return;
    csvColumnDraftHeaders = csvColumnDraftHeaders.filter((_, currentIndex) => currentIndex !== index);
    renderCsvColumnEditForm();
}

function bindCsvToolbarActions() {
    const bindings = [
        ['csv-create-trigger', openCsvCreateModal],
        ['csv-append-trigger', openCsvAppendModal],
    ];

    bindings.forEach(([id, handler]) => {
        const el = document.getElementById(id);
        if (!el || el.dataset.bound === 'true') return;
        el.dataset.bound = 'true';
        el.addEventListener('click', (event) => {
            event.preventDefault();
            handler();
        });
    });
}

function bindCsvAiModeControls() {
    const modeSelect = document.getElementById('modal-csv-ai-analysis-mode');
    if (!modeSelect || modeSelect.dataset.bound === 'true') return;
    modeSelect.dataset.bound = 'true';
    modeSelect.addEventListener('change', updateCsvAiCategorizeModeUi);
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
            const totalRowCount = Number(data.total_row_count || rows.length || 0);
            const displayedRowCount = Number(data.displayed_row_count || rows.length || 0);
            const previewLimit = Number(data.preview_limit || displayedRowCount || 0);
            const isPreviewLimited = Boolean(data.preview_limited);

    setSelectedCsvContext({
        id: csvFileId,
        fileName: data.file_name || fileName,
        headers,
    });
    renderCsvAiCategorizeForm();

    if (headers.length === 0) {
        container.innerHTML = `<p class="text-xs text-gray-400 text-center py-10 bg-white border rounded-xl italic">表示可能なカラムがありませんでした。</p>`;
        return;
    }

            let tableHtml = `
                <div class="bg-white border rounded-xl shadow-sm overflow-hidden animate-fadeIn flex flex-col h-full min-h-0">
                    <div class="bg-teal-50/50 px-4 py-2 border-b flex justify-between items-center text-xs flex-shrink-0">
                        <div class="flex flex-col gap-0.5">
                            <span class="font-bold text-[#00758F]">📄 ${escapeHTML(fileName)} (${displayedRowCount} / ${totalRowCount} 行を表示)</span>
                            ${isPreviewLimited ? `<span class="text-[10px] text-slate-500 font-medium">プレビューは先頭 ${previewLimit} レコードまで表示しています。</span>` : ''}
                        </div>
                        <div class="flex items-center gap-3">
                            <button type="button" onclick="window.openCsvAiCategorizeModal && window.openCsvAiCategorizeModal()" class="text-[#00758F] hover:text-[#005a6e] font-bold hover:underline">🤖 AI行解析</button>
                            <button type="button" onclick="window.openCsvColumnEditModal && window.openCsvColumnEditModal()" class="text-[#00758F] hover:text-[#005a6e] font-bold hover:underline">📝 編集</button>
                            <button type="button" onclick="handleDeleteCsv(${csvFileId})" class="text-red-500 hover:text-red-700 font-bold hover:underline">🗑️ CSVを全削除</button>
                        </div>
                    </div>
                    <div class="flex-1 min-h-0 overflow-x-auto overflow-y-auto custom-scrollbar">
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
                                        <td class="p-2 border-r text-center text-slate-400 bg-slate-50/50 sticky left-0 z-10 shadow-[1px_0_0_0_#e2e8f0]">${Number(row.__row_index || idx + 1)}</td>
                                        ${headers.map(h => `<td class="p-2 border-r text-slate-700 whitespace-nowrap">${escapeHTML(row.__row_data && row.__row_data[h] !== null && row.__row_data[h] !== undefined ? String(row.__row_data[h]) : '')}</td>`).join('')}
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
                        ensureCsvHistoryHasContent();
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

            if (selectedCsvContext && Number(selectedCsvContext.id) === Number(csvFileId)) {
                setSelectedCsvContext(null);
            }

            updateCsvBadge(-1);
            setCsvHistoryCount(document.querySelectorAll('[id^="csv-item-"]').length - 1);
            await loadCsvAiJobHistory();

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

async function handleCreateManualCsv(e) {
    e.preventDefault();
    const form = e.target;
    const { projectId } = getConfig();
    const fileName = String(form.file_name?.value || '').trim();
    const headersText = String(form.headers_text?.value || '').trim();
    const headers = headersText
        .split(/[\n,]/u)
        .map((item) => item.trim())
        .filter(Boolean);

    if (!projectId) {
        alert('案件情報が見つかりません。');
        return;
    }
    if (headers.length === 0) {
        alert('列名を1つ以上入力してください。');
        return;
    }

    try {
        const response = await secureFetch('api/create_csv_table.php', {
            method: 'POST',
            body: JSON.stringify({
                project_id: Number(projectId),
                file_name: fileName,
                headers,
            }),
        });

        if (!response?.success || !response.csv_file) {
            throw new Error(response?.error || 'CSV台帳の作成に失敗しました。');
        }

        prependCsvHistoryItem(response.csv_file);
        setCsvHistoryCount(document.querySelectorAll('[id^="csv-item-"]').length);
        updateCsvBadge(1);
        form.reset();
        closeCsvCreateModal();
        await loadCsvData(response.csv_file.id, response.csv_file.file_name);
    } catch (err) {
        alert(`CSV台帳の作成に失敗しました: ${err.message}`);
    }
}

async function handleAppendCsvRow(e) {
    e.preventDefault();
    const form = e.target;
    const { projectId } = getConfig();
    const csvFileId = Number(form.csv_file_id?.value || 0);

    if (!projectId || !csvFileId || !selectedCsvContext) {
        alert('追記先のCSVを選択してください。');
        return;
    }

    const rowData = {};
    form.querySelectorAll('[data-header]').forEach((input) => {
        const header = input.dataset.header || '';
        rowData[header] = input.value || '';
    });

    try {
        const response = await secureFetch('api/append_csv_row.php', {
            method: 'POST',
            body: JSON.stringify({
                project_id: Number(projectId),
                csv_file_id: csvFileId,
                row_data: rowData,
            }),
        });

        if (!response?.success) {
            throw new Error(response?.error || 'CSVへの追記に失敗しました。');
        }

        form.reset();
        closeCsvAppendModal();
        await loadCsvData(csvFileId, selectedCsvContext.fileName);
    } catch (err) {
        alert(`CSVへの追記に失敗しました: ${err.message}`);
    }
}

async function handleUpdateCsvColumns(e) {
    e.preventDefault();
    const form = e.target;
    const { projectId } = getConfig();
    const csvFileId = Number(form.csv_file_id?.value || 0);

    if (!projectId || !csvFileId || !selectedCsvContext) {
        alert('編集対象のCSVを選択してください。');
        return;
    }
    if (!Array.isArray(csvColumnDraftHeaders) || csvColumnDraftHeaders.length === 0) {
        alert('列を1つ以上残してください。');
        return;
    }

    const submitBtn = document.getElementById('modal-csv-column-edit-submit');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = '保存中...';
    }

    try {
        const response = await secureFetch('api/update_csv_columns.php', {
            method: 'POST',
            body: JSON.stringify({
                project_id: Number(projectId),
                csv_file_id: csvFileId,
                headers: csvColumnDraftHeaders,
            }),
        });

        if (!response?.success || !response.csv_file) {
            throw new Error(response?.error || 'CSV列の更新に失敗しました。');
        }

        selectedCsvContext = {
            id: response.csv_file.id,
            fileName: response.csv_file.file_name || selectedCsvContext.fileName,
            headers: Array.isArray(response.csv_file.headers) ? response.csv_file.headers : csvColumnDraftHeaders,
        };

        closeCsvColumnEditModal();
        await loadCsvData(response.csv_file.id, response.csv_file.file_name);
    } catch (err) {
        alert(`CSV列の更新に失敗しました: ${err.message}`);
        renderCsvColumnEditForm();
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = '保存する';
        }
    }
}

function ensureCsvAiJobOverlay() {
    let overlay = document.getElementById('csv-ai-job-overlay');
    if (overlay) return overlay;

    overlay = document.createElement('div');
    overlay.id = 'csv-ai-job-overlay';
    overlay.className = 'fixed bottom-6 right-6 bg-slate-900 text-white p-5 rounded-2xl shadow-2xl z-50 text-sm flex flex-col gap-3 min-w-[360px] max-w-[420px] animate-fadeIn border border-white/10 transition-all duration-500 opacity-100 pointer-events-none';
    document.body.appendChild(overlay);
    return overlay;
}

function renderCsvAiJobOverlay(status, job) {
    const overlay = ensureCsvAiJobOverlay();
    const progress = Math.max(0, Math.min(100, Number(status?.progress || 0)));
    const total = Number(status?.total || 0);
    const current = Number(status?.current || 0);
    const message = escapeHTML(status?.message || 'AI行解析ジョブを準備しています...');
    const sourceName = escapeHTML(job?.source_file_name || job?.source_csv_file_name || 'CSV');
    const state = String(status?.status || status?.state || job?.status || 'pending');
    const canCancel = state === 'pending' || state === 'processing';

    overlay.innerHTML = `
        <div class="flex justify-between items-start border-b border-white/10 pb-3">
            <div class="flex flex-col gap-1">
                <span class="text-[9px] text-cyan-400 uppercase tracking-widest font-black">AI Row Analysis</span>
                <span class="text-xs font-bold text-slate-200 truncate max-w-[220px]" title="${sourceName}">🤖 ${sourceName}</span>
            </div>
            <span class="text-2xl font-black text-cyan-400 font-mono">${progress}%</span>
        </div>
        <div class="mt-1 flex justify-between items-end gap-4">
            <div class="text-[11px] leading-snug flex items-center font-bold text-slate-100">${message}</div>
            <div class="text-[10px] text-slate-300 font-mono font-bold bg-white/10 px-2.5 py-0.5 rounded-full border border-white/5">${current} / ${total || '--'} 行</div>
        </div>
        <div class="w-full bg-white/10 h-2.5 rounded-full overflow-hidden shadow-inner mt-1"><div class="bg-gradient-to-r from-cyan-500 to-teal-400 h-full transition-all duration-700 ease-out" style="width:${progress}%"></div></div>
        ${canCancel && activeCsvAiJobId ? `<div class="flex justify-end mt-1 pointer-events-auto"><button type="button" onclick="window.handleCancelCsvAiJob && window.handleCancelCsvAiJob('${escapeHTML(activeCsvAiJobId)}')" class="text-[10px] font-bold text-amber-200 bg-white/10 border border-white/10 rounded-full px-3 py-1 hover:bg-white/15 transition-all">キャンセル</button></div>` : ''}
    `;

    return overlay;
}

function clearCsvAiJobTimer() {
    if (activeCsvAiJobTimer) {
        clearTimeout(activeCsvAiJobTimer);
        activeCsvAiJobTimer = null;
    }
}

function abortCsvAiJobRequest() {
    if (activeCsvAiJobRequestController) {
        activeCsvAiJobRequestController.abort();
        activeCsvAiJobRequestController = null;
    }
}

function stopCsvAiJobPolling({ resetJobId = false, removeOverlay = false } = {}) {
    clearCsvAiJobTimer();
    abortCsvAiJobRequest();
    if (resetJobId) {
        activeCsvAiJobId = null;
    }
    if (removeOverlay) {
        document.getElementById('csv-ai-job-overlay')?.remove();
    }
}

function bindCsvAiJobLifecycleGuards() {
    if (csvAiLifecycleBound) return;
    csvAiLifecycleBound = true;

    const stopForPageExit = () => {
        stopCsvAiJobPolling({ resetJobId: true, removeOverlay: true });
    };

    window.addEventListener('pagehide', stopForPageExit);
    window.addEventListener('beforeunload', stopForPageExit);
    document.addEventListener('visibilitychange', () => {
        if (document.visibilityState === 'hidden') {
            if (activeCsvAiJobId) {
                csvAiJobPausedByVisibility = true;
                stopCsvAiJobPolling({ resetJobId: false, removeOverlay: false });
            }
            return;
        }

        if (csvAiJobPausedByVisibility && activeCsvAiJobId && !activeCsvAiJobTimer) {
            csvAiJobPausedByVisibility = false;
            pollCsvAiJobStatus(activeCsvAiJobId).catch(() => {});
        }
    });
}

async function openCsvAiJobResult(csvFileId, fileName = '') {
    const id = Number(csvFileId || 0);
    if (!id) {
        alert('結果CSVがまだ作成されていません。');
        return;
    }

    if (typeof window.switchTab === 'function') {
        window.switchTab('tab-csv');
    }
    await loadCsvData(id, fileName || 'AI行解析結果.csv');
}

async function handleCancelCsvAiJob(jobId) {
    if (!jobId) return;
    if (!confirm('このAI行解析ジョブを停止しますか？進行中なら現在の行処理が終わり次第、止まっているジョブならその場で停止します。')) return;

    try {
        const response = await secureFetch('api/cancel_csv_ai_job.php', {
            method: 'POST',
            body: JSON.stringify({ job_id: jobId }),
        });

        if (!response?.success) {
            throw new Error(response?.error || 'キャンセル要求に失敗しました。');
        }

        const overlay = document.getElementById('csv-ai-job-overlay');
        if (overlay) {
            if (response.completed_now) {
                stopCsvAiJobPolling({ resetJobId: true });
                overlay.classList.replace('bg-slate-900', 'bg-amber-900');
                overlay.innerHTML = `
                    <div class="flex justify-between items-start border-b border-white/10 pb-3">
                        <div class="flex flex-col gap-1">
                            <span class="text-[9px] text-amber-200 uppercase tracking-widest font-black">AI Row Analysis</span>
                            <span class="text-xs font-bold text-white">⏹ ジョブを停止しました</span>
                        </div>
                    </div>
                    <div class="text-[11px] leading-snug font-bold text-white">${escapeHTML(response.message || 'ジョブを停止しました。')}</div>
                `;
                setTimeout(() => overlay.remove(), 5000);
            } else {
                overlay.classList.replace('bg-slate-900', 'bg-amber-900');
            }
        }

        if (!response.completed_now) {
            await loadCsvAiJobHistory();
        }
    } catch (err) {
        alert(`キャンセル要求に失敗しました: ${err.message}`);
    }
}

async function pollCsvAiJobStatus(jobId) {
    stopCsvAiJobPolling();
    activeCsvAiJobId = jobId;
    csvAiJobPausedByVisibility = false;
    renderCsvAiJobOverlay({ progress: 0, current: 0, total: 0, message: 'ジョブを開始しました。進捗を取得しています...' }, {});
    await loadCsvAiJobHistory();

    const pollOnce = async () => {
        if (activeCsvAiJobId !== jobId) return;

        let shouldScheduleNext = true;
        let timeoutId = null;
        try {
            activeCsvAiJobRequestController = new AbortController();
            timeoutId = setTimeout(() => {
                activeCsvAiJobRequestController?.abort();
            }, 8000);
            const data = await secureFetch(`api/get_csv_ai_job_status.php?job_id=${encodeURIComponent(jobId)}&_=${Date.now()}`, {
                method: 'GET',
                signal: activeCsvAiJobRequestController.signal,
            });
            if (timeoutId) {
                clearTimeout(timeoutId);
                timeoutId = null;
            }
            activeCsvAiJobRequestController = null;
            if (!data?.success || !data.status) {
                activeCsvAiJobTimer = setTimeout(pollOnce, 2000);
                return;
            }

            const overlay = renderCsvAiJobOverlay(data.status, data.job || {});
            const status = data.status;
            const job = data.job || {};
            const state = String(status.status || status.state || '');

            if (state === 'completed') {
                shouldScheduleNext = false;
                stopCsvAiJobPolling({ resetJobId: true });
                await loadCsvAiJobHistory();
                overlay.classList.replace('bg-slate-900', 'bg-emerald-900');
                const outputFileName = String(job.output_file_name || 'AI行解析結果.csv');
                const outputCsvFileId = Number(status.output_csv_file_id || 0);
                overlay.innerHTML = `
                    <div class="flex justify-between items-start border-b border-white/10 pb-3">
                        <div class="flex flex-col gap-1">
                            <span class="text-[9px] text-emerald-300 uppercase tracking-widest font-black">AI Row Analysis</span>
                            <span class="text-xs font-bold text-white truncate max-w-[220px]" title="${escapeHTML(outputFileName)}">✅ ${escapeHTML(outputFileName)}</span>
                        </div>
                        <span class="text-2xl font-black text-emerald-300 font-mono">100%</span>
                    </div>
                    <div class="text-[11px] leading-snug font-bold text-white">${state === 'completed' ? 'AI行解析CSVを作成しました。左の一覧へ追加して表示します。' : 'AI行解析の完了を確認しました。'}</div>
                    ${outputCsvFileId > 0 ? `<div class="flex justify-end pointer-events-auto"><button type="button" onclick="window.openCsvAiJobResult && window.openCsvAiJobResult(${outputCsvFileId}, '${outputFileName.replace(/\\/g, '\\\\').replace(/'/g, "\\'")}')" class="text-[10px] font-bold text-emerald-200 bg-white/10 border border-white/10 rounded-full px-3 py-1 hover:bg-white/15 transition-all">結果を開く</button></div>` : ''}
                `;

                if (outputCsvFileId > 0 && !document.getElementById(`csv-item-${outputCsvFileId}`)) {
                    prependCsvHistoryItem({
                        id: outputCsvFileId,
                        file_name: outputFileName,
                        row_count: Number(status.total || 0),
                        created_at: new Date().toISOString(),
                    });
                    setCsvHistoryCount(document.querySelectorAll('[id^="csv-item-"]').length);
                    updateCsvBadge(1);
                }

                if (outputCsvFileId > 0) {
                    await loadCsvData(outputCsvFileId, outputFileName);
                }

                setTimeout(() => overlay.remove(), 5000);
                return;
            }

            if (state === 'canceled') {
                shouldScheduleNext = false;
                stopCsvAiJobPolling({ resetJobId: true });
                overlay.classList.replace('bg-slate-900', 'bg-amber-900');
                overlay.innerHTML = `
                    <div class="flex justify-between items-start border-b border-white/10 pb-3">
                        <div class="flex flex-col gap-1">
                            <span class="text-[9px] text-amber-200 uppercase tracking-widest font-black">AI Row Analysis</span>
                            <span class="text-xs font-bold text-white">⏹ ジョブを停止しました</span>
                        </div>
                    </div>
                    <div class="text-[11px] leading-snug font-bold text-white">${escapeHTML(status.message || 'キャンセル要求により停止しました。')}</div>
                `;
                setTimeout(() => overlay.remove(), 6000);
                return;
            }

            if (state === 'error') {
                shouldScheduleNext = false;
                stopCsvAiJobPolling({ resetJobId: true });
                await loadCsvAiJobHistory();
                overlay.classList.replace('bg-slate-900', 'bg-red-950');
                overlay.innerHTML = `
                    <div class="flex justify-between items-start border-b border-white/10 pb-3">
                        <div class="flex flex-col gap-1">
                            <span class="text-[9px] text-red-300 uppercase tracking-widest font-black">AI Row Analysis</span>
                            <span class="text-xs font-bold text-white">❌ ジョブ失敗</span>
                        </div>
                    </div>
                    <div class="text-[11px] leading-snug font-bold text-white">${escapeHTML(status.error || status.message || 'カテゴリ分け処理に失敗しました。')}</div>
                `;
                setTimeout(() => overlay.remove(), 8000);
                return;
            }

            await loadCsvAiJobHistory();
        } catch (err) {
            if (timeoutId) {
                clearTimeout(timeoutId);
                timeoutId = null;
            }
            const requestWasTimedOut = err?.name === 'AbortError';
            activeCsvAiJobRequestController = null;
            if (requestWasTimedOut && !csvAiJobPausedByVisibility && activeCsvAiJobId === jobId) {
                const overlay = document.getElementById('csv-ai-job-overlay');
                if (overlay) {
                    overlay.classList.replace('bg-slate-900', 'bg-slate-800');
                    overlay.innerHTML = `
                        <div class="flex justify-between items-start border-b border-white/10 pb-3">
                            <div class="flex flex-col gap-1">
                                <span class="text-[9px] text-slate-300 uppercase tracking-widest font-black">AI Row Analysis</span>
                                <span class="text-xs font-bold text-white">⌛ 状態取得が遅いため再試行します</span>
                            </div>
                        </div>
                        <div class="text-[11px] leading-snug font-bold text-white">通信が一時的に遅延しています。しばらく待って再取得します。</div>
                    `;
                }
            } else if (err?.name === 'AbortError') {
                shouldScheduleNext = false;
                return;
            }

            const overlay = document.getElementById('csv-ai-job-overlay');
            if (overlay) {
                overlay.classList.replace('bg-slate-900', 'bg-slate-800');
                overlay.innerHTML = `
                    <div class="flex justify-between items-start border-b border-white/10 pb-3">
                        <div class="flex flex-col gap-1">
                            <span class="text-[9px] text-slate-300 uppercase tracking-widest font-black">AI Row Analysis</span>
                            <span class="text-xs font-bold text-white">⌛ 状態確認を再試行します</span>
                        </div>
                    </div>
                    <div class="text-[11px] leading-snug font-bold text-white">${escapeHTML(err.message || '状態取得に失敗しました。')}</div>
                `;
            }
        } finally {
            if (shouldScheduleNext && activeCsvAiJobId === jobId) {
                activeCsvAiJobTimer = setTimeout(pollOnce, 2000);
            }
        }
    };

    activeCsvAiJobTimer = setTimeout(pollOnce, 0);
}

async function handleStartCsvAiCategorizeJob(e) {
    e.preventDefault();
    const form = e.target;
    const { projectId } = getConfig();
    const csvFileId = Number(form.csv_file_id?.value || 0);
    const analysisMode = String(form.analysis_mode?.value || 'categorize').trim();
    const targetColumn = String(form.target_column?.value || '').trim();
    const outputFileName = String(form.output_file_name?.value || '').trim();

    if (!projectId || !csvFileId || !targetColumn || !outputFileName) {
        alert('対象CSV・対象列・出力CSV名を入力してください。');
        return;
    }

    const submitBtn = document.getElementById('modal-csv-ai-submit');
    if (submitBtn) {
        submitBtn.disabled = true;
        submitBtn.textContent = '起動中...';
    }

    const pendingSourceName = selectedCsvContext?.fileName || 'CSV';
    closeCsvAiCategorizeModal();
    renderCsvAiJobOverlay({
        progress: 0,
        current: 0,
        total: 0,
        message: analysisMode === 'summarize' ? 'AI要約ジョブの起動要求を送信しています...' : 'AI分類ジョブの起動要求を送信しています...',
        status: 'pending',
    }, {
        source_file_name: pendingSourceName,
        status: 'pending',
    });

    try {
        const response = await secureFetch('api/start_csv_ai_categorize_job.php', {
            method: 'POST',
            body: JSON.stringify({
                project_id: Number(projectId),
                csv_file_id: csvFileId,
                target_column: targetColumn,
                analysis_mode: analysisMode,
                output_file_name: outputFileName,
                category_column_name: String(form.category_column_name?.value || 'AIカテゴリ').trim(),
                reason_column_name: String(form.reason_column_name?.value || 'AI分類理由').trim(),
                summary_column_name: String(form.summary_column_name?.value || 'AI要約').trim(),
                instructions: String(form.instructions?.value || '').trim(),
            }),
        });

        if (!response?.success || !response.job_id) {
            throw new Error(response?.error || 'AI行解析ジョブの起動に失敗しました。');
        }

        await pollCsvAiJobStatus(response.job_id);
    } catch (err) {
        const overlay = document.getElementById('csv-ai-job-overlay');
        if (overlay) {
            overlay.remove();
        }
        alert(`AI行解析ジョブを開始できませんでした: ${err.message}`);
        openCsvAiCategorizeModal();
    } finally {
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.textContent = '非同期で開始する';
        }
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
    window.handleCreateManualCsv = handleCreateManualCsv;
    window.handleAppendCsvRow = handleAppendCsvRow;
    window.openCsvCreateModal = openCsvCreateModal;
    window.closeCsvCreateModal = closeCsvCreateModal;
    window.openCsvAppendModal = openCsvAppendModal;
    window.closeCsvAppendModal = closeCsvAppendModal;
    window.openCsvColumnEditModal = openCsvColumnEditModal;
    window.closeCsvColumnEditModal = closeCsvColumnEditModal;
    window.handleAddCsvColumnDraft = handleAddCsvColumnDraft;
    window.handleRemoveCsvColumnDraft = handleRemoveCsvColumnDraft;
    window.handleUpdateCsvColumns = handleUpdateCsvColumns;
    window.handleStartCsvAiCategorizeJob = handleStartCsvAiCategorizeJob;
    window.openCsvAiCategorizeModal = openCsvAiCategorizeModal;
    window.closeCsvAiCategorizeModal = closeCsvAiCategorizeModal;
    window.handleCancelCsvAiJob = handleCancelCsvAiJob;
    window.openCsvAiJobResult = openCsvAiJobResult;

    const originalOpenPdfTab = window.openPdfTab;
    window.openPdfTab = function(docId, title, page) {
        if (title && title.includes('[CSVデータ]')) {
            const cleanTitle = title.replace('[CSVデータ] ', '');
            openCsvPreviewByDocId(docId, cleanTitle);
        } else if (originalOpenPdfTab) {
            originalOpenPdfTab(docId, title, page);
        }
    };

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', () => {
            bindCsvAiJobLifecycleGuards();
            bindCsvToolbarActions();
            bindCsvAiModeControls();
            loadCsvAiJobHistory().catch(() => {});
        }, { once: true });
    } else {
        bindCsvAiJobLifecycleGuards();
        bindCsvToolbarActions();
        bindCsvAiModeControls();
        loadCsvAiJobHistory().catch(() => {});
    }
})();

export {
    handleCsvUpload,
    handlePostgresImport,
    startStructuredImportTracking,
    loadCsvData,
    handleDeleteCsv,
    openCsvPreviewByDocId,
    handleCreateManualCsv,
    handleAppendCsvRow,
    openCsvCreateModal,
    closeCsvCreateModal,
    openCsvAppendModal,
    closeCsvAppendModal,
    openCsvColumnEditModal,
    closeCsvColumnEditModal,
    handleAddCsvColumnDraft,
    handleRemoveCsvColumnDraft,
    handleUpdateCsvColumns,
    handleStartCsvAiCategorizeJob,
    openCsvAiCategorizeModal,
    closeCsvAiCategorizeModal,
    loadCsvAiJobHistory,
    handleCancelCsvAiJob,
    openCsvAiJobResult
};
