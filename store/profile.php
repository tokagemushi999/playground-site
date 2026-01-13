<?php
/**
 * アカウント設定ページ
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/member-auth.php';
require_once '../includes/site-settings.php';

requireMemberAuth();

$member = getCurrentMember();
$db = getDB();
$message = '';
$error = '';
$tab = $_GET['tab'] ?? 'profile';

// プロフィール更新
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_profile'])) {
    $name = trim($_POST['name'] ?? '');
    $nickname = trim($_POST['nickname'] ?? '');
    
    if (empty($name)) {
        $error = 'お名前を入力してください';
    } else {
        $stmt = $db->prepare("UPDATE members SET name = ?, nickname = ? WHERE id = ?");
        $stmt->execute([$name, $nickname, $member['id']]);
        $message = 'プロフィールを更新しました';
        $member['name'] = $name;
        $member['nickname'] = $nickname;
    }
}

// メールアドレス変更
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_email'])) {
    $newEmail = trim($_POST['new_email'] ?? '');
    $password = $_POST['current_password'] ?? '';
    
    if (empty($newEmail) || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $error = '有効なメールアドレスを入力してください';
    } elseif (!password_verify($password, $member['password'])) {
        $error = '現在のパスワードが正しくありません';
    } else {
        $stmt = $db->prepare("SELECT id FROM members WHERE email = ? AND id != ?");
        $stmt->execute([$newEmail, $member['id']]);
        if ($stmt->fetch()) {
            $error = 'このメールアドレスは既に使用されています';
        } else {
            $stmt = $db->prepare("UPDATE members SET email = ? WHERE id = ?");
            $stmt->execute([$newEmail, $member['id']]);
            $message = 'メールアドレスを変更しました';
            $member['email'] = $newEmail;
        }
    }
    $tab = 'email';
}

// パスワード変更
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_password'])) {
    $currentPassword = $_POST['current_password'] ?? '';
    $newPassword = $_POST['new_password'] ?? '';
    $confirmPassword = $_POST['confirm_password'] ?? '';
    
    if (!password_verify($currentPassword, $member['password'])) {
        $error = '現在のパスワードが正しくありません';
    } elseif (strlen($newPassword) < 8) {
        $error = '新しいパスワードは8文字以上で入力してください';
    } elseif ($newPassword !== $confirmPassword) {
        $error = '新しいパスワードが一致しません';
    } else {
        $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
        $stmt = $db->prepare("UPDATE members SET password = ? WHERE id = ?");
        $stmt->execute([$hashedPassword, $member['id']]);
        $message = 'パスワードを変更しました';
    }
    $tab = 'password';
}

$settings = getSiteSettings();
$siteName = $settings['site_name'] ?? 'CREATORS PLAYGROUND';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php include 'includes/pwa-meta.php'; ?>
    <title>アカウント設定 - <?= htmlspecialchars($siteName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'pop-blue': '#4A90D9',
                        'pop-pink': '#FF6B9D',
                        'pop-yellow': '#FFD93D',
                        'pop-purple': '#9B6DD8',
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-white shadow-sm sticky top-0 z-50">
        <div class="max-w-4xl mx-auto px-4 py-4 flex items-center justify-between">
            <a href="/" class="text-xl font-bold text-gray-800"><?= htmlspecialchars($siteName) ?></a>
            <a href="/store/mypage.php" class="text-gray-600 hover:text-pop-blue"><i class="fas fa-user"></i></a>
        </div>
    </header>

    <main class="max-w-2xl mx-auto px-4 py-8">
        <nav class="text-sm text-gray-500 mb-6">
            <a href="/" class="hover:text-pop-blue">ホーム</a>
            <span class="mx-2">/</span>
            <a href="/store/mypage.php" class="hover:text-pop-blue">マイページ</a>
            <span class="mx-2">/</span>
            <span class="text-gray-800">アカウント設定</span>
        </nav>
        
        <h1 class="text-2xl font-bold text-gray-800 mb-8">
            <i class="fas fa-user-cog text-pop-purple mr-2"></i>アカウント設定
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
        
        <!-- タブ -->
        <div class="flex border-b border-gray-200 mb-6">
            <a href="?tab=profile" class="px-6 py-3 font-bold text-sm <?= $tab === 'profile' ? 'text-pop-blue border-b-2 border-pop-blue' : 'text-gray-500 hover:text-gray-700' ?>">
                <i class="fas fa-user mr-1"></i>プロフィール
            </a>
            <a href="?tab=email" class="px-6 py-3 font-bold text-sm <?= $tab === 'email' ? 'text-pop-blue border-b-2 border-pop-blue' : 'text-gray-500 hover:text-gray-700' ?>">
                <i class="fas fa-envelope mr-1"></i>メールアドレス
            </a>
            <a href="?tab=password" class="px-6 py-3 font-bold text-sm <?= $tab === 'password' ? 'text-pop-blue border-b-2 border-pop-blue' : 'text-gray-500 hover:text-gray-700' ?>">
                <i class="fas fa-lock mr-1"></i>パスワード
            </a>
            <a href="?tab=address" class="px-6 py-3 font-bold text-sm <?= $tab === 'address' ? 'text-pop-blue border-b-2 border-pop-blue' : 'text-gray-500 hover:text-gray-700' ?>">
                <i class="fas fa-map-marker-alt mr-1"></i>配送先
            </a>
        </div>
        
        <?php if ($tab === 'profile'): ?>
        <!-- プロフィール -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h2 class="text-lg font-bold text-gray-800 mb-4">プロフィール設定</h2>
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">お名前 <span class="text-red-500">*</span></label>
                    <input type="text" name="name" value="<?= htmlspecialchars($member['name']) ?>" required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pop-blue outline-none">
                    <p class="text-xs text-gray-500 mt-1">配送先の宛名に使用されます</p>
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">ニックネーム</label>
                    <input type="text" name="nickname" value="<?= htmlspecialchars($member['nickname'] ?? '') ?>"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pop-blue outline-none">
                    <p class="text-xs text-gray-500 mt-1">サイト上で表示される名前です</p>
                </div>
                
                <button type="submit" name="update_profile" class="w-full bg-pop-blue hover:bg-blue-600 text-white py-3 rounded-lg font-bold transition-colors">
                    保存する
                </button>
            </form>
        </div>
        
        <?php elseif ($tab === 'email'): ?>
        <!-- メールアドレス -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h2 class="text-lg font-bold text-gray-800 mb-4">メールアドレス変更</h2>
            <div class="mb-4 p-3 bg-gray-50 rounded-lg">
                <p class="text-sm text-gray-600">現在のメールアドレス</p>
                <p class="font-bold"><?= htmlspecialchars($member['email']) ?></p>
            </div>
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">新しいメールアドレス</label>
                    <input type="email" name="new_email" required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pop-blue outline-none"
                        placeholder="new@email.com">
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">現在のパスワード</label>
                    <input type="password" name="current_password" required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pop-blue outline-none"
                        placeholder="確認のため入力">
                </div>
                
                <button type="submit" name="update_email" class="w-full bg-pop-blue hover:bg-blue-600 text-white py-3 rounded-lg font-bold transition-colors">
                    メールアドレスを変更
                </button>
            </form>
        </div>
        
        <?php elseif ($tab === 'password'): ?>
        <!-- パスワード -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h2 class="text-lg font-bold text-gray-800 mb-4">パスワード変更</h2>
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">現在のパスワード</label>
                    <input type="password" name="current_password" required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pop-blue outline-none">
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">新しいパスワード</label>
                    <input type="password" name="new_password" required minlength="8"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pop-blue outline-none"
                        placeholder="8文字以上">
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">新しいパスワード（確認）</label>
                    <input type="password" name="confirm_password" required minlength="8"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pop-blue outline-none"
                        placeholder="もう一度入力">
                </div>
                
                <button type="submit" name="update_password" class="w-full bg-pop-blue hover:bg-blue-600 text-white py-3 rounded-lg font-bold transition-colors">
                    パスワードを変更
                </button>
            </form>
        </div>
        
        <?php elseif ($tab === 'address'): ?>
        <!-- 配送先 -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <div class="flex items-center justify-between mb-4">
                <h2 class="text-lg font-bold text-gray-800">配送先住所</h2>
                <a href="/store/address.php" class="text-pop-blue hover:underline text-sm">
                    <i class="fas fa-external-link-alt mr-1"></i>管理画面へ
                </a>
            </div>
            <p class="text-gray-600 mb-4">配送先住所の追加・編集は専用ページで行えます。</p>
            <a href="/store/address.php" class="block w-full bg-pop-blue hover:bg-blue-600 text-white py-3 rounded-lg font-bold transition-colors text-center">
                <i class="fas fa-map-marker-alt mr-2"></i>配送先を管理
            </a>
        </div>
        <?php endif; ?>
        
        <!-- 登録情報 -->
        <div class="mt-8 bg-gray-100 rounded-xl p-6">
            <h3 class="font-bold text-gray-700 mb-3">アカウント情報</h3>
            <div class="text-sm text-gray-600 space-y-1">
                <p><span class="text-gray-500">会員ID:</span> <?= $member['id'] ?></p>
                <p><span class="text-gray-500">登録日:</span> <?= date('Y年n月j日', strtotime($member['created_at'])) ?></p>
                <p><span class="text-gray-500">最終ログイン:</span> <?= isset($member['last_login_at']) && $member['last_login_at'] ? date('Y年n月j日 H:i', strtotime($member['last_login_at'])) : '-' ?></p>
            </div>
        </div>
        
        <div class="mt-6 text-center">
            <a href="/store/mypage.php" class="text-pop-blue hover:underline">
                <i class="fas fa-arrow-left mr-1"></i>マイページに戻る
            </a>
        </div>
    </main>
</body>
</html>
