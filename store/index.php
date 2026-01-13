<?php
/**
 * ストアトップページ（カテゴリフィルター対応）
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/member-auth.php';
require_once '../includes/cart.php';
require_once '../includes/site-settings.php';
require_once '../includes/defaults.php';

$db = getDB();

// 検索クエリ
$search = trim($_GET['q'] ?? '');

// クリエイターフィルター
$creatorFilter = (int)($_GET['creator'] ?? 0);
$filterCreator = null;
if ($creatorFilter > 0) {
    try {
        $stmt = $db->prepare("SELECT id, name, slug, image FROM creators WHERE id = ? AND is_active = 1");
        $stmt->execute([$creatorFilter]);
        $filterCreator = $stmt->fetch();
    } catch (PDOException $e) {}
}

// カテゴリ一覧取得
$categories = [];
try {
    $stmt = $db->query("SELECT * FROM product_categories WHERE is_active = 1 ORDER BY sort_order");
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $categories = DEFAULT_PRODUCT_CATEGORIES;
}

// お知らせ取得
$announcements = [];
try {
    $stmt = $db->query("
        SELECT * FROM store_announcements 
        WHERE is_published = 1 
        AND (publish_start IS NULL OR publish_start <= NOW())
        AND (publish_end IS NULL OR publish_end >= NOW())
        ORDER BY sort_order, created_at DESC
        LIMIT 3
    ");
    $announcements = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// 全商品取得（フィルタはJavaScriptで行う）
// show_in_* カラムが存在するか確認
$hasProductDisplaySettings = false;
try {
    $productColumns = $db->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
    $hasProductDisplaySettings = in_array('show_in_gallery', $productColumns);
} catch (PDOException $e) {
    $hasProductDisplaySettings = false;
}

$sql = "SELECT p.*, c.name as creator_name,
        (SELECT COUNT(*) FROM work_pages wp WHERE wp.work_id = p.related_work_id) as page_count
        FROM products p 
        LEFT JOIN creators c ON p.creator_id = c.id 
        WHERE p.is_published = 1";

$params = [];

// クリエイターフィルター
if ($creatorFilter > 0 && $filterCreator) {
    $sql .= " AND p.creator_id = ?";
    $params[] = $creatorFilter;
}

if ($search) {
    $sql .= " AND (p.name LIKE ? OR p.description LIKE ? OR p.short_description LIKE ? OR c.name LIKE ?)";
    $searchTerm = '%' . $search . '%';
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
    $params[] = $searchTerm;
}

$sql .= " ORDER BY p.is_featured DESC, p.created_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$products = $stmt->fetchAll(PDO::FETCH_ASSOC);

// お気に入り商品IDを取得
$favoriteProductIds = [];
if (isLoggedIn() && isset($_SESSION['member_id'])) {
    $stmt = $db->prepare("SELECT product_id FROM member_favorites WHERE member_id = ?");
    $stmt->execute([$_SESSION['member_id']]);
    $favoriteProductIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// ページタイトル設定
if ($filterCreator) {
    $pageTitle = htmlspecialchars($filterCreator['name']) . 'さんのグッズ';
} elseif ($search) {
    $pageTitle = '「' . htmlspecialchars($search) . '」の検索結果';
} else {
    $pageTitle = 'ストア';
}

include 'includes/header.php';
?>

<style>
/* フィルターボタンのスタイル */
.category-filter {
    transition: all 0.2s ease;
}
.category-filter.active {
    background-color: var(--store-primary, #FF6B35);
    color: white;
    border-color: var(--store-primary, #FF6B35);
}
.category-filter:not(.active):hover {
    background-color: #f3f4f6;
}

/* 商品カードのアニメーション */
.product-card {
    transition: opacity 0.3s ease, transform 0.3s ease;
}
.product-card.hidden-filter {
    display: none;
}
.product-card.fade-out {
    opacity: 0;
    transform: scale(0.95);
}
</style>

<?php if ($filterCreator): ?>
<!-- クリエイターフィルターヘッダー -->
<div class="bg-white rounded-xl shadow-sm p-4 mb-6 flex items-center justify-between">
    <div class="flex items-center gap-3">
        <?php if (!empty($filterCreator['image'])): ?>
        <img src="/<?= htmlspecialchars($filterCreator['image']) ?>" alt="" class="w-12 h-12 rounded-full object-cover">
        <?php else: ?>
        <div class="w-12 h-12 rounded-full bg-gray-200 flex items-center justify-center">
            <i class="fas fa-user text-gray-400"></i>
        </div>
        <?php endif; ?>
        <div>
            <p class="font-bold text-gray-800"><?= htmlspecialchars($filterCreator['name']) ?>さんのグッズ</p>
            <p class="text-sm text-gray-500"><?= count($products) ?>件の商品</p>
        </div>
    </div>
    <div class="flex gap-2">
        <a href="/creator/<?= htmlspecialchars($filterCreator['slug']) ?>" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 text-sm font-bold">
            <i class="fas fa-arrow-left mr-1"></i>クリエイターページ
        </a>
        <a href="/store/" class="px-4 py-2 bg-gray-100 text-gray-700 rounded-lg hover:bg-gray-200 text-sm font-bold">
            すべての商品
        </a>
    </div>
</div>
<?php endif; ?>

<?php if (!$search && !$filterCreator): ?>
<!-- お知らせ -->
<?php if (!empty($announcements)): ?>
<section class="mb-6">
    <div class="space-y-2">
        <?php foreach ($announcements as $ann): 
            $typeColors = [
                'info' => 'bg-blue-50 border-blue-200 text-blue-700',
                'important' => 'bg-red-50 border-red-200 text-red-700',
                'campaign' => 'bg-yellow-50 border-yellow-200 text-yellow-700',
                'maintenance' => 'bg-gray-50 border-gray-200 text-gray-700',
            ];
            $typeIcons = [
                'info' => 'fa-info-circle',
                'important' => 'fa-exclamation-circle',
                'campaign' => 'fa-gift',
                'maintenance' => 'fa-wrench',
            ];
            $color = $typeColors[$ann['type']] ?? $typeColors['info'];
            $icon = $typeIcons[$ann['type']] ?? $typeIcons['info'];
        ?>
        <div class="<?= $color ?> border rounded-lg px-4 py-3 flex items-start gap-3">
            <i class="fas <?= $icon ?> mt-0.5"></i>
            <div class="flex-1">
                <p class="font-bold text-sm"><?= htmlspecialchars($ann['title']) ?></p>
                <?php if ($ann['content']): ?>
                <p class="text-xs mt-1 opacity-80"><?= htmlspecialchars($ann['content']) ?></p>
                <?php endif; ?>
                <?php if ($ann['link_url']): ?>
                <a href="<?= htmlspecialchars($ann['link_url']) ?>" class="text-xs underline mt-1 inline-block">
                    <?= htmlspecialchars($ann['link_text'] ?: '詳細を見る') ?> →
                </a>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<!-- カテゴリフィルター -->
<section class="mb-6">
    <div class="flex flex-wrap gap-2" id="category-filters">
        <!-- すべて -->
        <button class="category-filter active px-4 py-2 rounded-full text-sm font-bold border border-gray-200 bg-white" 
                data-category="all" data-type="all">
            すべて
        </button>
        
        <!-- タイプ別 -->
        <button class="category-filter px-4 py-2 rounded-full text-sm font-bold border border-gray-200 bg-white" 
                data-category="all" data-type="digital">
            <i class="fas fa-download mr-1 text-xs"></i>デジタル
        </button>
        <button class="category-filter px-4 py-2 rounded-full text-sm font-bold border border-gray-200 bg-white" 
                data-category="all" data-type="physical">
            <i class="fas fa-box mr-1 text-xs"></i>物販
        </button>
        
        <!-- カテゴリ別 -->
        <?php foreach ($categories as $cat): ?>
        <button class="category-filter px-4 py-2 rounded-full text-sm font-bold border border-gray-200 bg-white" 
                data-category="<?= htmlspecialchars($cat['slug']) ?>" data-type="all">
            <i class="fas <?= htmlspecialchars($cat['icon'] ?? 'fa-tag') ?> mr-1 text-xs" style="color: <?= htmlspecialchars($cat['color'] ?? '#FF6B35') ?>"></i>
            <?= htmlspecialchars($cat['name']) ?>
        </button>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<?php if ($search): ?>
<!-- 検索結果 -->
<section class="mb-4">
    <div class="flex items-center gap-2">
        <span class="text-gray-600">「<?= htmlspecialchars($search) ?>」の検索結果</span>
        <span class="text-sm text-gray-500" id="result-count">(<?= count($products) ?>件)</span>
        <a href="/store/" class="ml-auto text-sm text-store-primary hover:underline">クリア</a>
    </div>
</section>
<?php endif; ?>

<?php if (!$filterCreator && !$search): ?>
<!-- サービスへのリンク -->
<section class="mb-6">
    <a href="/store/services/" class="block bg-white border border-gray-200 rounded-xl p-4 hover:bg-gray-50 hover:border-gray-300 transition shadow-sm">
        <div class="flex items-center justify-between">
            <div class="flex items-center gap-4">
                <div class="w-10 h-10 bg-purple-100 rounded-lg flex items-center justify-center">
                    <i class="fas fa-paint-brush text-purple-500"></i>
                </div>
                <div>
                    <h3 class="font-bold text-gray-800">スキルマーケット</h3>
                    <p class="text-gray-500 text-sm">クリエイターに直接依頼できます</p>
                </div>
            </div>
            <i class="fas fa-chevron-right text-gray-400"></i>
        </div>
    </a>
</section>
<?php endif; ?>

<!-- 商品一覧 -->
<section>
    <div class="flex items-center justify-between mb-4">
        <h2 class="text-lg font-bold text-gray-800" id="section-title">
            すべての商品
            <span class="text-sm font-normal text-gray-500" id="product-count">(<?= count($products) ?>件)</span>
        </h2>
        <select id="sort-select" class="text-sm border rounded px-2 py-1">
            <option value="new">新着順</option>
            <option value="price_low">価格が安い順</option>
            <option value="price_high">価格が高い順</option>
            <option value="popular">人気順</option>
        </select>
    </div>
    
    <?php if (empty($products)): ?>
    <div class="bg-white rounded-lg p-12 text-center" id="empty-message">
        <i class="fas fa-box-open text-gray-300 text-4xl mb-4"></i>
        <p class="text-gray-500">商品が見つかりませんでした</p>
        <?php if ($search): ?>
        <a href="/store/" class="inline-block mt-4 text-store-primary hover:underline">すべての商品を見る</a>
        <?php endif; ?>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3" id="products-grid">
        <?php foreach ($products as $product): 
            $stock = $product['stock_quantity'] ?? null;
            $isFavorite = in_array($product['id'], $favoriteProductIds);
            $previewPages = $product['preview_pages'] ?? 3;
        ?>
        <a href="/store/product.php?id=<?= $product['id'] ?>" 
           class="product-card bg-white rounded-lg shadow-sm overflow-hidden hover:shadow-md transition"
           data-category="<?= htmlspecialchars($product['category'] ?? '') ?>"
           data-type="<?= htmlspecialchars($product['product_type']) ?>"
           data-price="<?= $product['price'] ?>"
           data-featured="<?= $product['is_featured'] ? '1' : '0' ?>"
           data-date="<?= $product['created_at'] ?>">
            <div class="relative bg-gray-50 aspect-square overflow-hidden">
                <?php if ($product['image']): ?>
                <img src="/<?= htmlspecialchars($product['image']) ?>" alt="" class="w-full h-full object-contain">
                <?php else: ?>
                <div class="w-full h-full bg-gray-100 flex items-center justify-center"><i class="fas fa-image text-gray-300 text-2xl"></i></div>
                <?php endif; ?>
                <div class="absolute top-2 left-2 flex flex-col gap-1">
                    <?php if ($product['product_type'] === 'digital'): ?>
                    <span class="bg-yellow-400 text-gray-800 text-xs font-bold px-2 py-0.5 rounded">DL</span>
                    <?php endif; ?>
                    <?php if ($stock !== null && $stock <= 5 && $stock > 0): ?>
                    <span class="bg-red-500 text-white text-xs font-bold px-2 py-0.5 rounded">残りわずか</span>
                    <?php elseif ($stock === 0 && $product['product_type'] !== 'digital'): ?>
                    <span class="bg-gray-500 text-white text-xs font-bold px-2 py-0.5 rounded">売切れ</span>
                    <?php endif; ?>
                </div>
                <?php if ($isFavorite): ?>
                <div class="absolute top-2 right-2">
                    <span class="w-7 h-7 bg-pink-500 rounded-full flex items-center justify-center shadow">
                        <i class="fas fa-heart text-white text-xs"></i>
                    </span>
                </div>
                <?php endif; ?>
                <?php if ($product['page_count'] > 0): ?>
                <div class="absolute bottom-2 right-2">
                    <span class="bg-purple-500 text-white text-xs font-bold px-2 py-0.5 rounded">
                        <?= $previewPages ?>P試読
                    </span>
                </div>
                <?php endif; ?>
            </div>
            <div class="p-3">
                <h3 class="text-sm font-bold text-gray-800 line-clamp-2"><?= htmlspecialchars($product['name']) ?></h3>
                <?php if ($product['creator_name']): ?>
                <p class="text-xs text-gray-500 mt-1"><?= htmlspecialchars($product['creator_name']) ?></p>
                <?php endif; ?>
                <p class="text-base font-bold text-store-primary mt-1"><?= formatPrice($product['price']) ?></p>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    
    <!-- フィルター結果が0件の時のメッセージ（初期は非表示） -->
    <div class="bg-white rounded-lg p-12 text-center hidden" id="no-results">
        <i class="fas fa-search text-gray-300 text-4xl mb-4"></i>
        <p class="text-gray-500">該当する商品がありません</p>
        <button onclick="resetFilter()" class="inline-block mt-4 text-store-primary hover:underline">すべての商品を表示</button>
    </div>
    <?php endif; ?>
</section>

<!-- FAQへのリンク -->
<section class="mt-8 bg-gray-100 rounded-xl p-4 text-center">
    <p class="text-sm text-gray-600 mb-2">お困りですか？</p>
    <a href="/store/faq.php" class="inline-flex items-center gap-2 text-store-primary font-bold hover:underline">
        <i class="fas fa-question-circle"></i> よくある質問を見る
    </a>
</section>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const filters = document.querySelectorAll('.category-filter');
    const products = document.querySelectorAll('.product-card');
    const grid = document.getElementById('products-grid');
    const noResults = document.getElementById('no-results');
    const productCount = document.getElementById('product-count');
    const sectionTitle = document.getElementById('section-title');
    const sortSelect = document.getElementById('sort-select');
    
    let currentCategory = 'all';
    let currentType = 'all';
    let currentSort = 'new';
    
    // カテゴリ名マッピング
    const categoryNames = {
        'all': 'すべての商品',
        <?php foreach ($categories as $cat): ?>
        '<?= $cat['slug'] ?>': '<?= $cat['name'] ?>',
        <?php endforeach; ?>
    };
    
    const typeNames = {
        'digital': 'デジタル商品',
        'physical': 'グッズ・物販'
    };
    
    // フィルターボタンクリック
    filters.forEach(btn => {
        btn.addEventListener('click', function() {
            // アクティブ状態を切り替え
            filters.forEach(f => f.classList.remove('active'));
            this.classList.add('active');
            
            currentCategory = this.dataset.category;
            currentType = this.dataset.type;
            
            applyFilter();
        });
    });
    
    // ソート変更
    if (sortSelect) {
        sortSelect.addEventListener('change', function() {
            currentSort = this.value;
            applyFilter();
        });
    }
    
    function applyFilter() {
        let visibleCount = 0;
        const productArray = Array.from(products);
        
        // フィルタリング
        productArray.forEach(card => {
            const cardCategory = card.dataset.category || '';
            const cardType = card.dataset.type || '';
            
            const matchCategory = currentCategory === 'all' || cardCategory === currentCategory;
            const matchType = currentType === 'all' || cardType === currentType;
            
            if (matchCategory && matchType) {
                card.classList.remove('hidden-filter');
                visibleCount++;
            } else {
                card.classList.add('hidden-filter');
            }
        });
        
        // ソート
        const visibleProducts = productArray.filter(p => !p.classList.contains('hidden-filter'));
        visibleProducts.sort((a, b) => {
            switch (currentSort) {
                case 'price_low':
                    return parseInt(a.dataset.price) - parseInt(b.dataset.price);
                case 'price_high':
                    return parseInt(b.dataset.price) - parseInt(a.dataset.price);
                case 'popular':
                    return parseInt(b.dataset.featured) - parseInt(a.dataset.featured);
                default: // new
                    return new Date(b.dataset.date) - new Date(a.dataset.date);
            }
        });
        
        // 並び替えを適用
        visibleProducts.forEach(p => grid.appendChild(p));
        
        // 件数更新
        if (productCount) {
            productCount.textContent = `(${visibleCount}件)`;
        }
        
        // タイトル更新
        if (sectionTitle) {
            let title = categoryNames[currentCategory] || 'すべての商品';
            if (currentType !== 'all') {
                title = typeNames[currentType] || title;
            }
            sectionTitle.innerHTML = `${title} <span class="text-sm font-normal text-gray-500">(${visibleCount}件)</span>`;
        }
        
        // 0件メッセージ
        if (noResults && grid) {
            if (visibleCount === 0) {
                grid.classList.add('hidden');
                noResults.classList.remove('hidden');
            } else {
                grid.classList.remove('hidden');
                noResults.classList.add('hidden');
            }
        }
    }
    
    // フィルターリセット
    window.resetFilter = function() {
        filters.forEach(f => f.classList.remove('active'));
        filters[0].classList.add('active');
        currentCategory = 'all';
        currentType = 'all';
        applyFilter();
    };
});
</script>

<?php include 'includes/footer.php'; ?>
