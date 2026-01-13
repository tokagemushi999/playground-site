<?php
/**
 * クリエイターログインページ
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/csrf.php';
require_once '../includes/creator-auth.php';

// 既にログイン済みの場合
if (getCurrentCreator()) {
    header('Location: /creator-dashboard/');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = '不正なリクエストです。';
    } else {
        $email = trim($_POST['email'] ?? '');
        $password = $_POST['password'] ?? '';
        
        if (empty($email) || empty($password)) {
            $error = 'メールアドレスとパスワードを入力してください。';
        } else {
            $result = loginCreator($email, $password);
            if (is_array($result) && isset($result['success']) && $result['success']) {
                $redirect = $_GET['redirect'] ?? '/creator-dashboard/';
                header('Location: ' . $redirect);
                exit;
            } else {
                // エラーメッセージを取得
                $error = is_array($result) && isset($result['error']) 
                    ? $result['error'] 
                    : 'メールアドレスまたはパスワードが正しくありません。';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>クリエイターログイン - ぷれぐら！</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body class="bg-gradient-to-br from-green-50 to-teal-50 min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-md">
        <div class="text-center mb-8">
            <div class="w-16 h-16 bg-gradient-to-br from-green-400 to-green-600 rounded-2xl flex items-center justify-center text-white text-2xl font-bold mx-auto mb-4 shadow-lg">
                C
            </div>
            <h1 class="text-2xl font-bold text-gray-800">クリエイターダッシュボード</h1>
            <p class="text-gray-500 mt-1">ログインしてサービスを管理</p>
        </div>
        
        <div class="bg-white rounded-2xl shadow-xl p-8">
            <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-lg mb-6">
                <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" class="space-y-6">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">メールアドレス</label>
                    <div class="relative">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400">
                            <i class="fas fa-envelope"></i>
                        </span>
                        <input type="email" name="email" required autocomplete="email"
                               value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                               class="w-full pl-12 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:border-green-500 focus:ring-2 focus:ring-green-200 outline-none transition">
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">パスワード</label>
                    <div class="relative">
                        <span class="absolute left-4 top-1/2 -translate-y-1/2 text-gray-400">
                            <i class="fas fa-lock"></i>
                        </span>
                        <input type="password" name="password" required autocomplete="current-password"
                               class="w-full pl-12 pr-4 py-3 border-2 border-gray-200 rounded-xl focus:border-green-500 focus:ring-2 focus:ring-green-200 outline-none transition">
                    </div>
                </div>
                
                <button type="submit" class="w-full py-4 bg-gradient-to-r from-green-500 to-green-600 text-white font-bold rounded-xl hover:from-green-600 hover:to-green-700 transition shadow-lg">
                    <i class="fas fa-sign-in-alt mr-2"></i>ログイン
                </button>
            </form>
            
            <div class="mt-6 text-center">
                <a href="/creator-dashboard/forgot-password.php" class="text-sm text-gray-500 hover:text-green-600">
                    <i class="fas fa-key mr-1"></i>パスワードをお忘れですか？
                </a>
            </div>
        </div>
        
        <div class="mt-6 text-center">
            <a href="/" class="text-gray-500 hover:text-gray-700">
                <i class="fas fa-arrow-left mr-1"></i>サイトトップに戻る
            </a>
        </div>
    </div>
</body>
</html>
