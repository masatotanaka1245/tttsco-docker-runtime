<?php
class User {
    private $pdo;

    public function __construct($pdo) {
        $this->pdo = $pdo;
    }

    /**
     * IDからユーザー情報を取得
     */
    public function getById($id) {
        $stmt = $this->pdo->prepare("SELECT id, username, department, email, created_at FROM users WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch();
    }

    /**
     * 全11分野のリスト（UI表示用）
     */
    public static function getDepartments() {
        return [
            '河川、砂防及び海岸・海洋分野',
            '土質及び基礎分野',
            '港湾及び空港分野',
            '鋼構造及びコンクリート分野',
            '電力土木分野',
            'トンネル分野',
            '道路分野',
            '建設環境分野',
            '都市計画及び地方計画分野',
            '電気電子分野',
            '地質分野'
        ];
    }
}