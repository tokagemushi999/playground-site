<?php
/**
 * お気に入り一覧（商品・サービス両対応）
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/member-auth.php';
require_once '../includes/cart.php';
require_once '../includes/site-settings.php';

requireMemberAuth();

$member = getCurrentMember();
$db = getDB();
$memberId = $member['id'];

// 商品お気に入りテーブル作成
try {
    $db->query("SELECT 1 FROM member_favorites LIMIT 1");
} catch (PDOException $e) {
    $db->exec("CREATE TABLE member_favorites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        member_id INT NOT NULL,
        product_id INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_member_product (member_id, product_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// サービスお気に入りテーブル作成
try {
    $db->query("SELECT 1 FROM service_favorites LIMIT 1");
} catch (PDOException $e) {
    $db->exec("CREATE TABLE service_favorites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        member_id INT NOT NULL,
        service_id INT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_favorite (member_id, service_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

// 商品お気に入り削除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_product_favorite'])) {
    $stmt = $db->prepare("DELETE FROM member_favorites WHERE member_id = ? AND product_id = ?");
    $stmt->execute([$memberId, (int)$_POST['product_id']]);
    header('Location: /store/favorites.php?removed=product');
    exit;
}

// サービスお気に入り削除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_service_favorite'])) {
    $stmt = $db->prepare("DELETE FROM service_favorites WHERE member_id = ? AND service_id = ?");
    $stmt->execute([$memberId, (int)$_POST['service_id']]);
    header('Location: /store/favorites.php?removed=service&tab=services');
    exit;
}

// カートに追加
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    addToCart((int)$_POST['product_id'], 1);
    header('Location: /store/favorites.php?added=1');
    exit;
}

// 商品お気に入り取得
$productFavorites = [];
try {
    $stmt = $db->prepare("
        SELECT p.*, c.name as creator_name 
        FROM member_favorites mf 
        JOIN products p ON mf.product_id = p.id 
        LEFT JOIN creators c ON p.creator_id = c.id 
        WHERE mf.member_id = ? AND p.is_published = 1 
        ORDER BY mf.created_at DESC
    ");
    $stmt->execute([$memberId]);
    $productFavorites = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

// サービスお気に入り取得
$serviceFavorites = [];
try {
    $stmt = $db->prepare("
        SELECT s.*, c.name as creator_name, c.image as creator_image, sf.created_at as favorited_at
        FROM service_favorites sf 
        JOIN services s ON sf.service_id = s.id 
        LEFT JOIN creators c ON s.creator_id = c.id 
        WHERE sf.member_id = ? AND s.status = 'active'
        ORDER BY sf.created_at DESC
    ");
    $stmt->execute([$memberId]);
    $serviceFavorites = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$settings = getSiteSettings();
$siteName = $settings['site_name'] ?? 'ぷれぐら！PLAYGROUND';
$cartCount = getCartCount();

$pageTitle = 'お気に入り';
include 'includes/header.php';

$totalCount = count($productFavorites) + count($serviceFavorites);
$activeTab = isset($_GET['tab']) ? $_GET['tab'] : (empty($productFavorites) && !empty($serviceFavorites) ? 'services' : 'products');
?>

<nav class="text-sm text-gray-500 mb-4">
    <a href="/store/mypage.php" class="hover:underline">マイページ</a> &gt; <span class="text-gray-800">お気に入り</span>
</nav>

<?php if (isset($_GET['removed'])): ?>
<div class="bg-blue-50 border border-blue-200 text-blue-700 px-4 py-3 rounded mb-4 text-sm">お気に入りから削除しました</div>
<?php endif; ?>

<?php if (isset($_GET['added'])): ?>
<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded mb-4 text-sm">カートに追加しました</div>
<?php endif; ?>

<h1 class="text-xl font-bold text-gray-800 mb-6">
    <i class="fas fa-heart text-pink-500 mr-2"></i>お気に入り
    <span class="text-sm font-normal text-gray-500">(<?= $totalCount ?>件)</span>
</h1>

<!-- タブ -->
<div class="flex gap-4 border-b mb-6">
    <button onclick="switchFavTab('products')" id="tab-btn-products"
            class="px-4 py-2 font-bold text-sm border-b-2 -mb-px transition <?= $activeTab === 'products' ? 'text-orange-600 border-orange-500' : 'text-gray-500 border-transparent hover:text-gray-700' ?>">
        <i class="fas fa-box mr-1"></i>商品 (<?= count($productFavorites) ?>)
    </button>
    <button onclick="switchFavTab('services')" id="tab-btn-services"
            class="px-4 py-2 font-bold text-sm border-b-2 -mb-px transition <?= $activeTab === 'services' ? 'text-orange-600 border-orange-500' : 'text-gray-500 border-transparent hover:text-gray-700' ?>">
        <i class="fas fa-paint-brush mr-1"></i>サービス (<?= count($serviceFavorites) ?>)
    </button>
</div>

<!-- 商品お気に入り -->
<div id="fav-products" class="<?= $activeTab !== 'products' ? 'hidden' : '' ?>">
    <?php if (empty($productFavorites)): ?>
    <div class="bg-white rounded-lg p-12 text-center">
        <i class="fas fa-box text-gray-300 text-4xl mb-4"></i>
        <p class="text-gray-500 mb-4">お気に入りに登録した商品はありません</p>
        <a href="/store/" class="inline-block bg-pop-orange text-white px-6 py-2 rounded font-bold hover:bg-orange-600 transition">ストアで商品を探す</a>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-4 gap-3">
        <?php foreach ($productFavorites as $product): ?>
        <div class="bg-white rounded-lg shadow-sm overflow-hidden">
            <a href="/store/product.php?id=<?= $product['id'] ?>">
                <div class="aspect-square">
                    <?php if ($product['image']): ?>
                    <img src="/<?= htmlspecialchars($product['image']) ?>" alt="" class="w-full h-full object-cover">
                    <?php else: ?>
                    <div class="w-full h-full bg-gray-100 flex items-center justify-center"><i class="fas fa-image text-gray-300 text-2xl"></i></div>
                    <?php endif; ?>
                </div>
            </a>
            <div class="p-3">
                <a href="/store/product.php?id=<?= $product['id'] ?>" class="font-bold text-gray-800 text-sm line-clamp-2 hover:text-pop-orange"><?= htmlspecialchars($product['name']) ?></a>
                <p class="font-bold text-pop-orange mt-1">¥<?= number_format($product['price']) ?></p>
                <div class="flex gap-2 mt-2">
                    <form method="POST" class="flex-1">
                        <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                        <button type="submit" name="add_to_cart" class="w-full btn-cart py-1.5 rounded text-xs font-bold">カートに入れる</button>
                    </form>
                    <form method="POST">
                        <input type="hidden" name="product_id" value="<?= $product['id'] ?>">
                        <button type="submit" name="remove_product_favorite" class="w-8 h-8 bg-gray-100 hover:bg-red-50 rounded flex items-center justify-center" title="削除">
                            <i class="fas fa-trash text-gray-400 hover:text-red-500 text-xs"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- サービスお気に入り -->
<div id="fav-services" class="<?= $activeTab !== 'services' ? 'hidden' : '' ?>">
    <?php if (empty($serviceFavorites)): ?>
    <div class="bg-white rounded-lg p-12 text-center">
        <i class="fas fa-paint-brush text-gray-300 text-4xl mb-4"></i>
        <p class="text-gray-500 mb-4">お気に入りに登録したサービスはありません</p>
        <a href="/store/services/" class="inline-block bg-orange-500 text-white px-6 py-2 rounded font-bold hover:bg-orange-600 transition">サービスを探す</a>
    </div>
    <?php else: ?>
    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
        <?php foreach ($serviceFavorites as $service): ?>
        <div class="bg-white rounded-lg shadow-sm overflow-hidden">
            <a href="/store/services/detail.php?id=<?= $service['id'] ?>">
                <div class="aspect-video bg-gray-100 relative">
                    <?php if (!empty($service['thumbnail_image'])): ?>
                    <img src="/<?= htmlspecialchars($service['thumbnail_image']) ?>" alt="" class="w-full h-full object-cover">
                    <?php else: ?>
                    <div class="w-full h-full flex items-center justify-center"><i class="fas fa-paint-brush text-gray-300 text-3xl"></i></div>
                    <?php endif; ?>
                </div>
            </a>
            <div class="p-4">
                <div class="flex items-center gap-2 mb-2">
                    <?php if (!empty($service['creator_image'])): ?>
                    <img src="/<?= htmlspecialchars($service['creator_image']) ?>" class="w-6 h-6 rounded-full object-cover">
                    <?php else: ?>
                    <div class="w-6 h-6 rounded-full bg-gray-200 flex items-center justify-center">
                        <i class="fas fa-user text-gray-400 text-xs"></i>
                    </div>
                    <?php endif; ?>
                    <span class="text-sm text-gray-500"><?= htmlspecialchars($service['creator_name'] ?? '') ?></span>
                </div>
                <a href="/store/services/detail.php?id=<?= $service['id'] ?>" class="font-bold text-gray-800 text-sm line-clamp-2 hover:text-orange-500"><?= htmlspecialchars($service['title']) ?></a>
                <div class="flex items-center justify-between mt-3">
                    <span class="font-bold text-orange-500">¥<?= number_format($service['base_price']) ?>〜</span>
                    <form method="POST">
                        <input type="hidden" name="service_id" value="<?= $service['id'] ?>">
                        <button type="submit" name="remove_service_favorite" class="text-gray-400 hover:text-red-500" title="削除">
                            <i class="fas fa-trash text-xs"></i>
                        </button>
                    </form>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<script>
function switchFavTab(tab) {
    // タブボタン
    document.getElementById('tab-btn-products').classList.remove('text-orange-600', 'border-orange-500');
    document.getElementById('tab-btn-products').classList.add('text-gray-500', 'border-transparent');
    document.getElementById('tab-btn-services').classList.remove('text-orange-600', 'border-orange-500');
    document.getElementById('tab-btn-services').classList.add('text-gray-500', 'border-transparent');
    
    document.getElementById('tab-btn-' + tab).classList.add('text-orange-600', 'border-orange-500');
    document.getElementById('tab-btn-' + tab).classList.remove('text-gray-500', 'border-transparent');
    
    // コンテンツ
    document.getElementById('fav-products').classList.add('hidden');
    document.getElementById('fav-services').classList.add('hidden');
    document.getElementById('fav-' + tab).classList.remove('hidden');
}
</script>

<?php include 'includes/footer.php'; ?>
