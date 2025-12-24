<?php
/**
 * クリエイター管理
 */

require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/image-helper.php';
requireAuth();

$db = getDB();
$message = '';
$error = '';

// 削除処理
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $stmt = $db->prepare("UPDATE creators SET is_active = 0 WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    $message = 'クリエイターを削除しました。';
}

// 保存処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? null;
    $name = $_POST['name'] ?? '';
    $slug = $_POST['slug'] ?? '';
    $role = $_POST['role'] ?? '';
    $description = $_POST['description'] ?? '';
    $bio_long = $_POST['bio_long'] ?? '';
    
    // SNS
    $twitter = $_POST['twitter'] ?? '';
    $instagram = $_POST['instagram'] ?? '';
    $pixiv = $_POST['pixiv'] ?? '';
    $youtube = $_POST['youtube'] ?? '';
    $tiktok = $_POST['tiktok'] ?? '';
    $booth = $_POST['booth'] ?? '';
    $skeb = $_POST['skeb'] ?? '';
    $litlink = $_POST['litlink'] ?? '';
    $website = $_POST['website'] ?? '';
    $discord = $_POST['discord'] ?? '';
    
    // ページ設定（デフォルト値を維持）
    $page_layout = 'default';
    $theme_color = '#FFD93D';
    $gallery_style = 'grid';
    $show_works = 1;
    $show_articles = 1;
    $custom_css = '';
    
    if (isset($_POST['sort_order'])) {
        $sort_order = (int)$_POST['sort_order'];
    } elseif ($id) {
        $stmt = $db->prepare("SELECT sort_order FROM creators WHERE id = ?");
        $stmt->execute([$id]);
        $sort_order = (int)$stmt->fetchColumn();
    } else {
        $sort_order = 0;
    }
    
    // プロフィール画像アップロード（WebP変換）
    $image = $_POST['current_image'] ?? '';
    if (!empty($_FILES['image']['name'])) {
        $uploadDir = '../uploads/creators/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $baseName = uniqid('creator_');
        $result = ImageHelper::processUpload(
            $_FILES['image']['tmp_name'],
            $uploadDir,
            $baseName,
            ['maxWidth' => 800, 'maxHeight' => 800]
        );
        if ($result && isset($result['path'])) {
            $image = 'uploads/creators/' . basename($result['path']);
        }
    }
    
    // ヘッダー画像アップロード（WebP変換）
    $header_image = $_POST['current_header_image'] ?? '';
    if (!empty($_FILES['header_image']['name'])) {
        $uploadDir = '../uploads/creators/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $baseName = uniqid('header_');
        $result = ImageHelper::processUpload(
            $_FILES['header_image']['tmp_name'],
            $uploadDir,
            $baseName,
            ['maxWidth' => 1920, 'maxHeight' => 600]
        );
        if ($result && isset($result['path'])) {
            $header_image = 'uploads/creators/' . basename($result['path']);
        }
    }
    
    if (empty($slug)) {
        if (!empty($name)) {
            $baseSlug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
            $baseSlug = trim($baseSlug, '-');
        } else {
            // 名前も空の場合はユニークIDを使用
            $baseSlug = 'creator';
        }
        
        // 空になってしまった場合の対策
        if (empty($baseSlug)) {
            $baseSlug = 'creator-' . uniqid();
        }
        
        // 重複チェック
        $slug = $baseSlug;
        $counter = 1;
        while (true) {
            $checkStmt = $db->prepare("SELECT id FROM creators WHERE slug = ? AND is_active = 1" . ($id ? " AND id != ?" : ""));
            if ($id) {
                $checkStmt->execute([$slug, $id]);
            } else {
                $checkStmt->execute([$slug]);
            }
            if (!$checkStmt->fetch()) {
                break; // 重複なし
            }
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }
    } else {
        // 手動入力されたスラッグの重複チェック
        $checkStmt = $db->prepare("SELECT id FROM creators WHERE slug = ? AND is_active = 1" . ($id ? " AND id != ?" : ""));
        if ($id) {
            $checkStmt->execute([$slug, $id]);
        } else {
            $checkStmt->execute([$slug]);
        }
        if ($checkStmt->fetch()) {
            $error = 'このスラッグは既に使用されています。別のスラッグを指定してください。';
        }
    }
    
    if (empty($error)) {
        if ($id) {
            // 更新
            $stmt = $db->prepare("UPDATE creators SET 
                name=?, slug=?, role=?, description=?, bio_long=?, image=?, header_image=?,
                twitter=?, instagram=?, pixiv=?, youtube=?, tiktok=?, booth=?, skeb=?, litlink=?, website=?, discord=?,
                page_layout=?, theme_color=?, gallery_style=?, show_works=?, show_articles=?, custom_css=?,
                sort_order=? WHERE id=?");
            $stmt->execute([
                $name, $slug, $role, $description, $bio_long, $image, $header_image,
                $twitter, $instagram, $pixiv, $youtube, $tiktok, $booth, $skeb, $litlink, $website, $discord,
                $page_layout, $theme_color, $gallery_style, $show_works, $show_articles, $custom_css,
                $sort_order, $id
            ]);
            $message = 'クリエイター情報を更新しました。';
        } else {
            // 新規作成
            $stmt = $db->prepare("INSERT INTO creators 
                (name, slug, role, description, bio_long, image, header_image,
                twitter, instagram, pixiv, youtube, tiktok, booth, skeb, litlink, website, discord,
                page_layout, theme_color, gallery_style, show_works, show_articles, custom_css, sort_order) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $name, $slug, $role, $description, $bio_long, $image, $header_image,
                $twitter, $instagram, $pixiv, $youtube, $tiktok, $booth, $skeb, $litlink, $website, $discord,
                $page_layout, $theme_color, $gallery_style, $show_works, $show_articles, $custom_css, $sort_order
            ]);
            $message = 'クリエイターを追加しました。';
        }
    }
}

// 編集対象を取得
$editCreator = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM creators WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editCreator = $stmt->fetch();
}

// 一覧を取得
$creators = $db->query("SELECT * FROM creators WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();

?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>メンバー管理 | 管理画面</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Zen Maru Gothic', sans-serif; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .tab-btn.active { background: #FBBF24; color: #1F2937; }
    </style>
</head>
<body class="bg-gray-50">
    <?php include 'includes/sidebar.php'; ?>
    
    <!-- Main Content -->
    <main class="lg:ml-64 p-8 pt-20 lg:pt-8">
        <!-- Updated header and removed old sidebar -->
        <div class="flex justify-between items-center mb-8">
            <div>
                <h2 class="text-2xl font-bold text-gray-800">メンバー管理</h2>
                <p class="text-gray-500">メンバーの追加・編集</p>
            </div>
            <a href="creators.php" class="bg-yellow-400 hover:bg-yellow-500 text-gray-900 font-bold px-6 py-3 rounded-lg transition">
                <i class="fas fa-plus mr-2"></i>新規追加
            </a>
        </div>
        
        <?php if ($message): ?>
        <div class="bg-green-50 border border-green-200 text-green-600 px-4 py-3 rounded-lg mb-6">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-lg mb-6">
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>
        
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-8">
            <!-- Form -->
            <div class="xl:col-span-2">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="font-bold text-gray-800 mb-6">
                        <?= $editCreator ? 'クリエイター編集' : '新規クリエイター' ?>
                    </h3>
                    
                    <!-- タブナビゲーション -->
                    <div class="flex gap-2 mb-6 border-b border-gray-200 pb-4">
                        <button type="button" onclick="switchTab('basic')" class="tab-btn active px-4 py-2 rounded-lg font-bold text-sm transition">
                            <i class="fas fa-user mr-1"></i>基本情報
                        </button>
                        <button type="button" onclick="switchTab('sns')" class="tab-btn px-4 py-2 rounded-lg font-bold text-sm bg-gray-100 hover:bg-gray-200 transition">
                            <i class="fas fa-share-alt mr-1"></i>SNS
                        </button>
                    </div>
                    
                    <form method="POST" enctype="multipart/form-data">
                        <?php if ($editCreator): ?>
                        <input type="hidden" name="id" value="<?= $editCreator['id'] ?>">
                        <input type="hidden" name="current_image" value="<?= htmlspecialchars($editCreator['image'] ?? '') ?>">
                        <input type="hidden" name="current_header_image" value="<?= htmlspecialchars($editCreator['header_image'] ?? '') ?>">
                        <?php endif; ?>
                        
                        <!-- 基本情報タブ -->
                        <div id="tab-basic" class="tab-content active space-y-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">名前 *</label>
                                    <input type="text" name="name" required
                                        value="<?= htmlspecialchars($editCreator['name'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">スラッグ（URL用）</label>
                                    <input type="text" name="slug"
                                        value="<?= htmlspecialchars($editCreator['slug'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                        placeholder="例: yamada-taro">
                                    <p class="text-xs text-gray-400 mt-1">空欄の場合は自動生成されます</p>
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">役職・肩書き</label>
                                <input type="text" name="role"
                                    value="<?= htmlspecialchars($editCreator['role'] ?? '') ?>"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                    placeholder="イラストレーター">
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">プロフィール画像</label>
                                    <?php if (!empty($editCreator['image'])): ?>
                                    <div class="mb-2">
                                        <img src="../<?= htmlspecialchars($editCreator['image']) ?>" class="w-20 h-20 object-cover rounded-lg">
                                    </div>
                                    <?php endif; ?>
                                    <input type="file" name="image" accept="image/*"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">ヘッダー画像（任意）</label>
                                    <?php if (!empty($editCreator['header_image'])): ?>
                                    <div class="mb-2">
                                        <img src="../<?= htmlspecialchars($editCreator['header_image']) ?>" class="w-32 h-16 object-cover rounded-lg">
                                    </div>
                                    <?php endif; ?>
                                    <input type="file" name="header_image" accept="image/*"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg">
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">自己紹介（短文）</label>
                                <textarea name="description" rows="2"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                    placeholder="トップページに表示される短い紹介文"><?= htmlspecialchars($editCreator['description'] ?? '') ?></textarea>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">詳細プロフィール</label>
                                <textarea name="bio_long" rows="5"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                    placeholder="個別ページに表示される詳しい自己紹介"><?= htmlspecialchars($editCreator['bio_long'] ?? '') ?></textarea>
                            </div>
                            
                        </div>
                        
                        <!-- SNSタブ -->
                        <div id="tab-sns" class="tab-content space-y-4">
                            <p class="text-sm text-gray-500 mb-4">各SNSのIDまたはURLを入力してください</p>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">
                                        <i class="fa-brands fa-x-twitter mr-1"></i>X (Twitter)
                                    </label>
                                    <input type="text" name="twitter"
                                        value="<?= htmlspecialchars($editCreator['twitter'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                        placeholder="@username">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">
                                        <i class="fa-brands fa-instagram mr-1" style="color:#E4405F"></i>Instagram
                                    </label>
                                    <input type="text" name="instagram"
                                        value="<?= htmlspecialchars($editCreator['instagram'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                        placeholder="username">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">
                                        <i class="fas fa-paintbrush mr-1" style="color:#0096FA"></i>Pixiv
                                    </label>
                                    <input type="text" name="pixiv"
                                        value="<?= htmlspecialchars($editCreator['pixiv'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                        placeholder="12345678">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">
                                        <i class="fa-brands fa-youtube mr-1" style="color:#FF0000"></i>YouTube
                                    </label>
                                    <input type="text" name="youtube"
                                        value="<?= htmlspecialchars($editCreator['youtube'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                        placeholder="https://youtube.com/@channel">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">
                                        <i class="fa-brands fa-tiktok mr-1"></i>TikTok
                                    </label>
                                    <input type="text" name="tiktok"
                                        value="<?= htmlspecialchars($editCreator['tiktok'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                        placeholder="@username">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">
                                        <i class="fas fa-store mr-1" style="color:#FC4D50"></i>BOOTH
                                    </label>
                                    <input type="text" name="booth"
                                        value="<?= htmlspecialchars($editCreator['booth'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                        placeholder="https://xxx.booth.pm/">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">
                                        <i class="fas fa-palette mr-1" style="color:#29B6F6"></i>Skeb
                                    </label>
                                    <input type="text" name="skeb"
                                        value="<?= htmlspecialchars($editCreator['skeb'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                        placeholder="username">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">
                                        <i class="fas fa-link mr-1" style="color:#28C8D2"></i>lit.link
                                    </label>
                                    <input type="text" name="litlink"
                                        value="<?= htmlspecialchars($editCreator['litlink'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                        placeholder="username">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">
                                        <i class="fab fa-discord mr-1" style="color:#5865F2"></i>Discord
                                    </label>
                                    <input type="text" name="discord"
                                        value="<?= htmlspecialchars($editCreator['discord'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                        placeholder="https://discord.gg/invite または ユーザー名">
                                </div>
                                <div class="col-span-2">
                                    <label class="block text-sm font-bold text-gray-700 mb-2">
                                        <i class="fas fa-globe mr-1"></i>Webサイト
                                    </label>
                                    <input type="url" name="website"
                                        value="<?= htmlspecialchars($editCreator['website'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                        placeholder="https://example.com">
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-6 pt-6 border-t border-gray-200">
                            <button type="submit" 
                                class="w-full bg-yellow-400 hover:bg-yellow-500 text-gray-900 font-bold py-3 rounded-lg transition">
                                <?= $editCreator ? '更新する' : '追加する' ?>
                            </button>
                            
                            <?php if ($editCreator): ?>
                            <a href="creators.php" class="block text-center text-gray-500 hover:text-gray-700 text-sm mt-3">
                                キャンセル
                            </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- List -->
            <div class="xl:col-span-1">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h3 class="font-bold text-gray-800">クリエイター一覧（<?= count($creators) ?>名）</h3>
                    </div>
                    
                    <?php if (empty($creators)): ?>
                    <div class="px-6 py-12 text-center text-gray-400">
                        <i class="fas fa-users text-4xl mb-4"></i>
                        <p>クリエイターがまだ登録されていません</p>
                    </div>
                    <?php else: ?>
                    <div class="divide-y divide-gray-100 max-h-[600px] overflow-y-auto">
                        <?php foreach ($creators as $creator): ?>
                        <div class="px-4 py-3 hover:bg-gray-50">
                            <div class="flex items-center gap-3">
                                <?php if (!empty($creator['image'])): ?>
                                <img src="../<?= htmlspecialchars($creator['image']) ?>" class="w-10 h-10 object-cover rounded-full">
                                <?php else: ?>
                                <div class="w-10 h-10 bg-gray-200 rounded-full flex items-center justify-center">
                                    <i class="fas fa-user text-gray-400"></i>
                                </div>
                                <?php endif; ?>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <p class="font-bold text-gray-800 text-sm truncate"><?= htmlspecialchars($creator['name']) ?></p>
                                        <!-- CHANGE: バッジ表示を追加 -->
                                        <?php if (!empty($creator['role'])): ?>
                                        <span class="text-xs bg-blue-100 text-blue-600 px-2 py-0.5 rounded font-bold"><?= htmlspecialchars($creator['role']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex items-center gap-2 mt-0.5">
                                        <?php if (!empty($creator['slug'])): ?>
                                        <span class="text-xs text-gray-400">@<?= htmlspecialchars($creator['slug']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="flex gap-1 mt-2">
                                <a href="?edit=<?= $creator['id'] ?>" 
                                    class="flex-1 text-center px-2 py-1.5 bg-yellow-100 text-yellow-700 rounded text-xs font-bold hover:bg-yellow-200 transition">
                                    <i class="fas fa-edit mr-1"></i>編集
                                </a>
                                <?php if (!empty($creator['slug'])): ?>
                                <a href="../creator.php?slug=<?= htmlspecialchars($creator['slug']) ?>" target="_blank"
                                    class="flex-1 text-center px-2 py-1.5 bg-green-100 text-green-600 rounded text-xs font-bold hover:bg-green-200 transition">
                                    <i class="fas fa-external-link-alt mr-1"></i>表示
                                </a>
                                <?php endif; ?>
                                <a href="?delete=<?= $creator['id'] ?>" 
                                    onclick="return confirm('本当に削除しますか？')"
                                    class="px-2 py-1.5 bg-red-100 text-red-600 rounded text-xs font-bold hover:bg-red-200 transition">
                                    <i class="fas fa-trash"></i>
                                </a>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
    
    <script>
        function switchTab(tab) {
            // タブコンテンツを切り替え
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.getElementById('tab-' + tab).classList.add('active');
            
            // タブボタンのスタイルを切り替え
            document.querySelectorAll('.tab-btn').forEach(el => {
                el.classList.remove('active');
                el.classList.add('bg-gray-100', 'hover:bg-gray-200');
            });
            event.target.classList.add('active');
            event.target.classList.remove('bg-gray-100', 'hover:bg-gray-200');
        }
    </script>
</body>
</html>
