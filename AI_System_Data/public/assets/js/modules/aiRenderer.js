/**
 * aiRenderer.js - AIメッセージ描画・リアルタイムグラフ生成・マークダウン美化専門モジュール
 * ★[4段階自律巡回進行インジケーター ＆ リアルタイムリレーマウント統合・UX超進化版]
 */

export class AiRenderer {
    /**
     * コンストラクタ
     * @param {string} chatBoxId チャットログが流れるスクロールコンテナの要素ID
     */
    constructor(chatBoxId) {
        this.chatBox = document.getElementById(chatBoxId);
        this.activeCharts = {}; // メモリリークおよび二重描画を防ぐためのインスタンス管理オブジェクト
        this.mermaidInitialized = false;
        this.themeColors = ['#4F5D95', '#10b981', '#f59e0b', '#3b82f6', '#ef4444', '#8b5cf6'];
        
        // 生テキストのバッククォート3連記号を完全に排除するための動的フェンス定義
        this.fence = "\x60".repeat(3);
    }

    /**
     * メッセージ表示用の器（バブルコンテナ）の生成と初期強制スクロールの融合
     * @param {string} messageId 一意のメッセージID
     * @param {string} role 'user' または 'assistant'
     */
    createMessageBubble(messageId, role) {
        if (!this.chatBox || document.getElementById(messageId)) return;

        const div = document.createElement('div');
        div.id = messageId;
        
        const isAsst = (role === 'assistant');
        div.className = `flex gap-3 items-start ${isAsst ? '' : 'flex-row-reverse'} animate-fadeIn mb-4 will-change-[height,transform] relative transition-all duration-150`;

        // 日時文字列の即時生成
        const now = new Date();
        const timeStr = `${now.getFullYear()}/${String(now.getMonth() + 1).padStart(2, '0')}/${String(now.getDate()).padStart(2, '0')} ${String(now.getHours()).padStart(2, '0')}:${String(now.getMinutes()).padStart(2, '0')}`;

        const avatarHtml = `
            <div class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0 shadow-sm border select-none
                ${isAsst ? 'bg-amber-50 text-amber-700 border-amber-200/60' : 'bg-indigo-100 text-indigo-700 border-indigo-200/60'}">
                ${isAsst ? '🤖' : '👤'}
            </div>
        `;

        div.innerHTML = `
            ${avatarHtml}
            <div class="chat-message-stack flex flex-col ${isAsst ? 'items-start' : 'items-end'} gap-0.5 w-full">
                <div class="flex items-center gap-1.5 px-1">
                    <span class="text-[9px] font-black text-slate-500 uppercase tracking-tight">${isAsst ? 'AI Assistant' : 'You'}</span>
                    <span class="text-[8px] text-slate-400 font-mono tracking-tighter">${timeStr}</span>
                </div>
                <div class="chat-message-bubble chat-message-body ${isAsst ? 'chat-assistant rounded-tl-none border-slate-200/80' : 'chat-user rounded-tr-none border-[#3d4975] shadow-md'} p-4 shadow-sm will-change-[height,transform]">
                    
                    <div class="ai-progress-container w-full bg-slate-50 border border-slate-100 rounded-xl p-4 my-2 transition-all duration-300">
                        <div class="flex justify-between items-center text-[10px] text-slate-400 font-bold mb-3.5 px-1">
                            <span class="flex items-center gap-1 text-[#4F5D95] font-black"><span class="w-2 h-2 rounded-full bg-[#4F5D95] animate-pulse"></span> マルチエージェント自律推論プロトコル稼働中</span>
                            <span class="progress-status-text font-medium text-slate-400 italic">準備中...</span>
                        </div>
                        
                        <div class="relative w-full h-1 bg-slate-200 rounded-full mb-3 overflow-hidden">
                            <div class="progress-gauge-bar absolute top-0 left-0 h-full bg-gradient-to-r from-indigo-500 to-[#4F5D95] w-[0%] transition-all duration-500 ease-in-out"></div>
                        </div>
                        
                        <div class="flex justify-between items-start relative w-full text-center">
                            <div class="step-node-1 flex flex-col items-center flex-1">
                                <div class="step-dot w-5 h-5 rounded-full border bg-white flex items-center justify-center font-bold text-[9px] text-slate-400 transition-all duration-300 relative">1</div>
                                <span class="text-[8px] font-black text-slate-400 mt-1.5 whitespace-nowrap">🧠 要求分解</span>
                            </div>
                            <div class="step-node-2 flex flex-col items-center flex-1">
                                <div class="step-dot w-5 h-5 rounded-full border bg-white flex items-center justify-center font-bold text-[9px] text-slate-400 transition-all duration-300 relative">2</div>
                                <span class="text-[8px] font-black text-slate-400 mt-1.5 whitespace-nowrap">🔬 SQL構築</span>
                            </div>
                            <div class="step-node-3 flex flex-col items-center flex-1">
                                <div class="step-dot w-5 h-5 rounded-full border bg-white flex items-center justify-center font-bold text-[9px] text-slate-400 transition-all duration-300 relative">3</div>
                                <span class="text-[8px] font-black text-slate-400 mt-1.5 whitespace-nowrap">🛡️ 安全監査</span>
                            </div>
                            <div class="step-node-4 flex flex-col items-center flex-1">
                                <div class="step-dot w-5 h-5 rounded-full border bg-white flex items-center justify-center font-bold text-[9px] text-slate-400 transition-all duration-300 relative">4</div>
                                <span class="text-[8px] font-black text-slate-400 mt-1.5 whitespace-nowrap">📊 考察生成</span>
                            </div>
                        </div>
                    </div>

                    <div class="ai-text-body markdown-body chat-markdown w-full will-change-[height,transform] transition-all duration-150"></div>
                    <div class="chat-inline-status hidden mt-3 w-full items-center justify-between gap-3 rounded-xl border px-3 py-2 text-[10px] shadow-xs"></div>
                </div>
            </div>
        `;

        this.chatBox.appendChild(div);
        this.smartScroll(true);
    }

    /**
     * 新設更新メソッド：chat.js等からステップ番号を受け取り進行状況をスピナー・鼓動明滅エフェクトで超絶進化制御
     * @param {string} messageId 対象のメッセージID
     * @param {number} stepNumber 現在のステップ番号（1〜4）
     * @param {string} statusMessage 進行状況に添える日本語のアナウンスメッセージ
     */
    updateStatusStep(messageId, stepNumber, statusMessage) {
        const bubble = document.getElementById(messageId);
        if (!bubble) return;

        const container = bubble.querySelector('.ai-progress-container');
        if (!container) return;

        // ステータステキストの更新
        const statusText = container.querySelector('.progress-status-text');
        if (statusText) statusText.textContent = statusMessage;

        // プログレスバーのゲージの横幅を滑らかに伸長（width: 0% ➔ 33.3% ➔ 66.6% ➔ 100%）
        const gaugeBar = container.querySelector('.progress-gauge-bar');
        if (gaugeBar) {
            const widthPercentage = Math.min(Math.max((stepNumber - 1) * 33.333, 0), 100);
            gaugeBar.style.width = `${widthPercentage}%`;
        }

        // 各ドットの状態を「Success(完了)」「Active(進行中スピナー)」「Waiting(未着手)」に動的クラス切り替え
        for (let i = 1; i <= 4; i++) {
            const node = container.querySelector(`.step-node-${i}`);
            if (!node) continue;

            const dot = node.querySelector('.step-dot');
            const label = node.querySelector('span');
            if (!dot || !label) continue;

            if (i < stepNumber) {
                // 【完了（Success）】状態：鮮やかなインディゴ反転、チェックマーク変換
                dot.className = "step-dot w-5 h-5 rounded-full bg-indigo-600 border-indigo-600 text-white flex items-center justify-center font-black text-[9px] shadow-2xs scale-102 transition-all duration-300 animate-none";
                dot.innerHTML = "✓";
                node.querySelector('span').className = "text-[8px] font-black text-indigo-600 mt-1.5 whitespace-nowrap transition-colors duration-300";
            } else if (i === stepNumber) {
                // 【進行中（Active）】状態のスピナー・サークルインジケーターの超進化マウント
                dot.className = "step-dot w-5 h-5 rounded-full bg-white border-2 border-[#4F5D95] text-[#4F5D95] flex items-center justify-center font-black text-[10px] shadow-2xs scale-105 transition-all duration-300";
                
                // 外側に滑らかなローディング回転サークルと、内側に脈打つパルスシールドを多重配置
                dot.innerHTML = `
                    <span class="absolute inset-0 w-full h-full rounded-full border-2 border-[#4F5D95] border-t-transparent animate-spin"></span>
                    <span class="absolute inset-0 w-full h-full rounded-full bg-[#4F5D95]/10 animate-ping opacity-75"></span>
                    <span class="relative z-10 animate-pulse-soft text-[9px] text-[#4F5D95]">${i}</span>
                `;
                node.querySelector('span').className = "text-[8px] font-black text-slate-800 mt-1.5 whitespace-nowrap transition-colors duration-300 animate-pulse-soft";
            } else {
                // 【未着手（Waiting）】状態：薄いグレーを維持
                dot.className = "step-dot w-5 h-5 rounded-full bg-white border-slate-200 text-slate-300 flex items-center justify-center font-bold text-[9px] transition-all duration-300 animate-none";
                dot.innerHTML = i;
                node.querySelector('span').className = "text-[8px] font-black text-slate-400 mt-1.5 whitespace-nowrap transition-colors duration-300";
            }
        }

        this.smartScroll();
    }

    /**
     * カスタムレンダラーを内包したマークダウン生成（ID固定化プロトコル）
     * @param {string} text パース対象のテキスト（累積または確定）
     * @param {string} messageId 不変の Canvas ID を紐づけるためのメッセージID
     * @param {boolean} isFinal 最終確定時に強固なサニタイズを実行するためのフラグ（デフォルト: false）
     */
    parseMarkdown(text, messageId, isFinal = false) {
        if (typeof marked === 'undefined') return text;

        const renderer = new marked.Renderer();
        let chartIndex = 0;
        let mermaidIndex = 0;
        const self = this;

        renderer.code = function(code, infostring, escaped) {
            const normalized = self.normalizeMarkedCodeArgs(code, infostring);
            code = normalized.code;
            const info = normalized.info;
            if (info === 'json:chart' || info === 'json:chart_data') {
                chartIndex++;
                const canvasId = `canvas-${messageId}-${chartIndex}`;

                try {
                    let sanitizedJson = code.trim();
                    if (!sanitizedJson.endsWith('}')) {
                        JSON.parse(sanitizedJson + '}'); 
                        sanitizedJson += '}';
                    }

                    const config = self.normalizeChartConfig(JSON.parse(sanitizedJson));
                    if (!config.type || !Array.isArray(config.labels) || !Array.isArray(config.datasets)) {
                        throw new Error("Incomplete JSON Structure");
                    }

                    return `
                        <div class="chart-card-wrapper cursor-pointer my-3" data-canvas-id="${canvasId}" data-chart-config="${self.escapeHTML(sanitizedJson)}">
                            <div class="text-[9px] text-slate-400 font-bold mb-2.5 select-none flex justify-between items-center pr-1">
                                <span class="flex items-center gap-1">📊 Chart.js 自律視覚化グラフ</span>
                                <span class="text-[8px] bg-indigo-50 text-indigo-600 font-black px-1.5 py-0.5 rounded-md border border-indigo-100">💡 ダブルクリックで拡大</span>
                            </div>
                            <div class="relative w-full h-52">
                                <canvas id="${canvasId}"></canvas>
                            </div>
                        </div>
                    `;
                } catch (e) {
                    return `
                        <div class="border border-dashed border-slate-200 rounded-xl p-5 bg-slate-50/50 flex flex-col items-center justify-center gap-2 select-none animate-pulse-soft my-3">
                            <div class="flex items-center gap-2 text-slate-500 font-medium text-[11px]">
                                <span class="inline-block w-3 h-3 border-2 border-[#4F5D95] border-t-transparent rounded-full animate-spin"></span>
                                <span class="stream-wait-msg">📊 分析データから高度なグラフを展開中...</span>
                            </div>
                        </div>
                    `;
                }
            }
            if (info === 'mermaid') {
                mermaidIndex++;
                const mermaidId = `mermaid-${messageId}-${mermaidIndex}`;
                return `
                    <div class="mermaid-card-wrapper my-3 rounded-xl border border-slate-200/80 bg-white p-3 shadow-xs" data-mermaid-id="${mermaidId}" data-mermaid-source="${self.escapeHTML(code.trim())}">
                        <div class="text-[9px] text-slate-400 font-bold mb-2.5 select-none flex items-center gap-1">🧭 Mermaid 図表</div>
                        <div id="${mermaidId}" class="mermaid-render-target overflow-x-auto text-center text-[10px] text-slate-400 py-2">図表を描画中...</div>
                    </div>
                `;
            }
            return `<pre class="bg-slate-50 p-3 rounded-xl border border-slate-200/60 overflow-x-auto my-2"><code class="text-[11px] font-mono text-slate-700">${self.escapeHTML(code)}</code></pre>`;
        };

        const rawHtml = marked.parse(text, { breaks: true, renderer: renderer });
        
        // ✨【修正項目1】二段階サニタイズ制御：ストリームの途中（isFinal=false）はそのまま返し、最終確定の瞬間のみDOMPurifyを実行
        if (isFinal === true && typeof DOMPurify !== 'undefined') {
            return DOMPurify.sanitize(rawHtml, {
                ADD_TAGS: ['canvas'],
                ADD_ATTR: ['data-chart-id', 'data-canvas-id', 'data-chart-config', 'data-mermaid-id', 'data-mermaid-source']
            });
        }
        return rawHtml;
    }

    /**
     * ストリームロジックの簡素化とジャストインタイム（JIT）自動マウント
     * @param {string} messageId 対象のメッセージID
     * @param {string} cumulativeText 1文字ずつ蓄積されていく累積テキスト
     */
    renderStream(messageId, cumulativeText) {
        const bubble = document.getElementById(messageId);
        if (!bubble) return;

        // 文字が流れ始めた瞬間に、先行配置していた進行インジケーター領域をフワッとフェードアウト消去してバトンタッチ
        const progressContainer = bubble.querySelector('.ai-progress-container');
        if (progressContainer) {
            progressContainer.style.opacity = '0';
            progressContainer.style.transform = 'translateY(-4px)';
            progressContainer.style.marginTop = '-12px';
            setTimeout(() => {
                if (progressContainer.parentNode) {
                    progressContainer.remove();
                }
            }, 300); // Tailwindアニメーションの時間に追従させて消滅
        }

        const textBody = bubble.querySelector('.ai-text-body');
        if (!textBody) return;

        // ストリーム中はフラグが渡されない（isFinal = false）ため、過剰防衛サニタイズを完全にバイパス開通
        textBody.innerHTML = this.parseMarkdown(cumulativeText, messageId);
        this.mountCharts(textBody);
        this.mountMermaid(textBody);

        this.smartScroll();
    }

    setInlineStatus(messageId, statusMessage, variant = 'processing', elapsed = 0) {
        const bubble = document.getElementById(messageId);
        if (!bubble) return;

        const statusEl = bubble.querySelector('.chat-inline-status');
        if (!statusEl) return;
        statusEl.className = 'chat-inline-status mt-3 flex w-full items-center justify-end gap-2 text-[9px] text-slate-400';
        statusEl.innerHTML = `
            <span class="font-bold tracking-tight">経過時間</span>
            <span class="shrink-0 font-mono">${elapsed}s</span>
        `;
    }

    clearInlineStatus(messageId) {
        const bubble = document.getElementById(messageId);
        if (!bubble) return;

        const statusEl = bubble.querySelector('.chat-inline-status');
        if (!statusEl) return;

        statusEl.className = 'chat-inline-status hidden mt-3 w-full items-center justify-between gap-3 rounded-xl border px-3 py-2 text-[10px] shadow-xs';
        statusEl.innerHTML = '';
    }

    /**
     * 通信完了（result）イベント受信時の最終確定プロトコル
     * @param {string} messageId 対象のメッセージID
     * @param {string} finalText サーバーから返却された最終確定レポート文字列
     */
    finalize(messageId, finalText) {
        // 1. まずはストリームと同じ仕様で描画（進行インジケーターのパージ等を連動）
        this.renderStream(messageId, finalText);
        
        // 2. ✨【修正項目2】最終確定した器（textBody）に対してのみ、isFinal = true を指定して強固なサニタイズを上書き執行！
        const bubble = document.getElementById(messageId);
        if (bubble) {
            const textBody = bubble.querySelector('.ai-text-body');
            if (textBody) {
                textBody.innerHTML = this.parseMarkdown(finalText, messageId, true);
                this.mountCharts(textBody);
                this.mountMermaid(textBody);
            }

            // ダブルクリックズームモーダル用イベントハンドラを正確にバインドマウント
            bubble.querySelectorAll('.chart-card-wrapper').forEach(wrapper => {
                if (wrapper.dataset.dblclickBound) return;
                wrapper.dataset.dblclickBound = "true";
                
                wrapper.addEventListener('dblclick', () => {
                    if (typeof window.launchChartZoomModal === 'function') {
                        window.launchChartZoomModal(wrapper.dataset.chartConfig);
                    } else {
                        this.fallbackZoomModal(wrapper.dataset.chartConfig);
                    }
                });
            });
        }
        
        this.smartScroll();
    }

    mountCharts(container) {
        if (!container) return;

        container.querySelectorAll('.chart-card-wrapper').forEach(wrapper => {
            const canvasId = wrapper.dataset.canvasId || wrapper.dataset.chartId;
            const configStr = wrapper.dataset.chartConfig;
            const canvasElement = document.getElementById(canvasId);
            if (!canvasElement || !configStr) return;

            try {
                const config = JSON.parse(configStr);
                if (this.activeCharts[canvasId]) {
                    this.activeCharts[canvasId].destroy();
                    delete this.activeCharts[canvasId];
                }

                const ctx = canvasElement.getContext('2d');
                this.activeCharts[canvasId] = new Chart(ctx, this.buildChartJSConfig(config));
                window.chartInstances = window.chartInstances || {};
                window.chartInstances[canvasId] = this.activeCharts[canvasId];
            } catch (err) {
                console.error("Final Chart Mount Error:", err);
            }
        });
    }

    mountMermaid(container) {
        if (!container || typeof mermaid === 'undefined') return;

        if (!this.mermaidInitialized) {
            try {
                if (typeof window.__tepscoInitMermaid === 'function') {
                    if (!window.__tepscoInitMermaid()) return;
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
                this.mermaidInitialized = true;
            } catch (err) {
                console.error("Mermaid Initialize Error:", err);
                return;
            }
        }

        container.querySelectorAll('.mermaid-card-wrapper').forEach(wrapper => {
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
                    console.warn("Mermaid Render Skipped:", err && err.message ? err.message : err);
                });
        });
    }

    /**
     * Chart.js の設定オブジェクト構造体を動的ビルド
     */
    buildChartJSConfig(config) {
        config = this.normalizeChartConfig(config);
        const isLine = config.type === 'line';
        const isPie = config.type === 'pie';

        const datasets = (config.datasets || []).map(ds => ({
            label: ds.label || '集計値',
            data: ds.data || [],
            backgroundColor: isPie ? this.themeColors : (isLine ? 'rgba(79, 93, 149, 0.08)' : '#4F5D95'),
            borderColor: '#4F5D95',
            borderWidth: isLine ? 2.5 : 0,
            borderRadius: isPie ? 0 : 6,
            tension: isLine ? 0.35 : 0,
            fill: isLine,
            pointBackgroundColor: '#4F5D95',
            pointBorderColor: '#ffffff',
            pointRadius: isLine ? 4 : 0
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
                        labels: { font: { size: 9, weight: 'bold' }, boxWidth: 12 }
                    }
                },
                scales: isPie ? {} : {
                    x: { grid: { display: false }, ticks: { font: { size: 8, weight: 'bold' }, color: '#64748b' } },
                    y: { grid: { color: '#f1f5f9' }, ticks: { font: { size: 8, weight: 'bold' }, color: '#64748b' } }
                }
            }
        };
    }

    /**
     * 手動スクロールを阻害しない商用AIレベルのインテリジェント底吸い付き自動スクロール近代化
     * @param {boolean} force 強制スクロールの有無フラグ
     */
    smartScroll(force = false) {
        if (!this.chatBox) return;

        // 新規送信時などの強制スクロール指定時は無条件で最下部へ瞬時ジャンプ
        if (force === true) {
            this.chatBox.scrollTop = this.chatBox.scrollHeight;
            return;
        }

        // 現在位置が「底から100px以内」であるかを判定（底付近にいるかどうかの境界線）
        const isNearBottom = (this.chatBox.scrollHeight - this.chatBox.scrollTop - this.chatBox.clientHeight) < 100;
        
        // スクロールバーが最下部付近にある場合のみ、磁石のように滑らかなスムーズスクロールで追従させる。
        if (isNearBottom) {
            this.chatBox.scrollTo({
                top: this.chatBox.scrollHeight,
                behavior: 'smooth'
            });
        }
    }

    /**
     * 特殊文字エスケープ
     */
    escapeHTML(str) {
        return String(str)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#39;');
    }

    normalizeMarkedCodeArgs(code, infostring = '') {
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

    normalizeChartConfig(config) {
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
     * モーダル未ロード時のフォールバック
     */
    fallbackZoomModal(configStr) {
        try {
            const config = JSON.parse(configStr);
            alert(`【グラフデータ簡易表示】\nタイトル: ${config.title}\n項目: ${config.labels.join(', ')}`);
        } catch (e) {
            console.error(e);
        }
    }
}
