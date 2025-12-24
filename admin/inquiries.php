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
    'archived' => $db->query("SELECT COUNT(*) FROM inquiries WHERE is_archived = 1")->fetchColumn(),
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>問い合わせ管理 | 管理画面</title>
    <link rel="manifest" href="/admin/manifest.json">
    <?php $backyardFavicon = getBackyardFaviconInfo($db); ?>
    <link rel="icon" href="<?= htmlspecialchars($backyardFavicon['path']) ?>" type="<?= htmlspecialchars($backyardFavicon['type']) ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($backyardFavicon['path']) ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Zen Maru Gothic', sans-serif; }
    </style>
</head>
<body class="bg-gray-50">
    <!-- 共通サイドバーをinclude -->
    <?php include 'includes/sidebar.php'; ?>
    
    <!-- Main Content -->
    <main class="lg:ml-64 pt-20 lg:pt-8 p-8">
        <div class="mb-8">
            <h2 class="text-2xl font-bold text-gray-800">問い合わせ管理</h2>
            <p class="text-gray-500">制作依頼・お問い合わせの管理</p>
        </div>

        <div class="flex gap-4 mb-6">
            <a href="inquiries.php" class="px-4 py-2 rounded-lg text-sm font-bold transition <?= !$showArchived ? 'bg-yellow-400 text-gray-900' : 'bg-white text-gray-600 hover:bg-gray-100' ?>">
                受信一覧
            </a>
            <a href="inquiries.php?archived=1" class="px-4 py-2 rounded-lg text-sm font-bold transition <?= $showArchived ? 'bg-gray-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100' ?>">
                アーカイブ
            </a>
        </div>
        
        <?php if ($message): ?>
        <div class="bg-green-50 border border-green-200 text-green-600 px-4 py-3 rounded-lg mb-6">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
        
        <!-- Stats -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-8">
            <a href="?status=new" class="bg-white rounded-xl p-4 shadow-sm border border-gray-100 hover:border-red-300 transition <?= $statusFilter === 'new' && !$showArchived ? 'ring-2 ring-red-400' : '' ?>">
                <p class="text-gray-500 text-sm">新規</p>
                <p class="text-2xl font-bold text-red-500"><?= $stats['new'] ?></p>
            </a>
            <a href="?status=in_progress" class="bg-white rounded-xl p-4 shadow-sm border border-gray-100 hover:border-yellow-300 transition <?= $statusFilter === 'in_progress' && !$showArchived ? 'ring-2 ring-yellow-400' : '' ?>">
                <p class="text-gray-500 text-sm">対応中</p>
                <p class="text-2xl font-bold text-yellow-500"><?= $stats['in_progress'] ?></p>
            </a>
            <a href="?status=completed" class="bg-white rounded-xl p-4 shadow-sm border border-gray-100 hover:border-green-300 transition <?= $statusFilter === 'completed' && !$showArchived ? 'ring-2 ring-green-400' : '' ?>">
                <p class="text-gray-500 text-sm">完了</p>
                <p class="text-2xl font-bold text-green-500"><?= $stats['completed'] ?></p>
            </a>
            <a href="?archived=1" class="bg-white rounded-xl p-4 shadow-sm border border-gray-100 hover:border-gray-400 transition <?= $showArchived ? 'ring-2 ring-gray-400' : '' ?>">
                <p class="text-gray-500 text-sm">アーカイブ</p>
                <p class="text-2xl font-bold text-gray-500"><?= $stats['archived'] ?></p>
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
            <form id="bulk-action-form" method="POST" class="border-b border-gray-100 px-6 py-4 flex flex-col md:flex-row gap-3 md:items-center md:justify-between">
                <div class="flex items-center gap-3">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-600">
                        <input type="checkbox" id="select-all" class="rounded border-gray-300 text-yellow-500 focus:ring-yellow-400">
                        <span>全て選択</span>
                    </label>
                    <span class="text-xs text-gray-400">選択した問い合わせに対して操作できます</span>
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
                        一括実行
                    </button>
                </div>
            </form>
            <div class="divide-y divide-gray-100">
                <?php foreach ($inquiries as $inquiry): ?>
                <div class="p-6 <?= $showArchived ? 'bg-gray-50' : '' ?>">
                    <div class="flex flex-col md:flex-row justify-between items-start mb-4 gap-4">
                        <div class="flex gap-3">
                            <div class="pt-1">
                                <input type="checkbox" name="selected_ids[]" value="<?= $inquiry['id'] ?>" form="bulk-action-form" class="inquiry-checkbox rounded border-gray-300 text-yellow-500 focus:ring-yellow-400">
                            </div>
                            <div class="flex flex-col">
                                <div class="flex items-center gap-2 mb-2 flex-wrap">
                                    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-bold 
                                        <?= $inquiry['status'] === 'new' ? 'bg-red-100 text-red-600' : 
                                           ($inquiry['status'] === 'in_progress' ? 'bg-yellow-100 text-yellow-600' : 'bg-green-100 text-green-600') ?>">
                                        <i class="fas <?= $inquiry['status'] === 'new' ? 'fa-exclamation-circle' : 
                                           ($inquiry['status'] === 'in_progress' ? 'fa-clock' : 'fa-check-circle') ?>"></i>
                                        <?= $inquiry['status'] === 'new' ? '新規' : 
                                           ($inquiry['status'] === 'in_progress' ? '対応中' : '完了') ?>
                                    </span>
                                    <?php if ($showArchived): ?>
                                    <span class="inline-flex items-center gap-1 px-3 py-1 rounded-full text-xs font-bold bg-gray-200 text-gray-600">
                                        <i class="fas fa-archive"></i> アーカイブ済
                                    </span>
                                    <?php endif; ?>
                                    <span class="text-gray-400 text-sm"><?= date('Y/m/d H:i', strtotime($inquiry['created_at'])) ?></span>
                                </div>
                                <div>
                                    <h4 class="font-bold text-gray-800 text-lg"><?= htmlspecialchars($inquiry['name'] ?: '名前なし') ?></h4>
                                    <?php if (!empty($inquiry['company_name'])): ?>
                                    <p class="text-gray-600 text-sm font-medium"><?= htmlspecialchars($inquiry['company_name']) ?></p>
                                    <?php endif; ?>
                                    <p class="text-gray-500 text-sm"><?= htmlspecialchars($inquiry['email'] ?: '-') ?></p>
                                </div>
                            </div>
                        </div>
                        <div class="text-right">
                            <p class="text-2xl font-bold text-gray-800"><?= htmlspecialchars($inquiry['budget'] ?: '未定') ?></p>
                            <p class="text-sm text-gray-500"><?= htmlspecialchars($inquiry['genre'] ?: '-') ?></p>
                        </div>
                    </div>
                    
                    <?php if ($inquiry['nominated_creator']): ?>
                    <div class="bg-yellow-50 px-4 py-2 rounded-lg mb-4 text-sm">
                        <i class="fas fa-user-check text-yellow-500 mr-2"></i>
                        指名: <strong><?= htmlspecialchars($inquiry['nominated_creator']) ?></strong>
                    </div>
                    <?php endif; ?>
                    
                    <div class="bg-gray-50 p-4 rounded-lg mb-4">
                        <p class="text-sm text-gray-600 whitespace-pre-wrap"><?= htmlspecialchars($inquiry['details'] ?: '詳細なし', ENT_QUOTES | ENT_HTML5, 'UTF-8') ?></p>
                    </div>
                    
                    <div class="grid grid-cols-2 gap-4 text-sm mb-4">
                        <div>
                            <span class="text-gray-400">納期:</span>
                            <span class="font-bold"><?= $inquiry['deadline'] ? date('Y/m/d', strtotime($inquiry['deadline'])) : '-' ?></span>
                        </div>
                        <div>
                            <span class="text-gray-400">用途:</span>
                            <span class="font-bold"><?= htmlspecialchars($inquiry['purpose'] ?: '-') ?></span>
                        </div>
                    </div>
                    
                    <!-- Status Update Form & Archive Button -->
                    <div class="flex flex-col md:flex-row gap-4 items-stretch md:items-end border-t border-gray-100 pt-4">
                        <form method="POST" class="flex flex-col md:flex-row gap-4 items-stretch md:items-end flex-1">
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
                                <select name="status" class="px-3 py-2 border border-gray-300 rounded-lg text-sm focus:ring-2 focus:ring-yellow-400 outline-none">
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
                        <?php if ($showArchived): ?>
                        <div class="flex flex-col sm:flex-row gap-2">
                            <a href="?unarchive=<?= $inquiry['id'] ?>" 
                                class="bg-blue-100 hover:bg-blue-200 text-blue-600 px-4 py-2 rounded-lg text-sm font-bold transition text-center"
                                onclick="return confirm('アーカイブを解除しますか？')">
                                <i class="fas fa-undo mr-1"></i>復元
                            </a>
                            <a href="?delete=<?= $inquiry['id'] ?>" 
                                class="bg-red-100 hover:bg-red-200 text-red-600 px-4 py-2 rounded-lg text-sm font-bold transition text-center"
                                onclick="return confirm('この問い合わせを完全に削除しますか？この操作は取り消せません。')">
                                <i class="fas fa-trash mr-1"></i>完全削除
                            </a>
                        </div>
                        <?php else: ?>
                        <a href="?archive=<?= $inquiry['id'] ?>" 
                            class="bg-gray-100 hover:bg-gray-200 text-gray-600 px-4 py-2 rounded-lg text-sm font-bold transition text-center"
                            onclick="return confirm('アーカイブしますか？')">
                            <i class="fas fa-archive mr-1"></i>アーカイブ
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </main>
    <script>
        const selectAll = document.getElementById('select-all');
        const inquiryCheckboxes = document.querySelectorAll('.inquiry-checkbox');

        if (selectAll) {
            selectAll.addEventListener('change', () => {
                inquiryCheckboxes.forEach((checkbox) => {
                    checkbox.checked = selectAll.checked;
                });
            });
        }

        inquiryCheckboxes.forEach((checkbox) => {
            checkbox.addEventListener('change', () => {
                if (!selectAll) {
                    return;
                }
                const allChecked = Array.from(inquiryCheckboxes).every((item) => item.checked);
                const anyChecked = Array.from(inquiryCheckboxes).some((item) => item.checked);
                selectAll.checked = allChecked;
                selectAll.indeterminate = !allChecked && anyChecked;
            });
        });
    </script>
</body>
</html>
