<?php
/**
 * クリエイター パスワードリセット実行
 */
require_once '../includes/db.php';
require_once '../includes/site-settings.php';
require_once '../includes/creator-auth.php';

$db = getDB();
$error = '';
$success = false;
$creator = null;

// サイト設定
$settings = getSiteSettings();
$siteName = $settings['site_name'] ?? 'ぷれぐら！PLAYGROUND';

// トークン確認
$token = $_GET['token'] ?? '';

if (empty($token)) {
    $error = '無効なリンクです。';
} else {
    $creator = validatePasswordResetToken($db, $token);
    if (!$creator) {
        $error = 'リンクの有効期限が切れています。もう一度パスワードリセットをリクエストしてください。';
    }
}

// パスワードリセット処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $creator) {
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    
    if (strlen($password) < 8) {
        $error = 'パスワードは8文字以上で入力してください。';
    } elseif ($password !== $passwordConfirm) {
        $error = 'パスワードが一致しません。';
    } else {
        if (resetCreatorPassword($db, $creator['id'], $password)) {
            $success = true;
        } else {
            $error = 'パスワードのリセットに失敗しました。';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>パスワード再設定 - <?= htmlspecialchars($siteName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gradient-to-br from-green-50 to-emerald-100 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <!-- ロゴ -->
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 bg-gradient-to-br from-green-400 to-green-600 rounded-2xl shadow-lg mb-4">
                <i class="fas fa-palette text-white text-2xl"></i>
            </div>
            <h1 class="text-2xl font-bold text-gray-800"><?= htmlspecialchars($siteName) ?></h1>
            <p class="text-gray-500">クリエイターダッシュボード</p>
        </div>
        
        <?php if ($success): ?>
        <!-- 成功画面 -->
        <div class="bg-white rounded-2xl shadow-xl p-8 text-center">
            <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-check-circle text-green-500 text-3xl"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-800 mb-2">パスワードを変更しました</h2>
            <p class="text-gray-600 mb-6">
                新しいパスワードでログインできます。
            </p>
            <a href="/creator-dashboard/login.php" 
               class="block w-full py-3 bg-gradient-to-r from-green-500 to-emerald-500 text-white rounded-lg font-bold hover:opacity-90 transition">
                <i class="fas fa-sign-in-alt mr-2"></i>ログインする
            </a>
        </div>
        
        <?php elseif ($error && !$creator): ?>
        <!-- エラー画面（トークン無効） -->
        <div class="bg-white rounded-2xl shadow-xl p-8 text-center">
            <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-exclamation-triangle text-red-500 text-3xl"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-800 mb-2">リンクが無効です</h2>
            <p class="text-gray-600 mb-6">
                <?= htmlspecialchars($error) ?>
            </p>
            <a href="/creator-dashboard/forgot-password.php" 
               class="block w-full py-3 bg-gradient-to-r from-green-500 to-emerald-500 text-white rounded-lg font-bold hover:opacity-90 transition">
                <i class="fas fa-redo mr-2"></i>もう一度リクエストする
            </a>
        </div>
        
        <?php else: ?>
        <!-- パスワード再設定フォーム -->
        <div class="bg-white rounded-2xl shadow-xl p-8">
            <div class="text-center mb-6">
                <h2 class="text-xl font-bold text-gray-800">新しいパスワードを設定</h2>
                <p class="text-gray-500 text-sm"><?= htmlspecialchars($creator['name']) ?>さん</p>
            </div>
            
            <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-600 rounded-lg p-3 mb-4 text-sm">
                <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">新しいパスワード</label>
                    <div class="relative">
                        <input type="password" name="password" id="password" required minlength="8"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-400 focus:border-green-400 outline-none"
                               placeholder="8文字以上">
                        <button type="button" onclick="togglePassword('password')" 
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">パスワード（確認）</label>
                    <div class="relative">
                        <input type="password" name="password_confirm" id="password_confirm" required minlength="8"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-400 focus:border-green-400 outline-none"
                               placeholder="もう一度入力">
                        <button type="button" onclick="togglePassword('password_confirm')" 
                                class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" 
                        class="w-full py-3 bg-gradient-to-r from-green-500 to-emerald-500 text-white rounded-lg font-bold hover:opacity-90 transition">
                    <i class="fas fa-key mr-2"></i>パスワードを変更
                </button>
            </form>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
    function togglePassword(id) {
        const input = document.getElementById(id);
        const icon = input.nextElementSibling.querySelector('i');
        if (input.type === 'password') {
            input.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            input.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }
    </script>
</body>
</html>
