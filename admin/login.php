<?php
/**
 * 管理画面ログイン
 */

require_once '../includes/db.php';
require_once '../includes/auth.php';

// 既にログイン済みならダッシュボードへ
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';

// ログイン処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    
    // 初期管理者作成を試行
    createInitialAdmin();
    
    if (login($email, $password)) {
        header('Location: index.php');
        exit;
    } else {
        $error = 'メールアドレスまたはパスワードが正しくありません。';
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ログイン | ぷれぐら！管理画面</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Zen Maru Gothic', sans-serif; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl p-8 w-full max-w-md">
        <div class="text-center mb-8">
            <h1 class="text-2xl font-bold text-gray-800 mb-2">ぷれぐら！管理画面</h1>
            <p class="text-gray-500 text-sm">ログインしてください</p>
        </div>
        
        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-lg mb-6 text-sm">
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" class="space-y-6">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">メールアドレス</label>
                <input type="email" name="email" required
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent outline-none transition"
                    placeholder="admin@example.com">
            </div>
            
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">パスワード</label>
                <input type="password" name="password" required
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent outline-none transition"
                    placeholder="••••••••">
            </div>
            
            <button type="submit" 
                class="w-full bg-yellow-400 hover:bg-yellow-500 text-gray-900 font-bold py-3 px-4 rounded-lg transition shadow-lg hover:shadow-xl">
                ログイン
            </button>
        </form>
        
        <p class="text-center text-gray-400 text-xs mt-8">
            &copy; ぷれぐら！PLAYGROUND
        </p>
    </div>
</body>
</html>
