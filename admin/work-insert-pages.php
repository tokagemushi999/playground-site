<?php
/**
 * 作品の挿入ページ管理
 * admin/work-insert-pages.php
 */

session_start();
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/site-settings.php';
requireAuth();

$db = getDB();

// テーブル存在確認
$stmt = $db->query("SHOW TABLES LIKE 'work_insert_pages'");
if ($stmt->rowCount() === 0) {
    header('Location: migrate-viewer.php');
    exit;
}

$workId = isset($_GET['work_id']) ? (int)$_GET['work_id'] : 0;

if (!$workId) {
    header('Location: works.php');
    exit;
}

// 作品情報を取得
$stmt = $db->prepare("SELECT * FROM works WHERE id = ?");
$stmt->execute([$workId]);
$work = $stmt->fetch();

if (!$work) {
    header('Location: works.php');
    exit;
}

// 作品のページ数を取得
$stmt = $db->prepare("SELECT COUNT(*) FROM work_pages WHERE work_id = ?");
$stmt->execute([$workId]);
$pageCount = (int)$stmt->fetchColumn();

$message = '';
$error = '';

// 挿入ページの追加/更新/削除処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    if ($action === 'add' || $action === 'update') {
        $id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
        $insertAfter = (int)($_POST['insert_after'] ?? 0);
        $pageType = $_POST['page_type'] ?? 'image';
        $htmlContent = $_POST['html_content'] ?? '';
        $linkUrl = trim($_POST['link_url'] ?? '');
        $linkTarget = $_POST['link_target'] ?? '_blank';
        $backgroundColor = $_POST['background_color'] ?? '#000000';
        $isActive = isset($_POST['is_active']) ? 1 : 0;
        $sortOrder = (int)($_POST['sort_order'] ?? 0);
        
        // 画像アップロード処理
        $imagePath = $_POST['existing_image'] ?? '';
        if (!empty($_FILES['image']['name'])) {
            $uploadDir = dirname(__DIR__) . '/uploads/insert-pages/';
            if (!is_dir($uploadDir)) {
                mkdir($uploadDir, 0755, true);
            }
            
            $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
            $allowed = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
            
            if (in_array($ext, $allowed)) {
                $filename = 'insert_' . $workId . '_' . time() . '.' . $ext;
                $targetPath = $uploadDir . $filename;
                
                if (move_uploaded_file($_FILES['image']['tmp_name'], $targetPath)) {
                    $imagePath = '/uploads/insert-pages/' . $filename;
                }
            }
        }
        
        try {
            if ($action === 'add') {
                $stmt = $db->prepare("
                    INSERT INTO work_insert_pages 
                    (work_id, insert_after, page_type, image, html_content, link_url, link_target, background_color, is_active, sort_order)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$workId, $insertAfter, $pageType, $imagePath, $htmlContent, $linkUrl, $linkTarget, $backgroundColor, $isActive, $sortOrder]);
                $message = '挿入ページを追加しました';
            } else {
                $stmt = $db->prepare("
                    UPDATE work_insert_pages SET
                    insert_after = ?, page_type = ?, image = ?, html_content = ?, link_url = ?, link_target = ?, background_color = ?, is_active = ?, sort_order = ?
                    WHERE id = ? AND work_id = ?
                ");
                $stmt->execute([$insertAfter, $pageType, $imagePath, $htmlContent, $linkUrl, $linkTarget, $backgroundColor, $isActive, $sortOrder, $id, $workId]);
                $message = '挿入ページを更新しました';
            }
        } catch (PDOException $e) {
            $error = 'エラー: ' . $e->getMessage();
        }
    }
    
    if ($action === 'delete' && isset($_POST['id'])) {
        $id = (int)$_POST['id'];
        try {
            $stmt = $db->prepare("DELETE FROM work_insert_pages WHERE id = ? AND work_id = ?");
            $stmt->execute([$id, $workId]);
            $message = '挿入ページを削除しました';
        } catch (PDOException $e) {
            $error = 'エラー: ' . $e->getMessage();
        }
    }
}

// 挿入ページ一覧を取得
$stmt = $db->prepare("SELECT * FROM work_insert_pages WHERE work_id = ? ORDER BY insert_after ASC, sort_order ASC");
$stmt->execute([$workId]);
$insertPages = $stmt->fetchAll();

// 編集対象
$editPage = null;
if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    foreach ($insertPages as $ip) {
        if ($ip['id'] == $editId) {
            $editPage = $ip;
            break;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php $pwaThemeColor = getSiteSetting($db, 'pwa_theme_color', '#ffffff'); ?>
    <meta name="theme-color" content="<?= htmlspecialchars($pwaThemeColor) ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="ぷれぐら！管理">
    <meta name="mobile-web-app-capable" content="yes">

    <title>挿入ページ管理 - <?= htmlspecialchars($work['title']) ?></title>
    <link rel="manifest" href="/admin/manifest.json">
    <?php $backyardFavicon = getBackyardFaviconInfo($db); ?>
    <link rel="icon" href="<?= htmlspecialchars($backyardFavicon['path']) ?>" type="<?= htmlspecialchars($backyardFavicon['type']) ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($backyardFavicon['path']) ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="flex">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="flex-1 p-8">
            <div class="max-w-4xl mx-auto">
                <!-- パンくず -->
                <nav class="mb-4 text-sm">
                    <a href="works.php" class="text-blue-500 hover:underline">作品管理</a>
                    <span class="mx-2 text-gray-400">/</span>
                    <span class="text-gray-600"><?= htmlspecialchars($work['title']) ?></span>
                    <span class="mx-2 text-gray-400">/</span>
                    <span class="text-gray-800">挿入ページ</span>
                </nav>
                
                <div class="flex items-center justify-between mb-6">
                    <h1 class="text-2xl font-bold flex items-center gap-3">
                        <i class="fas fa-ad text-orange-500"></i>
                        挿入ページ管理
                    </h1>
                    <a href="/manga/<?= $workId ?>" target="_blank" class="text-blue-500 hover:underline text-sm">
                        <i class="fas fa-external-link-alt mr-1"></i>プレビュー
                    </a>
                </div>
                
                <?php if ($message): ?>
                <div class="bg-green-100 text-green-700 p-4 rounded-lg mb-4">
                    <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($message) ?>
                </div>
                <?php endif; ?>
                
                <?php if ($error): ?>
                <div class="bg-red-100 text-red-700 p-4 rounded-lg mb-4">
                    <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
                </div>
                <?php endif; ?>
                
                <div class="bg-white rounded-xl shadow-lg p-6 mb-6">
                    <h2 class="font-bold text-lg mb-4">
                        <?= $editPage ? '挿入ページを編集' : '新しい挿入ページを追加' ?>
                    </h2>
                    
                    <form method="POST" enctype="multipart/form-data" class="space-y-4">
                        <input type="hidden" name="action" value="<?= $editPage ? 'update' : 'add' ?>">
                        <?php if ($editPage): ?>
                        <input type="hidden" name="id" value="<?= $editPage['id'] ?>">
                        <input type="hidden" name="existing_image" value="<?= htmlspecialchars($editPage['image'] ?? '') ?>">
                        <?php endif; ?>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-bold mb-1">挿入位置</label>
                                <select name="insert_after" class="w-full border rounded-lg px-4 py-2">
                                    <option value="0" <?= ($editPage['insert_after'] ?? 0) == 0 ? 'selected' : '' ?>>最初（1ページ目の前）</option>
                                    <?php for ($i = 1; $i <= $pageCount; $i++): ?>
                                    <option value="<?= $i ?>" <?= ($editPage['insert_after'] ?? 0) == $i ? 'selected' : '' ?>><?= $i ?>ページ目の後</option>
                                    <?php endfor; ?>
                                    <option value="-1" <?= ($editPage['insert_after'] ?? 0) == -1 ? 'selected' : '' ?>>最後（最終ページの後）</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-bold mb-1">タイプ</label>
                                <select name="page_type" id="page_type" class="w-full border rounded-lg px-4 py-2" onchange="toggleTypeFields()">
                                    <option value="image" <?= ($editPage['page_type'] ?? 'image') == 'image' ? 'selected' : '' ?>>画像</option>
                                    <option value="html" <?= ($editPage['page_type'] ?? '') == 'html' ? 'selected' : '' ?>>HTML</option>
                                </select>
                            </div>
                        </div>
                        
                        <div id="image-fields" class="<?= ($editPage['page_type'] ?? 'image') == 'html' ? 'hidden' : '' ?>">
                            <label class="block text-sm font-bold mb-1">画像</label>
                            <?php if (!empty($editPage['image'])): ?>
                            <div class="mb-2">
                                <img src="<?= htmlspecialchars($editPage['image']) ?>" class="h-32 object-contain bg-gray-100 rounded">
                            </div>
                            <?php endif; ?>
                            <input type="file" name="image" accept="image/*" class="w-full border rounded-lg px-4 py-2">
                        </div>
                        
                        <div id="html-fields" class="<?= ($editPage['page_type'] ?? 'image') != 'html' ? 'hidden' : '' ?>">
                            <label class="block text-sm font-bold mb-1">HTMLコンテンツ</label>
                            <textarea name="html_content" rows="5" class="w-full border rounded-lg px-4 py-2 font-mono text-sm"><?= htmlspecialchars($editPage['html_content'] ?? '') ?></textarea>
                            <p class="text-xs text-gray-500 mt-1">バナー画像やテキストなどをHTMLで記述できます</p>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-bold mb-1">リンクURL（任意）</label>
                                <input type="url" name="link_url" value="<?= htmlspecialchars($editPage['link_url'] ?? '') ?>" 
                                    class="w-full border rounded-lg px-4 py-2" placeholder="https://...">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-bold mb-1">リンクの開き方</label>
                                <select name="link_target" class="w-full border rounded-lg px-4 py-2">
                                    <option value="_blank" <?= ($editPage['link_target'] ?? '_blank') == '_blank' ? 'selected' : '' ?>>新しいタブで開く</option>
                                    <option value="_self" <?= ($editPage['link_target'] ?? '') == '_self' ? 'selected' : '' ?>>同じタブで開く</option>
                                </select>
                            </div>
                        </div>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-bold mb-1">背景色</label>
                                <input type="color" name="background_color" value="<?= htmlspecialchars($editPage['background_color'] ?? '#000000') ?>" 
                                    class="w-full h-10 border rounded-lg cursor-pointer">
                            </div>
                            
                            <div>
                                <label class="block text-sm font-bold mb-1">表示順（同位置での順序）</label>
                                <input type="number" name="sort_order" value="<?= (int)($editPage['sort_order'] ?? 0) ?>" 
                                    class="w-full border rounded-lg px-4 py-2">
                            </div>
                        </div>
                        
                        <div class="flex items-center gap-2">
                            <input type="checkbox" name="is_active" id="is_active" value="1" 
                                <?= ($editPage['is_active'] ?? 1) ? 'checked' : '' ?>>
                            <label for="is_active">有効</label>
                        </div>
                        
                        <div class="flex gap-3">
                            <button type="submit" class="bg-blue-500 hover:bg-blue-600 text-white font-bold py-2 px-6 rounded-lg">
                                <i class="fas fa-save mr-2"></i><?= $editPage ? '更新' : '追加' ?>
                            </button>
                            <?php if ($editPage): ?>
                            <a href="?work_id=<?= $workId ?>" class="bg-gray-200 hover:bg-gray-300 text-gray-700 font-bold py-2 px-6 rounded-lg">
                                キャンセル
                            </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
                
                <!-- 挿入ページ一覧 -->
                <div class="bg-white rounded-xl shadow-lg p-6">
                    <h2 class="font-bold text-lg mb-4">登録済みの挿入ページ</h2>
                    
                    <?php if (empty($insertPages)): ?>
                    <p class="text-gray-500">挿入ページはまだ登録されていません</p>
                    <?php else: ?>
                    <div class="space-y-3">
                        <?php foreach ($insertPages as $ip): ?>
                        <div class="flex items-center gap-4 p-4 border rounded-lg <?= $ip['is_active'] ? '' : 'opacity-50' ?>">
                            <div class="w-20 h-20 bg-gray-100 rounded flex-shrink-0 overflow-hidden">
                                <?php if ($ip['page_type'] === 'image' && !empty($ip['image'])): ?>
                                <img src="<?= htmlspecialchars($ip['image']) ?>" class="w-full h-full object-cover">
                                <?php else: ?>
                                <div class="w-full h-full flex items-center justify-center text-gray-400">
                                    <i class="fas fa-code text-2xl"></i>
                                </div>
                                <?php endif; ?>
                            </div>
                            
                            <div class="flex-1">
                                <div class="flex items-center gap-2 mb-1">
                                    <span class="bg-purple-100 text-purple-700 text-xs px-2 py-1 rounded">
                                        <?php
                                        if ($ip['insert_after'] == 0) {
                                            echo '最初';
                                        } elseif ($ip['insert_after'] == -1) {
                                            echo '最後';
                                        } else {
                                            echo $ip['insert_after'] . 'P後';
                                        }
                                        ?>
                                    </span>
                                    <span class="bg-gray-100 text-gray-700 text-xs px-2 py-1 rounded">
                                        <?= $ip['page_type'] === 'html' ? 'HTML' : '画像' ?>
                                    </span>
                                    <?php if (!$ip['is_active']): ?>
                                    <span class="bg-red-100 text-red-700 text-xs px-2 py-1 rounded">無効</span>
                                    <?php endif; ?>
                                </div>
                                <?php if (!empty($ip['link_url'])): ?>
                                <p class="text-xs text-gray-500 truncate">リンク: <?= htmlspecialchars($ip['link_url']) ?></p>
                                <?php endif; ?>
                            </div>
                            
                            <div class="flex gap-2">
                                <a href="?work_id=<?= $workId ?>&edit=<?= $ip['id'] ?>" 
                                    class="text-blue-500 hover:text-blue-700 p-2">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" class="inline" onsubmit="return confirm('削除しますか？');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $ip['id'] ?>">
                                    <button type="submit" class="text-red-500 hover:text-red-700 p-2">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- 埋め込みコード -->
                <div class="bg-white rounded-xl shadow-lg p-6 mt-6">
                    <h2 class="font-bold text-lg mb-4">
                        <i class="fas fa-code text-blue-500 mr-2"></i>
                        埋め込みコード
                    </h2>
                    
                    <p class="text-sm text-gray-600 mb-4">以下のコードをコピーして、他のサイトに貼り付けてください。</p>
                    
                    <div class="bg-gray-900 rounded-lg p-4 relative">
                        <pre class="text-green-400 text-sm overflow-x-auto" id="embed-code">&lt;iframe src="<?= htmlspecialchars('https://' . ($_SERVER['HTTP_HOST'] ?? 'example.com')) ?>/manga/<?= $workId ?>?embed=1" width="100%" height="600" frameborder="0" allowfullscreen&gt;&lt;/iframe&gt;</pre>
                        <button onclick="copyEmbedCode()" class="absolute top-2 right-2 text-gray-400 hover:text-white p-2">
                            <i class="fas fa-copy"></i>
                        </button>
                    </div>
                    
                    <div class="mt-4 text-sm text-gray-500">
                        <p><strong>オプション:</strong></p>
                        <ul class="list-disc ml-5 mt-2 space-y-1">
                            <li><code>height="600"</code> の値を調整して高さを変更</li>
                            <li><code>allowfullscreen</code> で全画面ボタンが機能</li>
                        </ul>
                    </div>
                </div>
            </div>
        </main>
    </div>
    
    <script>
        function toggleTypeFields() {
            const pageType = document.getElementById('page_type').value;
            document.getElementById('image-fields').classList.toggle('hidden', pageType === 'html');
            document.getElementById('html-fields').classList.toggle('hidden', pageType !== 'html');
        }
        
        function copyEmbedCode() {
            const code = document.getElementById('embed-code').textContent;
            navigator.clipboard.writeText(code).then(() => {
                alert('コピーしました');
            });
        }
    </script>
</body>
</html>
