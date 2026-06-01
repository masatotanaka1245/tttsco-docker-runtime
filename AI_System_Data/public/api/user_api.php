<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/session.php';
require_once __DIR__ . '/../../src/Auth.php';

header('Content-Type: application/json');

$auth = new Auth($pdo);
if (!$auth->isLoggedIn() || $_SESSION['role'] !== 'admin') {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$action = $input['action'] ?? '';

try {
    switch ($action) {
        case 'create':
            $hash = password_hash($input['password'], PASSWORD_DEFAULT);
            $stmt = $pdo->prepare("INSERT INTO users (username, password_hash, role, department) VALUES (?, ?, ?, ?)");
            $stmt->execute([$input['username'], $hash, $input['role'], $input['department']]);
            break;

        case 'update':
            if (!empty($input['password'])) {
                $hash = password_hash($input['password'], PASSWORD_DEFAULT);
                $stmt = $pdo->prepare("UPDATE users SET username=?, password_hash=?, role=?, department=? WHERE id=?");
                $stmt->execute([$input['username'], $hash, $input['role'], $input['department'], $input['id']]);
            } else {
                $stmt = $pdo->prepare("UPDATE users SET username=?, role=?, department=? WHERE id=?");
                $stmt->execute([$input['username'], $input['role'], $input['department'], $input['id']]);
            }
            break;

        case 'delete':
            if ($input['id'] == $_SESSION['user_id']) {
                throw new Exception('自分自身は削除できません。');
            }
            $stmt = $pdo->prepare("DELETE FROM users WHERE id = ?");
            $stmt->execute([$input['id']]);
            break;

        default:
            throw new Exception('Invalid action');
    }
    echo json_encode(['success' => true]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}