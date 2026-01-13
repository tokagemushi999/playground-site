<?php
/**
 * 問い合わせ管理
 */

require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/site-settings.php';
requireAuth();

$db = getDB();
$message = '';

// 一括操作
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['bulk_action'])) {
    $selectedIds = $_POST['selected_ids'] ?? [];
    $action = $_POST['bulk_action'];
    $ids = array_values(array_filter($selectedIds, 'is_numeric'));

    if ($action === '') {
        $message = '操作を選択してください。';
    } elseif (empty($ids)) {
        $message = '問い合わせを選択してください。';
    } else {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        if ($action === 'archive') {
            $stmt = $db->prepare("UPDATE inquiries SET is_archived = 1 WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $message = $stmt->rowCount() . '件をアーカイブしました。';
        } elseif ($action === 'unarchive') {
            $stmt = $db->prepare("UPDATE inquiries SET is_archived = 0 WHERE id IN ($placeholders)");
            $stmt->execute($ids);
            $message = $stmt->rowCount() . '件のアーカイブを解除しました。';
        } elseif ($action === 'delete') {
            $stmt = $db->prepare("DELETE FROM inquiries WHERE id IN ($placeholders) AND is_archived = 1");
            $stmt->execute($ids);
            if ($stmt->rowCount() > 0) {
                $message = $stmt->rowCount() . '件を完全に削除しました。';
            } else {
                $message = '削除できませんでした。アーカイブ済みの問い合わせを選択してください。';
            }
        }
    }
}

// アーカイブ処理
if (isset($_GET['archive']) && is_numeric($_GET['archive'])) {
    $stmt = $db->prepare("UPDATE inquiries SET is_archived = 1 WHERE id = ?");
    $stmt->execute([$_GET['archive']]);
    $message = 'アーカイブしました。';
}

// アーカイブ解除処理
if (isset($_GET['unarchive']) && is_numeric($_GET['unarchive'])) {
    $stmt = $db->prepare("UPDATE inquiries SET is_archived = 0 WHERE id = ?");
    $stmt->execute([$_GET['unarchive']]);
    $message = 'アーカイブを解除しました。';
}

// 完全削除（アーカイブ済みのみ）
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $stmt = $db->prepare("DELETE FROM inquiries WHERE id = ? AND is_archived = 1");
    $stmt->execute([$_GET['delete']]);
    if ($stmt->rowCount() > 0) {
        $message = '問い合わせを完全に削除しました。';
    } else {
        $message = '削除できませんでした。アーカイブ済みの問い合わせを選択してください。';
    }
}

// ステータス更新
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    $id = $_POST['id'];
    $status = $_POST['status'];
    $notes = $_POST['notes'] ?? '';
    
    $stmt = $db->prepare("UPDATE inquiries SET status = ?, notes = ? WHERE id = ?");
    $stmt->execute([$status, $notes, $id]);
    $message = 'ステータスを更新しました。';
}

// フィルター
$statusFilter = $_GET['status'] ?? '';
$showArchived = isset($_GET['archived']);

// クエリ構築
$query = "SELECT * FROM inquiries WHERE 1=1";
if ($showArchived) {
    $query .= " AND is_archived = 1";
} else {
    $query .= " AND (is_archived = 0 OR is_archived IS NULL)";
}
if ($statusFilter) {
    $query .= " AND status = " . $db->quote($statusFilter);
}
$query .= " ORDER BY created_at DESC";

$inquiries = $db->query($query)->fetchAll();

// 統計（アーカイブされていないもののみ）
$stats = [
    'new' => $db->query("SELECT COUNT(*) FROM inquiries WHERE status = 'new' AND (is_archived = 0 OR is_archived IS NULL)")->fetchColumn(),
    'in_progress' => $db->query("SELECT COUNT(*) FROM inquiries WHERE status = 'in_progress' AND (is_archived = 0 OR is_archived IS NULL)")->fetchColumn(),
    'completed' => $db->query("SELECT COUNT(*) FROM inquiries WHERE status = 'completed' AND (is_archived = 0 OR is_archived IS NULL)")->fetchColumn(),
    'cancelled' => $db->query("SELECT COUNT(*) FROM inquiries WHERE status = 'cancelled' AND (is_archived = 0 OR is_archived IS NULL)")->fetchColumn(),
    'archived' => $db->query("SELECT COUNT(*) FROM inquiries WHERE is_archived = 1")->fetchColumn(),
];

$pageTitle = '問い合わせ管理';
$extraCss = '<style>
    .inquiry-content { max-height: 0; overflow: hidden; transition: max-height 0.3s ease-out; }
    .inquiry-content.open { max-height: 2000px; transition: max-height 0.5s ease-in; }
    .inquiry-toggle { transition: transform 0.3s ease; }
    .inquiry-toggle.open { transform: rotate(180deg); }
    .status-menu { animation: fadeIn 0.15s ease-out; }
    @keyframes fadeIn { from { opacity: 0; transform: translateY(-5px); } to { opacity: 1; transform: translateY(0); } }
</style>';
include 'includes/header.php';
?>
        <div class="mb-6 md:mb-8">
            <h2 class="text-xl md:text-2xl font-bold text-gray-800">問い合わせ管理</h2>
            <p class="text-gray-500 text-sm">制作依頼・お問い合わせの管理</p>
        </div>

        <div class="flex gap-2 md:gap-4 mb-6 overflow-x-auto pb-2">
            <a href="inquiries.php" class="px-3 md:px-4 py-2 rounded-lg text-sm font-bold transition whitespace-nowrap <?= !$showArchived && !$statusFilter ? 'bg-yellow-400 text-gray-900' : 'bg-white text-gray-600 hover:bg-gray-100' ?>">
                すべて
            </a>
            <a href="inquiries.php?archived=1" class="px-3 md:px-4 py-2 rounded-lg text-sm font-bold transition whitespace-nowrap <?= $showArchived ? 'bg-gray-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100' ?>">
                アーカイブ
            </a>
        </div>
        
        <?php if ($message): ?>
        <div class="bg-green-50 border border-green-200 text-green-600 px-4 py-3 rounded-lg mb-6">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
        
        <!-- Stats -->
        <div class="grid grid-cols-2 md:grid-cols-5 gap-2 md:gap-4 mb-6 md:mb-8">
            <a href="?status=new" class="bg-white rounded-xl p-3 md:p-4 shadow-sm border border-gray-100 hover:border-red-300 transition <?= $statusFilter === 'new' && !$showArchived ? 'ring-2 ring-red-400' : '' ?>">
                <p class="text-gray-500 text-xs md:text-sm">新規</p>
                <p class="text-xl md:text-2xl font-bold text-red-500"><?= $stats['new'] ?></p>
            </a>
            <a href="?status=in_progress" class="bg-white rounded-xl p-3 md:p-4 shadow-sm border border-gray-100 hover:border-yellow-300 transition <?= $statusFilter === 'in_progress' && !$showArchived ? 'ring-2 ring-yellow-400' : '' ?>">
                <p class="text-gray-500 text-xs md:text-sm">対応中</p>
                <p class="text-xl md:text-2xl font-bold text-yellow-500"><?= $stats['in_progress'] ?></p>
            </a>
            <a href="?status=completed" class="bg-white rounded-xl p-3 md:p-4 shadow-sm border border-gray-100 hover:border-green-300 transition <?= $statusFilter === 'completed' && !$showArchived ? 'ring-2 ring-green-400' : '' ?>">
                <p class="text-gray-500 text-xs md:text-sm">完了</p>
                <p class="text-xl md:text-2xl font-bold text-green-500"><?= $stats['completed'] ?></p>
            </a>
            <a href="?status=cancelled" class="bg-white rounded-xl p-3 md:p-4 shadow-sm border border-gray-100 hover:border-gray-400 transition <?= $statusFilter === 'cancelled' && !$showArchived ? 'ring-2 ring-gray-400' : '' ?>">
                <p class="text-gray-500 text-xs md:text-sm">キャンセル</p>
                <p class="text-xl md:text-2xl font-bold text-gray-500"><?= $stats['cancelled'] ?></p>
            </a>
            <a href="?archived=1" class="bg-white rounded-xl p-3 md:p-4 shadow-sm border border-gray-100 hover:border-gray-400 transition <?= $showArchived ? 'ring-2 ring-gray-400' : '' ?>">
                <p class="text-gray-500 text-xs md:text-sm">アーカイブ</p>
                <p class="text-xl md:text-2xl font-bold text-gray-400"><?= $stats['archived'] ?></p>
            </a>
        </div>
        
        <?php if ($statusFilter || $showArchived): ?>
        <div class="mb-4">
            <a href="inquiries.php" class="text-yellow-600 hover:text-yellow-700 text-sm font-bold">
                <i class="fas fa-times mr-1"></i>フィルターを解除
            </a>
        </div>
        <?php endif; ?>
        
        <!-- List -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100">
            <?php if (empty($inquiries)): ?>
            <div class="px-6 py-12 text-center text-gray-400">
                <i class="fas fa-envelope text-4xl mb-4"></i>
                <p><?= $showArchived ? 'アーカイブされた問い合わせがありません' : '問い合わせがありません' ?></p>
            </div>
            <?php else: ?>
            <form id="bulk-action-form" method="POST" class="border-b border-gray-100 px-4 md:px-6 py-3 md:py-4 flex flex-col md:flex-row gap-3 md:items-center md:justify-between">
                <div class="flex items-center gap-3">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-600">
                        <input type="checkbox" id="select-all" class="rounded border-gray-300 text-yellow-500 focus:ring-yellow-400">
                        <span>全選択</span>
                    </label>
                </div>
                <div class="flex items-center gap-2">
                    <select name="bulk_action" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 outline-none">
                        <option value="">操作を選択</option>
                        <?php if ($showArchived): ?>
                        <option value="unarchive">アーカイブ解除</option>
                        <option value="delete">完全削除</option>
                        <?php else: ?>
                        <option value="archive">アーカイブ</option>
                        <?php endif; ?>
                    </select>
                    <button type="submit" class="bg-gray-900 hover:bg-gray-800 text-white px-4 py-2 rounded-lg text-sm font-bold transition">
                        実行
                    </button>
                </div>
            </form>
            <div class="divide-y divide-gray-100">
                <?php foreach ($inquiries as $index => $inquiry): ?>
                <div class="inquiry-item <?= $showArchived ? 'bg-gray-50' : '' ?>">
                    <!-- ヘッダー部分 -->
                    <div class="inquiry-header p-4 md:p-6 flex items-start gap-3">
                        <div class="pt-1" onclick="event.stopPropagation()">
                            <input type="checkbox" name="selected_ids[]" value="<?= $inquiry['id'] ?>" form="bulk-action-form" class="inquiry-checkbox rounded border-gray-300 text-yellow-500 focus:ring-yellow-400">
                        </div>
                        <div class="flex-1 min-w-0 cursor-pointer" onclick="toggleInquiry(<?= $index ?>)">
                            <div class="flex flex-wrap items-center gap-2 mb-1">
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold 
                                    <?php
                                    switch($inquiry['status']) {
                                        case 'new': echo 'bg-red-100 text-red-600'; break;
                                        case 'in_progress': echo 'bg-yellow-100 text-yellow-600'; break;
                                        case 'completed': echo 'bg-green-100 text-green-600'; break;
                                        case 'cancelled': echo 'bg-gray-200 text-gray-600'; break;
                                        default: echo 'bg-gray-100 text-gray-600';
                                    }
                                    ?>">
                                    <i class="fas <?php
                                        switch($inquiry['status']) {
                                            case 'new': echo 'fa-exclamation-circle'; break;
                                            case 'in_progress': echo 'fa-clock'; break;
                                            case 'completed': echo 'fa-check-circle'; break;
                                            case 'cancelled': echo 'fa-ban'; break;
                                            default: echo 'fa-question-circle';
                                        }
                                    ?>"></i>
                                    <?php
                                    switch($inquiry['status']) {
                                        case 'new': echo '新規'; break;
                                        case 'in_progress': echo '対応中'; break;
                                        case 'completed': echo '完了'; break;
                                        case 'cancelled': echo 'キャンセル'; break;
                                        default: echo '不明';
                                    }
                                    ?>
                                </span>
                                <?php if ($showArchived): ?>
                                <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-xs font-bold bg-gray-200 text-gray-600">
                                    <i class="fas fa-archive"></i>
                                </span>
                                <?php endif; ?>
                                <span class="text-gray-400 text-xs"><?= date('Y/m/d H:i', strtotime($inquiry['created_at'])) ?></span>
                            </div>
                            <h4 class="font-bold text-gray-800"><?= htmlspecialchars($inquiry['name'] ?: '名前なし') ?></h4>
                            <?php if (!empty($inquiry['company_name'])): ?>
                            <p class="text-gray-500 text-sm"><?= htmlspecialchars($inquiry['company_name']) ?></p>
                            <?php endif; ?>
                            <p class="text-gray-400 text-xs mt-1 truncate"><?= htmlspecialchars(mb_substr($inquiry['details'] ?: '詳細なし', 0, 50)) ?>...</p>
                        </div>
                        <div class="flex flex-col items-end gap-2 flex-shrink-0">
                            <p class="text-lg font-bold text-gray-800"><?= htmlspecialchars($inquiry['budget'] ?: '未定') ?></p>
                            <!-- クイック操作ボタン -->
                            <div class="flex items-center gap-1" onclick="event.stopPropagation()">
                                <!-- ステータス変更ドロップダウン -->
                                <div class="relative">
                                    <button type="button" onclick="toggleStatusMenu(<?= $index ?>)" class="w-8 h-8 rounded-full bg-gray-100 hover:bg-gray-200 flex items-center justify-center transition" title="ステータス変更">
                                        <i class="fas fa-exchange-alt text-gray-500 text-sm"></i>
                                    </button>
                                    <div id="status-menu-<?= $index ?>" class="status-menu hidden absolute right-0 top-full mt-1 bg-white rounded-lg shadow-lg border border-gray-200 py-1 z-20 min-w-[120px]">
                                        <form method="POST" class="status-form">
                                            <input type="hidden" name="update_status" value="1">
                                            <input type="hidden" name="id" value="<?= $inquiry['id'] ?>">
                                            <input type="hidden" name="notes" value="<?= htmlspecialchars($inquiry['notes'] ?? '') ?>">
                                            <button type="submit" name="status" value="new" class="w-full px-3 py-2 text-left text-sm hover:bg-red-50 text-red-600 flex items-center gap-2 <?= $inquiry['status'] === 'new' ? 'bg-red-50 font-bold' : '' ?>">
                                                <i class="fas fa-exclamation-circle"></i> 新規
                                            </button>
                                            <button type="submit" name="status" value="in_progress" class="w-full px-3 py-2 text-left text-sm hover:bg-yellow-50 text-yellow-600 flex items-center gap-2 <?= $inquiry['status'] === 'in_progress' ? 'bg-yellow-50 font-bold' : '' ?>">
                                                <i class="fas fa-clock"></i> 対応中
                                            </button>
                                            <button type="submit" name="status" value="completed" class="w-full px-3 py-2 text-left text-sm hover:bg-green-50 text-green-600 flex items-center gap-2 <?= $inquiry['status'] === 'completed' ? 'bg-green-50 font-bold' : '' ?>">
                                                <i class="fas fa-check-circle"></i> 完了
                                            </button>
                                            <button type="submit" name="status" value="cancelled" class="w-full px-3 py-2 text-left text-sm hover:bg-gray-100 text-gray-600 flex items-center gap-2 <?= $inquiry['status'] === 'cancelled' ? 'bg-gray-100 font-bold' : '' ?>">
                                                <i class="fas fa-ban"></i> キャンセル
                                            </button>
                                        </form>
                                    </div>
                                </div>
                                <!-- アーカイブ/復元ボタン -->
                                <?php if ($showArchived): ?>
                                <a href="?unarchive=<?= $inquiry['id'] ?>" class="w-8 h-8 rounded-full bg-blue-100 hover:bg-blue-200 flex items-center justify-center transition" title="復元" onclick="return confirm('アーカイブを解除しますか？')">
                                    <i class="fas fa-undo text-blue-500 text-sm"></i>
                                </a>
                                <a href="?delete=<?= $inquiry['id'] ?>" class="w-8 h-8 rounded-full bg-red-100 hover:bg-red-200 flex items-center justify-center transition" title="完全削除" onclick="return confirm('この問い合わせを完全に削除しますか？')">
                                    <i class="fas fa-trash text-red-500 text-sm"></i>
                                </a>
                                <?php else: ?>
                                <a href="?archive=<?= $inquiry['id'] ?>" class="w-8 h-8 rounded-full bg-gray-100 hover:bg-gray-200 flex items-center justify-center transition" title="アーカイブ" onclick="return confirm('アーカイブしますか？')">
                                    <i class="fas fa-archive text-gray-500 text-sm"></i>
                                </a>
                                <?php endif; ?>
                                <!-- 展開ボタン -->
                                <button type="button" onclick="toggleInquiry(<?= $index ?>)" class="w-8 h-8 rounded-full bg-gray-100 hover:bg-gray-200 flex items-center justify-center transition" title="詳細を見る">
                                    <i class="fas fa-chevron-down inquiry-toggle text-gray-500 text-sm" id="toggle-icon-<?= $index ?>"></i>
                                </button>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 展開される詳細部分 -->
                    <div class="inquiry-content" id="inquiry-content-<?= $index ?>">
                        <div class="px-4 md:px-6 pb-4 md:pb-6 pt-0">
                            <div class="border-t border-gray-100 pt-4">
                                <?php if ($inquiry['nominated_creator']): ?>
                                <div class="bg-yellow-50 px-4 py-2 rounded-lg mb-4 text-sm">
                                    <i class="fas fa-user-check text-yellow-500 mr-2"></i>
                                    指名: <strong><?= htmlspecialchars($inquiry['nominated_creator']) ?></strong>
                                </div>
                                <?php endif; ?>
                                
                                <div class="grid grid-cols-2 gap-3 text-sm mb-4">
                                    <div>
                                        <span class="text-gray-400 text-xs">メールアドレス</span>
                                        <p class="font-medium"><?= htmlspecialchars($inquiry['email'] ?: '-') ?></p>
                                    </div>
                                    <div>
                                        <span class="text-gray-400 text-xs">ジャンル</span>
                                        <p class="font-medium"><?= htmlspecialchars($inquiry['genre'] ?: '-') ?></p>
                                    </div>
                                    <div>
                                        <span class="text-gray-400 text-xs">納期</span>
                                        <p class="font-medium"><?= $inquiry['deadline'] ? date('Y/m/d', strtotime($inquiry['deadline'])) : '-' ?></p>
                                    </div>
                                    <div>
                                        <span class="text-gray-400 text-xs">用途</span>
                                        <p class="font-medium"><?= htmlspecialchars($inquiry['purpose'] ?: '-') ?></p>
                                    </div>
                                </div>
                                
                                <div class="bg-gray-50 p-4 rounded-lg mb-4">
                                    <p class="text-xs text-gray-400 mb-2">依頼内容</p>
                                    <p class="text-sm text-gray-700 whitespace-pre-wrap"><?= htmlspecialchars($inquiry['details'] ?: '詳細なし', ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></p>
                                </div>
                                
                                <!-- Status Update Form & Archive Button -->
                                <div class="flex flex-col gap-4 border-t border-gray-100 pt-4">
                                    <form method="POST" class="flex flex-col md:flex-row gap-3 items-stretch md:items-end">
                                        <input type="hidden" name="update_status" value="1">
                                        <input type="hidden" name="id" value="<?= $inquiry['id'] ?>">
                                        
                                        <div class="flex-1">
                                            <label class="block text-xs text-gray-500 mb-1">メモ</label>
                                            <input type="text" name="notes" 
                                                value="<?= htmlspecialchars($inquiry['notes'] ?? '') ?>"
                                                class="w-full px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 outline-none"
                                                placeholder="対応メモを入力...">
                                        </div>
                                        
                                        <div>
                                            <label class="block text-xs text-gray-500 mb-1">ステータス</label>
                                            <select name="status" class="w-full md:w-auto px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 outline-none">
                                                <option value="new" <?= $inquiry['status'] === 'new' ? 'selected' : '' ?>>新規</option>
                                                <option value="in_progress" <?= $inquiry['status'] === 'in_progress' ? 'selected' : '' ?>>対応中</option>
                                                <option value="completed" <?= $inquiry['status'] === 'completed' ? 'selected' : '' ?>>完了</option>
                                                <option value="cancelled" <?= $inquiry['status'] === 'cancelled' ? 'selected' : '' ?>>キャンセル</option>
                                            </select>
                                        </div>
                                        
                                        <button type="submit" class="bg-yellow-400 hover:bg-yellow-500 text-gray-900 px-4 py-2 rounded-lg text-sm font-bold transition">
                                            更新
                                        </button>
                                    </form>
                                    
                                    <!-- Archive/Unarchive Button -->
                                    <div class="flex flex-wrap gap-2">
                                        <?php if ($showArchived): ?>
                                        <a href="?unarchive=<?= $inquiry['id'] ?>" 
                                            class="bg-blue-100 hover:bg-blue-200 text-blue-600 px-4 py-2 rounded-lg text-sm font-bold transition"
                                            onclick="return confirm('アーカイブを解除しますか？')">
                                            <i class="fas fa-undo mr-1"></i>復元
                                        </a>
                                        <a href="?delete=<?= $inquiry['id'] ?>" 
                                            class="bg-red-100 hover:bg-red-200 text-red-600 px-4 py-2 rounded-lg text-sm font-bold transition"
                                            onclick="return confirm('この問い合わせを完全に削除しますか？この操作は取り消せません。')">
                                            <i class="fas fa-trash mr-1"></i>完全削除
                                        </a>
                                        <?php else: ?>
                                        <a href="?archive=<?= $inquiry['id'] ?>" 
                                            class="bg-gray-100 hover:bg-gray-200 text-gray-600 px-4 py-2 rounded-lg text-sm font-bold transition"
                                            onclick="return confirm('アーカイブしますか？')">
                                            <i class="fas fa-archive mr-1"></i>アーカイブ
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>

<?php include "includes/footer.php"; ?>
