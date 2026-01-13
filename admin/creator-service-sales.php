<?php
/**
 * クリエイター サービス売上・支払管理
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/site-settings.php';
require_once '../includes/mail.php';
requireAuth();

$db = getDB();
$message = '';
$error = '';

// 対象年月
$targetYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$targetMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');

// 期間計算
$startDate = sprintf('%04d-%02d-01 00:00:00', $targetYear, $targetMonth);
$endDate = date('Y-m-t 23:59:59', strtotime($startDate));
$displayMonth = sprintf('%d年%d月', $targetYear, $targetMonth);

// 納付期限計算（翌月10日）
$dueDate = date('Y-m-10', strtotime('+1 month', strtotime($startDate)));

// サイト設定
$settings = getSiteSettings();
$siteName = $settings['site_name'] ?? 'ショップ';
$shopName = getSiteSetting($db, 'store_business_name', $siteName);

// 支払確定処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    $creatorId = (int)$_POST['creator_id'];
    $grossSales = (int)$_POST['gross_sales'];
    $commission = (int)$_POST['commission'];
    $withholdingTax = (int)$_POST['withholding_tax'];
    $netPayment = (int)$_POST['net_payment'];
    $transactionCount = (int)$_POST['transaction_count'];
    
    try {
        // 既存のレコードを確認
        $stmt = $db->prepare("SELECT * FROM creator_service_payments WHERE creator_id = ? AND target_year = ? AND target_month = ?");
        $stmt->execute([$creatorId, $targetYear, $targetMonth]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            $stmt = $db->prepare("UPDATE creator_service_payments SET 
                gross_sales = ?, commission_amount = ?, withholding_tax = ?, net_payment = ?,
                transaction_count = ?, status = 'confirmed', confirmed_at = NOW()
                WHERE id = ?");
            $stmt->execute([$grossSales, $commission, $withholdingTax, $netPayment, $transactionCount, $existing['id']]);
        } else {
            $stmt = $db->prepare("INSERT INTO creator_service_payments 
                (creator_id, target_year, target_month, gross_sales, commission_amount, withholding_tax, net_payment, transaction_count, status, confirmed_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'confirmed', NOW())");
            $stmt->execute([$creatorId, $targetYear, $targetMonth, $grossSales, $commission, $withholdingTax, $netPayment, $transactionCount]);
        }
        
        $message = '支払を確定しました。';
    } catch (Exception $e) {
        $error = '確定処理に失敗しました: ' . $e->getMessage();
    }
}

// 支払済みマーク
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mark_paid'])) {
    $creatorId = (int)$_POST['creator_id'];
    
    try {
        $stmt = $db->prepare("UPDATE creator_service_payments SET status = 'paid', paid_at = NOW() 
            WHERE creator_id = ? AND target_year = ? AND target_month = ?");
        $stmt->execute([$creatorId, $targetYear, $targetMonth]);
        $message = '支払済みにしました。';
    } catch (Exception $e) {
        $error = '処理に失敗しました。';
    }
}

// クリエイターごとのサービス売上を集計
$creatorSales = [];
try {
    // 完了した取引を取得
    $stmt = $db->prepare("
        SELECT 
            t.creator_id,
            c.name as creator_name,
            c.email as creator_email,
            c.image as creator_image,
            c.service_commission_rate,
            c.service_commission_per_item,
            c.business_type,
            c.withholding_tax_required,
            c.bank_name,
            c.bank_branch,
            c.bank_account_type,
            c.bank_account_number,
            c.bank_account_name,
            COUNT(t.id) as transaction_count,
            SUM(t.total_amount) as total_sales
        FROM service_transactions t
        JOIN creators c ON t.creator_id = c.id
        WHERE t.status IN ('completed', 'delivered', 'paid', 'in_progress')
            AND t.paid_at >= ? AND t.paid_at <= ?
        GROUP BY t.creator_id
        ORDER BY total_sales DESC
    ");
    $stmt->execute([$startDate, $endDate]);
    $salesData = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($salesData as $data) {
        $creatorId = $data['creator_id'];
        $totalSales = (int)$data['total_sales'];
        $transactionCount = (int)$data['transaction_count'];
        
        // 手数料計算
        $commissionRate = (float)($data['service_commission_rate'] ?? 15);
        $commissionPerItem = (int)($data['service_commission_per_item'] ?? 0);
        
        $commissionByRate = floor($totalSales * $commissionRate / 100);
        $commissionByItem = $commissionPerItem * $transactionCount;
        $totalCommission = $commissionByRate + $commissionByItem;
        
        // 源泉徴収計算（個人かつ源泉徴収必要な場合）
        $withholdingTax = 0;
        if ($data['business_type'] === 'individual' && $data['withholding_tax_required']) {
            $taxableAmount = $totalSales - $totalCommission;
            $withholdingTax = floor($taxableAmount * 0.1021);
        }
        
        // 支払額
        $netPayment = $totalSales - $totalCommission - $withholdingTax;
        
        // 支払ステータスを確認
        $stmt = $db->prepare("SELECT * FROM creator_service_payments WHERE creator_id = ? AND target_year = ? AND target_month = ?");
        $stmt->execute([$creatorId, $targetYear, $targetMonth]);
        $paymentRecord = $stmt->fetch();
        
        $creatorSales[] = [
            'creator_id' => $creatorId,
            'creator_name' => $data['creator_name'],
            'creator_email' => $data['creator_email'],
            'creator_image' => $data['creator_image'],
            'commission_rate' => $commissionRate,
            'commission_per_item' => $commissionPerItem,
            'business_type' => $data['business_type'],
            'withholding_tax_required' => $data['withholding_tax_required'],
            'bank_name' => $data['bank_name'],
            'bank_branch' => $data['bank_branch'],
            'bank_account_type' => $data['bank_account_type'],
            'bank_account_number' => $data['bank_account_number'],
            'bank_account_name' => $data['bank_account_name'],
            'transaction_count' => $transactionCount,
            'total_sales' => $totalSales,
            'commission' => $totalCommission,
            'withholding_tax' => $withholdingTax,
            'net_payment' => $netPayment,
            'status' => $paymentRecord['status'] ?? 'pending',
            'confirmed_at' => $paymentRecord['confirmed_at'] ?? null,
            'paid_at' => $paymentRecord['paid_at'] ?? null,
        ];
    }
} catch (PDOException $e) {
    // テーブルがない場合は空のまま
}

// 集計
$totalSales = array_sum(array_column($creatorSales, 'total_sales'));
$totalCommission = array_sum(array_column($creatorSales, 'commission'));
$totalWithholding = array_sum(array_column($creatorSales, 'withholding_tax'));
$totalPayment = array_sum(array_column($creatorSales, 'net_payment'));
$totalTransactions = array_sum(array_column($creatorSales, 'transaction_count'));

$pageTitle = 'サービス売上管理';
include 'includes/header.php';
?>

<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">
    <div>
        <h2 class="text-2xl font-bold text-gray-800">
            <i class="fas fa-paint-brush text-purple-500 mr-2"></i>サービス売上・支払管理
        </h2>
        <p class="text-gray-500 text-sm">スキル販売の売上集計と支払管理</p>
    </div>
    
    <!-- 月選択 -->
    <div class="flex items-center gap-2">
        <a href="?year=<?= $targetMonth == 1 ? $targetYear - 1 : $targetYear ?>&month=<?= $targetMonth == 1 ? 12 : $targetMonth - 1 ?>"
           class="px-3 py-2 bg-gray-200 rounded-lg hover:bg-gray-300 transition">
            <i class="fas fa-chevron-left"></i>
        </a>
        <span class="px-4 py-2 bg-white rounded-lg font-bold text-gray-800 shadow-sm">
            <?= $displayMonth ?>
        </span>
        <a href="?year=<?= $targetMonth == 12 ? $targetYear + 1 : $targetYear ?>&month=<?= $targetMonth == 12 ? 1 : $targetMonth + 1 ?>"
           class="px-3 py-2 bg-gray-200 rounded-lg hover:bg-gray-300 transition">
            <i class="fas fa-chevron-right"></i>
        </a>
    </div>
</div>

<?php if ($message): ?>
<div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
    <i class="fas fa-check-circle text-green-500 mr-2"></i><?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
    <i class="fas fa-exclamation-circle text-red-500 mr-2"></i><?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<!-- サマリー -->
<div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-6">
    <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-100">
        <p class="text-gray-500 text-sm mb-1">売上総額</p>
        <p class="text-2xl font-bold text-gray-800">¥<?= number_format($totalSales) ?></p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-100">
        <p class="text-gray-500 text-sm mb-1">手数料収入</p>
        <p class="text-2xl font-bold text-purple-600">¥<?= number_format($totalCommission) ?></p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-100">
        <p class="text-gray-500 text-sm mb-1">源泉徴収税</p>
        <p class="text-2xl font-bold text-orange-600">¥<?= number_format($totalWithholding) ?></p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-100">
        <p class="text-gray-500 text-sm mb-1">支払総額</p>
        <p class="text-2xl font-bold text-green-600">¥<?= number_format($totalPayment) ?></p>
    </div>
    <div class="bg-white rounded-xl p-4 shadow-sm border border-gray-100">
        <p class="text-gray-500 text-sm mb-1">取引件数</p>
        <p class="text-2xl font-bold text-blue-600"><?= number_format($totalTransactions) ?>件</p>
    </div>
</div>

<!-- クリエイター別売上 -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="p-4 border-b border-gray-100 flex items-center justify-between">
        <h3 class="font-bold text-gray-800">
            <i class="fas fa-users text-purple-500 mr-2"></i>クリエイター別売上
        </h3>
        <span class="text-sm text-gray-500"><?= count($creatorSales) ?>名</span>
    </div>
    
    <?php if (empty($creatorSales)): ?>
    <div class="p-8 text-center text-gray-400">
        <i class="fas fa-chart-line text-4xl mb-4"></i>
        <p>この月のサービス売上はありません</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">クリエイター</th>
                    <th class="px-4 py-3 text-right text-sm font-bold text-gray-600">取引数</th>
                    <th class="px-4 py-3 text-right text-sm font-bold text-gray-600">売上</th>
                    <th class="px-4 py-3 text-right text-sm font-bold text-gray-600">手数料</th>
                    <th class="px-4 py-3 text-right text-sm font-bold text-gray-600">源泉税</th>
                    <th class="px-4 py-3 text-right text-sm font-bold text-gray-600">支払額</th>
                    <th class="px-4 py-3 text-center text-sm font-bold text-gray-600">ステータス</th>
                    <th class="px-4 py-3 text-center text-sm font-bold text-gray-600">操作</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($creatorSales as $data): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <div class="flex items-center gap-3">
                            <?php if ($data['creator_image']): ?>
                            <img src="../<?= htmlspecialchars($data['creator_image']) ?>" class="w-10 h-10 rounded-full object-cover">
                            <?php else: ?>
                            <div class="w-10 h-10 bg-gray-200 rounded-full flex items-center justify-center">
                                <i class="fas fa-user text-gray-400"></i>
                            </div>
                            <?php endif; ?>
                            <div>
                                <p class="font-bold text-gray-800"><?= htmlspecialchars($data['creator_name']) ?></p>
                                <p class="text-xs text-gray-500">
                                    手数料: <?= $data['commission_rate'] ?>%
                                    <?php if ($data['commission_per_item']): ?>
                                    + ¥<?= number_format($data['commission_per_item']) ?>/件
                                    <?php endif; ?>
                                </p>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-right text-gray-800"><?= $data['transaction_count'] ?>件</td>
                    <td class="px-4 py-3 text-right font-bold text-gray-800">¥<?= number_format($data['total_sales']) ?></td>
                    <td class="px-4 py-3 text-right text-red-600">-¥<?= number_format($data['commission']) ?></td>
                    <td class="px-4 py-3 text-right text-orange-600">
                        <?php if ($data['withholding_tax']): ?>
                        -¥<?= number_format($data['withholding_tax']) ?>
                        <?php else: ?>
                        <span class="text-gray-400">-</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-right font-bold text-green-600">¥<?= number_format($data['net_payment']) ?></td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($data['status'] === 'paid'): ?>
                        <span class="px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-bold">
                            <i class="fas fa-check mr-1"></i>支払済
                        </span>
                        <?php elseif ($data['status'] === 'confirmed'): ?>
                        <span class="px-2 py-1 bg-blue-100 text-blue-700 rounded-full text-xs font-bold">
                            <i class="fas fa-check mr-1"></i>確定済
                        </span>
                        <?php else: ?>
                        <span class="px-2 py-1 bg-gray-100 text-gray-600 rounded-full text-xs font-bold">未確定</span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($data['status'] === 'pending'): ?>
                        <form method="POST" class="inline">
                            <input type="hidden" name="creator_id" value="<?= $data['creator_id'] ?>">
                            <input type="hidden" name="gross_sales" value="<?= $data['total_sales'] ?>">
                            <input type="hidden" name="commission" value="<?= $data['commission'] ?>">
                            <input type="hidden" name="withholding_tax" value="<?= $data['withholding_tax'] ?>">
                            <input type="hidden" name="net_payment" value="<?= $data['net_payment'] ?>">
                            <input type="hidden" name="transaction_count" value="<?= $data['transaction_count'] ?>">
                            <button type="submit" name="confirm_payment" value="1"
                                    class="px-3 py-1 bg-blue-500 text-white rounded text-xs hover:bg-blue-600">
                                確定
                            </button>
                        </form>
                        <?php elseif ($data['status'] === 'confirmed'): ?>
                        <form method="POST" class="inline">
                            <input type="hidden" name="creator_id" value="<?= $data['creator_id'] ?>">
                            <button type="submit" name="mark_paid" value="1"
                                    class="px-3 py-1 bg-green-500 text-white rounded text-xs hover:bg-green-600">
                                支払済
                            </button>
                        </form>
                        <?php else: ?>
                        <span class="text-gray-400 text-xs"><?= date('m/d', strtotime($data['paid_at'])) ?></span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<!-- 取引詳細一覧 -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden mt-6">
    <div class="p-4 border-b border-gray-100">
        <h3 class="font-bold text-gray-800">
            <i class="fas fa-list text-gray-500 mr-2"></i>取引詳細一覧（<?= $displayMonth ?>）
        </h3>
    </div>
    
    <?php
    // 取引詳細を取得
    $transactions = [];
    try {
        $stmt = $db->prepare("
            SELECT t.*, 
                   s.title as service_title,
                   c.name as creator_name,
                   COALESCE(m.name, t.guest_name) as customer_name
            FROM service_transactions t
            LEFT JOIN services s ON t.service_id = s.id
            LEFT JOIN creators c ON t.creator_id = c.id
            LEFT JOIN store_members m ON t.member_id = m.id
            WHERE t.status IN ('completed', 'delivered', 'paid', 'in_progress')
                AND t.paid_at >= ? AND t.paid_at <= ?
            ORDER BY t.paid_at DESC
        ");
        $stmt->execute([$startDate, $endDate]);
        $transactions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {}
    ?>
    
    <?php if (empty($transactions)): ?>
    <div class="p-8 text-center text-gray-400">
        <p>この月の取引はありません</p>
    </div>
    <?php else: ?>
    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead class="bg-gray-50">
                <tr>
                    <th class="px-4 py-2 text-left">取引コード</th>
                    <th class="px-4 py-2 text-left">サービス</th>
                    <th class="px-4 py-2 text-left">クリエイター</th>
                    <th class="px-4 py-2 text-left">顧客</th>
                    <th class="px-4 py-2 text-right">金額</th>
                    <th class="px-4 py-2 text-center">決済日</th>
                    <th class="px-4 py-2 text-center">ステータス</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($transactions as $trans): ?>
                <tr class="hover:bg-gray-50">
                    <td class="px-4 py-2 font-mono text-xs"><?= htmlspecialchars($trans['transaction_code']) ?></td>
                    <td class="px-4 py-2 truncate max-w-[200px]"><?= htmlspecialchars($trans['service_title'] ?? '---') ?></td>
                    <td class="px-4 py-2"><?= htmlspecialchars($trans['creator_name'] ?? '---') ?></td>
                    <td class="px-4 py-2"><?= htmlspecialchars($trans['customer_name'] ?? '---') ?></td>
                    <td class="px-4 py-2 text-right font-bold">¥<?= number_format($trans['total_amount'] ?? 0) ?></td>
                    <td class="px-4 py-2 text-center text-gray-500"><?= $trans['paid_at'] ? date('m/d H:i', strtotime($trans['paid_at'])) : '-' ?></td>
                    <td class="px-4 py-2 text-center">
                        <?php
                        $statusLabels = [
                            'paid' => ['決済完了', 'bg-blue-100 text-blue-700'],
                            'in_progress' => ['制作中', 'bg-yellow-100 text-yellow-700'],
                            'delivered' => ['納品済', 'bg-purple-100 text-purple-700'],
                            'completed' => ['完了', 'bg-green-100 text-green-700'],
                        ];
                        $st = $statusLabels[$trans['status']] ?? [$trans['status'], 'bg-gray-100 text-gray-700'];
                        ?>
                        <span class="px-2 py-0.5 rounded text-xs font-bold <?= $st[1] ?>"><?= $st[0] ?></span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<?php include 'includes/footer.php'; ?>
