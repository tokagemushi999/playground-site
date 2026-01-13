<?php
/**
 * チェックアウト（購入手続き）ページ
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/member-auth.php';
require_once '../includes/cart.php';
require_once '../includes/site-settings.php';
require_once '../includes/stripe-config.php';

// ログイン必須
requireMemberAuth();

$db = getDB();
$member = getCurrentMember();
$cartItems = getCartItems();
$subtotal = getCartSubtotal();
$hasPhysical = cartHasPhysicalItems();

// カートが空なら戻す
if (empty($cartItems)) {
    header('Location: /store/cart.php');
    exit;
}

// バリデーション
$validation = validateCart();
if (!$validation['valid']) {
    $_SESSION['cart_error'] = implode('<br>', $validation['errors']);
    header('Location: /store/cart.php');
    exit;
}

$error = '';
$shippingFee = 0;

// 配送先住所を取得
$addresses = [];
if ($hasPhysical) {
    $stmt = $db->prepare("SELECT * FROM member_addresses WHERE member_id = ? ORDER BY is_default DESC, id DESC");
    $stmt->execute([$member['id']]);
    $addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 送料計算
$selectedAddressId = $_POST['address_id'] ?? ($_SESSION['selected_address_id'] ?? null);
if ($hasPhysical && $selectedAddressId) {
    $stmt = $db->prepare("SELECT prefecture FROM member_addresses WHERE id = ? AND member_id = ?");
    $stmt->execute([$selectedAddressId, $member['id']]);
    $addr = $stmt->fetch();
    if ($addr) {
        $shippingFee = calculateShippingFee($addr['prefecture'], $subtotal, $db, $cartItems);
    }
} elseif ($hasPhysical) {
    // 住所未選択でも送料を表示（仮計算）
    $shippingFee = calculateShippingFee('', $subtotal, $db, $cartItems);
}

$total = $subtotal + $shippingFee;

// 決済処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['process_payment'])) {
    try {
        // 配送先チェック
        if ($hasPhysical && !$selectedAddressId) {
            throw new Exception('配送先を選択してください');
        }
        
        // Stripeの初期化
        initStripe();
        
        // Stripeが正しく初期化されたか確認
        if (!class_exists('\Stripe\Stripe')) {
            throw new Exception('Stripeライブラリが見つかりません。stripe-phpフォルダを確認してください。');
        }
        
        // 注文番号生成
        $orderNumber = generateOrderNumber();
        
        // 配送先情報取得
        $shippingAddress = null;
        if ($hasPhysical && $selectedAddressId) {
            $stmt = $db->prepare("SELECT * FROM member_addresses WHERE id = ? AND member_id = ?");
            $stmt->execute([$selectedAddressId, $member['id']]);
            $shippingAddress = $stmt->fetch(PDO::FETCH_ASSOC);
        }
        
        // Stripe Checkout Session作成
        $lineItems = [];
        foreach ($cartItems as $item) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => STRIPE_CURRENCY,
                    'product_data' => [
                        'name' => $item['name'],
                        'images' => $item['image'] ? ['https://' . $_SERVER['HTTP_HOST'] . '/' . $item['image']] : [],
                    ],
                    'unit_amount' => $item['price'],
                ],
                'quantity' => $item['quantity'],
            ];
        }
        
        // 送料を追加
        if ($shippingFee > 0) {
            $lineItems[] = [
                'price_data' => [
                    'currency' => STRIPE_CURRENCY,
                    'product_data' => [
                        'name' => '送料',
                    ],
                    'unit_amount' => $shippingFee,
                ],
                'quantity' => 1,
            ];
        }
        
        // 注文を先に作成（pending状態）
        $stmt = $db->prepare("
            INSERT INTO orders (
                order_number, member_id, subtotal, shipping_fee, tax, total,
                payment_method, payment_status, order_status,
                has_physical_items, shipping_name, shipping_postal_code, shipping_prefecture,
                shipping_city, shipping_address1, shipping_address2, shipping_phone
            ) VALUES (?, ?, ?, ?, 0, ?, 'card', 'pending', 'pending', ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $orderNumber,
            $member['id'],
            $subtotal,
            $shippingFee,
            $total,
            $hasPhysical ? 1 : 0,
            $shippingAddress['name'] ?? null,
            $shippingAddress['postal_code'] ?? null,
            $shippingAddress['prefecture'] ?? null,
            $shippingAddress['city'] ?? null,
            $shippingAddress['address1'] ?? null,
            $shippingAddress['address2'] ?? null,
            $shippingAddress['phone'] ?? null,
        ]);
        $orderId = $db->lastInsertId();
        
        // 注文明細を作成
        foreach ($cartItems as $item) {
            $stmt = $db->prepare("
                INSERT INTO order_items (order_id, product_id, product_name, product_type, price, quantity, subtotal)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $orderId,
                $item['product_id'],
                $item['name'],
                $item['product_type'],
                $item['price'],
                $item['quantity'],
                $item['price'] * $item['quantity']
            ]);
        }
        
        // Checkout Session作成
        $checkoutSession = \Stripe\Checkout\Session::create([
            'payment_method_types' => ['card'],
            'line_items' => $lineItems,
            'mode' => 'payment',
            'success_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/store/complete.php?order_id=' . $orderId . '&session_id={CHECKOUT_SESSION_ID}',
            'cancel_url' => 'https://' . $_SERVER['HTTP_HOST'] . '/store/checkout.php?cancelled=1',
            'customer_email' => $member['email'],
            'metadata' => [
                'order_id' => $orderId,
                'order_number' => $orderNumber,
            ],
            'locale' => 'ja',
        ]);
        
        // Payment Intent IDを保存
        $stmt = $db->prepare("UPDATE orders SET stripe_payment_intent_id = ? WHERE id = ?");
        $stmt->execute([$checkoutSession->payment_intent, $orderId]);
        
        // セッションに注文IDを保存
        $_SESSION['pending_order_id'] = $orderId;
        
        // Stripe Checkoutにリダイレクト
        header('Location: ' . $checkoutSession->url);
        exit;
        
    } catch (\Stripe\Exception\CardException $e) {
        // カードエラー
        $error = translateStripeError($e->getError()->code ?? '', $e->getMessage());
        error_log("Stripe CardException: " . $e->getMessage() . " | Code: " . ($e->getError()->code ?? 'none'));
    } catch (\Stripe\Exception\InvalidRequestException $e) {
        // 無効なリクエスト - 詳細を表示
        $errorCode = $e->getError()->code ?? 'none';
        $errorMsg = $e->getMessage();
        $error = translateStripeError($errorCode, $errorMsg);
        // デバッグ用：実際のエラーメッセージを追加
        if (strpos($errorMsg, 'No such') !== false || strpos($errorMsg, 'Invalid') !== false) {
            $error .= ' (詳細: ' . htmlspecialchars(substr($errorMsg, 0, 100)) . ')';
        }
        error_log("Stripe InvalidRequestException: " . $errorMsg . " | Code: " . $errorCode);
    } catch (\Stripe\Exception\ApiConnectionException $e) {
        $error = '決済サーバーに接続できませんでした。しばらく経ってから再度お試しください。';
        error_log("Stripe ApiConnectionException: " . $e->getMessage());
    } catch (\Stripe\Exception\ApiErrorException $e) {
        $errorCode = $e->getError()->code ?? 'none';
        $errorMsg = $e->getMessage();
        $error = translateStripeError($errorCode, $errorMsg);
        error_log("Stripe ApiErrorException: " . $errorMsg . " | Code: " . $errorCode);
    } catch (Exception $e) {
        $error = $e->getMessage();
        error_log("Stripe General Exception: " . $e->getMessage() . " | File: " . $e->getFile() . " | Line: " . $e->getLine());
    }
}

/**
 * Stripeエラーを日本語に翻訳
 */
function translateStripeError($code, $originalMessage) {
    $translations = [
        'card_declined' => 'カードが拒否されました。別のカードをお試しください。',
        'expired_card' => 'カードの有効期限が切れています。',
        'incorrect_cvc' => 'セキュリティコード（CVC）が正しくありません。',
        'incorrect_number' => 'カード番号が正しくありません。',
        'insufficient_funds' => '残高が不足しています。',
        'invalid_cvc' => 'セキュリティコード（CVC）が無効です。',
        'invalid_expiry_month' => '有効期限の月が無効です。',
        'invalid_expiry_year' => '有効期限の年が無効です。',
        'invalid_number' => 'カード番号が無効です。',
        'processing_error' => '処理中にエラーが発生しました。再度お試しください。',
        'rate_limit' => 'リクエストが多すぎます。しばらく経ってから再度お試しください。',
        'account_country_invalid_address' => 'お住まいの国では現在ご利用いただけません。',
    ];
    
    // "Your account cannot currently make live charges" のような特定のメッセージをチェック
    if (strpos($originalMessage, 'cannot currently make live charges') !== false) {
        return '決済システムの設定が完了していません。管理者にお問い合わせください。';
    }
    
    return $translations[$code] ?? '決済処理中にエラーが発生しました。別のカードをお試しいただくか、しばらく経ってから再度お試しください。';
}

$settings = getSiteSettings();
$siteName = $settings['site_name'] ?? 'ぷれぐら！PLAYGROUND';
$cartCount = getCartCount();
$pageTitle = '購入手続き';
include 'includes/header.php';
?>

<!-- ステップ表示 -->
<div class="flex items-center justify-center mb-6 sm:mb-8">
    <div class="flex items-center">
        <div class="w-8 h-8 rounded-full bg-gray-800 text-white flex items-center justify-center text-sm font-bold">1</div>
        <span class="ml-2 text-sm font-bold text-gray-800 hidden sm:inline">カート</span>
    </div>
    <div class="w-8 sm:w-12 h-0.5 bg-gray-800 mx-2"></div>
    <div class="flex items-center">
        <div class="w-8 h-8 rounded-full bg-store-primary text-white flex items-center justify-center text-sm font-bold ring-4 ring-store-primary/30">2</div>
        <span class="ml-2 text-sm font-bold text-gray-800 hidden sm:inline">購入手続き</span>
    </div>
    <div class="w-8 sm:w-12 h-0.5 bg-gray-300 mx-2"></div>
    <div class="flex items-center">
        <div class="w-8 h-8 rounded-full bg-gray-300 text-gray-500 flex items-center justify-center text-sm font-bold">3</div>
        <span class="ml-2 text-sm text-gray-500 hidden sm:inline">完了</span>
    </div>
</div>

<h1 class="text-xl sm:text-2xl font-bold text-gray-800 mb-6 sm:mb-8 flex items-center gap-2">
    <i class="fas fa-credit-card text-store-primary"></i>購入手続き
</h1>

<?php if ($error): ?>
<div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-xl mb-6">
    <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<?php if (isset($_GET['cancelled'])): ?>
<div class="bg-yellow-100 border border-yellow-300 text-yellow-700 px-4 py-3 rounded-lg mb-6">
    <i class="fas fa-info-circle mr-2"></i>決済がキャンセルされました
</div>
<?php endif; ?>

<form method="POST">
    <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
        <div class="lg:col-span-2 space-y-6">
            <!-- 購入者情報 -->
            <div class="bg-white rounded-xl shadow-sm p-6">
                <h2 class="text-lg font-bold text-gray-800 mb-4">
                    <i class="fas fa-user mr-2 text-store-primary"></i>購入者情報
                </h2>
                <div class="space-y-2 text-gray-600">
                    <p><strong>お名前:</strong> <?= htmlspecialchars($member['name']) ?></p>
                    <p><strong>メールアドレス:</strong> <?= htmlspecialchars($member['email']) ?></p>
                </div>
            </div>
            
            <?php if ($hasPhysical): ?>
            <!-- 配送先住所 -->
            <div class="bg-white rounded-xl shadow-sm p-6">
                <h2 class="text-lg font-bold text-gray-800 mb-4">
                    <i class="fas fa-truck mr-2 text-store-primary"></i>配送先
                </h2>
                
                <?php if (empty($addresses)): ?>
                <div class="text-center py-6">
                    <p class="text-gray-500 mb-4">配送先住所が登録されていません</p>
                    <a href="/store/address.php?redirect=checkout" class="inline-block px-6 py-2 bg-store-primary text-white rounded-lg font-bold hover:bg-orange-600 transition-colors">
                        <i class="fas fa-plus mr-1"></i>住所を追加
                    </a>
                </div>
                <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($addresses as $addr): ?>
                    <label class="block border rounded-lg p-4 cursor-pointer hover:border-store-primary transition-colors <?= $selectedAddressId == $addr['id'] ? 'border-store-primary bg-orange-50' : 'border-gray-200' ?>">
                        <div class="flex items-start gap-3">
                            <input type="radio" name="address_id" value="<?= $addr['id'] ?>" 
                                <?= $selectedAddressId == $addr['id'] ? 'checked' : '' ?>
                                onchange="this.form.submit()"
                                class="mt-1 accent-store-primary">
                            <div class="flex-1">
                                <p class="font-bold"><?= htmlspecialchars($addr['name']) ?></p>
                                <p class="text-sm text-gray-600">
                                    〒<?= htmlspecialchars($addr['postal_code']) ?><br>
                                    <?= htmlspecialchars($addr['prefecture'] . $addr['city'] . $addr['address1']) ?>
                                    <?= $addr['address2'] ? '<br>' . htmlspecialchars($addr['address2']) : '' ?>
                                </p>
                                <p class="text-sm text-gray-500 mt-1">TEL: <?= htmlspecialchars($addr['phone']) ?></p>
                            </div>
                            <?php if ($addr['is_default']): ?>
                            <span class="text-xs bg-gray-100 text-gray-600 px-2 py-1 rounded">デフォルト</span>
                            <?php endif; ?>
                        </div>
                    </label>
                    <?php endforeach; ?>
                </div>
                <a href="/store/address.php?redirect=checkout" class="block text-center text-sm text-store-primary hover:underline mt-4">
                    <i class="fas fa-plus mr-1"></i>新しい住所を追加
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            
            <!-- 注文内容 -->
            <div class="bg-white rounded-xl shadow-sm p-6">
                <h2 class="text-lg font-bold text-gray-800 mb-4">
                    <i class="fas fa-shopping-bag mr-2 text-store-primary"></i>注文内容
                </h2>
                <div class="space-y-3">
                    <?php foreach ($cartItems as $item): ?>
                    <div class="flex items-center gap-3 py-2 border-b border-gray-100 last:border-0">
                        <?php if ($item['image']): ?>
                        <img src="/<?= htmlspecialchars($item['image']) ?>" alt="" class="w-12 h-12 object-cover rounded">
                        <?php else: ?>
                        <div class="w-12 h-12 bg-gray-100 rounded flex items-center justify-center">
                            <i class="fas fa-image text-gray-300"></i>
                        </div>
                        <?php endif; ?>
                        <div class="flex-1 min-w-0">
                            <p class="font-bold text-gray-800 truncate"><?= htmlspecialchars($item['name']) ?></p>
                            <p class="text-sm text-gray-500">数量: <?= $item['quantity'] ?></p>
                        </div>
                        <p class="font-bold">¥<?= number_format($item['price'] * $item['quantity']) ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- 注文サマリー -->
        <div class="lg:col-span-1">
            <div class="bg-white rounded-xl shadow-sm p-6 sticky top-24">
                <h2 class="text-lg font-bold text-gray-800 mb-4">ご注文内容</h2>
                
                <div class="space-y-3 mb-4">
                    <div class="flex justify-between text-gray-600">
                        <span>小計</span>
                        <span>¥<?= number_format($subtotal) ?></span>
                    </div>
                    
                    <?php if ($hasPhysical): ?>
                    <div class="flex justify-between text-gray-600">
                        <span>送料</span>
                        <span><?= $shippingFee > 0 ? '¥' . number_format($shippingFee) : ($selectedAddressId ? '¥0' : '未計算') ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="border-t border-gray-200 pt-4 mb-6">
                    <div class="flex justify-between font-bold text-lg">
                        <span>合計</span>
                        <span class="text-store-primary">¥<?= number_format($total) ?></span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">税込</p>
                </div>
                
                <?php if (!$hasPhysical || ($hasPhysical && $selectedAddressId)): ?>
                <button type="submit" name="process_payment" class="w-full bg-store-secondary hover:brightness-110 text-white py-4 px-6 rounded-xl font-bold text-base transition-all shadow-md hover:shadow-lg flex items-center justify-center gap-2">
                    <i class="fas fa-credit-card"></i>
                    クレジットカードで支払う
                </button>
                <?php else: ?>
                <button type="button" disabled class="w-full bg-gray-300 text-gray-500 py-4 rounded-xl font-bold text-base cursor-not-allowed">
                    配送先を選択してください
                </button>
                <?php endif; ?>
                
                <p class="text-xs text-gray-500 text-center mt-4">
                    <i class="fas fa-lock mr-1"></i>
                    Stripeによる安全な決済
                </p>
                
                <a href="/store/cart.php" class="block text-center text-sm text-store-primary hover:underline mt-4">
                    <i class="fas fa-arrow-left mr-1"></i>カートに戻る
                </a>
            </div>
        </div>
    </div>
</form>

<?php include 'includes/footer.php'; ?>
