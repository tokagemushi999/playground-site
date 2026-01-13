<?php
/**
 * ストア設定管理
 * - 特定商取引法に基づく表記
 * - お問い合わせ情報
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/site-settings.php';
requireAuth();

$db = getDB();
$message = '';
$error = '';

// getSiteSettingはsite-settings.phpで定義済み

// 保存処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_settings'])) {
    try {
        $settings = [
            // 特定商取引法
            'store_business_name' => $_POST['store_business_name'] ?? '',
            'store_representative' => $_POST['store_representative'] ?? '',
            'store_address' => $_POST['store_address'] ?? '',
            'store_address_note' => $_POST['store_address_note'] ?? '',
            'store_phone' => $_POST['store_phone'] ?? '',
            'store_phone_note' => $_POST['store_phone_note'] ?? '',
            'store_email' => $_POST['store_email'] ?? '',
            'store_invoice_number' => $_POST['store_invoice_number'] ?? '',
            'store_payment_methods' => $_POST['store_payment_methods'] ?? '',
            'store_payment_timing' => $_POST['store_payment_timing'] ?? '',
            'store_delivery_digital' => $_POST['store_delivery_digital'] ?? '',
            'store_delivery_physical' => $_POST['store_delivery_physical'] ?? '',
            'store_return_digital' => $_POST['store_return_digital'] ?? '',
            'store_return_physical' => $_POST['store_return_physical'] ?? '',
            'store_environment' => $_POST['store_environment'] ?? '',
            
            // 送料設定
            'shipping_default_fee' => $_POST['shipping_default_fee'] ?? '600',
            'shipping_free_threshold' => $_POST['shipping_free_threshold'] ?? '',
            'shipping_free_enabled' => isset($_POST['shipping_free_enabled']) ? '1' : '0',
            
            // お問い合わせ
            'contact_business_hours' => $_POST['contact_business_hours'] ?? '',
            'contact_response_time' => $_POST['contact_response_time'] ?? '',
            
            // OAuth設定
            'oauth_google_enabled' => isset($_POST['oauth_google_enabled']) ? '1' : '0',
            'oauth_google_client_id' => $_POST['oauth_google_client_id'] ?? '',
            'oauth_google_client_secret' => $_POST['oauth_google_client_secret'] ?? '',
            'oauth_line_enabled' => isset($_POST['oauth_line_enabled']) ? '1' : '0',
            'oauth_line_channel_id' => $_POST['oauth_line_channel_id'] ?? '',
            'oauth_line_channel_secret' => $_POST['oauth_line_channel_secret'] ?? '',
            'oauth_amazon_enabled' => isset($_POST['oauth_amazon_enabled']) ? '1' : '0',
            'oauth_amazon_client_id' => $_POST['oauth_amazon_client_id'] ?? '',
            'oauth_amazon_client_secret' => $_POST['oauth_amazon_client_secret'] ?? '',
            
            // Stripe設定
            'stripe_live_mode' => isset($_POST['stripe_live_mode']) ? '1' : '0',
            'stripe_test_publishable_key' => $_POST['stripe_test_publishable_key'] ?? '',
            'stripe_test_secret_key' => $_POST['stripe_test_secret_key'] ?? '',
            'stripe_test_webhook_secret' => $_POST['stripe_test_webhook_secret'] ?? '',
            'stripe_live_publishable_key' => $_POST['stripe_live_publishable_key'] ?? '',
            'stripe_live_secret_key' => $_POST['stripe_live_secret_key'] ?? '',
            'stripe_live_webhook_secret' => $_POST['stripe_live_webhook_secret'] ?? '',
        ];
        
        foreach ($settings as $key => $value) {
            $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
            $stmt->execute([$key, $value, $value]);
        }
        
        $message = '設定を保存しました';
    } catch (Exception $e) {
        $error = '保存に失敗しました: ' . $e->getMessage();
    }
}

// 現在の設定を取得
$settings = [
    'store_business_name' => getSiteSetting($db, 'store_business_name', ''),
    'store_representative' => getSiteSetting($db, 'store_representative', ''),
    'store_address' => getSiteSetting($db, 'store_address', ''),
    'store_address_note' => getSiteSetting($db, 'store_address_note', '請求があった場合は遅滞なく開示いたします'),
    'store_phone' => getSiteSetting($db, 'store_phone', ''),
    'store_phone_note' => getSiteSetting($db, 'store_phone_note', '請求があった場合は遅滞なく開示いたします'),
    'store_email' => getSiteSetting($db, 'store_email', ''),
    'store_invoice_number' => getSiteSetting($db, 'store_invoice_number', ''),
    'store_payment_methods' => getSiteSetting($db, 'store_payment_methods', 'クレジットカード（VISA、Mastercard、American Express、JCB）'),
    'store_payment_timing' => getSiteSetting($db, 'store_payment_timing', 'ご注文時にクレジットカード決済が行われます。'),
    'store_delivery_digital' => getSiteSetting($db, 'store_delivery_digital', '決済完了後、直ちにマイページの「本棚」からご利用いただけます。'),
    'store_delivery_physical' => getSiteSetting($db, 'store_delivery_physical', 'ご注文確認後、通常3〜7営業日以内に発送いたします。'),
    'store_return_digital' => getSiteSetting($db, 'store_return_digital', '商品の性質上、購入後の返品・返金はお受けできません。'),
    'store_return_physical' => getSiteSetting($db, 'store_return_physical', '商品到着後7日以内に限り、未開封・未使用の場合のみ返品を承ります。返品送料はお客様負担となります。不良品の場合は当社負担で交換いたします。'),
    'store_environment' => getSiteSetting($db, 'store_environment', "【デジタル商品の閲覧】\nPC: Chrome、Firefox、Safari、Edge の最新版\nスマートフォン: iOS 14以上、Android 10以上"),
    // 送料設定
    'shipping_default_fee' => getSiteSetting($db, 'shipping_default_fee', '600'),
    'shipping_free_threshold' => getSiteSetting($db, 'shipping_free_threshold', ''),
    'shipping_free_enabled' => getSiteSetting($db, 'shipping_free_enabled', '0'),
    // お問い合わせ
    'contact_business_hours' => getSiteSetting($db, 'contact_business_hours', '平日 10:00〜18:00（土日祝休み）'),
    'contact_response_time' => getSiteSetting($db, 'contact_response_time', '通常2〜3営業日以内にご返信いたします。'),
    // OAuth設定
    'oauth_google_enabled' => getSiteSetting($db, 'oauth_google_enabled', '0'),
    'oauth_google_client_id' => getSiteSetting($db, 'oauth_google_client_id', ''),
    'oauth_google_client_secret' => getSiteSetting($db, 'oauth_google_client_secret', ''),
    'oauth_line_enabled' => getSiteSetting($db, 'oauth_line_enabled', '0'),
    'oauth_line_channel_id' => getSiteSetting($db, 'oauth_line_channel_id', ''),
    'oauth_line_channel_secret' => getSiteSetting($db, 'oauth_line_channel_secret', ''),
    'oauth_amazon_enabled' => getSiteSetting($db, 'oauth_amazon_enabled', '0'),
    'oauth_amazon_client_id' => getSiteSetting($db, 'oauth_amazon_client_id', ''),
    'oauth_amazon_client_secret' => getSiteSetting($db, 'oauth_amazon_client_secret', ''),
    // Stripe設定
    'stripe_live_mode' => getSiteSetting($db, 'stripe_live_mode', '0'),
    'stripe_test_publishable_key' => getSiteSetting($db, 'stripe_test_publishable_key', ''),
    'stripe_test_secret_key' => getSiteSetting($db, 'stripe_test_secret_key', ''),
    'stripe_test_webhook_secret' => getSiteSetting($db, 'stripe_test_webhook_secret', ''),
    'stripe_live_publishable_key' => getSiteSetting($db, 'stripe_live_publishable_key', ''),
    'stripe_live_secret_key' => getSiteSetting($db, 'stripe_live_secret_key', ''),
    'stripe_live_webhook_secret' => getSiteSetting($db, 'stripe_live_webhook_secret', ''),
];

$pwaThemeColor = getSiteSetting($db, 'pwa_theme_color', '#ffffff'); 
$backyardFavicon = getBackyardFaviconInfo($db);

$pageTitle = "ストア設定";
include "includes/header.php";
?>
        <h1 class="text-2xl font-bold text-gray-800 mb-6">
            <i class="fas fa-cog text-green-500 mr-2"></i>ストア設定
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
        
        <form method="POST" class="space-y-8">
            <!-- 送料設定 -->
            <div class="bg-white rounded-xl shadow-sm p-6">
                <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-truck text-blue-500"></i>送料設定
                </h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">基本送料（円）</label>
                        <input type="number" name="shipping_default_fee" min="0" value="<?= htmlspecialchars($settings['shipping_default_fee']) ?>"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                        <p class="text-xs text-gray-500 mt-1">商品に個別送料が設定されていない場合に使用</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">送料無料の条件（円以上）</label>
                        <div class="flex items-center gap-4">
                            <input type="number" name="shipping_free_threshold" min="0" value="<?= htmlspecialchars($settings['shipping_free_threshold']) ?>" placeholder="例: 5000"
                                class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none <?= $settings['shipping_free_enabled'] !== '1' ? 'bg-gray-100' : '' ?>"
                                <?= $settings['shipping_free_enabled'] !== '1' ? 'disabled' : '' ?>>
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer mt-2">
                            <input type="checkbox" name="shipping_free_enabled" value="1" 
                                <?= $settings['shipping_free_enabled'] === '1' ? 'checked' : '' ?> 
                                class="w-5 h-5 rounded text-green-500"
                                onchange="document.querySelector('input[name=shipping_free_threshold]').disabled = !this.checked; document.querySelector('input[name=shipping_free_threshold]').classList.toggle('bg-gray-100', !this.checked);">
                            <span class="text-sm font-bold text-gray-700">〇〇円以上で送料無料を有効にする</span>
                        </label>
                    </div>
                </div>
                
                <div class="mt-4 p-4 bg-blue-50 rounded-lg text-sm text-blue-700">
                    <p><i class="fas fa-info-circle mr-1"></i><strong>送料の計算ルール</strong></p>
                    <ul class="list-disc list-inside mt-2 space-y-1 text-blue-600">
                        <li>商品に「送料無料」が設定されている場合 → その商品は送料がかかりません</li>
                        <li>商品に「個別送料」が設定されている場合 → その金額が送料になります</li>
                        <li>上記以外 → 基本送料が適用されます</li>
                        <li>送料無料の条件を満たす場合 → 全体の送料が無料になります</li>
                    </ul>
                </div>
            </div>
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
        
        <form method="POST" class="space-y-6">
            <!-- 特定商取引法に基づく表記 -->
            <div class="bg-white rounded-xl shadow-sm p-4 sm:p-6">
                <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-file-contract text-blue-500"></i>
                    特定商取引法に基づく表記
                </h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">販売事業者名 <span class="text-red-500">*</span></label>
                        <input type="text" name="store_business_name" value="<?= htmlspecialchars($settings['store_business_name']) ?>" required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">運営統括責任者 <span class="text-red-500">*</span></label>
                        <input type="text" name="store_representative" value="<?= htmlspecialchars($settings['store_representative']) ?>" required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">所在地</label>
                        <input type="text" name="store_address" value="<?= htmlspecialchars($settings['store_address']) ?>"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                        <input type="text" name="store_address_note" value="<?= htmlspecialchars($settings['store_address_note']) ?>" placeholder="注記（任意）"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none mt-2 text-sm">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">電話番号</label>
                        <input type="text" name="store_phone" value="<?= htmlspecialchars($settings['store_phone']) ?>"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                        <input type="text" name="store_phone_note" value="<?= htmlspecialchars($settings['store_phone_note']) ?>" placeholder="注記（任意）"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none mt-2 text-sm">
                    </div>
                    
                    <div class="md:col-span-2">
                        <label class="block text-sm font-bold text-gray-700 mb-2">メールアドレス <span class="text-red-500">*</span></label>
                        <input type="email" name="store_email" value="<?= htmlspecialchars($settings['store_email']) ?>" required
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                    </div>
                    
                    <div class="md:col-span-2">
                        <label class="block text-sm font-bold text-gray-700 mb-2">インボイス登録番号</label>
                        <input type="text" name="store_invoice_number" value="<?= htmlspecialchars($settings['store_invoice_number']) ?>"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                            placeholder="T1234567890123"
                            pattern="T?[0-9]{13}">
                        <p class="text-xs text-gray-500 mt-1">適格請求書発行事業者の場合はT + 13桁の数字を入力。領収書に表示されます。</p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">お支払い方法</label>
                        <textarea name="store_payment_methods" rows="2" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"><?= htmlspecialchars($settings['store_payment_methods']) ?></textarea>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">お支払い時期</label>
                        <textarea name="store_payment_timing" rows="2" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"><?= htmlspecialchars($settings['store_payment_timing']) ?></textarea>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">商品引渡し時期（デジタル）</label>
                        <textarea name="store_delivery_digital" rows="2" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"><?= htmlspecialchars($settings['store_delivery_digital']) ?></textarea>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">商品引渡し時期（物販）</label>
                        <textarea name="store_delivery_physical" rows="2" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"><?= htmlspecialchars($settings['store_delivery_physical']) ?></textarea>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">返品・交換（デジタル）</label>
                        <textarea name="store_return_digital" rows="2" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"><?= htmlspecialchars($settings['store_return_digital']) ?></textarea>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">返品・交換（物販）</label>
                        <textarea name="store_return_physical" rows="2" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"><?= htmlspecialchars($settings['store_return_physical']) ?></textarea>
                    </div>
                    
                    <div class="md:col-span-2">
                        <label class="block text-sm font-bold text-gray-700 mb-2">動作環境</label>
                        <textarea name="store_environment" rows="4" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"><?= htmlspecialchars($settings['store_environment']) ?></textarea>
                    </div>
                </div>
            </div>
            
            <!-- お問い合わせ設定 -->
            <div class="bg-white rounded-xl shadow-sm p-4 sm:p-6">
                <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-envelope text-purple-500"></i>
                    お問い合わせ設定
                </h2>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">営業時間</label>
                        <input type="text" name="contact_business_hours" value="<?= htmlspecialchars($settings['contact_business_hours']) ?>"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">返信目安</label>
                        <input type="text" name="contact_response_time" value="<?= htmlspecialchars($settings['contact_response_time']) ?>"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                    </div>
                </div>
                
                <p class="text-sm text-gray-500 mt-4">
                    <i class="fas fa-info-circle mr-1"></i>
                    お問い合わせ先メールアドレスは「サイト設定」の「連絡先メールアドレス」が使用されます。
                </p>
            </div>
            
            <!-- OAuth設定 -->
            <div class="bg-white rounded-xl shadow-sm p-4 sm:p-6">
                <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-key text-orange-500"></i>
                    ソーシャルログイン設定
                </h2>
                
                <!-- Google OAuth -->
                <div class="mb-6 p-4 border border-gray-200 rounded-lg">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <svg class="w-6 h-6" viewBox="0 0 24 24">
                                <path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/>
                                <path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/>
                                <path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/>
                                <path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/>
                            </svg>
                            <span class="font-bold">Google ログイン</span>
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="oauth_google_enabled" value="1"
                                <?= $settings['oauth_google_enabled'] === '1' ? 'checked' : '' ?>
                                class="w-5 h-5 text-green-500 rounded focus:ring-green-400">
                            <span class="text-sm font-bold">有効</span>
                        </label>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">クライアントID</label>
                            <input type="text" name="oauth_google_client_id" value="<?= htmlspecialchars($settings['oauth_google_client_id']) ?>"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">クライアントシークレット</label>
                            <input type="password" name="oauth_google_client_secret" value="<?= htmlspecialchars($settings['oauth_google_client_secret']) ?>"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none text-sm">
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">
                        <a href="https://console.cloud.google.com/apis/credentials" target="_blank" class="text-blue-500 hover:underline">Google Cloud Console</a> で OAuth 2.0 クライアントIDを作成してください。<br>
                        リダイレクトURI: <code class="bg-gray-100 px-1 rounded">https://<?= $_SERVER['HTTP_HOST'] ?>/store/oauth-callback.php</code>
                    </p>
                </div>
                
                <!-- LINE Login -->
                <div class="p-4 border border-gray-200 rounded-lg">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <svg class="w-6 h-6" viewBox="0 0 24 24" fill="#00B900">
                                <path d="M19.365 9.863c.349 0 .63.285.63.631 0 .345-.281.63-.63.63H17.61v1.125h1.755c.349 0 .63.283.63.63 0 .344-.281.629-.63.629h-2.386c-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63h2.386c.349 0 .63.285.63.63 0 .349-.281.63-.63.63H17.61v1.125h1.755zm-3.855 3.016c0 .27-.174.51-.432.596-.064.021-.133.031-.199.031-.211 0-.391-.09-.51-.25l-2.443-3.317v2.94c0 .344-.279.629-.631.629-.346 0-.626-.285-.626-.629V8.108c0-.27.173-.51.43-.595.06-.023.136-.033.194-.033.195 0 .375.104.495.254l2.462 3.33V8.108c0-.345.282-.63.63-.63.345 0 .63.285.63.63v4.771zm-5.741 0c0 .344-.282.629-.631.629-.345 0-.627-.285-.627-.629V8.108c0-.345.282-.63.63-.63.346 0 .628.285.628.63v4.771zm-2.466.629H4.917c-.345 0-.63-.285-.63-.629V8.108c0-.345.285-.63.63-.63.348 0 .63.285.63.63v4.141h1.756c.348 0 .629.283.629.63 0 .344-.282.629-.629.629M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/>
                            </svg>
                            <span class="font-bold">LINE ログイン</span>
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="oauth_line_enabled" value="1"
                                <?= $settings['oauth_line_enabled'] === '1' ? 'checked' : '' ?>
                                class="w-5 h-5 text-green-500 rounded focus:ring-green-400">
                            <span class="text-sm font-bold">有効</span>
                        </label>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">チャネルID</label>
                            <input type="text" name="oauth_line_channel_id" value="<?= htmlspecialchars($settings['oauth_line_channel_id']) ?>"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">チャネルシークレット</label>
                            <input type="password" name="oauth_line_channel_secret" value="<?= htmlspecialchars($settings['oauth_line_channel_secret']) ?>"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none text-sm">
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">
                        <a href="https://developers.line.biz/console/" target="_blank" class="text-blue-500 hover:underline">LINE Developers Console</a> でLINEログインチャネルを作成してください。<br>
                        コールバックURL: <code class="bg-gray-100 px-1 rounded">https://<?= $_SERVER['HTTP_HOST'] ?>/store/oauth-callback.php</code>
                    </p>
                </div>
                
                <!-- Amazon Login -->
                <div class="p-4 border border-gray-200 rounded-lg">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-3">
                            <svg class="w-6 h-6" viewBox="0 0 24 24" fill="#FF9900">
                                <path d="M.045 18.02c.072-.116.187-.124.348-.022 3.636 2.11 7.594 3.166 11.87 3.166 2.852 0 5.668-.533 8.447-1.595l.315-.14c.138-.06.234-.1.293-.13.226-.088.39-.046.487.126.06.11.075.244.035.396-.06.226-.27.4-.543.52-.903.413-1.9.744-2.984 1.008-2.19.53-4.4.79-6.63.79-4.51 0-8.64-1.16-12.37-3.49-.127-.08-.176-.18-.14-.3.015-.06.048-.12.087-.18l-.215-.15zM6.92 14.883c.082-.137.2-.15.354-.04l.077.053c1.404.96 2.905 1.44 4.5 1.44 1.02 0 2.12-.14 3.29-.42l.066-.015c.178-.045.328-.003.45.13.122.13.15.29.1.47-.05.18-.17.33-.35.42-.06.04-.12.07-.18.1-.18.08-.35.15-.53.22-.48.17-.96.3-1.43.38-.47.08-.94.12-1.41.12-1.96 0-3.72-.51-5.29-1.53-.18-.12-.23-.27-.15-.42l.5-.92z"/>
                            </svg>
                            <span class="font-bold">Amazon ログイン</span>
                        </div>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="oauth_amazon_enabled" value="1"
                                <?= ($settings['oauth_amazon_enabled'] ?? '0') === '1' ? 'checked' : '' ?>
                                class="w-5 h-5 text-orange-500 rounded focus:ring-orange-400">
                            <span class="text-sm font-bold">有効</span>
                        </label>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">クライアントID</label>
                            <input type="text" name="oauth_amazon_client_id" value="<?= htmlspecialchars($settings['oauth_amazon_client_id'] ?? '') ?>"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none text-sm">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">クライアントシークレット</label>
                            <input type="password" name="oauth_amazon_client_secret" value="<?= htmlspecialchars($settings['oauth_amazon_client_secret'] ?? '') ?>"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none text-sm">
                        </div>
                    </div>
                    <p class="text-xs text-gray-500 mt-2">
                        <a href="https://developer.amazon.com/loginwithamazon/console/site/lwa/overview.html" target="_blank" class="text-blue-500 hover:underline">Amazon Developer Console</a> でセキュリティプロファイルを作成してください。<br>
                        許可されたオリジン: <code class="bg-gray-100 px-1 rounded">https://<?= $_SERVER['HTTP_HOST'] ?></code><br>
                        許可されたリターンURL: <code class="bg-gray-100 px-1 rounded">https://<?= $_SERVER['HTTP_HOST'] ?>/store/oauth-callback.php</code>
                    </p>
                </div>
            </div>
            
            <!-- Stripe決済設定 -->
            <div class="bg-white rounded-xl shadow-sm p-6">
                <h2 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fab fa-stripe text-indigo-500"></i>Stripe決済設定
                </h2>
                
                <!-- 本番モード切り替え -->
                <div class="mb-6 p-4 bg-yellow-50 rounded-lg border border-yellow-200">
                    <label class="flex items-center gap-3">
                        <input type="checkbox" name="stripe_live_mode" value="1"
                            <?= $settings['stripe_live_mode'] === '1' ? 'checked' : '' ?>
                            class="w-5 h-5 text-yellow-500 rounded focus:ring-yellow-400">
                        <span class="font-bold text-yellow-800">本番モードを有効にする</span>
                    </label>
                    <p class="text-xs text-yellow-700 mt-2 ml-8">
                        ⚠️ 本番モードでは実際の決済が行われます。テストが完了してから有効にしてください。
                    </p>
                </div>
                
                <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                    <!-- テスト環境 -->
                    <div class="border border-gray-200 rounded-lg p-4">
                        <h3 class="font-bold text-gray-700 mb-4 flex items-center gap-2">
                            <i class="fas fa-flask text-orange-500"></i>テスト環境
                        </h3>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">公開キー（pk_test_...）</label>
                                <input type="text" name="stripe_test_publishable_key" 
                                    value="<?= htmlspecialchars($settings['stripe_test_publishable_key']) ?>"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none font-mono text-sm"
                                    placeholder="pk_test_...">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">シークレットキー（sk_test_...）</label>
                                <input type="password" name="stripe_test_secret_key" 
                                    value="<?= htmlspecialchars($settings['stripe_test_secret_key']) ?>"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none font-mono text-sm"
                                    placeholder="sk_test_...">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">Webhookシークレット（whsec_...）</label>
                                <input type="password" name="stripe_test_webhook_secret" 
                                    value="<?= htmlspecialchars($settings['stripe_test_webhook_secret']) ?>"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none font-mono text-sm"
                                    placeholder="whsec_...">
                            </div>
                        </div>
                        <p class="text-xs text-gray-500 mt-3">
                            テスト用カード: <code class="bg-gray-100 px-1 rounded">4242 4242 4242 4242</code>
                        </p>
                    </div>
                    
                    <!-- 本番環境 -->
                    <div class="border border-gray-200 rounded-lg p-4">
                        <h3 class="font-bold text-gray-700 mb-4 flex items-center gap-2">
                            <i class="fas fa-rocket text-green-500"></i>本番環境
                        </h3>
                        <div class="space-y-4">
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">公開キー（pk_live_...）</label>
                                <input type="text" name="stripe_live_publishable_key" 
                                    value="<?= htmlspecialchars($settings['stripe_live_publishable_key']) ?>"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none font-mono text-sm"
                                    placeholder="pk_live_...">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">シークレットキー（sk_live_...）</label>
                                <input type="password" name="stripe_live_secret_key" 
                                    value="<?= htmlspecialchars($settings['stripe_live_secret_key']) ?>"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none font-mono text-sm"
                                    placeholder="sk_live_...">
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">Webhookシークレット（whsec_...）</label>
                                <input type="password" name="stripe_live_webhook_secret" 
                                    value="<?= htmlspecialchars($settings['stripe_live_webhook_secret']) ?>"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none font-mono text-sm"
                                    placeholder="whsec_...">
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="mt-4 p-3 bg-gray-50 rounded-lg text-sm text-gray-600">
                    <p class="font-bold mb-2"><i class="fas fa-info-circle mr-1"></i>設定手順</p>
                    <ol class="list-decimal list-inside space-y-1 text-xs">
                        <li><a href="https://dashboard.stripe.com/apikeys" target="_blank" class="text-blue-500 hover:underline">Stripeダッシュボード</a> でAPIキーを取得</li>
                        <li>Webhookエンドポイントを追加: <code class="bg-gray-200 px-1 rounded">https://<?= $_SERVER['HTTP_HOST'] ?>/store/webhook.php</code></li>
                        <li>Webhookイベントで <code class="bg-gray-200 px-1 rounded">checkout.session.completed</code> を選択</li>
                        <li>Webhookシークレットをコピーして上記に入力</li>
                    </ol>
                </div>
            </div>
            
            <div class="flex justify-end">
                <button type="submit" name="save_settings" class="bg-yellow-400 hover:bg-yellow-500 text-gray-900 px-6 py-3 rounded-lg font-bold">
                    <i class="fas fa-save mr-2"></i>設定を保存
                </button>
            </div>
        </form>

<?php include "includes/footer.php"; ?>
