<?php
/**
 * 注文詳細ページ（商品画像付き）
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/member-auth.php';
require_once '../includes/site-settings.php';
require_once '../includes/cart.php';
require_once '../includes/stripe-config.php';

requireMemberAuth();

$member = getCurrentMember();
$db = getDB();
$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// 注文を取得
$stmt = $db->prepare("SELECT * FROM orders WHERE id = ? AND member_id = ?");
$stmt->execute([$orderId, $member['id']]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: /store/orders.php');
    exit;
}

// 注文明細を取得（商品画像付き）
$stmt = $db->prepare("
    SELECT oi.*, p.image 
    FROM order_items oi 
    LEFT JOIN products p ON oi.product_id = p.id 
    WHERE oi.order_id = ?
");
$stmt->execute([$orderId]);
$orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stripe領収書URLを取得
$receiptUrl = null;
if (!empty($order['stripe_payment_intent_id']) && $order['payment_status'] === 'paid') {
    try {
        initStripe();
        if (class_exists('\Stripe\PaymentIntent')) {
            $paymentIntent = \Stripe\PaymentIntent::retrieve($order['stripe_payment_intent_id']);
            if (!empty($paymentIntent->latest_charge)) {
                $charge = \Stripe\Charge::retrieve($paymentIntent->latest_charge);
                $receiptUrl = $charge->receipt_url ?? null;
            }
        }
    } catch (Exception $e) {
        // エラー時は領収書なし
    }
}

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

$status = $statusLabels[$order['order_status']] ?? ['label' => $order['order_status'], 'class' => 'bg-gray-100 text-gray-600'];

$pageTitle = '注文詳細';
include 'includes/header.php';
?>

<!-- ヘッダー -->
<div class="flex items-center justify-between mb-6">
    <div>
        <p class="text-sm text-gray-500 mb-1">注文番号</p>
        <h1 class="text-xl font-bold text-gray-800"><?= htmlspecialchars($order['order_number']) ?></h1>
    </div>
    <span class="inline-block px-3 py-1 rounded text-sm font-bold <?= $status['class'] ?>">
        <?= $status['label'] ?>
    </span>
</div>

<!-- 注文情報 -->
<div class="bg-white rounded-lg shadow-sm p-4 mb-4">
    <h2 class="font-bold text-gray-800 mb-3 text-sm">注文情報</h2>
    <div class="grid grid-cols-2 gap-3 text-sm">
        <div>
            <p class="text-gray-500 text-xs">注文日時</p>
            <p class="font-medium"><?= date('Y年n月j日 H:i', strtotime($order['created_at'])) ?></p>
        </div>
        <div>
            <p class="text-gray-500 text-xs">お支払い状況</p>
            <p class="font-medium <?= $order['payment_status'] === 'paid' ? 'text-green-600' : 'text-gray-600' ?>">
                <?= $order['payment_status'] === 'paid' ? '支払い済み' : ($order['payment_status'] === 'pending' ? '処理中' : $order['payment_status']) ?>
            </p>
        </div>
    </div>
</div>

<!-- 注文商品 -->
<div class="bg-white rounded-lg shadow-sm p-4 mb-4">
    <h2 class="font-bold text-gray-800 mb-3 text-sm">ご注文商品</h2>
    <div class="space-y-3">
        <?php foreach ($orderItems as $item): ?>
        <div class="flex items-center gap-3 pb-3 border-b border-gray-100 last:border-0 last:pb-0">
            <!-- 商品画像 -->
            <div class="w-16 h-16 rounded bg-gray-100 overflow-hidden flex-shrink-0">
                <?php if ($item['image']): ?>
                <img src="/<?= htmlspecialchars(ltrim($item['image'], '/')) ?>" 
                     alt="" class="w-full h-full object-cover">
                <?php else: ?>
                <div class="w-full h-full flex items-center justify-center text-gray-300">
                    <i class="fas fa-image text-xl"></i>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- 商品情報 -->
            <div class="flex-1 min-w-0">
                <p class="font-bold text-gray-800 text-sm truncate"><?= htmlspecialchars($item['product_name']) ?></p>
                <p class="text-xs text-gray-500">
                    <?= $item['product_type'] === 'digital' ? 'デジタル' : '物販' ?>
                    × <?= $item['quantity'] ?>点
                </p>
                <p class="font-bold text-gray-800">¥<?= number_format($item['subtotal']) ?></p>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    
    <!-- 合計 -->
    <div class="border-t border-gray-200 mt-4 pt-4 space-y-2 text-sm">
        <div class="flex justify-between text-gray-600">
            <span>小計</span>
            <span>¥<?= number_format($order['subtotal']) ?></span>
        </div>
        <?php if ($order['shipping_fee'] > 0): ?>
        <div class="flex justify-between text-gray-600">
            <span>送料</span>
            <span>¥<?= number_format($order['shipping_fee']) ?></span>
        </div>
        <?php endif; ?>
        <div class="flex justify-between font-bold text-lg pt-2 border-t border-gray-200">
            <span>合計</span>
            <span class="text-store-primary">¥<?= number_format($order['total']) ?></span>
        </div>
    </div>
</div>

<?php if ($order['has_physical_items'] && $order['shipping_name']): ?>
<!-- 配送先 -->
<div class="bg-white rounded-lg shadow-sm p-4 mb-4">
    <h2 class="font-bold text-gray-800 mb-3 text-sm">
        <i class="fas fa-truck mr-2 text-store-primary"></i>配送先
    </h2>
    <div class="text-sm text-gray-600">
        <p class="font-bold text-gray-800"><?= htmlspecialchars($order['shipping_name']) ?></p>
        <p>〒<?= htmlspecialchars($order['shipping_postal_code']) ?></p>
        <p><?= htmlspecialchars($order['shipping_prefecture'] . $order['shipping_city'] . $order['shipping_address1']) ?></p>
        <?php if ($order['shipping_address2']): ?>
        <p><?= htmlspecialchars($order['shipping_address2']) ?></p>
        <?php endif; ?>
        <p class="mt-2">TEL: <?= htmlspecialchars($order['shipping_phone']) ?></p>
    </div>
    
    <?php if ($order['tracking_number']): ?>
    <?php
    // 配送業者名
    $carrierNames = [
        'yamato' => 'ヤマト運輸',
        'sagawa' => '佐川急便',
        'japanpost' => '日本郵便',
        'japanpost_yu' => 'ゆうパック',
        'clickpost' => 'クリックポスト',
        'nekopos' => 'ネコポス',
        'yupacket' => 'ゆうパケット',
        'other' => 'その他',
    ];
    $carrierName = $carrierNames[$order['shipping_carrier'] ?? ''] ?? '';
    
    // 追跡URL
    $trackingUrl = '';
    switch ($order['shipping_carrier'] ?? '') {
        case 'yamato':
        case 'nekopos':
            $trackingUrl = "https://toi.kuronekoyamato.co.jp/cgi-bin/tneko?number=" . urlencode($order['tracking_number']);
            break;
        case 'sagawa':
            $trackingUrl = "https://k2k.sagawa-exp.co.jp/p/web/okurijosearch.do?okurijoNo=" . urlencode($order['tracking_number']);
            break;
        case 'japanpost':
        case 'japanpost_yu':
        case 'clickpost':
        case 'yupacket':
            $trackingUrl = "https://trackings.post.japanpost.jp/services/srv/search/?requestNo1=" . urlencode($order['tracking_number']);
            break;
    }
    ?>
    <div class="mt-3 p-3 bg-blue-50 rounded">
        <p class="text-sm text-blue-700 mb-1">
            <?php if ($carrierName): ?>
            <strong><?= htmlspecialchars($carrierName) ?></strong>
            <?php endif; ?>
        </p>
        <p class="text-sm text-blue-700">
            <strong>追跡番号:</strong> <?= htmlspecialchars($order['tracking_number']) ?>
        </p>
        <?php if ($trackingUrl): ?>
        <a href="<?= htmlspecialchars($trackingUrl) ?>" target="_blank" class="inline-block mt-2 px-3 py-1 bg-blue-500 text-white text-xs rounded hover:bg-blue-600 transition">
            <i class="fas fa-external-link-alt mr-1"></i>配送状況を確認
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<!-- 領収書 -->
<?php if ($order['payment_status'] === 'paid'): ?>
<div class="bg-white rounded-lg shadow-sm p-4 mb-4">
    <h2 class="font-bold text-gray-800 mb-3 text-sm">
        <i class="fas fa-receipt mr-2 text-store-primary"></i>領収書
    </h2>
    <p class="text-sm text-gray-600 mb-3">領収書の表示・印刷・PDF保存ができます。</p>
    <div class="flex flex-wrap gap-2">
        <a href="/store/invoice.php?id=<?= $orderId ?>" target="_blank" class="inline-block px-4 py-2 bg-gray-100 text-gray-700 rounded font-bold hover:bg-gray-200 transition text-sm">
            <i class="fas fa-file-invoice mr-2"></i>領収書を表示
        </a>
        <?php if ($receiptUrl): ?>
        <a href="<?= htmlspecialchars($receiptUrl) ?>" target="_blank" class="inline-block px-4 py-2 bg-indigo-100 text-indigo-700 rounded font-bold hover:bg-indigo-200 transition text-sm">
            <i class="fab fa-stripe mr-2"></i>Stripe領収書
        </a>
        <?php endif; ?>
    </div>
</div>
<?php endif; ?>

<!-- デジタル商品アクセス -->
<?php 
$hasDigital = false;
foreach ($orderItems as $item) {
    if ($item['product_type'] === 'digital') {
        $hasDigital = true;
        break;
    }
}
?>
<?php if ($hasDigital && $order['payment_status'] === 'paid'): ?>
<div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-4">
    <h2 class="font-bold text-green-700 mb-2">
        <i class="fas fa-book-open mr-2"></i>デジタル商品
    </h2>
    <p class="text-sm text-green-600 mb-3">購入したデジタル商品は本棚からいつでも閲覧できます。</p>
    <a href="/store/bookshelf.php" class="inline-block px-4 py-2 bg-green-600 text-white rounded font-bold hover:bg-green-700 transition text-sm">
        <i class="fas fa-book mr-2"></i>本棚を開く
    </a>
</div>
<?php endif; ?>

<!-- ナビゲーション -->
<div class="flex items-center justify-between text-sm">
    <a href="/store/orders.php" class="text-store-primary hover:underline">
        <i class="fas fa-arrow-left mr-1"></i>注文履歴に戻る
    </a>
    <a href="/contact.php" class="text-gray-500 hover:text-gray-700">
        <i class="fas fa-question-circle mr-1"></i>お問い合わせ
    </a>
</div>

<?php include 'includes/footer.php'; ?>
