<?php
/**
 * クリエイター認証ヘルパー
 */

require_once __DIR__ . '/db.php';

// セッション開始（まだ開始していない場合）
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

/**
 * 現在ログイン中のクリエイターを取得
 */
function getCurrentCreator() {
    if (empty($_SESSION['creator_id'])) {
        return null;
    }
    
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM creators WHERE id = ? AND is_active = 1");
    $stmt->execute([$_SESSION['creator_id']]);
    $creator = $stmt->fetch();
    
    // ログイン権限が無効化されていたらログアウト
    if ($creator && isset($creator['login_enabled']) && !$creator['login_enabled']) {
        logoutCreator();
        return null;
    }
    
    return $creator;
}

/**
 * クリエイターログインを要求
 */
function requireCreatorAuth() {
    $creator = getCurrentCreator();
    if (!$creator) {
        header('Location: /creator-dashboard/login.php');
        exit;
    }
    return $creator;
}

/**
 * クリエイターログイン処理
 */
function loginCreator($email, $password) {
    $db = getDB();
    
    // login_enabledカラムの存在を確認
    $hasLoginEnabled = false;
    try {
        $stmt = $db->query("SHOW COLUMNS FROM creators LIKE 'login_enabled'");
        $hasLoginEnabled = $stmt->rowCount() > 0;
    } catch (PDOException $e) {}
    
    // クリエイター取得
    if ($hasLoginEnabled) {
        $stmt = $db->prepare("SELECT * FROM creators WHERE email = ? AND is_active = 1");
    } else {
        $stmt = $db->prepare("SELECT * FROM creators WHERE email = ? AND is_active = 1");
    }
    $stmt->execute([$email]);
    $creator = $stmt->fetch();
    
    if (!$creator) {
        logLoginAttempt(null, $email, 'failed');
        return ['error' => 'メールアドレスまたはパスワードが正しくありません'];
    }
    
    // ログイン権限チェック
    if ($hasLoginEnabled && isset($creator['login_enabled']) && !$creator['login_enabled']) {
        logLoginAttempt($creator['id'], $email, 'failed');
        return ['error' => 'このアカウントはログインが許可されていません。運営にお問い合わせください。'];
    }
    
    // アカウントロックチェック
    if (!empty($creator['locked_until']) && strtotime($creator['locked_until']) > time()) {
        $remainingMinutes = ceil((strtotime($creator['locked_until']) - time()) / 60);
        logLoginAttempt($creator['id'], $email, 'locked');
        return ['error' => "アカウントがロックされています。{$remainingMinutes}分後に再試行してください。"];
    }
    
    // パスワードが設定されていない
    if (empty($creator['password'])) {
        return ['error' => 'パスワードが設定されていません。運営にお問い合わせください。'];
    }
    
    // パスワード検証
    if (!password_verify($password, $creator['password'])) {
        // 試行回数を増やす
        incrementLoginAttempts($db, $creator['id']);
        logLoginAttempt($creator['id'], $email, 'failed');
        return ['error' => 'メールアドレスまたはパスワードが正しくありません'];
    }
    
    // ログイン成功 - 試行回数リセット
    resetLoginAttempts($db, $creator['id']);
    
    // セッションに保存
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['creator_id'] = $creator['id'];
    $_SESSION['creator_name'] = $creator['name'];
    $_SESSION['creator_logged_in'] = true;
    
    // 最終ログイン更新
    try {
        $stmt = $db->prepare("UPDATE creators SET last_login = NOW() WHERE id = ?");
        $stmt->execute([$creator['id']]);
    } catch (PDOException $e) {}
    
    logLoginAttempt($creator['id'], $email, 'success');
    
    return ['success' => true, 'creator' => $creator];
}

/**
 * ログイン試行をログに記録
 */
function logLoginAttempt($creatorId, $email, $status) {
    try {
        $db = getDB();
        $stmt = $db->prepare("INSERT INTO creator_login_logs (creator_id, ip_address, user_agent, status) VALUES (?, ?, ?, ?)");
        $stmt->execute([
            $creatorId,
            $_SERVER['REMOTE_ADDR'] ?? null,
            $_SERVER['HTTP_USER_AGENT'] ?? null,
            $status
        ]);
    } catch (PDOException $e) {
        // テーブルがない場合は無視
    }
}

/**
 * ログイン試行回数を増やす
 */
function incrementLoginAttempts($db, $creatorId) {
    try {
        $stmt = $db->prepare("UPDATE creators SET login_attempts = COALESCE(login_attempts, 0) + 1 WHERE id = ?");
        $stmt->execute([$creatorId]);
        
        // 5回失敗したら30分ロック
        $stmt = $db->prepare("SELECT login_attempts FROM creators WHERE id = ?");
        $stmt->execute([$creatorId]);
        $attempts = $stmt->fetchColumn();
        
        if ($attempts >= 5) {
            $stmt = $db->prepare("UPDATE creators SET locked_until = DATE_ADD(NOW(), INTERVAL 30 MINUTE) WHERE id = ?");
            $stmt->execute([$creatorId]);
        }
    } catch (PDOException $e) {}
}

/**
 * ログイン試行回数をリセット
 */
function resetLoginAttempts($db, $creatorId) {
    try {
        $stmt = $db->prepare("UPDATE creators SET login_attempts = 0, locked_until = NULL WHERE id = ?");
        $stmt->execute([$creatorId]);
    } catch (PDOException $e) {}
}

/**
 * クリエイターログアウト
 */
function logoutCreator() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    unset($_SESSION['creator_id']);
    unset($_SESSION['creator_name']);
    unset($_SESSION['creator_logged_in']);
}

/**
 * クリエイターパスワードをハッシュ化
 */
function hashCreatorPassword($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}

/**
 * パスワードリセットトークンを生成
 */
function generatePasswordResetToken($db, $creatorId) {
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+24 hours'));
    
    $stmt = $db->prepare("UPDATE creators SET password_reset_token = ?, password_reset_expires = ? WHERE id = ?");
    $stmt->execute([$token, $expires, $creatorId]);
    
    return $token;
}

/**
 * パスワードリセットトークンを検証
 */
function validatePasswordResetToken($db, $token) {
    $stmt = $db->prepare("SELECT * FROM creators WHERE password_reset_token = ? AND password_reset_expires > NOW()");
    $stmt->execute([$token]);
    return $stmt->fetch();
}

/**
 * パスワードをリセット
 */
function resetCreatorPassword($db, $creatorId, $newPassword) {
    $hashedPassword = hashCreatorPassword($newPassword);
    $stmt = $db->prepare("UPDATE creators SET password = ?, password_reset_token = NULL, password_reset_expires = NULL, login_attempts = 0, locked_until = NULL WHERE id = ?");
    return $stmt->execute([$hashedPassword, $creatorId]);
}

/**
 * パスワード設定トークンを生成（招待用）
 */
function generatePasswordSetToken($db, $creatorId) {
    $token = bin2hex(random_bytes(32));
    $expires = date('Y-m-d H:i:s', strtotime('+7 days'));
    
    $stmt = $db->prepare("UPDATE creators SET password_set_token = ?, password_set_expires = ? WHERE id = ?");
    $stmt->execute([$token, $expires, $creatorId]);
    
    return $token;
}

/**
 * パスワード設定トークンを検証（招待用）
 */
function validatePasswordSetToken($db, $token) {
    $stmt = $db->prepare("SELECT * FROM creators WHERE password_set_token = ? AND password_set_expires > NOW()");
    $stmt->execute([$token]);
    return $stmt->fetch();
}

/**
 * 初回パスワードを設定
 */
function setCreatorPassword($db, $creatorId, $password) {
    $hashedPassword = hashCreatorPassword($password);
    $stmt = $db->prepare("UPDATE creators SET 
        password = ?, 
        password_set_token = NULL, 
        password_set_expires = NULL,
        login_enabled = 1,
        login_attempts = 0, 
        locked_until = NULL 
        WHERE id = ?");
    return $stmt->execute([$hashedPassword, $creatorId]);
}

/**
 * ログイン許可を切り替え
 */
function toggleCreatorLoginEnabled($db, $creatorId, $enabled) {
    $stmt = $db->prepare("UPDATE creators SET login_enabled = ? WHERE id = ?");
    return $stmt->execute([$enabled ? 1 : 0, $creatorId]);
}
