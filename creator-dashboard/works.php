<?php
/**
 * クリエイターダッシュボード - 作品管理（簡略化版）
 */
session_start();
define('CURRENT_ROLE', 'creator');

require_once '../includes/db.php';
require_once '../includes/csrf.php';
require_once '../includes/creator-auth.php';
require_once '../includes/image-helper.php';
require_once '../includes/admin-ui.php';

$creator = requireCreatorAuth();
$db = getDB();
$message = '';
$error = '';
$showArchived = isset($_GET['archived']);
$baseUrl = 'works.php';
$csrfToken = generateCsrfToken();

// コレクション取得
$collections = [];
try { $stmt = $db->prepare("SELECT * FROM collections WHERE creator_id = ? AND is_active = 1 ORDER BY title"); $stmt->execute([$creator['id']]); $collections = $stmt->fetchAll(); } catch (PDOException $e) {}

// アーカイブ/復元処理
if (isset($_GET['archive'])) { $db->prepare("UPDATE works SET is_active = 0 WHERE id = ? AND creator_id = ?")->execute([(int)$_GET['archive'], $creator['id']]); header("Location: {$baseUrl}"); exit; }
if (isset($_GET['restore'])) { $db->prepare("UPDATE works SET is_active = 1 WHERE id = ? AND creator_id = ? AND is_active = 0")->execute([(int)$_GET['restore'], $creator['id']]); header("Location: {$baseUrl}?archived=1"); exit; }

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $ids = array_map('intval', $_POST['selected_items'] ?? []);
    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($ids, [$creator['id']]);
        if (isset($_POST['bulk_archive'])) { $db->prepare("UPDATE works SET is_active = 0 WHERE id IN ($ph) AND creator_id = ?")->execute($params); $message = count($ids) . '件をアーカイブしました。'; }
        elseif (isset($_POST['bulk_restore'])) { $db->prepare("UPDATE works SET is_active = 1 WHERE id IN ($ph) AND creator_id = ? AND is_active = 0")->execute($params); $message = count($ids) . '件を復元しました。'; }
    }
    
    if (isset($_POST['create_work'])) {
        $title = trim($_POST['title'] ?? '');
        if (empty($title)) $error = '作品タイトルは必須です';
        else {
            $thumbnail = '';
            if (!empty($_FILES['thumbnail']['name']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = '../uploads/works/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $result = ImageHelper::processUpload($_FILES['thumbnail']['tmp_name'], $uploadDir, uniqid('work_'), ['maxWidth' => 800, 'maxHeight' => 1200]);
                if ($result) $thumbnail = 'uploads/works/' . basename($result['path']);
            }
            $stmt = $db->prepare("INSERT INTO works (creator_id, collection_id, title, description, image, is_active, approval_status, submitted_at) VALUES (?, ?, ?, ?, ?, 0, 'pending', NOW())");
            $stmt->execute([$creator['id'], (int)$_POST['collection_id'] ?: null, $title, trim($_POST['description'] ?? ''), $thumbnail]);
            $message = '作品を作成しました。';
        }
    }
    
    if (isset($_POST['update_work'])) {
        $stmt = $db->prepare("SELECT * FROM works WHERE id = ? AND creator_id = ?");
        $stmt->execute([(int)$_POST['work_id'], $creator['id']]);
        $work = $stmt->fetch();
        if ($work) {
            $thumbnail = $work['image'] ?? '';
            if (!empty($_FILES['thumbnail']['name']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = '../uploads/works/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $result = ImageHelper::processUpload($_FILES['thumbnail']['tmp_name'], $uploadDir, uniqid('work_'), ['maxWidth' => 800, 'maxHeight' => 1200]);
                if ($result) $thumbnail = 'uploads/works/' . basename($result['path']);
            }
            $newApproval = isApproved($work) ? 'pending' : ($work['approval_status'] ?? 'pending');
            $stmt = $db->prepare("UPDATE works SET title=?, description=?, collection_id=?, image=?, approval_status=?, submitted_at=NOW() WHERE id=?");
            $stmt->execute([trim($_POST['title']), trim($_POST['description'] ?? ''), (int)$_POST['collection_id'] ?: null, $thumbnail, $newApproval, $work['id']]);
            $message = '更新しました。';
        }
    }
    
    if (isset($_POST['toggle_show'])) { $db->prepare("UPDATE works SET show_in_gallery = ? WHERE id = ? AND creator_id = ?")->execute([(int)$_POST['new_show'], (int)$_POST['work_id'], $creator['id']]); }
    if (isset($_POST['submit_for_approval'])) { $db->prepare("UPDATE works SET approval_status = 'pending', submitted_at = NOW() WHERE id = ? AND creator_id = ? AND approval_status IN ('draft', 'rejected')")->execute([(int)$_POST['work_id'], $creator['id']]); $message = '審査を申請しました。'; }
}

// 一覧取得
$cond = $showArchived ? "= 0" : "= 1";
$stmt = $db->prepare("SELECT w.*, c.title as collection_name, (SELECT COUNT(*) FROM work_pages WHERE work_id = w.id) as page_count FROM works w LEFT JOIN collections c ON w.collection_id = c.id WHERE w.creator_id = ? AND w.is_active {$cond} ORDER BY w.created_at DESC");
$stmt->execute([$creator['id']]);
$works = $stmt->fetchAll();

// 編集対象
$edit = null;
if (isset($_GET['edit'])) { $stmt = $db->prepare("SELECT * FROM works WHERE id = ? AND creator_id = ?"); $stmt->execute([(int)$_GET['edit'], $creator['id']]); $edit = $stmt->fetch(); }

$pageTitle = '作品管理';
require_once 'includes/header.php';
?>

<?= renderPageHeader('作品管理', 'ギャラリー作品の管理', !$edit && !$showArchived ? renderCreateButton("document.getElementById('createModal').classList.remove('hidden')", '新規作成', 'purple') : '') ?>
<?= renderMessage($message) ?>
<?= renderMessage($error, 'error') ?>

<?php if (!$edit): ?><?= renderArchiveTabs($showArchived, $baseUrl) ?><?php endif; ?>

<?php if ($edit): ?>
<?= renderEditFormHeader('作品編集', $baseUrl, $edit) ?>
<form method="POST" enctype="multipart/form-data" class="space-y-4">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <input type="hidden" name="work_id" value="<?= $edit['id'] ?>">
    <div class="grid md:grid-cols-2 gap-4">
        <?= renderFormField('title', '作品タイトル', 'text', $edit['title'], ['required' => true]) ?>
        <?= renderFormField('collection_id', 'コレクション', 'select', $edit['collection_id'], ['items' => $collections]) ?>
    </div>
    <?= renderFormField('description', '説明', 'textarea', $edit['description'] ?? '') ?>
    <?= renderFormField('thumbnail', 'サムネイル画像', 'file') ?>
    <?= renderFormButtons('update_work', '保存', $baseUrl, 'purple') ?>
</form>
<?= renderEditFormFooter() ?>
<?php endif; ?>

<?= renderBulkFormStart($csrfToken) ?>
<?= renderBulkButtons($showArchived) ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <table class="w-full">
        <thead class="bg-gray-50"><tr>
            <th class="px-4 py-3 w-8"><input type="checkbox" class="select-all-checkbox rounded"></th>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">作品名</th>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600 hidden md:table-cell">コレクション</th>
            <th class="px-4 py-3 text-center text-sm font-bold text-gray-600 hidden sm:table-cell">ページ数</th>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">ステータス</th>
            <th class="px-4 py-3 text-center text-sm font-bold text-gray-600">表示</th>
            <th class="px-4 py-3 text-center text-sm font-bold text-gray-600">操作</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-100">
            <?php if (empty($works)): ?><?= renderEmptyRow(7, $showArchived ? 'アーカイブはありません' : '作品がありません') ?><?php endif; ?>
            <?php foreach ($works as $w): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3"><input type="checkbox" name="selected_items[]" value="<?= $w['id'] ?>" class="item-checkbox rounded"></td>
                <td class="px-4 py-3"><div class="flex items-center gap-3"><?= renderThumbnail($w['image'] ?? null, 'w-10 h-14 hidden sm:block') ?><div><div class="font-bold text-gray-800"><?= htmlspecialchars($w['title']) ?></div><div class="text-xs text-gray-500 md:hidden"><?= htmlspecialchars($w['collection_name'] ?? '') ?></div></div></div></td>
                <td class="px-4 py-3 text-sm text-gray-600 hidden md:table-cell"><?= htmlspecialchars($w['collection_name'] ?? '-') ?></td>
                <td class="px-4 py-3 text-center text-sm hidden sm:table-cell"><?= $w['page_count'] ?>P</td>
                <td class="px-4 py-3"><?= renderApprovalBadge($w) ?></td>
                <td class="px-4 py-3 text-center">
                    <?php if (isApproved($w) && !$showArchived): ?>
                    <form method="POST" class="inline"><input type="hidden" name="csrf_token" value="<?= $csrfToken ?>"><input type="hidden" name="work_id" value="<?= $w['id'] ?>"><input type="hidden" name="new_show" value="<?= ($w['show_in_gallery'] ?? 1) ? 0 : 1 ?>"><button type="submit" name="toggle_show" value="1" class="<?= ($w['show_in_gallery'] ?? 1) ? 'text-green-500' : 'text-gray-300' ?>"><i class="fas fa-eye"></i></button></form>
                    <?php else: ?><span class="text-gray-400">-</span><?php endif; ?>
                </td>
                <td class="px-4 py-3 text-center">
                    <?php if ($showArchived): ?>
                    <a href="?restore=<?= $w['id'] ?>" class="text-blue-500 hover:text-blue-700 mx-1" title="復元"><i class="fas fa-undo"></i></a>
                    <?php else: ?>
                    <a href="?edit=<?= $w['id'] ?>" class="text-blue-500 hover:text-blue-700 mx-1" title="編集"><i class="fas fa-edit"></i></a>
                    <?php if (canSubmitForApproval($w)): ?><form method="POST" class="inline"><input type="hidden" name="csrf_token" value="<?= $csrfToken ?>"><input type="hidden" name="work_id" value="<?= $w['id'] ?>"><button type="submit" name="submit_for_approval" value="1" class="text-purple-500 hover:text-purple-700 mx-1" title="審査申請"><i class="fas fa-paper-plane"></i></button></form><?php endif; ?>
                    <a href="?archive=<?= $w['id'] ?>" onclick="return confirm('アーカイブしますか？')" class="text-orange-500 hover:text-orange-700 mx-1" title="アーカイブ"><i class="fas fa-archive"></i></a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
</form>

<?= renderModalStart('createModal', '新規作品', 'fa-plus', 'purple') ?>
<form method="POST" enctype="multipart/form-data" class="space-y-4">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <?= renderFormField('title', '作品タイトル', 'text', '', ['required' => true]) ?>
    <?= renderFormField('collection_id', 'コレクション', 'select', '', ['items' => $collections]) ?>
    <?= renderFormField('description', '説明', 'textarea') ?>
    <?= renderFormField('thumbnail', 'サムネイル画像', 'file') ?>
    <?= renderFormButtons('create_work', '作成', '', 'purple') ?>
</form>
<?= renderModalEnd() ?>

<?= renderBulkScript() ?>
<?php require_once 'includes/footer.php'; ?>
