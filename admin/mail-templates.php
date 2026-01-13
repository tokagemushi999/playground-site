<?php
/**
 * メールテンプレート管理
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/site-settings.php';
requireAuth();

$db = getDB();
$message = '';
$error = '';

// テーブル存在確認
$tableExists = true;
try {
    $db->query("SELECT 1 FROM mail_templates LIMIT 1");
} catch (PDOException $e) {
    $tableExists = false;
}

// テーブル作成
if (!$tableExists && isset($_POST['create_table'])) {
    try {
        $sql = file_get_contents(__DIR__ . '/../sql/mail_templates.sql');
        $db->exec($sql);
        $message = 'メールテンプレートテーブルを作成しました';
        $tableExists = true;
    } catch (PDOException $e) {
        $error = 'テーブル作成に失敗しました: ' . $e->getMessage();
    }
}

// テンプレート更新
if ($tableExists && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_template'])) {
    $id = (int)$_POST['template_id'];
    $subject = trim($_POST['subject']);
    $body = trim($_POST['body']);
    $isActive = isset($_POST['is_active']) ? 1 : 0;
    
    try {
        $stmt = $db->prepare("UPDATE mail_templates SET subject = ?, body = ?, is_active = ? WHERE id = ?");
        $stmt->execute([$subject, $body, $isActive, $id]);
        $message = 'テンプレートを更新しました';
    } catch (PDOException $e) {
        $error = '更新に失敗しました';
    }
}

// テンプレート一覧取得
$templates = [];
$editTemplate = null;
if ($tableExists) {
    $stmt = $db->query("SELECT * FROM mail_templates ORDER BY id");
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 編集対象取得
    if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
        $stmt = $db->prepare("SELECT * FROM mail_templates WHERE id = ?");
        $stmt->execute([(int)$_GET['edit']]);
        $editTemplate = $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

$pageTitle = 'メールテンプレート';
include 'includes/header.php';
?>
        <h1 class="text-2xl font-bold text-gray-800 mb-6">
            <i class="fas fa-envelope text-blue-500 mr-2"></i>メールテンプレート
        </h1>
        
        <?php if ($message): ?>
        <div class="bg-green-100 border border-green-300 text-green-700 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="bg-red-100 border border-red-300 text-red-700 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>
        
        <?php if (!$tableExists): ?>
        <div class="bg-white rounded-xl shadow-sm p-6">
            <p class="text-gray-600 mb-4">メールテンプレートテーブルが存在しません。</p>
            <form method="POST">
                <button type="submit" name="create_table" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg font-bold">
                    <i class="fas fa-database mr-2"></i>テーブルを作成
                </button>
            </form>
        </div>
        <?php elseif ($editTemplate): ?>
        <!-- 編集フォーム -->
        <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-gray-800">
                    <i class="fas fa-edit text-orange-500 mr-2"></i><?= htmlspecialchars($editTemplate['name']) ?>
                </h2>
                <a href="mail-templates.php" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times text-xl"></i>
                </a>
            </div>
            
            <!-- 変数説明 -->
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-4">
                <p class="text-sm text-blue-800">
                    <i class="fas fa-info-circle mr-1"></i>
                    <?= htmlspecialchars($editTemplate['description']) ?>
                </p>
            </div>
            
            <form method="POST">
                <input type="hidden" name="template_id" value="<?= $editTemplate['id'] ?>">
                
                <div class="mb-4">
                    <label class="block text-sm font-bold text-gray-700 mb-2">件名</label>
                    <input type="text" name="subject" value="<?= htmlspecialchars($editTemplate['subject']) ?>" 
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-bold text-gray-700 mb-2">本文</label>
                    <textarea name="body" rows="20" 
                              class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-500 focus:border-blue-500 font-mono text-sm"><?= htmlspecialchars($editTemplate['body']) ?></textarea>
                </div>
                
                <div class="mb-6">
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="is_active" value="1" <?= $editTemplate['is_active'] ? 'checked' : '' ?> 
                               class="rounded text-blue-500">
                        <span class="text-sm font-bold text-gray-700">このメールを有効にする</span>
                    </label>
                </div>
                
                <div class="flex gap-3">
                    <button type="submit" name="update_template" class="bg-blue-500 hover:bg-blue-600 text-white px-6 py-2 rounded-lg font-bold">
                        <i class="fas fa-save mr-2"></i>保存
                    </button>
                    <a href="mail-templates.php" class="bg-gray-300 hover:bg-gray-400 text-gray-700 px-6 py-2 rounded-lg font-bold">
                        キャンセル
                    </a>
                </div>
            </form>
        </div>
        <?php else: ?>
        <!-- テンプレート一覧 -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">テンプレート名</th>
                        <th class="px-4 py-3 text-left text-sm font-bold text-gray-600 hidden md:table-cell">件名</th>
                        <th class="px-4 py-3 text-center text-sm font-bold text-gray-600">状態</th>
                        <th class="px-4 py-3 text-center text-sm font-bold text-gray-600">操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200">
                    <?php foreach ($templates as $template): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3">
                            <p class="font-bold text-gray-800"><?= htmlspecialchars($template['name']) ?></p>
                            <p class="text-xs text-gray-500"><?= htmlspecialchars($template['template_key']) ?></p>
                        </td>
                        <td class="px-4 py-3 text-sm text-gray-600 hidden md:table-cell">
                            <?= htmlspecialchars(mb_substr($template['subject'], 0, 40)) ?>...
                        </td>
                        <td class="px-4 py-3 text-center">
                            <?php if ($template['is_active']): ?>
                            <span class="text-xs px-2 py-1 rounded bg-green-100 text-green-700 font-bold">有効</span>
                            <?php else: ?>
                            <span class="text-xs px-2 py-1 rounded bg-gray-100 text-gray-500 font-bold">無効</span>
                            <?php endif; ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <a href="?edit=<?= $template['id'] ?>" class="text-blue-500 hover:text-blue-700">
                                <i class="fas fa-edit"></i> 編集
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <!-- 説明 -->
        <div class="mt-6 bg-yellow-50 border border-yellow-200 rounded-xl p-4">
            <h3 class="font-bold text-yellow-800 mb-2">
                <i class="fas fa-lightbulb mr-2"></i>使い方
            </h3>
            <ul class="text-sm text-yellow-700 space-y-1">
                <li>・件名と本文に <code class="bg-yellow-100 px-1 rounded">{変数名}</code> を使用すると、送信時に実際の値に置き換わります</li>
                <li>・各テンプレートで使用可能な変数は編集画面で確認できます</li>
                <li>・「無効」にするとそのメールは送信されません</li>
            </ul>
        </div>
        <?php endif; ?>

<?php include 'includes/footer.php'; ?>
