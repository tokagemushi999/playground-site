<?php
/**
 * 購入完了ページ
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/member-auth.php';
require_once '../includes/cart.php';
require_once '../includes/site-settings.php';
require_once '../includes/stripe-config.php';

requireMemberAuth();

$db = getDB();
$member = getCurrentMember();
$orderId = isset($_GET['order_id']) ? (int)$_GET['order_id'] : 0;
$sessionId = $_GET['session_id'] ?? '';

// 注文を取得
$stmt = $db->prepare("SELECT * FROM orders WHERE id = ? AND member_id = ?");
$stmt->execute([$orderId, $member['id']]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order) {
    header('Location: /store/mypage.php');
    exit;
}

// Stripe決済確認（まだ完了していない場合）
if ($order['payment_status'] === 'pending' && $sessionId) {
    try {
        initStripe();
        $session = \Stripe\Checkout\Session::retrieve($sessionId);
        
        if ($session->payment_status === 'paid') {
            // 決済完了処理
            $db->beginTransaction();
            
            try {
                // 注文ステータスを更新
                $stmt = $db->prepare("
                    UPDATE orders 
                    SET payment_status = 'paid', 
                        order_status = 'confirmed',
                        stripe_payment_intent_id = ?,
                        paid_at = NOW()
                    WHERE id = ? AND payment_status = 'pending'
                ");
                $stmt->execute([$session->payment_intent, $orderId]);
                
                // デジタル商品を本棚に追加（product_typeがnullの場合も対応）
                $stmt = $db->prepare("
                    SELECT oi.product_id, oi.product_type, p.product_type as product_product_type, p.related_work_id 
                    FROM order_items oi 
                    JOIN products p ON oi.product_id = p.id
                    WHERE oi.order_id = ?
                ");
                $stmt->execute([$orderId]);
                $items = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($items as $item) {
                    // product_typeがorder_itemsになければproductsから取得
                    $productType = $item['product_type'] ?: $item['product_product_type'];
                    
                    if ($productType === 'digital') {
                        // 本棚に追加
                        $stmt = $db->prepare("
                            INSERT IGNORE INTO member_bookshelf (member_id, product_id, order_id)
                            VALUES (?, ?, ?)
                        ");
                        $stmt->execute([$member['id'], $item['product_id'], $orderId]);
                        
                        error_log("Bookshelf added: member_id={$member['id']}, product_id={$item['product_id']}, order_id={$orderId}");
                    }
                    
                    // 在庫を減らす（物販の場合）
                    if ($productType === 'physical') {
                        $stmt = $db->prepare("
                            UPDATE products 
                            SET stock_quantity = stock_quantity - 1 
                            WHERE id = ? AND stock_quantity IS NOT NULL AND stock_quantity > 0
                        ");
                        $stmt->execute([$item['product_id']]);
                    }
                }
                
                // カートをクリア
                clearCart();
                
                $db->commit();
                
                error_log("Order completed: order_id={$orderId}, member_id={$member['id']}");
                
                // 注文情報を再取得
                $stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
                $stmt->execute([$orderId]);
                $order = $stmt->fetch(PDO::FETCH_ASSOC);
                
            } catch (Exception $e) {
                $db->rollBack();
                error_log('Order completion error: ' . $e->getMessage());
            }
        }
    } catch (Exception $e) {
        error_log('Stripe session error: ' . $e->getMessage());
    }
}

// 注文明細を取得
$stmt = $db->prepare("SELECT * FROM order_items WHERE order_id = ?");
$stmt->execute([$orderId]);
$orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// デジタル商品があるかチェック
$hasDigital = false;
foreach ($orderItems as $item) {
    if ($item['product_type'] === 'digital') {
        $hasDigital = true;
        break;
    }
}

$settings = getSiteSettings();
$siteName = $settings['site_name'] ?? 'CREATORS PLAYGROUND';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php include 'includes/pwa-meta.php'; ?>
    <title>ご注文完了 - <?= htmlspecialchars($siteName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'pop-blue': '#4A90D9',
                        'pop-pink': '#FF6B9D',
                        'pop-yellow': '#FFD93D',
                        'pop-purple': '#9B6DD8',
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50 min-h-screen">
    <!-- ヘッダー -->
    <header class="bg-white shadow-sm">
        <div class="max-w-4xl mx-auto px-4 py-4">
            <a href="/" class="text-xl font-bold text-gray-800"><?= htmlspecialchars($siteName) ?></a>
        </div>
    </header>

    <main class="max-w-2xl mx-auto px-4 py-12">
        <?php if ($order['payment_status'] === 'paid'): ?>
        <!-- 購入完了 -->
        <div class="text-center mb-8">
            <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6">
                <i class="fas fa-check text-4xl text-green-500"></i>
            </div>
            <h1 class="text-2xl font-bold text-gray-800 mb-2">ご注文ありがとうございます！</h1>
            <p class="text-gray-600">
                ご注文を受け付けました。<br>
                確認メールを <?= htmlspecialchars($member['email']) ?> にお送りしました。
            </p>
        </div>
        <?php else: ?>
        <!-- 処理中 -->
        <div class="text-center mb-8">
            <div class="w-20 h-20 bg-yellow-100 rounded-full flex items-center justify-center mx-auto mb-6">
                <i class="fas fa-clock text-4xl text-yellow-500"></i>
            </div>
            <h1 class="text-2xl font-bold text-gray-800 mb-2">お支払い処理中</h1>
            <p class="text-gray-600">決済の確認が完了するまでしばらくお待ちください</p>
        </div>
        <?php endif; ?>
        
        <!-- 注文詳細 -->
        <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
            <div class="border-b border-gray-200 pb-4 mb-4">
                <h2 class="text-lg font-bold text-gray-800">注文詳細</h2>
                <p class="text-sm text-gray-500 mt-1">注文番号: <?= htmlspecialchars($order['order_number']) ?></p>
            </div>
            
            <!-- 注文商品 -->
            <div class="space-y-3 mb-6">
                <?php foreach ($orderItems as $item): ?>
                <div class="flex items-center justify-between py-2">
                    <div>
                        <p class="font-bold text-gray-800"><?= htmlspecialchars($item['product_name']) ?></p>
                        <p class="text-sm text-gray-500">
                            <?= $item['product_type'] === 'digital' ? 'デジタル' : '物販' ?>
                            × <?= $item['quantity'] ?>
                        </p>
                    </div>
                    <p class="font-bold"><?= formatPrice($item['subtotal']) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- 合計 -->
            <div class="border-t border-gray-200 pt-4 space-y-2">
                <div class="flex justify-between text-gray-600">
                    <span>小計</span>
                    <span><?= formatPrice($order['subtotal']) ?></span>
                </div>
                <?php if ($order['shipping_fee'] > 0): ?>
                <div class="flex justify-between text-gray-600">
                    <span>送料</span>
                    <span><?= formatPrice($order['shipping_fee']) ?></span>
                </div>
                <?php endif; ?>
                <div class="flex justify-between font-bold text-lg pt-2 border-t border-gray-200">
                    <span>合計</span>
                    <span class="text-pop-pink"><?= formatPrice($order['total']) ?></span>
                </div>
            </div>
        </div>
        
        <?php if ($order['has_physical_items'] && $order['shipping_name']): ?>
        <!-- 配送先 -->
        <div class="bg-white rounded-2xl shadow-sm p-6 mb-6">
            <h2 class="text-lg font-bold text-gray-800 mb-4">
                <i class="fas fa-truck mr-2 text-pop-blue"></i>配送先
            </h2>
            <div class="text-gray-600">
                <p class="font-bold"><?= htmlspecialchars($order['shipping_name']) ?></p>
                <p>〒<?= htmlspecialchars($order['shipping_postal_code']) ?></p>
                <p><?= htmlspecialchars($order['shipping_prefecture'] . $order['shipping_city'] . $order['shipping_address1']) ?></p>
                <?php if ($order['shipping_address2']): ?>
                <p><?= htmlspecialchars($order['shipping_address2']) ?></p>
                <?php endif; ?>
                <p class="mt-2">TEL: <?= htmlspecialchars($order['shipping_phone']) ?></p>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- アクションボタン -->
        <div class="space-y-3">
            <?php if ($hasDigital && $order['payment_status'] === 'paid'): ?>
            <a href="/store/bookshelf.php" class="block w-full bg-pop-purple hover:bg-purple-600 text-white py-4 rounded-xl font-bold text-center transition-colors">
                <i class="fas fa-book-open mr-2"></i>本棚で読む
            </a>
            <?php endif; ?>
            
            <a href="/store/orders.php" class="block w-full bg-gray-100 hover:bg-gray-200 text-gray-700 py-4 rounded-xl font-bold text-center transition-colors">
                <i class="fas fa-receipt mr-2"></i>注文履歴を見る
            </a>
            
            <a href="/" class="block w-full text-center text-pop-blue hover:underline py-2">
                <i class="fas fa-home mr-1"></i>トップページに戻る
            </a>
        </div>
    </main>
</body>
</html>
