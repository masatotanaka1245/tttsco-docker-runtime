<?php
// clean_yousai.php ── 要塞クレンジング特効薬
$targetFile = __DIR__ . '/chat_advanced.php';

if (!file_exists($targetFile)) {
    die("⚠️ chat_advanced.php が見つかりません。パスを確認してください。");
}

$code = file_get_contents($targetFile);

// 1. 不可視の地雷文字（ノーブレークスペース \xA0）を通常の半角スペースへ強制置換
$code = str_replace("\xc2\xa0", " ", $code);
$code = str_replace("\xa0", " ", $code);

// 2. 万が一紛れ込んだ全角スペースを半角スペース2つへ安全置換
$code = str_replace("　", "  ", $code);

file_put_contents($targetFile, $code);

echo "✅ chat_advanced.php の不可視地雷文字・全角スペースの完全除去が完了しました！\n";