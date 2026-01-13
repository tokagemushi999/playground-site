<?php
/**
 * クリエイターダッシュボード - プロフィール設定
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/csrf.php';
require_once '../includes/creator-auth.php';
require_once '../includes/image-helper.php';

$creator = requireCreatorAuth();
$db = getDB();
$message = '';
$error = '';

// プロフィール更新処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = '不正なリクエストです';
    } else {
        $name = trim($_POST['name'] ?? '');
        $role = trim($_POST['role'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $bio_long = trim($_POST['bio_long'] ?? '');
        
        // SNSリンク
        $twitter = trim($_POST['twitter'] ?? '');
        $instagram = trim($_POST['instagram'] ?? '');
        $pixiv = trim($_POST['pixiv'] ?? '');
        $youtube = trim($_POST['youtube'] ?? '');
        $tiktok = trim($_POST['tiktok'] ?? '');
        $website = trim($_POST['website'] ?? '');
        
        if (empty($name)) {
            $error = '名前は必須です';
        } else {
            try {
                // 画像アップロード処理
                $image = $creator['image'];
                if (!empty($_FILES['image']['name']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = '../uploads/creators/';
                    if (!is_dir($uploadDir)) {
                        mkdir($uploadDir, 0755, true);
                    }
                    $baseName = uniqid('creator_');
                    $result = ImageHelper::processUpload(
                        $_FILES['image']['tmp_name'],
                        $uploadDir,
                        $baseName,
                        ['maxWidth' => 400, 'maxHeight' => 400]
                    );
                    if ($result && isset($result['path'])) {
                        $image = 'uploads/creators/' . basename($result['path']);
                    }
                }
                
                // ヘッダー画像
                $header_image = $creator['header_image'] ?? '';
                if (!empty($_FILES['header_image']['name']) && $_FILES['header_image']['error'] === UPLOAD_ERR_OK) {
                    $uploadDir = '../uploads/creators/';
                    $baseName = uniqid('header_');
                    $result = ImageHelper::processUpload(
                        $_FILES['header_image']['tmp_name'],
                        $uploadDir,
                        $baseName,
                        ['maxWidth' => 1200, 'maxHeight' => 400]
                    );
                    if ($result && isset($result['path'])) {
                        $header_image = 'uploads/creators/' . basename($result['path']);
                    }
                }
                
                $stmt = $db->prepare("UPDATE creators SET 
                    name = ?, role = ?, description = ?, bio_long = ?,
                    image = ?, header_image = ?,
                    twitter = ?, instagram = ?, pixiv = ?, youtube = ?, tiktok = ?, website = ?
                    WHERE id = ?");
                $stmt->execute([
                    $name, $role, $description, $bio_long,
                    $image, $header_image,
                    $twitter, $instagram, $pixiv, $youtube, $tiktok, $website,
                    $creator['id']
                ]);
                
                $message = 'プロフィールを更新しました';
                
                // クリエイター情報を再取得
                $stmt = $db->prepare("SELECT * FROM creators WHERE id = ?");
                $stmt->execute([$creator['id']]);
                $creator = $stmt->fetch();
                
            } catch (PDOException $e) {
                $error = '更新に失敗しました';
            }
        }
    }
}

$pageTitle = 'プロフィール設定';
require_once 'includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-800">プロフィール設定</h1>
    <p class="text-gray-500 text-sm">公開ページに表示される情報を編集</p>
</div>

<?php if ($message): ?>
<div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
    <i class="fas fa-check-circle text-green-500 mr-2"></i><?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
    <i class="fas fa-exclamation-circle text-red-500 mr-2"></i><?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<form method="POST" enctype="multipart/form-data" class="space-y-6">
    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
    
    <!-- 基本情報 -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-bold text-gray-800 mb-4">
            <i class="fas fa-user text-green-500 mr-2"></i>基本情報
        </h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">名前 <span class="text-red-500">*</span></label>
                <input type="text" name="name" value="<?= htmlspecialchars($creator['name']) ?>" required
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-400 outline-none">
            </div>
            
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">肩書き・役割</label>
                <input type="text" name="role" value="<?= htmlspecialchars($creator['role'] ?? '') ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-400 outline-none"
                       placeholder="イラストレーター、漫画家など">
            </div>
            
            <div class="md:col-span-2">
                <label class="block text-sm font-bold text-gray-700 mb-2">自己紹介（短文）</label>
                <input type="text" name="description" value="<?= htmlspecialchars($creator['description'] ?? '') ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-400 outline-none"
                       placeholder="一言でお仕事内容や得意分野を説明">
                <p class="text-xs text-gray-500 mt-1">クリエイター一覧などで表示されます</p>
            </div>
            
            <div class="md:col-span-2">
                <label class="block text-sm font-bold text-gray-700 mb-2">詳細プロフィール</label>
                <textarea name="bio_long" rows="5"
                          class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-400 outline-none"
                          placeholder="経歴や活動内容など、詳しい自己紹介を記入"><?= htmlspecialchars($creator['bio_long'] ?? '') ?></textarea>
                <p class="text-xs text-gray-500 mt-1">クリエイターページに表示されます</p>
            </div>
        </div>
    </div>
    
    <!-- 画像 -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-bold text-gray-800 mb-4">
            <i class="fas fa-image text-green-500 mr-2"></i>プロフィール画像
        </h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">アイコン画像</label>
                <div class="flex items-center gap-4">
                    <?php if (!empty($creator['image'])): ?>
                    <img src="/<?= htmlspecialchars($creator['image']) ?>" class="w-20 h-20 rounded-full object-cover">
                    <?php else: ?>
                    <div class="w-20 h-20 rounded-full bg-gray-200 flex items-center justify-center">
                        <i class="fas fa-user text-gray-400 text-2xl"></i>
                    </div>
                    <?php endif; ?>
                    <div class="flex-1">
                        <input type="file" name="image" accept="image/*"
                               class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-green-100 file:text-green-700 hover:file:bg-green-200">
                        <p class="text-xs text-gray-500 mt-1">正方形推奨、最大2MB</p>
                    </div>
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">ヘッダー画像</label>
                <div class="mb-2">
                    <?php if (!empty($creator['header_image'])): ?>
                    <img src="/<?= htmlspecialchars($creator['header_image']) ?>" class="w-full h-24 rounded-lg object-cover">
                    <?php else: ?>
                    <div class="w-full h-24 rounded-lg bg-gray-200 flex items-center justify-center">
                        <i class="fas fa-image text-gray-400 text-2xl"></i>
                    </div>
                    <?php endif; ?>
                </div>
                <input type="file" name="header_image" accept="image/*"
                       class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:bg-green-100 file:text-green-700 hover:file:bg-green-200">
                <p class="text-xs text-gray-500 mt-1">推奨: 1200x400px、最大2MB</p>
            </div>
        </div>
    </div>
    
    <!-- SNSリンク -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-bold text-gray-800 mb-4">
            <i class="fas fa-share-alt text-green-500 mr-2"></i>SNS・外部リンク
        </h2>
        
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">
                    <i class="fab fa-twitter text-blue-400 mr-1"></i>Twitter / X
                </label>
                <div class="flex">
                    <span class="px-3 py-2 bg-gray-100 border border-r-0 border-gray-300 rounded-l-lg text-gray-500 text-sm">@</span>
                    <input type="text" name="twitter" value="<?= htmlspecialchars($creator['twitter'] ?? '') ?>"
                           class="flex-1 px-4 py-2 border border-gray-300 rounded-r-lg focus:ring-2 focus:ring-green-400 outline-none"
                           placeholder="username">
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">
                    <i class="fab fa-instagram text-pink-500 mr-1"></i>Instagram
                </label>
                <div class="flex">
                    <span class="px-3 py-2 bg-gray-100 border border-r-0 border-gray-300 rounded-l-lg text-gray-500 text-sm">@</span>
                    <input type="text" name="instagram" value="<?= htmlspecialchars($creator['instagram'] ?? '') ?>"
                           class="flex-1 px-4 py-2 border border-gray-300 rounded-r-lg focus:ring-2 focus:ring-green-400 outline-none"
                           placeholder="username">
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">
                    <i class="fas fa-paint-brush text-blue-600 mr-1"></i>Pixiv
                </label>
                <input type="text" name="pixiv" value="<?= htmlspecialchars($creator['pixiv'] ?? '') ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-400 outline-none"
                       placeholder="ユーザーID">
            </div>
            
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">
                    <i class="fab fa-youtube text-red-500 mr-1"></i>YouTube
                </label>
                <input type="text" name="youtube" value="<?= htmlspecialchars($creator['youtube'] ?? '') ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-400 outline-none"
                       placeholder="チャンネルID or URL">
            </div>
            
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">
                    <i class="fab fa-tiktok mr-1"></i>TikTok
                </label>
                <div class="flex">
                    <span class="px-3 py-2 bg-gray-100 border border-r-0 border-gray-300 rounded-l-lg text-gray-500 text-sm">@</span>
                    <input type="text" name="tiktok" value="<?= htmlspecialchars($creator['tiktok'] ?? '') ?>"
                           class="flex-1 px-4 py-2 border border-gray-300 rounded-r-lg focus:ring-2 focus:ring-green-400 outline-none"
                           placeholder="username">
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">
                    <i class="fas fa-globe mr-1"></i>Webサイト
                </label>
                <input type="url" name="website" value="<?= htmlspecialchars($creator['website'] ?? '') ?>"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-400 outline-none"
                       placeholder="https://example.com">
            </div>
        </div>
    </div>
    
    <!-- 公開ページプレビュー -->
    <div class="bg-gray-50 rounded-lg p-4 flex items-center justify-between">
        <div>
            <p class="font-bold text-gray-800">公開ページを確認</p>
            <p class="text-sm text-gray-500">変更を保存してから確認してください</p>
        </div>
        <a href="/creator/<?= htmlspecialchars($creator['slug'] ?? $creator['id']) ?>" target="_blank"
           class="px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition">
            <i class="fas fa-external-link-alt mr-2"></i>プレビュー
        </a>
    </div>
    
    <!-- 保存ボタン -->
    <div class="flex justify-end">
        <button type="submit" name="update_profile" value="1"
                class="px-8 py-3 bg-green-500 text-white font-bold rounded-lg hover:bg-green-600 transition shadow-lg">
            <i class="fas fa-save mr-2"></i>変更を保存
        </button>
    </div>
</form>

<?php require_once 'includes/footer.php'; ?>
