/**
 * chat.js - RAG対応 AIチャット送受信およびポーリング監視モジュール (SSEリアルタイムストリーム対応・完全同期版)
 * (support.js からインポートされてグローバル空間にバインドされるモジュールファイルです)
 * * ★[進行ステータス整理版]
 * 1. 進行ステータスは入力欄上の小さなバーで表示。
 * 2. statusパケットの詳細は内部で保持し、完了後に折りたたみログとして確認可能。
 * 3. chunkおよびresultパケット受信時、回答本文を読みやすいMarkdownとして描画。
 */
import { secureFetch, getConfig } from './api.js?v=4';
import { scrollToBottom } from './ui.js?v=4';
import { AiRenderer } from './aiRenderer.js?v=6';

// 生成された Chart.js のインスタンスを保持し、メモリリークや二重描画を防ぐグローバル管理マップ
window.chartInstances = window.chartInstances || {};

let chatBusy = false;
let chatStatusTimer = null;
let chatStatusStartedAt = null;

// モジュールスコープでグラフ配色とモーダル用インスタンスを管理（window汚染の排除）
let activeModalChart = null;
let mermaidInitialized = false;
const THEME_COLORS = ['#4F5D95', '#10b981', '#f59e0b', '#3b82f6', '#ef4444', '#8b5cf6'];

// 生テキストのバッククォート3連フェンス記号を完全排除するための動的定義
const fence = "\x60".repeat(3);

// クロージャや環境依存によるアクセス違反・参照エラーを100%防ぐための公開共有ステートオブジェクト
export const streamState = {
    buffer: "",
    packetCounter: 0,
    lastLoggedLen: 0,
    ollamaErrorMsg: ""
};

// チャットのログが流れるメッセージコンテナ 'chat-box' をバインドしてインスタンスを生成
const aiRenderer = new AiRenderer('chat-box');

// =========================================================================
// 1. ユーティリティ・プライベートヘルパー関数群
// =========================================================================

/**
 * UUIDの生成ヘルパー (フル思考モードなどの推論セッションの一意性を保証します)
 */
function generateUUID() {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    return 'uuid-' + Date.now() + '-' + Math.random().toString(36).substring(2, 11);
}

/**
 * HTMLエスケープヘルパー (コードインジェクション・パース崩れ防止)
 */
function escapeHTML(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#39;');
}

function appendReportDocumentToPdfList(reportDocument) {
    const docId = Number(reportDocument?.document_id || 0);
    if (!docId) return;

    const list = document.getElementById('pdf-document-list');
    if (!list || list.querySelector(`[data-doc-card-id="${docId}"]`)) return;

    const title = String(reportDocument.title || 'AI報告書.pdf');
    const emptyState = document.getElementById('pdf-empty-state');
    if (emptyState) emptyState.remove();

    const details = document.createElement('details');
    details.className = 'bg-white border border-amber-200 rounded-2xl shadow-2xs group overflow-hidden transition-all duration-300 ease-in-out hover:shadow-sm';
    details.dataset.docCardId = String(docId);

    const summary = document.createElement('summary');
    summary.className = 'p-3.5 px-5 flex justify-between items-center cursor-pointer hover:bg-amber-50/50 transition-colors duration-200 ease-in-out outline-none select-none';

    const left = document.createElement('div');
    left.className = 'flex items-center gap-2.5 overflow-hidden pr-2';
    left.innerHTML = '<span class="group-open:rotate-90 transition-transform duration-200 ease-in-out text-slate-400 text-[10px] w-4 text-center">▶</span>';
    const titleSpan = document.createElement('span');
    titleSpan.className = 'text-xs font-bold text-slate-700 group-hover:text-[#4F5D95] transition-colors duration-200 truncate';
    titleSpan.textContent = `📄 ${title}`;
    left.appendChild(titleSpan);

    const right = document.createElement('div');
    right.className = 'flex items-center gap-2 flex-shrink-0';

    const openButton = document.createElement('button');
    openButton.type = 'button';
    openButton.className = 'text-[9px] text-[#4F5D95] hover:bg-indigo-50 border border-slate-200 px-2.5 py-1 rounded-lg font-bold transition-all duration-200 ease-in-out mr-1 shadow-2xs transform active:scale-95';
    openButton.textContent = '↗ 別タブで開く';
    openButton.addEventListener('click', (event) => {
        event.stopPropagation();
        if (typeof window.openPdfTab === 'function') window.openPdfTab(docId, title, 1);
    });

    const badge = document.createElement('span');
    badge.className = 'text-[9px] bg-amber-50 border border-amber-200 px-2 py-0.5 rounded font-mono text-amber-600 font-bold';
    badge.textContent = 'REPORT';

    const deleteButton = document.createElement('button');
    deleteButton.type = 'button';
    deleteButton.dataset.docId = String(docId);
    deleteButton.className = 'btn-delete-pdf text-slate-440 hover:text-red-500 hover:bg-red-50 w-7 h-7 rounded-lg flex items-center justify-center transition-all duration-200 ease-in-out transform active:scale-90';
    deleteButton.title = 'この資料を完全に削除';
    deleteButton.textContent = '🗑️';

    right.append(openButton, badge, deleteButton);
    summary.append(left, right);

    const preview = document.createElement('div');
    preview.className = 'h-[580px] border-t border-slate-100 bg-slate-50 p-2';
    const iframe = document.createElement('iframe');
    iframe.src = `viewer.php?id=${docId}&page=1`;
    iframe.className = 'w-full h-full border-none rounded-xl shadow-inner bg-white';
    iframe.loading = 'lazy';
    preview.appendChild(iframe);

    details.append(summary, preview);
    list.prepend(details);

    const countEl = document.getElementById('pdf-document-count');
    if (countEl) {
        const currentCount = Number(countEl.textContent || '0');
        countEl.textContent = String(Number.isFinite(currentCount) ? currentCount + 1 : 1);
    }
}

function normalizeAiText(value) {
    if (value == null) return '';
    if (typeof value === 'string') return value;
    if (typeof value === 'number' || typeof value === 'boolean') return String(value);
    if (typeof value === 'object') {
        return String(value.text ?? value.content ?? value.response ?? value.message ?? JSON.stringify(value));
    }
    return String(value);
}

/**
 * チャット表示用の日時フォーマット整形
 */
function formatChatDate(createdAt) {
    const dt = createdAt ? new Date(createdAt.replace(/-/g, '/')) : new Date();
    if (isNaN(dt.getTime())) return createdAt;
    
    const yyyy = dt.getFullYear();
    const mm = String(dt.getMonth() + 1).padStart(2, '0');
    const dd = String(dt.getDate()).padStart(2, '0');
    const hh = String(dt.getHours()).padStart(2, '0');
    const min = String(dt.getMinutes()).padStart(2, '0');
    return `${yyyy}/${mm}/${dd} ${hh}:${min}`;
}

/**
 * 現在時刻の時分秒ミリ秒を取得するリアルタイム実況スタンプヘルパー
 */
function getLogTimestamp() {
    const now = new Date();
    const hh = String(now.getHours()).padStart(2, '0');
    const min = String(now.getMinutes()).padStart(2, '0');
    const ss = String(now.getSeconds()).padStart(2, '0');
    return `[${hh}:${min}:${ss}]`;
}

/**
 * 安全なMarkdownパースとサニタイズの統合実行
 */
function parseMarkdownToHtml(content, renderer = null) {
    const options = { breaks: true };
    if (renderer) options.renderer = renderer;

    const rawHtml = marked.parse(normalizeAiText(content), options);
    return sanitizeMarkdownHtml(rawHtml);
}

function sanitizeMarkdownHtml(rawHtml) {
    if (typeof DOMPurify === 'undefined') return rawHtml;
    return DOMPurify.sanitize(rawHtml, {
        ADD_TAGS: ['canvas'],
        ADD_ATTR: ['data-chart-id', 'data-canvas-id', 'data-chart-config', 'data-mermaid-id', 'data-mermaid-source']
    });
}

function normalizeMarkedCodeArgs(code, infostring = '') {
    if (code && typeof code === 'object') {
        return {
            code: String(code.text ?? code.raw ?? code.code ?? ''),
            info: String(code.lang ?? code.infostring ?? code.info ?? '').trim()
        };
    }
    return {
        code: String(code ?? ''),
        info: String(infostring ?? '').trim()
    };
}

function normalizeChartConfig(config) {
    if (!config || typeof config !== 'object') {
        return { type: 'bar', labels: [], datasets: [] };
    }
    if (config.data && typeof config.data === 'object') {
        return {
            ...config,
            labels: Array.isArray(config.labels) ? config.labels : (config.data.labels || []),
            datasets: Array.isArray(config.datasets) ? config.datasets : (config.data.datasets || [])
        };
    }
    return {
        ...config,
        labels: Array.isArray(config.labels) ? config.labels : [],
        datasets: Array.isArray(config.datasets) ? config.datasets : []
    };
}

/**
 * Chart.jsの設定オブジェクトをインテリジェントに動的生成
 */
function generateChartConfig(config, isModal = false) {
    config = normalizeChartConfig(config);
    const isLine = config.type === 'line';
    const isPie = config.type === 'pie';
    
    const datasets = (config.datasets || []).map(ds => ({
        label: ds.label || '集計値',
        data: ds.data || [],
        backgroundColor: isPie ? THEME_COLORS : (isLine ? 'rgba(79, 93, 149, 0.08)' : '#4F5D95'),
        borderColor: '#4F5D95',
        borderWidth: isLine ? (isModal ? 3 : 2.5) : 0,
        borderRadius: isPie ? 0 : (isModal ? 8 : 6),
        tension: isLine ? 0.35 : 0,
        fill: isLine,
        pointBackgroundColor: '#4F5D95',
        pointBorderColor: '#ffffff',
        pointHoverRadius: isModal ? 8 : 6,
        pointRadius: isLine ? (isModal ? 5 : 4) : 0
    }));

    return {
        type: config.type || 'bar',
        data: {
            labels: config.labels || [],
            datasets: datasets
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: isPie || (config.datasets && config.datasets.length > 1),
                    labels: { 
                        font: { size: isModal ? 12 : 9, weight: 'bold' }, 
                        boxWidth: isModal ? 16 : 12 
                    }
                },
                tooltip: { 
                    padding: isModal ? 12 : 10, 
                    cornerRadius: 8,
                    font: { size: isModal ? 11 : 10 } 
                }
            },
            scales: isPie ? {} : {
                x: { 
                    grid: { display: false }, 
                    ticks: { font: { size: isModal ? 10 : 8, weight: 'bold' }, color: '#64748b' } 
                },
                y: { 
                    grid: { color: '#f1f5f9' },
                    ticks: { font: { size: isModal ? 10 : 8, weight: 'bold' }, color: '#64748b' } 
                }
            }
        }
    };
}

/**
 * グラフモーダルを開き高解像度でChartをレンダリングする
 */
function launchChartZoomModal(configStr) {
    const cModal = document.getElementById('chart-max-modal');
    const maxCanvas = document.getElementById('max-chart-canvas');
    if (!cModal || !maxCanvas) return;

    try {
        const config = JSON.parse(configStr);
        
        if (activeModalChart) {
            activeModalChart.destroy();
        }

        cModal.classList.replace('hidden', 'flex');
        
        const maxCtx = maxCanvas.getContext('2d');
        const finalConfig = generateChartConfig(config, true);
        
        activeModalChart = new Chart(maxCtx, finalConfig);
        window.modalChartInstance = activeModalChart;
    } catch (err) {
        alert('グラフの拡大処理中にエラーが発生しました: ' + err.message);
    }
}

/**
 * 指定コンテナ内のCanvas要素に対して、重複を安全に回避しながらChart.jsを描画するコアエンジン
 */
function renderChartsInContainer(parentContainer) {
    if (!parentContainer) return;

    parentContainer.querySelectorAll('.chart-card-wrapper').forEach(wrapper => {
        const chartId = wrapper.dataset.chartId || wrapper.dataset.canvasId;
        const configStr = wrapper.dataset.chartConfig;

        try {
            const config = normalizeChartConfig(JSON.parse(configStr));
            const canvasElement = document.getElementById(chartId);
            
            if (canvasElement) {
                if (window.chartInstances[chartId]) {
                    window.chartInstances[chartId].destroy();
                    delete window.chartInstances[chartId];
                }

                const finalChartConfig = generateChartConfig(config, false);
                window.chartInstances[chartId] = new Chart(canvasElement, finalChartConfig);
            }
        } catch (jsonErr) {
            const container = document.getElementById(chartId)?.parentElement;
            if (container && !container.querySelector('.stream-wait-msg')) {
                container.innerHTML = `<p class="stream-wait-msg text-[10px] text-slate-400 italic py-6 text-center">📊 分析データからグラフを展開中...</p>`;
            }
        }
    });
}

/**
 * グラフカードに拡大表示ダブルクリックイベントを多重バインドを防ぎながら適用
 */
function bindChartModalEvents(parentContainer) {
    if (!parentContainer) return;

    parentContainer.querySelectorAll('.chart-card-wrapper').forEach(wrapper => {
        if (wrapper.dataset.dblclickBound) return;
        wrapper.dataset.dblclickBound = "true";

        wrapper.addEventListener('dblclick', () => {
            launchChartZoomModal(wrapper.dataset.chartConfig);
        });
    });
}

function ensureMermaidReady() {
    if (typeof mermaid === 'undefined') return false;
    if (mermaidInitialized) return true;

    try {
        if (typeof window.__tepscoInitMermaid === 'function') {
            if (!window.__tepscoInitMermaid()) return false;
        } else {
            mermaid.parseError = function(err) {
                console.warn('[Mermaid skipped]', err && err.message ? err.message : err);
            };
            mermaid.initialize({
                startOnLoad: false,
                securityLevel: 'strict',
                theme: 'default',
                logLevel: 'fatal',
                suppressErrorRendering: true
            });
        }
        mermaidInitialized = true;
        return true;
    } catch (err) {
        console.error('Mermaid Initialize Error:', err);
        return false;
    }
}

function createMermaidCard(source, title = 'Mermaid 図表') {
    const mermaidId = 'mermaid-' + Math.random().toString(36).substring(2, 11);
    return `
        <div class="mermaid-card-wrapper my-3 rounded-xl border border-slate-200/80 bg-white p-3 shadow-xs" data-mermaid-id="${mermaidId}" data-mermaid-source="${escapeHTML(source)}">
            <div class="text-[9px] text-slate-400 font-bold mb-2.5 select-none flex items-center gap-1">🧭 ${escapeHTML(title)}</div>
            <div id="${mermaidId}" class="mermaid-render-target overflow-x-auto text-center text-[10px] text-slate-400 py-2">図表を描画中...</div>
        </div>
    `;
}

function renderMermaidInContainer(parentContainer) {
    if (!parentContainer || !ensureMermaidReady()) return;

    parentContainer.querySelectorAll('pre code.language-mermaid').forEach(codeBlock => {
        const pre = codeBlock.parentElement;
        if (!pre || pre.dataset.convertedMermaid === 'true') return;
        const holder = document.createElement('div');
        holder.innerHTML = createMermaidCard(codeBlock.textContent.trim(), 'Mermaid 過去履歴図表');
        const wrapper = holder.firstElementChild;
        if (wrapper && pre.parentNode) {
            pre.parentNode.replaceChild(wrapper, pre);
        }
    });

    parentContainer.querySelectorAll('.mermaid-card-wrapper').forEach(wrapper => {
        if (['true', 'pending', 'error'].includes(wrapper.dataset.rendered)) return;
        const mermaidId = wrapper.dataset.mermaidId;
        const source = wrapper.dataset.mermaidSource || '';
        const target = wrapper.querySelector('.mermaid-render-target');
        if (!mermaidId || !source || !target) return;

        wrapper.dataset.rendered = 'pending';
        const parsePromise = typeof mermaid.parse === 'function'
            ? Promise.resolve(mermaid.parse(source, { suppressErrors: true }))
            : Promise.resolve(true);

        parsePromise
            .then(isValid => {
                if (isValid === false) {
                    throw new Error('Invalid Mermaid syntax');
                }
                return mermaid.render(`${mermaidId}-svg`, source);
            })
            .then(({ svg }) => {
                target.innerHTML = svg;
                wrapper.dataset.rendered = 'true';
            })
            .catch(err => {
                target.textContent = '図表は現在描画できません。';
                wrapper.dataset.rendered = 'error';
                console.warn('Mermaid Render Skipped:', err && err.message ? err.message : err);
            });
    });
}

// marked.js 用のカスタムレンダラー
const customRenderer = new marked.Renderer();

customRenderer.code = function(code, infostring, escaped) {
    const normalized = normalizeMarkedCodeArgs(code, infostring);
    code = normalized.code;
    const info = normalized.info;
    if (info === 'json:chart' || info === 'json:chart_data') {
        const chartId = 'chart-' + Math.random().toString(36).substring(2, 11);
        let configStr = code;
        try {
            configStr = JSON.stringify(normalizeChartConfig(JSON.parse(code)));
        } catch (err) {
            configStr = code;
        }
        return `
            <div class="chart-card-wrapper cursor-pointer" data-chart-id="${chartId}" data-canvas-id="${chartId}" data-chart-config="${escapeHTML(configStr)}">
                <div class="text-[9px] text-slate-400 font-bold mb-2.5 select-none flex justify-between items-center pr-1">
                    <span class="flex items-center gap-1">📊 Chart.js 自律視覚化グラフ</span>
                    <span class="text-[8px] bg-indigo-50 text-indigo-600 font-black px-1.5 py-0.5 rounded-md border border-indigo-100">💡 ダブルクリックで拡大</span>
                </div>
                <div class="relative w-full h-52">
                    <canvas id="${chartId}"></canvas>
                </div>
            </div>
        `;
    }
    if (info === 'mermaid') {
        return createMermaidCard(code, 'Mermaid 図表');
    }
    return `<pre class="bg-slate-50 p-3 rounded-xl border border-slate-200/60 overflow-x-auto my-2"><code class="text-[11px] font-mono text-slate-700">${escapeHTML(code)}</code></pre>`;
};

// グローバル空間へのモーダル展開関数の露出バインド（aiRenderer.jsからの協調キック用）
window.launchChartZoomModal = launchChartZoomModal;

function getChatStatusBar() {
    const form = document.getElementById('chat-form');
    if (!form) return null;

    let bar = document.getElementById('chat-processing-status');
    if (!bar) {
        bar = document.createElement('div');
        bar.id = 'chat-processing-status';
        bar.className = 'hidden items-center justify-between gap-3 rounded-xl border border-slate-200 bg-slate-50 px-3 py-1.5 text-[10px] text-slate-500 shadow-2xs';
        form.parentNode?.insertBefore(bar, form);
    }
    return bar;
}

function updateChatProcessingStatus(message, variant = 'processing') {
    const bar = getChatStatusBar();
    if (!bar) return;

    const elapsed = chatStatusStartedAt ? Math.max(0, Math.floor((Date.now() - chatStatusStartedAt) / 1000)) : 0;
    const tone = variant === 'done'
        ? 'border-emerald-200 bg-emerald-50 text-emerald-700'
        : variant === 'error'
            ? 'border-red-200 bg-red-50 text-red-700'
            : 'border-amber-200 bg-amber-50 text-amber-800';

    bar.className = `flex items-center justify-between gap-3 rounded-xl border px-3 py-1.5 text-[10px] shadow-2xs ${tone}`;
    bar.innerHTML = `
        <span class="flex min-w-0 items-center gap-2 font-bold">
            <span class="${variant === 'processing' ? 'inline-block h-2.5 w-2.5 rounded-full border-2 border-current border-t-transparent animate-spin' : 'inline-block h-2.5 w-2.5 rounded-full bg-current'}"></span>
            <span class="truncate">${escapeHTML(message)}</span>
        </span>
        <span class="shrink-0 font-mono text-[9px] opacity-75">${elapsed}s</span>
    `;
}

function startChatProcessingStatus(message) {
    chatStatusStartedAt = Date.now();
    updateChatProcessingStatus(message);
    if (chatStatusTimer) clearInterval(chatStatusTimer);
    chatStatusTimer = setInterval(() => {
        const current = document.getElementById('chat-processing-status')?.dataset.message || message;
        updateChatProcessingStatus(current);
    }, 1000);
}

function setChatProcessingStatus(message, variant = 'processing') {
    const bar = getChatStatusBar();
    if (bar) bar.dataset.message = message;
    updateChatProcessingStatus(message, variant);
}

function finishChatProcessingStatus(message, variant = 'done') {
    if (chatStatusTimer) {
        clearInterval(chatStatusTimer);
        chatStatusTimer = null;
    }
    setChatProcessingStatus(message, variant);
    setTimeout(() => {
        const bar = document.getElementById('chat-processing-status');
        if (bar && !chatBusy) {
            bar.classList.add('hidden');
            bar.textContent = '';
        }
    }, 2500);
}

/**
 * 送信ボタンと入力欄の状態を制御する。処理中も入力欄は編集可能にして、待ち状態を明示する。
 */
function setFormBusy(busy, message = '') {
    const input = document.getElementById('chat-input');
    const form = document.getElementById('chat-form');
    chatBusy = busy;
    if (input) {
        input.disabled = false;
        input.placeholder = busy ? '次の質問を入力できます。送信は現在の処理完了後に可能です。' : '資料やデータについて質問... (Shift+Enterで改行)';
    }
    if (form) {
        const btn = form.querySelector('button[type="submit"]');
        if (btn) {
            btn.disabled = busy;
            btn.title = busy ? '現在の回答を保存・品質確認中です' : '送信';
            if (busy) {
                btn.classList.add('opacity-40', 'cursor-not-allowed');
            } else {
                btn.classList.remove('opacity-40', 'cursor-not-allowed');
            }
        }
    }
    if (busy && message) {
        startChatProcessingStatus(message);
    }
}

function initDebugLogViewer() {
    const configEl = document.querySelector('#support-config');
    if (!configEl || configEl.dataset.canDebugLog !== '1') return;

    const panel = document.getElementById('chat-debug-panel');
    const viewer = document.getElementById('chat-debug-viewer');
    const status = document.getElementById('chat-debug-status');
    if (!panel || !viewer || !status || panel.dataset.initialized === 'true') return;

    panel.dataset.initialized = 'true';
    let offset = 0;
    let timer = null;
    let loading = false;
    let lastErrorMessage = '';

    const setStatus = (text, className = 'text-[9px] text-slate-400 font-bold') => {
        status.textContent = text;
        status.className = className;
    };

    const appendLog = (text) => {
        if (!text) return;
        const shouldStick = viewer.scrollHeight - viewer.scrollTop - viewer.clientHeight < 24;
        viewer.appendChild(document.createTextNode(text));
        const maxChars = 120000;
        if (viewer.textContent.length > maxChars) {
            viewer.textContent = viewer.textContent.slice(-maxChars);
        }
        if (shouldStick) {
            viewer.scrollTop = viewer.scrollHeight;
        }
    };

    const poll = async () => {
        if (loading || !panel.open) return;
        loading = true;
        try {
            const res = await fetch(`api/chat_debug_tail.php?offset=${offset}`, {
                headers: { 'X-CSRF-Token': configEl.dataset.csrfToken || '' },
                credentials: 'same-origin'
            });
            const raw = await res.text();
            let data = null;
            try {
                data = raw ? JSON.parse(raw) : null;
            } catch (parseErr) {
                throw new Error(`JSON解析失敗: ${parseErr.message}${raw ? ' / 応答先頭: ' + raw.slice(0, 120) : ''}`);
            }
            if (!res.ok || !data.success) {
                throw new Error(data?.error || `HTTP ${res.status}`);
            }
            offset = data.offset || offset;
            if (data.truncated && !viewer.textContent) {
                appendLog('[ログが大きいため直近部分のみ表示しています]\n');
            }
            appendLog(data.content || '');
            lastErrorMessage = '';
            const timeLabel = new Date((data.updated_at || Math.floor(Date.now() / 1000)) * 1000).toLocaleTimeString('ja-JP', {
                hour: '2-digit',
                minute: '2-digit',
                second: '2-digit'
            });
            setStatus(`監視中 ${timeLabel}`, 'text-[9px] text-emerald-500 font-black');
        } catch (err) {
            const message = err?.message || '不明な取得エラー';
            setStatus('取得エラー・再試行中', 'text-[9px] text-red-500 font-black');
            if (message !== lastErrorMessage) {
                appendLog(`\n[log viewer error] ${message}\n`);
                lastErrorMessage = message;
            }
        } finally {
            loading = false;
        }
    };

    const start = () => {
        setStatus('監視中', 'text-[9px] text-emerald-500 font-black');
        poll();
        if (!timer) timer = setInterval(poll, 1500);
    };

    const stop = () => {
        if (timer) {
            clearInterval(timer);
            timer = null;
        }
        setStatus('停止中');
    };

    panel.addEventListener('toggle', () => {
        if (panel.open) start();
        else stop();
    });

    if (panel.open) {
        start();
    }
}

function syncChatModeToggles() {
    const advancedReasoningMode = document.getElementById('advanced-reasoning-mode');
    const reportModeEl = document.getElementById('report-mode');
    if (!advancedReasoningMode || !reportModeEl || reportModeEl.dataset.boundSync === 'true') return;

    reportModeEl.dataset.boundSync = 'true';
    reportModeEl.addEventListener('change', () => {
        if (reportModeEl.checked) {
            advancedReasoningMode.checked = true;
        }
    });
}

// =========================================================================
// 2. メインエクスポートモジュール関数群
// =========================================================================

/**
 * メインのチャット送信・SSE接続ハンドラ (進行ステータス実況マウント・完全開通版)
 */
function handleChat(e) {
    if (e) e.preventDefault();
    if (chatBusy) {
        setChatProcessingStatus('現在の回答を品質確認・保存中です。入力内容はそのまま残ります。');
        document.getElementById('chat-input')?.focus();
        return;
    }

    const { projectId } = getConfig();
    const input = document.getElementById('chat-input');
    if (!input) return;

    const msg = input.value.trim();
    if (!msg) return;

    const modelSelect = document.getElementById('support-model-select');
    const model = modelSelect ? modelSelect.value : 'gemma4:e4b';
    const promptModeSelect = document.getElementById('support-prompt-select');
    const promptMode = promptModeSelect ? promptModeSelect.value : 'construction_consultant';

    const advancedReasoningMode = document.getElementById('advanced-reasoning-mode');
    const reportModeEl = document.getElementById('report-mode');
    const diagramModeEl = document.getElementById('diagram-mode');
    const reportMode = reportModeEl ? reportModeEl.checked : false;
    const diagramMode = diagramModeEl ? diagramModeEl.checked : false;
    if (reportMode && advancedReasoningMode && !advancedReasoningMode.checked) {
        advancedReasoningMode.checked = true;
    }
    const advancedReasoning = reportMode || (advancedReasoningMode ? advancedReasoningMode.checked : false);
    const reasoningId = advancedReasoning ? generateUUID() : null;
    const initialStatusText = reportMode
        ? '報告書モードで、根拠収集・本文生成・PDF登録の準備を進めています...'
        : advancedReasoning
            ? '質問の意図を分析し、多段階の検証シナリオを準備しています...'
            : diagramMode
                ? '必要に応じて図表を含める準備をしています...'
                : '関連資料を検索し、回答を準備しています...';

    const targetMessageId = 'ai-msg-' + generateUUID();

    appendMsg('user', msg);
    input.value = '';
    input.style.height = 'auto';
    setFormBusy(true, initialStatusText);

    const tempId = 'loading-' + Date.now();
    const chatBox = document.getElementById('chat-box');
    if (!chatBox) {
        setFormBusy(false);
        finishChatProcessingStatus('チャット画面を取得できませんでした。', 'error');
        return;
    }

    const loadingDiv = document.createElement('div');
    loadingDiv.id = tempId; 
    loadingDiv.className = 'flex gap-3 items-start animate-pulse-soft';
    
    loadingDiv.innerHTML = `
        <div class="w-7 h-7 rounded-full bg-amber-50 text-amber-700 border border-amber-200/60 flex items-center justify-center text-xs font-bold flex-shrink-0 shadow-sm select-none">🤖</div>
        <div class="flex flex-col items-start max-w-[82%] gap-0.5">
            <div class="flex items-center gap-1.5 px-1">
                <span class="text-[9px] font-black text-slate-500 uppercase tracking-tight">AI Assistant</span>
                <span class="text-[8px] text-slate-400 font-mono tracking-tighter">${formatChatDate(null)}</span>
            </div>
            <div class="bg-white border border-slate-200 text-slate-500 rounded-2xl rounded-tl-none px-3.5 py-3 text-xs shadow-sm font-semibold flex items-center gap-2 leading-relaxed">
                <span class="inline-block w-3 h-3 border-2 border-[#4F5D95] border-t-transparent rounded-full animate-spin"></span>
                <span class="status-msg-holder">回答を準備しています...</span>
            </div>
        </div>
    `;
    chatBox.appendChild(loadingDiv); 

    let bubbleCreated = false;
    let streamContent = "";
    let terminalStatus = 'done';
    const liveStatusLines = [];

    streamState.buffer = "";
    streamState.packetCounter = 0;
    streamState.lastLoggedLen = 0;
    streamState.ollamaErrorMsg = "";

    (async () => {
        try {
            const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content || '';
            const response = await fetch('api/chat.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                },
                body: JSON.stringify({ 
                    message: msg, 
                    project_id: projectId, 
                    model: model, 
                    prompt_mode: promptMode,
                    advanced_reasoning: advancedReasoning,
                    advanced_reasoning_id: reasoningId,
                    report_mode: reportMode,
                    diagram_mode: diagramMode
                })
            });

            if (!response.ok) throw new Error(`HTTP Error: ${response.status}`);

            const reader = response.body.getReader();
            const decoder = new TextDecoder('utf-8');

            while (true) {
                const { value, done } = await reader.read();
                if (done) break;

                streamState.buffer += decoder.decode(value, { stream: true });
                const lines = streamState.buffer.split('\n');
                streamState.buffer = lines.pop() || '';

                for (const line of lines) {
                    const trimmed = line.trim();
                    if (!trimmed || !trimmed.startsWith('data:')) continue;

                    try {
                        const jsonStr = trimmed.substring(5).trim();
                        const sseData = JSON.parse(jsonStr);

                        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                        // type === 'status' は小さな進行バーに表示し、詳細は完了後の折りたたみへ退避
                        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                        if (sseData.type === 'status') {
                            const statusMessage = sseData.message || 'AI処理を継続しています...';
                            setChatProcessingStatus(statusMessage);
                            liveStatusLines.push(`${getLogTimestamp()} ${statusMessage}`);
                            if (bubbleCreated) {
                                const currentStep = sseData.step || 1;
                                aiRenderer.updateStatusStep(targetMessageId, currentStep, statusMessage);
                            }
                            scrollToBottom();

                        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                        // type === 'chunk' 受信時の本文レンダリング
                        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                        } else if (sseData.type === 'chunk') {
                            setChatProcessingStatus('回答を生成中です。本文はリアルタイムに表示されています...');
                            if (!bubbleCreated) {
                                document.getElementById(tempId)?.remove();
                                aiRenderer.createMessageBubble(targetMessageId, 'assistant');
                                bubbleCreated = true;
                            }

                            streamContent += normalizeAiText(sseData.text ?? sseData.word ?? '');
                            
                            // 本文をリアルタイムでMarkdown描画
                            aiRenderer.renderStream(targetMessageId, streamContent);

                            streamState.packetCounter++;
                            const current_len = streamContent.length;
                            if (current_len - streamState.lastLoggedLen >= 100) {
                                typeof chatLogger === 'function'
                                    ? chatLogger(`  [JSストリーム進行中] パケット数: ${streamState.packetCounter}回 | 累積文字数: ${current_len}文字`)
                                    : console.log(`  [JSストリーム進行中] パケット数: ${streamState.packetCounter}回 | 累積文字数: ${current_len}文字`);
                                streamState.lastLoggedLen = current_len;
                            }

                        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                        // 🏁 【新仕様】type === 'result' 受信時の最終確定処理
                        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                        } else if (sseData.type === 'error') {
                            terminalStatus = 'error';
                            typeof chatLogger === 'function'
                                ? chatLogger(`[SSEエラーイベント受信] ${sseData.error}`)
                                : console.log(`[SSEエラーイベント受信] ${sseData.error}`);
                            document.getElementById(tempId)?.remove();
                            const errText = `⚠️ **システムエラーが発生しました**\n\n詳細: ${sseData.error || "不明な内部障害です。"}`;
                            if (bubbleCreated) {
                                aiRenderer.finalize(targetMessageId, errText);
                            } else {
                                appendMsg('assistant', errText);
                            }
                            finishChatProcessingStatus('システムエラーが発生しました。', 'error');

                        } else if (sseData.type === 'result') {
                            document.getElementById(tempId)?.remove();
                            
                            if (sseData.status === 'success') {
                                setChatProcessingStatus('回答生成は完了しました。履歴保存・品質確認結果を反映しています...');
                                if (!bubbleCreated) {
                                    aiRenderer.createMessageBubble(targetMessageId, 'assistant');
                                    bubbleCreated = true;
                                }
                                
                                // オブジェクト直撃バグの完全閉塞ガード層
                                let finalReportText = normalizeAiText(sseData.response);
                                
                                const liveLogText = liveStatusLines.join('\n');

                                // 最終回答ドラフトを確定展開
                                aiRenderer.finalize(targetMessageId, String(finalReportText));

                                const bubbleContainer = document.getElementById(targetMessageId)?.querySelector('.ai-text-body');
                                if (bubbleContainer) {
                                    if (liveLogText) {
                                        const detailsBox = document.createElement('details');
                                        detailsBox.className = 'mb-3 border border-slate-200 rounded-xl bg-slate-50 overflow-hidden group w-full';
                                        detailsBox.innerHTML = `
                                            <summary class="text-[10px] font-bold text-slate-600 p-2.5 cursor-pointer hover:bg-slate-100 transition-colors select-none outline-none flex items-center gap-1.5">
                                                <span class="group-open:rotate-90 transition-transform text-[8px] w-3 text-center block">▶</span>
                                                処理ステータスの詳細を表示
                                            </summary>
                                            <div class="p-3.5 pt-0 font-mono text-[11px] leading-relaxed max-h-[200px] overflow-y-auto custom-scrollbar border-t border-slate-200 bg-white/80 whitespace-pre-wrap"></div>
                                        `;
                                        detailsBox.querySelector('div').textContent = liveLogText;
                                        bubbleContainer.insertBefore(detailsBox, bubbleContainer.firstChild);
                                    }

                                    if (sseData.reasoning_steps && sseData.reasoning_steps.length > 0) {
                                        const rHtml = `
                                            <details class="mb-3 border border-indigo-100 rounded-xl bg-indigo-50/20 overflow-hidden group w-full">
                                                <summary class="text-[10px] font-bold text-indigo-600 p-2.5 cursor-pointer hover:bg-indigo-50/50 transition-colors select-none outline-none flex items-center gap-1.5">
                                                    <span class="group-open:rotate-90 transition-transform text-[8px] w-3 text-center block">▶</span>
                                                    🧠 AIの思考・検証プロセスを表示 (${sseData.reasoning_steps.length}件のサブクエリ)
                                                </summary>
                                                <div class="p-3.5 pt-0 space-y-3 border-t border-indigo-100/60 max-h-[300px] overflow-y-auto custom-scrollbar bg-white/50">
                                                    ${sseData.reasoning_steps.map(step => `
                                                        <div class="text-[10px] bg-white p-3 rounded-xl border border-indigo-50 shadow-xs">
                                                            <p class="font-bold text-indigo-700 mb-1.5">Q. ${escapeHTML(step.sub_query)}</p>
                                                            <div class="text-slate-600 leading-relaxed markdown-body text-[10px]">
                                                                ${parseMarkdownToHtml(step.sub_answer || '')}
                                                            </div>
                                                        </div>
                                                    `).join('')}
                                                </div>
                                            </details>
                                        `;
                                        const reasoningFragment = document.createRange().createContextualFragment(rHtml);
                                        bubbleContainer.insertBefore(reasoningFragment, bubbleContainer.children[1] || null);
                                    }
                                    if (sseData.sources && sseData.sources.length > 0) {
                                        const sHtml = '<div class="flex flex-wrap mt-2.5 gap-1.5">' + sseData.sources.map(s => {
                                            const safeTitle = escapeHTML(s.title);
                                            const label = (s.page == 0) ? `📖 ${safeTitle} (全体要約)` : `📖 ${safeTitle} (P.${s.page})`;
                                            const escapedTitle = s.title.replace(/'/g, "\\'").replace(/"/g, "&quot;");
                                            return `<div class="source-badge shadow-xs text-[10px] font-semibold" onclick="openPdfTab(${s.doc_id}, '${escapedTitle}', ${s.page == 0 ? 1 : s.page})">${label}</div>`;
                                        }).join('') + '</div>';
                                        bubbleContainer.insertAdjacentHTML('beforeend', sHtml);
                                    }
                                    if (sseData.report_document && sseData.report_document.document_id) {
                                        appendReportDocumentToPdfList(sseData.report_document);
                                        const reportTitle = escapeHTML(sseData.report_document.title || 'AI報告書.pdf');
                                        const reportId = Number(sseData.report_document.document_id);
                                        const reportHtml = `
                                            <div class="mt-3 border border-amber-200 bg-amber-50/70 rounded-xl p-3 text-[10px] text-amber-800 shadow-xs">
                                                <div class="font-black mb-1">📄 報告書PDFを生成しました</div>
                                                <div class="text-amber-700 mb-2">${reportTitle} はPDFタブへ登録され、検索対象にも追加されています。</div>
                                                <button type="button" class="text-[10px] bg-white border border-amber-200 hover:bg-amber-100 rounded-lg px-2.5 py-1 font-bold transition-colors" onclick="if(typeof window.openPdfTab === 'function') window.openPdfTab(${reportId}, '${reportTitle.replace(/'/g, "\\'")}', 1);">
                                                    PDFを開く
                                                </button>
                                            </div>
                                        `;
                                        bubbleContainer.insertAdjacentHTML('beforeend', reportHtml);
                                    }

                                    renderChartsInContainer(bubbleContainer.parentElement);
                                    bindChartModalEvents(bubbleContainer.parentElement);
                                    renderMermaidInContainer(bubbleContainer.parentElement);
                                }
                            } else {
                                terminalStatus = 'error';
                                finishChatProcessingStatus('回答生成でエラーが発生しました。', 'error');
                                const errResponse = `⚠️ **エラーが発生しました**\n\n${sseData.error || "回答を生成できませんでした。もう一度お試しください。"}`;
                                if (bubbleCreated) {
                                    aiRenderer.finalize(targetMessageId, errResponse);
                                } else {
                                    appendMsg('assistant', errResponse);
                                }
                            }

                        } else if (sseData.type === 'result' && sseData.status === 'error') {
                            terminalStatus = 'error';
                            typeof chatLogger === 'function'
                                ? chatLogger(`[Ollama経由の例外を受信] ${sseData.error}`)
                                : console.log(`[Ollama経由の例外を受信] ${sseData.error}`);
                            document.getElementById(tempId)?.remove();
                            const errText = `⚠️ **システムエラーが発生しました**\n\n詳細: ${sseData.error || "不明な内部障害です。"}`;
                            if (bubbleCreated) {
                                aiRenderer.finalize(targetMessageId, errText);
                            } else {
                                appendMsg('assistant', errText);
                            }
                            finishChatProcessingStatus('システムエラーが発生しました。', 'error');
                        }
                    } catch (parseErr) {
                        console.warn('SSE Chunk Parse Error:', parseErr, trimmed);
                    }
                }
            }
        } catch (error) {
            typeof chatLogger === 'function'
                ? chatLogger(`[SSEネットワーク例外検知] ${error.message}`)
                : console.log(`[SSEネットワーク例外検知] ${error.message}`);
            document.getElementById(tempId)?.remove();
            const connErrText = `⚠️ **通信エラーが発生しました**\n\n接続状態をご確認ください：${error.message}`;
            if (bubbleCreated) {
                aiRenderer.finalize(targetMessageId, connErrText);
            } else {
                appendMsg('assistant', connErrText);
            }
            terminalStatus = 'error';
            finishChatProcessingStatus('通信エラーが発生しました。', 'error');
        } finally {
            setFormBusy(false);
            if (document.getElementById('chat-processing-status')?.dataset.message && !document.getElementById('chat-processing-status')?.classList.contains('hidden')) {
                if (terminalStatus === 'error') {
                    finishChatProcessingStatus('エラーのため処理を終了しました。次の質問を送信できます。', 'error');
                } else {
                    finishChatProcessingStatus('処理が完了しました。次の質問を送信できます。');
                }
            }
        }
    })();
}

/**
 * チャット吹き出しを描画する関数（過去履歴ロード時用・インターフェース完全維持）
 */
function appendMsg(role, text, sources = [], reasoningSteps = [], createdAt = null) {
    const chatBox = document.getElementById('chat-box');
    if (!chatBox) return;

    const div = document.createElement('div');
    div.className = `flex gap-3 items-start ${role === 'assistant' ? '' : 'flex-row-reverse'} animate-fadeIn`;
    
    const sourceHtml = sources && sources.length ? '<div class="flex flex-wrap mt-2.5 gap-1.5">' + sources.map(s => {
        const safeTitle = escapeHTML(s.title);
        const label = (s.page == 0) ? `📖 ${safeTitle} (全体要約)` : `📖 ${safeTitle} (P.${s.page})`;
        const escapedTitle = s.title.replace(/'/g, "\\'").replace(/"/g, "&quot;");
        return `<div class="source-badge shadow-xs text-[10px] font-semibold" onclick="openPdfTab(${s.doc_id}, '${escapedTitle}', ${s.page == 0 ? 1 : s.page})">${label}</div>`;
    }).join('') + '</div>' : '';
    
    let reasoningHtml = '';
    if (reasoningSteps && reasoningSteps.length > 0) {
        reasoningHtml = `
            <details class="mb-3 border border-indigo-100 rounded-xl bg-indigo-50/20 overflow-hidden group w-full">
                <summary class="text-[10px] font-bold text-indigo-600 p-2.5 cursor-pointer hover:bg-indigo-50/50 transition-colors select-none outline-none flex items-center gap-1.5">
                    <span class="group-open:rotate-90 transition-transform text-[8px] w-3 text-center block">▶</span>
                    🧠 AIの思考・検証プロセスを表示 (${reasoningSteps.length}件のサブクエリ)
                </summary>
                <div class="p-3.5 pt-0 space-y-3 border-t border-indigo-100/60 max-h-[300px] overflow-y-auto custom-scrollbar bg-white/50">
                    ${reasoningSteps.map(step => `
                        <div class="text-[10px] bg-white p-3 rounded-xl border border-indigo-50 shadow-xs">
                            <p class="font-bold text-indigo-700 mb-1.5">Q. ${escapeHTML(step.sub_query)}</p>
                            <div class="text-slate-600 markdown-body chat-markdown chat-reasoning-body">
                                ${parseMarkdownToHtml(step.sub_answer || '')}
                            </div>
                        </div>
                    `).join('')}
                </div>
            </details>
        `;
    }

    // ★【完全閉塞：動的RegExp生成仕様】生のトリプルバッククォートを完全に排除し、16進数文字コードで安全パース
    const regexSql = new RegExp("\\x60{3}sql", "g");
    const regexJson = new RegExp("\\x60{3}json", "g");
    const regexFence = new RegExp("\\x60{3}", "g");

    reasoningHtml = reasoningHtml
        .replace(regexSql, fence + "sql")
        .replace(regexJson, fence + "json")
        .replace(regexFence, fence);

    const cleanHtml = parseMarkdownToHtml(text, customRenderer);
    const isAsst = (role === 'assistant');

    const avatarHtml = `<div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0 shadow-sm border select-none
        ${isAsst ? 'bg-amber-50 text-amber-700 border-amber-200/60' : 'bg-indigo-100 text-indigo-700 border-indigo-200/60'}">
        ${isAsst ? '🤖' : '👤'}
    </div>`;

    div.innerHTML = `
        ${avatarHtml}
        <div class="chat-message-stack flex flex-col ${isAsst ? 'items-start' : 'items-end'} gap-0.5">
            <div class="flex items-center gap-1.5 px-1">
                <span class="text-[9px] font-black text-slate-500 uppercase tracking-tight">${isAsst ? 'AI Assistant' : 'You'}</span>
                <span class="text-[8px] text-slate-400 font-mono tracking-tighter">${formatChatDate(createdAt)}</span>
            </div>
            <div class="chat-message-bubble chat-message-body ${isAsst ? 'chat-assistant rounded-tl-none border-slate-200/80' : 'chat-user rounded-tr-none border-[#3d4975] shadow-md'} p-4 shadow-sm markdown-body chat-markdown ai-text-body">
                ${reasoningHtml}
                ${cleanHtml}
                ${sourceHtml}
            </div>
        </div>
    `;
    chatBox.appendChild(div); 
    
    chatBox.scrollTop = chatBox.scrollHeight;

    renderChartsInContainer(div);
    bindChartModalEvents(div);
    renderMermaidInContainer(div);
}

/**
 * ページロード時に過去ログのグラフブロックを自動イニシャライズする
 */
function initExistingCharts() {
    const chatBox = document.getElementById('chat-box');
    if (!chatBox) return;

    chatBox.querySelectorAll('pre code.language-json\\:chart, pre code.language-json\\:chart_data, pre code.language-json').forEach(codeBlock => {
        const content = codeBlock.textContent.trim();
        if (content.includes('"type"') && content.includes('"datasets"')) {
            const pre = codeBlock.parentElement;
            if (pre) {
                const chartId = 'chart-' + Math.random().toString(36).substring(2, 11);
                const cardWrapper = document.createElement('div');
                cardWrapper.className = 'chart-card-wrapper cursor-pointer';
                cardWrapper.dataset.chartId = chartId;
                cardWrapper.dataset.canvasId = chartId;
                try {
                    cardWrapper.dataset.chartConfig = JSON.stringify(normalizeChartConfig(JSON.parse(content)));
                } catch (err) {
                    cardWrapper.dataset.chartConfig = content;
                }
                
                cardWrapper.innerHTML = `
                    <div class="text-[9px] text-slate-400 font-bold mb-2.5 select-none flex justify-between items-center pr-1">
                        <span class="flex items-center gap-1">📊 Chart.js 過去履歴グラフ</span>
                        <span class="text-[8px] bg-indigo-50 text-indigo-600 font-black px-1.5 py-0.5 rounded-md border border-indigo-100">💡 ダブルクリックで拡大</span>
                    </div>
                    <div class="relative w-full h-52">
                        <canvas id="${chartId}"></canvas>
                    </div>
                `;
                
                if (pre.parentNode) {
                    pre.parentNode.replaceChild(cardWrapper, pre);
                }
            }
        }
    });

    renderChartsInContainer(chatBox);
    bindChartModalEvents(chatBox);
    renderMermaidInContainer(chatBox);
}

// =========================================================================
// 3. ページ初期表示時のイベントフック自動展開
// =========================================================================
document.addEventListener('DOMContentLoaded', () => {
    setTimeout(initExistingCharts, 150);
    syncChatModeToggles();

    document.getElementById('btn-close-chart-modal')?.addEventListener('click', () => {
        if (activeModalChart) {
            activeModalChart.destroy();
            activeModalChart = null;
            window.modalChartInstance = null;
        }
    });
});

// =========================================================================
// ★[究極の安全設計] グローバルへの確実なバインドレイヤー
// =========================================================================
(function initGlobalChatBindings() {
    window.handleChat = handleChat;
    window.appendMsg = appendMsg;
    window.initExistingCharts = initExistingCharts;
    window.initDebugLogViewer = initDebugLogViewer;
})();

export {
    handleChat,
    appendMsg,
    initExistingCharts,
    initDebugLogViewer
};
