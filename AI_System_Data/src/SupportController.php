<?php
// =========================================================================
// ★【最重要：変数隔離シールド方式】出力システムの自動パース干渉を100%物理遮断
// =========================================================================
$URL_OLLAMA_HOST = 'ht' . 'tp' . '://' . '127' . '.' . '0.0.1' . ':11434';
$URL_TAILWIND    = 'ht' . 'tps' . '://' . 'cdn' . '.tailwindcss.com';
$URL_MARKED      = 'ht' . 'tps' . '://' . 'cdn' . '.jsdelivr.net/npm/marked/marked.min.js';
$URL_DOMPURIFY   = 'ht' . 'tps' . '://' . 'cdnjs' . '.cloudflare.com/ajax/libs/dompurify/3.0.9/purify.min.js';
$URL_LEAFLET_CSS = 'ht' . 'tps' . '://' . 'unpkg' . '.com/leaflet@1.9.4/dist/leaflet.css';
$URL_LEAFLET_JS  = 'ht' . 'tps' . '://' . 'unpkg' . '.com/leaflet@1.9.4/dist/leaflet.js';
$URL_CHART_JS    = 'ht' . 'tps' . '://' . 'cdn' . '.jsdelivr.net/npm/chart.js';
$URL_MERMAID     = 'ht' . 'tps' . '://' . 'cdn' . '.jsdelivr.net/npm/mermaid@10.9.5/dist/mermaid.min.js';
$URL_SVG_XMLNS   = 'ht' . 'tp' . '://' . 'www' . '.w3.org/2000/svg';

/**
 * SupportController.php - 業務支援システム 拡張ビジネスロジック・コントローラー
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../src/Auth.php';
require_once __DIR__ . '/../src/Parsedown.php';
require_once __DIR__ . '/../src/ProjectAccess.php';
require_once __DIR__ . '/../src/ModelRoleResolver.php';
require_once __DIR__ . '/../src/ProjectContextMemory.php';

$parsedown = new Parsedown();
$parsedown->setBreaksEnabled(true);

$auth = new Auth($pdo);
if (!$auth->isLoggedIn()) {
    header('Location: login.php');
    exit;
}

$csrfToken = getCsrfToken();

// ★安全対策: プレビュー処理などで利用する安全なHTMLエスケープ関数の定義
if (!function_exists('h')) {
    function h(?string $str): string {
        return htmlspecialchars((string)$str, ENT_QUOTES, 'UTF-8');
    }
}

// コメント内のURLを安全にハイパーリンク化する関数
if (!function_exists('makeClickableLinks')) {
    function makeClickableLinks($text) {
        $text = htmlspecialchars((string)$text, ENT_QUOTES, 'UTF-8');
        $pattern = '/(https?:\/\/[^\s<]+)/';
        $replacement = '<a href="$1" target="_blank" rel="noopener noreferrer" class="text-blue-500 hover:underline break-all">$1</a>';
        return nl2br(preg_replace($pattern, $replacement, $text));
    }
}

$user_id = (int)$_SESSION['user_id'];
$role = $_SESSION['role'] ?? 'user';

$resolvedModels = ModelRoleResolver::resolveUserSettings($_SESSION);

// =========================================================================
// セッションからOllamaのURLとモデルを動的に取得
// =========================================================================
$default_prompt_mode = $_SESSION['default_prompt'] ?? 'construction_consultant';
$default_chat_model  = $resolvedModels['main_model'];
$ollama_host         = $resolvedModels['ollama_host'] ?: rtrim($URL_OLLAMA_HOST, '/');
$default_model       = ModelRoleResolver::DEFAULT_MAIN_MODEL;

$installed_models = [];

if (function_exists('curl_init')) {
    try {
        $ch = curl_init("{$ollama_host}/api/tags");
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $res = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($status === 200) {
            $data = json_decode($res, true);
            if (isset($data['models'])) {
                foreach ($data['models'] as $m) { $installed_models[] = $m['name']; }
            }
        }
    } catch (Exception $e) {}
}
$active_model = in_array($default_chat_model, $installed_models) ? $default_chat_model : $default_model;
if (!in_array($active_model, $installed_models)) { array_unshift($installed_models, $active_model); }

$selected_project_id = filter_input(INPUT_GET, 'project_id', FILTER_VALIDATE_INT);
$active_tab = filter_input(INPUT_GET, 'tab', FILTER_SANITIZE_SPECIAL_CHARS) ?: 'overview';
$memory_flash = filter_input(INPUT_GET, 'memory_saved', FILTER_SANITIZE_SPECIAL_CHARS) ?: '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'save_project_memory') {
    $token = (string)($_POST['csrf_token'] ?? '');
    $proj_id = filter_input(INPUT_POST, 'project_id', FILTER_VALIDATE_INT);

    if (empty($token) || !isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
        header('Location: support.php?project_id=' . urlencode((string)$proj_id) . '&tab=overview&memory_saved=csrf_error');
        exit;
    }

    if (!$proj_id || !canAccessProject($pdo, (int)$proj_id, $user_id, $role)) {
        header('Location: support.php?project_id=' . urlencode((string)$proj_id) . '&tab=overview&memory_saved=forbidden');
        exit;
    }

    if ($role !== 'admin') {
        header('Location: support.php?project_id=' . urlencode((string)$proj_id) . '&tab=overview&memory_saved=forbidden');
        exit;
    }

    try {
        ProjectContextMemory::save($pdo, (int)$proj_id, [
            'agents' => (string)($_POST['memory_agents'] ?? ''),
            'readme' => (string)($_POST['memory_readme'] ?? ''),
            'todo' => (string)($_POST['memory_todo'] ?? ''),
        ]);
        header('Location: support.php?project_id=' . urlencode((string)$proj_id) . '&tab=overview&memory_saved=1');
        exit;
    } catch (Throwable $e) {
        error_log('SupportController Memory Save Error: ' . $e->getMessage());
        header('Location: support.php?project_id=' . urlencode((string)$proj_id) . '&tab=overview&memory_saved=error');
        exit;
    }
}

// =========================================================================
// 【非同期（Ajax）コメント追加リクエストのエンドポイント処理】
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_GET['action']) && $_GET['action'] === 'add_comment') {
    header('Content-Type: application/json; charset=utf-8');
    $input = json_decode(file_get_contents('php://input'), true);
    $token = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    
    if (empty($token) || !isset($_SESSION['csrf_token']) || $token !== $_SESSION['csrf_token']) {
        echo json_encode(['success' => false, 'error' => 'CSRFトークンが正しくありません。']);
        exit;
    }

    $comment_text = trim($input['comment'] ?? '');
    $proj_id = filter_var($input['project_id'], FILTER_VALIDATE_INT);

    if (empty($comment_text) || !$proj_id) {
        echo json_encode(['success' => false, 'error' => 'コメント内容が空か、プロジェクトIDが不正です。']);
        exit;
    }

    if (!canAccessProject($pdo, (int)$proj_id, $user_id, $role)) {
        http_response_code(403);
        echo json_encode(['success' => false, 'error' => 'この案件にコメントする権限がありません。']);
        exit;
    }

    try {
        $pdo->beginTransaction();
        $stmt = $pdo->prepare("INSERT INTO project_comments (project_id, user_id, comment_text, created_at) VALUES (?, ?, ?, NOW())");
        $stmt->execute([$proj_id, $user_id, $comment_text]);
        $new_id = $pdo->lastInsertId();

        $stmtUser = $pdo->prepare("SELECT username FROM users WHERE id = ?");
        $stmtUser->execute([$user_id]);
        $u_info = $stmtUser->fetch();
        $username = $u_info['username'] ?? 'Unknown';
        $pdo->commit();

        echo json_encode([
            'success' => true,
            'comment' => [
                'id' => (int)$new_id,
                'username' => $username,
                'comment_text' => $comment_text,
                'created_at' => date('Y-m-d H:i:s'),
                'user_id' => (int)$user_id
            ]
        ]);
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        echo json_encode(['success' => false, 'error' => 'データベースエラーが発生しました。']);
    }
    exit;
}

// =========================================================================
// 1. プロジェクト一覧の取得 (権限による認可制御シールドの実装)
// =========================================================================
if ($role === 'admin') {
    $stmtProjects = $pdo->prepare("SELECT id, project_name, updated_at FROM projects ORDER BY updated_at DESC");
    $stmtProjects->execute();
} else {
    // 一般ユーザーは自身が作成、またはメンバーとしてアサインされた案件を取得
    $stmtProjects = $pdo->prepare("
        SELECT DISTINCT p.id, p.project_name, p.updated_at
        FROM projects p
        LEFT JOIN project_members pm ON p.id = pm.project_id AND pm.user_id = :user_id
        WHERE p.created_by = :user_id OR pm.user_id IS NOT NULL
        ORDER BY p.updated_at DESC
    ");
    $stmtProjects->execute(['user_id' => $user_id]);
}
$projects = $stmtProjects->fetchAll(PDO::FETCH_ASSOC);

// メンバー追加モーダル用に全ユーザーの一覧を取得
$all_users = $pdo->query("SELECT id, username, department FROM users ORDER BY username ASC")->fetchAll(PDO::FETCH_ASSOC);

// =========================================================================
// 2. 選択中案件の詳細情報および拡張情報の取得 (安全なプリペアドステートメント一元化)
// =========================================================================
$current_project = null;
$documents = [];
$chat_history = [];
$chat_reasoning_steps_by_chat_id = [];
$comments = [];
$faqs = [];
$members = [];
$csv_files = [];
$project_memory_docs = [];
$can_manage_project_memory = ($role === 'admin');

if ($selected_project_id) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM projects WHERE id = :project_id");
        $stmt->execute(['project_id' => $selected_project_id]);
        $current_project = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($current_project) {
            // URLパラメータ改ざんによる他案件への不正アクセスを防ぐ認可制御
            if (!canAccessProject($pdo, (int)$selected_project_id, $user_id, $role)) {
                $current_project = null; // 権限がない場合はデータを破棄
            } else {
                // 安全なプリペアドステートメントによる関連テーブルの一元フェッチ
                $stmtDocs = $pdo->prepare("SELECT * FROM documents WHERE project_id = :project_id AND title NOT LIKE '[CSVデータ]%' ORDER BY created_at DESC");
                $stmtDocs->execute(['project_id' => $selected_project_id]);
                $documents = $stmtDocs->fetchAll(PDO::FETCH_ASSOC);

                $stmtChat = $pdo->prepare("SELECT * FROM chat_history WHERE project_id = :project_id ORDER BY created_at ASC");
                $stmtChat->execute(['project_id' => $selected_project_id]);
                $chat_history = $stmtChat->fetchAll(PDO::FETCH_ASSOC);

                $assistantChatIds = array_values(array_filter(array_map(static function ($chat) {
                    return ($chat['role'] ?? '') === 'assistant' ? (int)$chat['id'] : null;
                }, $chat_history)));
                if ($assistantChatIds) {
                    $placeholders = implode(',', array_fill(0, count($assistantChatIds), '?'));
                    $stmtReasoning = $pdo->prepare("
                        SELECT chat_history_id, step_number, sub_query, sub_answer, created_at
                        FROM chat_reasoning_steps
                        WHERE chat_history_id IN ($placeholders)
                        ORDER BY chat_history_id ASC, step_number ASC, id ASC
                    ");
                    $stmtReasoning->execute($assistantChatIds);
                    foreach ($stmtReasoning->fetchAll(PDO::FETCH_ASSOC) as $step) {
                        $historyId = (int)($step['chat_history_id'] ?? 0);
                        if ($historyId <= 0) {
                            continue;
                        }
                        $chat_reasoning_steps_by_chat_id[$historyId][] = [
                            'step_number' => (int)($step['step_number'] ?? 0),
                            'sub_query' => (string)($step['sub_query'] ?? ''),
                            'sub_answer' => (string)($step['sub_answer'] ?? ''),
                            'created_at' => (string)($step['created_at'] ?? ''),
                        ];
                    }
                }

                $stmtComments = $pdo->prepare("SELECT pc.*, u.username FROM project_comments pc JOIN users u ON pc.user_id = u.id WHERE pc.project_id = :project_id ORDER BY pc.created_at DESC");
                $stmtComments->execute(['project_id' => $selected_project_id]);
                $comments = $stmtComments->fetchAll(PDO::FETCH_ASSOC);

                $stmtMembers = $pdo->prepare("SELECT pm.*, u.username, u.department FROM project_members pm JOIN users u ON pm.user_id = u.id WHERE pm.project_id = :project_id ORDER BY pm.assigned_at ASC");
                $stmtMembers->execute(['project_id' => $selected_project_id]);
                $members = $stmtMembers->fetchAll(PDO::FETCH_ASSOC);

                $stmtFaqs = $pdo->prepare("SELECT pf.*, u.username FROM project_faqs pf LEFT JOIN users u ON pf.created_by = u.id WHERE pf.project_id = :project_id ORDER BY pf.created_at DESC");
                $stmtFaqs->execute(['project_id' => $selected_project_id]);
                $faqs = $stmtFaqs->fetchAll(PDO::FETCH_ASSOC);

                $stmtCsv = $pdo->prepare("SELECT * FROM project_csv_files WHERE project_id = :project_id ORDER BY created_at DESC");
                $stmtCsv->execute(['project_id' => $selected_project_id]);
                $csv_files = $stmtCsv->fetchAll(PDO::FETCH_ASSOC);

                $project_memory_docs = ProjectContextMemory::load($pdo, (int)$selected_project_id);
            }
        }
    } catch (PDOException $e) {
        error_log("SupportController Data Fetch Error: " . $e->getMessage());
    }
}
