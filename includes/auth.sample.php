<?php
/**
 * 認証処理
 */

session_start();

// 初期管理者（adminsテーブルが空の場合に作成されます）
define('INITIAL_ADMIN_EMAIL', 'admin@example.com');
define('INITIAL_ADMIN_PASSWORD', 'change-me');

define('ADMIN_EMAIL', 'admin@example.com');
define('ADMIN_PASSWORD_HASH', 'CHANGE_ME'); // 初回ロ��イン時に更新される

/**
 * ログイン処理
 */
function login($email, $password) {
    $db = getDB();
    
    // 管理者をDBから取得
    $stmt = $db->prepare("SELECT * FROM admins WHERE email = ?");
    $stmt->execute([$email]);
    $admin = $stmt->fetch();
    
    if ($admin && password_verify($password, $admin['password'])) {
        $_SESSION['admin_id'] = $admin['id'];
        $_SESSION['admin_email'] = $admin['email'];
        $_SESSION['admin_name'] = $admin['name'];
        $_SESSION['logged_in'] = true;
        return true;
    }
    
    return false;
}

/**
 * ログアウト処理
 */
function logout() {
    session_destroy();
    header('Location: login.php');
    exit;
}

/**
 * ログイン状態チェック
 */
function isLoggedIn() {
    return isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true;
}

/**
 * 認証必須ページのチェック
 */
function requireAuth() {
    if (!isLoggedIn()) {
        header('Location: login.php');
        exit;
    }
}

/**
 * 初期管理者を作成
 */
function createInitialAdmin() {
    $db = getDB();
    
    // 既存の管理者がいるかチェック
    $stmt = $db->query("SELECT COUNT(*) as count FROM admins");
    $result = $stmt->fetch();
    
    if ($result['count'] == 0) {
        // 初期管理者を作成
        $hashedPassword = password_hash(INITIAL_ADMIN_PASSWORD, PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO admins (email, password, name) VALUES (?, ?, ?)");
        $stmt->execute([INITIAL_ADMIN_EMAIL, $hashedPassword, '管理者']);
        return true;
    }
    
    return false;
}
?>
