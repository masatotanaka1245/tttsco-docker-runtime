<?php
/**
 * config/session.php - セッション管理 ＆ CSRF保護トークン生成・検証用ヘルパー
 */

// セッションがまだ開始されていない場合のみ、セッションを開始する
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_lifetime' => 86400,          // 1日
        'cookie_secure'   => false,          // 本番環境がHTTPSでない場合はfalse (HTTPS環境に移行した場合はtrueを推奨)
        'cookie_httponly' => true,           // JavaScriptからのクッキー読み取りを防御 (XSS対策)
        'use_strict_mode' => true,           // セッションIDの固定化攻撃（Session Fixation）を防止
        'sid_length'      => 48,
        'sid_bits_per_character' => 5,
    ]);
}

/**
 * CSRFトークンを生成またはセッションから取得する関数
 * * @return string CSRFトークン (16進数文字列)
 */
function getCsrfToken(): string {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * リクエストから送信されたCSRFトークンが正しいかを検証する関数
 * * @param string|null $token 検証対象のトークン文字列
 * @return bool 一致した場合は true、不一致またはトークンが空の場合は false
 */
function verifyCsrfToken(?string $token): bool {
    if (empty($_SESSION['csrf_token']) || empty($token)) {
        return false;
    }
    // タイミング攻撃（Timing Attack）を防ぐため、安全な文字列比較（hash_equals）を使用
    return hash_equals($_SESSION['csrf_token'], $token);
}