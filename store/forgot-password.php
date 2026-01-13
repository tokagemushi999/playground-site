<?php
/**
 * パスワードリセット申請ページ
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/member-auth.php';
require_once '../includes/site-settings.php';
require_once '../includes/mail.php';

// 既にログイン中なら mypage へ
if (isLoggedIn()) {
    header('Location: /store/mypage.php');
    exit;
}

$db = getDB();
$message = '';
$error = '';
$sent = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    
    if (empty($email)) {
        $error = 'メールアドレスを入力してください';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = '有効なメールアドレスを入力してください';
    } else {
        // トークン生成
        $result = createPasswordResetToken($email);
        
        // セキュリティのため、メールが存在するかどうかに関わらず同じメッセージを表示
        $sent = true;
        $message = 'パスワードリセット用のメールを送信しました。メールが届かない場合は、メールアドレスが正しいかご確認ください。';
        
        // 実際のメール送信処理（トークンが生成された場合のみ）
        if ($result['success'] && isset($result['token'])) {
            $resetUrl = 'https://' . $_SERVER['HTTP_HOST'] . '/store/reset-password.php?token=' . $result['token'];
            
            // 会員名を取得
            $stmt = $db->prepare("SELECT name FROM members WHERE email = ?");
            $stmt->execute([$email]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // パスワードリセットメール送信
            try {
                sendPasswordResetMail([
                    'name' => $member['name'] ?? 'お客',
                    'email' => $email
                ], $resetUrl);
            } catch (Exception $e) {
                error_log("Password reset mail error: " . $e->getMessage());
            }
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
    <title>パスワードをお忘れの方 - <?= htmlspecialchars($siteName) ?></title>
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
            <h1 class="text-xl font-bold text-gray-800 mb-2 text-center">パスワードをお忘れの方</h1>
            <p class="text-gray-500 text-sm text-center mb-6">
                登録したメールアドレスを入力してください
            </p>
            
            <?php if ($sent): ?>
            <div class="bg-green-50 border border-green-200 text-green-700 px-4 py-6 rounded-lg text-center">
                <i class="fas fa-envelope text-3xl mb-3"></i>
                <p class="font-bold mb-2">メールを送信しました</p>
                <p class="text-sm"><?= htmlspecialchars($message) ?></p>
            </div>
            
            <div class="mt-6 text-center">
                <a href="/store/login.php" class="text-pop-blue hover:underline">
                    <i class="fas fa-arrow-left mr-1"></i>ログインに戻る
                </a>
            </div>
            <?php else: ?>
            
            <?php if ($error): ?>
            <div class="bg-red-100 border border-red-300 text-red-700 px-4 py-3 rounded-lg mb-6 text-sm">
                <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" class="space-y-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">メールアドレス</label>
                    <input type="email" name="email" value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required
                        class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pop-blue outline-none"
                        placeholder="example@email.com">
                </div>
                
                <button type="submit" class="w-full bg-pop-blue hover:bg-blue-600 text-white py-3 rounded-lg font-bold transition-colors">
                    リセットメールを送信
                </button>
            </form>
            
            <div class="mt-6 text-center text-sm text-gray-500">
                <a href="/store/login.php" class="text-pop-blue hover:underline">
                    <i class="fas fa-arrow-left mr-1"></i>ログインに戻る
                </a>
            </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
