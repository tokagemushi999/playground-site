<?php
/**
 * 会員管理ページ（管理画面）
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/site-settings.php';
requireAuth();

$db = getDB();
$message = '';
$error = '';

// membersテーブルが存在するか確認
$tableExists = true;
try {
    $db->query("SELECT 1 FROM members LIMIT 1");
} catch (PDOException $e) {
    $tableExists = false;
    $error = 'membersテーブルが存在しません。sql/ec_tables.sqlを実行してください。';
}

// 会員操作
if ($tableExists && $_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['toggle_status'])) {
        $id = (int)$_POST['member_id'];
        $newStatus = $_POST['new_status'];
        $stmt = $db->prepare("UPDATE members SET status = ? WHERE id = ?");
        $stmt->execute([$newStatus, $id]);
        $message = 'ステータスを更新しました';
    }
}

// 検索・フィルター
$search = $_GET['search'] ?? '';
$status = $_GET['status'] ?? '';

// 会員一覧取得
$members = [];
$stats = ['total' => 0, 'active' => 0, 'suspended' => 0];

if ($tableExists) {
    // 統計
    $stmt = $db->query("SELECT COUNT(*) as total, SUM(status='active') as active, SUM(status='suspended') as suspended FROM members");
    $stats = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 会員一覧
    $sql = "SELECT m.*, 
                   (SELECT COUNT(*) FROM orders WHERE member_id = m.id) as order_count,
                   (SELECT SUM(total) FROM orders WHERE member_id = m.id AND payment_status = 'paid') as total_spent
            FROM members m WHERE 1=1";
    $params = [];
    
    if ($search) {
        $sql .= " AND (m.name LIKE ? OR m.email LIKE ? OR m.nickname LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    if ($status) {
        $sql .= " AND m.status = ?";
        $params[] = $status;
    }
    
    $sql .= " ORDER BY m.created_at DESC LIMIT 100";
    
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $members = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// 会員詳細
$memberDetail = null;
$memberOrders = [];
$memberBookshelf = [];
if ($tableExists && isset($_GET['view'])) {
    $stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
    $stmt->execute([(int)$_GET['view']]);
    $memberDetail = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($memberDetail) {
        // 注文履歴
        $stmt = $db->prepare("SELECT * FROM orders WHERE member_id = ? ORDER BY created_at DESC LIMIT 20");
        $stmt->execute([$memberDetail['id']]);
        $memberOrders = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // 本棚
        $stmt = $db->prepare("SELECT b.*, p.name as product_name FROM member_bookshelf b JOIN products p ON b.product_id = p.id WHERE b.member_id = ? ORDER BY b.created_at DESC");
        $stmt->execute([$memberDetail['id']]);
        $memberBookshelf = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$pageTitle = '会員管理';
include 'includes/header.php';
?>
        <h1 class="text-2xl font-bold text-gray-800 mb-6">
            <i class="fas fa-users text-blue-500 mr-2"></i>会員管理
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
            
        <?php if ($memberDetail): ?>
        <!-- 会員詳細 -->
        <div class="bg-white rounded-xl shadow-sm p-6 mb-8">
            <div class="flex items-center justify-between mb-6">
                <h2 class="text-xl font-bold">会員詳細</h2>
                <a href="members.php" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times text-xl"></i>
                </a>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6 mb-6">
                <div>
                    <h3 class="font-bold text-gray-700 mb-3">基本情報</h3>
                    <table class="w-full text-sm">
                        <tr><td class="py-1 text-gray-500 w-24">ID</td><td class="py-1"><?= $memberDetail['id'] ?></td></tr>
                        <tr><td class="py-1 text-gray-500">氏名</td><td class="py-1 font-bold"><?= htmlspecialchars($memberDetail['name']) ?></td></tr>
                        <tr><td class="py-1 text-gray-500">ニックネーム</td><td class="py-1"><?= htmlspecialchars($memberDetail['nickname'] ?? '-') ?></td></tr>
                        <tr><td class="py-1 text-gray-500">メール</td><td class="py-1"><?= htmlspecialchars($memberDetail['email']) ?></td></tr>
                        <tr>
                            <td class="py-1 text-gray-500">ステータス</td>
                            <td class="py-1">
                                <span class="px-2 py-0.5 rounded text-xs font-bold <?= $memberDetail['status'] === 'active' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                                    <?= $memberDetail['status'] === 'active' ? '有効' : '停止中' ?>
                                </span>
                            </td>
                        </tr>
                        <tr><td class="py-1 text-gray-500">登録日</td><td class="py-1"><?= date('Y/m/d H:i', strtotime($memberDetail['created_at'])) ?></td></tr>
                        <tr><td class="py-1 text-gray-500">最終ログイン</td><td class="py-1"><?= isset($memberDetail['last_login_at']) && $memberDetail['last_login_at'] ? date('Y/m/d H:i', strtotime($memberDetail['last_login_at'])) : '-' ?></td></tr>
                    </table>
                </div>
                
                <div>
                    <h3 class="font-bold text-gray-700 mb-3">購入情報</h3>
                    <div class="grid grid-cols-2 gap-4">
                        <div class="bg-gray-50 rounded-lg p-4 text-center">
                            <p class="text-2xl font-bold text-gray-800"><?= count($memberOrders) ?></p>
                            <p class="text-xs text-gray-500">注文数</p>
                        </div>
                        <div class="bg-gray-50 rounded-lg p-4 text-center">
                            <p class="text-2xl font-bold text-green-600">¥<?= number_format(array_sum(array_column($memberOrders, 'total'))) ?></p>
                            <p class="text-xs text-gray-500">累計購入額</p>
                        </div>
                    </div>
                    
                    <form method="POST" class="mt-4">
                        <input type="hidden" name="member_id" value="<?= $memberDetail['id'] ?>">
                        <input type="hidden" name="new_status" value="<?= $memberDetail['status'] === 'active' ? 'suspended' : 'active' ?>">
                        <button type="submit" name="toggle_status" 
                            class="w-full py-2 rounded-lg font-bold <?= $memberDetail['status'] === 'active' ? 'bg-red-100 text-red-700 hover:bg-red-200' : 'bg-green-100 text-green-700 hover:bg-green-200' ?>">
                            <?= $memberDetail['status'] === 'active' ? '<i class="fas fa-ban mr-1"></i>アカウントを停止' : '<i class="fas fa-check mr-1"></i>アカウントを有効化' ?>
                        </button>
                    </form>
                </div>
            </div>
            
            <!-- 注文履歴 -->
            <h3 class="font-bold text-gray-700 mb-3">注文履歴</h3>
            <?php if (empty($memberOrders)): ?>
            <p class="text-gray-500 text-sm py-4">注文履歴がありません</p>
            <?php else: ?>
            <table class="w-full text-sm mb-6">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-3 py-2 text-left">注文番号</th>
                        <th class="px-3 py-2 text-left">日時</th>
                        <th class="px-3 py-2 text-right">合計</th>
                        <th class="px-3 py-2 text-left">支払い</th>
                        <th class="px-3 py-2 text-left">ステータス</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($memberOrders as $order): ?>
                    <tr class="border-t hover:bg-gray-50">
                        <td class="px-3 py-2">
                            <a href="orders.php?view=<?= $order['id'] ?>" class="text-blue-500 hover:underline"><?= htmlspecialchars($order['order_number']) ?></a>
                        </td>
                        <td class="px-3 py-2"><?= date('Y/m/d H:i', strtotime($order['created_at'])) ?></td>
                        <td class="px-3 py-2 text-right font-bold">¥<?= number_format($order['total']) ?></td>
                        <td class="px-3 py-2">
                            <span class="text-xs px-2 py-0.5 rounded <?= $order['payment_status'] === 'paid' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-700' ?>">
                                <?= $order['payment_status'] === 'paid' ? '支払済' : $order['payment_status'] ?>
                            </span>
                        </td>
                        <td class="px-3 py-2"><?= $order['order_status'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>
            
            <!-- 本棚 -->
            <h3 class="font-bold text-gray-700 mb-3">本棚（購入済み商品）</h3>
            <?php if (empty($memberBookshelf)): ?>
            <p class="text-gray-500 text-sm py-4">購入済み商品がありません</p>
            <?php else: ?>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                <?php foreach ($memberBookshelf as $item): ?>
                <div class="bg-gray-50 rounded-lg p-3 text-sm">
                    <p class="font-bold truncate"><?= htmlspecialchars($item['product_name']) ?></p>
                    <p class="text-xs text-gray-500"><?= date('Y/m/d', strtotime($item['created_at'])) ?></p>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
            
        <!-- 統計 -->
        <?php if ($tableExists): ?>
        <div class="grid grid-cols-3 gap-4 mb-6">
            <div class="bg-white rounded-xl p-4 shadow-sm text-center">
                <p class="text-2xl font-bold text-gray-800"><?= number_format($stats['total'] ?? 0) ?></p>
                <p class="text-xs text-gray-500">総会員数</p>
            </div>
            <div class="bg-white rounded-xl p-4 shadow-sm text-center">
                <p class="text-2xl font-bold text-green-600"><?= number_format($stats['active'] ?? 0) ?></p>
                <p class="text-xs text-gray-500">有効</p>
            </div>
            <div class="bg-white rounded-xl p-4 shadow-sm text-center">
                <p class="text-2xl font-bold text-red-600"><?= number_format($stats['suspended'] ?? 0) ?></p>
                <p class="text-xs text-gray-500">停止中</p>
            </div>
        </div>
        
        <!-- 検索・フィルター -->
        <div class="bg-white rounded-xl shadow-sm p-4 mb-6">
            <form method="GET" class="flex flex-wrap gap-3">
                <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="名前・メール・ニックネーム" 
                    class="flex-1 min-w-48 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                <select name="status" class="px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <option value="">すべて</option>
                    <option value="active" <?= $status === 'active' ? 'selected' : '' ?>>有効</option>
                    <option value="suspended" <?= $status === 'suspended' ? 'selected' : '' ?>>停止中</option>
                </select>
                <button type="submit" class="px-6 py-2 bg-blue-500 text-white rounded-lg font-bold hover:bg-blue-600">
                    <i class="fas fa-search mr-1"></i>検索
                </button>
                <?php if ($search || $status): ?>
                <a href="members.php" class="px-4 py-2 text-gray-500 hover:text-gray-700">クリア</a>
                <?php endif; ?>
            </form>
        </div>
        
        <!-- 会員一覧 -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <?php if (empty($members)): ?>
            <div class="p-8 text-center text-gray-500">
                会員が見つかりません
            </div>
            <?php else: ?>
            <div class="divide-y divide-gray-100">
                <?php foreach ($members as $m): ?>
                <a href="?view=<?= $m['id'] ?>" class="block p-4 hover:bg-gray-50">
                    <div class="flex items-center justify-between">
                        <div class="flex-1 min-w-0">
                            <p class="font-bold text-gray-800"><?= htmlspecialchars($m['name']) ?></p>
                            <p class="text-sm text-gray-500 truncate"><?= htmlspecialchars($m['email']) ?></p>
                        </div>
                        <div class="flex items-center gap-4 ml-4">
                            <span class="text-xs px-2 py-1 rounded font-bold <?= $m['status'] === 'active' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' ?>">
                                <?= $m['status'] === 'active' ? '有効' : '停止' ?>
                            </span>
                            <div class="hidden sm:flex items-center gap-3 text-sm text-gray-500">
                                <span>注文 <?= $m['order_count'] ?>件</span>
                                <span class="font-bold text-gray-800">¥<?= number_format($m['total_spent'] ?? 0) ?></span>
                                <span class="text-xs"><?= date('Y/m/d', strtotime($m['created_at'])) ?></span>
                            </div>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>

<?php include 'includes/footer.php'; ?>
