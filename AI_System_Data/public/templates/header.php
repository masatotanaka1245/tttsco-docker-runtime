<?php
/**
 * 共通ヘッダーコンポーネント (ユーザー設定機能 統合版)
 * 権限に基づいたナビゲーション制御および個人設定モーダルを含む
 */
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../src/ModelRoleResolver.php';
require_once __DIR__ . '/../../src/UserSettingsSchema.php';

$current_page = basename($_SERVER['PHP_SELF']);
$username = $_SESSION['username'] ?? 'ゲスト';
$dept = $_SESSION['department'] ?? '未設定';
$role = $_SESSION['role'] ?? 'user';
$userId = $_SESSION['user_id'] ?? 0;

// ユーザー設定の取得 (DBから最新の状態を反映、未設定時のデフォルト値も定義)
$modelDefaults = ModelRoleResolver::defaults();
$userSettings = [
    'default_prompt' => 'construction_consultant',
    'default_lang'   => 'ja',
    'default_model'  => $modelDefaults['main_model'],
    'sub_model'      => $modelDefaults['sub_model'],
    'embedding_model' => $modelDefaults['embedding_model'],
    'ollama_host'    => $_SESSION['ollama_host'] ?? $modelDefaults['ollama_host']
];
$hasEmbeddingModelColumn = UserSettingsSchema::hasEmbeddingModelColumn($pdo);

if ($userId) {
    try {
        $selectColumns = 'default_prompt, default_lang, default_model, sub_model, ollama_host';
        if ($hasEmbeddingModelColumn) {
            $selectColumns .= ', embedding_model';
        }
        $stmtSet = $pdo->prepare("SELECT {$selectColumns} FROM users WHERE id = ?");
        $stmtSet->execute([$userId]);
        $dbSettings = $stmtSet->fetch(PDO::FETCH_ASSOC);
        if ($dbSettings) {
            $userSettings = array_merge($userSettings, array_filter($dbSettings, function($v) { return $v !== null && $v !== ''; }));
            
            // 取得した設定をセッションにも同期させて、API側で利用できるようにする
            $_SESSION['default_prompt'] = $userSettings['default_prompt'];
            $_SESSION['default_lang']   = $userSettings['default_lang'];
            $_SESSION['default_model']  = $userSettings['default_model'];
            $_SESSION['sub_model']      = $userSettings['sub_model'];
            $_SESSION['embedding_model'] = $userSettings['embedding_model'];
            $_SESSION['ollama_host']    = $userSettings['ollama_host'];
        }
    } catch (PDOException $e) {
        // エラー時はデフォルトを使用
    }
}

// アクティブページの判定用関数
if (!function_exists('isActive')) {
    function isActive($page, $current_page) {
        return $page === $current_page ? 'border-b-2 border-white pb-1 font-bold opacity-100' : 'opacity-70 hover:opacity-100 transition';
    }
}
?>
<header class="bg-[#4F5D95] text-white p-4 shadow-lg z-30">
    <div class="max-w-[1600px] mx-auto flex items-center justify-between">
        <div class="flex items-center gap-6">
            <a href="index.php" class="font-black tracking-widest text-xl hover:opacity-80 transition">TEPSCO Routines</a>
            <div class="h-8 w-px bg-white/20"></div>
            
            <nav class="flex gap-8 text-sm font-medium items-center">
                <a href="index.php" class="<?= isActive('index.php', $current_page) ?>">ダッシュボード</a>
                <a href="support.php" class="<?= isActive('support.php', $current_page) ?>">業務支援</a>
                <a href="search.php" class="<?= isActive('search.php', $current_page) ?>">資料検索</a>

                <?php if ($role === 'admin'): ?>
                    <div class="h-4 w-px bg-white/20 mx-1"></div>
                    <a href="user_management.php" class="flex items-center gap-1 <?= isActive('user_management.php', $current_page) ?>">
                        <span class="text-xs">⚙</span> ユーザー管理
                    </a>
                    <a href="docs/design_v3.html" class="opacity-70 hover:opacity-100 transition ml-2">設計書</a>
                    <a href="../../UIUX設計書_初版.html" class="opacity-70 hover:opacity-100 transition ml-2">UIUX設計書</a>
                    <a href="../../仮.html" class="opacity-70 hover:opacity-100 transition ml-2">AIの推進に向けて</a>
                <?php endif; ?>
            </nav>
        </div>

        <div class="flex items-center gap-4 text-xs">
            <div class="text-right hidden sm:block">
                <div class="font-bold"><?= htmlspecialchars($username) ?></div>
                <div class="text-[10px] text-white/70 italic">
                    <?= htmlspecialchars($dept) ?> 所属 | 
                    <span class="<?= $role === 'admin' ? 'text-yellow-300' : '' ?>">
                        <?= $role === 'admin' ? '管理者' : '一般ユーザー' ?>
                    </span>
                </div>
            </div>
            
            <button id="user-settings-btn" class="bg-white/10 hover:bg-white/20 px-3 py-1.5 rounded-lg font-bold transition flex items-center gap-1 border border-white/10 shadow-sm">
                ⚙️ 接続設定
            </button>

            <div class="w-9 h-9 bg-white/20 rounded-full flex items-center justify-center text-lg border border-white/30 shadow-inner">
                <?= $role === 'admin' ? '🛠️' : '👤' ?>
            </div>

            <a href="logout.php" 
               onclick="return confirm('ログアウトしてよろしいですか？')"
               class="bg-red-500/80 px-3 py-1.5 rounded font-bold hover:bg-red-600 transition flex items-center gap-1 shadow-md">
                <span>ログアウト</span>
            </a>
        </div>
    </div>
</header>

<!-- ユーザー設定モーダル -->
<div id="user-settings-modal" class="fixed inset-0 bg-black/70 hidden items-center justify-center z-[100]">
    <div class="bg-white rounded-2xl shadow-2xl w-full max-w-lg mx-4 overflow-hidden transform transition-all">
        <div class="bg-gray-50 px-6 py-4 border-b flex justify-between items-center">
            <h3 class="text-sm font-black text-[#4F5D95] uppercase tracking-widest flex items-center gap-2">⚙️ AI Server & Model Settings</h3>
            <button type="button" class="user-settings-close text-gray-400 hover:text-gray-600 text-2xl">&times;</button>
        </div>
        
        <form id="user-settings-form" class="p-6 space-y-5 text-xs text-slate-700">
            <!-- CSRFトークン -->
            <input type="hidden" name="csrf_token" id="settings-csrf-token" value="<?= htmlspecialchars($_SESSION['csrf_token'] ?? '') ?>">

            <div class="grid grid-cols-2 gap-4 border-b border-dashed border-gray-200 pb-4">
                <div>
                    <label class="block font-black text-gray-500 mb-1.5 uppercase tracking-tighter">デフォルトプロンプト</label>
                    <select name="default_prompt" class="w-full border-gray-300 border rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-[#4F5D95]/20 outline-none">
                        <option value="construction_consultant" <?= $userSettings['default_prompt'] === 'construction_consultant' ? 'selected' : '' ?>>🏗️ 建設コンサルタントモード</option>
                        <option value="technical_expert" <?= $userSettings['default_prompt'] === 'technical_expert' ? 'selected' : '' ?>>🔬 技術専門家モード</option>
                        <option value="proofreader" <?= $userSettings['default_prompt'] === 'proofreader' ? 'selected' : '' ?>>📝 報告書校正モード</option>
                        <option value="general_chat" <?= $userSettings['default_prompt'] == 'general_chat' ? 'selected' : '' ?>>💬 会話モード</option>
                    </select>
                </div>

                <div>
                    <label class="block font-black text-gray-500 mb-1.5 uppercase tracking-tighter">表示言語</label>
                    <select name="default_lang" class="w-full border-gray-300 border rounded-lg px-3 py-2 bg-white focus:ring-2 focus:ring-[#4F5D95]/20 outline-none">
                        <option value="ja" <?= $userSettings['default_lang'] === 'ja' ? 'selected' : '' ?>>日本語 (Japanese)</option>
                        <option value="en" <?= $userSettings['default_lang'] === 'en' ? 'selected' : '' ?>>English</option>
                    </select>
                </div>
            </div>

            <!-- ★ 新規追加: 接続先・モデルの手動入力エリア -->
            <div class="space-y-4 pt-1">
                <div>
                    <label class="block font-black text-[#00758F] mb-1.5 uppercase tracking-tighter">Ollama 接続先 URL <span class="text-red-500">*</span></label>
                    <input type="text" name="ollama_host" class="w-full border-gray-300 border rounded-lg px-3 py-2 font-mono bg-blue-50/30 focus:ring-2 focus:ring-[#00758F]/20 outline-none" 
                           value="<?= htmlspecialchars($userSettings['ollama_host']) ?>" required placeholder="http://192.168.x.x:11434">
                    <p class="text-[9px] text-gray-400 mt-1">※ AI推論エンジン（GPUサーバー）のIPアドレス・ポートを指定してください。</p>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div>
                        <label class="block font-bold text-[#00758F] mb-1.5 tracking-tighter">メイン使用モデル <span class="text-red-500">*</span></label>
                        <input type="text" name="default_model" class="w-full border-gray-300 border rounded-lg px-3 py-2 font-mono bg-blue-50/30 focus:ring-2 focus:ring-[#00758F]/20 outline-none"
                               value="<?= htmlspecialchars($userSettings['default_model']) ?>" required placeholder="gemma4:e4b">
                        <p class="text-[9px] text-gray-400 mt-1">※ 因数分解と最終回答の統合に使います。</p>
                    </div>

                    <div>
                        <label class="block font-bold text-[#00758F] mb-1.5 tracking-tighter">サブモデル (中間処理用) <span class="text-red-500">*</span></label>
                        <input type="text" name="sub_model" class="w-full border-gray-300 border rounded-lg px-3 py-2 font-mono bg-blue-50/30 focus:ring-2 focus:ring-[#00758F]/20 outline-none"
                               value="<?= htmlspecialchars($userSettings['sub_model']) ?>" required placeholder="gpt-oss:20b">
                        <p class="text-[9px] text-gray-400 mt-1">※ SQL生成や補助分析などの中間処理に使います。</p>
                    </div>

                    <div>
                        <label class="block font-bold text-[#00758F] mb-1.5 tracking-tighter">Embeddingモデル <span class="text-red-500">*</span></label>
                        <input type="text" name="embedding_model" class="w-full border-gray-300 border rounded-lg px-3 py-2 font-mono bg-blue-50/30 focus:ring-2 focus:ring-[#00758F]/20 outline-none"
                               value="<?= htmlspecialchars($userSettings['embedding_model']) ?>" required placeholder="mxbai-embed-large">
                        <p class="text-[9px] text-gray-400 mt-1">※ ベクトル化と類似検索の埋め込み生成に使います。</p>
                    </div>
                </div>
                <?php if (!$hasEmbeddingModelColumn): ?>
                    <p class="text-[10px] text-amber-600">※ 現在のDBでは `embedding_model` 列が未作成のため、この値はセッション上の暫定設定として扱われます。</p>
                <?php endif; ?>
                <p class="text-[10px] text-slate-500">※ 保存時に `Ollama /api/tags` を確認し、入力したモデル名が実在しない場合は保存しません。</p>
            </div>

            <div class="flex justify-end gap-2 mt-8 pt-4 border-t">
                <button type="button" class="user-settings-close px-4 py-2 bg-gray-100 text-gray-600 rounded-lg font-bold hover:bg-gray-200 transition-all">キャンセル</button>
                <button type="submit" id="settings-submit-btn" class="px-6 py-2 bg-[#4F5D95] text-white rounded-lg font-bold shadow-lg hover:bg-[#3f4a7a] transition-all active:scale-95 flex items-center gap-1">
                    <span>設定を保存</span>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal = document.getElementById('user-settings-modal');
    const openBtn = document.getElementById('user-settings-btn');
    const closeBtns = document.querySelectorAll('.user-settings-close');
    const form = document.getElementById('user-settings-form');

    const toggleModal = (show) => {
        if (show) {
            modal.classList.replace('hidden', 'flex');
            document.body.style.overflow = 'hidden';
        } else {
            modal.classList.replace('flex', 'hidden');
            document.body.style.overflow = '';
        }
    };

    openBtn?.addEventListener('click', () => toggleModal(true));
    closeBtns.forEach(btn => btn.addEventListener('click', () => toggleModal(false)));
    
    // ★枠外（背景）クリックで閉じる処理を削除（無効化）
    // modal.addEventListener('click', (e) => { if (e.target === modal) toggleModal(false); });

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        const submitBtn = document.getElementById('settings-submit-btn');
        const formData = new FormData(form);
        const csrfToken = document.getElementById('settings-csrf-token').value;

        submitBtn.disabled = true;
        submitBtn.innerHTML = '🔄 保存 ＆ 通信テスト中...';
        submitBtn.classList.add('opacity-80', 'cursor-wait');

        try {
            const res = await fetch('api/save_user_settings.php', {
                method: 'POST',
                body: JSON.stringify(Object.fromEntries(formData)),
                headers: { 
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': csrfToken
                }
            });
            
            const data = await res.json();
            if (data.success) {
                // 保存＆接続テスト成功
                location.reload();
            } else {
                alert('エラー: ' + (data.error || '保存に失敗しました。'));
                submitBtn.disabled = false;
                submitBtn.innerHTML = '設定を保存';
                submitBtn.classList.remove('opacity-80', 'cursor-wait');
            }
        } catch (err) {
            console.error('Save error:', err);
            alert('通信エラーが発生しました。api/save_user_settings.php の配置場所を確認してください。');
            submitBtn.disabled = false;
            submitBtn.innerHTML = '設定を保存';
            submitBtn.classList.remove('opacity-80', 'cursor-wait');
        }
    });
});
</script>
<style>
@keyframes fadeIn { from { opacity: 0; } to { opacity: 1; } }
#user-settings-modal { animation: fadeIn 0.2s ease-out forwards; }
</style>
