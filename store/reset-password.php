<?php
/**
 * パスワードリセット実行ページ
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/member-auth.php';
require_once '../includes/site-settings.php';

$db = getDB();
$token = $_GET['token'] ?? '';
$error = '';
$success = false;

// トークン検証
$validToken = false;
if ($token) {
    $stmt = $db->prepare("SELECT * FROM members WHERE password_reset_token = ? AND password_reset_expires > NOW()");
    $stmt->execute([$token]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    $validToken = (bool)$member;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $validToken) {
    $password = $_POST['password'] ?? '';
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    
    if (empty($password)) {
        $error = '新しいパスワードを入力してください';
    } elseif (strlen($password) < 8) {
        $error = 'パスワードは8文字以上で入力してください';
    } elseif ($password !== $passwordConfirm) {
        $error = 'パスワードが一致しません';
    } else {
        $result = resetPassword($token, $password);
        if ($result['success']) {
            $success = true;
        } else {
            $error = $result['error'];
        }
    }
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
    <title>パスワードリセット - <?= htmlspecialchars($siteName) ?></title>
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
<body class="bg-gray-50 min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <a href="/" class="text-2xl font-bold text-gray-800"><?= htmlspecialchars($siteName) ?></a>
        </div>
        
        <div class="bg-white rounded-2xl shadow-sm p-8">
            <?php if ($success): ?>
            <div class="text-center">
                <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-check text-3xl text-green-500"></i>
                </div>
                <h1 class="text-xl font-bold text-gray-800 mb-2">パスワードを変更しました</h1>
                <p class="text-gray-500 text-sm mb-6">新しいパスワードでログインしてください</p>
                <a href="/store/login.php" class="block w-full bg-pop-blue hover:bg-blue-600 text-white py-3 rounded-lg font-bold transition-colors text-center">
                    ログインする
                </a>
            </div>
            
            <?php elseif (!$validToken): ?>
            <div class="text-center">
                <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-times text-3xl text-red-500"></i>
                </div>
                <h1 class="text-xl font-bold text-gray-800 mb-2">無効なリンクです</h1>
                <p class="text-gray-500 text-sm mb-6">
                    このリンクは無効または有効期限切れです。<br>
                    再度パスワードリセットをお試しください。
                </p>
                <a href="/store/forgot-password.php" class="block w-full bg-pop-blue hover:bg-blue-600 text-white py-3 rounded-lg font-bold transition-colors text-center">
                    パスワードリセットを再申請
                </a>
            </div>
            
            <?php else: ?>
            <h1 class="text-xl font-bold text-gray-800 mb-2 text-center">新しいパスワードを設定</h1>
            <p class="text-gray-500 text-sm text-center mb-6">
                8文字以上で入力してください
            </p>
            
            <?php if ($error): ?>
            <div class="bg-red-100 border border-red-300 text-red-700 px-4 py-3 rounded-lg mb-6 text-sm">
                <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">新しいパスワード</label>
                    <input type="password" name="password" required minlength="8"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pop-blue outline-none"
                        placeholder="8文字以上">
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">パスワード（確認）</label>
                    <input type="password" name="password_confirm" required minlength="8"
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pop-blue outline-none"
                        placeholder="もう一度入力">
                </div>
                
                <button type="submit" class="w-full bg-pop-blue hover:bg-blue-600 text-white py-3 rounded-lg font-bold transition-colors">
                    パスワードを変更
                </button>
            </form>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
