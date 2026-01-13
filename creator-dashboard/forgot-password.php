<?php
/**
 * クリエイター パスワードリセット依頼
 */
require_once '../includes/db.php';
require_once '../includes/site-settings.php';
require_once '../includes/creator-auth.php';
require_once '../includes/mail.php';

$db = getDB();
$error = '';
$success = false;

// サイト設定
$settings = getSiteSettings();
$siteName = $settings['site_name'] ?? 'ぷれぐら！PLAYGROUND';

// リセット依頼処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'メールアドレスを入力してください。';
    } else {
        // クリエイターを検索
        $stmt = $db->prepare("SELECT * FROM creators WHERE email = ? AND is_active = 1");
        $stmt->execute([$email]);
        $creator = $stmt->fetch();
        
        if ($creator) {
            // ログインが有効化されているか確認
            if (empty($creator['login_enabled'])) {
                $error = 'このアカウントはログインが許可されていません。運営にお問い合わせください。';
            } else {
                // リセットトークンを生成
                $token = generatePasswordResetToken($db, $creator['id']);
                $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
                         . '://' . $_SERVER['HTTP_HOST'];
                $resetUrl = $baseUrl . '/creator-dashboard/reset-password.php?token=' . $token;
                
                // メール送信
                $subject = "【{$siteName}】パスワードリセット";
                $body = "{$creator['name']} 様\n\n";
                $body .= "パスワードリセットのリクエストを受け付けました。\n\n";
                $body .= "以下のリンクから新しいパスワードを設定してください：\n";
                $body .= $resetUrl . "\n\n";
                $body .= "※このリンクは24時間有効です。\n";
                $body .= "※このリクエストに心当たりがない場合は、このメールを無視してください。\n\n";
                $body .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
                $body .= $siteName . "\n";
                $body .= $baseUrl . "\n";
                
                sendMail($creator['email'], $subject, $body);
            }
        }
        
        // セキュリティのため、存在しないメールでも同じメッセージを表示
        if (empty($error)) {
            $success = true;
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>パスワードリセット - <?= htmlspecialchars($siteName) ?></title>
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
        <!-- 送信完了画面 -->
        <div class="bg-white rounded-2xl shadow-xl p-8 text-center">
            <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-envelope-open-text text-green-500 text-3xl"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-800 mb-2">メールを送信しました</h2>
            <p class="text-gray-600 mb-6">
                登録されているメールアドレス宛に<br>
                パスワードリセット用のリンクを送信しました。
            </p>
            <p class="text-sm text-gray-500 mb-6">
                メールが届かない場合は、迷惑メールフォルダをご確認ください。
            </p>
            <a href="/creator-dashboard/login.php" class="text-green-600 hover:underline">
                <i class="fas fa-arrow-left mr-1"></i>ログインページに戻る
            </a>
        </div>
        
        <?php else: ?>
        <!-- リセット依頼フォーム -->
        <div class="bg-white rounded-2xl shadow-xl p-8">
            <h2 class="text-xl font-bold text-gray-800 text-center mb-2">パスワードをお忘れですか？</h2>
            <p class="text-gray-500 text-sm text-center mb-6">
                登録済みのメールアドレスを入力してください
            </p>
            
            <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-600 rounded-lg p-3 mb-4 text-sm">
                <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">メールアドレス</label>
                    <input type="email" name="email" required
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-400 focus:border-green-400 outline-none"
                           placeholder="email@example.com"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>">
                </div>
                
                <button type="submit" 
                        class="w-full py-3 bg-gradient-to-r from-green-500 to-emerald-500 text-white rounded-lg font-bold hover:opacity-90 transition">
                    <i class="fas fa-paper-plane mr-2"></i>リセットリンクを送信
                </button>
            </form>
            
            <div class="mt-6 text-center">
                <a href="/creator-dashboard/login.php" class="text-green-600 hover:underline text-sm">
                    <i class="fas fa-arrow-left mr-1"></i>ログインページに戻る
                </a>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
