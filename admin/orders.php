<?php
/**
 * 注文管理ページ（管理画面）
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/site-settings.php';
require_once '../includes/mail.php';
require_once '../includes/stripe-config.php';
requireAuth();

$db = getDB();
$message = '';
$error = '';

// ordersテーブルが存在するか確認
$tableExists = true;
try {
    $db->query("SELECT 1 FROM orders LIMIT 1");
} catch (PDOException $e) {
    $tableExists = false;
    $error = 'ordersテーブルが存在しません。sql/ec_tables.sqlを実行してください。';
}

// ステータス更新
if ($tableExists && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $orderId = (int)$_POST['order_id'];
    $newStatus = $_POST['order_status'];
    $trackingNumber = trim($_POST['tracking_number'] ?? '');
    $shippingCarrier = trim($_POST['shipping_carrier'] ?? '');
    $sendShippingMail = isset($_POST['send_shipping_mail']);
    
    // 追跡番号と配送業者が両方入力されていて、まだ発送済み/完了でなければ自動的に発送済みに
    if (!empty($trackingNumber) && !empty($shippingCarrier) && !in_array($newStatus, ['shipped', 'completed', 'cancelled', 'refunded'])) {
        $newStatus = 'shipped';
    }
    
    try {
        // 更新前のステータスを取得
        $stmt = $db->prepare("SELECT order_status FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $oldStatus = $stmt->fetchColumn();
        
        $sql = "UPDATE orders SET order_status = ?";
        $params = [$newStatus];
        
        // 追跡番号
        $sql .= ", tracking_number = ?";
        $params[] = $trackingNumber ?: null;
        
        // 配送業者
        $sql .= ", shipping_carrier = ?";
        $params[] = $shippingCarrier ?: null;
        
        if ($newStatus === 'shipped' && $oldStatus !== 'shipped') {
            $sql .= ", shipped_at = NOW()";
        } elseif ($newStatus === 'completed' && $oldStatus !== 'completed') {
            $sql .= ", completed_at = NOW()";
        }
        
        $sql .= " WHERE id = ?";
        $params[] = $orderId;
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        $message = 'ステータスを更新しました';
        
        // 発送通知メール送信（発送済みに変更された場合、またはチェックボックスがON）
        if (($sendShippingMail || ($newStatus === 'shipped' && $oldStatus !== 'shipped')) && $newStatus === 'shipped') {
            // 注文情報取得
            $stmt = $db->prepare("SELECT o.*, m.name as member_name, m.email as member_email FROM orders o LEFT JOIN members m ON o.member_id = m.id WHERE o.id = ?");
            $stmt->execute([$orderId]);
            $order = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // 注文商品取得
            $stmt = $db->prepare("SELECT * FROM order_items WHERE order_id = ?");
            $stmt->execute([$orderId]);
            $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 会員情報
            $member = [
                'name' => $order['member_name'],
                'email' => $order['member_email'],
            ];
            
            if (sendShippingNotificationMail($order, $orderItems, $member)) {
                $message .= '（発送通知メールを送信しました）';
            } else {
                $message .= '（メール送信に失敗しました）';
            }
        }
    } catch (PDOException $e) {
        $error = '更新に失敗しました: ' . $e->getMessage();
    }
}

// 強制注文作成（テスト用）
if ($tableExists && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_test_order'])) {
    $memberId = (int)$_POST['member_id'];
    $productId = (int)$_POST['product_id'];
    
    try {
        // 商品情報取得
        $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
        $stmt->execute([$productId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            throw new Exception('商品が見つかりません');
        }
        
        // 注文番号生成
        $orderNumber = 'ORD-' . date('Ymd') . '-' . str_pad(mt_rand(1, 9999), 4, '0', STR_PAD_LEFT);
        
        // 注文作成
        $stmt = $db->prepare("
            INSERT INTO orders (member_id, order_number, subtotal, shipping_fee, total, 
                payment_status, order_status, payment_method, created_at)
            VALUES (?, ?, ?, 0, ?, 'paid', 'completed', 'manual', NOW())
        ");
        $stmt->execute([$memberId, $orderNumber, $product['price'], $product['price']]);
        $orderId = $db->lastInsertId();
        
        // 注文明細作成
        $stmt = $db->prepare("
            INSERT INTO order_items (order_id, product_id, product_name, product_type, price, quantity, subtotal)
            VALUES (?, ?, ?, ?, ?, 1, ?)
        ");
        $stmt->execute([$orderId, $product['id'], $product['name'], $product['product_type'], $product['price'], $product['price']]);
        
        // デジタル商品の場合は本棚に追加
        if ($product['product_type'] === 'digital') {
            $stmt = $db->prepare("
                INSERT IGNORE INTO member_bookshelf (member_id, product_id, order_id, created_at)
                VALUES (?, ?, ?, NOW())
            ");
            $stmt->execute([$memberId, $product['id'], $orderId]);
        }
        
        $message = "テスト注文を作成しました（注文番号: {$orderNumber}）";
    } catch (Exception $e) {
        $error = 'テスト注文の作成に失敗しました: ' . $e->getMessage();
    }
}

// 注文削除処理
if ($tableExists && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_order'])) {
    $orderId = (int)$_POST['order_id'];
    
    try {
        $db->beginTransaction();
        
        // 注文情報を取得
        $stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            throw new Exception('注文が見つかりません');
        }
        
        // 本棚から関連データを削除
        $stmt = $db->prepare("DELETE FROM member_bookshelf WHERE order_id = ?");
        $stmt->execute([$orderId]);
        
        // 読書進捗を削除（本棚に紐づいているため）
        // order_idで削除できる場合のみ
        try {
            $stmt = $db->prepare("DELETE FROM reading_progress WHERE order_id = ?");
            $stmt->execute([$orderId]);
        } catch (Exception $e) {
            // reading_progressにorder_idカラムがない場合は無視
        }
        
        // 注文明細を削除
        $stmt = $db->prepare("DELETE FROM order_items WHERE order_id = ?");
        $stmt->execute([$orderId]);
        
        // 注文を削除
        $stmt = $db->prepare("DELETE FROM orders WHERE id = ?");
        $stmt->execute([$orderId]);
        
        $db->commit();
        
        $message = "注文（{$order['order_number']}）を完全に削除しました";
        
        // 詳細表示をクリア
        header('Location: orders.php?deleted=1');
        exit;
        
    } catch (Exception $e) {
        $db->rollBack();
        $error = '削除に失敗しました: ' . $e->getMessage();
    }
}

// 削除完了メッセージ
if (isset($_GET['deleted'])) {
    $message = '注文を完全に削除しました';
}

// フィルター
$statusFilter = $_GET['status'] ?? '';
$paymentFilter = $_GET['payment'] ?? '';
$searchQuery = trim($_GET['q'] ?? '');

// 注文一覧取得
$orders = [];
if ($tableExists) {
    $sql = "SELECT o.*, m.name as member_name, m.email as member_email,
                   (SELECT COUNT(*) FROM order_items WHERE order_id = o.id) as item_count
            FROM orders o
            LEFT JOIN members m ON o.member_id = m.id
            WHERE 1=1";
    $params = [];
    
    if ($searchQuery) {
        $sql .= " AND (o.order_number LIKE ? OR m.name LIKE ? OR m.email LIKE ?)";
        $params[] = "%{$searchQuery}%";
        $params[] = "%{$searchQuery}%";
        $params[] = "%{$searchQuery}%";
    }
    
    if ($statusFilter) {
        $sql .= " AND o.order_status = ?";
        $params[] = $statusFilter;
    }
    
    if ($paymentFilter) {
        $sql .= " AND o.payment_status = ?";
        $params[] = $paymentFilter;
    }
    
    $sql .= " ORDER BY o.created_at DESC LIMIT 100";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 各注文の商品画像を取得
    foreach ($orders as &$order) {
        $stmt = $db->prepare("
            SELECT oi.*, p.name as product_name, p.image 
            FROM order_items oi 
            LEFT JOIN products p ON oi.product_id = p.id 
            WHERE oi.order_id = ? 
            LIMIT 3
        ");
        $stmt->execute([$order['id']]);
        $order['items'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    unset($order);
}

// 注文詳細取得
$orderDetail = null;
$orderItems = [];
$stripePayment = null;

if (isset($_GET['view']) && is_numeric($_GET['view'])) {
    $stmt = $db->prepare("SELECT o.*, m.name as member_name, m.email as member_email FROM orders o LEFT JOIN members m ON o.member_id = m.id WHERE o.id = ?");
    $stmt->execute([(int)$_GET['view']]);
    $orderDetail = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($orderDetail) {
        $stmt = $db->prepare("SELECT * FROM order_items WHERE order_id = ?");
        $stmt->execute([$orderDetail['id']]);
        $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Stripe決済情報を取得
        if (!empty($orderDetail['stripe_payment_intent_id'])) {
            try {
                initStripe();
                // Stripeライブラリが読み込まれているか確認
                if (!class_exists('\Stripe\PaymentIntent')) {
                    throw new Exception('Stripeライブラリが見つかりません');
                }
                $paymentIntent = \Stripe\PaymentIntent::retrieve($orderDetail['stripe_payment_intent_id']);
                
                // Chargeの詳細を取得
                $charge = null;
                if (!empty($paymentIntent->latest_charge)) {
                    $charge = \Stripe\Charge::retrieve($paymentIntent->latest_charge);
                }
                
                $stripePayment = [
                    'payment_intent_id' => $paymentIntent->id,
                    'amount' => $paymentIntent->amount,
                    'currency' => $paymentIntent->currency,
                    'status' => $paymentIntent->status,
                    'created' => date('Y/m/d H:i:s', $paymentIntent->created),
                    'payment_method_type' => $paymentIntent->payment_method_types[0] ?? 'card',
                    'receipt_url' => $charge->receipt_url ?? null,
                    'card_brand' => $charge->payment_method_details->card->brand ?? null,
                    'card_last4' => $charge->payment_method_details->card->last4 ?? null,
                    'card_exp_month' => $charge->payment_method_details->card->exp_month ?? null,
                    'card_exp_year' => $charge->payment_method_details->card->exp_year ?? null,
                    'risk_level' => $charge->outcome->risk_level ?? null,
                    'seller_message' => $charge->outcome->seller_message ?? null,
                ];
            } catch (Exception $e) {
                // Stripe APIエラー時はnullのまま
                error_log('Stripe API error: ' . $e->getMessage());
            }
        }
    }
}

$settings = getSiteSettings();
$siteName = $settings['site_name'] ?? 'CREATORS PLAYGROUND';

// テスト注文用の会員・商品リスト取得
$members = [];
$products = [];
if ($tableExists) {
    try {
        $stmt = $db->query("SELECT id, name, email FROM members ORDER BY name");
        $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $stmt = $db->query("SELECT id, name, price, product_type FROM products WHERE is_published = 1 ORDER BY name");
        $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // テーブルがない場合は空のまま
    }
}

$statusLabels = [
    'pending' => ['label' => '処理待ち', 'color' => 'gray'],
    'confirmed' => ['label' => '確認済み', 'color' => 'blue'],
    'processing' => ['label' => '準備中', 'color' => 'yellow'],
    'shipped' => ['label' => '発送済み', 'color' => 'blue'],
    'completed' => ['label' => '完了', 'color' => 'green'],
    'cancelled' => ['label' => 'キャンセル', 'color' => 'red'],
    'refunded' => ['label' => '返金済み', 'color' => 'red'],
];

$paymentLabels = [
    'pending' => ['label' => '未払い', 'color' => 'gray'],
    'paid' => ['label' => '支払い済み', 'color' => 'green'],
    'failed' => ['label' => '失敗', 'color' => 'red'],
    'refunded' => ['label' => '返金済み', 'color' => 'orange'],
];

$pageTitle = "注文管理";
include "includes/header.php";
?>
        <h1 class="text-2xl font-bold text-gray-800 mb-6">
            <i class="fas fa-receipt text-green-500 mr-2"></i>注文管理
        </h1>
            
            <?php if ($message): ?>
            <div class="bg-green-100 border border-green-300 text-green-700 px-4 py-3 rounded-lg mb-6">
                <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($message) ?>
            </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
            <div class="bg-red-100 border border-red-300 text-red-700 px-4 py-3 rounded-lg mb-6">
                <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>
            
            <!-- テスト注文作成フォーム -->
            <?php if (!empty($members) && !empty($products)): ?>
            <div class="bg-yellow-50 border border-yellow-200 rounded-xl p-4 mb-6">
                <h3 class="font-bold text-yellow-800 mb-3">
                    <i class="fas fa-flask mr-2"></i>テスト注文作成（本棚テスト用）
                </h3>
                <form method="POST" class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-[150px]">
                        <label class="block text-xs text-gray-600 mb-1">会員</label>
                        <select name="member_id" required class="w-full px-3 py-2 border rounded text-sm">
                            <option value="">選択...</option>
                            <?php foreach ($members as $m): ?>
                            <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['name']) ?> (<?= htmlspecialchars($m['email']) ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="flex-1 min-w-[200px]">
                        <label class="block text-xs text-gray-600 mb-1">商品</label>
                        <select name="product_id" required class="w-full px-3 py-2 border rounded text-sm">
                            <option value="">選択...</option>
                            <?php foreach ($products as $p): ?>
                            <option value="<?= $p['id'] ?>"><?= htmlspecialchars($p['name']) ?> (¥<?= number_format($p['price']) ?>) [<?= $p['product_type'] === 'digital' ? 'DL' : '物販' ?>]</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="create_test_order" class="px-4 py-2 bg-yellow-500 hover:bg-yellow-600 text-white rounded font-bold text-sm">
                        <i class="fas fa-plus mr-1"></i>注文作成
                    </button>
                </form>
                <p class="text-xs text-gray-500 mt-2">※ 決済なしで注文を作成し、デジタル商品は本棚に追加されます</p>
            </div>
            <?php endif; ?>
            
            <?php if ($orderDetail): ?>
            <!-- 注文詳細 -->
            <div class="bg-white rounded-xl shadow-sm p-6 mb-8">
                <div class="flex items-center justify-between mb-6">
                    <h2 class="text-xl font-bold">注文詳細: <?= htmlspecialchars($orderDetail['order_number']) ?></h2>
                    <a href="orders.php" class="text-gray-500 hover:text-gray-700">
                        <i class="fas fa-times text-xl"></i>
                    </a>
                </div>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                    <!-- 注文情報 -->
                    <div>
                        <h3 class="font-bold text-gray-700 mb-3">注文情報</h3>
                        <table class="w-full text-sm">
                            <tr><td class="py-1 text-gray-500">注文番号</td><td class="py-1 font-bold"><?= htmlspecialchars($orderDetail['order_number']) ?></td></tr>
                            <tr><td class="py-1 text-gray-500">注文日時</td><td class="py-1"><?= date('Y/m/d H:i', strtotime($orderDetail['created_at'])) ?></td></tr>
                            <tr><td class="py-1 text-gray-500">購入者</td><td class="py-1"><?= htmlspecialchars($orderDetail['member_name']) ?></td></tr>
                            <tr><td class="py-1 text-gray-500">メール</td><td class="py-1"><?= htmlspecialchars($orderDetail['member_email']) ?></td></tr>
                        </table>
                    </div>
                    
                    <!-- 配送先 -->
                    <?php if ($orderDetail['has_physical_items']): ?>
                    <div>
                        <h3 class="font-bold text-gray-700 mb-3">配送先</h3>
                        <div class="text-sm">
                            <p class="font-bold"><?= htmlspecialchars($orderDetail['shipping_name']) ?></p>
                            <p>〒<?= htmlspecialchars($orderDetail['shipping_postal_code']) ?></p>
                            <p><?= htmlspecialchars($orderDetail['shipping_prefecture'] . $orderDetail['shipping_city'] . $orderDetail['shipping_address1']) ?></p>
                            <?php if ($orderDetail['shipping_address2']): ?><p><?= htmlspecialchars($orderDetail['shipping_address2']) ?></p><?php endif; ?>
                            <p>TEL: <?= htmlspecialchars($orderDetail['shipping_phone']) ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Stripe決済情報 -->
                <?php if ($stripePayment): ?>
                <div class="bg-gradient-to-r from-indigo-50 to-purple-50 border border-indigo-200 rounded-lg p-4 mb-6">
                    <h3 class="font-bold text-indigo-800 mb-3">
                        <i class="fab fa-stripe text-indigo-600 mr-2"></i>Stripe決済情報
                    </h3>
                    <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4 text-sm">
                        <div>
                            <span class="text-gray-500">決済ID</span>
                            <p class="font-mono text-xs break-all"><?= htmlspecialchars($stripePayment['payment_intent_id']) ?></p>
                        </div>
                        <div>
                            <span class="text-gray-500">決済ステータス</span>
                            <p>
                                <?php
                                $stripeStatusLabels = [
                                    'succeeded' => ['label' => '成功', 'class' => 'bg-green-100 text-green-700'],
                                    'processing' => ['label' => '処理中', 'class' => 'bg-yellow-100 text-yellow-700'],
                                    'requires_payment_method' => ['label' => '支払い方法が必要', 'class' => 'bg-red-100 text-red-700'],
                                    'requires_confirmation' => ['label' => '確認が必要', 'class' => 'bg-yellow-100 text-yellow-700'],
                                    'canceled' => ['label' => 'キャンセル', 'class' => 'bg-gray-100 text-gray-700'],
                                ];
                                $statusInfo = $stripeStatusLabels[$stripePayment['status']] ?? ['label' => $stripePayment['status'], 'class' => 'bg-gray-100 text-gray-700'];
                                ?>
                                <span class="inline-block px-2 py-1 rounded text-xs font-bold <?= $statusInfo['class'] ?>"><?= $statusInfo['label'] ?></span>
                            </p>
                        </div>
                        <div>
                            <span class="text-gray-500">決済日時</span>
                            <p><?= htmlspecialchars($stripePayment['created']) ?></p>
                        </div>
                        <?php if ($stripePayment['card_brand']): ?>
                        <div>
                            <span class="text-gray-500">カード情報</span>
                            <p>
                                <?php
                                $brandIcons = [
                                    'visa' => 'fab fa-cc-visa text-blue-600',
                                    'mastercard' => 'fab fa-cc-mastercard text-red-500',
                                    'amex' => 'fab fa-cc-amex text-blue-400',
                                    'jcb' => 'fab fa-cc-jcb text-green-600',
                                    'diners' => 'fab fa-cc-diners-club text-blue-800',
                                    'discover' => 'fab fa-cc-discover text-orange-500',
                                ];
                                $brandIcon = $brandIcons[$stripePayment['card_brand']] ?? 'fas fa-credit-card text-gray-500';
                                ?>
                                <i class="<?= $brandIcon ?> mr-1"></i>
                                <span class="uppercase"><?= htmlspecialchars($stripePayment['card_brand']) ?></span>
                                **** <?= htmlspecialchars($stripePayment['card_last4']) ?>
                                <span class="text-gray-400 text-xs ml-1">(<?= $stripePayment['card_exp_month'] ?>/<?= $stripePayment['card_exp_year'] ?>)</span>
                            </p>
                        </div>
                        <?php endif; ?>
                        <div>
                            <span class="text-gray-500">決済金額</span>
                            <p class="font-bold text-lg">¥<?= number_format($stripePayment['amount']) ?></p>
                        </div>
                        <?php if ($stripePayment['risk_level']): ?>
                        <div>
                            <span class="text-gray-500">リスク評価</span>
                            <p>
                                <?php
                                $riskColors = [
                                    'normal' => 'text-green-600',
                                    'elevated' => 'text-yellow-600',
                                    'highest' => 'text-red-600',
                                ];
                                $riskColor = $riskColors[$stripePayment['risk_level']] ?? 'text-gray-600';
                                ?>
                                <span class="<?= $riskColor ?> font-bold"><?= ucfirst($stripePayment['risk_level']) ?></span>
                                <?php if ($stripePayment['seller_message']): ?>
                                <span class="text-xs text-gray-500 block"><?= htmlspecialchars($stripePayment['seller_message']) ?></span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="mt-3 pt-3 border-t border-indigo-200 flex flex-wrap gap-3">
                        <?php if ($stripePayment['receipt_url']): ?>
                        <a href="<?= htmlspecialchars($stripePayment['receipt_url']) ?>" target="_blank" class="text-indigo-600 hover:text-indigo-800 text-sm font-bold">
                            <i class="fas fa-receipt mr-1"></i>領収書を見る
                        </a>
                        <?php endif; ?>
                        <a href="https://dashboard.stripe.com/payments/<?= htmlspecialchars($stripePayment['payment_intent_id']) ?>" target="_blank" class="text-indigo-600 hover:text-indigo-800 text-sm font-bold">
                            <i class="fas fa-external-link-alt mr-1"></i>Stripeダッシュボードで見る
                        </a>
                    </div>
                </div>
                <?php elseif ($orderDetail['payment_method'] === 'stripe' && empty($orderDetail['stripe_payment_intent_id'])): ?>
                <div class="bg-yellow-50 border border-yellow-200 rounded-lg p-4 mb-6">
                    <p class="text-yellow-700 text-sm">
                        <i class="fas fa-exclamation-triangle mr-1"></i>Stripe決済情報が取得できません（Payment Intent IDが未設定）
                    </p>
                </div>
                <?php endif; ?>
                
                <!-- 注文商品 -->
                <h3 class="font-bold text-gray-700 mb-3">注文商品</h3>
                <table class="w-full text-sm mb-6">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-3 py-2 text-left">商品名</th>
                            <th class="px-3 py-2 text-left">タイプ</th>
                            <th class="px-3 py-2 text-right">単価</th>
                            <th class="px-3 py-2 text-right">数量</th>
                            <th class="px-3 py-2 text-right">小計</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orderItems as $item): ?>
                        <tr class="border-t">
                            <td class="px-3 py-2"><?= htmlspecialchars($item['product_name']) ?></td>
                            <td class="px-3 py-2">
                                <span class="text-xs px-2 py-0.5 rounded <?= $item['product_type'] === 'digital' ? 'bg-blue-100 text-blue-700' : 'bg-orange-100 text-orange-700' ?>">
                                    <?= $item['product_type'] === 'digital' ? 'デジタル' : '物販' ?>
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right">¥<?= number_format($item['price']) ?></td>
                            <td class="px-3 py-2 text-right"><?= $item['quantity'] ?></td>
                            <td class="px-3 py-2 text-right font-bold">¥<?= number_format($item['subtotal']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot class="border-t-2">
                        <tr><td colspan="4" class="px-3 py-2 text-right">小計</td><td class="px-3 py-2 text-right">¥<?= number_format($orderDetail['subtotal']) ?></td></tr>
                        <?php if ($orderDetail['shipping_fee'] > 0): ?>
                        <tr><td colspan="4" class="px-3 py-2 text-right">送料</td><td class="px-3 py-2 text-right">¥<?= number_format($orderDetail['shipping_fee']) ?></td></tr>
                        <?php endif; ?>
                        <tr><td colspan="4" class="px-3 py-2 text-right font-bold">合計</td><td class="px-3 py-2 text-right font-bold text-lg text-green-600">¥<?= number_format($orderDetail['total']) ?></td></tr>
                    </tfoot>
                </table>
                
                <!-- ステータス更新 -->
                <form method="POST" class="bg-gray-50 rounded-lg p-4 mb-4">
                    <input type="hidden" name="order_id" value="<?= $orderDetail['id'] ?>">
                    
                    <?php if ($orderDetail['has_physical_items']): ?>
                    <!-- 配送情報入力エリア -->
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
                        <h4 class="font-bold text-blue-800 mb-3">
                            <i class="fas fa-shipping-fast mr-2"></i>配送情報
                            <?php if ($orderDetail['order_status'] === 'shipped'): ?>
                            <span class="text-xs bg-green-100 text-green-700 px-2 py-1 rounded ml-2">発送済み</span>
                            <?php endif; ?>
                        </h4>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-1">配送業者 <span class="text-red-500">*</span></label>
                                <select name="shipping_carrier" class="w-full px-3 py-2 border border-gray-300 rounded-lg" id="shippingCarrier">
                                    <option value="">選択してください</option>
                                    <option value="yamato" <?= ($orderDetail['shipping_carrier'] ?? '') === 'yamato' ? 'selected' : '' ?>>ヤマト運輸</option>
                                    <option value="sagawa" <?= ($orderDetail['shipping_carrier'] ?? '') === 'sagawa' ? 'selected' : '' ?>>佐川急便</option>
                                    <option value="japanpost" <?= ($orderDetail['shipping_carrier'] ?? '') === 'japanpost' ? 'selected' : '' ?>>日本郵便</option>
                                    <option value="japanpost_yu" <?= ($orderDetail['shipping_carrier'] ?? '') === 'japanpost_yu' ? 'selected' : '' ?>>ゆうパック</option>
                                    <option value="clickpost" <?= ($orderDetail['shipping_carrier'] ?? '') === 'clickpost' ? 'selected' : '' ?>>クリックポスト</option>
                                    <option value="nekopos" <?= ($orderDetail['shipping_carrier'] ?? '') === 'nekopos' ? 'selected' : '' ?>>ネコポス</option>
                                    <option value="yupacket" <?= ($orderDetail['shipping_carrier'] ?? '') === 'yupacket' ? 'selected' : '' ?>>ゆうパケット</option>
                                    <option value="other" <?= ($orderDetail['shipping_carrier'] ?? '') === 'other' ? 'selected' : '' ?>>その他</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-1">追跡番号 <span class="text-red-500">*</span></label>
                                <input type="text" name="tracking_number" id="trackingNumber" 
                                       value="<?= htmlspecialchars($orderDetail['tracking_number'] ?? '') ?>" 
                                       placeholder="例: 1234-5678-9012" 
                                       class="w-full px-3 py-2 border border-gray-300 rounded-lg">
                            </div>
                        </div>
                        <p class="text-xs text-blue-600 mt-2">
                            <i class="fas fa-info-circle mr-1"></i>配送業者と追跡番号を入力して保存すると、自動的に「発送済み」になります
                        </p>
                        
                        <?php if ($orderDetail['shipped_at']): ?>
                        <p class="text-sm text-gray-600 mt-2">
                            <i class="fas fa-clock mr-1"></i>発送日時: <?= date('Y/m/d H:i', strtotime($orderDetail['shipped_at'])) ?>
                        </p>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-1">注文ステータス</label>
                            <select name="order_status" class="w-full px-3 py-2 border border-gray-300 rounded-lg" id="orderStatus">
                                <?php foreach ($statusLabels as $key => $label): ?>
                                <option value="<?= $key ?>" <?= $orderDetail['order_status'] === $key ? 'selected' : '' ?>><?= $label['label'] ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="flex items-end gap-4">
                            <?php if ($orderDetail['has_physical_items']): ?>
                            <label class="flex items-center gap-2 text-sm cursor-pointer whitespace-nowrap">
                                <input type="checkbox" name="send_shipping_mail" value="1" class="rounded text-green-500" id="sendShippingMail">
                                <span>発送通知メール送信</span>
                            </label>
                            <?php endif; ?>
                            <button type="submit" name="update_status" class="flex-1 bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded-lg font-bold">
                                <i class="fas fa-save mr-1"></i>保存
                            </button>
                        </div>
                    </div>
                </form>
                
                <?php if ($orderDetail['has_physical_items']): ?>
                <script>
                // 配送業者と追跡番号が入力されたらステータスを発送済みに変更
                document.addEventListener('DOMContentLoaded', function() {
                    const carrier = document.getElementById('shippingCarrier');
                    const tracking = document.getElementById('trackingNumber');
                    const status = document.getElementById('orderStatus');
                    const mailCheckbox = document.getElementById('sendShippingMail');
                    
                    function checkShippingInfo() {
                        if (carrier && tracking && status) {
                            if (carrier.value && tracking.value.trim()) {
                                // 両方入力されたら発送済みに変更
                                status.value = 'shipped';
                                // メール送信チェックボックスをONに
                                if (mailCheckbox && !mailCheckbox.checked) {
                                    mailCheckbox.checked = true;
                                }
                            }
                        }
                    }
                    
                    if (carrier) carrier.addEventListener('change', checkShippingInfo);
                    if (tracking) tracking.addEventListener('input', checkShippingInfo);
                });
                </script>
                <?php endif; ?>
                
                <!-- 削除ボタン -->
                <div class="border-t pt-4">
                    <form method="POST" onsubmit="return confirm('この注文を完全に削除しますか？\n\n注文番号: <?= htmlspecialchars($orderDetail['order_number']) ?>\n購入者: <?= htmlspecialchars($orderDetail['member_name']) ?>\n合計: ¥<?= number_format($orderDetail['total']) ?>\n\n※本棚からも削除され、購入者は商品を閲覧できなくなります。\n※この操作は取り消せません。');">
                        <input type="hidden" name="order_id" value="<?= $orderDetail['id'] ?>">
                        <button type="submit" name="delete_order" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-lg font-bold">
                            <i class="fas fa-trash mr-1"></i>この注文を完全に削除
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- フィルター -->
            <form method="GET" class="bg-white rounded-xl shadow-sm p-4 mb-6">
                <div class="flex flex-wrap items-center gap-4">
                    <div class="flex-1 min-w-[200px]">
                        <label class="text-xs text-gray-500">検索（注文番号・会員名・メール）</label>
                        <div class="flex">
                            <input type="text" name="q" value="<?= htmlspecialchars($searchQuery) ?>" placeholder="ORD-20250102-..." class="flex-1 px-3 py-2 border border-gray-300 rounded-l-lg text-sm">
                            <button type="submit" class="px-4 py-2 bg-gray-100 border border-l-0 border-gray-300 rounded-r-lg hover:bg-gray-200">
                                <i class="fas fa-search"></i>
                            </button>
                        </div>
                    </div>
                    <div>
                        <label class="text-xs text-gray-500">注文ステータス</label>
                        <select name="status" onchange="this.form.submit()" class="block px-3 py-2 border border-gray-300 rounded-lg text-sm">
                            <option value="">すべて</option>
                            <?php foreach ($statusLabels as $key => $label): ?>
                            <option value="<?= $key ?>" <?= $statusFilter === $key ? 'selected' : '' ?>><?= $label['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="text-xs text-gray-500">支払いステータス</label>
                        <select name="payment" onchange="this.form.submit()" class="block px-3 py-2 border border-gray-300 rounded-lg text-sm">
                            <option value="">すべて</option>
                            <?php foreach ($paymentLabels as $key => $label): ?>
                            <option value="<?= $key ?>" <?= $paymentFilter === $key ? 'selected' : '' ?>><?= $label['label'] ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if ($searchQuery || $statusFilter || $paymentFilter): ?>
                    <a href="orders.php" class="px-3 py-2 text-gray-500 hover:text-gray-700 text-sm">
                        <i class="fas fa-times mr-1"></i>クリア
                    </a>
                    <?php endif; ?>
                </div>
            </form>
            
            <!-- 注文一覧 -->
            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                <!-- PC用テーブル -->
                <div class="hidden lg:block overflow-x-auto">
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">商品</th>
                                <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">注文番号</th>
                                <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">購入者</th>
                                <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">合計</th>
                                <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">支払い</th>
                                <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">ステータス</th>
                                <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">注文日</th>
                                <th class="px-4 py-3 text-center text-sm font-bold text-gray-600">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200">
                            <?php if (empty($orders)): ?>
                            <tr><td colspan="8" class="px-4 py-8 text-center text-gray-500">注文がありません</td></tr>
                            <?php else: ?>
                            <?php foreach ($orders as $order): 
                                $orderStatus = $order['order_status'] ?: ($order['payment_status'] === 'paid' ? 'confirmed' : 'pending');
                                $status = $statusLabels[$orderStatus] ?? ['label' => $orderStatus ?: '未設定', 'color' => 'gray'];
                                $payment = $paymentLabels[$order['payment_status']] ?? ['label' => $order['payment_status'], 'color' => 'gray'];
                            ?>
                            <tr class="hover:bg-gray-50">
                                <!-- 商品画像 -->
                                <td class="px-4 py-3">
                                    <div class="relative w-16 h-12">
                                        <?php if (!empty($order['items'])): ?>
                                            <?php foreach (array_slice($order['items'], 0, 3) as $idx => $item): ?>
                                            <div class="absolute w-10 h-10 rounded bg-gray-100 border border-gray-200 overflow-hidden"
                                                 style="left: <?= $idx * 8 ?>px; z-index: <?= 10 - $idx ?>;">
                                                <?php if ($item['image']): ?>
                                                <img src="/<?= htmlspecialchars(ltrim($item['image'], '/')) ?>" 
                                                     alt="" class="w-full h-full object-cover">
                                                <?php else: ?>
                                                <div class="w-full h-full flex items-center justify-center text-gray-300 text-xs">
                                                    <i class="fas fa-image"></i>
                                                </div>
                                                <?php endif; ?>
                                            </div>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                        <div class="w-10 h-10 rounded bg-gray-100 flex items-center justify-center text-gray-300">
                                            <i class="fas fa-shopping-bag"></i>
                                        </div>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="font-mono text-sm"><?= htmlspecialchars($order['order_number']) ?></p>
                                    <p class="text-xs text-gray-500 truncate max-w-32">
                                        <?php if (!empty($order['items'])): ?>
                                            <?= htmlspecialchars($order['items'][0]['product_name'] ?? '') ?>
                                            <?php if ($order['item_count'] > 1): ?>他<?= $order['item_count'] - 1 ?>点<?php endif; ?>
                                        <?php endif; ?>
                                    </p>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="font-bold text-sm"><?= htmlspecialchars($order['member_name']) ?></p>
                                    <p class="text-xs text-gray-500"><?= htmlspecialchars($order['member_email']) ?></p>
                                </td>
                                <td class="px-4 py-3 font-bold">¥<?= number_format($order['total']) ?></td>
                                <td class="px-4 py-3">
                                    <span class="text-xs px-2 py-1 rounded font-bold
                                        <?= $payment['color'] === 'green' ? 'bg-green-100 text-green-700' : 
                                           ($payment['color'] === 'red' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-700') ?>">
                                        <?= $payment['label'] ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="text-xs px-2 py-1 rounded font-bold
                                        <?= $status['color'] === 'green' ? 'bg-green-100 text-green-700' : 
                                           ($status['color'] === 'blue' ? 'bg-blue-100 text-blue-700' : 
                                           ($status['color'] === 'yellow' ? 'bg-yellow-100 text-yellow-700' :
                                           ($status['color'] === 'red' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-700'))) ?>">
                                        <?= $status['label'] ?>
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500"><?= date('Y/m/d H:i', strtotime($order['created_at'])) ?></td>
                                <td class="px-4 py-3 text-center">
                                    <a href="?view=<?= $order['id'] ?>" class="text-blue-500 hover:text-blue-700">
                                        <i class="fas fa-eye"></i> 詳細
                                    </a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- モバイル用カード -->
                <div class="lg:hidden divide-y divide-gray-200">
                    <?php if (empty($orders)): ?>
                    <div class="px-4 py-8 text-center text-gray-500">注文がありません</div>
                    <?php else: ?>
                    <?php foreach ($orders as $order): 
                        $orderStatus = $order['order_status'] ?: ($order['payment_status'] === 'paid' ? 'confirmed' : 'pending');
                        $status = $statusLabels[$orderStatus] ?? ['label' => $orderStatus ?: '未設定', 'color' => 'gray'];
                        $payment = $paymentLabels[$order['payment_status']] ?? ['label' => $order['payment_status'], 'color' => 'gray'];
                    ?>
                    <a href="?view=<?= $order['id'] ?>" class="block p-4 hover:bg-gray-50">
                        <div class="flex justify-between items-start mb-2">
                            <span class="font-mono text-xs text-gray-500"><?= htmlspecialchars($order['order_number']) ?></span>
                            <span class="text-xs text-gray-400"><?= date('m/d H:i', strtotime($order['created_at'])) ?></span>
                        </div>
                        <div class="flex justify-between items-center mb-2">
                            <span class="font-bold"><?= htmlspecialchars($order['member_name']) ?></span>
                            <span class="font-bold text-lg">¥<?= number_format($order['total']) ?></span>
                        </div>
                        <div class="flex items-center gap-2">
                            <span class="text-xs px-2 py-1 rounded font-bold
                                <?= $payment['color'] === 'green' ? 'bg-green-100 text-green-700' : 
                                   ($payment['color'] === 'red' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-700') ?>">
                                <?= $payment['label'] ?>
                            </span>
                            <span class="text-xs px-2 py-1 rounded font-bold
                                <?= $status['color'] === 'green' ? 'bg-green-100 text-green-700' : 
                                   ($status['color'] === 'blue' ? 'bg-blue-100 text-blue-700' : 
                                   ($status['color'] === 'yellow' ? 'bg-yellow-100 text-yellow-700' :
                                   ($status['color'] === 'red' ? 'bg-red-100 text-red-700' : 'bg-gray-100 text-gray-700'))) ?>">
                                <?= $status['label'] ?>
                            </span>
                            <span class="text-xs text-gray-500"><?= $order['item_count'] ?>点</span>
                        </div>
                    </a>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>

<?php include "includes/footer.php"; ?>
