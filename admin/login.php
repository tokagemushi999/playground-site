<?php
/**
 * 管理画面ログイン（セキュリティ強化版）
 * - アカウントロック対応
 * - 二要素認証対応
 */

require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/site-settings.php';

$db = getDB();

// セキュリティテーブルを作成
createSecurityTables();

// 既にログイン済みならダッシュボードへ
if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$totpRequired = false;
$pendingAdminId = null;
$attemptsRemaining = MAX_LOGIN_ATTEMPTS;

// ログイン処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';
    $totpCode = $_POST['totp_code'] ?? null;
    
    // 初期管理者作成を試行
    createInitialAdmin();
    
    $result = login($email, $password, $totpCode);
    
    if ($result['success']) {
        header('Location: index.php');
        exit;
    } else {
        switch ($result['error']) {
            case 'locked':
                $minutes = ceil($result['remaining'] / 60);
                $error = "アカウントがロックされています。{$minutes}分後に再試行してください。";
                break;
            case 'invalid':
                $attemptsRemaining = $result['attempts_remaining'];
                if ($attemptsRemaining <= 3) {
                    $error = "メールアドレスまたはパスワードが正しくありません。残り{$attemptsRemaining}回でロックされます。";
                } else {
                    $error = 'メールアドレスまたはパスワードが正しくありません。';
                }
                break;
            case 'totp_required':
                $totpRequired = true;
                $pendingAdminId = $result['admin_id'];
                $_SESSION['pending_email'] = $email;
                $_SESSION['pending_password'] = $password;
                break;
            case 'totp_invalid':
                $totpRequired = true;
                $error = '認証コードが正しくありません。';
                if (isset($_SESSION['pending_email'])) {
                    $email = $_SESSION['pending_email'];
                }
                break;
        }
    }
}

// TOTP入力画面への遷移
if (isset($_SESSION['pending_email']) && !isset($_POST['totp_code'])) {
    $totpRequired = true;
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

    <title>ログイン | ぷれぐら！管理画面</title>
    <link rel="manifest" href="/admin/manifest.json">
    <?php $backyardFavicon = getBackyardFaviconInfo($db); ?>
    <link rel="icon" href="<?= htmlspecialchars($backyardFavicon['path']) ?>" type="<?= htmlspecialchars($backyardFavicon['type']) ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($backyardFavicon['path']) ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        body { font-family: 'Zen Maru Gothic', sans-serif; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center p-4">
    <div class="bg-white rounded-2xl shadow-xl p-8 w-full max-w-md">
        <div class="text-center mb-8">
            <div class="w-16 h-16 bg-yellow-400 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-lock text-2xl text-gray-800"></i>
            </div>
            <h1 class="text-2xl font-bold text-gray-800 mb-2">ぷれぐら！管理画面</h1>
            <p class="text-gray-500 text-sm">
                <?= $totpRequired ? '認証コードを入力してください' : 'ログインしてください' ?>
            </p>
        </div>
        
        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-lg mb-6 text-sm">
            <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>
        
        <?php if ($totpRequired): ?>
        <!-- 二要素認証入力フォーム -->
        <form method="POST" class="space-y-6">
            <input type="hidden" name="email" value="<?= htmlspecialchars($_SESSION['pending_email'] ?? '') ?>">
            <input type="hidden" name="password" value="<?= htmlspecialchars($_SESSION['pending_password'] ?? '') ?>">
            
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">
                    <i class="fas fa-mobile-alt mr-1"></i>認証コード
                </label>
                <input type="text" name="totp_code" required autofocus
                    pattern="[0-9]{6}" maxlength="6" inputmode="numeric"
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent outline-none transition text-center text-2xl tracking-widest"
                    placeholder="000000">
                <p class="text-gray-500 text-xs mt-2">認証アプリに表示されている6桁のコードを入力</p>
            </div>
            
            <button type="submit" 
                class="w-full bg-yellow-400 hover:bg-yellow-500 text-gray-900 font-bold py-3 px-4 rounded-lg transition shadow-lg hover:shadow-xl">
                <i class="fas fa-check mr-2"></i>認証
            </button>
            
            <a href="login.php" class="block text-center text-gray-500 hover:text-gray-700 text-sm">
                <i class="fas fa-arrow-left mr-1"></i>戻る
            </a>
        </form>
        <?php else: ?>
        <!-- 通常ログインフォーム -->
        <form method="POST" class="space-y-6">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">メールアドレス</label>
                <input type="email" name="email" required
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent outline-none transition"
                    placeholder="admin@example.com"
                    value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
            </div>
            
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">パスワード</label>
                <input type="password" name="password" required
                    class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent outline-none transition"
                    placeholder="••••••••">
            </div>
            
            <button type="submit" 
                class="w-full bg-yellow-400 hover:bg-yellow-500 text-gray-900 font-bold py-3 px-4 rounded-lg transition shadow-lg hover:shadow-xl">
                <i class="fas fa-sign-in-alt mr-2"></i>ログイン
            </button>
        </form>
        <?php endif; ?>
        
        <!-- セキュリティ情報 -->
        <div class="mt-8 pt-6 border-t border-gray-200">
            <div class="flex items-center justify-center gap-4 text-xs text-gray-400">
                <span><i class="fas fa-shield-alt mr-1"></i>SSL暗号化</span>
                <span><i class="fas fa-lock mr-1"></i>10回失敗でロック</span>
            </div>
        </div>
        
        <p class="text-center text-gray-400 text-xs mt-4">
            &copy; ぷれぐら！PLAYGROUND
        </p>
    </div>
</body>
</html>
<?php
// セッションのクリーンアップ（TOTP認証をキャンセルした場合）
if (!$totpRequired && isset($_SESSION['pending_email'])) {
    unset($_SESSION['pending_email']);
    unset($_SESSION['pending_password']);
}
?>
