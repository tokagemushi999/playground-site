<?php
/**
 * 管理画面ダッシュボード
 */

require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/site-settings.php';
requireAuth();

$db = getDB();

// 統計情報を取得
$stats = [
    'creators' => $db->query("SELECT COUNT(*) FROM creators WHERE is_active = 1")->fetchColumn(),
    'works' => $db->query("SELECT COUNT(*) FROM works WHERE is_active = 1")->fetchColumn(),
    'articles' => $db->query("SELECT COUNT(*) FROM articles WHERE is_active = 1")->fetchColumn(),
    'inquiries_new' => $db->query("SELECT COUNT(*) FROM inquiries WHERE status = 'new'")->fetchColumn(),
];

// ストア統計（商品販売）
$storeStats = [
    'orders_new' => 0,
    'orders_today' => 0,
    'revenue_today' => 0,
    'revenue_month' => 0,
];

try {
    $storeStats['orders_new'] = $db->query("SELECT COUNT(*) FROM orders WHERE order_status IN ('pending', 'paid') AND payment_status = 'paid'")->fetchColumn();
    $storeStats['orders_today'] = $db->query("SELECT COUNT(*) FROM orders WHERE DATE(created_at) = CURDATE() AND payment_status = 'paid'")->fetchColumn();
    $storeStats['revenue_today'] = $db->query("SELECT COALESCE(SUM(total), 0) FROM orders WHERE DATE(created_at) = CURDATE() AND payment_status = 'paid'")->fetchColumn();
    $storeStats['revenue_month'] = $db->query("SELECT COALESCE(SUM(total), 0) FROM orders WHERE YEAR(created_at) = YEAR(CURDATE()) AND MONTH(created_at) = MONTH(CURDATE()) AND payment_status = 'paid'")->fetchColumn();
} catch (PDOException $e) {}

// サービス統計（スキル販売）
$serviceStats = [
    'services_active' => 0,
    'transactions_new' => 0,
    'transactions_progress' => 0,
    'revenue_service_month' => 0,
];

try {
    // アクティブなサービス数
    $serviceStats['services_active'] = $db->query("SELECT COUNT(*) FROM services WHERE status = 'active'")->fetchColumn();
    
    // 新規見積もり依頼（inquiry状態）
    $serviceStats['transactions_new'] = $db->query("SELECT COUNT(*) FROM service_transactions WHERE status = 'inquiry'")->fetchColumn();
    
    // 進行中の取引（paid, in_progress, delivered）
    $serviceStats['transactions_progress'] = $db->query("SELECT COUNT(*) FROM service_transactions WHERE status IN ('paid', 'in_progress', 'delivered', 'revision_requested')")->fetchColumn();
    
    // 今月のサービス売上
    $serviceStats['revenue_service_month'] = $db->query("SELECT COALESCE(SUM(total_amount), 0) FROM service_transactions WHERE status IN ('paid', 'in_progress', 'delivered', 'completed') AND YEAR(paid_at) = YEAR(CURDATE()) AND MONTH(paid_at) = MONTH(CURDATE())")->fetchColumn();
} catch (PDOException $e) {}

// 審査待ち統計（db.phpの共通関数を使用）
$approvalStats = getPendingApprovalCounts();

// 最新の問い合わせ
$recentInquiries = $db->query("SELECT * FROM inquiries ORDER BY created_at DESC LIMIT 5")->fetchAll();

// 最新の注文
$recentOrders = [];
try {
    $recentOrders = $db->query("SELECT o.*, m.name as member_name FROM orders o LEFT JOIN store_members m ON o.member_id = m.id ORDER BY o.created_at DESC LIMIT 5")->fetchAll();
} catch (PDOException $e) {}

// 最新のサービス取引
$recentTransactions = [];
try {
    $recentTransactions = $db->query("
        SELECT t.*, s.title as service_title, c.name as creator_name,
               COALESCE(m.name, t.guest_name) as customer_name
        FROM service_transactions t
        LEFT JOIN services s ON t.service_id = s.id
        LEFT JOIN creators c ON t.creator_id = c.id
        LEFT JOIN store_members m ON t.member_id = m.id
        ORDER BY t.created_at DESC LIMIT 5
    ")->fetchAll();
} catch (PDOException $e) {}

$pageTitle = 'ダッシュボード';
include 'includes/header.php';
?>
        <div class="mb-8">
            <h2 class="text-2xl font-bold text-gray-800">ダッシュボード</h2>
            <p class="text-gray-500">ようこそ、<?= htmlspecialchars($_SESSION['admin_name'] ?? '管理者') ?>さん</p>
        </div>
        
        <?php if ($storeStats['orders_new'] > 0): ?>
        <!-- 新規注文アラート -->
        <div class="bg-gradient-to-r from-green-500 to-emerald-600 rounded-xl p-4 md:p-6 mb-4 text-white shadow-lg">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-white/20 rounded-full flex items-center justify-center">
                        <i class="fas fa-shopping-bag text-2xl"></i>
                    </div>
                    <div>
                        <p class="text-white/80 text-sm">新規注文があります</p>
                        <p class="text-3xl font-bold"><?= $storeStats['orders_new'] ?>件</p>
                    </div>
                </div>
                <a href="orders.php" class="bg-white text-green-600 px-4 py-2 rounded-lg font-bold hover:bg-green-50 transition">
                    注文を確認 <i class="fas fa-arrow-right ml-2"></i>
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($serviceStats['transactions_new'] > 0): ?>
        <!-- 新規見積もり依頼アラート -->
        <div class="bg-gradient-to-r from-purple-500 to-indigo-600 rounded-xl p-4 md:p-6 mb-4 text-white shadow-lg">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-white/20 rounded-full flex items-center justify-center">
                        <i class="fas fa-file-invoice text-2xl"></i>
                    </div>
                    <div>
                        <p class="text-white/80 text-sm">新規見積もり依頼</p>
                        <p class="text-3xl font-bold"><?= $serviceStats['transactions_new'] ?>件</p>
                    </div>
                </div>
                <a href="transactions.php?status=inquiry" class="bg-white text-purple-600 px-4 py-2 rounded-lg font-bold hover:bg-purple-50 transition">
                    依頼を確認 <i class="fas fa-arrow-right ml-2"></i>
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($serviceStats['transactions_progress'] > 0): ?>
        <!-- 進行中の取引 -->
        <div class="bg-gradient-to-r from-orange-400 to-amber-500 rounded-xl p-4 md:p-6 mb-4 text-white shadow-lg">
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-white/20 rounded-full flex items-center justify-center">
                        <i class="fas fa-tasks text-2xl"></i>
                    </div>
                    <div>
                        <p class="text-white/80 text-sm">進行中のサービス取引</p>
                        <p class="text-3xl font-bold"><?= $serviceStats['transactions_progress'] ?>件</p>
                    </div>
                </div>
                <a href="transactions.php" class="bg-white text-orange-600 px-4 py-2 rounded-lg font-bold hover:bg-orange-50 transition">
                    取引を確認 <i class="fas fa-arrow-right ml-2"></i>
                </a>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($approvalStats['total'] > 0): ?>
        <!-- 審査待ちアラート -->
        <div class="bg-gradient-to-r from-yellow-400 to-orange-400 rounded-xl p-4 md:p-6 mb-6 text-white shadow-lg">
            <div class="flex items-center justify-between flex-wrap gap-4">
                <div class="flex items-center gap-4">
                    <div class="w-12 h-12 bg-white/20 rounded-full flex items-center justify-center">
                        <i class="fas fa-clipboard-check text-2xl"></i>
                    </div>
                    <div>
                        <p class="text-white/80 text-sm">審査待ちコンテンツ</p>
                        <p class="text-3xl font-bold"><?= $approvalStats['total'] ?>件</p>
                    </div>
                </div>
                <div class="flex gap-2 flex-wrap">
                    <?php if ($approvalStats['services'] > 0): ?>
                    <a href="services.php?filter=pending" class="bg-white/20 hover:bg-white/30 px-3 py-2 rounded-lg font-bold transition">
                        サービス <?= $approvalStats['services'] ?>件
                    </a>
                    <?php endif; ?>
                    <?php if ($approvalStats['products'] > 0): ?>
                    <a href="products.php?filter=pending" class="bg-white/20 hover:bg-white/30 px-3 py-2 rounded-lg font-bold transition">
                        商品 <?= $approvalStats['products'] ?>件
                    </a>
                    <?php endif; ?>
                    <?php if ($approvalStats['works'] > 0): ?>
                    <a href="works.php?filter=pending" class="bg-white/20 hover:bg-white/30 px-3 py-2 rounded-lg font-bold transition">
                        作品 <?= $approvalStats['works'] ?>件
                    </a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- ストア統計 -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-6 mb-6">
            <div class="bg-white rounded-xl p-4 md:p-6 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs md:text-sm">本日の注文</p>
                        <p class="text-2xl md:text-3xl font-bold text-gray-800"><?= $storeStats['orders_today'] ?></p>
                    </div>
                    <div class="w-10 h-10 md:w-12 md:h-12 bg-green-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-shopping-cart text-green-500"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl p-4 md:p-6 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs md:text-sm">本日の売上</p>
                        <p class="text-2xl md:text-3xl font-bold text-gray-800">¥<?= number_format($storeStats['revenue_today']) ?></p>
                    </div>
                    <div class="w-10 h-10 md:w-12 md:h-12 bg-yellow-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-yen-sign text-yellow-500"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl p-4 md:p-6 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs md:text-sm">今月の売上</p>
                        <p class="text-2xl md:text-3xl font-bold text-gray-800">¥<?= number_format($storeStats['revenue_month']) ?></p>
                    </div>
                    <div class="w-10 h-10 md:w-12 md:h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-chart-line text-blue-500"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl p-4 md:p-6 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs md:text-sm">新規問い合わせ</p>
                        <p class="text-2xl md:text-3xl font-bold text-gray-800"><?= $stats['inquiries_new'] ?></p>
                    </div>
                    <div class="w-10 h-10 md:w-12 md:h-12 bg-red-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-envelope text-red-500"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- サービス統計 -->
        <h3 class="text-lg font-bold text-gray-700 mb-4"><i class="fas fa-paint-brush text-purple-500 mr-2"></i>サービス（スキル販売）</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-6 mb-6">
            <div class="bg-white rounded-xl p-4 md:p-6 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs md:text-sm">公開サービス</p>
                        <p class="text-2xl md:text-3xl font-bold text-gray-800"><?= $serviceStats['services_active'] ?></p>
                    </div>
                    <div class="w-10 h-10 md:w-12 md:h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-paint-brush text-purple-500"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl p-4 md:p-6 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs md:text-sm">見積もり依頼</p>
                        <p class="text-2xl md:text-3xl font-bold text-gray-800"><?= $serviceStats['transactions_new'] ?></p>
                    </div>
                    <div class="w-10 h-10 md:w-12 md:h-12 bg-indigo-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-file-invoice text-indigo-500"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl p-4 md:p-6 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs md:text-sm">進行中の取引</p>
                        <p class="text-2xl md:text-3xl font-bold text-gray-800"><?= $serviceStats['transactions_progress'] ?></p>
                    </div>
                    <div class="w-10 h-10 md:w-12 md:h-12 bg-orange-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-tasks text-orange-500"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl p-4 md:p-6 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs md:text-sm">今月の売上</p>
                        <p class="text-2xl md:text-3xl font-bold text-gray-800">¥<?= number_format($serviceStats['revenue_service_month']) ?></p>
                    </div>
                    <div class="w-10 h-10 md:w-12 md:h-12 bg-green-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-hand-holding-usd text-green-500"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- サイト統計 -->
        <h3 class="text-lg font-bold text-gray-700 mb-4"><i class="fas fa-globe text-blue-500 mr-2"></i>サイトコンテンツ</h3>
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-6 mb-10">
            <div class="bg-white rounded-xl p-4 md:p-6 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs md:text-sm">メンバー</p>
                        <p class="text-2xl md:text-3xl font-bold text-gray-800"><?= $stats['creators'] ?></p>
                    </div>
                    <div class="w-10 h-10 md:w-12 md:h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-users text-blue-500"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl p-4 md:p-6 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs md:text-sm">作品数</p>
                        <p class="text-2xl md:text-3xl font-bold text-gray-800"><?= $stats['works'] ?></p>
                    </div>
                    <div class="w-10 h-10 md:w-12 md:h-12 bg-green-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-images text-green-500"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl p-4 md:p-6 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs md:text-sm">記事数</p>
                        <p class="text-2xl md:text-3xl font-bold text-gray-800"><?= $stats['articles'] ?></p>
                    </div>
                    <div class="w-10 h-10 md:w-12 md:h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-newspaper text-purple-500"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl p-4 md:p-6 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs md:text-sm">コレクション</p>
                        <p class="text-2xl md:text-3xl font-bold text-gray-800"><?= $db->query("SELECT COUNT(*) FROM collections WHERE is_active = 1")->fetchColumn() ?></p>
                    </div>
                    <div class="w-10 h-10 md:w-12 md:h-12 bg-pink-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-layer-group text-pink-500"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- 最新のサービス取引 -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 mb-6">
            <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                <h3 class="font-bold text-gray-800"><i class="fas fa-handshake text-purple-500 mr-2"></i>最新のサービス取引</h3>
                <a href="transactions.php" class="text-purple-600 hover:text-purple-700 text-sm font-bold">すべて見る →</a>
            </div>
            <div class="divide-y divide-gray-100">
                <?php if (empty($recentTransactions)): ?>
                <div class="px-6 py-8 text-center text-gray-400">
                    まだサービス取引がありません
                </div>
                <?php else: ?>
                <?php foreach ($recentTransactions as $trans): ?>
                <div class="px-6 py-4 flex items-center justify-between hover:bg-gray-50">
                    <div class="flex-1 min-w-0">
                        <p class="font-bold text-gray-800 truncate"><?= htmlspecialchars($trans['transaction_code']) ?></p>
                        <p class="text-sm text-gray-500 truncate"><?= htmlspecialchars($trans['service_title'] ?? '---') ?></p>
                        <p class="text-xs text-gray-400"><?= htmlspecialchars($trans['customer_name'] ?? '---') ?> → <?= htmlspecialchars($trans['creator_name'] ?? '---') ?></p>
                    </div>
                    <div class="text-right ml-4">
                        <?php
                        $transStatusColors = [
                            'inquiry' => 'bg-blue-100 text-blue-600',
                            'quote_pending' => 'bg-yellow-100 text-yellow-600',
                            'quote_sent' => 'bg-indigo-100 text-indigo-600',
                            'quote_accepted' => 'bg-green-100 text-green-600',
                            'paid' => 'bg-emerald-100 text-emerald-600',
                            'in_progress' => 'bg-orange-100 text-orange-600',
                            'delivered' => 'bg-purple-100 text-purple-600',
                            'completed' => 'bg-gray-100 text-gray-600',
                            'cancelled' => 'bg-red-100 text-red-600',
                        ];
                        $transStatusLabels = [
                            'inquiry' => '見積依頼',
                            'quote_pending' => '見積待ち',
                            'quote_sent' => '見積送信',
                            'quote_accepted' => '見積承諾',
                            'paid' => '決済完了',
                            'in_progress' => '制作中',
                            'delivered' => '納品済み',
                            'completed' => '完了',
                            'cancelled' => 'キャンセル',
                        ];
                        ?>
                        <span class="inline-block px-2 py-1 rounded-full text-xs font-bold <?= $transStatusColors[$trans['status']] ?? 'bg-gray-100 text-gray-600' ?>">
                            <?= $transStatusLabels[$trans['status']] ?? $trans['status'] ?>
                        </span>
                        <?php if ($trans['total_amount']): ?>
                        <p class="font-bold text-gray-800 mt-1">¥<?= number_format($trans['total_amount']) ?></p>
                        <?php endif; ?>
                        <p class="text-xs text-gray-400"><?= date('m/d H:i', strtotime($trans['created_at'])) ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="grid md:grid-cols-2 gap-6">
            <!-- 最新の注文 -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="font-bold text-gray-800"><i class="fas fa-shopping-bag text-green-500 mr-2"></i>最新の注文</h3>
                    <a href="orders.php" class="text-green-600 hover:text-green-700 text-sm font-bold">すべて見る →</a>
                </div>
                <div class="divide-y divide-gray-100">
                    <?php if (empty($recentOrders)): ?>
                    <div class="px-6 py-8 text-center text-gray-400">
                        まだ注文がありません
                    </div>
                    <?php else: ?>
                    <?php foreach ($recentOrders as $order): ?>
                    <div class="px-6 py-4 flex items-center justify-between hover:bg-gray-50">
                        <div>
                            <p class="font-bold text-gray-800"><?= htmlspecialchars($order['order_number']) ?></p>
                            <p class="text-sm text-gray-500"><?= htmlspecialchars($order['member_name'] ?? '---') ?></p>
                        </div>
                        <div class="text-right">
                            <p class="font-bold text-gray-800">¥<?= number_format($order['total']) ?></p>
                            <?php
                            $statusColors = [
                                'pending' => 'bg-yellow-100 text-yellow-600',
                                'paid' => 'bg-blue-100 text-blue-600',
                                'processing' => 'bg-purple-100 text-purple-600',
                                'shipped' => 'bg-green-100 text-green-600',
                                'delivered' => 'bg-gray-100 text-gray-600',
                                'cancelled' => 'bg-red-100 text-red-600',
                            ];
                            $statusLabels = [
                                'pending' => '決済待ち',
                                'paid' => '決済完了',
                                'processing' => '処理中',
                                'shipped' => '発送済み',
                                'delivered' => '配達完了',
                                'cancelled' => 'キャンセル',
                            ];
                            $status = $order['order_status'];
                            ?>
                            <span class="inline-block px-2 py-1 rounded-full text-xs font-bold <?= $statusColors[$status] ?? 'bg-gray-100 text-gray-600' ?>">
                                <?= $statusLabels[$status] ?? $status ?>
                            </span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- 最新の問い合わせ -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                <div class="px-6 py-4 border-b border-gray-100 flex items-center justify-between">
                    <h3 class="font-bold text-gray-800"><i class="fas fa-envelope text-red-500 mr-2"></i>最新の問い合わせ</h3>
                    <a href="inquiries.php" class="text-yellow-600 hover:text-yellow-700 text-sm font-bold">すべて見る →</a>
                </div>
                <div class="divide-y divide-gray-100">
                    <?php if (empty($recentInquiries)): ?>
                    <div class="px-6 py-8 text-center text-gray-400">
                        まだ問い合わせがありません
                    </div>
                    <?php else: ?>
                    <?php foreach ($recentInquiries as $inquiry): ?>
                    <div class="px-6 py-4 flex items-center justify-between hover:bg-gray-50">
                        <div>
                            <p class="font-bold text-gray-800"><?= htmlspecialchars($inquiry['name'] ?: '名前なし') ?></p>
                            <p class="text-sm text-gray-500"><?= htmlspecialchars($inquiry['genre']) ?></p>
                        </div>
                        <div class="text-right">
                            <span class="inline-block px-3 py-1 rounded-full text-xs font-bold 
                                <?= $inquiry['status'] === 'new' ? 'bg-red-100 text-red-600' : 
                                   ($inquiry['status'] === 'in_progress' ? 'bg-yellow-100 text-yellow-600' : 'bg-green-100 text-green-600') ?>">
                                <?= $inquiry['status'] === 'new' ? '新規' : 
                                   ($inquiry['status'] === 'in_progress' ? '対応中' : '完了') ?>
                            </span>
                            <p class="text-xs text-gray-400 mt-1"><?= date('m/d H:i', strtotime($inquiry['created_at'])) ?></p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

<?php include 'includes/footer.php'; ?>
