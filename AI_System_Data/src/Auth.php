<?php
// src/Auth.php

// ① User クラスを読み込む
require_once __DIR__ . '/User.php';

class Auth {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    public function login($username, $password) {
        $stmt = $this->pdo->prepare("SELECT * FROM users WHERE username = ?");
        $stmt->execute([$username]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($user && password_verify($password, $user['password_hash'])) {
            // ここでセッションに必要な情報をすべて入れる！
            $_SESSION['user_id']    = $user['id'];
            $_SESSION['username']   = $user['username'];
            $_SESSION['role']       = $user['role'];       // これが必要！
            $_SESSION['department'] = $user['department']; // これも必要！
            return true;
        }
        return false;
    }

    /** ログアウト処理 */
    public function logout(): void
    {
        $_SESSION = [];
        session_destroy();
    }

    /** ログイン状態判定 */
    public function isLoggedIn(): bool
    {
        return isset($_SESSION['user_id']);
    }
}