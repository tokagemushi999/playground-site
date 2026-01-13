<?php
/**
 * クリエイターダッシュボード - コレクション管理（簡略化版）
 */
session_start();
define('CURRENT_ROLE', 'creator');

require_once '../includes/db.php';
require_once '../includes/csrf.php';
require_once '../includes/creator-auth.php';
require_once '../includes/admin-ui.php';

$creator = requireCreatorAuth();
$db = getDB();
$message = '';
$error = '';
$showArchived = isset($_GET['archived']);
$baseUrl = 'collections.php';
$csrfToken = generateCsrfToken();

// カテゴリ・表示スタイル定義
$categoryOptions = [
    ['id' => 'illustration', 'name' => 'イラスト'],
    ['id' => 'manga', 'name' => 'マンガ'],
    ['id' => 'design', 'name' => 'デザイン'],
    ['id' => 'photo', 'name' => '写真'],
    ['id' => 'other', 'name' => 'その他'],
];
$styleOptions = [
    ['id' => 'grid', 'name' => 'グリッド表示'],
    ['id' => 'manga', 'name' => 'マンガビューア'],
    ['id' => 'slide', 'name' => 'スライドショー'],
];

// アーカイブ/復元処理
if (isset($_GET['archive'])) { $db->prepare("UPDATE collections SET is_active = 0 WHERE id = ? AND creator_id = ?")->execute([(int)$_GET['archive'], $creator['id']]); header("Location: {$baseUrl}"); exit; }
if (isset($_GET['restore'])) { $db->prepare("UPDATE collections SET is_active = 1 WHERE id = ? AND creator_id = ? AND is_active = 0")->execute([(int)$_GET['restore'], $creator['id']]); header("Location: {$baseUrl}?archived=1"); exit; }

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrfToken($_POST['csrf_token'] ?? '')) {
    $ids = array_map('intval', $_POST['selected_items'] ?? []);
    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($ids, [$creator['id']]);
        if (isset($_POST['bulk_archive'])) { $db->prepare("UPDATE collections SET is_active = 0 WHERE id IN ($ph) AND creator_id = ?")->execute($params); $message = count($ids) . '件をアーカイブしました。'; }
        elseif (isset($_POST['bulk_restore'])) { $db->prepare("UPDATE collections SET is_active = 1 WHERE id IN ($ph) AND creator_id = ? AND is_active = 0")->execute($params); $message = count($ids) . '件を復元しました。'; }
    }
    
    if (isset($_POST['create_collection'])) {
        $title = trim($_POST['title'] ?? '');
        if (empty($title)) $error = 'コレクション名は必須です';
        else {
            $stmt = $db->prepare("INSERT INTO collections (creator_id, title, description, category, display_style, is_active, sort_order) VALUES (?, ?, ?, ?, ?, 1, 0)");
            $stmt->execute([$creator['id'], $title, trim($_POST['description'] ?? ''), $_POST['category'] ?? 'illustration', $_POST['display_style'] ?? 'grid']);
            $message = 'コレクションを作成しました。';
        }
    }
    
    if (isset($_POST['update_collection'])) {
        $stmt = $db->prepare("SELECT * FROM collections WHERE id = ? AND creator_id = ?");
        $stmt->execute([(int)$_POST['collection_id'], $creator['id']]);
        $collection = $stmt->fetch();
        if ($collection) {
            $stmt = $db->prepare("UPDATE collections SET title=?, description=?, category=?, display_style=?, store_url=?, store_text=? WHERE id=?");
            $stmt->execute([trim($_POST['title']), trim($_POST['description'] ?? ''), $_POST['category'] ?? 'illustration', $_POST['display_style'] ?? 'grid', trim($_POST['store_url'] ?? ''), trim($_POST['store_text'] ?? 'ストアで見る'), $collection['id']]);
            $message = '更新しました。';
        }
    }
}

// 一覧取得
$cond = $showArchived ? "= 0" : "= 1";
$stmt = $db->prepare("SELECT c.*, (SELECT COUNT(*) FROM works WHERE collection_id = c.id AND is_active = 1) as work_count FROM collections c WHERE c.creator_id = ? AND c.is_active {$cond} ORDER BY c.sort_order, c.title");
$stmt->execute([$creator['id']]);
$collections = $stmt->fetchAll();

// 編集対象
$edit = null;
if (isset($_GET['edit'])) { $stmt = $db->prepare("SELECT * FROM collections WHERE id = ? AND creator_id = ?"); $stmt->execute([(int)$_GET['edit'], $creator['id']]); $edit = $stmt->fetch(); }

$pageTitle = 'コレクション管理';
require_once 'includes/header.php';
?>

<?= renderPageHeader('コレクション管理', '作品のグループ管理', !$edit && !$showArchived ? renderCreateButton("document.getElementById('createModal').classList.remove('hidden')", '新規作成', 'indigo') : '') ?>
<?= renderMessage($message) ?>
<?= renderMessage($error, 'error') ?>

<?php if (!$edit): ?><?= renderArchiveTabs($showArchived, $baseUrl) ?><?php endif; ?>

<?php if ($edit): ?>
<?= renderEditFormHeader('コレクション編集', $baseUrl) ?>
<form method="POST" class="space-y-4">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <input type="hidden" name="collection_id" value="<?= $edit['id'] ?>">
    <?= renderFormField('title', 'コレクション名', 'text', $edit['title'], ['required' => true]) ?>
    <?= renderFormField('description', '説明', 'textarea', $edit['description'] ?? '') ?>
    <div class="grid md:grid-cols-2 gap-4">
        <?= renderFormField('category', 'カテゴリ', 'select', $edit['category'] ?? 'illustration', ['items' => $categoryOptions]) ?>
        <?= renderFormField('display_style', '表示スタイル', 'select', $edit['display_style'] ?? 'grid', ['items' => $styleOptions]) ?>
    </div>
    <div class="grid md:grid-cols-2 gap-4">
        <?= renderFormField('store_url', 'ストアURL', 'text', $edit['store_url'] ?? '', ['placeholder' => 'https://...']) ?>
        <?= renderFormField('store_text', 'ストアボタンテキスト', 'text', $edit['store_text'] ?? 'ストアで見る') ?>
    </div>
    <?= renderFormButtons('update_collection', '保存', $baseUrl, 'indigo') ?>
</form>
<?= renderEditFormFooter() ?>
<?php endif; ?>

<?= renderBulkFormStart($csrfToken) ?>
<?= renderBulkButtons($showArchived) ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <table class="w-full">
        <thead class="bg-gray-50"><tr>
            <th class="px-4 py-3 w-8"><input type="checkbox" class="select-all-checkbox rounded"></th>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">コレクション名</th>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600 hidden md:table-cell">カテゴリ</th>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600 hidden sm:table-cell">表示形式</th>
            <th class="px-4 py-3 text-center text-sm font-bold text-gray-600">作品数</th>
            <th class="px-4 py-3 text-center text-sm font-bold text-gray-600">操作</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-100">
            <?php if (empty($collections)): ?><?= renderEmptyRow(6, $showArchived ? 'アーカイブはありません' : 'コレクションがありません') ?><?php endif; ?>
            <?php foreach ($collections as $c): 
                $catLabels = ['illustration' => 'イラスト', 'manga' => 'マンガ', 'design' => 'デザイン', 'photo' => '写真', 'other' => 'その他'];
                $styleLabels = ['grid' => 'グリッド', 'manga' => 'ビューア', 'slide' => 'スライド'];
            ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3"><input type="checkbox" name="selected_items[]" value="<?= $c['id'] ?>" class="item-checkbox rounded"></td>
                <td class="px-4 py-3"><div class="font-bold text-gray-800"><?= htmlspecialchars($c['title']) ?></div><div class="text-xs text-gray-500 line-clamp-1"><?= htmlspecialchars($c['description'] ?? '') ?></div></td>
                <td class="px-4 py-3 text-sm text-gray-600 hidden md:table-cell"><?= $catLabels[$c['category'] ?? 'other'] ?? $c['category'] ?></td>
                <td class="px-4 py-3 text-sm text-gray-600 hidden sm:table-cell"><?= $styleLabels[$c['display_style'] ?? 'grid'] ?? $c['display_style'] ?></td>
                <td class="px-4 py-3 text-center"><span class="px-2 py-1 bg-purple-100 text-purple-700 rounded text-xs font-bold"><?= $c['work_count'] ?>作品</span></td>
                <td class="px-4 py-3 text-center">
                    <?php if ($showArchived): ?>
                    <a href="?restore=<?= $c['id'] ?>" class="text-blue-500 hover:text-blue-700 mx-1" title="復元"><i class="fas fa-undo"></i></a>
                    <?php else: ?>
                    <a href="?edit=<?= $c['id'] ?>" class="text-blue-500 hover:text-blue-700 mx-1" title="編集"><i class="fas fa-edit"></i></a>
                    <a href="?archive=<?= $c['id'] ?>" onclick="return confirm('アーカイブしますか？')" class="text-orange-500 hover:text-orange-700 mx-1" title="アーカイブ"><i class="fas fa-archive"></i></a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
</form>

<?= renderModalStart('createModal', '新規コレクション', 'fa-folder-plus', 'indigo') ?>
<form method="POST" class="space-y-4">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <?= renderFormField('title', 'コレクション名', 'text', '', ['required' => true]) ?>
    <?= renderFormField('description', '説明', 'textarea') ?>
    <div class="grid grid-cols-2 gap-4">
        <?= renderFormField('category', 'カテゴリ', 'select', 'illustration', ['items' => $categoryOptions]) ?>
        <?= renderFormField('display_style', '表示スタイル', 'select', 'grid', ['items' => $styleOptions]) ?>
    </div>
    <?= renderFormButtons('create_collection', '作成', '', 'indigo') ?>
</form>
<?= renderModalEnd() ?>

<?= renderBulkScript() ?>
<?php require_once 'includes/footer.php'; ?>
