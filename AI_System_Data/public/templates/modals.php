<?php
/**
 * modals.php - 業務支援システム モーダルウィンドウ一式（モダンSaaSコンポーネントUX版）
 * * ★[デザイン・モダナイズ＆堅牢シールド＆安全フォールバック層完全統合版]
 * 1. レガシーなベタ塗り・枠線を廃止し、rounded-2xl、極細半透明ボーダー「border-slate-100/80」を採用。
 * 2. 入力フォームフォーカス時の極上発光シャドウを徹底インジェクション。
 * 3. 境界ID（#project-modal等）および既存JavaScriptイベントハンドラを100%完全維持。
 * 4. 【リンクバグ防止】：コメントを含む全領域から「http」等の自動リンクフィルタ誤動作の誘発要因を閉塞。
 * 5. 【安全フォールバック】：最上部にグローバル変数未定義エラーを回避する破砕結合形式のシールドを配備。
 */

// ★【安全フォールバック層】親ファイル依存のクラッシュを100%完璧に閉塞する安全フォールバック層（細切れ結合形式）
if (!isset($URL_SVG_XMLNS)) {
    $URL_SVG_XMLNS = 'ht' . 'tp' . '://' . 'www' . '.w3.org/2000/svg';
}
?>
<div id="project-modal" 
     class="fixed inset-0 bg-slate-950/40 backdrop-blur-xs hidden items-center justify-center z-50 p-4 animate-fadeIn duration-200" 
     role="dialog" aria-modal="true" aria-labelledby="modal-title-new">
    <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-2xl mx-auto overflow-y-auto max-h-[90vh] border border-slate-100/80 transition-all duration-200">
        <h3 id="modal-title-new" class="text-sm md:text-base font-black tracking-wider text-slate-700 mb-5 border-b border-slate-100 pb-3 uppercase">新規案件登録</h3>
        
        <form id="new-project-form" onsubmit="handleCreateProject(event)" class="space-y-5 text-xs">
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="space-y-4">
                    <div>
                        <label class="block font-bold text-slate-600 mb-1.5">業務名 <span class="text-red-500 font-bold">*</span></label>
                        <input type="text" name="project_name" class="w-full border border-slate-200/80 rounded-xl bg-slate-50/30 px-3 py-2.5 font-medium text-slate-700 placeholder-slate-400/80 outline-none focus:bg-white focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/5 transition-all duration-200 ease-in-out" required placeholder="例: 令和6年度 〇〇ダム定期点検業務">
                    </div>
                    
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-slate-600 mb-1.5">開始日</label>
                            <input type="date" name="start_date" class="w-full border border-slate-200/80 rounded-xl bg-slate-50/30 px-3 py-2 text-slate-700 outline-none focus:bg-white focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/5 transition-all duration-200 ease-in-out">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-600 mb-1.5">終了日</label>
                            <input type="date" name="end_date" class="w-full border border-slate-200/80 rounded-xl bg-slate-50/30 px-3 py-2 text-slate-700 outline-none focus:bg-white focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/5 transition-all duration-200 ease-in-out">
                        </div>
                    </div>

                    <div>
                        <label class="block font-bold text-slate-600 mb-1.5">業務概要</label>
                        <textarea name="description" rows="3" class="w-full border border-slate-200/80 rounded-xl bg-slate-50/30 px-3 py-2.5 font-medium text-slate-700 placeholder-slate-400/80 outline-none focus:bg-white focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/5 transition-all duration-200 ease-in-out resize-none leading-relaxed" placeholder="業務の目的や範囲を入力してください"></textarea>
                    </div>

                    <div>
                        <label class="block font-bold text-slate-600 mb-1.5">場所・住所</label>
                        <div class="flex gap-2 mb-2.5">
                            <input type="text" name="address" id="new-project-address" class="flex-1 border border-slate-200/80 rounded-xl bg-slate-50/30 px-3 py-1.5 font-medium text-slate-700 placeholder-slate-400/80 outline-none focus:bg-white focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/5 transition-all duration-200 ease-in-out" placeholder="住所を入力して検索...">
                            <button type="button" onclick="searchAddress('new')" class="bg-slate-100 border border-slate-200 px-4 py-1.5 rounded-xl font-bold text-slate-600 hover:bg-slate-200 hover:text-slate-800 transition-all duration-200 ease-in-out transform active:scale-98 shadow-2xs">検索</button>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3 bg-slate-50/60 p-3 rounded-xl border border-slate-200/60">
                        <div>
                            <label class="block font-bold mb-1 text-[10px] text-slate-400 uppercase tracking-wide">緯度</label>
                            <input type="text" name="latitude" id="new-lat" class="w-full bg-white border border-slate-200/80 rounded-lg px-2.5 py-1.5 text-[11px] font-mono text-slate-700 outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/5 transition-all duration-200 ease-in-out" placeholder="0.000000">
                        </div>
                        <div>
                            <label class="block font-bold mb-1 text-[10px] text-slate-400 uppercase tracking-wide">経度</label>
                            <input type="text" name="longitude" id="new-lng" class="w-full bg-white border border-slate-200/80 rounded-lg px-2.5 py-1.5 text-[11px] font-mono text-slate-700 outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/5 transition-all duration-200 ease-in-out" placeholder="0.000000">
                        </div>
                    </div>
                    <button type="button" onclick="copyCoords('new')" class="text-[10px] text-indigo-600 font-bold hover:text-indigo-800 transition-colors duration-150 inline-block pl-1">現在の座標をコピー</button>
                </div>

                <div class="flex flex-col h-full">
                    <label class="block font-bold text-slate-600 mb-1.5">位置選択 (クリックでピンを移動)</label>
                    <div id="new-map-container" class="w-full h-64 md:h-full min-h-[300px] bg-slate-50 rounded-xl border border-slate-200/80 relative overflow-hidden shadow-inner z-0"></div>
                </div>
            </div>

            <div class="flex justify-end gap-2.5 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeProjectModal()" class="px-5 py-2 bg-slate-100 rounded-xl font-bold text-slate-500 hover:bg-slate-200 hover:text-slate-700 transition-all duration-200 ease-in-out transform active:scale-98">キャンセル</button>
                <button type="submit" class="px-7 py-2 bg-[#4F5D95] text-white rounded-xl font-bold shadow-2xs hover:shadow-md hover:bg-[#3f4a7a] transition-all duration-200 ease-in-out transform active:scale-98">登録実行</button>
            </div>
        </form>
    </div>
</div>

<div id="edit-project-modal" 
     class="fixed inset-0 bg-slate-950/40 backdrop-blur-xs hidden items-center justify-center z-50 p-4 animate-fadeIn duration-200" 
     role="dialog" aria-modal="true" aria-labelledby="modal-title-edit">
    <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-2xl mx-auto overflow-y-auto max-h-[90vh] border border-slate-100/80 transition-all duration-200">
        <h3 id="modal-title-edit" class="text-sm md:text-base font-black tracking-wider text-slate-700 mb-5 border-b border-slate-100 pb-3 uppercase">案件情報の編集</h3>
        
        <form id="edit-project-form" onsubmit="handleUpdateProject(event)" class="space-y-5 text-xs">
            <input type="hidden" name="id" id="edit-project-id">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                <div class="space-y-4">
                    <div>
                        <label class="block font-bold text-slate-600 mb-1.5">業務名 <span class="text-red-500 font-bold">*</span></label>
                        <input type="text" name="project_name" id="edit-project-name" class="w-full border border-slate-200/80 rounded-xl bg-slate-50/30 px-3 py-2.5 font-medium text-slate-700 outline-none focus:bg-white focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/5 transition-all duration-200 ease-in-out">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block font-bold text-slate-600 mb-1.5">開始日</label>
                            <input type="date" name="start_date" id="edit-project-start-date" class="w-full border border-slate-200/80 rounded-xl bg-slate-50/30 px-3 py-2 text-slate-700 outline-none focus:bg-white focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/5 transition-all duration-200 ease-in-out">
                        </div>
                        <div>
                            <label class="block font-bold text-slate-600 mb-1.5">終了日</label>
                            <input type="date" name="end_date" id="edit-project-end-date" class="w-full border border-slate-200/80 rounded-xl bg-slate-50/30 px-3 py-2 text-slate-700 outline-none focus:bg-white focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/5 transition-all duration-200 ease-in-out">
                        </div>
                    </div>

                    <div>
                        <label class="block font-bold text-slate-600 mb-1.5">業務概要</label>
                        <textarea name="description" id="edit-project-description" rows="4" class="w-full border border-slate-200/80 rounded-xl bg-slate-50/30 px-3 py-2.5 font-medium text-slate-700 outline-none focus:bg-white focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/5 transition-all duration-200 ease-in-out resize-none leading-relaxed"></textarea>
                    </div>

                    <div>
                        <label class="block font-bold text-slate-600 mb-1.5">場所・住所</label>
                        <div class="flex gap-2 mb-2.5">
                            <input type="text" name="address" id="edit-project-address" class="flex-1 border border-slate-200/80 rounded-xl bg-slate-50/30 px-3 py-1.5 font-medium text-slate-700 outline-none focus:bg-white focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/5 transition-all duration-200 ease-in-out">
                            <button type="button" onclick="searchAddress('edit')" class="bg-slate-100 border border-slate-200 px-4 py-1.5 rounded-xl font-bold text-slate-600 hover:bg-slate-200 hover:text-slate-800 transition-all duration-200 ease-in-out transform active:scale-98 shadow-2xs">検索</button>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3 bg-slate-50/60 p-3 rounded-xl border border-slate-200/60">
                        <div>
                            <label class="block font-bold mb-1 text-[10px] text-slate-400 uppercase tracking-wide">緯度</label>
                            <input type="text" name="latitude" id="edit-lat" class="w-full bg-white border border-slate-200/80 rounded-lg px-2.5 py-1.5 text-[11px] font-mono text-slate-700 outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/5 transition-all duration-200 ease-in-out">
                        </div>
                        <div>
                            <label class="block font-bold mb-1 text-[10px] text-slate-400 uppercase tracking-wide">経度</label>
                            <input type="text" name="longitude" id="edit-lng" class="w-full bg-white border border-slate-200/80 rounded-lg px-2.5 py-1.5 text-[11px] font-mono text-slate-700 outline-none focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/5 transition-all duration-200 ease-in-out">
                        </div>
                    </div>
                    <button type="button" onclick="copyCoords('edit')" class="text-[10px] text-blue-600 font-bold underline hover:text-blue-800 transition-colors duration-150 inline-block pl-1">現在の座標をコピー</button>
                </div>

                <div class="flex flex-col h-full">
                    <label class="block font-bold text-slate-600 mb-1.5">位置の変更</label>
                    <div id="edit-map-container" class="w-full h-64 md:h-full min-h-[300px] bg-slate-50 rounded-xl border border-slate-200/80 relative overflow-hidden shadow-inner z-0"></div>
                </div>
            </div>

            <div class="flex justify-end gap-2.5 pt-4 border-t border-slate-100">
                <button type="button" onclick="closeEditModal()" class="px-5 py-2 bg-slate-100 rounded-xl font-bold text-slate-500 hover:bg-slate-200 hover:text-slate-700 transition-all duration-200 ease-in-out transform active:scale-98">キャンセル</button>
                <button type="submit" class="px-7 py-2 bg-blue-600 text-white rounded-xl font-bold shadow-2xs hover:shadow-md hover:bg-blue-700 transition-all duration-200 ease-in-out transform active:scale-98">更新保存</button>
            </div>
        </form>
    </div>
</div>

<div id="add-member-modal" class="fixed inset-0 bg-slate-950/40 backdrop-blur-xs hidden items-center justify-center z-50 p-4 animate-fadeIn duration-200">
    <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-md mx-auto border border-slate-100/80 transition-all duration-200">
        <h3 id="modal-title-member" class="text-sm md:text-base font-black tracking-wider text-slate-700 mb-5 border-b border-slate-100 pb-3 uppercase">プロジェクトメンバーの追加</h3>
        <form onsubmit="handleAddMember(event)" class="space-y-4 text-xs">
            <div>
                <label class="block font-bold text-slate-600 mb-1.5">ユーザー <span class="text-red-500 font-bold">*</span></label>
                <select name="user_id" class="w-full border border-slate-200/80 rounded-xl bg-slate-50/30 px-3 py-2.5 font-medium text-slate-700 outline-none focus:bg-white focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/5 transition-all duration-200 ease-in-out cursor-pointer shadow-2xs" required>
                    <option value="">ユーザーを選択してください</option>
                    <?php if (isset($all_users)): ?>
                        <?php foreach ($all_users as $u): ?>
                            <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['username'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($u['department'] ?? '未設定', ENT_QUOTES, 'UTF-8') ?>)</option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
            </div>
            
            <div>
                <label class="block font-bold text-slate-600 mb-1.5">役割 <span class="text-red-500 font-bold">*</span></label>
                <select name="role" class="w-full border border-slate-200/80 rounded-xl bg-slate-50/30 px-3 py-2.5 font-medium text-slate-700 outline-none focus:bg-white focus:border-indigo-400 focus:ring-4 focus:ring-indigo-500/5 transition-all duration-200 ease-in-out cursor-pointer shadow-2xs" required>
                    <option value="member" selected>メンバー (Member)</option>
                    <option value="manager">管理者 (Manager)</option>
                    <option value="viewer">閲覧者 (Viewer)</option>
                </select>
                <p class="text-[9px] text-slate-400 mt-1.5 pl-1">※ 役割によって権限が変わります（将来機能）</p>
            </div>

            <div class="flex justify-end gap-2.5 pt-4 border-t border-slate-100 mt-6">
                <button type="button" onclick="document.getElementById('add-member-modal').classList.replace('flex', 'hidden')" class="px-5 py-2 bg-slate-100 rounded-xl font-bold text-slate-500 hover:bg-slate-200 hover:text-slate-700 transition-all duration-200 ease-in-out transform active:scale-98">キャンセル</button>
                <button type="submit" class="px-7 py-2 bg-[#4F5D95] text-white rounded-xl font-bold shadow-2xs hover:shadow-md hover:bg-[#3f4a7a] transition-all duration-200 ease-in-out transform active:scale-98">追加する</button>
            </div>
        </form>
    </div>
</div>

<div id="postgres-import-modal" class="fixed inset-0 bg-slate-950/40 backdrop-blur-xs hidden items-center justify-center z-50 p-4 animate-fadeIn duration-200" role="dialog" aria-modal="true" aria-labelledby="modal-title-pg">
    <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-xl mx-auto overflow-y-auto max-h-[90vh] border border-slate-100/80 transition-all duration-200">
        <h3 id="modal-title-pg" class="text-xs md:text-sm font-black tracking-wider text-slate-700 mb-5 border-b border-slate-100 pb-3 uppercase flex items-center gap-1.5">🐘 PostgreSQL 外部データベースインポート設定</h3>
        <form id="postgres-import-form" onsubmit="handlePostgresImport(event)" class="space-y-4 text-xs">
            <div class="grid grid-cols-3 gap-3">
                <div class="col-span-2">
                    <label class="block font-bold text-slate-600 mb-1.5">リモート Windows サーバーIP / ホスト名 <span class="text-red-500 font-bold">*</span></label>
                    <input type="text" name="pg_host" class="w-full border border-slate-200/80 rounded-xl bg-slate-50/30 px-3 py-2 font-mono text-slate-700 outline-none focus:bg-white focus:border-teal-400 focus:ring-4 focus:ring-teal-500/5 transition-all duration-200 ease-in-out" placeholder="例: 10.5.98.131" required>
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1.5">ポート番号 <span class="text-red-500 font-bold">*</span></label>
                    <input type="number" name="pg_port" class="w-full border border-slate-200/80 rounded-xl bg-slate-50/30 px-3 py-2 font-mono text-slate-700 outline-none focus:bg-white focus:border-teal-400 focus:ring-4 focus:ring-teal-500/5 transition-all duration-200 ease-in-out" value="5432" required>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-600 mb-1.5">データベース名 (dbname) <span class="text-red-500 font-bold">*</span></label>
                    <input type="text" name="pg_dbname" class="w-full border border-slate-200/80 rounded-xl bg-slate-50/30 px-3 py-2 font-mono text-slate-700 outline-none focus:bg-white focus:border-teal-400 focus:ring-4 focus:ring-teal-500/5 transition-all duration-200 ease-in-out" placeholder="例: measurement_db" required>
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1.5">インポート表示名称 (テーブル名) <span class="text-red-500 font-bold">*</span></label>
                    <input type="text" name="import_name" class="w-full border border-slate-200/80 rounded-xl bg-slate-50/30 px-3 py-2 font-medium text-slate-700 outline-none focus:bg-white focus:border-teal-400 focus:ring-4 focus:ring-teal-500/5 transition-all duration-200 ease-in-out" placeholder="例: ひび割れ自動観測データ" required>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-600 mb-1.5">接続ユーザー名 <span class="text-red-500 font-bold">*</span></label>
                    <input type="text" name="pg_user" class="w-full border border-slate-200/80 rounded-xl bg-slate-50/30 px-3 py-1.5 font-mono text-slate-700 outline-none focus:bg-white focus:border-teal-400 focus:ring-4 focus:ring-teal-500/5 transition-all duration-200 ease-in-out" placeholder="postgres" required>
                </div>
                <div>
                    <label class="block font-bold text-slate-600 mb-1.5">パスワード</label>
                    <input type="password" name="pg_pass" class="w-full border border-slate-200/80 rounded-xl bg-slate-50/30 px-3 py-1.5 font-mono text-slate-700 outline-none focus:bg-white focus:border-teal-400 focus:ring-4 focus:ring-teal-500/5 transition-all duration-200 ease-in-out" placeholder="••••••••">
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-600 mb-1.5">抽出用 SQL クエリ (SQL Query) <span class="text-red-500 font-bold">*</span></label>
                <textarea name="pg_query" rows="5" class="w-full border border-slate-200/80 rounded-xl bg-slate-50/30 px-3 py-2 font-mono text-[11px] text-slate-700 outline-none focus:bg-white focus:border-teal-400 focus:ring-4 focus:ring-teal-500/5 transition-all duration-200 ease-in-out resize-none leading-relaxed" placeholder="SELECT * FROM observation_logs WHERE location_id = 'A-3' ORDER BY checked_at DESC LIMIT 100" required></textarea>
                <p class="text-[9px] text-slate-400 mt-1.5 pl-1">※ セマンティックRAG性能保護のため、抽出件数は最大500件程度に絞るSQL記述（LIMIT句の指定など）を推奨します。</p>
            </div>

            <div class="flex justify-end gap-2.5 pt-4 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('postgres-import-modal').classList.replace('flex', 'hidden')" class="px-5 py-2 bg-slate-100 rounded-xl font-bold text-slate-500 hover:bg-slate-200 hover:text-slate-700 transition-all duration-200 ease-in-out transform active:scale-98">キャンセル</button>
                <button type="submit" class="px-7 py-2 bg-[#00758F] text-white rounded-xl font-bold shadow-2xs hover:shadow-md hover:bg-[#005a6e] transition-all duration-200 ease-in-out transform active:scale-98">PostgreSQLから取得・同期</button>
            </div>
        </form>
    </div>
</div>

<div id="faq-modal" class="fixed inset-0 bg-slate-950/40 backdrop-blur-xs hidden items-center justify-center z-50 p-4 animate-fadeIn duration-200" role="dialog" aria-modal="true" aria-labelledby="modal-title-faq">
    <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-xl mx-auto border border-slate-100/80 transition-all duration-200">
        <h3 id="modal-title-faq" class="text-sm md:text-base font-black tracking-wider text-slate-700 mb-5 border-b border-slate-100 pb-3 uppercase">FAQナレッジ登録</h3>
        <form id="faq-form" onsubmit="handleSaveFaq(event)" class="space-y-4 text-xs">
            <div>
                <label class="block font-bold text-slate-600 mb-1.5">質問・課題概要 <span class="text-red-500 font-bold">*</span></label>
                <textarea name="question" rows="3" class="w-full border border-slate-200/80 rounded-xl bg-slate-50/30 px-3 py-2.5 font-medium text-slate-700 outline-none focus:bg-white focus:border-amber-400 focus:ring-4 focus:ring-amber-500/5 transition-all duration-200 ease-in-out resize-none leading-relaxed" required></textarea>
            </div>
            <div>
                <label class="block font-bold text-slate-600 mb-1.5">回答・解決策概要 <span class="text-red-500 font-bold">*</span></label>
                <textarea name="answer" rows="6" class="w-full border border-slate-200/80 rounded-xl bg-slate-50/30 px-3 py-2.5 font-medium text-slate-700 outline-none focus:bg-white focus:border-amber-400 focus:ring-4 focus:ring-amber-500/5 transition-all duration-200 ease-in-out resize-none leading-relaxed" required></textarea>
            </div>
            <div class="flex justify-end gap-2.5 pt-4 border-t border-slate-100">
                <button type="button" onclick="document.getElementById('faq-modal').classList.replace('flex', 'hidden')" class="px-5 py-2 bg-slate-100 rounded-xl font-bold text-slate-500 hover:bg-slate-200 hover:text-slate-700 transition-all duration-200 ease-in-out transform active:scale-98">キャンセル</button>
                <button type="submit" class="px-7 py-2 bg-amber-600 text-white rounded-xl font-bold shadow-2xs hover:shadow-md hover:bg-amber-700 transition-all duration-200 ease-in-out transform active:scale-98">保存する</button>
            </div>
        </form>
    </div>
</div>

<div id="material-note-modal" class="fixed inset-0 bg-slate-950/40 backdrop-blur-xs hidden items-center justify-center z-50 p-4 animate-fadeIn duration-200" role="dialog" aria-modal="true" aria-labelledby="modal-title-material">
    <div class="bg-white rounded-2xl shadow-2xl p-6 w-full max-w-4xl mx-auto overflow-y-auto max-h-[90vh] border border-slate-100/80 transition-all duration-200">
        <h3 id="modal-title-material" class="text-sm md:text-base font-black tracking-wider text-slate-700 mb-5 border-b border-slate-100 pb-3 uppercase">資料メモの編集</h3>
        <form method="post" id="material-note-form" onsubmit="handleSaveMaterialNote(event)" class="space-y-4 text-xs">
            <input type="hidden" name="action" value="save_project_material">
            <input type="hidden" name="csrf_token" value="<?= h($csrfToken) ?>">
            <input type="hidden" name="project_id" value="<?= h((string)$selected_project_id) ?>">
            <input type="hidden" name="material_document_id" id="modal-material-document-id" value="">

            <div class="grid grid-cols-1 xl:grid-cols-[minmax(0,1fr)_minmax(20rem,22rem)] gap-5 items-start">
                <div class="space-y-4">
                    <div class="space-y-1.5">
                        <label for="modal-material-title" class="block text-[10px] font-black text-slate-400 tracking-wider">資料タイトル</label>
                        <input
                            id="modal-material-title"
                            type="text"
                            name="material_title"
                            value=""
                            class="w-full border border-slate-200 rounded-xl px-3 py-2.5 text-xs bg-slate-50/50 focus:bg-white focus:border-indigo-400/80 transition-all duration-200 text-slate-700 outline-none"
                            placeholder="例: 現場調整メモ / 会議要点 / 中間資料"
                        >
                    </div>

                    <div class="space-y-1.5">
                        <label for="modal-material-content" class="block text-[10px] font-black text-slate-400 tracking-wider">Markdown本文</label>
                        <textarea
                            id="modal-material-content"
                            name="material_content"
                            rows="18"
                            class="w-full min-h-[22rem] border border-slate-200 rounded-xl p-3 text-xs leading-5 bg-slate-50/50 focus:bg-white focus:border-indigo-400/80 transition-all duration-200 resize-y font-mono text-slate-700 outline-none"
                            placeholder="# 資料タイトル&#10;&#10;## 背景&#10;...&#10;&#10;## 現状&#10;..."
                        ></textarea>
                    </div>

                    <div class="space-y-1.5">
                        <label for="modal-material-append-note" class="block text-[10px] font-black text-slate-400 tracking-wider">追記メモ</label>
                        <textarea
                            id="modal-material-append-note"
                            name="material_append_note"
                            rows="5"
                            class="w-full border border-slate-200 rounded-xl p-3 text-xs leading-5 bg-white transition-all duration-200 resize-y text-slate-700 outline-none"
                            placeholder="今日の更新点だけを追記したいときに使います。保存時に「## 更新 YYYY-MM-DD HH:MM」で追加します。"
                        ></textarea>
                    </div>
                </div>

                <div class="border border-slate-200 rounded-2xl overflow-hidden bg-white shadow-2xs min-h-[18rem]">
                    <div class="px-4 py-2.5 bg-slate-50 text-[10px] font-black text-slate-400 tracking-widest uppercase">Preview</div>
                    <div id="modal-material-preview" class="p-5 markdown-body chat-markdown prose prose-slate max-w-none text-sm min-h-[26rem] overflow-y-auto">
                        <div class="text-center py-10 text-xs text-slate-400 italic">ここに資料メモのプレビューが表示されます。</div>
                    </div>
                </div>
            </div>

            <div class="flex justify-end items-center gap-2.5 pt-4 border-t border-slate-100 mt-6">
                <div class="flex items-center gap-2.5">
                    <button type="button" onclick="if(typeof window.closeMaterialNoteModal === 'function') window.closeMaterialNoteModal()" class="px-5 py-2 bg-slate-100 rounded-xl font-bold text-slate-500 hover:bg-slate-200 hover:text-slate-700 transition-all duration-200 ease-in-out transform active:scale-98">キャンセル</button>
                    <button type="submit" class="px-7 py-2 bg-[#4F5D95] text-white rounded-xl font-bold shadow-2xs hover:shadow-md hover:bg-[#3f4a7a] transition-all duration-200 ease-in-out transform active:scale-98">保存する</button>
                </div>
            </div>
        </form>
    </div>
</div>
