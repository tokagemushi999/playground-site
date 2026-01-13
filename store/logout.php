<?php
/**
 * ログアウト処理
 */

// 出力バッファリング開始
ob_start();

// セッション開始（既に開始されている場合も対応）
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// デバッグ情報
$debugBefore = [
    'session_id' => session_id(),
    'member_id' => $_SESSION['member_id'] ?? 'なし',
    'member_session_cookie' => $_COOKIE['member_session'] ?? 'なし',
];

// 1. member_session Cookie からDBのセッションを無効化
if (isset($_COOKIE['member_session'])) {
    require_once '../includes/db.php';
    try {
        $db = getDB();
        $stmt = $db->prepare("DELETE FROM member_sessions WHERE session_token = ?");
        $stmt->execute([$_COOKIE['member_session']]);
    } catch (Exception $e) {
        // エラーは無視
    }
}

// 2. member_idを明示的に削除
if (isset($_SESSION['member_id'])) {
    unset($_SESSION['member_id']);
}

// 3. 全てのセッション変数をクリア
$_SESSION = [];

// 4. セッションCookieを削除
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', 1,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// 5. 認証関連のCookieを全て削除
setcookie('member_session', '', 1, '/', '', true, true);
setcookie('remember_token', '', 1, '/');
setcookie('PHPSESSID', '', 1, '/');

// 6. セッションを破棄
session_destroy();

// 7. 出力バッファをクリア
ob_end_clean();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ログアウト完了</title>
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, sans-serif; display: flex; justify-content: center; align-items: center; min-height: 100vh; margin: 0; background: #f3f4f6; }
        .box { background: white; padding: 2rem; border-radius: 8px; text-align: center; box-shadow: 0 2px 10px rgba(0,0,0,0.1); max-width: 90%; }
        .icon { font-size: 3rem; margin-bottom: 1rem; }
        h1 { font-size: 1.25rem; margin: 0 0 0.5rem; }
        p { color: #666; margin: 0 0 1.5rem; font-size: 0.9rem; }
        a { display: inline-block; padding: 0.75rem 2rem; background: #f97316; color: white; text-decoration: none; border-radius: 6px; font-weight: bold; }
        a:hover { background: #ea580c; }
    </style>
</head>
<body>
    <div class="box">
        <div class="icon">✓</div>
        <h1>ログアウトしました</h1>
        <p>ご利用ありがとうございました</p>
        <a href="/store/">ストアトップへ</a>
    </div>
    
    <script>
        // ブラウザ側でもストレージをクリア
        try {
            sessionStorage.clear();
            localStorage.removeItem('member_id');
            localStorage.removeItem('logged_in');
        } catch(e) {}
        
        // Cookieも削除（JavaScript側から）
        document.cookie = 'member_session=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
        document.cookie = 'PHPSESSID=; expires=Thu, 01 Jan 1970 00:00:00 UTC; path=/;';
    </script>
</body>
</html>
