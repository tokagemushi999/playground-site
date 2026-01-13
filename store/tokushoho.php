<?php
/**
 * 特定商取引法に基づく表記
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/site-settings.php';

$db = getDB();

$settings = getSiteSettings();
$siteName = $settings['site_name'] ?? 'CREATORS PLAYGROUND';

// getSiteSettingを使用（site-settings.phpで定義済み）
$store = [
    'business_name' => getSiteSetting($db, 'store_business_name', ''),
    'representative' => getSiteSetting($db, 'store_representative', ''),
    'address' => getSiteSetting($db, 'store_address', ''),
    'address_note' => getSiteSetting($db, 'store_address_note', ''),
    'phone' => getSiteSetting($db, 'store_phone', ''),
    'phone_note' => getSiteSetting($db, 'store_phone_note', ''),
    'email' => getSiteSetting($db, 'store_email', ''),
    'payment_methods' => getSiteSetting($db, 'store_payment_methods', 'クレジットカード（VISA、Mastercard、American Express、JCB）'),
    'payment_timing' => getSiteSetting($db, 'store_payment_timing', 'ご注文時にクレジットカード決済が行われます。'),
    'delivery_digital' => getSiteSetting($db, 'store_delivery_digital', '決済完了後、直ちにマイページの「本棚」からご利用いただけます。'),
    'delivery_physical' => getSiteSetting($db, 'store_delivery_physical', 'ご注文確認後、通常3〜7営業日以内に発送いたします。'),
    'return_digital' => getSiteSetting($db, 'store_return_digital', '商品の性質上、購入後の返品・返金はお受けできません。'),
    'return_physical' => getSiteSetting($db, 'store_return_physical', '商品到着後7日以内に限り、未開封・未使用の場合のみ返品を承ります。'),
    'environment' => getSiteSetting($db, 'store_environment', ''),
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php include 'includes/pwa-meta.php'; ?>
    <title>特定商取引法に基づく表記 - <?= htmlspecialchars($siteName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Zen Maru Gothic', sans-serif; }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-white shadow-sm">
        <div class="max-w-4xl mx-auto px-4 py-4">
            <a href="/" class="text-xl font-bold text-gray-800"><?= htmlspecialchars($siteName) ?></a>
        </div>
    </header>

    <main class="max-w-3xl mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold text-gray-800 mb-8">特定商取引法に基づく表記</h1>
        
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <table class="w-full">
                <tbody class="divide-y divide-gray-200">
                    <tr>
                        <th class="px-4 sm:px-6 py-4 bg-gray-50 text-left text-sm font-bold text-gray-700 w-1/3">販売事業者</th>
                        <td class="px-4 sm:px-6 py-4 text-gray-600">
                            <?= htmlspecialchars($store['business_name']) ?: '<span class="text-gray-400">未設定</span>' ?>
                        </td>
                    </tr>
                    <tr>
                        <th class="px-4 sm:px-6 py-4 bg-gray-50 text-left text-sm font-bold text-gray-700">運営統括責任者</th>
                        <td class="px-4 sm:px-6 py-4 text-gray-600">
                            <?= htmlspecialchars($store['representative']) ?: '<span class="text-gray-400">未設定</span>' ?>
                        </td>
                    </tr>
                    <tr>
                        <th class="px-4 sm:px-6 py-4 bg-gray-50 text-left text-sm font-bold text-gray-700">所在地</th>
                        <td class="px-4 sm:px-6 py-4 text-gray-600">
                            <?php if ($store['address']): ?>
                                <?= nl2br(htmlspecialchars($store['address'])) ?>
                            <?php endif; ?>
                            <?php if ($store['address_note']): ?>
                                <br><span class="text-sm text-gray-500">※<?= htmlspecialchars($store['address_note']) ?></span>
                            <?php endif; ?>
                            <?php if (!$store['address'] && !$store['address_note']): ?>
                                <span class="text-gray-400">未設定</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th class="px-4 sm:px-6 py-4 bg-gray-50 text-left text-sm font-bold text-gray-700">電話番号</th>
                        <td class="px-4 sm:px-6 py-4 text-gray-600">
                            <?php if ($store['phone']): ?>
                                <?= htmlspecialchars($store['phone']) ?>
                            <?php endif; ?>
                            <?php if ($store['phone_note']): ?>
                                <br><span class="text-sm text-gray-500">※<?= htmlspecialchars($store['phone_note']) ?></span>
                            <?php endif; ?>
                            <?php if (!$store['phone'] && !$store['phone_note']): ?>
                                <span class="text-gray-400">未設定</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th class="px-4 sm:px-6 py-4 bg-gray-50 text-left text-sm font-bold text-gray-700">メールアドレス</th>
                        <td class="px-4 sm:px-6 py-4 text-gray-600">
                            <?= htmlspecialchars($store['email']) ?: '<span class="text-gray-400">未設定</span>' ?>
                        </td>
                    </tr>
                    <tr>
                        <th class="px-4 sm:px-6 py-4 bg-gray-50 text-left text-sm font-bold text-gray-700">販売URL</th>
                        <td class="px-4 sm:px-6 py-4 text-gray-600">
                            https://<?= $_SERVER['HTTP_HOST'] ?>/store/
                        </td>
                    </tr>
                    <tr>
                        <th class="px-4 sm:px-6 py-4 bg-gray-50 text-left text-sm font-bold text-gray-700">販売価格</th>
                        <td class="px-4 sm:px-6 py-4 text-gray-600">
                            各商品ページに税込価格で表示
                        </td>
                    </tr>
                    <tr>
                        <th class="px-4 sm:px-6 py-4 bg-gray-50 text-left text-sm font-bold text-gray-700">商品代金以外の必要料金</th>
                        <td class="px-4 sm:px-6 py-4 text-gray-600">
                            <strong>送料:</strong> 物販商品の場合、別途送料がかかります。送料は地域により異なり、ご注文時に表示されます。<br>
                            <strong>決済手数料:</strong> クレジットカード決済の手数料は当社負担です。
                        </td>
                    </tr>
                    <tr>
                        <th class="px-4 sm:px-6 py-4 bg-gray-50 text-left text-sm font-bold text-gray-700">お支払い方法</th>
                        <td class="px-4 sm:px-6 py-4 text-gray-600">
                            <?= nl2br(htmlspecialchars($store['payment_methods'])) ?>
                        </td>
                    </tr>
                    <tr>
                        <th class="px-4 sm:px-6 py-4 bg-gray-50 text-left text-sm font-bold text-gray-700">お支払い時期</th>
                        <td class="px-4 sm:px-6 py-4 text-gray-600">
                            <?= nl2br(htmlspecialchars($store['payment_timing'])) ?>
                        </td>
                    </tr>
                    <tr>
                        <th class="px-4 sm:px-6 py-4 bg-gray-50 text-left text-sm font-bold text-gray-700">商品の引渡し時期</th>
                        <td class="px-4 sm:px-6 py-4 text-gray-600">
                            <strong>デジタル商品:</strong> <?= nl2br(htmlspecialchars($store['delivery_digital'])) ?><br>
                            <strong>物販商品:</strong> <?= nl2br(htmlspecialchars($store['delivery_physical'])) ?>
                        </td>
                    </tr>
                    <tr>
                        <th class="px-4 sm:px-6 py-4 bg-gray-50 text-left text-sm font-bold text-gray-700">返品・交換について</th>
                        <td class="px-4 sm:px-6 py-4 text-gray-600">
                            <strong>デジタル商品:</strong> <?= nl2br(htmlspecialchars($store['return_digital'])) ?><br>
                            <strong>物販商品:</strong> <?= nl2br(htmlspecialchars($store['return_physical'])) ?><br>
                            <span class="text-sm text-gray-500">※返品・交換のお申し込みは、お問い合わせフォームよりご連絡ください。</span>
                        </td>
                    </tr>
                    <?php if ($store['environment']): ?>
                    <tr>
                        <th class="px-4 sm:px-6 py-4 bg-gray-50 text-left text-sm font-bold text-gray-700">動作環境</th>
                        <td class="px-4 sm:px-6 py-4 text-gray-600">
                            <?= nl2br(htmlspecialchars($store['environment'])) ?>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
        
        <div class="mt-8 text-center">
            <a href="/store/" class="text-blue-600 hover:underline">
                <i class="fas fa-arrow-left mr-1"></i>ストアに戻る
            </a>
        </div>
    </main>
    
    <footer class="bg-white border-t mt-12 py-8">
        <div class="max-w-4xl mx-auto px-4 text-center text-sm text-gray-500">
            <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($siteName) ?></p>
        </div>
    </footer>
</body>
</html>
