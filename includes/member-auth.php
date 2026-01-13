<?php
/**
 * 会員認証ヘルパー
 */

require_once __DIR__ . '/db.php';

// セッション開始（まだ開始していない場合）
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * 会員登録
 */
function registerMember($email, $password, $name, $nickname = null) {
    $db = getDB();
    
    // メールアドレスの重複チェック
    $stmt = $db->prepare("SELECT id FROM members WHERE email = ?");
    $stmt->execute([$email]);
    if ($stmt->fetch()) {
        return ['success' => false, 'error' => 'このメールアドレスは既に登録されています'];
    }
    
    // パスワードハッシュ化
    $passwordHash = password_hash($password, PASSWORD_DEFAULT);
    
    // メール認証トークン生成
    $verifyToken = bin2hex(random_bytes(32));
    
    try {
        $stmt = $db->prepare("
            INSERT INTO members (email, password_hash, name, nickname, email_verify_token, terms_agreed_at, privacy_agreed_at)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$email, $passwordHash, $name, $nickname, $verifyToken]);
        
        $memberId = $db->lastInsertId();
        
        // TODO: 認証メール送信
        // sendVerificationEmail($email, $verifyToken);
        
        return ['success' => true, 'member_id' => $memberId, 'verify_token' => $verifyToken];
    } catch (PDOException $e) {
        return ['success' => false, 'error' => '登録に失敗しました'];
    }
}

/**
 * ログイン
 */
function loginMember($email, $password, $rememberMe = false) {
    $db = getDB();
    
    $stmt = $db->prepare("SELECT * FROM members WHERE email = ? AND status = 'active'");
    $stmt->execute([$email]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$member || !password_verify($password, $member['password_hash'])) {
        return ['success' => false, 'error' => 'メールアドレスまたはパスワードが正しくありません'];
    }
    
    // セッションに会員情報を保存
    $_SESSION['member_id'] = $member['id'];
    $_SESSION['member_email'] = $member['email'];
    $_SESSION['member_name'] = $member['name'];
    $_SESSION['member_nickname'] = $member['nickname'];
    
    // セッショントークンを生成してDBに保存
    $sessionToken = bin2hex(random_bytes(32));
    $expiresAt = $rememberMe ? date('Y-m-d H:i:s', strtotime('+30 days')) : date('Y-m-d H:i:s', strtotime('+24 hours'));
    
    $stmt = $db->prepare("
        INSERT INTO member_sessions (member_id, session_token, ip_address, user_agent, expires_at)
        VALUES (?, ?, ?, ?, ?)
    ");
    $stmt->execute([
        $member['id'],
        $sessionToken,
        $_SERVER['REMOTE_ADDR'] ?? null,
        $_SERVER['HTTP_USER_AGENT'] ?? null,
        $expiresAt
    ]);
    
    // Cookieにセッショントークンを保存
    $cookieExpires = $rememberMe ? time() + (30 * 24 * 60 * 60) : 0;
    setcookie('member_session', $sessionToken, $cookieExpires, '/', '', true, true);
    
    // ゲストカートをマージ
    mergeGuestCart($member['id']);
    
    return ['success' => true, 'member' => $member];
}

/**
 * ログアウト
 */
function logoutMember() {
    $db = getDB();
    
    // DBからセッション削除
    if (isset($_COOKIE['member_session'])) {
        $stmt = $db->prepare("DELETE FROM member_sessions WHERE session_token = ?");
        $stmt->execute([$_COOKIE['member_session']]);
        setcookie('member_session', '', time() - 3600, '/', '', true, true);
    }
    
    // セッション破棄
    unset($_SESSION['member_id']);
    unset($_SESSION['member_email']);
    unset($_SESSION['member_name']);
    unset($_SESSION['member_nickname']);
    
    return true;
}

/**
 * ログイン状態チェック
 */
function isLoggedIn() {
    // セッションにmember_idがあればログイン中
    if (isset($_SESSION['member_id'])) {
        return true;
    }
    
    // Cookieからセッショントークンを確認
    if (isset($_COOKIE['member_session'])) {
        $db = getDB();
        $stmt = $db->prepare("
            SELECT m.* FROM members m
            JOIN member_sessions s ON m.id = s.member_id
            WHERE s.session_token = ? AND s.expires_at > NOW() AND m.status = 'active'
        ");
        $stmt->execute([$_COOKIE['member_session']]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($member) {
            $_SESSION['member_id'] = $member['id'];
            $_SESSION['member_email'] = $member['email'];
            $_SESSION['member_name'] = $member['name'];
            $_SESSION['member_nickname'] = $member['nickname'];
            return true;
        }
    }
    
    return false;
}

/**
 * ログイン中の会員情報を取得
 */
function getCurrentMember() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM members WHERE id = ? AND status = 'active'");
    $stmt->execute([$_SESSION['member_id']]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/**
 * ログイン必須ページ用
 */
function requireMemberAuth() {
    if (!isLoggedIn()) {
        $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'];
        header('Location: /store/login.php');
        exit;
    }
}

/**
 * パスワードリセットトークン生成
 */
function createPasswordResetToken($email) {
    $db = getDB();
    
    $stmt = $db->prepare("SELECT id FROM members WHERE email = ? AND status = 'active'");
    $stmt->execute([$email]);
    $member = $stmt->fetch();
    
    if (!$member) {
        // セキュリティのため、存在しない場合も同じレスポンス
        return ['success' => true];
    }
    
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+1 hour'));
    
    $stmt = $db->prepare("UPDATE members SET password_reset_token = ?, password_reset_expires = ? WHERE id = ?");
    $stmt->execute([$token, $expires, $member['id']]);
    
    // TODO: パスワードリセットメール送信
    // sendPasswordResetEmail($email, $token);
    
    return ['success' => true, 'token' => $token];
}

/**
 * パスワードリセット実行
 */
function resetPassword($token, $newPassword) {
    $db = getDB();
    
    $stmt = $db->prepare("
        SELECT id FROM members 
        WHERE password_reset_token = ? AND password_reset_expires > NOW() AND status = 'active'
    ");
    $stmt->execute([$token]);
    $member = $stmt->fetch();
    
    if (!$member) {
        return ['success' => false, 'error' => 'リンクが無効か期限切れです'];
    }
    
    $passwordHash = password_hash($newPassword, PASSWORD_DEFAULT);
    
    $stmt = $db->prepare("
        UPDATE members 
        SET password_hash = ?, password_reset_token = NULL, password_reset_expires = NULL 
        WHERE id = ?
    ");
    $stmt->execute([$passwordHash, $member['id']]);
    
    return ['success' => true];
}

/**
 * メール認証
 */
function verifyEmail($token) {
    $db = getDB();
    
    $stmt = $db->prepare("
        SELECT id FROM members 
        WHERE email_verify_token = ? AND email_verified_at IS NULL
    ");
    $stmt->execute([$token]);
    $member = $stmt->fetch();
    
    if (!$member) {
        return ['success' => false, 'error' => '無効なトークンです'];
    }
    
    $stmt = $db->prepare("
        UPDATE members 
        SET email_verified_at = NOW(), email_verify_token = NULL 
        WHERE id = ?
    ");
    $stmt->execute([$member['id']]);
    
    return ['success' => true];
}

/**
 * ゲストカートを会員カートにマージ
 */
function mergeGuestCart($memberId) {
    if (!isset($_SESSION['guest_cart_id'])) {
        return;
    }
    
    $db = getDB();
    $guestCartId = $_SESSION['guest_cart_id'];
    
    // 会員のカートを取得または作成
    $stmt = $db->prepare("SELECT id FROM carts WHERE member_id = ?");
    $stmt->execute([$memberId]);
    $memberCart = $stmt->fetch();
    
    if (!$memberCart) {
        $stmt = $db->prepare("INSERT INTO carts (member_id) VALUES (?)");
        $stmt->execute([$memberId]);
        $memberCartId = $db->lastInsertId();
    } else {
        $memberCartId = $memberCart['id'];
    }
    
    // ゲストカートのアイテムを会員カートに移動
    $stmt = $db->prepare("
        INSERT INTO cart_items (cart_id, product_id, quantity)
        SELECT ?, ci.product_id, ci.quantity FROM cart_items ci WHERE ci.cart_id = ?
        ON DUPLICATE KEY UPDATE quantity = cart_items.quantity + VALUES(quantity)
    ");
    $stmt->execute([$memberCartId, $guestCartId]);
    
    // ゲストカートを削除
    $stmt = $db->prepare("DELETE FROM carts WHERE id = ?");
    $stmt->execute([$guestCartId]);
    
    unset($_SESSION['guest_cart_id']);
}
