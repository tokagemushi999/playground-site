<?php
/**
 * ストアFAQ管理
 * ストアのFAQページと問い合わせフォームの両方で使用
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
if (!tableExists($db, 'store_faq')) {
    $db->exec("
        CREATE TABLE IF NOT EXISTS store_faq (
            id INT AUTO_INCREMENT PRIMARY KEY,
            category VARCHAR(50) NOT NULL COMMENT 'カテゴリ',
            question TEXT NOT NULL COMMENT '質問',
            answer TEXT NOT NULL COMMENT '回答',
            sort_order INT DEFAULT 0 COMMENT '表示順',
            is_published TINYINT(1) DEFAULT 1 COMMENT '公開状態',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    // デフォルトFAQを挿入
    insertDefaultFaq($db);
    $message = 'テーブルを作成し、初期FAQを追加しました';
}

// 削除
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $stmt = $db->prepare("DELETE FROM store_faq WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    header('Location: store-faq.php?msg=deleted');
    exit;
}

// 保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? null;
    $category = trim($_POST['category'] ?? '');
    $question = trim($_POST['question'] ?? '');
    $answer = trim($_POST['answer'] ?? '');
    $isPublished = isset($_POST['is_published']) ? 1 : 0;
    $sortOrder = (int)($_POST['sort_order'] ?? 0);
    
    if (empty($category) || empty($question) || empty($answer)) {
        $error = '全ての項目を入力してください';
    } else {
        if ($id) {
            $stmt = $db->prepare("
                UPDATE store_faq SET category = ?, question = ?, answer = ?, is_published = ?, sort_order = ? WHERE id = ?
            ");
            $stmt->execute([$category, $question, $answer, $isPublished, $sortOrder, $id]);
        } else {
            $stmt = $db->prepare("
                INSERT INTO store_faq (category, question, answer, is_published, sort_order) VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([$category, $question, $answer, $isPublished, $sortOrder]);
        }
        header('Location: store-faq.php?msg=saved');
        exit;
    }
}

// 編集対象取得
$editItem = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM store_faq WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editItem = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 一覧取得
$faqs = $db->query("SELECT * FROM store_faq ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC);

// カテゴリ一覧
$existingCategories = $db->query("SELECT DISTINCT category FROM store_faq ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

if (isset($_GET['msg'])) {
    $message = $_GET['msg'] === 'saved' ? '保存しました' : '削除しました';
}

$defaultCategories = DEFAULT_FAQ_CATEGORIES;

$pageTitle = 'FAQ管理';
include 'includes/header.php';
?>

<div class="max-w-4xl mx-auto">
    <div class="flex items-center justify-between mb-6">
        <h1 class="text-2xl font-bold text-gray-800"><i class="fas fa-question-circle text-green-500 mr-2"></i>FAQ管理</h1>
    </div>
    
    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
        <p class="text-blue-700 text-sm">
            <i class="fas fa-info-circle mr-1"></i>
            ここで登録したFAQは以下の場所で表示されます：
        </p>
        <ul class="text-blue-700 text-sm mt-2 ml-4 list-disc">
            <li><strong>FAQページ</strong>（/store/faq.php）</li>
            <li><strong>お問い合わせフォーム</strong>（/store/contact.php）のよくある質問セクション</li>
        </ul>
    </div>
    
    <?php if ($message): ?>
    <div class="bg-green-100 border border-green-300 text-green-700 px-4 py-3 rounded mb-4"><?= htmlspecialchars($message) ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="bg-red-100 border border-red-300 text-red-700 px-4 py-3 rounded mb-4"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    
    <!-- フォーム -->
    <div class="bg-white rounded-lg shadow p-6 mb-6">
        <h2 class="text-lg font-bold text-gray-800 mb-4"><?= $editItem ? 'FAQを編集' : '新規追加' ?></h2>
        <form method="POST" class="space-y-4">
            <?php if ($editItem): ?>
            <input type="hidden" name="id" value="<?= $editItem['id'] ?>">
            <?php endif; ?>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">カテゴリ <span class="text-red-500">*</span></label>
                    <input type="text" name="category" required list="category-list" value="<?= htmlspecialchars($editItem['category'] ?? '') ?>"
                           class="w-full border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-yellow-400" placeholder="購入について">
                    <datalist id="category-list">
                        <?php foreach (array_unique(array_merge($defaultCategories, $existingCategories)) as $cat): ?>
                        <option value="<?= htmlspecialchars($cat) ?>">
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">表示順</label>
                    <input type="number" name="sort_order" value="<?= $editItem['sort_order'] ?? 0 ?>"
                           class="w-full border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-yellow-400">
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">質問 <span class="text-red-500">*</span></label>
                <input type="text" name="question" required value="<?= htmlspecialchars($editItem['question'] ?? '') ?>"
                       class="w-full border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-yellow-400">
            </div>
            
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">回答 <span class="text-red-500">*</span></label>
                <textarea name="answer" required rows="4" class="w-full border border-gray-300 rounded px-3 py-2 focus:ring-2 focus:ring-yellow-400"><?= htmlspecialchars($editItem['answer'] ?? '') ?></textarea>
            </div>
            
            <div class="flex items-center gap-4">
                <label class="flex items-center gap-2">
                    <input type="checkbox" name="is_published" value="1" <?= ($editItem['is_published'] ?? 1) ? 'checked' : '' ?> class="rounded border-gray-300">
                    <span class="text-gray-700">公開する</span>
                </label>
            </div>
            
            <div class="flex gap-2">
                <button type="submit" class="px-6 py-2 bg-yellow-400 hover:bg-yellow-500 rounded font-bold text-gray-800">
                    <i class="fas fa-save mr-1"></i> 保存
                </button>
                <?php if ($editItem): ?>
                <a href="store-faq.php" class="px-6 py-2 bg-gray-200 hover:bg-gray-300 rounded font-bold text-gray-700">キャンセル</a>
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
                    <th class="px-4 py-3 text-left text-sm font-bold text-gray-700">質問</th>
                    <th class="px-4 py-3 text-center text-sm font-bold text-gray-700">状態</th>
                    <th class="px-4 py-3 text-center text-sm font-bold text-gray-700">操作</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($faqs as $faq): ?>
                <tr class="border-t border-gray-200 hover:bg-gray-50">
                    <td class="px-4 py-3">
                        <span class="px-2 py-1 bg-gray-100 rounded text-xs text-gray-700"><?= htmlspecialchars($faq['category']) ?></span>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-bold text-sm text-gray-800"><?= htmlspecialchars($faq['question']) ?></div>
                        <div class="text-xs text-gray-500 truncate max-w-md"><?= htmlspecialchars(mb_substr($faq['answer'], 0, 50)) ?>...</div>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php if ($faq['is_published']): ?>
                        <span class="text-green-500"><i class="fas fa-check-circle"></i></span>
                        <?php else: ?>
                        <span class="text-gray-400"><i class="fas fa-eye-slash"></i></span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <a href="?edit=<?= $faq['id'] ?>" class="text-blue-500 hover:text-blue-700 mr-3"><i class="fas fa-edit"></i></a>
                        <a href="?delete=<?= $faq['id'] ?>" onclick="return confirm('削除しますか？')" class="text-red-500 hover:text-red-700"><i class="fas fa-trash"></i></a>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($faqs)): ?>
                <tr><td colspan="4" class="px-4 py-8 text-center text-gray-500">FAQはありません</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'includes/footer.php'; ?>
