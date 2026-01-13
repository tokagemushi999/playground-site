<?php
/**
 * 商品詳細ページ（シンプルデザイン）
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/member-auth.php';
require_once '../includes/cart.php';
require_once '../includes/site-settings.php';

$db = getDB();
$productId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $db->prepare("SELECT p.*, c.name as creator_name, c.image as creator_image, c.slug as creator_slug FROM products p LEFT JOIN creators c ON p.creator_id = c.id WHERE p.id = ? AND p.is_published = 1");
$stmt->execute([$productId]);
$product = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$product) {
    header('Location: /store/');
    exit;
}

// 関連作品
$relatedWork = null;
if ($product['related_work_id']) {
    $stmt = $db->prepare("SELECT w.*, (SELECT COUNT(*) FROM work_pages WHERE work_id = w.id) as page_count FROM works w WHERE w.id = ? AND w.is_active = 1");
    $stmt->execute([$product['related_work_id']]);
    $relatedWork = $stmt->fetch(PDO::FETCH_ASSOC);
}

// カートに追加
$message = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_to_cart'])) {
    $quantity = max(1, (int)($_POST['quantity'] ?? 1));
    $result = addToCart($product['id'], $quantity);
    if ($result['success']) {
        $message = 'カートに追加しました';
    } else {
        $error = $result['error'];
    }
}

// 購入済み・お気に入りチェック
$isPurchased = false;
$isFavorite = false;
if (isLoggedIn()) {
    $stmt = $db->prepare("SELECT id FROM member_bookshelf WHERE member_id = ? AND product_id = ?");
    $stmt->execute([$_SESSION['member_id'], $product['id']]);
    $isPurchased = (bool)$stmt->fetch();
    
    try {
        $stmt = $db->prepare("SELECT id FROM member_favorites WHERE member_id = ? AND product_id = ?");
        $stmt->execute([$_SESSION['member_id'], $product['id']]);
        $isFavorite = (bool)$stmt->fetch();
    } catch (PDOException $e) {}
}

// お気に入り切り替え
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_favorite']) && isLoggedIn()) {
    try {
        if ($isFavorite) {
            $stmt = $db->prepare("DELETE FROM member_favorites WHERE member_id = ? AND product_id = ?");
        } else {
            $stmt = $db->prepare("INSERT IGNORE INTO member_favorites (member_id, product_id) VALUES (?, ?)");
        }
        $stmt->execute([$_SESSION['member_id'], $product['id']]);
        header('Location: /store/product.php?id=' . $product['id']);
        exit;
    } catch (PDOException $e) {}
}

$settings = getSiteSettings();
$siteName = $settings['site_name'] ?? 'ぷれぐら！PLAYGROUND';
$cartCount = getCartCount();
$stock = $product['stock'] ?? null;
$typeLabels = ['digital' => 'デジタル', 'physical' => '物販', 'bundle' => 'セット'];

$pageTitle = $product['name'];
include 'includes/header.php';
?>

<!-- パンくず -->
<nav class="text-sm text-gray-500 mb-4">
    <a href="/store/" class="hover:underline">ストア</a> &gt; 
    <span class="text-gray-800"><?= htmlspecialchars($product['name']) ?></span>
</nav>

<?php if ($message): ?>
<!-- カート追加モーダル -->
<div id="cartAddedModal" class="fixed inset-0 bg-black/50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl max-w-sm w-full p-6 transform animate-bounce-in">
        <div class="text-center mb-6">
            <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-check text-3xl text-green-500"></i>
            </div>
            <h3 class="text-xl font-bold text-gray-800 mb-2">カートに追加しました！</h3>
            <p class="text-gray-600"><?= htmlspecialchars($product['name']) ?></p>
        </div>
        
        <div class="space-y-3">
            <a href="/store/cart.php" class="block w-full bg-store-primary hover:bg-pink-600 text-white py-3 rounded-xl font-bold text-center transition-colors">
                <i class="fas fa-shopping-cart mr-2"></i>カートを見る
            </a>
            <button onclick="document.getElementById('cartAddedModal').remove()" class="block w-full bg-gray-100 hover:bg-gray-200 text-gray-700 py-3 rounded-xl font-bold text-center transition-colors">
                <i class="fas fa-shopping-bag mr-2"></i>買い物を続ける
            </button>
        </div>
        
        <p class="text-center text-sm text-gray-500 mt-4">
            カート内: <?= getCartCount() ?>点
        </p>
    </div>
</div>
<style>
@keyframes bounce-in {
    0% { transform: scale(0.8); opacity: 0; }
    50% { transform: scale(1.05); }
    100% { transform: scale(1); opacity: 1; }
}
.animate-bounce-in { animation: bounce-in 0.3s ease-out; }
</style>
<?php endif; ?>

<?php if ($error): ?>
<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4">
    <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<div class="grid lg:grid-cols-2 gap-6">
    <!-- 画像 -->
    <div class="bg-white rounded-lg overflow-hidden shadow-sm relative">
        <?php if ($product['image']): ?>
        <img src="/<?= htmlspecialchars($product['image']) ?>" alt="" class="w-full h-auto">
        <?php else: ?>
        <div class="w-full aspect-square bg-gray-100 flex items-center justify-center">
            <i class="fas fa-image text-gray-300 text-5xl"></i>
        </div>
        <?php endif; ?>
        <?php if ($isFavorite): ?>
        <div class="absolute top-3 right-3">
            <span class="w-10 h-10 bg-pink-500 rounded-full flex items-center justify-center shadow-lg">
                <i class="fas fa-heart text-white text-lg"></i>
            </span>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- 情報 -->
    <div class="space-y-4">
        <!-- バッジ -->
        <div class="flex gap-2">
            <span class="bg-pop-yellow text-gray-800 text-xs font-bold px-3 py-1 rounded-full">
                <?= $typeLabels[$product['product_type']] ?? $product['product_type'] ?>
            </span>
            <?php if ($stock !== null && $stock <= 5 && $stock > 0): ?>
            <span class="bg-red-100 text-red-600 text-xs font-bold px-3 py-1 rounded-full">残りわずか</span>
            <?php elseif ($stock === 0 && $product['product_type'] !== 'digital'): ?>
            <span class="bg-gray-100 text-gray-600 text-xs font-bold px-3 py-1 rounded-full">売切れ</span>
            <?php endif; ?>
        </div>
        
        <!-- 商品名 -->
        <h1 class="text-xl lg:text-2xl font-bold text-gray-800"><?= htmlspecialchars($product['name']) ?></h1>
        
        <!-- クリエイター -->
        <?php if ($product['creator_name']): ?>
        <a href="/creator/<?= htmlspecialchars($product['creator_slug'] ?? '') ?>" class="inline-flex items-center gap-2 text-gray-600 hover:text-gray-800">
            <?php if ($product['creator_image']): ?>
            <img src="/<?= htmlspecialchars($product['creator_image']) ?>" alt="" class="w-8 h-8 rounded-full object-cover">
            <?php endif; ?>
            <span><?= htmlspecialchars($product['creator_name']) ?></span>
        </a>
        <?php endif; ?>
        
        <!-- 価格 -->
        <div class="bg-gray-50 rounded-lg p-4">
            <p class="text-2xl font-bold text-pop-orange">
                ¥<?= number_format($product['price']) ?>
                <span class="text-sm font-normal text-gray-500">（税込）</span>
            </p>
        </div>
        
        <!-- 購入ボタン -->
        <?php if ($isPurchased): ?>
        <div class="bg-green-50 border border-green-200 rounded-lg p-4 text-center">
            <i class="fas fa-check-circle text-green-500 text-2xl mb-2"></i>
            <p class="text-green-700 font-bold">購入済みです</p>
            <?php if ($product['product_type'] === 'digital'): ?>
            <a href="/store/bookshelf.php" class="inline-block mt-2 text-green-600 font-bold hover:underline">本棚で読む →</a>
            <?php endif; ?>
        </div>
        <?php elseif ($stock === 0 && $product['product_type'] !== 'digital'): ?>
        <div class="bg-gray-100 rounded-lg p-4 text-center">
            <p class="text-gray-600 font-bold">現在売切れ中です</p>
        </div>
        <?php else: ?>
        <form method="POST" class="space-y-3">
            <?php if ($product['product_type'] === 'physical' && ($stock === null || $stock > 0)): ?>
            <div class="flex items-center gap-3">
                <label class="text-sm text-gray-700">数量</label>
                <select name="quantity" class="px-3 py-2 border rounded">
                    <?php for ($i = 1; $i <= min(10, $stock ?? 10); $i++): ?>
                    <option value="<?= $i ?>"><?= $i ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <?php endif; ?>
            <button type="submit" name="add_to_cart" class="w-full btn-cart py-3 rounded-lg font-bold text-lg">
                <i class="fas fa-shopping-cart mr-2"></i>カートに入れる
            </button>
        </form>
        
        <!-- お気に入り -->
        <?php if (isLoggedIn()): ?>
        <form method="POST">
            <button type="submit" name="toggle_favorite" class="w-full py-2 rounded-lg font-medium border <?= $isFavorite ? 'bg-pink-50 text-pink-600 border-pink-200' : 'bg-white text-gray-600 border-gray-200 hover:bg-gray-50' ?>">
                <i class="<?= $isFavorite ? 'fas' : 'far' ?> fa-heart mr-1"></i>
                <?= $isFavorite ? 'お気に入り登録済み' : 'お気に入りに追加' ?>
            </button>
        </form>
        <?php endif; ?>
        <?php endif; ?>
        
        <!-- 試し読み -->
        <?php if ($relatedWork && $relatedWork['page_count'] > 0): 
            $previewPages = $product['preview_pages'] ?? 3;
            $totalPages = $relatedWork['page_count'];
            $isFullAccess = $isPurchased || $previewPages >= $totalPages;
        ?>
        <a href="/manga/<?= $relatedWork['id'] ?>" class="block <?= $isFullAccess ? 'bg-green-50 border-green-200' : 'bg-purple-50 border-purple-200' ?> border rounded-lg p-4 hover:opacity-80 transition">
            <div class="flex items-center gap-3">
                <i class="fas fa-book-open <?= $isFullAccess ? 'text-green-500' : 'text-purple-500' ?> text-xl"></i>
                <div class="flex-1">
                    <?php if ($isFullAccess): ?>
                    <p class="font-bold text-green-700">全編を読む</p>
                    <p class="text-sm text-gray-600">全<?= $totalPages ?>ページ</p>
                    <?php else: ?>
                    <p class="font-bold text-purple-700">試し読みする</p>
                    <p class="text-sm text-gray-600"><?= $previewPages ?>ページ無料 / 全<?= $totalPages ?>ページ</p>
                    <?php endif; ?>
                </div>
                <i class="fas fa-chevron-right text-gray-400"></i>
            </div>
        </a>
        <?php endif; ?>
        
        <!-- 説明 -->
        <?php if ($product['description']): ?>
        <div class="bg-white rounded-lg p-4 shadow-sm">
            <h2 class="font-bold text-gray-800 mb-2">商品説明</h2>
            <div class="text-gray-600 text-sm whitespace-pre-wrap"><?= nl2br(htmlspecialchars($product['description'])) ?></div>
        </div>
        <?php endif; ?>
        
        <!-- 注意事項 -->
        <div class="bg-gray-50 rounded-lg p-4 text-sm text-gray-600">
            <?php if ($product['product_type'] === 'digital'): ?>
            <p><i class="fas fa-download mr-2 text-pop-orange"></i>購入後、マイページの「本棚」からすぐにご覧いただけます。</p>
            <?php else: ?>
            <p><i class="fas fa-truck mr-2 text-pop-orange"></i>ご注文確認後、通常3〜7営業日以内に発送いたします。</p>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
