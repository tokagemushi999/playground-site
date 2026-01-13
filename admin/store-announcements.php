<?php
/**
 * ストアお知らせ管理
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
if (!tableExists($db, 'store_announcements')) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS store_announcements (
            id INT AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL COMMENT 'タイトル',
            content TEXT COMMENT '内容',
            type ENUM('info', 'important', 'campaign', 'maintenance') DEFAULT 'info' COMMENT '種類',
            link_url VARCHAR(500) DEFAULT NULL COMMENT 'リンクURL',
            link_text VARCHAR(100) DEFAULT NULL COMMENT 'リンクテキスト',
            is_published TINYINT(1) DEFAULT 0 COMMENT '公開状態',
            publish_start DATETIME DEFAULT NULL COMMENT '公開開始',
            publish_end DATETIME DEFAULT NULL COMMENT '公開終了',
            sort_order INT DEFAULT 0 COMMENT '表示順',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    $message = 'テーブルを作成しました';
}

// 削除
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $stmt = $db->prepare("DELETE FROM store_announcements WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    header('Location: store-announcements.php?msg=deleted');
    exit;
}

// 保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? null;
    $title = trim($_POST['title'] ?? '');
    $content = trim($_POST['content'] ?? '');
    $type = $_POST['type'] ?? 'info';
    $linkUrl = trim($_POST['link_url'] ?? '');
    $linkText = trim($_POST['link_text'] ?? '');
    $isPublished = isset($_POST['is_published']) ? 1 : 0;
    $publishStart = $_POST['publish_start'] ?: null;
    $publishEnd = $_POST['publish_end'] ?: null;
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    
    if (empty($title)) {
        $error = 'タイトルを入力してください';
    } else {
        if ($id) {
            $stmt = $db->prepare("
                UPDATE store_announcements 
                SET title = ?, content = ?, type = ?, link_url = ?, link_text = ?,
                    is_published = ?, publish_start = ?, publish_end = ?, sort_order = ?
                WHERE id = ?
            ");
            $stmt->execute([$title, $content, $type, $linkUrl, $linkText, $isPublished, $publishStart, $publishEnd, $sortOrder, $id]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO store_announcements (title, content, type, link_url, link_text, is_published, publish_start, publish_end, sort_order)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$title, $content, $type, $linkUrl, $linkText, $isPublished, $publishStart, $publishEnd, $sortOrder]);
        }
        header('Location: store-announcements.php?msg=saved');
        exit;
    }
}

// 編集対象取得
$editItem = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM store_announcements WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editItem = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 一覧取得
$announcements = $db->query("SELECT * FROM store_announcements ORDER BY sort_order, created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['msg'])) {
    $message = $_GET['msg'] === 'saved' ? '保存しました' : '削除しました';
}

$types = [
    'info' => ['label' => 'お知らせ', 'color' => 'blue'],
    'important' => ['label' => '重要', 'color' => 'red'],
    'campaign' => ['label' => 'キャンペーン', 'color' => 'green'],
    'maintenance' => ['label' => 'メンテナンス', 'color' => 'yellow'],
];

$pageTitle = 'お知らせ管理';
include 'includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-800"><i class="fas fa-bullhorn text-green-500 mr-2"></i>お知らせ管理</h1>
    </div>
    
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
        <p class="text-blue-700 text-sm">
            <i class="fas fa-info-circle mr-1"></i>
            ここで登録したお知らせは<strong>ストアトップページ</strong>に表示されます。
        </p>
    </div>
    
    <?php if ($message): ?>
    <div class="bg-green-100 border border-green-300 text-green-700 px-4 py-3 rounded mb-4"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="bg-red-100 border border-red-300 text-red-700 px-4 py-3 rounded mb-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <!-- フォーム -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-lg font-bold text-gray-800 mb-4"><?= $editItem ? 'お知らせを編集' : '新規追加' ?></h2>
        <form method="POST" class="space-y-4">
            <?php if ($editItem): ?>
            <input type="hidden" name="id" value="<?= $editItem['id'] ?>">
            <?php endif; ?>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">タイトル <span class="text-red-500">*</span></label>
                    <input type="text" name="title" required value="<?= htmlspecialchars($editItem['title'] ?? '') ?>"
                           class="w-full border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-yellow-400">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">種類</label>
                    <select name="type" class="w-full border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-yellow-400">
                        <?php foreach ($types as $key => $t): ?>
                        <option value="<?= $key ?>" <?= ($editItem['type'] ?? 'info') === $key ? 'selected' : '' ?>><?= $t['label'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">内容</label>
                <textarea name="content" rows="3" class="w-full border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-yellow-400"><?= htmlspecialchars($editItem['content'] ?? '') ?></textarea>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">リンクURL</label>
                    <input type="url" name="link_url" value="<?= htmlspecialchars($editItem['link_url'] ?? '') ?>"
                           class="w-full border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-yellow-400" placeholder="https://...">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">リンクテキスト</label>
                    <input type="text" name="link_text" value="<?= htmlspecialchars($editItem['link_text'] ?? '') ?>"
                           class="w-full border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-yellow-400" placeholder="詳細を見る">
                </div>
            </div>
            
            <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">公開開始</label>
                    <input type="datetime-local" name="publish_start" value="<?= $editItem['publish_start'] ? date('Y-m-d\TH:i', strtotime($editItem['publish_start'])) : '' ?>"
                           class="w-full border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-yellow-400">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">公開終了</label>
                    <input type="datetime-local" name="publish_end" value="<?= $editItem['publish_end'] ? date('Y-m-d\TH:i', strtotime($editItem['publish_end'])) : '' ?>"
                           class="w-full border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-yellow-400">
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">表示順</label>
                    <input type="number" name="sort_order" value="<?= $editItem['sort_order'] ?? 0 ?>"
                           class="w-full border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-yellow-400">
                </div>
            </div>
            
            <div class="flex items-center gap-4">
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="is_published" value="1" <?= ($editItem['is_published'] ?? 0) ? 'checked' : '' ?> class="rounded border-gray-300">
                    <span class="text-gray-700">公開する</span>
                </label>
            </div>
            
            <div class="flex gap-2">
                <button type="submit" class="px-6 py-2 bg-yellow-400 hover:bg-yellow-500 rounded font-bold text-gray-800">
                    <i class="fas fa-save mr-1"></i> 保存
                </button>
                <?php if ($editItem): ?>
                <a href="store-announcements.php" class="px-6 py-2 bg-gray-200 hover:bg-gray-300 rounded font-bold text-gray-700">キャンセル</a>
                <?php endif; ?>
            </div>
        </form>
    </div>
    
    <!-- 一覧 -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead class="bg-gray-100">
                <tr>
                    <th class="px-4 py-3 text-left text-sm font-bold text-gray-700">タイトル</th>
                    <th class="px-4 py-3 text-left text-sm font-bold text-gray-700">種類</th>
                    <th class="px-4 py-3 text-center text-sm font-bold text-gray-700">状態</th>
                    <th class="px-4 py-3 text-center text-sm font-bold text-gray-700">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($announcements as $ann): 
                    $t = $types[$ann['type']] ?? $types['info'];
                    $colorClasses = [
                        'blue' => 'bg-blue-100 text-blue-700',
                        'red' => 'bg-red-100 text-red-700',
                        'green' => 'bg-green-100 text-green-700',
                        'yellow' => 'bg-yellow-100 text-yellow-700',
                    ];
                ?>
                <tr class="border-t border-gray-200 hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <div class="font-bold text-gray-800"><?= htmlspecialchars($ann['title']) ?></div>
                        <?php if ($ann['content']): ?>
                        <div class="text-xs text-gray-500 truncate max-w-xs"><?= htmlspecialchars($ann['content']) ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <span class="px-2 py-1 rounded text-xs font-bold <?= $colorClasses[$t['color']] ?>"><?= $t['label'] ?></span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($ann['is_published']): ?>
                        <span class="text-green-500"><i class="fas fa-check-circle"></i></span>
                        <?php else: ?>
                        <span class="text-gray-400"><i class="fas fa-eye-slash"></i></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <a href="?edit=<?= $ann['id'] ?>" class="text-blue-500 hover:text-blue-700 mr-3"><i class="fas fa-edit"></i></a>
                        <a href="?delete=<?= $ann['id'] ?>" onclick="return confirm('削除しますか？')" class="text-red-500 hover:text-red-700"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($announcements)): ?>
                <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">お知らせはありません</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
