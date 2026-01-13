<?php
/**
 * サービスカテゴリ管理画面
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/site-settings.php';
requireAuth();

$db = getDB();
$message = '';
$error = '';

// 保存処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = '不正なリクエストです。';
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $name = trim($_POST['name']);
        $slug = trim($_POST['slug']) ?: preg_replace('/[^a-z0-9\-]/', '', strtolower(str_replace(' ', '-', $name)));
        $icon = trim($_POST['icon'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        $is_active = isset($_POST['is_active']) ? 1 : 0;
        
        try {
            if ($id > 0) {
                $stmt = $db->prepare("UPDATE service_categories SET name = ?, slug = ?, icon = ?, description = ?, sort_order = ?, is_active = ? WHERE id = ?");
                $stmt->execute([$name, $slug, $icon, $description, $sort_order, $is_active, $id]);
                $message = 'カテゴリを更新しました。';
            } else {
                $stmt = $db->prepare("INSERT INTO service_categories (name, slug, icon, description, sort_order, is_active) VALUES (?, ?, ?, ?, ?, ?)");
                $stmt->execute([$name, $slug, $icon, $description, $sort_order, $is_active]);
                $message = 'カテゴリを登録しました。';
            }
        } catch (PDOException $e) {
            $error = 'エラー: ' . $e->getMessage();
        }
    }
}

// 削除処理
if (isset($_GET['delete']) && isset($_GET['csrf_token'])) {
    if (validateCsrfToken($_GET['csrf_token'])) {
        $deleteId = (int)$_GET['delete'];
        
        // 使用中のサービスがあるか確認
        $stmt = $db->prepare("SELECT COUNT(*) FROM services WHERE category_id = ?");
        $stmt->execute([$deleteId]);
        $usageCount = $stmt->fetchColumn();
        
        if ($usageCount > 0) {
            $error = "このカテゴリは{$usageCount}件のサービスで使用中のため削除できません。";
        } else {
            try {
                $stmt = $db->prepare("DELETE FROM service_categories WHERE id = ?");
                $stmt->execute([$deleteId]);
                $message = 'カテゴリを削除しました。';
            } catch (PDOException $e) {
                $error = 'エラー: ' . $e->getMessage();
            }
        }
    }
}

// 編集データ取得
$editCategory = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $db->prepare("SELECT * FROM service_categories WHERE id = ?");
    $stmt->execute([$editId]);
    $editCategory = $stmt->fetch();
}

// カテゴリ一覧取得
$categories = $db->query("
    SELECT sc.*, 
           (SELECT COUNT(*) FROM services WHERE category_id = sc.id) as service_count
    FROM service_categories sc
    ORDER BY sc.sort_order, sc.name
")->fetchAll();

$pageTitle = 'サービスカテゴリ管理';
require_once 'includes/header.php';
require_once 'includes/sidebar.php';
?>

<div class="flex-1 flex flex-col overflow-hidden">
    <header class="bg-white shadow-sm border-b border-gray-200 px-6 py-4">
        <div class="flex items-center justify-between">
            <h1 class="text-xl font-bold text-gray-800">
                <i class="fas fa-tags text-orange-500 mr-2"></i>サービスカテゴリ管理
            </h1>
        </div>
    </header>

    <main class="flex-1 overflow-y-auto p-6 bg-gray-50">
        <?php if ($message): ?>
        <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-6">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-lg mb-6">
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <div class="grid lg:grid-cols-3 gap-6">
            <!-- 登録・編集フォーム -->
            <div class="lg:col-span-1">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h2 class="text-lg font-bold text-gray-800 mb-4">
                        <i class="fas fa-<?= $editCategory ? 'edit' : 'plus' ?> text-orange-500 mr-2"></i>
                        <?= $editCategory ? 'カテゴリ編集' : '新規登録' ?>
                    </h2>
                    
                    <form method="POST">
                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                        <input type="hidden" name="id" value="<?= $editCategory['id'] ?? 0 ?>">
                        <input type="hidden" name="save" value="1">
                        
                        <div class="mb-4">
                            <label class="block text-sm font-bold text-gray-700 mb-1">カテゴリ名 <span class="text-red-500">*</span></label>
                            <input type="text" name="name" required value="<?= htmlspecialchars($editCategory['name'] ?? '') ?>"
                                   placeholder="例: イラスト制作"
                                   class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-orange-400 outline-none">
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-bold text-gray-700 mb-1">スラッグ</label>
                            <input type="text" name="slug" value="<?= htmlspecialchars($editCategory['slug'] ?? '') ?>"
                                   placeholder="illustration（空欄で自動生成）"
                                   class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-orange-400 outline-none">
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-bold text-gray-700 mb-1">アイコン（FontAwesome）</label>
                            <input type="text" name="icon" value="<?= htmlspecialchars($editCategory['icon'] ?? '') ?>"
                                   placeholder="fa-paint-brush"
                                   class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-orange-400 outline-none">
                            <p class="text-xs text-gray-500 mt-1">
                                <a href="https://fontawesome.com/icons" target="_blank" class="text-orange-500 hover:underline">FontAwesome Icons</a>
                            </p>
                        </div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-bold text-gray-700 mb-1">説明</label>
                            <textarea name="description" rows="2"
                                      placeholder="カテゴリの説明..."
                                      class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-orange-400 outline-none"><?= htmlspecialchars($editCategory['description'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4 mb-4">
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-1">表示順</label>
                                <input type="number" name="sort_order" value="<?= $editCategory['sort_order'] ?? 0 ?>"
                                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-orange-400 outline-none">
                            </div>
                            <div class="flex items-end">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" name="is_active" value="1" 
                                           <?= ($editCategory['is_active'] ?? 1) ? 'checked' : '' ?>
                                           class="w-5 h-5 rounded border-gray-300 text-orange-500 focus:ring-orange-400">
                                    <span class="text-sm font-bold text-gray-700">有効</span>
                                </label>
                            </div>
                        </div>
                        
                        <div class="flex gap-2">
                            <button type="submit" class="flex-1 px-4 py-2 bg-orange-500 text-white rounded-lg font-bold hover:bg-orange-600 transition">
                                <i class="fas fa-save mr-1"></i>保存
                            </button>
                            <?php if ($editCategory): ?>
                            <a href="service-categories.php" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg font-bold hover:bg-gray-400 transition">
                                キャンセル
                            </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- カテゴリ一覧 -->
            <div class="lg:col-span-2">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <div class="px-6 py-4 border-b bg-gray-50">
                        <h2 class="text-lg font-bold text-gray-800">
                            <i class="fas fa-list text-orange-500 mr-2"></i>カテゴリ一覧
                        </h2>
                    </div>
                    
                    <?php if (empty($categories)): ?>
                    <div class="p-8 text-center text-gray-500">
                        <i class="fas fa-folder-open text-4xl text-gray-300 mb-3"></i>
                        <p>カテゴリがまだ登録されていません</p>
                    </div>
                    <?php else: ?>
                    <table class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">カテゴリ名</th>
                                <th class="px-4 py-3 text-center text-sm font-bold text-gray-600">サービス数</th>
                                <th class="px-4 py-3 text-center text-sm font-bold text-gray-600">状態</th>
                                <th class="px-4 py-3 text-center text-sm font-bold text-gray-600">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <?php foreach ($categories as $cat): ?>
                            <tr class="hover:bg-gray-50">
                                <td class="px-4 py-3">
                                    <div class="flex items-center gap-3">
                                        <?php if (!empty($cat['icon'])): ?>
                                        <i class="fas <?= htmlspecialchars($cat['icon']) ?> text-orange-500 text-lg"></i>
                                        <?php else: ?>
                                        <i class="fas fa-folder text-gray-400 text-lg"></i>
                                        <?php endif; ?>
                                        <div>
                                            <div class="font-bold text-gray-800"><?= htmlspecialchars($cat['name']) ?></div>
                                            <div class="text-xs text-gray-500"><?= htmlspecialchars($cat['slug']) ?></div>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <span class="inline-flex items-center px-2 py-1 bg-gray-100 text-gray-600 rounded-full text-sm font-bold">
                                        <?= $cat['service_count'] ?>件
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <?php if ($cat['is_active']): ?>
                                    <span class="inline-flex items-center px-2 py-1 bg-green-100 text-green-700 rounded-full text-xs font-bold">
                                        <i class="fas fa-check mr-1"></i>有効
                                    </span>
                                    <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-1 bg-gray-100 text-gray-500 rounded-full text-xs font-bold">
                                        <i class="fas fa-minus mr-1"></i>無効
                                    </span>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <div class="flex items-center justify-center gap-2">
                                        <a href="?edit=<?= $cat['id'] ?>" class="text-blue-500 hover:text-blue-600" title="編集">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                        <?php if ($cat['service_count'] == 0): ?>
                                        <a href="?delete=<?= $cat['id'] ?>&csrf_token=<?= generateCsrfToken() ?>" 
                                           onclick="return confirm('このカテゴリを削除しますか？')"
                                           class="text-red-500 hover:text-red-600" title="削除">
                                            <i class="fas fa-trash"></i>
                                        </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
</div>

<?php require_once 'includes/footer.php'; ?>
