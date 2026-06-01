<?php
/**
 * 資料検索・地図連携システム (FULLTEXT検索 & 画像解析データ表示対応版)
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/session.php';
require_once __DIR__ . '/../src/Auth.php';

$auth = new Auth($pdo);
if (!$auth->isLoggedIn()) {
    header('Location: login.php');
    exit;
}

// 検索クエリの取得（サニタイズ）
$keyword = filter_input(INPUT_GET, 'q', FILTER_SANITIZE_SPECIAL_CHARS);

// 1. プロジェクト情報の取得（地図プロット用：全件）
$stmt = $pdo->query("SELECT id, project_name, latitude, longitude, address FROM projects WHERE latitude IS NOT NULL");
$map_projects = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. 資料検索ロジック
$search_results = [];
$error_msg = null;

try {
    if ($keyword) {
        // ★ 修正ポイント：image_description を SELECT と WHERE に追加
        // 本文(chunk_text) または 画像説明(image_description) または タイトル から検索
        $sql = "SELECT d.id, d.title, c.page_number, c.chunk_text as excerpt, c.image_description, 
                       p.project_name, p.latitude, p.longitude,
                       (MATCH(c.chunk_text) AGAINST(:q1 IN BOOLEAN MODE) * 2 + 
                        IF(c.image_description LIKE :qdesc, 1, 0)) as score
                FROM doc_chunks c
                JOIN documents d ON c.doc_id = d.id
                JOIN projects p ON d.project_id = p.id
                WHERE (MATCH(c.chunk_text) AGAINST(:q2 IN BOOLEAN MODE) 
                   OR c.image_description LIKE :qdesc2
                   OR d.title LIKE :qlike)
                ORDER BY score DESC, d.created_at DESC
                LIMIT 50";
        
        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            'q1' => $keyword,
            'q2' => $keyword,
            'qdesc' => '%' . $keyword . '%',
            'qdesc2' => '%' . $keyword . '%',
            'qlike' => '%' . $keyword . '%'
        ]);
        $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        // デフォルト表示（最新資料）
        $sql = "SELECT d.id, d.title, p.project_name, p.latitude, p.longitude, 
                       c.page_number, c.chunk_text as excerpt, c.image_description
                FROM documents d
                JOIN projects p ON d.project_id = p.id
                LEFT JOIN doc_chunks c ON c.id = (
                    SELECT id FROM doc_chunks 
                    WHERE doc_id = d.id 
                    AND page_number IN (0, 1) 
                    ORDER BY page_number ASC LIMIT 1
                )
                ORDER BY d.created_at DESC
                LIMIT 30";
        $stmt = $pdo->query($sql);
        $search_results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $error_msg = "検索処理中にエラーが発生しました: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>高度資料検索 | AI SYSTEM</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <style>
        body { height: 100vh; overflow: hidden; }
        #map { height: 100%; width: 100%; z-index: 10; }
        .search-pane { height: calc(100vh - 128px); }
        .custom-scrollbar::-webkit-scrollbar { width: 4px; }
        .custom-scrollbar::-webkit-scrollbar-thumb { background: #cbd5e1; border-radius: 10px; }
        .line-clamp-2 { display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
    </style>
</head>
<body class="bg-gray-100 flex flex-col overflow-hidden text-slate-800">

<?php include_once __DIR__ . '/templates/header.php'; ?>

<!-- 検索バー -->
<div class="bg-white p-4 border-b shadow-sm flex justify-start items-center gap-4 z-20">
    <form action="" method="GET" class="flex gap-4">
        <div class="w-80 relative">
            <input type="text" name="q" value="<?= htmlspecialchars($keyword ?? '') ?>"
                   class="w-full bg-slate-50 border border-slate-300 rounded-lg p-2.5 text-xs outline-none focus:ring-2 focus:ring-[#4F5D95]/20" 
                   placeholder="キーワード、画像の内容などで検索...">
            <span class="absolute right-3 top-2.5 text-slate-400 opacity-50">🔍</span>
        </div>
        <button type="submit" class="bg-[#4F5D95] text-white px-10 rounded-lg text-xs font-black shadow-sm hover:bg-[#3f4a7a] transition">
            検索
        </button>
    </form>
    
    <?php if ($error_msg): ?>
        <div class="bg-red-50 text-red-500 text-[10px] px-3 py-1 rounded border border-red-100"><?= htmlspecialchars($error_msg) ?></div>
    <?php endif; ?>

    <div class="flex items-center text-xs text-gray-500 font-bold ml-auto">
        ヒット件数: <span class="text-lg text-[#4F5D95] ml-2"><?= count($search_results) ?></span> 件
    </div>
</div>

<main class="flex-1 flex overflow-hidden gap-px bg-gray-300 search-pane">
    <!-- 左ペイン: 地図 -->
    <div class="w-1/3 bg-blue-50 relative shadow-inner">
        <div id="map"></div>
    </div>

    <!-- 中央ペイン: 検索結果リスト -->
    <div class="w-1/3 bg-white flex flex-col overflow-hidden border-x border-gray-200">
        <div class="p-3 bg-gray-50 border-b flex justify-between items-center">
            <span class="text-[10px] font-black text-slate-400 uppercase tracking-widest">Search Results</span>
            <span class="text-[9px] text-[#4F5D95] font-bold italic">Visual & Text Insights</span>
        </div>
        <div class="flex-1 overflow-y-auto p-3 space-y-3 custom-scrollbar">
            <?php if ($keyword && empty($search_results) && !$error_msg): ?>
                <p class="text-center text-xs text-gray-400 py-10 italic">該当する資料は見つかりませんでした。</p>
            <?php endif; ?>

            <?php foreach ($search_results as $row): ?>
                <div onclick="previewPdf(<?= $row['id'] ?>, '<?= htmlspecialchars($row['title'], ENT_QUOTES) ?>', <?= $row['page_number'] ?? 1 ?>)"
                     id="card-<?= $row['id'] ?>-<?= $row['page_number'] ?? 1 ?>"
                     class="p-4 bg-white border border-slate-200 rounded-xl hover:border-[#4F5D95] hover:shadow-md cursor-pointer transition-all group active:scale-[0.98] shadow-sm">
                    
                    <div class="flex justify-between items-start mb-1">
                        <h3 class="text-xs font-bold text-slate-800 group-hover:text-[#4F5D95] leading-tight flex-1">📄 <?= htmlspecialchars($row['title']) ?></h3>
                        <?php if (isset($row['page_number']) && $row['page_number'] == 0): ?>
                            <span class="bg-amber-100 text-amber-700 text-[9px] px-2 py-0.5 rounded font-black whitespace-nowrap ml-2">全体要約</span>
                        <?php else: ?>
                            <span class="bg-blue-50 text-blue-700 text-[9px] px-2 py-0.5 rounded font-black whitespace-nowrap ml-2">P.<?= $row['page_number'] ?? 1 ?></span>
                        <?php endif; ?>
                    </div>

                    <div class="space-y-2">
                        <p class="text-[9px] text-slate-400 italic flex items-center gap-1">
                            <span class="opacity-50">📂</span> <?= htmlspecialchars($row['project_name']) ?>
                        </p>
                        
                        <!-- 本文抜粋 -->
                        <?php if (!empty($row['excerpt'])): ?>
                            <div class="text-[10px] text-slate-600 bg-slate-50 p-2 rounded border border-slate-100 line-clamp-2 leading-relaxed">
                                <?= htmlspecialchars(mb_substr(strip_tags($row['excerpt']), 0, 150)) ?>...
                            </div>
                        <?php endif; ?>

                        <!-- ★追加: 画像解析結果の表示 -->
                        <?php if (!empty($row['image_description'])): ?>
                            <div class="text-[9px] text-blue-700 bg-blue-50/50 p-2 rounded border border-blue-100 flex items-start gap-1.5 mt-1">
                                <span class="flex-shrink-0 mt-0.5">🖼️</span>
                                <div class="flex-1">
                                    <span class="font-bold block mb-0.5 opacity-70 uppercase tracking-tighter">AI Image Insight:</span>
                                    <div class="line-clamp-2 italic"><?= htmlspecialchars($row['image_description']) ?></div>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- 右ペイン: プレビュー -->
    <div class="w-1/3 bg-slate-100 flex flex-col shadow-inner">
        <div id="preview-header" class="bg-slate-700 text-white p-2 px-4 text-[9px] font-bold flex justify-between items-center hidden">
            <span id="preview-title" class="truncate mr-4 text-white">資料プレビュー</span>
            <button onclick="openFullViewer()" class="bg-white/10 hover:bg-white/20 px-2 py-1 rounded transition text-[8px] flex-shrink-0">👁️ 全画面表示</button>
        </div>
        <div id="preview-container" class="flex-1 flex items-center justify-center p-4">
            <div id="preview-placeholder" class="text-center text-slate-400 italic text-[10px]">
                <p class="text-3xl mb-2 opacity-20">📄</p>
                <p>資料を選択すると<br>該当ページが表示されます</p>
            </div>
            <iframe id="preview-iframe" class="w-full h-full border-none rounded shadow-lg hidden"></iframe>
        </div>
    </div>
</main>

<script>
    let map = null;
    let markers = [];
    let currentDocId = null;
    let currentPageNum = 1;

    function initMap() {
        map = L.map('map').setView([35.6812, 139.7671], 10);
        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '&copy; OpenStreetMap contributors'
        }).addTo(map);

        const allProjects = <?= json_encode($map_projects) ?>;
        allProjects.forEach(p => {
            if (p.latitude && p.longitude) {
                const marker = L.marker([p.latitude, p.longitude])
                    .addTo(map)
                    .bindPopup(`<b class="text-xs">${p.project_name}</b><br><span class="text-[10px]">${p.address || ''}</span>`);
                markers.push(marker);
            }
        });

        <?php if (!empty($search_results)): ?>
            const results = <?= json_encode($search_results) ?>;
            const resultCoords = results
                .filter(r => r.latitude && r.longitude)
                .map(r => [parseFloat(r.latitude), parseFloat(r.longitude)]);
            
            if (resultCoords.length > 0) {
                map.fitBounds(resultCoords, { padding: [50, 50] });
            }
        <?php endif; ?>
    }

    function previewPdf(id, title, page) {
        currentDocId = id;
        currentPageNum = page;
        
        const displayLabel = (page === 0) ? "全体要約" : `P.${page}`;
        document.getElementById('preview-placeholder').classList.add('hidden');
        document.getElementById('preview-header').classList.remove('hidden');
        document.getElementById('preview-title').textContent = `${title} (${displayLabel})`;
        
        const iframe = document.getElementById('preview-iframe');
        iframe.src = `viewer.php?id=${id}&page=${page}`;
        iframe.classList.remove('hidden');

        document.querySelectorAll('[id^="card-"]').forEach(el => el.classList.remove('border-[#4F5D95]', 'bg-blue-50/30', 'shadow-md'));
        const activeCard = document.getElementById(`card-${id}-${page}`);
        if(activeCard) {
            activeCard.classList.add('border-[#4F5D95]', 'bg-blue-50/30', 'shadow-md');
        }
    }

    function openFullViewer() {
        if (currentDocId) {
            window.open(`viewer.php?id=${currentDocId}&page=${currentPageNum}`, '_blank');
        }
    }

    document.addEventListener('DOMContentLoaded', initMap);
</script>

</body>
</html>