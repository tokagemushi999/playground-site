<?php
/**
 * 会員登録ページ（シンプルデザイン）
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/member-auth.php';
require_once '../includes/site-settings.php';
require_once '../includes/oauth.php';
require_once '../includes/mail.php';

$db = getDB();

if (isLoggedIn()) {
    header('Location: /store/mypage.php');
    exit;
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
    $passwordConfirm = $_POST['password_confirm'] ?? '';
    $name = trim($_POST['name'] ?? '');
    $agreeTerms = isset($_POST['agree_terms']);
    
    if (empty($email) || empty($password) || empty($name)) {
        $error = '必須項目を入力してください';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '有効なメールアドレスを入力してください';
    } elseif (strlen($password) < 8) {
        $error = 'パスワードは8文字以上で入力してください';
    } elseif ($password !== $passwordConfirm) {
        $error = 'パスワードが一致しません';
    } elseif (!$agreeTerms) {
        $error = '利用規約への同意が必要です';
    } else {
        $result = registerMember($email, $password, $name, null);
        if ($result['success']) {
            // 会員登録完了メール送信
            try {
                sendMemberRegistrationMail([
                    'name' => $name,
                    'email' => $email
                ]);
            } catch (Exception $e) {
                error_log("Registration mail error: " . $e->getMessage());
            }
            
            loginMember($email, $password);
            header('Location: /store/mypage.php');
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
    <title>会員登録 - <?= htmlspecialchars($siteName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center py-8 px-4">
    <div class="max-w-md w-full">
        <!-- ロゴ -->
        <div class="text-center mb-6">
            <a href="/" class="text-2xl font-bold text-gray-800">ぷれぐら！</a>
            <h1 class="text-xl font-bold text-gray-800 mt-4">会員登録</h1>
            <p class="text-sm text-gray-500 mt-1">
                すでにアカウントをお持ちの方は <a href="/store/login.php" class="text-orange-500 hover:underline">ログイン</a>
            </p>
        </div>
        
        <?php if ($googleEnabled || $lineEnabled || $amazonEnabled): ?>
        <!-- ソーシャルログイン -->
        <div class="space-y-3 mb-6">
            <?php if ($googleEnabled): ?>
            <a href="/store/oauth-start.php?provider=google" class="flex items-center justify-center gap-3 w-full py-2 bg-white border rounded hover:bg-gray-50 transition">
                <svg class="w-5 h-5" viewBox="0 0 24 24"><path fill="#4285F4" d="M22.56 12.25c0-.78-.07-1.53-.2-2.25H12v4.26h5.92c-.26 1.37-1.04 2.53-2.21 3.31v2.77h3.57c2.08-1.92 3.28-4.74 3.28-8.09z"/><path fill="#34A853" d="M12 23c2.97 0 5.46-.98 7.28-2.66l-3.57-2.77c-.98.66-2.23 1.06-3.71 1.06-2.86 0-5.29-1.93-6.16-4.53H2.18v2.84C3.99 20.53 7.7 23 12 23z"/><path fill="#FBBC05" d="M5.84 14.09c-.22-.66-.35-1.36-.35-2.09s.13-1.43.35-2.09V7.07H2.18C1.43 8.55 1 10.22 1 12s.43 3.45 1.18 4.93l2.85-2.22.81-.62z"/><path fill="#EA4335" d="M12 5.38c1.62 0 3.06.56 4.21 1.64l3.15-3.15C17.45 2.09 14.97 1 12 1 7.7 1 3.99 3.47 2.18 7.07l3.66 2.84c.87-2.6 3.3-4.53 6.16-4.53z"/></svg>
                <span class="text-gray-700 font-medium">Googleで登録</span>
            </a>
            <?php endif; ?>
            <?php if ($lineEnabled): ?>
            <a href="/store/oauth-start.php?provider=line" class="flex items-center justify-center gap-3 w-full py-2 bg-[#00B900] rounded hover:bg-[#00a000] transition">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="white"><path d="M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/></svg>
                <span class="text-white font-medium">LINEで登録</span>
            </a>
            <?php endif; ?>
            <?php if ($amazonEnabled): ?>
            <a href="/store/oauth-start.php?provider=amazon" class="flex items-center justify-center gap-2 w-full py-2.5 rounded-sm transition hover:opacity-90" style="background: linear-gradient(to bottom, #f7dfa5, #f0c14b); border: 1px solid #a88734;">
                <img src="/assets/images/amazon-logo.png" alt="amazon" class="h-5" style="filter: brightness(0);">
                <span class="text-sm font-medium" style="color: #111;">で登録</span>
            </a>
            <?php endif; ?>
            <p class="text-xs text-gray-500 text-center">ソーシャルログインで利用規約に同意したものとみなされます</p>
        </div>
        
        <div class="flex items-center mb-6">
            <div class="flex-1 border-t border-gray-300"></div>
            <span class="px-3 text-sm text-gray-500">または</span>
            <div class="flex-1 border-t border-gray-300"></div>
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
                <label class="block text-sm font-medium text-gray-700 mb-1">メールアドレス <span class="text-red-500">*</span></label>
                <input name="email" type="email" required value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" 
                    class="w-full px-3 py-2 border rounded focus:ring-2 focus:ring-orange-300 outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">お名前 <span class="text-red-500">*</span></label>
                <input name="name" type="text" required value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" 
                    class="w-full px-3 py-2 border rounded focus:ring-2 focus:ring-orange-300 outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">パスワード <span class="text-red-500">*</span></label>
                <input name="password" type="password" required placeholder="8文字以上"
                    class="w-full px-3 py-2 border rounded focus:ring-2 focus:ring-orange-300 outline-none">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">パスワード確認 <span class="text-red-500">*</span></label>
                <input name="password_confirm" type="password" required 
                    class="w-full px-3 py-2 border rounded focus:ring-2 focus:ring-orange-300 outline-none">
            </div>
            <label class="flex items-start gap-2 text-sm">
                <input name="agree_terms" type="checkbox" required class="mt-1 rounded">
                <span class="text-gray-600">
                    <a href="/store/terms.php" target="_blank" class="text-orange-500 hover:underline">利用規約</a>および
                    <a href="/store/privacy.php" target="_blank" class="text-orange-500 hover:underline">プライバシーポリシー</a>に同意します
                </span>
            </label>
            <button type="submit" class="w-full py-2 bg-orange-500 hover:bg-orange-600 text-white rounded font-bold transition">
                会員登録
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
                <span class="text-gray-700 font-medium">Googleで登録</span>
            </a>
            <?php endif; ?>
            <?php if ($lineEnabled): ?>
            <a href="/store/oauth-start.php?provider=line" class="flex items-center justify-center gap-3 w-full py-2 bg-[#00B900] rounded hover:bg-[#00a000] transition">
                <svg class="w-5 h-5" viewBox="0 0 24 24" fill="white"><path d="M24 10.314C24 4.943 18.615.572 12 .572S0 4.943 0 10.314c0 4.811 4.27 8.842 10.035 9.608.391.082.923.258 1.058.59.12.301.079.766.038 1.08l-.164 1.02c-.045.301-.24 1.186 1.049.645 1.291-.539 6.916-4.078 9.436-6.975C23.176 14.393 24 12.458 24 10.314"/></svg>
                <span class="text-white font-medium">LINEで登録</span>
            </a>
            <?php endif; ?>
            <?php if ($amazonEnabled): ?>
            <a href="/store/oauth-start.php?provider=amazon" class="flex items-center justify-center gap-2 w-full py-2.5 rounded-sm transition hover:opacity-90" style="background: linear-gradient(to bottom, #f7dfa5, #f0c14b); border: 1px solid #a88734;">
                <svg class="h-5" viewBox="0 0 602 182" fill="#333">
                    <path d="M373.6 141.2c-34.2 25.3-83.8 38.7-126.5 38.7-59.8 0-113.7-22.1-154.5-59-3.2-2.9-.3-6.8 3.5-4.6 44 25.6 98.5 41 154.7 41 37.9 0 79.6-7.9 118-24.1 5.8-2.5 10.6 3.8 4.8 8z"/>
                    <path d="M387.4 125.5c-4.4-5.6-28.9-2.7-39.9-1.3-3.4.4-3.9-2.5-.8-4.7 19.5-13.7 51.5-9.8 55.3-5.2 3.8 4.7-1 37-19.3 52.4-2.8 2.4-5.5 1.1-4.3-2 4.2-10.4 13.4-33.7 9-39.2z"/>
                    <path d="M348.5 25.3V7.9c0-2.6 2-4.4 4.4-4.4h77.5c2.5 0 4.5 1.8 4.5 4.4v14.9c0 2.5-2.1 5.7-5.8 10.8l-40.2 57.3c14.9-.4 30.7 1.9 44.2 9.5 3.1 1.7 3.9 4.3 4.1 6.8v18.6c0 2.5-2.8 5.5-5.7 4-23.8-12.5-55.4-13.8-81.7.1-2.7 1.4-5.5-1.5-5.5-4.1v-17.7c0-2.9 0-7.8 2.9-12.2l46.5-66.7h-40.5c-2.5 0-4.5-1.8-4.5-4.4z"/>
                    <path d="M134.3 108.8h-23.6c-2.2-.2-4-1.9-4.2-4V8.3c0-2.4 2-4.4 4.5-4.4h22c2.3.1 4.1 1.9 4.3 4.1v12.5h.4c5.8-12.8 16.6-18.8 31.3-18.8 15 0 24.3 6 31 18.8 5.7-12.8 18.7-18.8 32.7-18.8 9.9 0 20.8 4.1 27.4 13.3 7.5 10.2 6 25.1 6 38.1v51.3c0 2.4-2 4.4-4.5 4.4h-23.5c-2.3-.2-4.2-2.1-4.2-4.4V58.3c0-5.1.5-17.9-.7-22.7-1.8-8.1-7.2-10.4-14.2-10.4-5.9 0-12 3.9-14.5 10.2-2.5 6.3-2.3 16.7-2.3 22.9v46.1c0 2.4-2 4.4-4.5 4.4h-23.5c-2.3-.2-4.2-2.1-4.2-4.4V58.3c0-13.5 2.2-33.4-14.9-33.4-17.3 0-16.6 19.4-16.6 33.4v46.1c0 2.4-2 4.4-4.5 4.4z"/>
                    <path d="M464.9 1.8c35 0 53.9 30 53.9 68.2 0 36.9-20.9 66.2-53.9 66.2-34.4 0-53.1-30-53.1-67.5 0-37.7 18.9-66.9 53.1-66.9zm.2 24.7c-17.4 0-18.5 23.7-18.5 38.5 0 14.8-.2 46.4 18.3 46.4 18.3 0 19.2-25.5 19.2-41 0-10.2-.4-22.5-3.4-32.2-2.6-8.5-7.7-11.7-15.6-11.7z"/>
                    <path d="M567.3 108.8h-23.5c-2.3-.2-4.2-2.1-4.2-4.4V8c.2-2.3 2.2-4.1 4.5-4.1h21.9c2 .1 3.7 1.5 4.2 3.4v15.9h.5c6.5-14.6 15.7-21.5 31.8-21.5 10.6 0 20.9 3.8 27.6 14.3 6.2 9.7 6.2 26.1 6.2 37.9v51.2c-.3 2.2-2.2 3.9-4.5 3.9h-23.7c-2.2-.2-3.9-1.8-4.2-3.9V57c0-13.3 1.5-32.7-15.1-32.7-5.9 0-11.3 3.9-14 9.9-3.4 7.6-3.9 15.1-3.9 22.8v47.4c0 2.4-2 4.4-4.5 4.4z"/>
                    <path d="M93.3 63c0 9.2.2 16.9-4.4 25.1-3.8 6.7-9.8 10.8-16.4 10.8-9.1 0-14.5-7-14.5-17.2 0-20.3 18.2-24 35.3-24v5.3zm24 58c-1.6 1.4-3.9 1.5-5.7.6-8-6.6-9.4-9.7-13.8-16.1-13.2 13.5-22.6 17.5-39.7 17.5-20.3 0-36.1-12.5-36.1-37.6 0-19.6 10.6-32.9 25.7-39.4 13.1-5.8 31.4-6.8 45.4-8.4v-3.1c0-5.8.5-12.6-2.9-17.6-3-4.5-8.8-6.3-13.9-6.3-9.4 0-17.8 4.8-19.9 14.9-.4 2.2-2.1 4.4-4.4 4.5l-22.8-2.5c-2.1-.5-4.4-2.1-3.8-5.3C33.7 6.1 59.3-2.2 82.3-2.2c11.8 0 27.2 3.1 36.5 12.1 11.8 11 10.6 25.6 10.6 41.6v37.7c0 11.3 4.7 16.3 9.1 22.4 1.5 2.2 1.9 4.8-.1 6.4-5 4.2-13.9 12-18.8 16.3l-.3-.3z"/>
                </svg>
                <span class="text-sm font-medium" style="color: #111;">で登録</span>
            </a>
            <?php endif; ?>
        </div>
        <p class="text-xs text-gray-400 text-center mt-2">※ソーシャルアカウントで登録すると、利用規約に同意したものとみなします。</p>
        <?php endif; ?>
        
        <p class="text-center text-sm text-gray-500 mt-6">
            <a href="/" class="hover:underline">← トップページに戻る</a>
        </p>
    </div>
</body>
</html>
