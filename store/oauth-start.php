<?php
/**
 * OAuth認証開始ページ
 * ここでstateを生成してからOAuthプロバイダーにリダイレクト
 */

// セッション設定を明示的に指定
ini_set('session.cookie_samesite', 'Lax');
ini_set('session.cookie_secure', '1');
session_start();

require_once '../includes/db.php';
require_once '../includes/site-settings.php';
require_once '../includes/oauth.php';

$db = getDB();
$provider = $_GET['provider'] ?? '';

// リダイレクト先を保存
if (isset($_GET['redirect'])) {
    $_SESSION['redirect_after_login'] = $_GET['redirect'];
}

$url = null;

if ($provider === 'google') {
    $url = getGoogleAuthUrl($db);
} elseif ($provider === 'line') {
    $url = getLineAuthUrl($db);
} elseif ($provider === 'amazon') {
    $url = getAmazonAuthUrl($db);
}

if ($url) {
    // セッションを確実に保存してからリダイレクト
    session_write_close();
    header('Location: ' . $url);
    exit;
}

// URLが生成できなかった場合
header('Location: /store/login.php?error=oauth_config');
exit;
