<?php
/**
 * 注文履歴ページ（商品画像付き）
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/member-auth.php';
require_once '../includes/site-settings.php';
require_once '../includes/cart.php';

requireMemberAuth();

$member = getCurrentMember();
$db = getDB();

// 注文履歴を取得
$stmt = $db->prepare("
    SELECT o.*, 
           (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
    FROM orders o 
    WHERE o.member_id = ? 
    ORDER BY o.created_at DESC
");
$stmt->execute([$member['id']]);
$orders = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 各注文の商品画像を取得
foreach ($orders as &$order) {
    $stmt = $db->prepare("
        SELECT oi.*, p.name as product_name, p.image 
        FROM order_items oi 
        LEFT JOIN products p ON oi.product_id = p.id 
        WHERE oi.order_id = ? 
        LIMIT 4
    ");
    $stmt->execute([$order['id']]);
    $order['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
unset($order);

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
    'refunded' => ['label' => '返金済み', 'class' => 'bg-red-100 text-red-600'],
];

$pageTitle = '注文履歴';
include 'includes/header.php';
?>

<h1 class="text-xl font-bold text-gray-800 mb-6">
    <i class="fas fa-receipt text-store-primary mr-2"></i>注文履歴
</h1>

<?php if (empty($orders)): ?>
<div class="bg-white rounded-lg shadow-sm p-12 text-center">
    <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-6">
        <i class="fas fa-receipt text-4xl text-gray-300"></i>
    </div>
    <h2 class="text-lg font-bold text-gray-800 mb-2">注文履歴がありません</h2>
    <p class="text-gray-500 mb-6">まだ何も購入していません</p>
    <a href="/store/" class="inline-block px-6 py-2 bg-store-primary text-white rounded font-bold hover:bg-orange-600 transition">
        <i class="fas fa-store mr-2"></i>ストアを見る
    </a>
</div>
<?php else: ?>
<div class="space-y-4">
    <?php foreach ($orders as $order): 
        $status = $statusLabels[$order['order_status']] ?? ['label' => $order['order_status'], 'class' => 'bg-gray-100 text-gray-600'];
    ?>
    <a href="/store/order.php?id=<?= $order['id'] ?>" class="block bg-white rounded-lg shadow-sm hover:shadow-md transition">
        <div class="p-4">
            <div class="flex items-start gap-4">
                <!-- 商品画像（最大3枚重ねて表示） -->
                <div class="relative w-20 h-20 flex-shrink-0">
                    <?php if (!empty($order['items'])): ?>
                        <?php foreach (array_slice($order['items'], 0, 3) as $idx => $item): ?>
                        <div class="absolute w-14 h-14 rounded bg-gray-100 border border-gray-200 overflow-hidden shadow-sm"
                             style="left: <?= $idx * 10 ?>px; top: <?= $idx * 10 ?>px; z-index: <?= 10 - $idx ?>;">
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
                    <div class="w-14 h-14 rounded bg-gray-100 flex items-center justify-center text-gray-300">
                        <i class="fas fa-shopping-bag"></i>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- 注文情報 -->
                <div class="flex-1 min-w-0">
                    <div class="flex items-center justify-between mb-2">
                        <div>
                            <p class="font-bold text-gray-800">注文 #<?= $order['order_number'] ?></p>
                            <p class="text-xs text-gray-400"><?= date('Y年n月j日 H:i', strtotime($order['created_at'])) ?></p>
                        </div>
                        <span class="inline-block px-2 py-1 rounded text-xs font-bold <?= $status['class'] ?>">
                            <?= $status['label'] ?>
                        </span>
                    </div>
                    
                    <!-- 商品名 -->
                    <p class="text-sm text-gray-600 mb-2">
                        <?php if (!empty($order['items'])): ?>
                            <?= htmlspecialchars($order['items'][0]['product_name'] ?? '商品') ?>
                            <?php if ($order['item_count'] > 1): ?>
                            <span class="text-gray-400">他<?= $order['item_count'] - 1 ?>点</span>
                            <?php endif; ?>
                        <?php else: ?>
                            <?= $order['item_count'] ?>点の商品
                        <?php endif; ?>
                    </p>
                    
                    <div class="flex items-center justify-between">
                        <p class="font-bold text-lg text-gray-800"><?= formatPrice($order['total']) ?></p>
                        <span class="text-store-primary text-sm font-medium">
                            詳細を見る <i class="fas fa-chevron-right ml-1"></i>
                        </span>
                    </div>
                </div>
            </div>
        </div>
    </a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
