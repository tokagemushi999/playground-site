<?php
/**
 * クリエイターダッシュボード - 売上レポート（契約ベース）
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/creator-auth.php';

$creator = requireCreatorAuth();
$db = getDB();

// 対象年月
$targetYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$targetMonth = isset($_GET['month']) ? (int)$_GET['month'] : null;

// 契約条件（手数料率）
$productCommissionRate = (float)($creator['commission_rate'] ?? 20);
$serviceCommissionRate = (float)($creator['service_commission_rate'] ?? 15);
$productCommissionPerItem = (int)($creator['commission_per_item'] ?? 0);
$serviceCommissionPerItem = (int)($creator['service_commission_per_item'] ?? 0);
$withholdingTaxRequired = (bool)($creator['withholding_tax_required'] ?? 1);

// 現在の契約を取得
$currentContract = null;
try {
    $stmt = $db->prepare("
        SELECT * FROM creator_contracts 
        WHERE creator_id = ? AND status = 'agreed' 
        ORDER BY version DESC LIMIT 1
    ");
    $stmt->execute([$creator['id']]);
    $currentContract = $stmt->fetch();
} catch (PDOException $e) {}

// 月別売上データを取得
$monthlyData = [];

// 商品売上（注文ベース）
$productSalesRaw = [];
try {
    $stmt = $db->prepare("
        SELECT 
            MONTH(o.created_at) as month,
            SUM(oi.subtotal) as sales,
            COUNT(DISTINCT o.id) as order_count
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        JOIN products p ON oi.product_id = p.id
        WHERE p.creator_id = ? 
            AND YEAR(o.created_at) = ?
            AND o.order_status IN ('confirmed', 'processing', 'shipped', 'completed')
        GROUP BY MONTH(o.created_at)
    ");
    $stmt->execute([$creator['id'], $targetYear]);
    while ($row = $stmt->fetch()) {
        $productSalesRaw[$row['month']] = [
            'sales' => (int)$row['sales'],
            'count' => (int)$row['order_count']
        ];
    }
} catch (PDOException $e) {}

// サービス売上（取引ベース）
$serviceSalesRaw = [];
try {
    $stmt = $db->prepare("
        SELECT 
            MONTH(paid_at) as month,
            SUM(total_amount) as sales,
            COUNT(*) as transaction_count
        FROM service_transactions
        WHERE creator_id = ? 
            AND YEAR(paid_at) = ?
            AND status IN ('paid', 'in_progress', 'delivered', 'completed')
        GROUP BY MONTH(paid_at)
    ");
    $stmt->execute([$creator['id'], $targetYear]);
    while ($row = $stmt->fetch()) {
        $serviceSalesRaw[$row['month']] = [
            'sales' => (int)$row['sales'],
            'count' => (int)$row['transaction_count']
        ];
    }
} catch (PDOException $e) {}

// 12ヶ月分のデータを整形（手数料・源泉徴収計算込み）
for ($m = 1; $m <= 12; $m++) {
    $productSales = $productSalesRaw[$m]['sales'] ?? 0;
    $productCount = $productSalesRaw[$m]['count'] ?? 0;
    $serviceSales = $serviceSalesRaw[$m]['sales'] ?? 0;
    $serviceCount = $serviceSalesRaw[$m]['count'] ?? 0;
    
    // 商品手数料計算
    $productCommission = floor($productSales * $productCommissionRate / 100);
    if ($productCommissionPerItem > 0) {
        $productCommission += $productCommissionPerItem * $productCount;
    }
    $productNet = $productSales - $productCommission;
    
    // サービス手数料計算
    $serviceCommission = floor($serviceSales * $serviceCommissionRate / 100);
    if ($serviceCommissionPerItem > 0) {
        $serviceCommission += $serviceCommissionPerItem * $serviceCount;
    }
    $serviceNet = $serviceSales - $serviceCommission;
    
    // 源泉徴収計算（10.21%）
    $totalNet = $productNet + $serviceNet;
    $withholdingTax = 0;
    if ($withholdingTaxRequired && $totalNet > 0) {
        $withholdingTax = floor($totalNet * 0.1021);
    }
    
    $monthlyData[$m] = [
        'product_sales' => $productSales,
        'product_count' => $productCount,
        'product_commission' => $productCommission,
        'product_net' => $productNet,
        'service_sales' => $serviceSales,
        'service_count' => $serviceCount,
        'service_commission' => $serviceCommission,
        'service_net' => $serviceNet,
        'total_sales' => $productSales + $serviceSales,
        'total_commission' => $productCommission + $serviceCommission,
        'total_net' => $totalNet,
        'withholding_tax' => $withholdingTax,
        'payout' => $totalNet - $withholdingTax
    ];
}

// 年間合計
$yearlyTotal = [
    'product_sales' => 0,
    'product_commission' => 0,
    'product_net' => 0,
    'service_sales' => 0,
    'service_commission' => 0,
    'service_net' => 0,
    'total_sales' => 0,
    'total_commission' => 0,
    'total_net' => 0,
    'withholding_tax' => 0,
    'payout' => 0
];
foreach ($monthlyData as $data) {
    foreach ($yearlyTotal as $key => $val) {
        $yearlyTotal[$key] += $data[$key];
    }
}

// 詳細表示用（特定月が選択されている場合）
$monthDetail = null;
$monthOrders = [];
$monthTransactions = [];

if ($targetMonth) {
    $monthDetail = $monthlyData[$targetMonth] ?? null;
    
    // 商品注文詳細
    try {
        $stmt = $db->prepare("
            SELECT o.id, o.order_number, o.created_at, o.order_status,
                   SUM(oi.subtotal) as subtotal,
                   GROUP_CONCAT(CONCAT(p.name, ' x', oi.quantity) SEPARATOR ', ') as items
            FROM orders o
            JOIN order_items oi ON o.id = oi.order_id
            JOIN products p ON oi.product_id = p.id
            WHERE p.creator_id = ? 
                AND YEAR(o.created_at) = ?
                AND MONTH(o.created_at) = ?
                AND o.order_status IN ('confirmed', 'processing', 'shipped', 'completed')
            GROUP BY o.id
            ORDER BY o.created_at DESC
        ");
        $stmt->execute([$creator['id'], $targetYear, $targetMonth]);
        $monthOrders = $stmt->fetchAll();
    } catch (PDOException $e) {}
    
    // サービス取引詳細
    try {
        $stmt = $db->prepare("
            SELECT t.*, s.title as service_title
            FROM service_transactions t
            LEFT JOIN services s ON t.service_id = s.id
            WHERE t.creator_id = ? 
                AND YEAR(t.paid_at) = ?
                AND MONTH(t.paid_at) = ?
                AND t.status IN ('paid', 'in_progress', 'delivered', 'completed')
            ORDER BY t.paid_at DESC
        ");
        $stmt->execute([$creator['id'], $targetYear, $targetMonth]);
        $monthTransactions = $stmt->fetchAll();
    } catch (PDOException $e) {}
}

// 振込履歴
$paymentHistory = [];
try {
    $stmt = $db->prepare("
        SELECT 'product' as type, target_year, target_month, gross_sales, commission_amount, withholding_tax, net_payment, status, paid_at
        FROM creator_payments WHERE creator_id = ?
        UNION ALL
        SELECT 'service' as type, target_year, target_month, gross_sales, commission_amount, withholding_tax, net_payment, status, paid_at
        FROM creator_service_payments WHERE creator_id = ?
        ORDER BY target_year DESC, target_month DESC, type
        LIMIT 24
    ");
    $stmt->execute([$creator['id'], $creator['id']]);
    $paymentHistory = $stmt->fetchAll();
} catch (PDOException $e) {}

$pageTitle = '売上レポート';
require_once 'includes/header.php';
?>

<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h1 class="text-2xl font-bold text-gray-800">売上レポート</h1>
        <p class="text-gray-500 text-sm">契約に基づく売上・手数料・振込額</p>
    </div>
    
    <!-- 年選択 -->
    <div class="flex items-center gap-2">
        <a href="?year=<?= $targetYear - 1 ?>" class="px-3 py-2 bg-gray-200 rounded-lg hover:bg-gray-300 transition">
            <i class="fas fa-chevron-left"></i>
        </a>
        <span class="px-4 py-2 bg-white rounded-lg font-bold text-gray-800 shadow-sm">
            <?= $targetYear ?>年
        </span>
        <a href="?year=<?= $targetYear + 1 ?>" class="px-3 py-2 bg-gray-200 rounded-lg hover:bg-gray-300 transition">
            <i class="fas fa-chevron-right"></i>
        </a>
    </div>
</div>

<!-- 契約条件サマリー -->
<div class="bg-gradient-to-r from-green-50 to-blue-50 rounded-xl border border-green-200 p-4 mb-6">
    <div class="flex flex-wrap items-center gap-4 text-sm">
        <span class="font-bold text-gray-700"><i class="fas fa-file-contract mr-1"></i>現在の契約条件:</span>
        <span class="px-3 py-1 bg-white rounded-full text-blue-700">
            <i class="fas fa-box mr-1"></i>商品手数料 <?= $productCommissionRate ?>%
            <?= $productCommissionPerItem > 0 ? "+ ¥" . number_format($productCommissionPerItem) . "/件" : "" ?>
        </span>
        <span class="px-3 py-1 bg-white rounded-full text-purple-700">
            <i class="fas fa-paint-brush mr-1"></i>サービス手数料 <?= $serviceCommissionRate ?>%
            <?= $serviceCommissionPerItem > 0 ? "+ ¥" . number_format($serviceCommissionPerItem) . "/件" : "" ?>
        </span>
        <?php if ($withholdingTaxRequired): ?>
        <span class="px-3 py-1 bg-orange-100 rounded-full text-orange-700">
            <i class="fas fa-percent mr-1"></i>源泉徴収 10.21%
        </span>
        <?php endif; ?>
    </div>
</div>

<?php if ($targetMonth): ?>
<!-- 月別詳細表示 -->
<div class="mb-6">
    <a href="?year=<?= $targetYear ?>" class="text-blue-500 hover:underline">
        <i class="fas fa-arrow-left mr-1"></i><?= $targetYear ?>年の一覧に戻る
    </a>
</div>

<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
    <h2 class="text-xl font-bold text-gray-800 mb-4"><?= $targetYear ?>年<?= $targetMonth ?>月の売上詳細</h2>
    
    <!-- 月間サマリー -->
    <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
        <div class="bg-gray-50 rounded-lg p-4 text-center">
            <p class="text-gray-500 text-sm">総売上</p>
            <p class="text-2xl font-bold text-gray-800">¥<?= number_format($monthDetail['total_sales'] ?? 0) ?></p>
        </div>
        <div class="bg-red-50 rounded-lg p-4 text-center">
            <p class="text-gray-500 text-sm">手数料</p>
            <p class="text-2xl font-bold text-red-600">-¥<?= number_format($monthDetail['total_commission'] ?? 0) ?></p>
        </div>
        <?php if ($withholdingTaxRequired): ?>
        <div class="bg-orange-50 rounded-lg p-4 text-center">
            <p class="text-gray-500 text-sm">源泉徴収</p>
            <p class="text-2xl font-bold text-orange-600">-¥<?= number_format($monthDetail['withholding_tax'] ?? 0) ?></p>
        </div>
        <?php endif; ?>
        <div class="bg-green-50 rounded-lg p-4 text-center">
            <p class="text-gray-500 text-sm">振込予定額</p>
            <p class="text-2xl font-bold text-green-600">¥<?= number_format($monthDetail['payout'] ?? 0) ?></p>
        </div>
    </div>
    
    <!-- 商品注文一覧 -->
    <?php if (!empty($monthOrders)): ?>
    <div class="mb-6">
        <h3 class="font-bold text-gray-800 mb-3"><i class="fas fa-box text-blue-500 mr-2"></i>商品注文 (<?= count($monthOrders) ?>件)</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left">注文番号</th>
                        <th class="px-4 py-2 text-left">日時</th>
                        <th class="px-4 py-2 text-left">商品</th>
                        <th class="px-4 py-2 text-right">金額</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($monthOrders as $order): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 font-mono text-xs"><?= htmlspecialchars($order['order_number']) ?></td>
                        <td class="px-4 py-2 text-gray-600"><?= date('m/d H:i', strtotime($order['created_at'])) ?></td>
                        <td class="px-4 py-2"><?= htmlspecialchars(mb_substr($order['items'], 0, 40)) ?><?= mb_strlen($order['items']) > 40 ? '...' : '' ?></td>
                        <td class="px-4 py-2 text-right font-bold">¥<?= number_format($order['subtotal']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- サービス取引一覧 -->
    <?php if (!empty($monthTransactions)): ?>
    <div>
        <h3 class="font-bold text-gray-800 mb-3"><i class="fas fa-paint-brush text-purple-500 mr-2"></i>サービス取引 (<?= count($monthTransactions) ?>件)</h3>
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-2 text-left">取引番号</th>
                        <th class="px-4 py-2 text-left">日時</th>
                        <th class="px-4 py-2 text-left">サービス</th>
                        <th class="px-4 py-2 text-center">ステータス</th>
                        <th class="px-4 py-2 text-right">金額</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($monthTransactions as $tx): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-2 font-mono text-xs"><?= htmlspecialchars($tx['transaction_code']) ?></td>
                        <td class="px-4 py-2 text-gray-600"><?= date('m/d H:i', strtotime($tx['paid_at'])) ?></td>
                        <td class="px-4 py-2"><?= htmlspecialchars(mb_substr($tx['service_title'] ?? '不明', 0, 30)) ?></td>
                        <td class="px-4 py-2 text-center">
                            <span class="px-2 py-0.5 rounded text-xs <?= $tx['status'] === 'completed' ? 'bg-green-100 text-green-700' : 'bg-blue-100 text-blue-700' ?>">
                                <?= $tx['status'] === 'completed' ? '完了' : '進行中' ?>
                            </span>
                        </td>
                        <td class="px-4 py-2 text-right font-bold">¥<?= number_format($tx['total_amount']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>
    
    <?php if (empty($monthOrders) && empty($monthTransactions)): ?>
    <div class="text-center py-8 text-gray-400">
        <i class="fas fa-inbox text-4xl mb-2"></i>
        <p>この月の売上はありません</p>
    </div>
    <?php endif; ?>
</div>

<?php else: ?>
<!-- 年間サマリー -->
<div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-4 gap-4 mb-6">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <p class="text-gray-500 text-sm mb-1">年間総売上</p>
        <p class="text-2xl font-bold text-gray-800">¥<?= number_format($yearlyTotal['total_sales']) ?></p>
        <div class="flex gap-2 text-xs mt-2">
            <span class="text-blue-600">商品 ¥<?= number_format($yearlyTotal['product_sales']) ?></span>
            <span class="text-purple-600">サービス ¥<?= number_format($yearlyTotal['service_sales']) ?></span>
        </div>
    </div>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <p class="text-gray-500 text-sm mb-1">年間手数料</p>
        <p class="text-2xl font-bold text-red-600">-¥<?= number_format($yearlyTotal['total_commission']) ?></p>
        <p class="text-xs text-gray-400 mt-2">売上の<?= round($yearlyTotal['total_sales'] > 0 ? $yearlyTotal['total_commission'] / $yearlyTotal['total_sales'] * 100 : 0, 1) ?>%</p>
    </div>
    <?php if ($withholdingTaxRequired): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <p class="text-gray-500 text-sm mb-1">年間源泉徴収</p>
        <p class="text-2xl font-bold text-orange-600">-¥<?= number_format($yearlyTotal['withholding_tax']) ?></p>
        <p class="text-xs text-gray-400 mt-2">確定申告で精算可能</p>
    </div>
    <?php endif; ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
        <p class="text-gray-500 text-sm mb-1">年間振込額</p>
        <p class="text-2xl font-bold text-green-600">¥<?= number_format($yearlyTotal['payout']) ?></p>
        <p class="text-xs text-gray-400 mt-2">手取り金額</p>
    </div>
</div>

<!-- 月別グラフ -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 lg:p-6 mb-6">
    <h2 class="font-bold text-gray-800 mb-4">月別売上推移</h2>
    
    <div class="overflow-x-auto">
        <div class="min-w-[600px]">
            <div class="flex items-end gap-2 h-48 mb-2">
                <?php 
                $maxValue = max(1, max(array_column($monthlyData, 'total_sales')));
                foreach ($monthlyData as $month => $data): 
                    $productHeight = $data['product_sales'] / $maxValue * 100;
                    $serviceHeight = $data['service_sales'] / $maxValue * 100;
                ?>
                <a href="?year=<?= $targetYear ?>&month=<?= $month ?>" class="flex-1 flex flex-col items-center group">
                    <div class="w-full flex flex-col justify-end h-40">
                        <?php if ($data['service_sales'] > 0): ?>
                        <div class="w-full bg-purple-400 group-hover:bg-purple-500 transition rounded-t" style="height: <?= $serviceHeight ?>%"></div>
                        <?php endif; ?>
                        <?php if ($data['product_sales'] > 0): ?>
                        <div class="w-full bg-blue-400 group-hover:bg-blue-500 transition <?= $data['service_sales'] > 0 ? '' : 'rounded-t' ?>" style="height: <?= $productHeight ?>%"></div>
                        <?php endif; ?>
                        <?php if ($data['total_sales'] == 0): ?>
                        <div class="w-full bg-gray-200 rounded-t" style="height: 2px"></div>
                        <?php endif; ?>
                    </div>
                    <span class="text-xs text-gray-500 mt-2 group-hover:text-blue-600 transition"><?= $month ?>月</span>
                </a>
                <?php endforeach; ?>
            </div>
            
            <!-- 凡例 -->
            <div class="flex justify-center gap-6 mt-4">
                <div class="flex items-center gap-2">
                    <div class="w-4 h-4 bg-blue-400 rounded"></div>
                    <span class="text-sm text-gray-600">商品</span>
                </div>
                <div class="flex items-center gap-2">
                    <div class="w-4 h-4 bg-purple-400 rounded"></div>
                    <span class="text-sm text-gray-600">サービス</span>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- 月別詳細テーブル -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mb-6">
    <div class="p-4 border-b">
        <h2 class="font-bold text-gray-800">月別売上詳細（クリックで詳細表示）</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left font-bold text-gray-600">月</th>
                    <th class="px-4 py-3 text-right font-bold text-gray-600">総売上</th>
                    <th class="px-4 py-3 text-right font-bold text-gray-600">手数料</th>
                    <?php if ($withholdingTaxRequired): ?>
                    <th class="px-4 py-3 text-right font-bold text-gray-600">源泉</th>
                    <?php endif; ?>
                    <th class="px-4 py-3 text-right font-bold text-gray-600">振込額</th>
                    <th class="px-4 py-3 text-center font-bold text-gray-600"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($monthlyData as $month => $data): ?>
                <tr class="hover:bg-gray-50 <?= $data['total_sales'] > 0 ? 'cursor-pointer' : '' ?>" 
                    <?= $data['total_sales'] > 0 ? "onclick=\"location.href='?year={$targetYear}&month={$month}'\"" : '' ?>>
                    <td class="px-4 py-3 font-bold text-gray-800"><?= $month ?>月</td>
                    <td class="px-4 py-3 text-right">
                        <span class="font-bold text-gray-800">¥<?= number_format($data['total_sales']) ?></span>
                        <?php if ($data['product_sales'] > 0 || $data['service_sales'] > 0): ?>
                        <div class="text-xs text-gray-400">
                            <?php if ($data['product_sales'] > 0): ?>商品 ¥<?= number_format($data['product_sales']) ?><?php endif; ?>
                            <?php if ($data['product_sales'] > 0 && $data['service_sales'] > 0): ?> / <?php endif; ?>
                            <?php if ($data['service_sales'] > 0): ?>サービス ¥<?= number_format($data['service_sales']) ?><?php endif; ?>
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right text-red-600">-¥<?= number_format($data['total_commission']) ?></td>
                    <?php if ($withholdingTaxRequired): ?>
                    <td class="px-4 py-3 text-right text-orange-600">-¥<?= number_format($data['withholding_tax']) ?></td>
                    <?php endif; ?>
                    <td class="px-4 py-3 text-right font-bold text-green-600">¥<?= number_format($data['payout']) ?></td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($data['total_sales'] > 0): ?>
                        <i class="fas fa-chevron-right text-gray-400"></i>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <tr class="bg-gray-50 font-bold">
                    <td class="px-4 py-3 text-gray-800">年間合計</td>
                    <td class="px-4 py-3 text-right text-gray-800">¥<?= number_format($yearlyTotal['total_sales']) ?></td>
                    <td class="px-4 py-3 text-right text-red-600">-¥<?= number_format($yearlyTotal['total_commission']) ?></td>
                    <?php if ($withholdingTaxRequired): ?>
                    <td class="px-4 py-3 text-right text-orange-600">-¥<?= number_format($yearlyTotal['withholding_tax']) ?></td>
                    <?php endif; ?>
                    <td class="px-4 py-3 text-right text-green-600">¥<?= number_format($yearlyTotal['payout']) ?></td>
                    <td class="px-4 py-3"></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<!-- 振込履歴 -->
<?php if (!empty($paymentHistory)): ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mb-6">
    <div class="p-4 border-b">
        <h2 class="font-bold text-gray-800"><i class="fas fa-university text-green-500 mr-2"></i>振込履歴</h2>
    </div>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left font-bold text-gray-600">対象月</th>
                    <th class="px-4 py-3 text-left font-bold text-gray-600">種別</th>
                    <th class="px-4 py-3 text-right font-bold text-gray-600">売上</th>
                    <th class="px-4 py-3 text-right font-bold text-gray-600">手数料</th>
                    <th class="px-4 py-3 text-right font-bold text-gray-600">振込額</th>
                    <th class="px-4 py-3 text-center font-bold text-gray-600">状態</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($paymentHistory as $payment): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3 font-bold"><?= $payment['target_year'] ?>年<?= $payment['target_month'] ?>月</td>
                    <td class="px-4 py-3">
                        <?php if ($payment['type'] === 'product'): ?>
                        <span class="text-blue-600"><i class="fas fa-box mr-1"></i>商品</span>
                        <?php else: ?>
                        <span class="text-purple-600"><i class="fas fa-paint-brush mr-1"></i>サービス</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right">¥<?= number_format($payment['gross_sales']) ?></td>
                    <td class="px-4 py-3 text-right text-red-600">-¥<?= number_format($payment['commission_amount']) ?></td>
                    <td class="px-4 py-3 text-right font-bold text-green-600">¥<?= number_format($payment['net_payment']) ?></td>
                    <td class="px-4 py-3 text-center">
                        <span class="px-2 py-1 rounded text-xs font-bold 
                            <?= $payment['status'] === 'paid' ? 'bg-green-100 text-green-700' : 
                               ($payment['status'] === 'confirmed' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600') ?>">
                            <?= $payment['status'] === 'paid' ? '振込済' : ($payment['status'] === 'confirmed' ? '確定' : '処理中') ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>
<?php endif; ?>

<?php endif; ?>

<!-- 注意事項 -->
<div class="bg-yellow-50 rounded-lg p-4">
    <p class="text-sm text-yellow-800">
        <i class="fas fa-info-circle mr-1"></i>
        <strong>振込について:</strong>
        売上は月末締め、翌月末払いとなります。振込手数料は運営負担です。
        源泉徴収税は確定申告時に精算できます。詳細は<a href="contracts.php" class="underline">契約書</a>をご確認ください。
    </p>
</div>

<?php require_once 'includes/footer.php'; ?>
