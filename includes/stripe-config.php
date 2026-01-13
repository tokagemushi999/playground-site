<?php
/**
 * Stripe設定ファイル
 * 
 * 設定は管理画面のストア設定から行います。
 * 
 * テスト用カード番号:
 * - 成功: 4242 4242 4242 4242
 * - 失敗: 4000 0000 0000 0002
 */

require_once __DIR__ . '/formatting.php';
require_once __DIR__ . '/site-settings.php';

// ============================================
// DBから設定を読み込む
// ============================================
function getStripeSettingFromDB($key, $default = '') {
    static $settings = null;
    
    if ($settings === null) {
        try {
            require_once __DIR__ . '/db.php';
            $db = getDB();
            $stmt = $db->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'stripe_%'");
            $settings = [];
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
        } catch (Exception $e) {
            $settings = [];
        }
    }
    
    return $settings[$key] ?? $default;
}

// 本番環境かどうか
$stripeLiveMode = getStripeSettingFromDB('stripe_live_mode', '0') === '1';

// テスト環境用キー
$stripeTestPublishableKey = getStripeSettingFromDB('stripe_test_publishable_key', '');
$stripeTestSecretKey = getStripeSettingFromDB('stripe_test_secret_key', '');
$stripeTestWebhookSecret = getStripeSettingFromDB('stripe_test_webhook_secret', '');

// 本番環境用キー
$stripeLivePublishableKey = getStripeSettingFromDB('stripe_live_publishable_key', '');
$stripeLiveSecretKey = getStripeSettingFromDB('stripe_live_secret_key', '');
$stripeLiveWebhookSecret = getStripeSettingFromDB('stripe_live_webhook_secret', '');

// 使用するキーを決定
if ($stripeLiveMode) {
    define('STRIPE_PUBLISHABLE_KEY', $stripeLivePublishableKey);
    define('STRIPE_SECRET_KEY', $stripeLiveSecretKey);
    define('STRIPE_WEBHOOK_SECRET', $stripeLiveWebhookSecret);
    define('STRIPE_LIVE_MODE', true);
} else {
    define('STRIPE_PUBLISHABLE_KEY', $stripeTestPublishableKey);
    define('STRIPE_SECRET_KEY', $stripeTestSecretKey);
    define('STRIPE_WEBHOOK_SECRET', $stripeTestWebhookSecret);
    define('STRIPE_LIVE_MODE', false);
}

// ============================================
// その他の設定
// ============================================

// 通貨
define('STRIPE_CURRENCY', 'jpy');

// ============================================
// Stripeライブラリの初期化
// ============================================
function initStripe() {
    // 既に読み込み済みの場合はスキップ
    if (class_exists('\Stripe\Stripe')) {
        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
        return;
    }
    
    // Composerでインストールした場合
    if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
        require_once __DIR__ . '/../vendor/autoload.php';
    }
    // 手動インストールの場合（stripe-phpフォルダ）
    elseif (file_exists(__DIR__ . '/stripe-php/init.php')) {
        require_once __DIR__ . '/stripe-php/init.php';
    }
    // 別の場所にある場合
    elseif (file_exists(__DIR__ . '/../stripe-php/init.php')) {
        require_once __DIR__ . '/../stripe-php/init.php';
    }
    
    if (class_exists('\Stripe\Stripe')) {
        \Stripe\Stripe::setApiKey(STRIPE_SECRET_KEY);
    }
}

// ============================================
// 便利関数
// ============================================

/**
 * 注文番号を生成
 */
function generateOrderNumber() {
    return 'ORD-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
}

/**
 * 都道府県から地域を判定
 */
function getPrefectureRegion($prefecture) {
    $regions = [
        'hokkaido' => ['北海道'],
        'tohoku' => ['青森県', '岩手県', '宮城県', '秋田県', '山形県', '福島県'],
        'kanto' => ['茨城県', '栃木県', '群馬県', '埼玉県', '千葉県', '東京都', '神奈川県', '山梨県'],
        'shinetsu' => ['新潟県', '長野県'],
        'hokuriku' => ['富山県', '石川県', '福井県'],
        'tokai' => ['岐阜県', '静岡県', '愛知県', '三重県'],
        'kinki' => ['滋賀県', '京都府', '大阪府', '兵庫県', '奈良県', '和歌山県'],
        'chugoku' => ['鳥取県', '島根県', '岡山県', '広島県', '山口県'],
        'shikoku' => ['徳島県', '香川県', '愛媛県', '高知県'],
        'kyushu' => ['福岡県', '佐賀県', '長崎県', '熊本県', '大分県', '宮崎県', '鹿児島県'],
        'okinawa' => ['沖縄県']
    ];
    
    foreach ($regions as $region => $prefs) {
        if (in_array($prefecture, $prefs)) {
            return $region;
        }
    }
    return 'kanto'; // デフォルト
}

/**
 * 送料を計算（新ロジック）
 * - 商品ごとの送料設定に対応
 * - 送料無料閾値に対応
 */
function calculateShippingFee($prefecture, $subtotal, $db, $cartItems = []) {
    // 送料設定を取得
    $defaultFee = (int)getSiteSetting($db, 'shipping_default_fee', '600');
    $freeEnabled = getSiteSetting($db, 'shipping_free_enabled', '0') === '1';
    $freeThreshold = (int)getSiteSetting($db, 'shipping_free_threshold', '0');
    
    // 送料無料条件を満たす場合
    if ($freeEnabled && $freeThreshold > 0 && $subtotal >= $freeThreshold) {
        return 0;
    }
    
    // カートアイテムが渡されている場合、商品ごとの送料を計算
    if (!empty($cartItems)) {
        $totalShipping = 0;
        $hasPhysicalWithoutFreeShipping = false;
        
        foreach ($cartItems as $item) {
            // デジタル商品は送料不要
            if (($item['product_type'] ?? 'digital') !== 'physical') {
                continue;
            }
            
            // 送料無料フラグが立っている商品
            if (!empty($item['is_free_shipping'])) {
                continue;
            }
            
            $hasPhysicalWithoutFreeShipping = true;
            
            // 商品に個別送料が設定されている場合
            if (isset($item['shipping_fee']) && $item['shipping_fee'] !== null && $item['shipping_fee'] !== '') {
                $totalShipping += (int)$item['shipping_fee'] * ($item['quantity'] ?? 1);
            } else {
                // 基本送料を適用（1回のみ）
                $totalShipping = $defaultFee;
                break; // 基本送料は1回だけ
            }
        }
        
        // 物販商品があるが全て送料無料の場合
        if (!$hasPhysicalWithoutFreeShipping) {
            return 0;
        }
        
        return $totalShipping;
    }
    
    // カートアイテムがない場合は基本送料を返す
    return $defaultFee;
}
