<?php
/**
 * クリエイターダッシュボード - 商品管理（簡略化版）
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
$showArchived = isset($_GET['archived']);
$baseUrl = 'products.php';
$csrfToken = generateCsrfToken();
$message = '';
$error = '';

// カテゴリ取得
$categories = [];
try { $categories = $db->query("SELECT * FROM product_categories WHERE is_active = 1 ORDER BY sort_order")->fetchAll(); } catch (PDOException $e) {}

// アーカイブ/復元処理（is_publishedカラム使用: 0=アーカイブ, 1=公開）
if (isset($_GET['archive'])) {
    $db->prepare("UPDATE products SET is_published = 0 WHERE id = ? AND creator_id = ?")->execute([(int)$_GET['archive'], $creator['id']]);
    header("Location: {$baseUrl}"); exit;
}
if (isset($_GET['restore'])) {
    $db->prepare("UPDATE products SET is_published = 1 WHERE id = ? AND creator_id = ? AND is_published = 0")->execute([(int)$_GET['restore'], $creator['id']]);
    header("Location: {$baseUrl}?archived=1"); exit;
}

// POST処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && validateCsrfToken($_POST['csrf_token'] ?? '')) {
    // 一括操作
    $ids = array_map('intval', $_POST['selected_items'] ?? []);
    if (!empty($ids)) {
        $ph = implode(',', array_fill(0, count($ids), '?'));
        $params = array_merge($ids, [$creator['id']]);
        if (isset($_POST['bulk_archive'])) {
            $db->prepare("UPDATE products SET is_published = 0 WHERE id IN ($ph) AND creator_id = ?")->execute($params);
            $message = count($ids) . '件をアーカイブしました。';
        } elseif (isset($_POST['bulk_restore'])) {
            $db->prepare("UPDATE products SET is_published = 1 WHERE id IN ($ph) AND creator_id = ? AND is_published = 0")->execute($params);
            $message = count($ids) . '件を復元しました。';
        }
    }

    // 公開/非公開切替
    if (isset($_POST['toggle_publish'])) {
        $db->prepare("UPDATE products SET is_published = ? WHERE id = ? AND creator_id = ? AND (approval_status = 'approved' OR approval_status IS NULL)")->execute([(int)$_POST['new_value'], (int)$_POST['item_id'], $creator['id']]);
    }
    
    // 新規作成
    if (isset($_POST['create_product'])) {
        $name = trim($_POST['name'] ?? '');
        $price = (int)($_POST['price'] ?? 0);
        if (!empty($name) && $price >= 100) {
            $image = '';
            if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = '../uploads/products/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $result = ImageHelper::processUpload($_FILES['image']['tmp_name'], $uploadDir, uniqid('product_'), ['maxWidth' => 800, 'maxHeight' => 800]);
                if ($result) $image = 'uploads/products/' . basename($result['path']);
            }
            $stmt = $db->prepare("INSERT INTO products (creator_id, category, name, description, price, stock_quantity, image, is_published, approval_status, submitted_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, 'pending', NOW())");
            $stmt->execute([$creator['id'], $_POST['category_id'] ?? null, $name, trim($_POST['description'] ?? ''), $price, (int)($_POST['stock'] ?? 0), $image]);
            $message = '商品を作成しました。';
        } else {
            $error = '商品名と価格（100円以上）は必須です。';
        }
    }
    
    // 編集
    if (isset($_POST['update_product'])) {
        $stmt = $db->prepare("SELECT * FROM products WHERE id = ? AND creator_id = ?");
        $stmt->execute([(int)$_POST['product_id'], $creator['id']]);
        $product = $stmt->fetch();
        if ($product) {
            $image = $product['image'];
            if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = '../uploads/products/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                $result = ImageHelper::processUpload($_FILES['image']['tmp_name'], $uploadDir, uniqid('product_'), ['maxWidth' => 800, 'maxHeight' => 800]);
                if ($result) $image = 'uploads/products/' . basename($result['path']);
            }
            $newApproval = isApproved($product) ? 'pending' : ($product['approval_status'] ?? 'pending');
            $stmt = $db->prepare("UPDATE products SET name=?, description=?, category=?, price=?, stock_quantity=?, image=?, approval_status=?, submitted_at=NOW() WHERE id=?");
            $stmt->execute([trim($_POST['name']), trim($_POST['description'] ?? ''), $_POST['category_id'] ?? null, (int)$_POST['price'], (int)$_POST['stock'], $image, $newApproval, $product['id']]);
            $message = '更新しました。';
        }
    }
    
    // 審査申請
    if (isset($_POST['submit_for_approval'])) {
        $db->prepare("UPDATE products SET approval_status = 'pending', submitted_at = NOW() WHERE id = ? AND creator_id = ? AND approval_status IN ('draft', 'rejected')")->execute([(int)$_POST['item_id'], $creator['id']]);
        $message = '審査を申請しました。';
    }
    
    if (!isset($_POST['toggle_publish'])) {
        header("Location: {$baseUrl}" . ($showArchived ? '?archived=1' : '')); exit;
    }
}

// 一覧取得（is_publishedカラム使用）
$publishCond = $showArchived ? "= 0" : "= 1";
$stmt = $db->prepare("
    SELECT p.*, c.name as category_name,
           (SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi JOIN orders o ON oi.order_id = o.id WHERE oi.product_id = p.id AND o.order_status = 'completed') as sold_count
    FROM products p LEFT JOIN product_categories c ON p.category = c.id
    WHERE p.creator_id = ? AND p.is_published {$publishCond} ORDER BY p.created_at DESC
");
$stmt->execute([$creator['id']]);
$products = $stmt->fetchAll();

// 編集対象
$edit = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM products WHERE id = ? AND creator_id = ?");
    $stmt->execute([(int)$_GET['edit'], $creator['id']]);
    $edit = $stmt->fetch();
}

$pageTitle = '商品管理';
require_once 'includes/header.php';
?>

<?= renderContentHeader('商品管理', '販売商品の管理', !$edit ? '<button onclick="document.getElementById(\'createModal\').classList.remove(\'hidden\')" class="px-4 py-2 bg-blue-500 text-white rounded-lg font-bold hover:bg-blue-600"><i class="fas fa-plus mr-2"></i>新規作成</button>' : '', $showArchived, $baseUrl) ?>
<?= renderMessage($message) ?>
<?= renderMessage($error, 'error') ?>

<?php if ($edit): ?>
<?= renderEditFormHeader('商品編集', $baseUrl, $edit) ?>
<form method="POST" enctype="multipart/form-data" class="space-y-4">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <input type="hidden" name="product_id" value="<?= $edit['id'] ?>">
    <div class="grid md:grid-cols-2 gap-4">
        <?= renderFormField('name', '商品名', 'text', $edit['name'], ['required' => true]) ?>
        <?= renderFormField('category_id', 'カテゴリ', 'select', $edit['category'], ['items' => $categories]) ?>
    </div>
    <?= renderFormField('description', '説明', 'textarea', $edit['description'] ?? '') ?>
    <div class="grid md:grid-cols-2 gap-4">
        <?= renderFormField('price', '価格', 'number', $edit['price'], ['required' => true, 'min' => 100]) ?>
        <?= renderFormField('stock', '在庫数', 'number', $edit['stock_quantity'] ?? 0, ['min' => 0]) ?>
    </div>
    <?= renderFormField('image', '商品画像', 'file') ?>
    <?= renderFormButtons('update_product', '保存', $baseUrl, 'blue') ?>
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
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">商品名</th>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600 hidden md:table-cell">カテゴリ</th>
            <th class="px-4 py-3 text-right text-sm font-bold text-gray-600">価格</th>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">ステータス</th>
            <th class="px-4 py-3 text-center text-sm font-bold text-gray-600 hidden sm:table-cell">在庫/売上</th>
            <th class="px-4 py-3 text-center text-sm font-bold text-gray-600">操作</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-100">
            <?php if (empty($products)): ?><?= renderEmptyRow(7, $showArchived ? 'アーカイブはありません' : '商品がありません') ?><?php endif; ?>
            <?php foreach ($products as $p): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3"><input type="checkbox" name="selected_items[]" value="<?= $p['id'] ?>" class="item-checkbox rounded"></td>
                <td class="px-4 py-3"><div class="flex items-center gap-3"><?= renderThumbnail($p['image'] ?? null) ?><div class="font-bold text-gray-800"><?= htmlspecialchars($p['name']) ?></div></div></td>
                <td class="px-4 py-3 text-sm text-gray-600 hidden md:table-cell"><?= htmlspecialchars($p['category_name'] ?? '-') ?></td>
                <td class="px-4 py-3 font-bold text-green-600 text-right"><?= formatPrice($p['price']) ?></td>
                <td class="px-4 py-3"><div class="flex flex-wrap gap-1"><?= renderApprovalBadge($p) ?></div></td>
                <td class="px-4 py-3 text-center text-sm hidden sm:table-cell"><span class="<?= ($p['stock_quantity'] ?? 0) > 0 ? 'text-gray-800' : 'text-red-600' ?>"><?= $p['stock_quantity'] ?? 0 ?></span><span class="text-green-600">(<?= $p['sold_count'] ?? 0 ?>売)</span></td>
                <td class="px-4 py-3 text-center">
                    <?php if ($showArchived): ?>
                    <a href="?restore=<?= $p['id'] ?>" class="text-blue-500 hover:text-blue-700 mx-1" title="復元"><i class="fas fa-undo"></i></a>
                    <?php else: ?>
                    <a href="?edit=<?= $p['id'] ?>" class="text-blue-500 hover:text-blue-700 mx-1" title="編集"><i class="fas fa-edit"></i></a>
                    <?php if (isApproved($p)): ?>
                    <form method="POST" class="inline"><input type="hidden" name="csrf_token" value="<?= $csrfToken ?>"><input type="hidden" name="item_id" value="<?= $p['id'] ?>"><input type="hidden" name="new_value" value="<?= $p['is_published'] ? 0 : 1 ?>"><button type="submit" name="toggle_publish" value="1" class="<?= $p['is_published'] ? 'text-yellow-500' : 'text-green-500' ?> hover:opacity-70 mx-1" title="<?= $p['is_published'] ? '非公開' : '公開' ?>"><i class="fas <?= $p['is_published'] ? 'fa-pause' : 'fa-play' ?>"></i></button></form>
                    <?php elseif (canSubmitForApproval($p)): ?>
                    <form method="POST" class="inline"><input type="hidden" name="csrf_token" value="<?= $csrfToken ?>"><input type="hidden" name="item_id" value="<?= $p['id'] ?>"><button type="submit" name="submit_for_approval" value="1" class="text-purple-500 hover:text-purple-700 mx-1" title="審査申請"><i class="fas fa-paper-plane"></i></button></form>
                    <?php endif; ?>
                    <a href="?archive=<?= $p['id'] ?>" onclick="return confirm('アーカイブしますか？')" class="text-orange-500 hover:text-orange-700 mx-1" title="アーカイブ"><i class="fas fa-archive"></i></a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
</form>
<?php endif; ?>

<?= renderModalStart('createModal', '新規商品', 'fa-plus', 'blue') ?>
<form method="POST" enctype="multipart/form-data" class="space-y-4">
    <input type="hidden" name="csrf_token" value="<?= $csrfToken ?>">
    <?= renderFormField('name', '商品名', 'text', '', ['required' => true]) ?>
    <?= renderFormField('category_id', 'カテゴリ', 'select', '', ['items' => $categories]) ?>
    <?= renderFormField('description', '説明', 'textarea') ?>
    <div class="grid grid-cols-2 gap-4">
        <?= renderFormField('price', '価格', 'number', '1000', ['required' => true, 'min' => 100]) ?>
        <?= renderFormField('stock', '在庫数', 'number', '10', ['min' => 0]) ?>
    </div>
    <?= renderFormField('image', '商品画像', 'file') ?>
    <?= renderFormButtons('create_product', '作成', '', 'blue') ?>
</form>
<?= renderModalEnd() ?>

<?= renderBulkScript() ?>
<?php require_once 'includes/footer.php'; ?>
