<?php
/**
 * OAuth コールバックページ
 * Google / LINE からのリダイレクト先
 */

// セッション設定を明示的に指定
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', '1');
session_start();

require_once '../includes/db.php';
require_once '../includes/site-settings.php';
require_once '../includes/member-auth.php';
require_once '../includes/oauth.php';

$db = getDB();
$error = '';
$debugInfo = '';

// デバッグモード（本番では false にする）
$debug = true;

// エラーチェック
if (isset($_GET['error'])) {
    $error = 'ログインがキャンセルされました';
    if ($debug && isset($_GET['error_description'])) {
        $debugInfo = $_GET['error_description'];
    }
} elseif (!isset($_GET['code'])) {
    $error = '認証コードがありません';
} elseif (!isset($_GET['state'])) {
    $error = 'stateパラメータがありません';
} else {
    // セッションまたはCookieからstateを取得
    $savedState = $_SESSION['oauth_state'] ?? $_COOKIE['oauth_state'] ?? null;
    $savedProvider = $_SESSION['oauth_provider'] ?? $_COOKIE['oauth_provider'] ?? null;
    
    if (!$savedState) {
        $error = 'セッションが切れました。もう一度お試しください。';
        if ($debug) {
            $debugInfo = 'Session ID: ' . session_id() . ' / Session keys: ' . implode(', ', array_keys($_SESSION)) . ' / Cookie keys: ' . implode(', ', array_keys($_COOKIE));
        }
    } elseif ($_GET['state'] !== $savedState) {
        $error = 'セキュリティエラー：stateが一致しません';
        if ($debug) {
            $debugInfo = 'GET state: ' . substr($_GET['state'], 0, 16) . '... / Saved state: ' . substr($savedState, 0, 16) . '... / Source: ' . (isset($_SESSION['oauth_state']) ? 'Session' : 'Cookie');
        }
    } else {
        $code = $_GET['code'];
        $provider = $savedProvider;
        
        // state使用済みにする
        unset($_SESSION['oauth_state']);
        unset($_SESSION['oauth_provider']);
        setcookie('oauth_state', '', time() - 3600, '/');
        setcookie('oauth_provider', '', time() - 3600, '/');
        
        try {
            if ($provider === 'google') {
            // Googleトークン取得
            $tokens = getGoogleTokens($code, $db);
            
            if (!isset($tokens['access_token'])) {
                throw new Exception('アクセストークンを取得できませんでした');
            }
            
            // ユーザー情報取得
            $userInfo = getGoogleUserInfo($tokens['access_token']);
            
            if (!isset($userInfo['id'])) {
                throw new Exception('ユーザーIDを取得できませんでした');
            }
            
            $result = loginOrRegisterOAuthUser(
                'google',
                $userInfo['id'],
                $userInfo['email'] ?? null,
                $userInfo['name'] ?? 'ユーザー',
                $userInfo['picture'] ?? null
            );
            
        } elseif ($provider === 'line') {
            // LINEトークン取得
            $tokens = getLineTokens($code, $db);
            
            if (!isset($tokens['access_token'])) {
                throw new Exception('アクセストークンを取得できませんでした');
            }
            
            // ユーザー情報取得
            $userInfo = getLineUserInfo($tokens['access_token']);
            
            if (!isset($userInfo['userId'])) {
                throw new Exception('ユーザーIDを取得できませんでした');
            }
            
            // メールアドレス取得（IDトークンから）
            $email = null;
            if (isset($tokens['id_token'])) {
                $email = getLineEmail($tokens['id_token'], $db);
            }
            
            $result = loginOrRegisterOAuthUser(
                'line',
                $userInfo['userId'],
                $email,
                $userInfo['displayName'] ?? 'ユーザー',
                $userInfo['pictureUrl'] ?? null
            );
            
        } elseif ($provider === 'amazon') {
            // Amazonトークン取得
            $tokens = getAmazonTokens($code, $db);
            
            if (!isset($tokens['access_token'])) {
                throw new Exception('アクセストークンを取得できませんでした');
            }
            
            // ユーザー情報取得
            $userInfo = getAmazonUserInfo($tokens['access_token']);
            
            if (!isset($userInfo['user_id'])) {
                throw new Exception('ユーザーIDを取得できませんでした');
            }
            
            $result = loginOrRegisterOAuthUser(
                'amazon',
                $userInfo['user_id'],
                $userInfo['email'] ?? null,
                $userInfo['name'] ?? 'ユーザー',
                null
            );
            
        } else {
            throw new Exception('プロバイダーが不明です: ' . $provider);
        }
        
        if ($result['success']) {
            // ログイン成功
            $redirect = $_SESSION['redirect_after_login'] ?? '/store/mypage.php';
            unset($_SESSION['redirect_after_login']);
            
            if ($result['is_new']) {
                $_SESSION['welcome_message'] = '会員登録が完了しました！';
            }
            
            header('Location: ' . $redirect);
            exit;
        } else {
            $error = $result['error'];
        }
        
    } catch (Exception $e) {
        $error = $e->getMessage();
        error_log('OAuth Error: ' . $e->getMessage());
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ログイン処理中 - <?= htmlspecialchars($siteName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center py-8 px-4">
    <div class="max-w-md w-full">
        <?php if ($error): ?>
        <div class="bg-white rounded-lg shadow-sm p-6 text-center">
            <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-exclamation-circle text-red-500 text-2xl"></i>
            </div>
            <h1 class="text-lg font-bold text-gray-800 mb-2">ログインできませんでした</h1>
            <p class="text-gray-600 mb-4"><?= htmlspecialchars($error) ?></p>
            
            <?php if ($debug): ?>
            <div class="bg-gray-100 rounded p-3 text-left text-xs text-gray-600 mb-4 break-all">
                <strong>デバッグ情報:</strong><br>
                <?php if ($debugInfo): ?>
                <?= htmlspecialchars($debugInfo) ?><br>
                <?php endif; ?>
                Session ID: <?= session_id() ?><br>
                oauth_state in session: <?= isset($_SESSION['oauth_state']) ? 'Yes (' . substr($_SESSION['oauth_state'], 0, 8) . '...)' : 'No' ?><br>
                oauth_state in cookie: <?= isset($_COOKIE['oauth_state']) ? 'Yes (' . substr($_COOKIE['oauth_state'], 0, 8) . '...)' : 'No' ?><br>
                oauth_provider: <?= $_SESSION['oauth_provider'] ?? $_COOKIE['oauth_provider'] ?? 'Not set' ?>
            </div>
            <?php endif; ?>
            
            <a href="/store/login.php" class="inline-block bg-orange-500 text-white px-6 py-2 rounded font-bold hover:bg-orange-600 transition">
                ログインページに戻る
            </a>
        </div>
        <?php else: ?>
        <div class="bg-white rounded-lg shadow-sm p-6 text-center">
            <div class="animate-spin w-10 h-10 border-4 border-orange-500 border-t-transparent rounded-full mx-auto mb-4"></div>
            <p class="text-gray-600">ログイン処理中...</p>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>
