<?php
/**
 * 認証処理（セキュリティ強化版）
 * - アカウントロック機能（10回失敗でロック）
 * - 二要素認証（TOTP）対応
 * - ログイン履歴記録
 */

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('ADMIN_EMAIL', 'nitta@tokagemushi.jp');
define('ADMIN_PASSWORD_HASH', '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi');

// ロック設定
define('MAX_LOGIN_ATTEMPTS', 10);
define('LOCKOUT_DURATION', 1800); // 30分

/**
 * ログイン試行回数を取得
 */
function getLoginAttempts($email) {
    $db = getDB();
    
    // テーブルが存在するか確認
    try {
        $stmt = $db->prepare("SELECT attempts, locked_until FROM admin_login_attempts WHERE email = ?");
        $stmt->execute([$email]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        // テーブルがなければ作成
        createSecurityTables();
        return null;
    }
}

/**
 * ログイン試行を記録
 */
function recordLoginAttempt($email, $success) {
    $db = getDB();
    
    if ($success) {
        // 成功時はリセット
        $stmt = $db->prepare("DELETE FROM admin_login_attempts WHERE email = ?");
        $stmt->execute([$email]);
    } else {
        // 失敗時はカウントアップ
        $attempt = getLoginAttempts($email);
        
        if ($attempt) {
            $newAttempts = $attempt['attempts'] + 1;
            $lockedUntil = null;
            
            if ($newAttempts >= MAX_LOGIN_ATTEMPTS) {
                $lockedUntil = date('Y-m-d H:i:s', time() + LOCKOUT_DURATION);
            }
            
            $stmt = $db->prepare("UPDATE admin_login_attempts SET attempts = ?, locked_until = ?, updated_at = NOW() WHERE email = ?");
            $stmt->execute([$newAttempts, $lockedUntil, $email]);
        } else {
            $stmt = $db->prepare("INSERT INTO admin_login_attempts (email, attempts, created_at, updated_at) VALUES (?, 1, NOW(), NOW())");
            $stmt->execute([$email]);
        }
    }
}

/**
 * アカウントがロックされているか確認
 */
function isAccountLocked($email) {
    $attempt = getLoginAttempts($email);
    
    if (!$attempt) {
        return false;
    }
    
    if ($attempt['locked_until'] && strtotime($attempt['locked_until']) > time()) {
        return true;
    }
    
    // ロック期間が過ぎていればリセット
    if ($attempt['locked_until'] && strtotime($attempt['locked_until']) <= time()) {
        $db = getDB();
        $stmt = $db->prepare("DELETE FROM admin_login_attempts WHERE email = ?");
        $stmt->execute([$email]);
        return false;
    }
    
    return false;
}

/**
 * 残りロック時間を取得（秒）
 */
function getRemainingLockTime($email) {
    $attempt = getLoginAttempts($email);
    
    if (!$attempt || !$attempt['locked_until']) {
        return 0;
    }
    
    $remaining = strtotime($attempt['locked_until']) - time();
    return max(0, $remaining);
}

/**
 * ログイン処理（セキュリティ強化版）
 */
function login($email, $password, $totpCode = null) {
    $db = getDB();
    
    // アカウントロックチェック
    if (isAccountLocked($email)) {
        return ['success' => false, 'error' => 'locked', 'remaining' => getRemainingLockTime($email)];
    }
    
    // 管理者をDBから取得
    $stmt = $db->prepare("SELECT * FROM admins WHERE email = ?");
    $stmt->execute([$email]);
    $admin = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$admin || !password_verify($password, $admin['password'])) {
        recordLoginAttempt($email, false);
        $attempt = getLoginAttempts($email);
        $remaining = MAX_LOGIN_ATTEMPTS - ($attempt ? $attempt['attempts'] : 0);
        return ['success' => false, 'error' => 'invalid', 'attempts_remaining' => $remaining];
    }
    
    // 二要素認証チェック
    if (!empty($admin['totp_secret'])) {
        if (empty($totpCode)) {
            return ['success' => false, 'error' => 'totp_required', 'admin_id' => $admin['id']];
        }
        
        if (!verifyTOTP($admin['totp_secret'], $totpCode)) {
            recordLoginAttempt($email, false);
            return ['success' => false, 'error' => 'totp_invalid'];
        }
    }
    
    // ログイン成功
    recordLoginAttempt($email, true);
    
    $_SESSION['admin_id'] = $admin['id'];
    $_SESSION['admin_email'] = $admin['email'];
    $_SESSION['admin_name'] = $admin['name'];
    $_SESSION['logged_in'] = true;
    $_SESSION['login_time'] = time();
    $_SESSION['ip_address'] = $_SERVER['REMOTE_ADDR'];
    
    // ログイン履歴を記録
    recordLoginHistory($admin['id'], true);
    
    // 最終ログイン日時を更新
    $stmt = $db->prepare("UPDATE admins SET last_login_at = NOW() WHERE id = ?");
    $stmt->execute([$admin['id']]);
    
    return ['success' => true];
}

/**
 * ログイン履歴を記録
 */
function recordLoginHistory($adminId, $success) {
    $db = getDB();
    
    try {
        $stmt = $db->prepare("INSERT INTO admin_login_history (admin_id, ip_address, user_agent, success, created_at) VALUES (?, ?, ?, ?, NOW())");
        $stmt->execute([
            $adminId,
            $_SERVER['REMOTE_ADDR'] ?? '',
            $_SERVER['HTTP_USER_AGENT'] ?? '',
            $success ? 1 : 0
        ]);
    } catch (PDOException $e) {
        // テーブルがない場合は無視
    }
}

/**
 * TOTP（Time-based One-Time Password）検証
 */
function verifyTOTP($secret, $code, $window = 1) {
    $timestamp = floor(time() / 30);
    
    for ($i = -$window; $i <= $window; $i++) {
        $calculatedCode = generateTOTP($secret, $timestamp + $i);
        if (hash_equals($calculatedCode, $code)) {
            return true;
        }
    }
    
    return false;
}

/**
 * TOTPコード生成
 */
function generateTOTP($secret, $timestamp) {
    $secretKey = base32Decode($secret);
    $time = pack('N*', 0) . pack('N*', $timestamp);
    $hash = hash_hmac('sha1', $time, $secretKey, true);
    $offset = ord(substr($hash, -1)) & 0x0F;
    $code = (
        ((ord($hash[$offset]) & 0x7F) << 24) |
        ((ord($hash[$offset + 1]) & 0xFF) << 16) |
        ((ord($hash[$offset + 2]) & 0xFF) << 8) |
        (ord($hash[$offset + 3]) & 0xFF)
    ) % 1000000;
    
    return str_pad($code, 6, '0', STR_PAD_LEFT);
}

/**
 * Base32デコード
 */
function base32Decode($input) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $output = '';
    $v = 0;
    $vbits = 0;
    
    $input = strtoupper($input);
    $input = str_replace('=', '', $input);
    
    for ($i = 0; $i < strlen($input); $i++) {
        $v = ($v << 5) | strpos($alphabet, $input[$i]);
        $vbits += 5;
        
        while ($vbits >= 8) {
            $vbits -= 8;
            $output .= chr(($v >> $vbits) & 0xFF);
        }
    }
    
    return $output;
}

/**
 * TOTPシークレット生成
 */
function generateTOTPSecret($length = 16) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    
    for ($i = 0; $i < $length; $i++) {
        $secret .= $alphabet[random_int(0, 31)];
    }
    
    return $secret;
}

/**
 * QRコード用のOTPAuthURL生成
 */
function getTOTPUrl($secret, $email, $issuer = 'ぷれぐら管理画面') {
    return 'otpauth://totp/' . urlencode($issuer) . ':' . urlencode($email) . '?secret=' . $secret . '&issuer=' . urlencode($issuer);
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
    // セッションタイムアウト（8時間）
    if (isset($_SESSION['login_time']) && time() - $_SESSION['login_time'] > 28800) {
        session_destroy();
        return false;
    }
    
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
        $hashedPassword = password_hash('Tme-1-23', PASSWORD_DEFAULT);
        $stmt = $db->prepare("INSERT INTO admins (email, password, name) VALUES (?, ?, ?)");
        $stmt->execute(['nitta@tokagemushi.jp', $hashedPassword, '管理者']);
        return true;
    }
    
    return false;
}

/**
 * セキュリティ用テーブル作成
 */
function createSecurityTables() {
    $db = getDB();
    
    // ログイン試行テーブル
    $db->exec("CREATE TABLE IF NOT EXISTS admin_login_attempts (
        id INT AUTO_INCREMENT PRIMARY KEY,
        email VARCHAR(255) NOT NULL,
        attempts INT DEFAULT 0,
        locked_until DATETIME DEFAULT NULL,
        created_at DATETIME,
        updated_at DATETIME,
        UNIQUE KEY (email)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // ログイン履歴テーブル
    $db->exec("CREATE TABLE IF NOT EXISTS admin_login_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        admin_id INT NOT NULL,
        ip_address VARCHAR(45),
        user_agent TEXT,
        success TINYINT(1) DEFAULT 0,
        created_at DATETIME,
        INDEX (admin_id),
        INDEX (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // adminsテーブルにカラム追加（存在しない場合）
    try {
        $db->exec("ALTER TABLE admins ADD COLUMN totp_secret VARCHAR(32) DEFAULT NULL");
    } catch (PDOException $e) {
        // 既に存在する場合は無視
    }
    
    try {
        $db->exec("ALTER TABLE admins ADD COLUMN last_login_at DATETIME DEFAULT NULL");
    } catch (PDOException $e) {
        // 既に存在する場合は無視
    }
}
