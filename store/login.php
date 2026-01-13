<?php
/**
 * ログインページ（シンプルデザイン）
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/member-auth.php';
require_once '../includes/site-settings.php';
require_once '../includes/oauth.php';

$db = getDB();

if (isLoggedIn()) {
    header('Location: /store/mypage.php');
    exit;
}

// リダイレクト先をセッションに保存
if (isset($_GET['redirect']) && !isset($_SESSION['redirect_after_login'])) {
    $_SESSION['redirect_after_login'] = $_GET['redirect'];
}

// OAuth設定があるか確認（URLは生成しない）
$config = getOAuthConfig($db);
$googleEnabled = $config['google']['enabled'] && !empty($config['google']['client_id']);
$lineEnabled = $config['line']['enabled'] && !empty($config['line']['channel_id']);
$amazonEnabled = $config['amazon']['enabled'] && !empty($config['amazon']['client_id']);
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($email) || empty($password)) {
        $error = 'メールアドレスとパスワードを入力してください';
    } else {
        $result = loginMember($email, $password, isset($_POST['remember_me']));
        if ($result['success']) {
            $redirect = $_SESSION['redirect_after_login'] ?? '/store/mypage.php';
            unset($_SESSION['redirect_after_login']);
            header('Location: ' . $redirect);
            exit;
        } else {
            $error = $result['error'];
        }
    }
}

$settings = getSiteSettings();
$siteName = $settings['site_name'] ?? 'ぷれぐら！PLAYGROUND';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php include 'includes/pwa-meta.php'; ?>
    <title>ログイン - <?= htmlspecialchars($siteName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center py-8 px-4">
    <div class="max-w-md w-full">
        <!-- ロゴ -->
        <div class="text-center mb-6">
            <a href="/" class="text-2xl font-bold text-gray-800">ぷれぐら！</a>
            <h1 class="text-xl font-bold text-gray-800 mt-4">ログイン</h1>
            <p class="text-sm text-gray-500 mt-1">
                アカウントをお持ちでない方は <a href="/store/register.php" class="text-orange-500 hover:underline">会員登録</a>
            </p>
        </div>
        
        <?php if (isset($_GET['logged_out'])): ?>
        <div class="bg-blue-50 border border-blue-200 text-blue-700 px-4 py-3 rounded mb-4 text-sm">
            ログアウトしました
        </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['message'])): ?>
        <div class="bg-yellow-50 border border-yellow-200 text-yellow-700 px-4 py-3 rounded mb-4 text-sm">
            <i class="fas fa-info-circle mr-1"></i><?= htmlspecialchars($_GET['message']) ?>
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded mb-4 text-sm">
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>
        
        <!-- フォーム -->
        <form method="POST" class="bg-white p-6 rounded-lg shadow-sm space-y-4">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">メールアドレス</label>
                <input name="email" type="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" 
                    class="w-full px-3 py-2 border rounded focus:ring-2 focus:ring-orange-300 outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">パスワード</label>
                <input name="password" type="password" required 
                    class="w-full px-3 py-2 border rounded focus:ring-2 focus:ring-orange-300 outline-none">
            </div>
            <div class="flex items-center justify-between text-sm">
                <label class="flex items-center gap-2">
                    <input name="remember_me" type="checkbox" class="rounded">
                    <span class="text-gray-600">ログイン状態を保持</span>
                </label>
                <a href="/store/forgot-password.php" class="text-orange-500 hover:underline">パスワードを忘れた</a>
            </div>
            <button type="submit" class="w-full py-2 bg-orange-500 hover:bg-orange-600 text-white rounded font-bold transition">
                ログイン
            </button>
        </form>
        
        <?php if ($googleEnabled || $lineEnabled || $amazonEnabled): ?>
        <!-- ソーシャルログイン -->
        <div class="my-6 flex items-center">
            <div class="flex-1 border-t border-gray-300"></div>
            <span class="px-3 text-sm text-gray-500">または</span>
            <div class="flex-1 border-t border-gray-300"></div>
        </div>
        
        <div class="space-y-3">
            <?php if ($googleEnabled): ?>
            <a href="/store/oauth-start.php?provider=google" class="flex items-center justify-center gap-3 w-full py-2 bg-white border rounded hover:bg-gray-50 transition">
                <svg class="w-5 h-5" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                <span class="text-gray-700 font-medium">Googleでログイン</span>
            </a>
            <?php endif; ?>
            <?php if ($lineEnabled): ?>
            <a href="/store/oauth-start.php?provider=line" class="flex items-center justify-center gap-3 w-full py-2 bg-[#00B900] rounded hover:bg-[#00a000] transition">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="white"><path d="M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/></svg>
                <span class="text-white font-medium">LINEでログイン</span>
            </a>
            <?php endif; ?>
            <?php if ($amazonEnabled): ?>
            <a href="/store/oauth-start.php?provider=amazon" class="flex items-center justify-center gap-2 w-full py-2.5 rounded-sm transition hover:opacity-90" style="background: linear-gradient(to bottom, #f7dfa5, #f0c14b); border: 1px solid #a88734;">
                <img src="/assets/images/amazon-logo.png" alt="amazon" class="h-5" style="filter: brightness(0);">
                <span class="text-sm font-medium" style="color: #111;">でログイン</span>
            </a>
            <?php endif; ?>
        </div>
        <p class="text-xs text-gray-400 text-center mt-2">※アプリ内ブラウザでは利用できない場合があります。<br>その場合はSafari/Chromeで開いてください。</p>
        <?php endif; ?>
        
        <p class="text-center text-sm text-gray-500 mt-6">
            <a href="/" class="hover:underline">← トップページに戻る</a>
        </p>
    </div>
</body>
</html>
