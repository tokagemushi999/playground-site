<?php
/**
 * カート機能ヘルパー
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/member-auth.php';

/**
 * カートIDを取得（なければ作成）
 */
function getCartId() {
    $db = getDB();
    
    // ログイン中の場合
    if (isLoggedIn()) {
        $memberId = $_SESSION['member_id'];
        $stmt = $db->prepare("SELECT id FROM carts WHERE member_id = ?");
        $stmt->execute([$memberId]);
        $cart = $stmt->fetch();
        
        if ($cart) {
            return $cart['id'];
        }
        
        $stmt = $db->prepare("INSERT INTO carts (member_id) VALUES (?)");
        $stmt->execute([$memberId]);
        return $db->lastInsertId();
    }
    
    // ゲストの場合
    if (isset($_SESSION['guest_cart_id'])) {
        // カートが存在するか確認
        $stmt = $db->prepare("SELECT id FROM carts WHERE id = ?");
        $stmt->execute([$_SESSION['guest_cart_id']]);
        if ($stmt->fetch()) {
            return $_SESSION['guest_cart_id'];
        }
    }
    
    // 新規カート作成
    $sessionId = session_id();
    $stmt = $db->prepare("INSERT INTO carts (session_id) VALUES (?)");
    $stmt->execute([$sessionId]);
    $cartId = $db->lastInsertId();
    $_SESSION['guest_cart_id'] = $cartId;
    
    return $cartId;
}

/**
 * カートに商品を追加
 */
function addToCart($productId, $quantity = 1) {
    $db = getDB();
    $cartId = getCartId();
    
    // 商品の存在確認
    $stmt = $db->prepare("SELECT * FROM products WHERE id = ? AND is_published = 1");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        return ['success' => false, 'error' => '商品が見つかりません'];
    }
    
    // 在庫チェック（物販の場合）
    if ($product['product_type'] === 'physical' && $product['stock_quantity'] !== null) {
        // 現在のカート内数量を取得
        $stmt = $db->prepare("SELECT quantity FROM cart_items WHERE cart_id = ? AND product_id = ?");
        $stmt->execute([$cartId, $productId]);
        $existing = $stmt->fetch();
        $currentQty = $existing ? $existing['quantity'] : 0;
        
        if ($currentQty + $quantity > $product['stock_quantity']) {
            return ['success' => false, 'error' => '在庫が不足しています'];
        }
    }
    
    // デジタル商品は1つまで
    if ($product['product_type'] === 'digital') {
        $quantity = 1;
    }
    
    // カートに追加（既存なら数量を更新）
    $stmt = $db->prepare("
        INSERT INTO cart_items (cart_id, product_id, quantity)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE quantity = quantity + VALUES(quantity)
    ");
    $stmt->execute([$cartId, $productId, $quantity]);
    
    return ['success' => true, 'cart_count' => getCartCount()];
}

/**
 * カートから商品を削除
 */
function removeFromCart($productId) {
    $db = getDB();
    $cartId = getCartId();
    
    $stmt = $db->prepare("DELETE FROM cart_items WHERE cart_id = ? AND product_id = ?");
    $stmt->execute([$cartId, $productId]);
    
    return ['success' => true, 'cart_count' => getCartCount()];
}

/**
 * カート内の数量を更新
 */
function updateCartQuantity($productId, $quantity) {
    $db = getDB();
    $cartId = getCartId();
    
    if ($quantity <= 0) {
        return removeFromCart($productId);
    }
    
    // 商品の存在確認
    $stmt = $db->prepare("SELECT * FROM products WHERE id = ? AND is_published = 1");
    $stmt->execute([$productId]);
    $product = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$product) {
        return ['success' => false, 'error' => '商品が見つかりません'];
    }
    
    // 在庫チェック（物販の場合）
    if ($product['product_type'] === 'physical' && $product['stock_quantity'] !== null) {
        if ($quantity > $product['stock_quantity']) {
            return ['success' => false, 'error' => '在庫が不足しています'];
        }
    }
    
    // デジタル商品は1つまで
    if ($product['product_type'] === 'digital') {
        $quantity = 1;
    }
    
    $stmt = $db->prepare("UPDATE cart_items SET quantity = ? WHERE cart_id = ? AND product_id = ?");
    $stmt->execute([$quantity, $cartId, $productId]);
    
    return ['success' => true, 'cart_count' => getCartCount()];
}

/**
 * カート内容を取得
 */
function getCartItems() {
    $db = getDB();
    $cartId = getCartId();
    
    $stmt = $db->prepare("
        SELECT 
            ci.*,
            p.name,
            p.price,
            p.image,
            p.product_type,
            p.stock_quantity,
            p.stock_status,
            p.slug,
            p.shipping_fee,
            p.is_free_shipping
        FROM cart_items ci
        JOIN products p ON ci.product_id = p.id
        WHERE ci.cart_id = ? AND p.is_published = 1
        ORDER BY ci.created_at DESC
    ");
    $stmt->execute([$cartId]);
    
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * カート内の商品数を取得
 */
function getCartCount() {
    $db = getDB();
    $cartId = getCartId();
    
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(quantity), 0) as count 
        FROM cart_items 
        WHERE cart_id = ?
    ");
    $stmt->execute([$cartId]);
    $result = $stmt->fetch();
    
    return (int)$result['count'];
}

/**
 * カート小計を計算
 */
function getCartSubtotal() {
    $items = getCartItems();
    $subtotal = 0;
    
    foreach ($items as $item) {
        $subtotal += $item['price'] * $item['quantity'];
    }
    
    return $subtotal;
}

/**
 * カートに物販商品が含まれているか
 */
function cartHasPhysicalItems() {
    $items = getCartItems();
    
    foreach ($items as $item) {
        if ($item['product_type'] === 'physical') {
            return true;
        }
    }
    
    return false;
}

/**
 * カートをクリア
 */
function clearCart() {
    $db = getDB();
    $cartId = getCartId();
    
    $stmt = $db->prepare("DELETE FROM cart_items WHERE cart_id = ?");
    $stmt->execute([$cartId]);
    
    return true;
}

/**
 * カートの検証（購入前チェック）
 */
function validateCart() {
    $db = getDB();
    $items = getCartItems();
    $errors = [];
    
    foreach ($items as $item) {
        // 在庫チェック
        if ($item['product_type'] === 'physical') {
            if ($item['stock_status'] === 'out_of_stock') {
                $errors[] = "「{$item['name']}」は売り切れです";
            } elseif ($item['stock_quantity'] !== null && $item['quantity'] > $item['stock_quantity']) {
                $errors[] = "「{$item['name']}」の在庫が不足しています（残り{$item['stock_quantity']}点）";
            }
        }
    }
    
    return empty($errors) ? ['valid' => true] : ['valid' => false, 'errors' => $errors];
}
