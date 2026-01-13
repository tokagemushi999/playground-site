<?php
/**
 * 商品カテゴリ管理
 */
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/site-settings.php';
require_once '../includes/defaults.php';
requireAuth();

$db = getDB();
$message = '';
$error = '';

// テーブル作成
if (!tableExists($db, 'product_categories')) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS product_categories (
            id INT AUTO_INCREMENT PRIMARY KEY,
            name VARCHAR(100) NOT NULL COMMENT 'カテゴリ名',
            slug VARCHAR(100) NOT NULL UNIQUE COMMENT 'URL用スラッグ',
            description TEXT COMMENT '説明',
            icon VARCHAR(50) DEFAULT NULL COMMENT 'FontAwesomeアイコン',
            color VARCHAR(20) DEFAULT NULL COMMENT 'テーマカラー',
            sort_order INT DEFAULT 0 COMMENT '表示順',
            is_active TINYINT(1) DEFAULT 1 COMMENT '有効/無効',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // デフォルトカテゴリを挿入
    insertDefaultCategories($db);
    $message = 'テーブルを作成し、初期カテゴリを追加しました';
}

// 削除
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $stmt = $db->prepare("DELETE FROM product_categories WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    header('Location: store-categories.php?msg=deleted');
    exit;
}

// 保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? null;
    $name = trim($_POST['name'] ?? '');
    $slug = trim($_POST['slug'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $icon = trim($_POST['icon'] ?? '');
    $color = trim($_POST['color'] ?? '#FF6B35');
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    
    // スラッグ自動生成
    if (empty($slug) && !empty($name)) {
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
    }
    
    if (empty($name) || empty($slug)) {
        $error = 'カテゴリ名とスラッグを入力してください';
    } else {
        // スラッグ重複チェック
        $stmt = $db->prepare("SELECT id FROM product_categories WHERE slug = ? AND id != ?");
        $stmt->execute([$slug, $id ?? 0]);
        if ($stmt->fetch()) {
            $error = 'このスラッグは既に使用されています';
        } else {
            if ($id) {
                $stmt = $db->prepare("
                    UPDATE product_categories 
                    SET name = ?, slug = ?, description = ?, icon = ?, color = ?, is_active = ?, sort_order = ?
                    WHERE id = ?
                ");
                $stmt->execute([$name, $slug, $description, $icon, $color, $isActive, $sortOrder, $id]);
            } else {
                $stmt = $db->prepare("
                    INSERT INTO product_categories (name, slug, description, icon, color, is_active, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $slug, $description, $icon, $color, $isActive, $sortOrder]);
            }
            header('Location: store-categories.php?msg=saved');
            exit;
        }
    }
}

// 編集対象取得
$editItem = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM product_categories WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editItem = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 一覧取得
$categories = $db->query("SELECT * FROM product_categories ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['msg'])) {
    $message = $_GET['msg'] === 'saved' ? '保存しました' : '削除しました';
}

$pageTitle = 'カテゴリ管理';
include 'includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-800"><i class="fas fa-tags text-green-500 mr-2"></i>カテゴリ管理</h1>
    </div>
    
    <?php if ($message): ?>
    <div class="bg-green-100 border border-green-300 text-green-700 px-4 py-3 rounded mb-4"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="bg-red-100 border border-red-300 text-red-700 px-4 py-3 rounded mb-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <!-- フォーム -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-lg font-bold text-gray-800 mb-4"><?= $editItem ? 'カテゴリを編集' : '新規追加' ?></h2>
        <form method="POST" class="space-y-4">
            <?php if ($editItem): ?>
            <input type="hidden" name="id" value="<?= $editItem['id'] ?>">
            <?php endif; ?>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">カテゴリ名 <span class="text-red-500">*</span></label>
                    <input type="text" name="name" required value="<?= htmlspecialchars($editItem['name'] ?? '') ?>"
                           class="w-full border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-yellow-400 focus:border-transparent">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">スラッグ <span class="text-red-500">*</span></label>
                    <input type="text" name="slug" value="<?= htmlspecialchars($editItem['slug'] ?? '') ?>"
                           class="w-full border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-yellow-400 focus:border-transparent" placeholder="manga">
                    <p class="text-xs text-gray-500 mt-1">URLに使用されます（空欄で自動生成）</p>
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">説明</label>
                <textarea name="description" rows="2" class="w-full border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-yellow-400 focus:border-transparent"><?= htmlspecialchars($editItem['description'] ?? '') ?></textarea>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">アイコン</label>
                    <input type="text" name="icon" value="<?= htmlspecialchars($editItem['icon'] ?? 'fa-tag') ?>"
                           class="w-full border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-yellow-400 focus:border-transparent" placeholder="fa-book">
                    <p class="text-xs text-gray-500 mt-1">FontAwesomeクラス名</p>
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">カラー</label>
                    <div class="flex gap-2">
                        <input type="color" name="color" value="<?= htmlspecialchars($editItem['color'] ?? '#FF6B35') ?>"
                               class="w-12 h-10 border border-gray-300 rounded cursor-pointer">
                        <input type="text" id="color-text" value="<?= htmlspecialchars($editItem['color'] ?? '#FF6B35') ?>" 
                               class="flex-1 border border-gray-300 rounded px-3 py-2 text-sm"
                               onchange="document.querySelector('input[name=color]').value = this.value">
                    </div>
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">表示順</label>
                    <input type="number" name="sort_order" value="<?= $editItem['sort_order'] ?? 0 ?>"
                           class="w-full border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-yellow-400 focus:border-transparent">
                </div>
            </div>
            
            <div class="flex items-center gap-4">
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="is_active" value="1" <?= ($editItem['is_active'] ?? 1) ? 'checked' : '' ?> class="rounded border-gray-300">
                    <span class="text-gray-700">有効</span>
                </label>
            </div>
            
            <div class="flex gap-2">
                <button type="submit" class="px-6 py-2 bg-yellow-400 hover:bg-yellow-500 rounded font-bold text-gray-800">
                    <i class="fas fa-save mr-1"></i> 保存
                </button>
                <?php if ($editItem): ?>
                <a href="store-categories.php" class="px-6 py-2 bg-gray-200 hover:bg-gray-300 rounded font-bold text-gray-700">キャンセル</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    
    <!-- 一覧 -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-100">
                <tr>
                    <th class="px-4 py-3 text-left text-sm font-bold text-gray-700">カテゴリ</th>
                    <th class="px-4 py-3 text-left text-sm font-bold text-gray-700">スラッグ</th>
                    <th class="px-4 py-3 text-center text-sm font-bold text-gray-700">プレビュー</th>
                    <th class="px-4 py-3 text-center text-sm font-bold text-gray-700">状態</th>
                    <th class="px-4 py-3 text-center text-sm font-bold text-gray-700">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($categories as $cat): ?>
                <tr class="border-t border-gray-200 hover:bg-gray-50">
                    <td class="px-4 py-3 font-bold text-gray-800"><?= htmlspecialchars($cat['name']) ?></td>
                    <td class="px-4 py-3 text-sm text-gray-500"><?= htmlspecialchars($cat['slug']) ?></td>
                    <td class="px-4 py-3 text-center">
                        <div class="inline-flex items-center gap-2 px-3 py-1 rounded" style="background-color: <?= htmlspecialchars($cat['color']) ?>20">
                            <i class="fas <?= htmlspecialchars($cat['icon'] ?? 'fa-tag') ?>" style="color: <?= htmlspecialchars($cat['color']) ?>"></i>
                            <span style="color: <?= htmlspecialchars($cat['color']) ?>"><?= htmlspecialchars($cat['name']) ?></span>
                        </div>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($cat['is_active']): ?>
                        <span class="text-green-500"><i class="fas fa-check-circle"></i></span>
                        <?php else: ?>
                        <span class="text-gray-400"><i class="fas fa-eye-slash"></i></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <a href="?edit=<?= $cat['id'] ?>" class="text-blue-500 hover:text-blue-700 mr-3"><i class="fas fa-edit"></i></a>
                        <a href="?delete=<?= $cat['id'] ?>" onclick="return confirm('削除しますか？')" class="text-red-500 hover:text-red-700"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($categories)): ?>
                <tr><td colspan="5" class="px-4 py-8 text-center text-gray-500">カテゴリはありません</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <!-- アイコン参考 -->
    <div class="mt-6 bg-white rounded-lg shadow p-4">
        <h3 class="font-bold text-gray-800 mb-2">よく使うアイコン</h3>
        <div class="flex flex-wrap gap-3 text-sm text-gray-600">
            <span><i class="fas fa-book mr-1"></i>fa-book</span>
            <span><i class="fas fa-images mr-1"></i>fa-images</span>
            <span><i class="fas fa-gift mr-1"></i>fa-gift</span>
            <span><i class="fas fa-download mr-1"></i>fa-download</span>
            <span><i class="fas fa-music mr-1"></i>fa-music</span>
            <span><i class="fas fa-video mr-1"></i>fa-video</span>
            <span><i class="fas fa-tshirt mr-1"></i>fa-tshirt</span>
            <span><i class="fas fa-paint-brush mr-1"></i>fa-paint-brush</span>
        </div>
    </div>
</div>

<script>
document.querySelector('input[name=color]').addEventListener('input', function() {
    document.getElementById('color-text').value = this.value;
});
</script>

<?php include 'includes/footer.php'; ?>
