<?php
/**
 * 表示順管理
 */
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/site-settings.php';
requireAuth();

$db = getDB();
$message = '';

// 並び順保存処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'save_order') {
            $type = $_POST['type'];
            $ids = json_decode($_POST['ids'], true);
            
            $table = match($type) {
                'pickup', 'gallery' => 'works',
                'creators' => 'creators',
                default => throw new Exception('Invalid type')
            };
            
            foreach ($ids as $index => $id) {
                $stmt = $db->prepare("UPDATE {$table} SET sort_order = ? WHERE id = ?");
                $stmt->execute([$index + 1, $id]);
            }
        }
        $message = '並び順を保存しました';
    } catch (Exception $e) {
        $message = 'エラー: ' . $e->getMessage();
    }
}

// ピックアップ作品を取得
$pickupWorks = $db->query("SELECT w.*, c.name as creator_name FROM works w LEFT JOIN creators c ON w.creator_id = c.id WHERE w.is_featured = 1 AND w.is_active = 1 ORDER BY w.sort_order ASC, w.id DESC")->fetchAll();

// クリエイターを取得
$creators = $db->query("SELECT * FROM creators WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();

// ギャラリー作品（カテゴリ別）
$categories = ['illustration', 'manga', 'video', 'animation', 'music', 'live2d', 'logo', 'web'];
$worksByCategory = [];
foreach ($categories as $cat) {
    $stmt = $db->prepare("SELECT w.*, c.name as creator_name FROM works w LEFT JOIN creators c ON w.creator_id = c.id WHERE w.is_active = 1 AND w.category LIKE ? ORDER BY w.sort_order ASC, w.id DESC");
    $stmt->execute(['%' . $cat . '%']);
    $worksByCategory[$cat] = $stmt->fetchAll();
}

$categoryLabels = [
    'illustration' => 'イラスト',
    'manga' => 'マンガ',
    'video' => '動画',
    'animation' => 'アニメーション',
    'music' => '音楽',
    'live2d' => 'Live2D',
    'logo' => 'ロゴ',
    'web' => 'Webデザイン'
];

$activeTab = $_GET['tab'] ?? 'pickup';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>表示順管理 | 管理画面</title>
    <link rel="manifest" href="/admin/manifest.json">
    <?php $backyardFavicon = getBackyardFaviconInfo($db); ?>
    <link rel="icon" href="<?= htmlspecialchars($backyardFavicon['path']) ?>" type="<?= htmlspecialchars($backyardFavicon['type']) ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($backyardFavicon['path']) ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@400;500;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <style>
        body { font-family: 'Zen Maru Gothic', sans-serif; }
        .sortable-ghost { opacity: 0.4; background: #fef3c7; }
        .sortable-drag { background: white; box-shadow: 0 10px 20px rgba(0,0,0,0.2); }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
    </style>
</head>
<body class="bg-gray-50">
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="lg:ml-64 pt-20 lg:pt-8 p-8">
        <div class="max-w-6xl mx-auto">
            <div class="mb-8">
                <h2 class="text-2xl font-bold text-gray-800">表示順管理</h2>
                <p class="text-gray-500">ドラッグ&ドロップで表示順を変更できます</p>
            </div>
            
            <?php if ($message): ?>
            <div class="bg-green-50 border border-green-200 text-green-600 px-4 py-3 rounded-lg mb-6">
                <?= htmlspecialchars($message) ?>
            </div>
            <?php endif; ?>
            
            <!-- タブナビゲーション -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 mb-6">
                <div class="flex border-b overflow-x-auto">
                    <button class="tab-btn flex-shrink-0 px-6 py-4 font-bold text-sm transition <?= $activeTab === 'pickup' ? 'text-yellow-600 border-b-2 border-yellow-400 bg-yellow-50' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' ?>" data-tab="pickup">
                        <i class="fas fa-star mr-2"></i>ピックアップ
                        <span class="ml-2 bg-yellow-100 text-yellow-700 text-xs px-2 py-0.5 rounded-full font-bold"><?= count($pickupWorks) ?></span>
                    </button>
                    <button class="tab-btn flex-shrink-0 px-6 py-4 font-bold text-sm transition <?= $activeTab === 'creators' ? 'text-yellow-600 border-b-2 border-yellow-400 bg-yellow-50' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' ?>" data-tab="creators">
                        <i class="fas fa-users mr-2"></i>メンバー
                        <span class="ml-2 bg-blue-100 text-blue-700 text-xs px-2 py-0.5 rounded-full font-bold"><?= count($creators) ?></span>
                    </button>
                    <button class="tab-btn flex-shrink-0 px-6 py-4 font-bold text-sm transition <?= $activeTab === 'gallery' ? 'text-yellow-600 border-b-2 border-yellow-400 bg-yellow-50' : 'text-gray-600 hover:text-gray-900 hover:bg-gray-50' ?>" data-tab="gallery">
                        <i class="fas fa-images mr-2"></i>ギャラリー
                    </button>
                </div>
            </div>
            
            <!-- ピックアップタブ -->
            <div id="tab-pickup" class="tab-content <?= $activeTab === 'pickup' ? 'active' : '' ?>">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-bold text-lg text-gray-800">ピックアップ作品の並び順</h3>
                        <button onclick="saveOrder('pickup')" class="bg-yellow-400 hover:bg-yellow-500 text-gray-900 px-4 py-2 rounded-lg font-bold text-sm transition">
                            <i class="fas fa-save mr-2"></i>保存
                        </button>
                    </div>
                    <p class="text-gray-500 text-sm mb-4">ドラッグ&ドロップで並び替えできます</p>
                    <div id="sortable-pickup" class="space-y-2">
                        <?php foreach ($pickupWorks as $work): ?>
                        <div class="sortable-item flex items-center gap-4 p-3 bg-gray-50 rounded-lg cursor-move hover:bg-yellow-50 transition border border-transparent hover:border-yellow-200" data-id="<?= $work['id'] ?>">
                            <i class="fas fa-grip-vertical text-gray-400"></i>
                            <?php if (!empty($work['image'])): ?>
                            <img src="../<?= htmlspecialchars($work['image']) ?>" class="w-16 h-12 object-cover rounded">
                            <?php else: ?>
                            <div class="w-16 h-12 bg-gray-200 rounded flex items-center justify-center text-gray-400">
                                <i class="fas fa-image"></i>
                            </div>
                            <?php endif; ?>
                            <div class="flex-1 min-w-0">
                                <p class="font-bold text-gray-800 truncate"><?= htmlspecialchars($work['title'] ?: '無題') ?></p>
                                <p class="text-sm text-gray-500"><?= htmlspecialchars($work['creator_name'] ?? '未設定') ?></p>
                            </div>
                            <span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded"><?= htmlspecialchars($categoryLabels[$work['category']] ?? $work['category'] ?? '-') ?></span>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($pickupWorks)): ?>
                        <div class="text-center py-12 text-gray-400">
                            <i class="fas fa-star text-4xl mb-4"></i>
                            <p>ピックアップ作品がありません</p>
                            <p class="text-sm mt-2">作品管理で「ピックアップに表示」にチェックを入れてください</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- メンバータブ -->
            <div id="tab-creators" class="tab-content <?= $activeTab === 'creators' ? 'active' : '' ?>">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-bold text-lg text-gray-800">メンバーの並び順</h3>
                        <button onclick="saveOrder('creators')" class="bg-yellow-400 hover:bg-yellow-500 text-gray-900 px-4 py-2 rounded-lg font-bold text-sm transition">
                            <i class="fas fa-save mr-2"></i>保存
                        </button>
                    </div>
                    <p class="text-gray-500 text-sm mb-4">ドラッグ&ドロップで並び替えできます</p>
                    <div id="sortable-creators" class="space-y-2">
                        <?php foreach ($creators as $creator): ?>
                        <div class="sortable-item flex items-center gap-4 p-3 bg-gray-50 rounded-lg cursor-move hover:bg-yellow-50 transition border border-transparent hover:border-yellow-200" data-id="<?= $creator['id'] ?>">
                            <i class="fas fa-grip-vertical text-gray-400"></i>
                            <?php if (!empty($creator['image'])): ?>
                            <img src="../<?= htmlspecialchars($creator['image']) ?>" class="w-12 h-12 object-cover rounded-full">
                            <?php else: ?>
                            <div class="w-12 h-12 bg-gray-200 rounded-full flex items-center justify-center text-gray-400">
                                <i class="fas fa-user"></i>
                            </div>
                            <?php endif; ?>
                            <div class="flex-1 min-w-0">
                                <p class="font-bold text-gray-800 truncate"><?= htmlspecialchars($creator['name']) ?></p>
                                <p class="text-sm text-gray-500"><?= htmlspecialchars($creator['role'] ?? '未設定') ?></p>
                            </div>
                            <?php if (!empty($creator['role'])): ?>
                            <span class="text-xs bg-blue-100 text-blue-600 px-2 py-1 rounded font-bold"><?= htmlspecialchars($creator['role']) ?></span>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($creators)): ?>
                        <div class="text-center py-12 text-gray-400">
                            <i class="fas fa-users text-4xl mb-4"></i>
                            <p>メンバーがいません</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <!-- ギャラリータブ -->
            <div id="tab-gallery" class="tab-content <?= $activeTab === 'gallery' ? 'active' : '' ?>">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="flex justify-between items-center mb-4">
                        <h3 class="font-bold text-lg text-gray-800">ギャラリー作品の並び順</h3>
                        <button onclick="saveAllGallery()" class="bg-yellow-400 hover:bg-yellow-500 text-gray-900 px-4 py-2 rounded-lg font-bold text-sm transition">
                            <i class="fas fa-save mr-2"></i>すべて保存
                        </button>
                    </div>
                    <p class="text-gray-500 text-sm mb-6">カテゴリごとにドラッグ&ドロップで並び替えできます</p>
                    
                    <?php foreach ($categories as $cat): ?>
                    <?php if (!empty($worksByCategory[$cat])): ?>
                    <div class="mb-8">
                        <h4 class="font-bold mb-3 flex items-center gap-2">
                            <span class="bg-gray-200 text-gray-700 px-3 py-1 rounded"><?= $categoryLabels[$cat] ?></span>
                            <span class="text-sm text-gray-500">(<?= count($worksByCategory[$cat]) ?>件)</span>
                        </h4>
                        <div id="sortable-gallery-<?= $cat ?>" class="sortable-gallery grid grid-cols-3 md:grid-cols-5 lg:grid-cols-7 gap-3" data-category="<?= $cat ?>">
                            <?php foreach ($worksByCategory[$cat] as $work): ?>
                            <div class="sortable-item cursor-move group" data-id="<?= $work['id'] ?>">
                                <div class="relative aspect-square bg-gray-100 rounded-lg overflow-hidden border-2 border-transparent group-hover:border-yellow-400 transition">
                                    <?php if (!empty($work['image'])): ?>
                                    <img src="../<?= htmlspecialchars($work['image']) ?>" class="w-full h-full object-cover group-hover:scale-105 transition">
                                    <?php else: ?>
                                    <div class="w-full h-full flex items-center justify-center text-gray-400">
                                        <i class="fas fa-image text-2xl"></i>
                                    </div>
                                    <?php endif; ?>
                                    <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-30 transition flex items-center justify-center">
                                        <i class="fas fa-grip-vertical text-white opacity-0 group-hover:opacity-100 text-2xl"></i>
                                    </div>
                                </div>
                                <p class="text-xs text-center mt-1 truncate text-gray-600"><?= htmlspecialchars($work['title'] ?: '無題') ?></p>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php endforeach; ?>
                    
                    <?php 
                    $hasAnyWorks = false;
                    foreach ($worksByCategory as $works) {
                        if (!empty($works)) $hasAnyWorks = true;
                    }
                    if (!$hasAnyWorks): 
                    ?>
                    <div class="text-center py-12 text-gray-400">
                        <i class="fas fa-images text-4xl mb-4"></i>
                        <p>作品がありません</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
    
    <script>
    // タブ切り替え
    document.querySelectorAll('.tab-btn').forEach(btn => {
        btn.addEventListener('click', function() {
            const tabId = this.dataset.tab;
            
            // タブボタンのスタイル切り替え
            document.querySelectorAll('.tab-btn').forEach(b => {
                b.classList.remove('text-yellow-600', 'border-b-2', 'border-yellow-400', 'bg-yellow-50');
                b.classList.add('text-gray-600', 'hover:text-gray-900', 'hover:bg-gray-50');
            });
            this.classList.remove('text-gray-600', 'hover:text-gray-900', 'hover:bg-gray-50');
            this.classList.add('text-yellow-600', 'border-b-2', 'border-yellow-400', 'bg-yellow-50');
            
            // タブコンテンツの切り替え
            document.querySelectorAll('.tab-content').forEach(content => {
                content.classList.remove('active');
            });
            document.getElementById('tab-' + tabId).classList.add('active');
            
            // URLを更新（リロードなし）
            const url = new URL(window.location);
            url.searchParams.set('tab', tabId);
            window.history.pushState({}, '', url);
        });
    });
    
    // Sortable初期化
    const pickupSortable = document.getElementById('sortable-pickup');
    if (pickupSortable) {
        new Sortable(pickupSortable, {
            animation: 150,
            ghostClass: 'sortable-ghost',
            dragClass: 'sortable-drag'
        });
    }
    
    const creatorsSortable = document.getElementById('sortable-creators');
    if (creatorsSortable) {
        new Sortable(creatorsSortable, {
            animation: 150,
            ghostClass: 'sortable-ghost',
            dragClass: 'sortable-drag'
        });
    }
    
    document.querySelectorAll('.sortable-gallery').forEach(el => {
        new Sortable(el, {
            animation: 150,
            ghostClass: 'sortable-ghost',
            dragClass: 'sortable-drag'
        });
    });
    
    // 並び順保存
    function saveOrder(type) {
        const container = document.getElementById('sortable-' + type);
        const ids = Array.from(container.children)
            .filter(el => el.dataset.id)
            .map(el => el.dataset.id);
        
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="save_order">
            <input type="hidden" name="type" value="${type}">
            <input type="hidden" name="ids" value='${JSON.stringify(ids)}'>
        `;
        document.body.appendChild(form);
        form.submit();
    }
    
    // ギャラリー全保存
    function saveAllGallery() {
        const galleries = document.querySelectorAll('.sortable-gallery');
        let allIds = [];
        
        galleries.forEach(gallery => {
            const ids = Array.from(gallery.children)
                .filter(el => el.dataset.id)
                .map(el => el.dataset.id);
            allIds = allIds.concat(ids);
        });
        
        const form = document.createElement('form');
        form.method = 'POST';
        form.innerHTML = `
            <input type="hidden" name="action" value="save_order">
            <input type="hidden" name="type" value="gallery">
            <input type="hidden" name="ids" value='${JSON.stringify(allIds)}'>
        `;
        document.body.appendChild(form);
        form.submit();
    }
    </script>
</body>
</html>
