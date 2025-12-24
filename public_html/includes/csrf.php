<?php
/**
 * CSRF対策ヘルパー関数
 */

/**
 * CSRFトークンを生成してセッションに保存
 * @return string トークン
 */
function generateCsrfToken() {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    
    return $_SESSION['csrf_token'];
}

/**
 * CSRFトークンを検証
 * @param string $token 検証するトークン
 * @return bool 検証結果
 */
function verifyCsrfToken($token) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    
    if (empty($_SESSION['csrf_token'])) {
        return false;
    }
    
    return hash_equals($_SESSION['csrf_token'], $token);
}

/**
 * CSRFトークンの隠しフィールドHTMLを出力
 * @return string HTML
 */
function csrfField() {
    $token = generateCsrfToken();
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars($token, ENT_QUOTES, 'UTF-8') . '">';
}

/**
 * POSTリクエストのCSRFトークンを検証（不正な場合は終了）
 */
function requireCsrfToken() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        return;
    }
    
    $token = $_POST['csrf_token'] ?? '';
    
    if (!verifyCsrfToken($token)) {
        http_response_code(403);
        die('不正なリクエストです。もう一度お試しください。');
    }
}
