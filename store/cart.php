<?php
/**
 * カートページ（シンプルデザイン）
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/member-auth.php';
require_once '../includes/cart.php';
require_once '../includes/site-settings.php';

$db = getDB();
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['update_quantity'])) {
        $result = updateCartQuantity((int)$_POST['product_id'], (int)$_POST['quantity']);
        if (!$result['success']) $error = $result['error'];
    }
    if (isset($_POST['remove_item'])) {
        removeFromCart((int)$_POST['product_id']);
        $message = 'カートから削除しました';
    }
    if (isset($_POST['clear_cart'])) {
        clearCart();
        $message = 'カートを空にしました';
    }
}

$cartItems = getCartItems();
$subtotal = getCartSubtotal();
$hasPhysical = cartHasPhysicalItems();
$validation = validateCart();
if (!$validation['valid'] && !empty($validation['errors'])) {
    $error = implode('<br>', $validation['errors']);
}

// 送料無料設定を取得
$shippingFreeEnabled = getSiteSetting($db, 'shipping_free_enabled', '0') === '1';
$shippingFreeThreshold = (int)getSiteSetting($db, 'shipping_free_threshold', '0');
$amountToFreeShipping = $shippingFreeThreshold - $subtotal;
$isFreeShipping = $shippingFreeEnabled && $shippingFreeThreshold > 0 && $subtotal >= $shippingFreeThreshold;

// おすすめ商品を取得（カートに入っていない商品）
$cartProductIds = array_column($cartItems, 'product_id');
$recommendedProducts = [];
if (!empty($cartProductIds)) {
    $placeholders = implode(',', array_fill(0, count($cartProductIds), '?'));
    
    // カート内の商品と同じクリエイターの商品を取得
    $stmt = $db->prepare("
        SELECT DISTINCT p.*, c.name as creator_name
        FROM products p
        LEFT JOIN creators c ON p.creator_id = c.id
        WHERE p.is_published = 1 
        AND p.id NOT IN ($placeholders)
        AND p.creator_id IN (SELECT DISTINCT creator_id FROM products WHERE id IN ($placeholders) AND creator_id IS NOT NULL)
        ORDER BY RAND()
        LIMIT 4
    ");
    $params = array_merge($cartProductIds, $cartProductIds);
    $stmt->execute($params);
    $recommendedProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// おすすめ商品が少ない場合、人気商品で補完
if (count($recommendedProducts) < 4) {
    $excludeIds = array_merge($cartProductIds, array_column($recommendedProducts, 'id'));
    $limit = 4 - count($recommendedProducts);
    
    if (!empty($excludeIds)) {
        $placeholders = implode(',', array_fill(0, count($excludeIds), '?'));
        $stmt = $db->prepare("
            SELECT p.*, c.name as creator_name
            FROM products p
            LEFT JOIN creators c ON p.creator_id = c.id
            WHERE p.is_published = 1 
            AND p.id NOT IN ($placeholders)
            ORDER BY p.id DESC
            LIMIT $limit
        ");
        $stmt->execute($excludeIds);
    } else {
        $stmt = $db->prepare("
            SELECT p.*, c.name as creator_name
            FROM products p
            LEFT JOIN creators c ON p.creator_id = c.id
            WHERE p.is_published = 1 
            ORDER BY p.id DESC
            LIMIT $limit
        ");
        $stmt->execute();
    }
    $moreProducts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $recommendedProducts = array_merge($recommendedProducts, $moreProducts);
}

$settings = getSiteSettings();
$siteName = $settings['site_name'] ?? 'ぷれぐら！PLAYGROUND';
$cartCount = count($cartItems);

$pageTitle = 'カート';
include 'includes/header.php';
?>

<?php if ($message): ?>
<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded mb-4 text-sm">
    <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4 text-sm"><?= $error ?></div>
<?php endif; ?>

<h1 class="text-xl font-bold text-gray-800 mb-6">
    <i class="fas fa-shopping-cart text-pop-orange mr-2"></i>カート
    <?php if ($cartCount > 0): ?><span class="text-sm font-normal text-gray-500">(<?= $cartCount ?>点)</span><?php endif; ?>
</h1>

<?php if (empty($cartItems)): ?>
<div class="bg-white rounded-lg p-12 text-center">
    <i class="fas fa-shopping-cart text-gray-300 text-4xl mb-4"></i>
    <p class="text-gray-500 mb-4">カートに商品がありません</p>
    <a href="/store/" class="inline-block bg-pop-orange text-white px-6 py-2 rounded font-bold hover:bg-orange-600 transition">ストアで商品を探す</a>
</div>

<?php if (!empty($recommendedProducts)): ?>
<!-- おすすめ商品（カート空の時も表示） -->
<div class="mt-8">
    <h2 class="text-lg font-bold text-gray-800 mb-4">
        <i class="fas fa-star text-yellow-400 mr-2"></i>おすすめ商品
    </h2>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <?php foreach ($recommendedProducts as $rec): ?>
        <a href="/store/product.php?id=<?= $rec['id'] ?>" class="bg-white rounded-lg shadow-sm overflow-hidden hover:shadow-md transition group">
            <?php if ($rec['image']): ?>
            <img src="/<?= htmlspecialchars($rec['image']) ?>" alt="" class="w-full aspect-square object-cover group-hover:scale-105 transition">
            <?php else: ?>
            <div class="w-full aspect-square bg-gray-100 flex items-center justify-center"><i class="fas fa-image text-gray-300 text-2xl"></i></div>
            <?php endif; ?>
            <div class="p-3">
                <p class="font-bold text-gray-800 text-sm line-clamp-2 mb-1"><?= htmlspecialchars($rec['name']) ?></p>
                <p class="text-pop-orange font-bold">¥<?= number_format($rec['price']) ?></p>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php else: ?>

<?php if ($shippingFreeEnabled && $shippingFreeThreshold > 0 && $hasPhysical): ?>
<!-- 送料無料プログレスバー -->
<div class="bg-gradient-to-r from-green-50 to-emerald-50 border border-green-200 rounded-xl p-4 mb-6">
    <?php if ($isFreeShipping): ?>
    <div class="flex items-center gap-3">
        <div class="w-10 h-10 bg-green-500 rounded-full flex items-center justify-center flex-shrink-0">
            <i class="fas fa-truck text-white"></i>
        </div>
        <div>
            <p class="font-bold text-green-700"><i class="fas fa-check-circle mr-1"></i>送料無料です！</p>
            <p class="text-sm text-green-600">¥<?= number_format($shippingFreeThreshold) ?>以上のご注文で送料無料</p>
        </div>
    </div>
    <?php else: ?>
    <div class="flex items-center gap-3 mb-3">
        <div class="w-10 h-10 bg-orange-100 rounded-full flex items-center justify-center flex-shrink-0">
            <i class="fas fa-truck text-orange-500"></i>
        </div>
        <div class="flex-1">
            <p class="font-bold text-gray-800">あと<span class="text-pop-orange">¥<?= number_format($amountToFreeShipping) ?></span>で送料無料！</p>
            <p class="text-sm text-gray-600">¥<?= number_format($shippingFreeThreshold) ?>以上のご注文で送料無料</p>
        </div>
    </div>
    <div class="w-full bg-gray-200 rounded-full h-2.5">
        <div class="bg-gradient-to-r from-orange-400 to-green-500 h-2.5 rounded-full transition-all" style="width: <?= min(100, ($subtotal / $shippingFreeThreshold) * 100) ?>%"></div>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="grid lg:grid-cols-3 gap-6">
    <!-- 商品一覧 -->
    <div class="lg:col-span-2 space-y-3">
        <?php foreach ($cartItems as $item): ?>
        <div class="bg-white rounded-lg shadow-sm overflow-hidden">
            <div class="flex gap-4 p-4">
                <a href="/store/product.php?id=<?= $item['product_id'] ?>" class="flex-shrink-0">
                    <?php if ($item['image']): ?>
                    <img src="/<?= htmlspecialchars($item['image']) ?>" alt="" class="w-20 h-20 object-cover rounded">
                    <?php else: ?>
                    <div class="w-20 h-20 bg-gray-100 rounded flex items-center justify-center"><i class="fas fa-image text-gray-300"></i></div>
                    <?php endif; ?>
                </a>
                <div class="flex-1 min-w-0">
                    <a href="/store/product.php?id=<?= $item['product_id'] ?>" class="font-bold text-gray-800 hover:text-pop-orange line-clamp-2 text-sm">
                        <?= htmlspecialchars($item['name']) ?>
                    </a>
                    <span class="inline-block mt-1 text-xs <?= $item['product_type'] === 'digital' ? 'bg-yellow-100 text-yellow-700' : 'bg-gray-100 text-gray-600' ?> px-2 py-0.5 rounded">
                        <?= $item['product_type'] === 'digital' ? 'デジタル' : '物販' ?>
                    </span>
                    <p class="font-bold text-pop-orange mt-2">¥<?= number_format($item['price']) ?></p>
                </div>
            </div>
            <div class="flex items-center justify-between border-t px-4 py-2 bg-gray-50 text-sm">
                <?php if ($item['product_type'] === 'physical'): ?>
                <form method="POST" class="flex items-center gap-2">
                    <input type="hidden" name="product_id" value="<?= $item['product_id'] ?>">
                    <label class="text-gray-500">数量</label>
                    <select name="quantity" onchange="this.form.submit()" class="px-2 py-1 border rounded">
                        <?php for ($i = 1; $i <= 10; $i++): ?>
                        <option value="<?= $i ?>" <?= $item['quantity'] == $i ? 'selected' : '' ?>><?= $i ?></option>
                        <?php endfor; ?>
                    </select>
                    <input type="hidden" name="update_quantity" value="1">
                </form>
                <?php else: ?>
                <span class="text-gray-500">数量: 1</span>
                <?php endif; ?>
                <form method="POST">
                    <input type="hidden" name="product_id" value="<?= $item['product_id'] ?>">
                    <button type="submit" name="remove_item" class="text-red-500 hover:underline"><i class="fas fa-trash-alt mr-1"></i>削除</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
        
        <form method="POST" class="text-center">
            <button type="submit" name="clear_cart" onclick="return confirm('カートを空にしますか？')" class="text-sm text-gray-500 hover:text-red-500">
                <i class="fas fa-trash mr-1"></i>カートを空にする
            </button>
        </form>
    </div>
    
    <!-- サマリー -->
    <div class="lg:col-span-1">
        <div class="bg-white rounded-lg shadow-sm p-6 sticky top-20">
            <h2 class="font-bold text-gray-800 mb-4">ご注文内容</h2>
            <div class="space-y-2 text-sm">
                <div class="flex justify-between">
                    <span class="text-gray-600">小計（<?= $cartCount ?>点）</span>
                    <span class="font-bold">¥<?= number_format($subtotal) ?></span>
                </div>
                <?php if ($hasPhysical): ?>
                <div class="flex justify-between">
                    <span class="text-gray-600">送料</span>
                    <?php if ($isFreeShipping): ?>
                    <span class="text-green-600 font-bold"><i class="fas fa-check-circle mr-1"></i>無料</span>
                    <?php else: ?>
                    <span class="text-gray-500">チェックアウト時に計算</span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
            <div class="border-t mt-4 pt-4">
                <div class="flex justify-between items-center mb-4">
                    <span class="font-bold">合計</span>
                    <span class="text-xl font-bold text-pop-orange">¥<?= number_format($subtotal) ?></span>
                </div>
                <?php if ($validation['valid']): ?>
                <a href="/store/checkout.php" class="block w-full btn-cart py-3 rounded font-bold text-center">
                    <i class="fas fa-lock mr-2"></i>レジに進む
                </a>
                <?php else: ?>
                <button disabled class="block w-full bg-gray-300 text-gray-500 py-3 rounded font-bold cursor-not-allowed">購入できない商品があります</button>
                <?php endif; ?>
                <p class="text-xs text-gray-500 text-center mt-2"><i class="fas fa-shield-alt mr-1"></i>安全な決済（SSL暗号化）</p>
            </div>
            <a href="/store/" class="block text-center text-sm text-pop-orange font-medium mt-4 hover:underline">← 買い物を続ける</a>
        </div>
    </div>
</div>

<?php if (!empty($recommendedProducts)): ?>
<!-- おすすめ商品 -->
<div class="mt-10">
    <h2 class="text-lg font-bold text-gray-800 mb-4">
        <i class="fas fa-star text-yellow-400 mr-2"></i>こちらもおすすめ
    </h2>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
        <?php foreach ($recommendedProducts as $rec): ?>
        <a href="/store/product.php?id=<?= $rec['id'] ?>" class="bg-white rounded-lg shadow-sm overflow-hidden hover:shadow-md transition group">
            <?php if ($rec['image']): ?>
            <img src="/<?= htmlspecialchars($rec['image']) ?>" alt="" class="w-full aspect-square object-cover group-hover:scale-105 transition">
            <?php else: ?>
            <div class="w-full aspect-square bg-gray-100 flex items-center justify-center"><i class="fas fa-image text-gray-300 text-2xl"></i></div>
            <?php endif; ?>
            <div class="p-3">
                <p class="font-bold text-gray-800 text-sm line-clamp-2 mb-1"><?= htmlspecialchars($rec['name']) ?></p>
                <?php if ($rec['creator_name']): ?>
                <p class="text-xs text-gray-500 mb-1"><?= htmlspecialchars($rec['creator_name']) ?></p>
                <?php endif; ?>
                <p class="text-pop-orange font-bold">¥<?= number_format($rec['price']) ?></p>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<?php include 'includes/footer.php'; ?>
