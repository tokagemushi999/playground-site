<?php
/**
 * 領収書/インボイス表示・PDF出力
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/member-auth.php';
require_once '../includes/site-settings.php';

requireMemberAuth();

$member = getCurrentMember();
$db = getDB();
$orderId = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// 注文を取得（自分の注文のみ）
$stmt = $db->prepare("SELECT * FROM orders WHERE id = ? AND member_id = ?");
$stmt->execute([$orderId, $member['id']]);
$order = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$order || $order['payment_status'] !== 'paid') {
    header('Location: /store/orders.php');
    exit;
}

// 注文明細を取得（クリエイター情報付き）
$stmt = $db->prepare("
    SELECT oi.*, p.creator_id, p.image,
           c.name as creator_name
    FROM order_items oi 
    LEFT JOIN products p ON oi.product_id = p.id 
    LEFT JOIN creators c ON p.creator_id = c.id
    WHERE oi.order_id = ?
");
$stmt->execute([$orderId]);
$orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// サイト設定
$settings = getSiteSettings();
$siteName = $settings['site_name'] ?? 'ぷれぐら！PLAYGROUND';

// ショップのインボイス情報を取得
$shopInvoiceName = getSiteSetting($db, 'store_business_name', $siteName);
$shopInvoiceNumber = getSiteSetting($db, 'store_invoice_number', '');
$shopInvoiceAddress = getSiteSetting($db, 'store_address', '');

// 送料・合計
$shippingFee = (int)$order['shipping_fee'];
$orderSubtotal = (int)$order['subtotal'];
$orderTotal = (int)$order['total'];

// 税額計算（10%内税）
$totalTax = floor($orderTotal * 10 / 110);
$totalWithoutTax = $orderTotal - $totalTax;

// 支払方法
$paymentMethod = '未設定';
if ($order['payment_method'] === 'stripe') {
    $paymentMethod = 'クレジットカード';
} elseif ($order['payment_method'] === 'bank_transfer') {
    $paymentMethod = '銀行振込';
} elseif ($order['payment_method'] === 'cod') {
    $paymentMethod = '代金引換';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>領収書 - <?= htmlspecialchars($order['order_number']) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none !important; }
            .print-break { page-break-after: always; }
        }
        .invoice-box {
            max-width: 800px;
            margin: auto;
            padding: 30px;
            border: 1px solid #eee;
            box-shadow: 0 0 10px rgba(0, 0, 0, .15);
            font-size: 14px;
            line-height: 24px;
            background: white;
        }
    </style>
</head>
<body class="bg-gray-100 py-8">
    <!-- 操作ボタン -->
    <div class="no-print max-w-[800px] mx-auto mb-4 px-4 flex justify-between items-center">
        <a href="/store/order.php?id=<?= $orderId ?>" class="text-blue-600 hover:underline">
            <i class="fas fa-arrow-left mr-1"></i>注文詳細に戻る
        </a>
        <button onclick="window.print()" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
            <i class="fas fa-print mr-2"></i>印刷 / PDF保存
        </button>
    </div>

    <div class="invoice-box mb-8">
        <!-- ヘッダー -->
        <div class="text-center border-b-2 border-gray-800 pb-4 mb-6">
            <h1 class="text-3xl font-bold tracking-widest">領 収 書</h1>
            <?php if ($shopInvoiceNumber): ?>
            <p class="text-sm text-gray-600 mt-1">（適格請求書）</p>
            <?php endif; ?>
        </div>
        
        <div class="grid grid-cols-2 gap-8 mb-8">
            <!-- 左側：宛名 -->
            <div>
                <div class="border-b-2 border-gray-800 pb-2 mb-4">
                    <p class="text-xl font-bold"><?= htmlspecialchars($member['name']) ?> 様</p>
                </div>
                <table class="text-sm">
                    <tr>
                        <td class="text-gray-600 pr-4">発行日</td>
                        <td><?= date('Y年n月j日', strtotime($order['paid_at'] ?: $order['created_at'])) ?></td>
                    </tr>
                    <tr>
                        <td class="text-gray-600 pr-4">注文番号</td>
                        <td><?= htmlspecialchars($order['order_number']) ?></td>
                    </tr>
                    <tr>
                        <td class="text-gray-600 pr-4">お支払方法</td>
                        <td><?= htmlspecialchars($paymentMethod) ?></td>
                    </tr>
                </table>
            </div>
            
            <!-- 右側：発行者 -->
            <div class="text-right">
                <p class="font-bold text-lg mb-1"><?= htmlspecialchars($shopInvoiceName) ?></p>
                <?php if ($shopInvoiceNumber): ?>
                <p class="text-sm text-gray-600">
                    登録番号: <?= htmlspecialchars($shopInvoiceNumber) ?>
                </p>
                <?php endif; ?>
                <?php if ($shopInvoiceAddress): ?>
                <p class="text-sm text-gray-600 whitespace-pre-line mt-1"><?= htmlspecialchars($shopInvoiceAddress) ?></p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- 金額 -->
        <div class="bg-gray-100 p-4 rounded mb-6 text-center">
            <p class="text-gray-600 text-sm mb-1">ご利用金額（税込）</p>
            <p class="text-3xl font-bold">¥<?= number_format($orderTotal) ?>-</p>
        </div>
        
        <!-- 明細 -->
        <table class="w-full mb-6">
            <thead>
                <tr class="border-b-2 border-gray-800">
                    <th class="text-left py-2">品名</th>
                    <th class="text-center py-2 w-20">数量</th>
                    <th class="text-right py-2 w-28">単価</th>
                    <th class="text-right py-2 w-28">金額</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($orderItems as $item): ?>
                <tr class="border-b border-gray-200">
                    <td class="py-2">
                        <?= htmlspecialchars($item['product_name']) ?>
                        <span class="text-xs text-gray-500 ml-1">※</span>
                    </td>
                    <td class="text-center py-2"><?= $item['quantity'] ?></td>
                    <td class="text-right py-2">¥<?= number_format($item['price']) ?></td>
                    <td class="text-right py-2">¥<?= number_format($item['subtotal']) ?></td>
                </tr>
                <?php endforeach; ?>
                
                <?php if ($shippingFee > 0): ?>
                <tr class="border-b border-gray-200">
                    <td class="py-2">
                        送料
                        <span class="text-xs text-gray-500 ml-1">※</span>
                    </td>
                    <td class="text-center py-2">1</td>
                    <td class="text-right py-2">¥<?= number_format($shippingFee) ?></td>
                    <td class="text-right py-2">¥<?= number_format($shippingFee) ?></td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- 税額計算 -->
        <div class="flex justify-end mb-6">
            <table class="text-sm">
                <tr>
                    <td class="text-gray-600 pr-8">小計</td>
                    <td class="text-right">¥<?= number_format($orderSubtotal) ?></td>
                </tr>
                <?php if ($shippingFee > 0): ?>
                <tr>
                    <td class="text-gray-600 pr-8">送料</td>
                    <td class="text-right">¥<?= number_format($shippingFee) ?></td>
                </tr>
                <?php endif; ?>
                <tr class="border-t border-gray-300">
                    <td class="text-gray-600 pr-8 pt-2">10%対象</td>
                    <td class="text-right pt-2">¥<?= number_format($orderTotal) ?></td>
                </tr>
                <tr>
                    <td class="text-gray-600 pr-8">（税抜金額）</td>
                    <td class="text-right">¥<?= number_format($totalWithoutTax) ?></td>
                </tr>
                <tr>
                    <td class="text-gray-600 pr-8">（消費税10%）</td>
                    <td class="text-right">¥<?= number_format($totalTax) ?></td>
                </tr>
                <tr class="border-t-2 border-gray-800">
                    <td class="font-bold pr-8 pt-2 text-base">合計</td>
                    <td class="text-right font-bold pt-2 text-base">¥<?= number_format($orderTotal) ?></td>
                </tr>
            </table>
        </div>
        
        <!-- 備考 -->
        <div class="text-xs text-gray-500 border-t pt-4">
            <p>※ 軽減税率（8%）対象品目はありません。すべて標準税率（10%）対象です。</p>
            <p>上記の金額を正に領収いたしました。</p>
            <?php if ($shopInvoiceNumber): ?>
            <p class="mt-2">この領収書は適格請求書等保存方式（インボイス制度）に対応しています。</p>
            <?php else: ?>
            <p class="mt-2 text-orange-600">※ この領収書はインボイス登録番号の記載がないため、仕入税額控除の対象となりません。</p>
            <?php endif; ?>
        </div>
    </div>
    
    <!-- 操作ボタン（下部） -->
    <div class="no-print max-w-[800px] mx-auto mt-4 px-4 text-center text-sm text-gray-500">
        <p>印刷ダイアログで「PDFとして保存」を選択するとPDFファイルとして保存できます。</p>
    </div>
</body>
</html>
