<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../src/Auth.php';

$auth = new Auth($pdo);
if (!$auth->isLoggedIn() || $_SESSION['role'] !== 'admin') {
    header('Location: index.php'); // 管理者以外は追い返す
    exit;
}

// ユーザー一覧の取得
$users = $pdo->query("SELECT id, username, role, department, created_at FROM users ORDER BY id ASC")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>ユーザー管理 | AI SYSTEM</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body class="bg-gray-100 min-h-screen flex flex-col">

<?php include __DIR__ . '/templates/header.php'; ?>

<main class="flex-1 p-8">
    <div class="max-w-5xl mx-auto">
        <div class="flex justify-between items-center mb-6">
            <h2 class="text-xl font-black text-gray-800 uppercase tracking-tighter">ユーザーリスト</h2>
            <button onclick="openUserModal()" class="bg-[#4F5D95] text-white px-4 py-2 rounded-lg text-xs font-bold shadow-lg hover:opacity-90">
                + 新規ユーザー登録
            </button>
        </div>

        <div class="bg-white rounded-2xl shadow-sm border border-gray-200 overflow-hidden">
            <table class="w-full text-left text-xs">
                <thead class="bg-gray-50 border-b text-gray-400 font-bold uppercase">
                    <tr>
                        <th class="p-4">ID</th>
                        <th class="p-4">ユーザー名</th>
                        <th class="p-4">権限</th>
                        <th class="p-4">所属</th>
                        <th class="p-4">登録日</th>
                        <th class="p-4 text-center">操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($users as $u): ?>
                    <tr class="hover:bg-gray-50 transition-colors">
                        <td class="p-4 font-mono"><?= $u['id'] ?></td>
                        <td class="p-4 font-bold text-gray-700"><?= htmlspecialchars($u['username']) ?></td>
                        <td class="p-4">
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-bold <?= $u['role'] === 'admin' ? 'bg-purple-100 text-purple-600' : 'bg-blue-100 text-blue-600' ?>">
                                <?= strtoupper($u['role']) ?>
                            </span>
                        </td>
                        <td class="p-4 text-gray-500"><?= htmlspecialchars($u['department'] ?? '-') ?></td>
                        <td class="p-4 text-gray-400"><?= date('Y/m/d', strtotime($u['created_at'])) ?></td>
                        <td class="p-4 text-center space-x-2">
                            <button onclick='editUser(<?= json_encode($u) ?>)' class="text-blue-600 hover:underline font-bold">編集</button>
                            <?php if ($u['id'] != $_SESSION['user_id']): ?>
                                <button onclick="deleteUser(<?= $u['id'] ?>)" class="text-red-500 hover:underline font-bold">削除</button>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</main>

<div id="user-modal" class="hidden fixed inset-0 bg-black/50 backdrop-blur-sm z-[100] flex items-center justify-center p-4">
    <div class="bg-white w-full max-w-md rounded-2xl shadow-2xl overflow-hidden">
        <div class="p-4 bg-gray-50 border-b flex justify-between items-center">
            <h3 id="modal-title" class="text-sm font-black text-gray-700 uppercase">ユーザー登録</h3>
            <button onclick="closeUserModal()" class="text-gray-400">✕</button>
        </div>
        <form id="user-form" onsubmit="saveUser(event)" class="p-6 space-y-4">
            <input type="hidden" name="id" id="user-id">
            
            <div class="space-y-1">
                <label class="text-[10px] font-bold text-gray-400 uppercase">ユーザー名</label>
                <input type="text" name="username" id="user-username" required class="w-full border rounded-lg p-2 text-xs outline-none focus:ring-2 ring-[#4F5D95]/20">
            </div>

            <div class="space-y-1">
                <label class="text-[10px] font-bold text-gray-400 uppercase">パスワード <span id="pw-label" class="text-[8px] normal-case font-normal">(変更時のみ入力)</span></label>
                <input type="password" name="password" id="user-password" class="w-full border rounded-lg p-2 text-xs outline-none focus:ring-2 ring-[#4F5D95]/20">
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="space-y-1">
                    <label class="text-[10px] font-bold text-gray-400 uppercase">権限</label>
                    <select name="role" id="user-role" class="w-full border rounded-lg p-2 text-xs">
                        <option value="user">USER</option>
                        <option value="admin">ADMIN</option>
                    </select>
                </div>
                <div class="space-y-1">
                    <label class="text-[10px] font-bold text-gray-400 uppercase">所属部署</label>
                    <input type="text" name="department" id="user-department" class="w-full border rounded-lg p-2 text-xs">
                </div>
            </div>

            <div class="pt-4 flex gap-2">
                <button type="button" onclick="closeUserModal()" class="flex-1 p-2 bg-gray-100 text-gray-500 rounded-lg text-xs font-bold">キャンセル</button>
                <button type="submit" class="flex-1 p-2 bg-[#4F5D95] text-white rounded-lg text-xs font-bold shadow-lg">保存する</button>
            </div>
        </form>
    </div>
</div>

<script>
function openUserModal() {
    document.getElementById('modal-title').innerText = "ユーザー登録";
    document.getElementById('user-form').reset();
    document.getElementById('user-id').value = "";
    document.getElementById('user-password').required = true;
    document.getElementById('pw-label').style.display = "none";
    document.getElementById('user-modal').classList.remove('hidden');
}

function editUser(user) {
    document.getElementById('modal-title').innerText = "ユーザー編集";
    document.getElementById('user-id').value = user.id;
    document.getElementById('user-username').value = user.username;
    document.getElementById('user-role').value = user.role;
    document.getElementById('user-department').value = user.department || "";
    document.getElementById('user-password').required = false;
    document.getElementById('pw-label').style.display = "inline";
    document.getElementById('user-modal').classList.remove('hidden');
}

function closeUserModal() {
    document.getElementById('user-modal').classList.add('hidden');
}

async function saveUser(e) {
    e.preventDefault();
    const formData = new FormData(e.target);
    const data = Object.fromEntries(formData.entries());
    data.action = data.id ? 'update' : 'create';

    const res = await fetch('api/user_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
    });
    const result = await res.json();
    if (result.success) location.reload();
    else alert('エラー: ' + result.error);
}

async function deleteUser(id) {
    if (!confirm('本当にこのユーザーを削除しますか？')) return;
    const res = await fetch('api/user_api.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ action: 'delete', id: id })
    });
    const result = await res.json();
    if (result.success) location.reload();
    else alert('エラー: ' + result.error);
}
</script>
</body>
</html>