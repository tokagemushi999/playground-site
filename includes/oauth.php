<?php
/**
 * OAuth認証ヘルパー
 * Google / LINE / Amazon ログイン対応（cURL使用版）
 */

/**
 * OAuth設定を取得
 */
function getOAuthConfig($db) {
    return [
        'google' => [
            'enabled' => getSiteSetting($db, 'oauth_google_enabled', '0') === '1',
            'client_id' => getSiteSetting($db, 'oauth_google_client_id', ''),
            'client_secret' => getSiteSetting($db, 'oauth_google_client_secret', ''),
        ],
        'line' => [
            'enabled' => getSiteSetting($db, 'oauth_line_enabled', '0') === '1',
            'channel_id' => getSiteSetting($db, 'oauth_line_channel_id', ''),
            'channel_secret' => getSiteSetting($db, 'oauth_line_channel_secret', ''),
        ],
        'amazon' => [
            'enabled' => getSiteSetting($db, 'oauth_amazon_enabled', '0') === '1',
            'client_id' => getSiteSetting($db, 'oauth_amazon_client_id', ''),
            'client_secret' => getSiteSetting($db, 'oauth_amazon_client_secret', ''),
        ],
    ];
}

/**
 * Google OAuth URL生成
 */
function getGoogleAuthUrl($db) {
    $config = getOAuthConfig($db);
    if (!$config['google']['enabled'] || empty($config['google']['client_id'])) {
        return null;
    }
    
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $_SESSION['oauth_provider'] = 'google';
    
    // Cookieにも保存（アプリ内ブラウザ対策）
    setcookie('oauth_state', $state, time() + 600, '/', '', true, true);
    setcookie('oauth_provider', 'google', time() + 600, '/', '', true, true);
    
    $redirectUri = 'https://' . $_SERVER['HTTP_HOST'] . '/store/oauth-callback.php';
    
    $params = [
        'client_id' => $config['google']['client_id'],
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'openid email profile',
        'state' => $state,
        'access_type' => 'online',
        'prompt' => 'select_account',
    ];
    
    return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
}

/**
 * LINE OAuth URL生成
 */
function getLineAuthUrl($db) {
    $config = getOAuthConfig($db);
    if (!$config['line']['enabled'] || empty($config['line']['channel_id'])) {
        return null;
    }
    
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $_SESSION['oauth_provider'] = 'line';
    
    // Cookieにも保存（アプリ内ブラウザ対策）
    setcookie('oauth_state', $state, time() + 600, '/', '', true, true);
    setcookie('oauth_provider', 'line', time() + 600, '/', '', true, true);
    
    $redirectUri = 'https://' . $_SERVER['HTTP_HOST'] . '/store/oauth-callback.php';
    
    $params = [
        'response_type' => 'code',
        'client_id' => $config['line']['channel_id'],
        'redirect_uri' => $redirectUri,
        'state' => $state,
        'scope' => 'profile openid email',
    ];
    
    return 'https://access.line.me/oauth2/v2.1/authorize?' . http_build_query($params);
}

/**
 * cURLでPOSTリクエスト
 */
function oauthPostRequest($url, $data) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query($data),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception('接続エラー: ' . $error);
    }
    
    $result = json_decode($response, true);
    
    if ($httpCode >= 400) {
        $errorMsg = $result['error_description'] ?? $result['error'] ?? '不明なエラー';
        throw new Exception('APIエラー: ' . $errorMsg);
    }
    
    return $result;
}

/**
 * cURLでGETリクエスト
 */
function oauthGetRequest($url, $accessToken) {
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $accessToken],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        throw new Exception('接続エラー: ' . $error);
    }
    
    if ($httpCode >= 400) {
        throw new Exception('ユーザー情報取得エラー (HTTP ' . $httpCode . ')');
    }
    
    return json_decode($response, true);
}

/**
 * Googleトークン取得
 */
function getGoogleTokens($code, $db) {
    $config = getOAuthConfig($db);
    $redirectUri = 'https://' . $_SERVER['HTTP_HOST'] . '/store/oauth-callback.php';
    
    return oauthPostRequest('https://oauth2.googleapis.com/token', [
        'code' => $code,
        'client_id' => $config['google']['client_id'],
        'client_secret' => $config['google']['client_secret'],
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code',
    ]);
}

/**
 * Googleユーザー情報取得
 */
function getGoogleUserInfo($accessToken) {
    return oauthGetRequest('https://www.googleapis.com/oauth2/v2/userinfo', $accessToken);
}

/**
 * LINEトークン取得
 */
function getLineTokens($code, $db) {
    $config = getOAuthConfig($db);
    $redirectUri = 'https://' . $_SERVER['HTTP_HOST'] . '/store/oauth-callback.php';
    
    return oauthPostRequest('https://api.line.me/oauth2/v2.1/token', [
        'grant_type' => 'authorization_code',
        'code' => $code,
        'redirect_uri' => $redirectUri,
        'client_id' => $config['line']['channel_id'],
        'client_secret' => $config['line']['channel_secret'],
    ]);
}

/**
 * LINEユーザー情報取得
 */
function getLineUserInfo($accessToken) {
    return oauthGetRequest('https://api.line.me/v2/profile', $accessToken);
}

/**
 * LINEのIDトークンからメール取得
 */
function getLineEmail($idToken, $db) {
    $parts = explode('.', $idToken);
    if (count($parts) !== 3) {
        return null;
    }
    
    $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
    return $payload['email'] ?? null;
}

/**
 * Amazon OAuth URL生成
 */
function getAmazonAuthUrl($db) {
    $config = getOAuthConfig($db);
    if (!$config['amazon']['enabled'] || empty($config['amazon']['client_id'])) {
        return null;
    }
    
    $state = bin2hex(random_bytes(16));
    $_SESSION['oauth_state'] = $state;
    $_SESSION['oauth_provider'] = 'amazon';
    
    // Cookieにも保存（アプリ内ブラウザ対策）
    setcookie('oauth_state', $state, time() + 600, '/', '', true, true);
    setcookie('oauth_provider', 'amazon', time() + 600, '/', '', true, true);
    
    $redirectUri = 'https://' . $_SERVER['HTTP_HOST'] . '/store/oauth-callback.php';
    
    $params = [
        'client_id' => $config['amazon']['client_id'],
        'redirect_uri' => $redirectUri,
        'response_type' => 'code',
        'scope' => 'profile',
        'state' => $state,
    ];
    
    return 'https://www.amazon.co.jp/ap/oa?' . http_build_query($params);
}

/**
 * Amazonトークン取得
 */
function getAmazonTokens($code, $db) {
    $config = getOAuthConfig($db);
    $redirectUri = 'https://' . $_SERVER['HTTP_HOST'] . '/store/oauth-callback.php';
    
    return oauthPostRequest('https://api.amazon.co.jp/auth/o2/token', [
        'code' => $code,
        'client_id' => $config['amazon']['client_id'],
        'client_secret' => $config['amazon']['client_secret'],
        'redirect_uri' => $redirectUri,
        'grant_type' => 'authorization_code',
    ]);
}

/**
 * Amazonユーザー情報取得
 */
function getAmazonUserInfo($accessToken) {
    return oauthGetRequest('https://api.amazon.co.jp/user/profile', $accessToken);
}

/**
 * member_oauthテーブルを作成（存在しない場合）
 */
function ensureOAuthTable($db) {
    try {
        $db->query("SELECT 1 FROM member_oauth LIMIT 1");
    } catch (PDOException $e) {
        $db->exec("
            CREATE TABLE member_oauth (
                id INT AUTO_INCREMENT PRIMARY KEY,
                member_id INT NOT NULL,
                provider VARCHAR(50) NOT NULL,
                provider_user_id VARCHAR(255) NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uk_provider_user (provider, provider_user_id),
                INDEX idx_member (member_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
    }
}

/**
 * OAuthユーザーでログインまたは新規登録
 */
function loginOrRegisterOAuthUser($provider, $providerUserId, $email, $name, $picture = null) {
    $db = getDB();
    
    // テーブル存在確認
    ensureOAuthTable($db);
    
    // 既存のOAuth連携を確認
    $stmt = $db->prepare("
        SELECT m.* FROM members m 
        JOIN member_oauth mo ON m.id = mo.member_id 
        WHERE mo.provider = ? AND mo.provider_user_id = ?
    ");
    $stmt->execute([$provider, $providerUserId]);
    $member = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($member) {
        // 既存ユーザーでログイン
        $_SESSION['member_id'] = $member['id'];
        
        if (function_exists('mergeGuestCart')) {
            mergeGuestCart($member['id']);
        }
        
        return ['success' => true, 'member' => $member, 'is_new' => false];
    }
    
    // メールアドレスで既存ユーザーを検索
    if ($email) {
        $stmt = $db->prepare("SELECT * FROM members WHERE email = ?");
        $stmt->execute([$email]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($member) {
            // 既存アカウントにOAuth連携を追加
            $stmt = $db->prepare("INSERT INTO member_oauth (member_id, provider, provider_user_id) VALUES (?, ?, ?)");
            $stmt->execute([$member['id'], $provider, $providerUserId]);
            
            $_SESSION['member_id'] = $member['id'];
            
            if (function_exists('mergeGuestCart')) {
                mergeGuestCart($member['id']);
            }
            
            return ['success' => true, 'member' => $member, 'is_new' => false];
        }
    }
    
    // 新規ユーザー登録
    // メールがない場合はダミーメールを生成（LINE等でメール取得できない場合）
    if (!$email) {
        $email = $provider . '_' . substr($providerUserId, 0, 20) . '@oauth.local';
    }
    
    try {
        $db->beginTransaction();
        
        $stmt = $db->prepare("
            INSERT INTO members (email, name, nickname, password_hash, status, email_verified_at, created_at)
            VALUES (?, ?, ?, '', 'active', NOW(), NOW())
        ");
        $stmt->execute([$email, $name, $name]);
        $memberId = $db->lastInsertId();
        
        $stmt = $db->prepare("INSERT INTO member_oauth (member_id, provider, provider_user_id) VALUES (?, ?, ?)");
        $stmt->execute([$memberId, $provider, $providerUserId]);
        
        $db->commit();
        
        $_SESSION['member_id'] = $memberId;
        
        if (function_exists('mergeGuestCart')) {
            mergeGuestCart($memberId);
        }
        
        $stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
        $stmt->execute([$memberId]);
        $member = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return ['success' => true, 'member' => $member, 'is_new' => true];
        
    } catch (Exception $e) {
        $db->rollBack();
        return ['success' => false, 'error' => '登録に失敗しました: ' . $e->getMessage()];
    }
}
