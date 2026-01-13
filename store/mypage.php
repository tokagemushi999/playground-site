<?php
/**
 * マイページ（シンプルデザイン）
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/member-auth.php';
require_once '../includes/cart.php';
require_once '../includes/site-settings.php';

requireMemberAuth();

$member = getCurrentMember();
$db = getDB();

// 購入履歴（商品情報付き）
$stmt = $db->prepare("SELECT * FROM orders WHERE member_id = ? ORDER BY created_at DESC LIMIT 5");
$stmt->execute([$member['id']]);
$recentOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 各注文の商品画像を取得
foreach ($recentOrders as &$order) {
    $stmt = $db->prepare("
        SELECT oi.*, p.name as product_name, p.image 
        FROM order_items oi 
        LEFT JOIN products p ON oi.product_id = p.id 
        WHERE oi.order_id = ? 
        LIMIT 4
    ");
    $stmt->execute([$order['id']]);
    $order['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 総アイテム数
    $stmt = $db->prepare("SELECT COUNT(*) FROM order_items WHERE order_id = ?");
    $stmt->execute([$order['id']]);
    $order['item_count'] = $stmt->fetchColumn();
}
unset($order);

// 本棚数
$stmt = $db->prepare("SELECT COUNT(*) FROM member_bookshelf WHERE member_id = ?");
$stmt->execute([$member['id']]);
$bookshelfCount = $stmt->fetchColumn();

// お気に入り数（商品）
$favoriteCount = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM member_favorites WHERE member_id = ?");
    $stmt->execute([$member['id']]);
    $favoriteCount = $stmt->fetchColumn();
} catch (PDOException $e) {}

// お気に入り数（サービス）
$serviceFavoriteCount = 0;
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM service_favorites WHERE member_id = ?");
    $stmt->execute([$member['id']]);
    $serviceFavoriteCount = $stmt->fetchColumn();
} catch (PDOException $e) {}

// 総注文数
$stmt = $db->prepare("SELECT COUNT(*) FROM orders WHERE member_id = ?");
$stmt->execute([$member['id']]);
$orderCount = $stmt->fetchColumn();

// サービス取引数
$serviceTransactionCount = 0;
$activeServiceTransactions = [];
try {
    $stmt = $db->prepare("SELECT COUNT(*) FROM service_transactions WHERE member_id = ?");
    $stmt->execute([$member['id']]);
    $serviceTransactionCount = $stmt->fetchColumn();
    
    // 進行中のサービス取引
    $stmt = $db->prepare("
        SELECT t.*, s.title as service_title, s.thumbnail_image, c.name as creator_name
        FROM service_transactions t
        LEFT JOIN services s ON t.service_id = s.id
        LEFT JOIN creators c ON t.creator_id = c.id
        WHERE t.member_id = ? AND t.status NOT IN ('completed', 'cancelled', 'refunded')
        ORDER BY t.updated_at DESC
        LIMIT 3
    ");
    $stmt->execute([$member['id']]);
    $activeServiceTransactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {}

$settings = getSiteSettings();
$siteName = $settings['site_name'] ?? 'ぷれぐら！PLAYGROUND';
$cartCount = getCartCount();

$statusLabels = [
    'pending' => ['label' => '処理待ち', 'class' => 'bg-gray-100 text-gray-600'],
    'confirmed' => ['label' => '確認済み', 'class' => 'bg-blue-100 text-blue-600'],
    'processing' => ['label' => '準備中', 'class' => 'bg-yellow-100 text-yellow-700'],
    'shipped' => ['label' => '発送済み', 'class' => 'bg-purple-100 text-purple-600'],
    'completed' => ['label' => '完了', 'class' => 'bg-green-100 text-green-600'],
    'cancelled' => ['label' => 'キャンセル', 'class' => 'bg-red-100 text-red-600'],
];

$serviceStatusLabels = [
    'inquiry' => ['label' => '見積依頼中', 'class' => 'bg-blue-100 text-blue-600'],
    'quote_pending' => ['label' => '見積待ち', 'class' => 'bg-yellow-100 text-yellow-600'],
    'quote_sent' => ['label' => '見積回答', 'class' => 'bg-indigo-100 text-indigo-600'],
    'quote_accepted' => ['label' => '見積承諾', 'class' => 'bg-green-100 text-green-600'],
    'payment_pending' => ['label' => '決済待ち', 'class' => 'bg-orange-100 text-orange-600'],
    'paid' => ['label' => '決済完了', 'class' => 'bg-emerald-100 text-emerald-600'],
    'in_progress' => ['label' => '制作中', 'class' => 'bg-purple-100 text-purple-600'],
    'delivered' => ['label' => '納品済み', 'class' => 'bg-pink-100 text-pink-600'],
    'revision_requested' => ['label' => '修正依頼', 'class' => 'bg-red-100 text-red-600'],
    'completed' => ['label' => '完了', 'class' => 'bg-gray-100 text-gray-600'],
    'cancelled' => ['label' => 'キャンセル', 'class' => 'bg-red-100 text-red-600'],
];

$pageTitle = 'マイページ';
include 'includes/header.php';
?>

<!-- ウェルカム -->
<div class="bg-white rounded-lg shadow-sm p-6 mb-6">
    <p class="text-sm text-gray-500">ようこそ</p>
    <h1 class="text-xl font-bold text-gray-800"><?= htmlspecialchars($member['nickname'] ?: $member['name']) ?>さん</h1>
    <p class="text-sm text-gray-500 mt-1"><?= htmlspecialchars($member['email']) ?></p>
</div>

<!-- メインメニュー -->
<div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-6">
    <a href="/store/bookshelf.php" class="bg-white rounded-lg shadow-sm p-4 text-center hover:shadow-md transition">
        <i class="fas fa-book text-purple-500 text-2xl mb-2"></i>
        <h3 class="font-bold text-gray-800 text-sm">本棚</h3>
        <p class="text-xs text-pop-orange font-bold"><?= $bookshelfCount ?>作品</p>
    </a>
    <a href="/store/orders.php" class="bg-white rounded-lg shadow-sm p-4 text-center hover:shadow-md transition">
        <i class="fas fa-receipt text-blue-500 text-2xl mb-2"></i>
        <h3 class="font-bold text-gray-800 text-sm">購入履歴</h3>
        <p class="text-xs text-pop-orange font-bold"><?= $orderCount ?>件</p>
    </a>
    <a href="/store/transactions/" class="bg-white rounded-lg shadow-sm p-4 text-center hover:shadow-md transition relative">
        <i class="fas fa-handshake text-green-500 text-2xl mb-2"></i>
        <h3 class="font-bold text-gray-800 text-sm">サービス取引</h3>
        <p class="text-xs text-pop-orange font-bold"><?= $serviceTransactionCount ?>件</p>
        <?php if (count($activeServiceTransactions) > 0): ?>
        <span class="absolute top-2 right-2 bg-red-500 text-white text-xs px-1.5 py-0.5 rounded-full"><?= count($activeServiceTransactions) ?></span>
        <?php endif; ?>
    </a>
    <a href="/store/favorites.php" class="bg-white rounded-lg shadow-sm p-4 text-center hover:shadow-md transition">
        <i class="fas fa-heart text-pink-500 text-2xl mb-2"></i>
        <h3 class="font-bold text-gray-800 text-sm">お気に入り</h3>
        <p class="text-xs text-pop-orange font-bold"><?= $favoriteCount + $serviceFavoriteCount ?>件</p>
    </a>
</div>

<!-- サブメニュー -->
<div class="grid grid-cols-3 gap-3 mb-6">
    <a href="/store/profile.php" class="bg-white rounded-lg shadow-sm p-3 text-center hover:shadow-md transition">
        <i class="fas fa-user-cog text-gray-400 text-lg mb-1"></i>
        <h3 class="font-bold text-gray-600 text-xs">プロフィール</h3>
    </a>
    <a href="/store/address.php" class="bg-white rounded-lg shadow-sm p-3 text-center hover:shadow-md transition">
        <i class="fas fa-map-marker-alt text-gray-400 text-lg mb-1"></i>
        <h3 class="font-bold text-gray-600 text-xs">住所管理</h3>
    </a>
    <a href="/store/services/" class="bg-white rounded-lg shadow-sm p-3 text-center hover:shadow-md transition">
        <i class="fas fa-paint-brush text-gray-400 text-lg mb-1"></i>
        <h3 class="font-bold text-gray-600 text-xs">サービスを探す</h3>
    </a>
</div>

<?php if (!empty($activeServiceTransactions)): ?>
<!-- 進行中のサービス取引 -->
<div class="bg-white rounded-lg shadow-sm overflow-hidden mb-6">
    <div class="p-4 border-b flex items-center justify-between bg-green-50">
        <h2 class="font-bold text-gray-800"><i class="fas fa-spinner fa-spin mr-2 text-green-500"></i>進行中のサービス取引</h2>
        <a href="/store/transactions/" class="text-sm text-green-600 font-medium hover:underline">すべて見る</a>
    </div>
    <div class="divide-y">
        <?php foreach ($activeServiceTransactions as $trans): 
            $status = $serviceStatusLabels[$trans['status']] ?? ['label' => $trans['status'], 'class' => 'bg-gray-100 text-gray-600'];
        ?>
        <a href="/store/transactions/?code=<?= htmlspecialchars($trans['transaction_code']) ?>" class="block p-4 hover:bg-gray-50 transition">
            <div class="flex items-center gap-4">
                <div class="w-14 h-14 bg-gray-100 rounded-lg overflow-hidden flex-shrink-0">
                    <?php if (!empty($trans['thumbnail_image'])): ?>
                    <img src="/<?= htmlspecialchars($trans['thumbnail_image']) ?>" class="w-full h-full object-cover">
                    <?php else: ?>
                    <div class="w-full h-full flex items-center justify-center text-gray-300">
                        <i class="fas fa-paint-brush text-xl"></i>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-bold text-gray-800 truncate"><?= htmlspecialchars($trans['service_title'] ?? '---') ?></p>
                    <p class="text-sm text-gray-500"><?= htmlspecialchars($trans['creator_name'] ?? '---') ?></p>
                    <p class="text-xs text-gray-400"><?= htmlspecialchars($trans['transaction_code']) ?></p>
                </div>
                <div class="text-right">
                    <span class="inline-block px-2 py-1 rounded text-xs font-bold <?= $status['class'] ?>">
                        <?= $status['label'] ?>
                    </span>
                    <?php if ($trans['total_amount']): ?>
                    <p class="text-sm font-bold text-gray-700 mt-1"><?= formatPrice($trans['total_amount']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<!-- 最近の注文 -->
<div class="bg-white rounded-lg shadow-sm overflow-hidden mb-6">
    <div class="p-4 border-b flex items-center justify-between">
        <h2 class="font-bold text-gray-800"><i class="fas fa-clock mr-2 text-pop-orange"></i>最近の注文</h2>
        <a href="/store/orders.php" class="text-sm text-pop-orange font-medium hover:underline">すべて見る</a>
    </div>
    
    <?php if (empty($recentOrders)): ?>
    <div class="p-8 text-center">
        <i class="fas fa-shopping-bag text-gray-300 text-3xl mb-2"></i>
        <p class="text-gray-500">まだ注文がありません</p>
        <a href="/store/" class="inline-block mt-3 text-pop-orange font-bold hover:underline">ストアを見る →</a>
    </div>
    <?php else: ?>
    <div class="divide-y">
        <?php foreach ($recentOrders as $order): 
            $status = $statusLabels[$order['order_status']] ?? ['label' => $order['order_status'], 'class' => 'bg-gray-100 text-gray-600'];
        ?>
        <a href="/store/order.php?id=<?= $order['id'] ?>" class="block p-4 hover:bg-gray-50 transition">
            <div class="flex items-start gap-4">
                <!-- 商品画像（最大4枚重ねて表示） -->
                <div class="relative w-16 h-16 flex-shrink-0">
                    <?php if (!empty($order['items'])): ?>
                        <?php foreach (array_slice($order['items'], 0, 3) as $idx => $item): ?>
                        <div class="absolute w-12 h-12 rounded bg-gray-100 border border-gray-200 overflow-hidden"
                             style="left: <?= $idx * 8 ?>px; top: <?= $idx * 8 ?>px; z-index: <?= 10 - $idx ?>;">
                            <?php if ($item['image']): ?>
                            <img src="/<?= htmlspecialchars(ltrim($item['image'], '/')) ?>" 
                                 alt="" class="w-full h-full object-cover">
                            <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center text-gray-300">
                                <i class="fas fa-image"></i>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    <?php else: ?>
                    <div class="w-12 h-12 rounded bg-gray-100 flex items-center justify-center text-gray-300">
                        <i class="fas fa-shopping-bag"></i>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- 注文情報 -->
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between mb-1">
                        <p class="font-bold text-gray-800 text-sm">注文 #<?= $order['order_number'] ?></p>
                        <span class="inline-block px-2 py-0.5 rounded text-xs font-bold <?= $status['class'] ?>"><?= $status['label'] ?></span>
                    </div>
                    <!-- 商品名 -->
                    <p class="text-xs text-gray-600 truncate">
                        <?php if (!empty($order['items'])): ?>
                            <?= htmlspecialchars($order['items'][0]['product_name'] ?? '商品') ?>
                            <?php if ($order['item_count'] > 1): ?>
                            <span class="text-gray-400">他<?= $order['item_count'] - 1 ?>点</span>
                            <?php endif; ?>
                        <?php endif; ?>
                    </p>
                    <div class="flex items-center justify-between mt-1">
                        <p class="text-xs text-gray-400"><?= date('Y/m/d H:i', strtotime($order['created_at'])) ?></p>
                        <p class="font-bold text-gray-800"><?= formatPrice($order['total'] ?? 0) ?></p>
                    </div>
                </div>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- その他のリンク -->
<div class="grid sm:grid-cols-2 gap-3">
    <a href="/store/address.php" class="flex items-center gap-3 bg-white rounded-lg shadow-sm p-4 hover:shadow-md transition">
        <i class="fas fa-map-marker-alt text-gray-400"></i>
        <div>
            <p class="font-bold text-gray-800 text-sm">配送先住所</p>
            <p class="text-xs text-gray-500">住所の追加・編集</p>
        </div>
    </a>
    <a href="/store/logout.php" class="flex items-center gap-3 bg-white rounded-lg shadow-sm p-4 hover:shadow-md transition">
        <i class="fas fa-sign-out-alt text-red-400"></i>
        <div>
            <p class="font-bold text-gray-800 text-sm">ログアウト</p>
            <p class="text-xs text-gray-500">アカウントからログアウト</p>
        </div>
    </a>
</div>

<?php include 'includes/footer.php'; ?>
