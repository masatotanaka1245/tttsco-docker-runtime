/**
 * chat.js - RAG対応 AIチャット送受信およびポーリング監視モジュール (SSEリアルタイムストリーム対応・完全同期版)
 * (support.js からインポートされてグローバル空間にバインドされるモジュールファイルです)
 * * ★[UXガラス張り化パッチ・進行ステータス実況マウント完全版]
 * 1. AI応答開始時に、吹き出しの内部に「思考ログ専用コンテナ」を動的インジェクト。
 * 2. statusパケット受信時、インジケーターの上書きではなくタイムスタンプ付きで下部へ数珠繋ぎ（Append）追記。
 * 3. chunkおよびresultパケット受信時、ログコンテナを上部に維持したまま、下部へ美麗なMarkdown本文を共存調停。
 */
import { secureFetch, getConfig } from './api.js?v=4';
import { scrollToBottom } from './ui.js?v=4';
import { AiRenderer } from './aiRenderer.js?v=5';

// 生成された Chart.js のインスタンスを保持し、メモリリークや二重描画を防ぐグローバル管理マップ
window.chartInstances = window.chartInstances || {};

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
        if (wrapper.dataset.rendered === 'true' || wrapper.dataset.rendered === 'pending') return;
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

/**
 * 送信ボタン及びテキストエリアの有効・無効状態を一括制御する
 */
function setFormDisabled(disabled) {
    const input = document.getElementById('chat-input');
    const form = document.getElementById('chat-form');
    if (input) input.disabled = disabled;
    if (form) {
        const btn = form.querySelector('button[type="submit"]');
        if (btn) {
            btn.disabled = disabled;
            if (disabled) {
                btn.classList.add('opacity-40', 'cursor-not-allowed');
            } else {
                btn.classList.remove('opacity-40', 'cursor-not-allowed');
            }
        }
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
            const data = await res.json();
            if (!res.ok || !data.success) {
                throw new Error(data.error || `HTTP ${res.status}`);
            }
            offset = data.offset || offset;
            if (data.truncated && !viewer.textContent) {
                appendLog('[ログが大きいため直近部分のみ表示しています]\n');
            }
            appendLog(data.content || '');
            setStatus('監視中', 'text-[9px] text-emerald-500 font-black');
        } catch (err) {
            setStatus('取得エラー', 'text-[9px] text-red-500 font-black');
            if (!viewer.textContent.includes(err.message)) {
                appendLog(`\n[log viewer error] ${err.message}\n`);
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
}

// =========================================================================
// 2. メインエクスポートモジュール関数群
// =========================================================================

/**
 * メインのチャット送信・SSE接続ハンドラ (進行ステータス実況マウント・完全開通版)
 */
function handleChat(e) {
    if (e) e.preventDefault();
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
    const advancedReasoning = advancedReasoningMode ? advancedReasoningMode.checked : false;
    const reasoningId = advancedReasoning ? generateUUID() : null;
    
    const targetMessageId = 'ai-msg-' + generateUUID();
    
    appendMsg('user', msg); 
    input.value = '';
    input.style.height = 'auto'; 
    setFormDisabled(true); 

    const tempId = 'loading-' + Date.now();
    const chatBox = document.getElementById('chat-box');
    if (!chatBox) {
        setFormDisabled(false);
        return;
    }

    const loadingText = advancedReasoning 
        ? '🧠 質問の意図を分析し、最適な多段階集計・検証シナリオを構築しています...' 
        : '🔍 関連ドキュメントのベクトル類似度検索を実行しています...';

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
            <div class="bg-white border border-slate-200 text-slate-400 rounded-2xl rounded-tl-none p-3.5 text-xs shadow-sm font-semibold flex items-center gap-2 leading-relaxed">
                <span class="inline-block w-3 h-3 border-2 border-[#4F5D95] border-t-transparent rounded-full animate-spin"></span>
                <span class="status-msg-holder">${loadingText}</span>
            </div>
        </div>
    `;
    chatBox.appendChild(loadingDiv); 

    let bubbleCreated = false;
    let streamContent = "";

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
                    advanced_reasoning_id: reasoningId
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
                        // 🧠 【新仕様】type === 'status' パケットのリアルタイム数珠繋ぎ
                        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                        if (sseData.type === 'status') {
                            if (!bubbleCreated) {
                                document.getElementById(tempId)?.remove(); 
                                aiRenderer.createMessageBubble(targetMessageId, 'assistant'); 
                                
                                // ① 空の吹き出しの中に、思考ログ実況専用のコンテナを動的生成・インジェクト
                                const targetMsgEl = document.getElementById(targetMessageId);
                                const textBody = targetMsgEl?.querySelector('.ai-text-body');
                                if (textBody) {
                                    const logContainer = document.createElement('div');
                                    logContainer.className = 'ai-thought-log';
                                    logContainer.style.cssText = 'background: #f4f6f8; border-left: 4px solid #007bff; padding: 10px; margin-bottom: 15px; font-family: monospace; font-size: 0.85em; border-radius: 4px; white-space: pre-wrap; line-height: 1.5; color: #495057;';
                                    textBody.appendChild(logContainer);
                                }
                                bubbleCreated = true;
                            }
                            
                            // ② コンテナ内部へタイムスタンプ付き実況メッセージを数珠繋ぎで改行追記（Append）
                            const targetMsgEl = document.getElementById(targetMessageId);
                            const logBox = targetMsgEl?.querySelector('.ai-thought-log');
                            if (logBox) {
                                const ts = getLogTimestamp();
                                const newLogLine = document.createTextNode(`${ts} ${sseData.message}\n`);
                                logBox.appendChild(newLogLine);
                            }

                            // 既存の4段階ステップインジケーターの進捗同期も同時に維持（デグレ防止）
                            const currentStep = sseData.step || 1;
                            aiRenderer.updateStatusStep(targetMessageId, currentStep, sseData.message);
                            scrollToBottom();

                        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                        // 🥞 【新仕様】type === 'chunk' 受信時の調停レンダリング
                        // ━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
                        } else if (sseData.type === 'chunk') {
                            if (!bubbleCreated) {
                                document.getElementById(tempId)?.remove();
                                aiRenderer.createMessageBubble(targetMessageId, 'assistant');
                                bubbleCreated = true;
                            }

                            streamContent += normalizeAiText(sseData.text ?? sseData.word ?? '');
                            
                            // 思考ログコンテナは上部に残したまま、その下部にMarkdown本文を美しくストリームレンダリング
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

                        } else if (sseData.type === 'result') {
                            document.getElementById(tempId)?.remove();
                            
                            if (sseData.status === 'success') {
                                if (!bubbleCreated) {
                                    aiRenderer.createMessageBubble(targetMessageId, 'assistant');
                                    bubbleCreated = true;
                                }
                                
                                // オブジェクト直撃バグの完全閉塞ガード層
                                let finalReportText = normalizeAiText(sseData.response);
                                
                                const existingBubble = document.getElementById(targetMessageId);
                                const liveLogText = existingBubble?.querySelector('.ai-thought-log')?.textContent || '';

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
                                                ⚙️ バックエンド自律推論ステータス実況ログを表示
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
                                    
                                    renderChartsInContainer(bubbleContainer.parentElement);
                                    bindChartModalEvents(bubbleContainer.parentElement);
                                    renderMermaidInContainer(bubbleContainer.parentElement);
                                }
                            } else {
                                const errResponse = `⚠️ **エラーが発生しました**\n\n${sseData.error || "回答を生成できませんでした。もう一度お試しください。"}`;
                                if (bubbleCreated) {
                                    aiRenderer.finalize(targetMessageId, errResponse);
                                } else {
                                    appendMsg('assistant', errResponse);
                                }
                            }

                        } else if (sseData.type === 'result' && sseData.status === 'error') {
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
        } finally {
            setFormDisabled(false); 
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
