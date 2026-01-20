<?php
/**
 * クリエイターダッシュボード トップページ
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/csrf.php';
require_once '../includes/creator-auth.php';
require_once '../includes/formatting.php';
require_once '../includes/transactions.php';

$creator = requireCreatorAuth();
$db = getDB();

// 統計情報
$stats = [
    'pending' => 0,
    'in_progress' => 0,
    'completed_month' => 0,
    'earnings_service_month' => 0,
    'earnings_product_month' => 0,
    'unread_messages' => 0
];

try {
    // サービス: 対応待ち
    $stmt = $db->prepare("SELECT COUNT(*) FROM service_transactions WHERE creator_id = ? AND status IN ('inquiry', 'quote_revision')");
    $stmt->execute([$creator['id']]);
    $stats['pending'] = $stmt->fetchColumn();
    
    // サービス: 制作中
    $stmt = $db->prepare("SELECT COUNT(*) FROM service_transactions WHERE creator_id = ? AND status IN ('paid', 'in_progress', 'revision_requested')");
    $stmt->execute([$creator['id']]);
    $stats['in_progress'] = $stmt->fetchColumn();
    
    // サービス: 今月完了
    $stmt = $db->prepare("SELECT COUNT(*) FROM service_transactions WHERE creator_id = ? AND status = 'completed' AND completed_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
    $stmt->execute([$creator['id']]);
    $stats['completed_month'] = $stmt->fetchColumn();
    
    // サービス: 今月売上
    $stmt = $db->prepare("SELECT COALESCE(SUM(total_amount), 0) FROM service_transactions WHERE creator_id = ? AND status IN ('paid', 'in_progress', 'delivered', 'completed') AND paid_at >= DATE_FORMAT(NOW(), '%Y-%m-01')");
    $stmt->execute([$creator['id']]);
    $stats['earnings_service_month'] = $stmt->fetchColumn();
    
    // 商品: 今月売上
    $stmt = $db->prepare("
        SELECT COALESCE(SUM(oi.subtotal), 0) 
        FROM order_items oi
        JOIN orders o ON oi.order_id = o.id
        JOIN products p ON oi.product_id = p.id
        WHERE p.creator_id = ? 
            AND o.created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
            AND o.order_status IN ('confirmed', 'processing', 'shipped', 'completed')
    ");
    $stmt->execute([$creator['id']]);
    $stats['earnings_product_month'] = $stmt->fetchColumn();
    
    // 未読メッセージ
    $stmt = $db->prepare("
        SELECT COUNT(*) FROM service_messages m
        JOIN service_transactions t ON m.transaction_id = t.id
        WHERE t.creator_id = ? AND m.read_by_creator = 0 AND m.sender_type != 'creator'
    ");
    $stmt->execute([$creator['id']]);
    $stats['unread_messages'] = $stmt->fetchColumn();
} catch (PDOException $e) {}

// 最近の取引
$recentTransactions = [];
try {
    $stmt = $db->prepare("
        SELECT t.*, 
               s.title as service_title,
               COALESCE(m.name, t.guest_name) as customer_name,
               0 as unread_count
        FROM service_transactions t
        LEFT JOIN services s ON t.service_id = s.id
        LEFT JOIN store_members m ON t.member_id = m.id
        WHERE t.creator_id = ?
        ORDER BY t.updated_at DESC
        LIMIT 5
    ");
    $stmt->execute([$creator['id']]);
    $recentTransactions = $stmt->fetchAll();
} catch (PDOException $e) {}

// 最近の商品注文
$recentOrders = [];
try {
    $stmt = $db->prepare("
        SELECT DISTINCT o.id, o.order_number, o.created_at, o.total_amount,
               GROUP_CONCAT(p.name SEPARATOR ', ') as product_names
        FROM orders o
        JOIN order_items oi ON o.id = oi.order_id
        JOIN products p ON oi.product_id = p.id
        WHERE p.creator_id = ?
        GROUP BY o.id
        ORDER BY o.created_at DESC
        LIMIT 5
    ");
    $stmt->execute([$creator['id']]);
    $recentOrders = $stmt->fetchAll();
} catch (PDOException $e) {}

// 今月の合計
$totalEarningsMonth = $stats['earnings_service_month'] + $stats['earnings_product_month'];

$pageTitle = 'ダッシュボード';
require_once 'includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-800">ダッシュボード</h1>
    <p class="text-gray-500">おかえりなさい、<?= htmlspecialchars($creator['name']) ?>さん</p>
</div>

<!-- 今月の売上サマリー -->
<div class="bg-gradient-to-r from-green-500 to-emerald-600 rounded-xl p-4 lg:p-6 text-white mb-6">
    <p class="text-sm text-green-100 mb-1">今月の売上（<?= date('n') ?>月）</p>
    <p class="text-3xl lg:text-4xl font-bold"><?= formatPrice($totalEarningsMonth) ?></p>
    <div class="flex gap-6 mt-3 text-sm">
        <div>
            <span class="text-green-200">サービス:</span>
            <span class="font-bold"><?= formatPrice($stats['earnings_service_month']) ?></span>
        </div>
        <div>
            <span class="text-green-200">商品:</span>
            <span class="font-bold"><?= formatPrice($stats['earnings_product_month']) ?></span>
        </div>
    </div>
</div>

<!-- 統計カード -->
<div class="grid grid-cols-2 lg:grid-cols-4 gap-3 lg:gap-4 mb-8">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-3 lg:p-4">
        <div class="flex items-center justify-between">
            <div class="min-w-0">
                <p class="text-xs lg:text-sm text-gray-500">対応待ち</p>
                <p class="text-xl lg:text-2xl font-bold text-yellow-600"><?= $stats['pending'] ?></p>
            </div>
            <div class="w-10 h-10 lg:w-12 lg:h-12 bg-yellow-100 rounded-lg flex items-center justify-center flex-shrink-0">
                <i class="fas fa-clock text-yellow-600 text-lg lg:text-xl"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-3 lg:p-4">
        <div class="flex items-center justify-between">
            <div class="min-w-0">
                <p class="text-xs lg:text-sm text-gray-500">制作中</p>
                <p class="text-xl lg:text-2xl font-bold text-blue-600"><?= $stats['in_progress'] ?></p>
            </div>
            <div class="w-10 h-10 lg:w-12 lg:h-12 bg-blue-100 rounded-lg flex items-center justify-center flex-shrink-0">
                <i class="fas fa-palette text-blue-600 text-lg lg:text-xl"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-3 lg:p-4">
        <div class="flex items-center justify-between">
            <div class="min-w-0">
                <p class="text-xs lg:text-sm text-gray-500">今月完了</p>
                <p class="text-xl lg:text-2xl font-bold text-green-600"><?= $stats['completed_month'] ?></p>
            </div>
            <div class="w-10 h-10 lg:w-12 lg:h-12 bg-green-100 rounded-lg flex items-center justify-center flex-shrink-0">
                <i class="fas fa-check-circle text-green-600 text-lg lg:text-xl"></i>
            </div>
        </div>
    </div>
    
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-3 lg:p-4">
        <div class="flex items-center justify-between">
            <div class="min-w-0">
                <p class="text-xs lg:text-sm text-gray-500">未読メッセージ</p>
                <p class="text-xl lg:text-2xl font-bold <?= $stats['unread_messages'] > 0 ? 'text-red-600' : 'text-gray-400' ?>"><?= $stats['unread_messages'] ?></p>
            </div>
            <div class="w-10 h-10 lg:w-12 lg:h-12 <?= $stats['unread_messages'] > 0 ? 'bg-red-100' : 'bg-gray-100' ?> rounded-lg flex items-center justify-center flex-shrink-0">
                <i class="fas fa-envelope <?= $stats['unread_messages'] > 0 ? 'text-red-600' : 'text-gray-400' ?> text-lg lg:text-xl"></i>
            </div>
        </div>
    </div>
</div>

<!-- 取引リスト -->
<div class="grid lg:grid-cols-2 gap-6">
    <!-- 最近のサービス取引 -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="p-4 border-b flex items-center justify-between">
            <h2 class="font-bold text-gray-800">
                <i class="fas fa-handshake text-green-500 mr-2"></i>サービス取引
            </h2>
            <a href="/creator-dashboard/transactions.php" class="text-sm text-green-600 hover:underline">
                すべて見る <i class="fas fa-arrow-right ml-1"></i>
            </a>
        </div>
        
        <?php if (empty($recentTransactions)): ?>
        <div class="p-8 text-center text-gray-500">
            <i class="fas fa-inbox text-gray-300 text-4xl mb-3"></i>
            <p>まだ取引がありません</p>
        </div>
        <?php else: ?>
        <div class="divide-y divide-gray-100">
            <?php foreach ($recentTransactions as $t): ?>
            <a href="/creator-dashboard/transaction-detail.php?id=<?= $t['id'] ?>" class="flex items-center gap-4 p-4 hover:bg-gray-50 transition">
                <div class="flex-1 min-w-0">
                    <div class="flex items-center gap-2">
                        <span class="font-bold text-gray-800 truncate text-sm"><?= htmlspecialchars($t['service_title'] ?? '取引') ?></span>
                    </div>
                    <div class="flex items-center gap-3 text-xs text-gray-500 mt-1">
                        <span><?= htmlspecialchars($t['customer_name'] ?? 'ゲスト') ?></span>
                        <span class="text-gray-300">|</span>
                        <span><?= date('n/j H:i', strtotime($t['updated_at'])) ?></span>
                    </div>
                </div>
                <span class="px-2 py-1 rounded-full text-xs font-bold <?= getTransactionStatusColor($t['status']) ?>">
                    <?= getTransactionStatusLabel($t['status']) ?>
                </span>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- 最近の商品注文 -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
        <div class="p-4 border-b flex items-center justify-between">
            <h2 class="font-bold text-gray-800">
                <i class="fas fa-box text-blue-500 mr-2"></i>商品注文
            </h2>
            <a href="/creator-dashboard/earnings.php" class="text-sm text-blue-600 hover:underline">
                売上を見る <i class="fas fa-arrow-right ml-1"></i>
            </a>
        </div>
        
        <?php if (empty($recentOrders)): ?>
        <div class="p-8 text-center text-gray-500">
            <i class="fas fa-box-open text-gray-300 text-4xl mb-3"></i>
            <p>まだ注文がありません</p>
        </div>
        <?php else: ?>
        <div class="divide-y divide-gray-100">
            <?php foreach ($recentOrders as $order): ?>
            <div class="p-4">
                <div class="flex items-center justify-between">
                    <div class="min-w-0 flex-1">
                        <p class="font-bold text-gray-800 text-sm truncate"><?= htmlspecialchars($order['product_names']) ?></p>
                        <p class="text-xs text-gray-500 mt-1">
                            #<?= htmlspecialchars($order['order_number']) ?> • <?= date('n/j H:i', strtotime($order['created_at'])) ?>
                        </p>
                    </div>
                    <span class="text-green-600 font-bold text-sm"><?= formatPrice($order['total_amount']) ?></span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- クイックリンク -->
<div class="mt-6 grid grid-cols-2 lg:grid-cols-4 gap-3">
    <a href="/creator-dashboard/services.php" class="bg-white rounded-lg p-4 border border-gray-100 hover:border-green-300 hover:shadow-md transition text-center">
        <i class="fas fa-paint-brush text-purple-500 text-2xl mb-2"></i>
        <p class="text-sm font-bold text-gray-700">サービス管理</p>
    </a>
    <a href="/creator-dashboard/works.php" class="bg-white rounded-lg p-4 border border-gray-100 hover:border-green-300 hover:shadow-md transition text-center">
        <i class="fas fa-images text-blue-500 text-2xl mb-2"></i>
        <p class="text-sm font-bold text-gray-700">作品管理</p>
    </a>
    <a href="/creator-dashboard/profile.php" class="bg-white rounded-lg p-4 border border-gray-100 hover:border-green-300 hover:shadow-md transition text-center">
        <i class="fas fa-user-circle text-green-500 text-2xl mb-2"></i>
        <p class="text-sm font-bold text-gray-700">プロフィール</p>
    </a>
    <a href="/creator/<?= htmlspecialchars($creator['slug'] ?? $creator['id']) ?>" target="_blank" class="bg-white rounded-lg p-4 border border-gray-100 hover:border-green-300 hover:shadow-md transition text-center">
        <i class="fas fa-external-link-alt text-gray-500 text-2xl mb-2"></i>
        <p class="text-sm font-bold text-gray-700">公開ページ</p>
    </a>
</div>

<?php require_once 'includes/footer.php'; ?>
