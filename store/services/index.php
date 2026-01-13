<?php
/**
 * サービス一覧ページ
 */
session_start();
require_once '../../includes/db.php';
require_once '../../includes/site-settings.php';
require_once '../../includes/member-auth.php';
require_once '../../includes/cart.php';

$db = getDB();

// フィルター
$categoryFilter = $_GET['category'] ?? '';
$creatorFilter = (int)($_GET['creator'] ?? 0);
$searchQuery = trim($_GET['q'] ?? '');
$priceMin = (int)($_GET['price_min'] ?? 0);
$priceMax = (int)($_GET['price_max'] ?? 0);
$sortBy = $_GET['sort'] ?? 'recommended';

// クリエイター情報取得
$filterCreator = null;
if ($creatorFilter > 0) {
    try {
        $stmt = $db->prepare("SELECT id, name, slug, image FROM creators WHERE id = ?");
        $stmt->execute([$creatorFilter]);
        $filterCreator = $stmt->fetch();
    } catch (PDOException $e) {}
}

// サービス取得
$services = [];
if ($creatorFilter > 0 && $filterCreator) {
    // クリエイターでフィルター
    try {
        $stmt = $db->prepare("
            SELECT s.*, c.name as creator_name, c.image as creator_image, c.slug as creator_slug
            FROM services s
            LEFT JOIN creators c ON s.creator_id = c.id
            WHERE s.creator_id = ? AND s.status = 'active'
              AND (s.approval_status = 'approved' OR s.approval_status IS NULL)
              AND (s.show_in_store = 1 OR s.show_in_store IS NULL)
            ORDER BY s.sort_order, s.created_at DESC
        ");
        $stmt->execute([$creatorFilter]);
        $services = $stmt->fetchAll();
    } catch (PDOException $e) {
        $services = [];
    }
    $pageTitle = htmlspecialchars($filterCreator['name']) . 'さんのサービス';
} else {
    // 検索・フィルター
    try {
        $sql = "
            SELECT s.*, c.name as creator_name, c.image as creator_image, c.slug as creator_slug,
                   s.category as category_name
            FROM services s
            LEFT JOIN creators c ON s.creator_id = c.id
            WHERE s.status = 'active'
              AND (s.approval_status = 'approved' OR s.approval_status IS NULL)
              AND (s.show_in_store = 1 OR s.show_in_store IS NULL)
        ";
        $params = [];
        
        // キーワード検索
        if ($searchQuery) {
            $sql .= " AND (s.title LIKE ? OR s.description LIKE ? OR c.name LIKE ? OR s.usage_tags LIKE ? OR s.genre_tags LIKE ?)";
            $likeQuery = "%{$searchQuery}%";
            $params = array_merge($params, [$likeQuery, $likeQuery, $likeQuery, $likeQuery, $likeQuery]);
        }
        
        // カテゴリフィルター
        if ($categoryFilter) {
            $sql .= " AND s.category = ?";
            $params[] = $categoryFilter;
        }
        
        // 価格フィルター
        if ($priceMin > 0) {
            $sql .= " AND s.base_price >= ?";
            $params[] = $priceMin;
        }
        if ($priceMax > 0) {
            $sql .= " AND s.base_price <= ?";
            $params[] = $priceMax;
        }
        
        // ソート
        switch ($sortBy) {
            case 'price_asc':
                $sql .= " ORDER BY s.base_price ASC";
                break;
            case 'price_desc':
                $sql .= " ORDER BY s.base_price DESC";
                break;
            case 'newest':
                $sql .= " ORDER BY s.created_at DESC";
                break;
            case 'popular':
                $sql .= " ORDER BY s.order_count DESC, s.view_count DESC";
                break;
            case 'rating':
                $sql .= " ORDER BY s.rating_avg DESC, s.rating_count DESC";
                break;
            default: // recommended
                $sql .= " ORDER BY s.is_featured DESC, s.sort_order, s.created_at DESC";
        }
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $services = $stmt->fetchAll();
    } catch (PDOException $e) {
        $services = [];
    }
    
    if ($searchQuery) {
        $pageTitle = "「{$searchQuery}」の検索結果";
    } elseif ($categoryFilter) {
        $pageTitle = $categoryFilter . ' - サービス一覧';
    } else {
        $pageTitle = 'サービス一覧';
    }
}

require_once '../includes/header.php';

// おすすめサービス（トップページ用）
$featuredServices = (!$searchQuery && !$categoryFilter && !$filterCreator) ? getFeaturedServices(4) : [];

// カテゴリ一覧（service_categoriesテーブルから取得、なければservicesテーブルから）
$categories = [];
try {
    $stmt = $db->query("SELECT id, name, slug, icon FROM service_categories WHERE is_active = 1 ORDER BY sort_order, name");
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    // service_categoriesテーブルがない場合はservicesから取得
    try {
        $stmt = $db->query("SELECT DISTINCT category as name FROM services WHERE status = 'active' AND category IS NOT NULL AND category != '' ORDER BY category");
        $categories = $stmt->fetchAll();
    } catch (PDOException $e2) {
        $categories = [];
    }
}
?>

<div class="max-w-6xl mx-auto px-4 py-8">
    <?php if ($filterCreator): ?>
    <!-- クリエイターでフィルター中 -->
    <div class="mb-8">
        <a href="/creator/<?= htmlspecialchars($filterCreator['slug'] ?? '') ?>" class="text-gray-500 hover:text-gray-700 mb-4 inline-block">
            <i class="fas fa-arrow-left mr-1"></i>クリエイターページに戻る
        </a>
        
        <div class="flex items-center gap-4 mt-4">
            <?php if (!empty($filterCreator['image'])): ?>
            <img src="/<?= htmlspecialchars($filterCreator['image']) ?>" class="w-16 h-16 rounded-full object-cover border-2 border-gray-200">
            <?php else: ?>
            <div class="w-16 h-16 rounded-full bg-orange-100 flex items-center justify-center">
                <i class="fas fa-user text-orange-400 text-2xl"></i>
            </div>
            <?php endif; ?>
            <div>
                <h1 class="text-2xl md:text-3xl font-bold text-gray-800">
                    <?= htmlspecialchars($filterCreator['name']) ?>さんのサービス
                </h1>
                <p class="text-gray-600"><?= count($services) ?>件のサービスが見つかりました</p>
            </div>
        </div>
    </div>
    <?php else: ?>
    <!-- 通常の一覧 -->
    <div class="mb-8">
        <h1 class="text-2xl md:text-3xl font-bold text-gray-800 mb-2">
            <i class="fas fa-paint-brush text-orange-500 mr-2"></i>
            <?= htmlspecialchars($pageTitle) ?>
        </h1>
        <p class="text-gray-600">クリエイターにお仕事を依頼できます</p>
    </div>
    
    <!-- 検索フォーム -->
    <form method="GET" class="mb-6">
        <div class="flex flex-col md:flex-row gap-4">
            <div class="flex-1 relative">
                <input type="text" name="q" value="<?= htmlspecialchars($searchQuery) ?>" 
                       placeholder="サービスを検索（タイトル、クリエイター名、タグなど）"
                       class="w-full pl-10 pr-4 py-3 border border-gray-200 rounded-xl focus:ring-2 focus:ring-orange-400 outline-none">
                <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
            </div>
            <button type="submit" class="px-6 py-3 bg-orange-500 text-white rounded-xl font-bold hover:bg-orange-600 transition">
                検索
            </button>
        </div>
        
        <!-- フィルター・ソート -->
        <div class="flex flex-wrap items-center gap-4 mt-4">
            <div class="flex items-center gap-2">
                <label class="text-sm font-bold text-gray-600">並び替え:</label>
                <select name="sort" onchange="this.form.submit()" 
                        class="px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                    <option value="recommended" <?= $sortBy === 'recommended' ? 'selected' : '' ?>>おすすめ順</option>
                    <option value="newest" <?= $sortBy === 'newest' ? 'selected' : '' ?>>新着順</option>
                    <option value="popular" <?= $sortBy === 'popular' ? 'selected' : '' ?>>人気順</option>
                    <option value="rating" <?= $sortBy === 'rating' ? 'selected' : '' ?>>評価順</option>
                    <option value="price_asc" <?= $sortBy === 'price_asc' ? 'selected' : '' ?>>価格：安い順</option>
                    <option value="price_desc" <?= $sortBy === 'price_desc' ? 'selected' : '' ?>>価格：高い順</option>
                </select>
            </div>
            
            <?php if ($searchQuery || $categoryFilter || $priceMin || $priceMax): ?>
            <a href="index.php" class="text-sm text-orange-500 hover:underline">
                <i class="fas fa-times mr-1"></i>条件をクリア
            </a>
            <?php endif; ?>
            
            <span class="text-sm text-gray-500"><?= count($services) ?>件</span>
        </div>
        
        <?php if ($categoryFilter): ?>
        <input type="hidden" name="category" value="<?= htmlspecialchars($categoryFilter) ?>">
        <?php endif; ?>
    </form>
    <?php endif; ?>
    
    <!-- カテゴリナビ（クリエイターフィルター時は非表示） -->
    <?php if (!$filterCreator && !empty($categories)): ?>
    <div class="mb-8 overflow-x-auto">
        <div class="flex gap-2 pb-2">
            <a href="index.php<?= $searchQuery ? '?q=' . urlencode($searchQuery) : '' ?>" 
               class="flex-shrink-0 px-4 py-2 rounded-full text-sm font-bold transition
                      <?= !$categoryFilter ? 'bg-orange-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">
                すべて
            </a>
            <?php foreach ($categories as $cat): ?>
            <?php 
            $catName = is_array($cat) ? ($cat['name'] ?? '') : $cat;
            $catIcon = is_array($cat) ? ($cat['icon'] ?? '') : '';
            ?>
            <a href="?category=<?= urlencode($catName) ?><?= $searchQuery ? '&q=' . urlencode($searchQuery) : '' ?>" 
               class="flex-shrink-0 px-4 py-2 rounded-full text-sm font-bold transition inline-flex items-center gap-1
                      <?= $categoryFilter === $catName ? 'bg-orange-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">
                <?php if ($catIcon): ?>
                <i class="fas <?= htmlspecialchars($catIcon) ?>"></i>
                <?php endif; ?>
                <?= htmlspecialchars($catName) ?>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (!$filterCreator && !$categoryFilter && !empty($featuredServices)): ?>
    <!-- おすすめサービス -->
    <div class="mb-10">
        <h2 class="text-xl font-bold text-gray-800 mb-4">
            <i class="fas fa-star text-yellow-400 mr-2"></i>おすすめ
        </h2>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <?php foreach ($featuredServices as $service): ?>
            <a href="detail.php?id=<?= $service['id'] ?>" class="group">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden hover:shadow-md transition">
                    <div class="aspect-video bg-gray-100 relative overflow-hidden flex items-center justify-center">
                        <?php if (!empty($service['thumbnail_image'])): ?>
                        <img src="/<?= htmlspecialchars($service['thumbnail_image']) ?>" 
                             alt="<?= htmlspecialchars($service['title']) ?>"
                             class="w-full h-full object-cover">
                        <?php else: ?>
                        <i class="fas fa-paint-brush text-4xl text-gray-300"></i>
                        <?php endif; ?>
                        <?php if (!empty($service['category_name'])): ?>
                        <span class="absolute top-2 left-2 px-2 py-1 bg-white/90 rounded-full text-xs font-bold">
                            <?= htmlspecialchars($service['category_name']) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="p-3">
                        <h3 class="font-bold text-gray-800 text-sm line-clamp-2 mb-2 group-hover:text-orange-500 transition">
                            <?= htmlspecialchars($service['title']) ?>
                        </h3>
                        <div class="flex items-center gap-2 mb-2">
                            <?php if (!empty($service['creator_image'])): ?>
                            <img src="../../<?= htmlspecialchars($service['creator_image']) ?>" class="w-5 h-5 rounded-full object-cover">
                            <?php else: ?>
                            <div class="w-5 h-5 rounded-full bg-gray-200 flex items-center justify-center">
                                <i class="fas fa-user text-gray-400 text-xs"></i>
                            </div>
                            <?php endif; ?>
                            <span class="text-xs text-gray-500"><?= htmlspecialchars($service['creator_name'] ?? '') ?></span>
                        </div>
                        <div class="flex items-center justify-between">
                            <span class="text-orange-600 font-bold">
                                <?= formatPrice($service['min_price'] ?? $service['base_price']) ?>〜
                            </span>
                            <?php if (!empty($service['avg_rating'])): ?>
                            <span class="text-yellow-400 text-sm">
                                <i class="fas fa-star"></i>
                                <?= formatNumber($service['avg_rating'], '-', 1) ?>
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </a>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- サービス一覧 -->
    <?php if (empty($services)): ?>
    <div class="text-center py-16">
        <i class="fas fa-search text-6xl text-gray-300 mb-4"></i>
        <p class="text-gray-500">サービスが見つかりませんでした</p>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($services as $service): ?>
        <a href="detail.php?id=<?= $service['id'] ?>" class="group">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden hover:shadow-lg transition">
                <!-- サムネイル -->
                <div class="aspect-video bg-gray-100 relative overflow-hidden flex items-center justify-center">
                    <?php if (!empty($service['thumbnail_image'])): ?>
                    <img src="/<?= htmlspecialchars($service['thumbnail_image']) ?>" 
                         alt="<?= htmlspecialchars($service['title']) ?>"
                         class="w-full h-full object-cover">
                    <?php else: ?>
                    <i class="fas fa-paint-brush text-5xl text-gray-300"></i>
                    <?php endif; ?>
                    
                    <!-- カテゴリバッジ -->
                    <?php if (!empty($service['category_name'])): ?>
                    <span class="absolute top-3 left-3 px-2 py-1 bg-white/90 backdrop-blur rounded-full text-xs font-bold text-gray-700">
                        <?= htmlspecialchars($service['category_name']) ?>
                    </span>
                    <?php endif; ?>
                </div>
                
                <!-- コンテンツ -->
                <div class="p-4">
                    <h3 class="font-bold text-gray-800 mb-2 line-clamp-2 group-hover:text-orange-500 transition">
                        <?= htmlspecialchars($service['title']) ?>
                    </h3>
                    
                    <?php if (!empty($service['description'])): ?>
                    <p class="text-sm text-gray-500 mb-3 line-clamp-2">
                        <?= htmlspecialchars($service['description']) ?>
                    </p>
                    <?php endif; ?>
                    
                    <!-- クリエイター -->
                    <div class="flex items-center gap-2 mb-3">
                        <?php if (!empty($service['creator_image'])): ?>
                        <img src="../../<?= htmlspecialchars($service['creator_image']) ?>" 
                             class="w-8 h-8 rounded-full object-cover">
                        <?php else: ?>
                        <div class="w-8 h-8 rounded-full bg-gray-200 flex items-center justify-center">
                            <i class="fas fa-user text-gray-400"></i>
                        </div>
                        <?php endif; ?>
                        <span class="text-sm text-gray-600"><?= htmlspecialchars($service['creator_name'] ?? '') ?></span>
                    </div>
                    
                    <!-- フッター -->
                    <div class="flex items-center justify-between pt-3 border-t">
                        <div>
                            <span class="text-lg font-bold text-orange-600">
                                <?= formatPrice($service['min_price'] ?? $service['base_price']) ?>
                            </span>
                            <span class="text-gray-400 text-sm">〜</span>
                        </div>
                        <div class="flex items-center gap-3 text-sm text-gray-500">
                            <?php if (!empty($service['avg_rating'])): ?>
                            <span class="text-yellow-400">
                                <i class="fas fa-star"></i>
                                <?= formatNumber($service['avg_rating'], '-', 1) ?>
                                <span class="text-gray-400">(<?= $service['review_count'] ?? 0 ?>)</span>
                            </span>
                            <?php endif; ?>
                            <span>
                                <i class="fas fa-clock mr-1"></i><?= $service['delivery_days'] ?>日
                            </span>
                        </div>
                    </div>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php require_once '../includes/footer.php'; ?>
