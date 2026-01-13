<?php
/**
 * 支払通知書生成（クリエイター向け）
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/site-settings.php';
require_once '../includes/google-drive.php';
requireAuth();

$db = getDB();
$gdrive = getGoogleDrive($db);
$message = '';

$creatorId = isset($_GET['creator_id']) ? (int)$_GET['creator_id'] : 0;
$yearMonth = isset($_GET['month']) ? $_GET['month'] : date('Y-m');

// 期間の計算
$startDate = $yearMonth . '-01 00:00:00';
$endDate = date('Y-m-t 23:59:59', strtotime($startDate));
$targetYear = (int)date('Y', strtotime($startDate));
$targetMonth = (int)date('n', strtotime($startDate));

// クリエイター情報を取得
$stmt = $db->prepare("SELECT * FROM creators WHERE id = ? AND is_active = 1");
$stmt->execute([$creatorId]);
$creator = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$creator) {
    header('Location: creators.php');
    exit;
}

// サイト設定
$settings = getSiteSettings();
$siteName = $settings['site_name'] ?? 'ぷれぐら！PLAYGROUND';
$shopName = getSiteSetting($db, 'store_business_name', $siteName);
$shopAddress = getSiteSetting($db, 'store_address', '');
$shopInvoiceNumber = getSiteSetting($db, 'store_invoice_number', '');

// Google Drive保存処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_to_drive'])) {
    if ($gdrive->isConnected()) {
        try {
            $folderId = $gdrive->getMonthlyFolder('payment_notices', $targetYear, $targetMonth);
            if ($folderId) {
                // HTMLをPDFとして保存（簡易版：HTMLファイルとして保存）
                $filename = sprintf('支払通知書_%s_%d年%02d月.html', 
                    $creator['name'], $targetYear, $targetMonth);
                
                // HTMLコンテンツを生成
                ob_start();
                $isPrint = true;
                $saveMode = true;
                include __FILE__;
                $htmlContent = ob_get_clean();
                
                // Google Driveにアップロード
                $result = $gdrive->uploadPdfContent($htmlContent, $filename, $folderId);
                
                if ($result) {
                    // 保存履歴を記録
                    $stmt = $db->prepare("INSERT INTO document_archives 
                        (document_type, reference_id, reference_type, filename, gdrive_file_id)
                        VALUES ('payment_notice', ?, 'creator', ?, ?)");
                    $stmt->execute([$creatorId, $filename, $result['id']]);
                    
                    $message = 'Google Driveに保存しました。';
                }
            }
        } catch (Exception $e) {
            $message = 'エラー: ' . $e->getMessage();
        }
    }
}

// このクリエイターの売上を取得（支払済み注文のみ）
$stmt = $db->prepare("
    SELECT 
        o.id as order_id,
        o.order_number,
        o.paid_at,
        oi.product_name,
        oi.price,
        oi.quantity,
        oi.subtotal,
        p.name as product_name_master
    FROM orders o
    INNER JOIN order_items oi ON o.id = oi.order_id
    INNER JOIN products p ON oi.product_id = p.id
    WHERE p.creator_id = ?
      AND o.payment_status = 'paid'
      AND o.paid_at >= ?
      AND o.paid_at <= ?
    ORDER BY o.paid_at ASC
");
$stmt->execute([$creatorId, $startDate, $endDate]);
$salesItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 集計
$totalSales = 0;
$totalQuantity = 0;
$orderCount = 0;
$processedOrders = [];

foreach ($salesItems as $item) {
    $totalSales += $item['subtotal'];
    $totalQuantity += $item['quantity'];
    if (!in_array($item['order_id'], $processedOrders)) {
        $processedOrders[] = $item['order_id'];
        $orderCount++;
    }
}

// 手数料計算
$commissionRate = (float)($creator['commission_rate'] ?? 20);
$commissionPerItem = (int)($creator['commission_per_item'] ?? 0);

$commissionByRate = floor($totalSales * $commissionRate / 100);
$commissionByItem = $commissionPerItem * $orderCount;
$totalCommission = $commissionByRate + $commissionByItem;

// 源泉徴収計算（個人で源泉徴収対象の場合）
$businessType = $creator['business_type'] ?? 'individual';
$withholdingRequired = (int)($creator['withholding_tax_required'] ?? 1);
$withholdingTax = 0;
$withholdingTaxRate = 10.21; // 復興特別所得税含む

if ($businessType === 'individual' && $withholdingRequired && $totalSales > 0) {
    // 源泉徴収の計算（支払金額に対して10.21%）
    $taxableAmount = $totalSales - $totalCommission;
    if ($taxableAmount <= 1000000) {
        // 100万円以下：10.21%
        $withholdingTax = floor($taxableAmount * 0.1021);
    } else {
        // 100万円超：(支払金額 - 100万円) × 20.42% + 102,100円
        $withholdingTax = floor(($taxableAmount - 1000000) * 0.2042) + 102100;
    }
}

$paymentAmount = $totalSales - $totalCommission - $withholdingTax;

$displayMonth = date('Y年n月', strtotime($startDate));

// 印刷モード
$isPrint = isset($_GET['print']);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>支払通知書 - <?= htmlspecialchars($creator['name']) ?> - <?= $displayMonth ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @media print {
            body { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            .no-print { display: none !important; }
        }
        .document-box {
            max-width: 800px;
            margin: auto;
            padding: 40px;
            border: 1px solid #ddd;
            box-shadow: 0 0 10px rgba(0, 0, 0, .1);
            font-size: 14px;
            line-height: 1.8;
            background: white;
        }
    </style>
</head>
<body class="bg-gray-100 py-8">
    <!-- 操作ボタン -->
    <div class="no-print max-w-[800px] mx-auto mb-4 px-4">
        <?php if ($message): ?>
        <div class="bg-green-50 border border-green-200 text-green-600 px-4 py-2 rounded-lg mb-4">
            <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
        
        <div class="flex justify-between items-center">
            <a href="creator-sales.php?year=<?= $targetYear ?>&month=<?= $targetMonth ?>" class="text-blue-600 hover:underline">
                <i class="fas fa-arrow-left mr-1"></i>売上・支払管理に戻る
            </a>
            <div class="flex gap-2">
                <!-- 月選択 -->
                <form method="GET" class="flex items-center gap-2">
                    <input type="hidden" name="creator_id" value="<?= $creatorId ?>">
                    <input type="month" name="month" value="<?= $yearMonth ?>" 
                        class="px-3 py-2 border border-gray-300 rounded-lg"
                        onchange="this.form.submit()">
                </form>
                
                <?php if ($gdrive->isConnected()): ?>
                <form method="POST" class="inline">
                    <button type="submit" name="save_to_drive" value="1"
                            class="bg-green-600 text-white px-4 py-2 rounded hover:bg-green-700">
                        <i class="fab fa-google-drive mr-2"></i>Driveに保存
                    </button>
                </form>
                <?php endif; ?>
                
                <button onclick="window.print()" class="bg-blue-600 text-white px-4 py-2 rounded hover:bg-blue-700">
                    <i class="fas fa-print mr-2"></i>印刷 / PDF保存
                </button>
            </div>
        </div>
    </div>

    <div class="document-box mb-8">
        <!-- ヘッダー -->
        <div class="text-center border-b-2 border-gray-800 pb-4 mb-8">
            <h1 class="text-2xl font-bold tracking-widest">支 払 通 知 書</h1>
            <p class="text-sm text-gray-600 mt-2"><?= $displayMonth ?>分</p>
        </div>
        
        <div class="grid grid-cols-2 gap-8 mb-8">
            <!-- 左側：宛先 -->
            <div>
                <div class="border-b-2 border-gray-800 pb-2 mb-4">
                    <p class="text-xl font-bold"><?= htmlspecialchars($creator['name']) ?> 様</p>
                </div>
            </div>
            
            <!-- 右側：発行者 -->
            <div class="text-right">
                <p class="text-sm text-gray-600 mb-2">発行日: <?= date('Y年n月j日') ?></p>
                <p class="font-bold text-lg"><?= htmlspecialchars($shopName) ?></p>
                <?php if ($shopAddress): ?>
                <p class="text-sm text-gray-600 whitespace-pre-line mt-1"><?= htmlspecialchars($shopAddress) ?></p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- お支払金額 -->
        <div class="bg-blue-50 border-2 border-blue-200 p-6 rounded-lg mb-8 text-center">
            <p class="text-gray-600 text-sm mb-2">お支払金額</p>
            <p class="text-4xl font-bold text-blue-800">¥<?= number_format($paymentAmount) ?>-</p>
        </div>
        
        <!-- 集計 -->
        <div class="mb-8">
            <h2 class="font-bold text-lg border-b-2 border-gray-800 pb-2 mb-4">売上・手数料集計</h2>
            <table class="w-full">
                <tr class="border-b border-gray-200">
                    <td class="py-2 text-gray-600">売上合計</td>
                    <td class="py-2 text-right font-bold">¥<?= number_format($totalSales) ?></td>
                </tr>
                <tr class="border-b border-gray-200">
                    <td class="py-2 text-gray-600 pl-4">- 販売数量</td>
                    <td class="py-2 text-right"><?= number_format($totalQuantity) ?>点</td>
                </tr>
                <tr class="border-b border-gray-200">
                    <td class="py-2 text-gray-600 pl-4">- 注文件数</td>
                    <td class="py-2 text-right"><?= number_format($orderCount) ?>件</td>
                </tr>
                <tr class="border-b border-gray-200">
                    <td class="py-2 text-gray-600">販売手数料（<?= $commissionRate ?>%）</td>
                    <td class="py-2 text-right text-red-600">-¥<?= number_format($commissionByRate) ?></td>
                </tr>
                <?php if ($commissionPerItem > 0): ?>
                <tr class="border-b border-gray-200">
                    <td class="py-2 text-gray-600">販売手数料（¥<?= number_format($commissionPerItem) ?>/件 × <?= $orderCount ?>件）</td>
                    <td class="py-2 text-right text-red-600">-¥<?= number_format($commissionByItem) ?></td>
                </tr>
                <?php endif; ?>
                <tr class="border-b border-gray-200">
                    <td class="py-2 text-gray-600 font-bold">手数料合計</td>
                    <td class="py-2 text-right font-bold text-red-600">-¥<?= number_format($totalCommission) ?></td>
                </tr>
                <?php if ($withholdingTax > 0): ?>
                <tr class="border-b border-gray-200 bg-yellow-50">
                    <td class="py-2 text-gray-600">
                        源泉徴収税額（<?= $withholdingTaxRate ?>%）
                        <span class="text-xs text-gray-500 block">※復興特別所得税含む</span>
                    </td>
                    <td class="py-2 text-right text-orange-600 font-bold">-¥<?= number_format($withholdingTax) ?></td>
                </tr>
                <?php endif; ?>
                <tr class="border-t-2 border-gray-800">
                    <td class="py-3 font-bold text-lg">お支払金額</td>
                    <td class="py-3 text-right font-bold text-lg text-blue-800">¥<?= number_format($paymentAmount) ?></td>
                </tr>
            </table>
            <?php if ($withholdingTax > 0): ?>
            <p class="text-xs text-gray-500 mt-2">
                <i class="fas fa-info-circle mr-1"></i>
                源泉徴収税は当社が税務署に納付いたします。
            </p>
            <?php endif; ?>
        </div>
        
        <!-- 振込先 -->
        <?php if ($creator['bank_name']): ?>
        <div class="mb-8 bg-gray-50 p-4 rounded-lg">
            <h2 class="font-bold text-lg mb-3"><i class="fas fa-university mr-2"></i>振込先口座</h2>
            <table class="text-sm">
                <tr>
                    <td class="text-gray-600 pr-4 py-1">金融機関</td>
                    <td class="font-bold"><?= htmlspecialchars($creator['bank_name']) ?></td>
                </tr>
                <tr>
                    <td class="text-gray-600 pr-4 py-1">支店名</td>
                    <td><?= htmlspecialchars($creator['bank_branch']) ?></td>
                </tr>
                <tr>
                    <td class="text-gray-600 pr-4 py-1">口座種別</td>
                    <td><?= htmlspecialchars($creator['bank_account_type']) ?></td>
                </tr>
                <tr>
                    <td class="text-gray-600 pr-4 py-1">口座番号</td>
                    <td><?= htmlspecialchars($creator['bank_account_number']) ?></td>
                </tr>
                <tr>
                    <td class="text-gray-600 pr-4 py-1">口座名義</td>
                    <td><?= htmlspecialchars($creator['bank_account_name']) ?></td>
                </tr>
            </table>
        </div>
        <?php endif; ?>
        
        <!-- 明細 -->
        <div class="mb-8">
            <h2 class="font-bold text-lg border-b-2 border-gray-800 pb-2 mb-4">売上明細</h2>
            <?php if (empty($salesItems)): ?>
            <p class="text-gray-500 text-center py-8">この期間の売上はありません</p>
            <?php else: ?>
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b-2 border-gray-300 bg-gray-50">
                        <th class="text-left py-2 px-2">日付</th>
                        <th class="text-left py-2 px-2">注文番号</th>
                        <th class="text-left py-2 px-2">商品名</th>
                        <th class="text-center py-2 px-2">数量</th>
                        <th class="text-right py-2 px-2">単価</th>
                        <th class="text-right py-2 px-2">金額</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($salesItems as $item): ?>
                    <tr class="border-b border-gray-200">
                        <td class="py-2 px-2"><?= date('m/d', strtotime($item['paid_at'])) ?></td>
                        <td class="py-2 px-2 font-mono text-xs"><?= htmlspecialchars($item['order_number']) ?></td>
                        <td class="py-2 px-2"><?= htmlspecialchars($item['product_name']) ?></td>
                        <td class="py-2 px-2 text-center"><?= $item['quantity'] ?></td>
                        <td class="py-2 px-2 text-right">¥<?= number_format($item['price']) ?></td>
                        <td class="py-2 px-2 text-right">¥<?= number_format($item['subtotal']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-gray-800 bg-gray-50">
                        <td colspan="5" class="py-2 px-2 font-bold text-right">合計</td>
                        <td class="py-2 px-2 text-right font-bold">¥<?= number_format($totalSales) ?></td>
                    </tr>
                </tfoot>
            </table>
            <?php endif; ?>
        </div>
        
        <!-- 備考 -->
        <div class="text-xs text-gray-500 border-t pt-4">
            <p>※ 上記金額は<?= $displayMonth ?>1日〜<?= date('t', strtotime($startDate)) ?>日の売上に基づいて計算しています。</p>
            <p>※ 手数料には消費税が含まれています。</p>
            <p>※ お支払いは翌月末日までに上記口座へ振り込みいたします。</p>
        </div>
    </div>
    
    <!-- 操作説明 -->
    <div class="no-print max-w-[800px] mx-auto mt-4 px-4 text-center text-sm text-gray-500">
        <p>印刷ダイアログで「PDFとして保存」を選択するとPDFファイルとして保存できます。</p>
    </div>
</body>
</html>
