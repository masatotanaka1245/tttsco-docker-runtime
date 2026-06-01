<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../src/Auth.php';

$auth = new Auth($pdo);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';

    if ($auth->login($username, $password)) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'ユーザー名またはパスワードが正しくありません。';
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>Login | TEPSCO Routines</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/styles.css">
</head>
<body class="bg-[#f8fafc] flex items-center justify-center min-h-screen">

<div class="w-full max-w-md">
    <div class="text-center mb-8">
        <h1 class="text-3xl font-black text-[#4F5D95] tracking-widest">TEPSCO Routines</h1>
        <p class="text-xs text-slate-400 mt-2 uppercase font-bold tracking-tighter">On-premise Technical Support Platform</p>
    </div>

    <div class="bg-white p-8 rounded-3xl shadow-2xl border border-slate-100">
        <h2 class="text-xl font-bold text-slate-800 mb-6 border-l-4 border-[#4F5D95] pl-3">ログイン</h2>

        <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-600 text-xs p-3 rounded-lg mb-6">
                <?= htmlspecialchars($error) ?>
            </div>
        <?php endif; ?>

        <form action="" method="POST" class="space-y-5">
            <div>
                <label class="block text-xs font-bold text-slate-500 mb-1">ユーザー名</label>
                <input type="text" name="username" required
                       class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm outline-none focus:ring-2 ring-[#4F5D95]/20 focus:border-[#4F5D95] transition-all">
            </div>

            <div>
                <label class="block text-xs font-bold text-slate-500 mb-1">パスワード</label>
                <input type="password" name="password" required
                       class="w-full bg-slate-50 border border-slate-200 rounded-xl px-4 py-3 text-sm outline-none focus:ring-2 ring-[#4F5D95]/20 focus:border-[#4F5D95] transition-all">
            </div>

            <button type="submit" 
                    class="w-full bg-[#4F5D95] hover:bg-[#3d4a7a] text-white font-bold py-3 rounded-xl shadow-lg shadow-[#4F5D95]/20 transition-all transform active:scale-[0.98]">
                認証を開始
            </button>
        </form>

        <div class="mt-8 pt-6 border-t border-slate-100 text-center">
            <p class="text-[10px] text-slate-400 leading-relaxed">
                本システムは外部ネットワークから隔離されています。<br>
                ログイン情報は社内規定に従い厳重に管理してください。
            </p>
        </div>
    </div>
</div>

</body>
</html>