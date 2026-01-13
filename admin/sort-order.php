<?php
/**
 * 表示順管理
 * - ピックアップ: is_featured=1 の作品
 * - メンバー: creatorsテーブル
 * - ギャラリー: カテゴリ順 + カテゴリ別の作品（コレクション含む）
 *   ※ ギャラリーで並び替えるとメンバー内作品も同じ順番になる
 */
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/site-settings.php';
require_once '../includes/gallery-render.php';
requireAuth();

$db = getDB();
$message = '';

// カテゴリ順序を取得（共通関数を使用）
$defaultCategoryOrder = getGalleryCategoryOrder($db);

// 並び順保存処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    try {
        if ($_POST['action'] === 'save_order') {
            $type = $_POST['type'];
            $ids = json_decode($_POST['ids'], true);
            
            if ($type === 'category_order') {
                // カテゴリ順序を保存
                $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('gallery_category_order', ?) ON DUPLICATE KEY UPDATE setting_value = ?");
                $json = json_encode($ids);
                $stmt->execute([$json, $json]);
                $message = 'カテゴリ順序を保存しました';
            } else {
                // 通常の並び順保存
                $table = 'works';
                if ($type === 'creators') {
                    $table = 'creators';
                } elseif ($type === 'collections') {
                    $table = 'collections';
                }
                
                foreach ($ids as $index => $id) {
                    $stmt = $db->prepare("UPDATE {$table} SET sort_order = ? WHERE id = ?");
                    $stmt->execute([$index + 1, $id]);
                }
                $message = '並び順を保存しました';
            }
        }
    } catch (Exception $e) {
        $message = 'エラー: ' . $e->getMessage();
    }
}

// ピックアップ作品を取得
$pickupWorks = $db->query("
    SELECT w.*, c.name as creator_name 
    FROM works w 
    LEFT JOIN creators c ON w.creator_id = c.id 
    WHERE w.is_featured = 1 AND w.is_active = 1 
    ORDER BY w.sort_order ASC, w.id DESC
")->fetchAll();

// クリエイターを取得
$creators = $db->query("SELECT * FROM creators WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();

// カテゴリ別の作品を取得（共通関数を使用）
$worksByCategory = getGalleryWorksByCategory(null, $db);

// イラストカテゴリ用のコレクション（共通関数を使用）
$illustrationCollections = getGalleryCollections(null, $db);

$activeTab = $_GET['tab'] ?? 'pickup';

$pageTitle = "表示順設定";
include "includes/header.php";
?>

<style>
.sortable-ghost { opacity: 0.4; background: #fef3c7; }
.sortable-drag { background: #fff; box-shadow: 0 10px 40px rgba(0,0,0,0.2); }
.tab-content { display: none; }
.tab-content.active { display: block; }
.sort-item {
    user-select: none;
    -webkit-user-select: none;
}
.sort-item * { pointer-events: none; }
.category-item { transition: all 0.15s; }
.category-item:hover { transform: translateX(4px); }
</style>

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
                <button onclick="saveOrder('pickup', 'works')" class="bg-yellow-400 hover:bg-yellow-500 text-gray-900 px-4 py-2 rounded-lg font-bold text-sm transition">
                    <i class="fas fa-save mr-2"></i>保存
                </button>
            </div>
            <div id="sortable-pickup" class="space-y-2">
                <?php foreach ($pickupWorks as $work): ?>
                <div class="sort-item flex items-center gap-4 p-3 bg-gray-50 rounded-lg cursor-move hover:bg-yellow-50 transition border border-transparent hover:border-yellow-200" data-id="<?= $work['id'] ?>">
                    <i class="fas fa-grip-vertical text-gray-400"></i>
                    <?php if (!empty($work['image'])): ?>
                    <img src="../<?= htmlspecialchars($work['image']) ?>" class="w-16 h-12 object-cover rounded">
                    <?php else: ?>
                    <div class="w-16 h-12 bg-gray-200 rounded flex items-center justify-center text-gray-400"><i class="fas fa-image"></i></div>
                    <?php endif; ?>
                    <div class="flex-1 min-w-0">
                        <p class="font-bold text-gray-800 truncate"><?= htmlspecialchars($work['title'] ?: '無題') ?></p>
                        <p class="text-sm text-gray-500"><?= htmlspecialchars($work['creator_name'] ?? '未設定') ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php if (empty($pickupWorks)): ?>
                <div class="text-center py-12 text-gray-400">
                    <i class="fas fa-star text-4xl mb-4"></i>
                    <p>ピックアップ作品がありません</p>
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
                <button onclick="saveOrder('creators', 'creators')" class="bg-yellow-400 hover:bg-yellow-500 text-gray-900 px-4 py-2 rounded-lg font-bold text-sm transition">
                    <i class="fas fa-save mr-2"></i>保存
                </button>
            </div>
            <div id="sortable-creators" class="space-y-2">
                <?php foreach ($creators as $creator): ?>
                <div class="sort-item flex items-center gap-4 p-3 bg-gray-50 rounded-lg cursor-move hover:bg-yellow-50 transition border border-transparent hover:border-yellow-200" data-id="<?= $creator['id'] ?>">
                    <i class="fas fa-grip-vertical text-gray-400"></i>
                    <?php if (!empty($creator['image'])): ?>
                    <img src="../<?= htmlspecialchars($creator['image']) ?>" class="w-12 h-12 object-cover rounded-full">
                    <?php else: ?>
                    <div class="w-12 h-12 bg-gray-200 rounded-full flex items-center justify-center text-gray-400"><i class="fas fa-user"></i></div>
                    <?php endif; ?>
                    <div class="flex-1 min-w-0">
                        <p class="font-bold text-gray-800"><?= htmlspecialchars($creator['name']) ?></p>
                        <p class="text-sm text-gray-500"><?= htmlspecialchars($creator['role'] ?? '-') ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    
    <!-- ギャラリータブ -->
    <div id="tab-gallery" class="tab-content <?= $activeTab === 'gallery' ? 'active' : '' ?>">
        <!-- カテゴリ順序 -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
            <div class="flex justify-between items-center mb-4">
                <div>
                    <h3 class="font-bold text-lg text-gray-800">カテゴリの表示順序</h3>
                    <p class="text-sm text-gray-500">ギャラリーとメンバーページ両方に適用されます</p>
                </div>
                <button onclick="saveCategoryOrder()" class="bg-yellow-400 hover:bg-yellow-500 text-gray-900 px-4 py-2 rounded-lg font-bold text-sm transition">
                    <i class="fas fa-save mr-2"></i>カテゴリ順を保存
                </button>
            </div>
            <div id="sortable-categories" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-6 gap-3">
                <?php foreach ($defaultCategoryOrder as $cat): ?>
                <div class="category-item sort-item cursor-move p-4 bg-<?= $cat['color'] ?>-100 rounded-xl border-2 border-<?= $cat['color'] ?>-200 hover:border-<?= $cat['color'] ?>-400 text-center" data-id="<?= $cat['id'] ?>">
                    <i class="fas <?= $cat['icon'] ?> text-2xl text-<?= $cat['color'] ?>-500 mb-2"></i>
                    <p class="font-bold text-<?= $cat['color'] ?>-700 text-sm"><?= $cat['name'] ?></p>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        
        <!-- カテゴリ別作品 -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <div class="mb-4">
                <h3 class="font-bold text-lg text-gray-800">カテゴリ別の作品並び順</h3>
                <p class="text-sm text-gray-500">ここで並び替えると、ギャラリーとメンバーページ両方に反映されます</p>
            </div>
            
            <?php foreach ($defaultCategoryOrder as $cat): ?>
            <?php 
            $catId = $cat['id'];
            $catWorks = $worksByCategory[$catId] ?? [];
            $catCollections = ($catId === 'illustration') ? $illustrationCollections : [];
            $totalItems = count($catWorks) + count($catCollections);
            ?>
            <div class="mb-8 pb-6 border-b last:border-b-0">
                <div class="flex justify-between items-center mb-4">
                    <h4 class="font-bold flex items-center gap-2">
                        <i class="fas <?= $cat['icon'] ?> text-<?= $cat['color'] ?>-500"></i>
                        <span class="bg-<?= $cat['color'] ?>-100 text-<?= $cat['color'] ?>-700 px-3 py-1 rounded"><?= $cat['name'] ?></span>
                        <span class="text-sm text-gray-500">(<?= $totalItems ?>件)</span>
                    </h4>
                    <?php if ($totalItems > 0): ?>
                    <button onclick="saveOrder('gallery_<?= $catId ?>', '<?= $catId === 'illustration' ? 'mixed' : 'works' ?>')" class="bg-yellow-400 hover:bg-yellow-500 text-gray-900 px-4 py-2 rounded-lg font-bold text-sm transition">
                        <i class="fas fa-save mr-2"></i>保存
                    </button>
                    <?php endif; ?>
                </div>
                
                <?php if ($totalItems > 0): ?>
                <?php
                // アスペクト比とグリッドを決定
                $aspectClass = 'aspect-square';
                $gridClass = 'grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8 gap-3';
                if ($catId === 'manga') {
                    $aspectClass = 'aspect-[3/4]';
                } elseif ($catId === 'video' || $catId === 'animation') {
                    $aspectClass = 'aspect-video';
                    $gridClass = 'grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4';
                }
                
                // 作品とコレクションを統合
                $allItems = [];
                foreach ($catWorks as $work) {
                    $allItems[] = ['type' => 'work', 'data' => $work, 'sort_order' => $work['sort_order'] ?? 999];
                }
                foreach ($catCollections as $col) {
                    $allItems[] = ['type' => 'collection', 'data' => $col, 'sort_order' => $col['sort_order'] ?? 999];
                }
                usort($allItems, function($a, $b) {
                    return ($a['sort_order'] ?? 999) - ($b['sort_order'] ?? 999);
                });
                ?>
                <div id="sortable-gallery_<?= $catId ?>" class="<?= $gridClass ?>">
                    <?php foreach ($allItems as $item): ?>
                    <?php if ($item['type'] === 'work'): $work = $item['data']; ?>
                    <div class="sort-item cursor-move group" data-id="<?= $work['id'] ?>" data-type="work">
                        <div class="relative <?= $aspectClass ?> bg-gray-100 rounded-lg overflow-hidden border-2 border-transparent group-hover:border-yellow-400 transition">
                            <?php if (!empty($work['image'])): ?>
                            <img src="../<?= htmlspecialchars($work['image']) ?>" class="w-full h-full object-cover">
                            <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center text-gray-400"><i class="fas fa-image text-2xl"></i></div>
                            <?php endif; ?>
                            <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-30 transition flex items-center justify-center">
                                <i class="fas fa-grip-vertical text-white opacity-0 group-hover:opacity-100 text-2xl"></i>
                            </div>
                        </div>
                        <p class="text-xs text-center mt-1 truncate text-gray-600"><?= htmlspecialchars($work['title'] ?: '無題') ?></p>
                    </div>
                    <?php else: $col = $item['data']; ?>
                    <div class="sort-item cursor-move group" data-id="<?= $col['id'] ?>" data-type="collection">
                        <div class="relative <?= $aspectClass ?> bg-purple-100 rounded-lg overflow-hidden border-2 border-purple-300 group-hover:border-yellow-400 transition flex items-center justify-center">
                            <i class="fas fa-layer-group text-purple-400 text-3xl"></i>
                            <div class="absolute top-1 right-1 bg-purple-500 text-white text-xs px-1.5 py-0.5 rounded font-bold"><?= $col['sticker_count'] ?? 0 ?></div>
                            <div class="absolute inset-0 bg-black bg-opacity-0 group-hover:bg-opacity-30 transition flex items-center justify-center">
                                <i class="fas fa-grip-vertical text-white opacity-0 group-hover:opacity-100 text-2xl"></i>
                            </div>
                        </div>
                        <p class="text-xs text-center mt-1 truncate text-purple-600 font-bold"><?= htmlspecialchars($col['title'] ?: 'コレクション') ?></p>
                    </div>
                    <?php endif; ?>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center py-8 text-gray-400 bg-gray-50 rounded-lg">
                    <i class="fas <?= $cat['icon'] ?> text-3xl mb-2"></i>
                    <p>作品がありません</p>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
<script>
// タブ切り替え
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', function() {
        const tabId = this.dataset.tab;
        
        document.querySelectorAll('.tab-btn').forEach(b => {
            b.classList.remove('text-yellow-600', 'border-b-2', 'border-yellow-400', 'bg-yellow-50');
            b.classList.add('text-gray-600', 'hover:text-gray-900', 'hover:bg-gray-50');
        });
        this.classList.remove('text-gray-600', 'hover:text-gray-900', 'hover:bg-gray-50');
        this.classList.add('text-yellow-600', 'border-b-2', 'border-yellow-400', 'bg-yellow-50');
        
        document.querySelectorAll('.tab-content').forEach(content => content.classList.remove('active'));
        document.getElementById('tab-' + tabId).classList.add('active');
        
        const url = new URL(window.location);
        url.searchParams.set('tab', tabId);
        window.history.pushState({}, '', url);
    });
});

// Sortable初期化
function initSortable(elementId) {
    const el = document.getElementById(elementId);
    if (el && el.children.length > 0) {
        new Sortable(el, {
            animation: 150,
            ghostClass: 'sortable-ghost',
            dragClass: 'sortable-drag'
        });
    }
}

initSortable('sortable-pickup');
initSortable('sortable-creators');
initSortable('sortable-categories');

// ギャラリーのカテゴリごと
<?php foreach ($defaultCategoryOrder as $cat): ?>
initSortable('sortable-gallery_<?= $cat['id'] ?>');
<?php endforeach; ?>

// カテゴリ順序保存
function saveCategoryOrder() {
    const container = document.getElementById('sortable-categories');
    const ids = Array.from(container.children)
        .filter(el => el.dataset.id)
        .map(el => el.dataset.id);
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="save_order">
        <input type="hidden" name="type" value="category_order">
        <input type="hidden" name="ids" value='${JSON.stringify(ids)}'>
    `;
    document.body.appendChild(form);
    form.submit();
}

// 並び順保存
function saveOrder(type, tableType) {
    const container = document.getElementById('sortable-' + type);
    if (!container) {
        alert('保存対象が見つかりません');
        return;
    }
    
    // mixed（イラスト）の場合は作品とコレクションを分けて保存
    if (tableType === 'mixed') {
        const workIds = [];
        const collectionIds = [];
        Array.from(container.children).forEach((el, index) => {
            if (el.dataset.id) {
                if (el.dataset.type === 'collection') {
                    collectionIds.push({id: el.dataset.id, order: index + 1});
                } else {
                    workIds.push({id: el.dataset.id, order: index + 1});
                }
            }
        });
        
        // 両方を保存
        const promises = [];
        
        if (workIds.length > 0) {
            promises.push(fetch(window.location.pathname, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=save_order&type=works&ids=${encodeURIComponent(JSON.stringify(workIds.map(w => w.id)))}`
            }));
        }
        
        if (collectionIds.length > 0) {
            promises.push(fetch(window.location.pathname, {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `action=save_order&type=collections&ids=${encodeURIComponent(JSON.stringify(collectionIds.map(c => c.id)))}`
            }));
        }
        
        Promise.all(promises).then(() => {
            location.reload();
        });
        return;
    }
    
    // 通常の保存
    const ids = Array.from(container.children)
        .filter(el => el.dataset.id)
        .map(el => el.dataset.id);
    
    const table = (tableType === 'collections') ? 'collections' : 
                  (tableType === 'creators') ? 'creators' : 'works';
    
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input type="hidden" name="action" value="save_order">
        <input type="hidden" name="type" value="${table}">
        <input type="hidden" name="ids" value='${JSON.stringify(ids)}'>
    `;
    document.body.appendChild(form);
    form.submit();
}
</script>

<?php include "includes/footer.php"; ?>
