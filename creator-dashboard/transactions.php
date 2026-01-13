<?php
/**
 * クリエイターダッシュボード - 取引管理（統一デザイン）
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/csrf.php';
require_once '../includes/creator-auth.php';
require_once '../includes/transactions.php';
require_once '../includes/admin-ui.php';

$creator = requireCreatorAuth();
$db = getDB();

$statusFilter = $_GET['status'] ?? '';

// 取引一覧
$transactions = getCreatorTransactions($creator['id'], $statusFilter ?: null);

// 統計
$stats = $db->prepare("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status IN ('inquiry', 'quote_revision') THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'quote_sent' THEN 1 ELSE 0 END) as quote_sent,
        SUM(CASE WHEN status IN ('paid', 'in_progress', 'revision_requested') THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
    FROM service_transactions WHERE creator_id = ?
");
$stats->execute([$creator['id']]);
$stats = $stats->fetch();

$pageTitle = '取引管理';
require_once 'includes/header.php';
?>

<?= renderPageHeader('取引管理', '顧客からの依頼を管理します') ?>

<!-- 統計 -->
<?= renderTransactionStats($stats) ?>

<!-- フィルター -->
<?php
$filters = [
    '' => ['label' => 'すべて', 'color' => 'green'],
    'pending' => ['label' => '対応待ち', 'color' => 'yellow', 'count' => $stats['pending'] ?? 0],
    'quote_sent' => ['label' => '見積もり中', 'color' => 'blue', 'count' => $stats['quote_sent'] ?? 0],
    'in_progress' => ['label' => '制作中', 'color' => 'green', 'count' => $stats['in_progress'] ?? 0],
    'delivered' => ['label' => '納品済', 'color' => 'purple', 'count' => $stats['delivered'] ?? 0],
    'completed' => ['label' => '完了', 'color' => 'gray'],
];
echo renderFilterTabs($filters, $statusFilter);
?>

<!-- 取引一覧 -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <table class="w-full">
        <thead class="bg-gray-50"><tr>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">取引コード</th>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600 hidden md:table-cell">サービス</th>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">顧客</th>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">ステータス</th>
            <th class="px-4 py-3 text-right text-sm font-bold text-gray-600 hidden sm:table-cell">金額</th>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600 hidden lg:table-cell">開始日</th>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600 hidden lg:table-cell">納品予定</th>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600 hidden md:table-cell">更新日</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-100">
            <?php if (empty($transactions)): ?>
            <?= renderEmptyRow(8, '該当する取引がありません') ?>
            <?php endif; ?>
            <?php foreach ($transactions as $t): ?>
            <?= renderTransactionRow($t, false) ?>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php require_once 'includes/footer.php'; ?>
