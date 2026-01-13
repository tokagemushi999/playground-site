<?php
/**
 * クリエイターダッシュボード - サービス管理（簡略化版）
 */
session_start();
define('CURRENT_ROLE', 'creator');

require_once '../includes/db.php';
require_once '../includes/creator-auth.php';
require_once '../includes/image-helper.php';
require_once '../includes/content-manager.php';
require_once '../includes/admin-ui.php';

$creator = requireCreatorAuth();
$db = getDB();
$showArchived = isset($_GET['archived']);
$baseUrl = 'services.php';

// カテゴリ取得
$categories = [];
try { $categories = $db->query("SELECT * FROM service_categories WHERE is_active = 1 ORDER BY sort_order")->fetchAll(); } catch (PDOException $e) {}

// ContentManagerでリクエスト処理
$manager = new ContentManager($db, 'services', $creator['id']);
$manager->setBaseUrl($baseUrl);
$manager->setActiveField('status', 'active', 'archived');

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrfToken($_POST['csrf_token'] ?? '')) {
    if (isset($_POST['toggle_status'])) {
        $db->prepare("UPDATE services SET status = ? WHERE id = ? AND creator_id = ? AND approval_status = 'approved'")->execute([$_POST['new_status'], (int)$_POST['item_id'], $creator['id']]);
    }
    
    if (isset($_POST['create_service'])) {
        $title = trim($_POST['title'] ?? '');
        $base_price = (int)($_POST['base_price'] ?? 0);
        if (!empty($title) && $base_price >= 500) {
            $thumbnail = '';
            if (!empty($_FILES['thumbnail']['name']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = '../uploads/services/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $result = ImageHelper::processUpload($_FILES['thumbnail']['tmp_name'], $uploadDir, uniqid('service_'), ['maxWidth' => 800, 'maxHeight' => 600]);
                if ($result) $thumbnail = 'uploads/services/' . basename($result['path']);
            }
            $stmt = $db->prepare("INSERT INTO services (creator_id, category_id, title, description, base_price, delivery_days, thumbnail_image, status, approval_status, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, 'draft', 'pending', NOW())");
            $stmt->execute([$creator['id'], (int)$_POST['category_id'] ?: null, $title, trim($_POST['description'] ?? ''), $base_price, (int)($_POST['delivery_days'] ?? 7), $thumbnail]);
        }
        header("Location: {$baseUrl}"); exit;
    }
    
    if (isset($_POST['update_service'])) {
        $stmt = $db->prepare("SELECT * FROM services WHERE id = ? AND creator_id = ?");
        $stmt->execute([(int)$_POST['service_id'], $creator['id']]);
        $service = $stmt->fetch();
        if ($service) {
            $thumbnail = $service['thumbnail_image'];
            if (!empty($_FILES['thumbnail']['name']) && $_FILES['thumbnail']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = '../uploads/services/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $result = ImageHelper::processUpload($_FILES['thumbnail']['tmp_name'], $uploadDir, uniqid('service_'), ['maxWidth' => 800, 'maxHeight' => 600]);
                if ($result) $thumbnail = 'uploads/services/' . basename($result['path']);
            }
            $newApproval = isApproved($service) ? 'pending' : ($service['approval_status'] ?? 'pending');
            $stmt = $db->prepare("UPDATE services SET title=?, description=?, category_id=?, base_price=?, delivery_days=?, thumbnail_image=?, approval_status=?, submitted_at=NOW() WHERE id=?");
            $stmt->execute([trim($_POST['title']), trim($_POST['description'] ?? ''), (int)$_POST['category_id'] ?: null, (int)$_POST['base_price'], (int)$_POST['delivery_days'], $thumbnail, $newApproval, $service['id']]);
        }
        header("Location: {$baseUrl}"); exit;
    }
}

$manager->processRequest();
$message = $manager->getMessage();
$error = $manager->getError();
$csrfToken = $manager->getCsrfToken();

// 一覧取得
$statusCond = $showArchived ? "= 'archived'" : "!= 'archived'";
$stmt = $db->prepare("
    SELECT s.*, c.name as category_name,
           (SELECT COUNT(*) FROM service_transactions WHERE service_id = s.id) as tx_count,
           (SELECT COUNT(*) FROM service_transactions WHERE service_id = s.id AND status = 'completed') as done_count
    FROM services s LEFT JOIN service_categories c ON s.category_id = c.id
    WHERE s.creator_id = ? AND s.status {$statusCond} ORDER BY s.created_at DESC
");
$stmt->execute([$creator['id']]);
$services = $stmt->fetchAll();

// 編集対象
$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM services WHERE id = ? AND creator_id = ?");
    $stmt->execute([(int)$_GET['edit'], $creator['id']]);
    $edit = $stmt->fetch();
}

$pageTitle = 'サービス管理';
require_once 'includes/header.php';
?>

<?= renderContentHeader('サービス管理', '受注サービスの管理', !$edit ? '<button onclick="document.getElementById(\'createModal\').classList.remove(\'hidden\')" class="px-4 py-2 bg-green-500 text-white rounded-lg font-bold hover:bg-green-600"><i class="fas fa-plus mr-2"></i>新規作成</button>' : '', $showArchived, $baseUrl) ?>
<?= renderMessage($message) ?>
<?= renderMessage($error, 'error') ?>

<?php if ($edit): ?>
<?= renderEditFormHeader('サービス編集', $baseUrl, $edit) ?>
<form method="POST" enctype="multipart/form-data" class="space-y-4">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <input type="hidden" name="service_id" value="<?= $edit['id'] ?>">
    <div class="grid md:grid-cols-2 gap-4">
        <?= renderFormField('title', 'サービス名', 'text', $edit['title'], ['required' => true]) ?>
        <?= renderFormField('category_id', 'カテゴリ', 'select', $edit['category_id'], ['items' => $categories]) ?>
    </div>
    <?= renderFormField('description', '説明', 'textarea', $edit['description'] ?? '') ?>
    <div class="grid md:grid-cols-2 gap-4">
        <?= renderFormField('base_price', '基本料金', 'number', $edit['base_price'], ['required' => true, 'min' => 500]) ?>
        <?= renderFormField('delivery_days', '納品日数', 'number', $edit['delivery_days'] ?? 7, ['min' => 1]) ?>
    </div>
    <?= renderFormField('thumbnail', 'サムネイル', 'file') ?>
    <?= renderFormButtons('update_service', '保存', $baseUrl, 'green') ?>
</form>
<?= renderEditFormFooter() ?>
<?php endif; ?>

<?php if (!$edit): ?>
<?= renderBulkFormStart($csrfToken) ?>
<?= renderBulkButtons($showArchived) ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <table class="w-full">
        <thead class="bg-gray-50"><tr>
            <th class="px-4 py-3 w-8"><input type="checkbox" class="select-all-checkbox rounded"></th>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">サービス名</th>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600 hidden md:table-cell">カテゴリ</th>
            <th class="px-4 py-3 text-right text-sm font-bold text-gray-600">価格</th>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">ステータス</th>
            <th class="px-4 py-3 text-center text-sm font-bold text-gray-600 hidden sm:table-cell">取引</th>
            <th class="px-4 py-3 text-center text-sm font-bold text-gray-600">操作</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-100">
            <?php if (empty($services)): ?><?= renderEmptyRow(7, $showArchived ? 'アーカイブはありません' : 'サービスがありません') ?><?php endif; ?>
            <?php foreach ($services as $s): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3"><input type="checkbox" name="selected_items[]" value="<?= $s['id'] ?>" class="item-checkbox rounded"></td>
                <td class="px-4 py-3"><div class="flex items-center gap-3"><?= renderThumbnail($s['thumbnail_image'] ?? null) ?><div class="font-bold text-gray-800 truncate max-w-[150px]"><?= htmlspecialchars($s['title']) ?></div></div></td>
                <td class="px-4 py-3 text-sm text-gray-600 hidden md:table-cell"><?= htmlspecialchars($s['category_name'] ?? '-') ?></td>
                <td class="px-4 py-3 font-bold text-green-600 text-right"><?= formatPrice($s['base_price']) ?></td>
                <td class="px-4 py-3"><div class="flex flex-wrap gap-1"><?= renderApprovalBadge($s) ?><?php if (isApproved($s) && !$showArchived): ?><?= renderPublishBadge($s, 'status') ?><?php endif; ?></div></td>
                <td class="px-4 py-3 text-center text-sm hidden sm:table-cell"><span class="text-gray-600"><?= $s['tx_count'] ?></span><span class="text-green-600">(<?= $s['done_count'] ?>完)</span></td>
                <td class="px-4 py-3 text-center"><?= renderCreatorItemActions($s, $showArchived, $csrfToken, ['statusField' => 'status']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
</form>
<?php endif; ?>

<?= renderModalStart('createModal', '新規サービス', 'fa-plus', 'green') ?>
<form method="POST" enctype="multipart/form-data" class="space-y-4">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <?= renderFormField('title', 'サービス名', 'text', '', ['required' => true]) ?>
    <?= renderFormField('category_id', 'カテゴリ', 'select', '', ['items' => $categories]) ?>
    <?= renderFormField('description', '説明', 'textarea') ?>
    <div class="grid grid-cols-2 gap-4">
        <?= renderFormField('base_price', '基本料金', 'number', '5000', ['required' => true, 'min' => 500]) ?>
        <?= renderFormField('delivery_days', '納品日数', 'number', '7', ['min' => 1]) ?>
    </div>
    <?= renderFormField('thumbnail', 'サムネイル', 'file') ?>
    <?= renderFormButtons('create_service', '作成', '', 'green') ?>
</form>
<?= renderModalEnd() ?>

<?= renderBulkScript() ?>
<?php require_once 'includes/footer.php'; ?>
