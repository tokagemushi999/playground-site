<?php
/**
 * Google Drive API連携ヘルパー
 * 
 * 設定方法：
 * 1. Google Cloud Consoleでプロジェクト作成
 * 2. Google Drive APIを有効化
 * 3. OAuth 2.0クライアントIDを作成（Webアプリケーション）
 * 4. リダイレクトURIを設定: https://yoursite.com/admin/google-drive-callback.php
 * 5. クライアントID/シークレットを管理画面で設定
 */

class GoogleDrive {
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $accessToken;
    private $refreshToken;
    private $db;
    
    public function __construct($db) {
        $this->db = $db;
        $this->loadConfig();
    }
    
    /**
     * 設定を読み込む
     */
    private function loadConfig() {
        $stmt = $this->db->query("SELECT setting_key, setting_value FROM site_settings WHERE setting_key LIKE 'gdrive_%'");
        $settings = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $settings[$row['setting_key']] = $row['setting_value'];
        }
        
        $this->clientId = $settings['gdrive_client_id'] ?? '';
        $this->clientSecret = $settings['gdrive_client_secret'] ?? '';
        $this->redirectUri = $settings['gdrive_redirect_uri'] ?? '';
        $this->accessToken = $settings['gdrive_access_token'] ?? '';
        $this->refreshToken = $settings['gdrive_refresh_token'] ?? '';
    }
    
    /**
     * 設定が完了しているか確認
     */
    public function isConfigured(): bool {
        return !empty($this->clientId) && !empty($this->clientSecret) && !empty($this->redirectUri);
    }
    
    /**
     * 接続済みか確認
     */
    public function isConnected(): bool {
        return !empty($this->accessToken) && !empty($this->refreshToken);
    }
    
    /**
     * OAuth認証URLを取得
     */
    public function getAuthUrl(): string {
        $params = [
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/drive.file',
            'access_type' => 'offline',
            'prompt' => 'consent'
        ];
        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }
    
    /**
     * 認証コードからアクセストークンを取得
     */
    public function handleCallback(string $code): bool {
        $response = $this->httpPost('https://oauth2.googleapis.com/token', [
            'code' => $code,
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri' => $this->redirectUri,
            'grant_type' => 'authorization_code'
        ]);
        
        if (isset($response['access_token'])) {
            $this->accessToken = $response['access_token'];
            $this->refreshToken = $response['refresh_token'] ?? $this->refreshToken;
            
            // DBに保存
            $this->saveSetting('gdrive_access_token', $this->accessToken);
            if (isset($response['refresh_token'])) {
                $this->saveSetting('gdrive_refresh_token', $response['refresh_token']);
            }
            
            return true;
        }
        
        return false;
    }
    
    /**
     * アクセストークンをリフレッシュ
     */
    private function refreshAccessToken(): bool {
        if (empty($this->refreshToken)) {
            return false;
        }
        
        $response = $this->httpPost('https://oauth2.googleapis.com/token', [
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $this->refreshToken,
            'grant_type' => 'refresh_token'
        ]);
        
        if (isset($response['access_token'])) {
            $this->accessToken = $response['access_token'];
            $this->saveSetting('gdrive_access_token', $this->accessToken);
            return true;
        }
        
        return false;
    }
    
    /**
     * フォルダを作成（存在しなければ）
     */
    public function createFolder(string $name, string $parentId = null): ?string {
        // 既存フォルダを検索
        $existingId = $this->findFolder($name, $parentId);
        if ($existingId) {
            return $existingId;
        }
        
        $metadata = [
            'name' => $name,
            'mimeType' => 'application/vnd.google-apps.folder'
        ];
        
        if ($parentId) {
            $metadata['parents'] = [$parentId];
        }
        
        $response = $this->apiRequest('POST', 'https://www.googleapis.com/drive/v3/files', $metadata);
        
        return $response['id'] ?? null;
    }
    
    /**
     * フォルダを検索
     */
    public function findFolder(string $name, string $parentId = null): ?string {
        $query = "name='" . addslashes($name) . "' and mimeType='application/vnd.google-apps.folder' and trashed=false";
        if ($parentId) {
            $query .= " and '" . $parentId . "' in parents";
        }
        
        $response = $this->apiRequest('GET', 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
            'q' => $query,
            'fields' => 'files(id,name)'
        ]));
        
        if (!empty($response['files'])) {
            return $response['files'][0]['id'];
        }
        
        return null;
    }
    
    /**
     * ファイルをアップロード
     */
    public function uploadFile(string $filePath, string $fileName, string $folderId, string $mimeType = 'application/pdf'): ?array {
        if (!file_exists($filePath)) {
            return null;
        }
        
        $fileContent = file_get_contents($filePath);
        
        // メタデータ
        $metadata = json_encode([
            'name' => $fileName,
            'parents' => [$folderId]
        ]);
        
        // マルチパートリクエスト
        $boundary = '-------' . uniqid();
        
        $body = "--{$boundary}\r\n";
        $body .= "Content-Type: application/json; charset=UTF-8\r\n\r\n";
        $body .= $metadata . "\r\n";
        $body .= "--{$boundary}\r\n";
        $body .= "Content-Type: {$mimeType}\r\n\r\n";
        $body .= $fileContent . "\r\n";
        $body .= "--{$boundary}--";
        
        $response = $this->apiRequestRaw(
            'POST',
            'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart',
            $body,
            "multipart/related; boundary={$boundary}"
        );
        
        if (isset($response['id'])) {
            return [
                'id' => $response['id'],
                'name' => $response['name'] ?? $fileName,
                'webViewLink' => $response['webViewLink'] ?? null
            ];
        }
        
        return null;
    }
    
    /**
     * HTMLコンテンツを直接アップロード
     */
    public function uploadHtmlContent(string $htmlContent, string $fileName, string $folderId): ?array {
        // 一時ファイルに保存
        $tempFile = tempnam(sys_get_temp_dir(), 'gdrive_');
        file_put_contents($tempFile, $htmlContent);
        
        $result = $this->uploadFile($tempFile, $fileName, $folderId, 'text/html');
        
        unlink($tempFile);
        
        return $result;
    }
    
    /**
     * ドキュメントコンテンツをアップロード（HTMLとして保存）
     * ※mpdfはPHP8で警告が出るため無効化
     */
    public function uploadPdfContent(string $content, string $fileName, string $folderId): ?array {
        // HTMLファイル名に変換
        $htmlFileName = preg_replace('/\.pdf$/i', '.html', $fileName);
        if ($htmlFileName === $fileName) {
            $htmlFileName .= '.html';
        }
        return $this->uploadHtmlContent($content, $htmlFileName, $folderId);
    }
    
    /**
     * ルートフォルダ構造を作成
     */
    public function setupFolderStructure(): array {
        $rootFolderId = $this->getSetting('gdrive_root_folder_id');
        
        // ルートフォルダがなければ作成
        if (!$rootFolderId) {
            $rootFolderId = $this->createFolder('ショップ書類');
            $this->saveSetting('gdrive_root_folder_id', $rootFolderId);
        }
        
        // サブフォルダを作成
        $folders = [
            'receipts' => '領収書',
            'payment_notices' => '支払通知書',
            'invoices' => '請求書',
            'contracts' => '契約書',
            'withholding' => '源泉徴収',
            'orders' => '注文データ'
        ];
        
        $folderIds = ['root' => $rootFolderId];
        
        foreach ($folders as $key => $name) {
            $folderId = $this->createFolder($name, $rootFolderId);
            $folderIds[$key] = $folderId;
            $this->saveSetting("gdrive_folder_{$key}", $folderId);
        }
        
        return $folderIds;
    }
    
    /**
     * 年月サブフォルダを取得/作成
     */
    public function getMonthlyFolder(string $type, int $year, int $month): ?string {
        $parentFolderId = $this->getSetting("gdrive_folder_{$type}");
        if (!$parentFolderId) {
            return null;
        }
        
        // 年フォルダ
        $yearFolderId = $this->createFolder((string)$year, $parentFolderId);
        
        // 月フォルダ
        $monthName = sprintf('%02d月', $month);
        $monthFolderId = $this->createFolder($monthName, $yearFolderId);
        
        return $monthFolderId;
    }
    
    /**
     * 接続を解除
     */
    public function disconnect(): void {
        $this->saveSetting('gdrive_access_token', '');
        $this->saveSetting('gdrive_refresh_token', '');
        $this->saveSetting('gdrive_root_folder_id', '');
        
        // フォルダIDもクリア
        $folders = ['receipts', 'payment_notices', 'invoices', 'contracts', 'withholding', 'orders'];
        foreach ($folders as $folder) {
            $this->saveSetting("gdrive_folder_{$folder}", '');
        }
    }
    
    /**
     * API リクエスト（JSON）
     */
    private function apiRequest(string $method, string $url, array $data = null): array {
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: application/json'
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        
        if ($method === 'POST') {
            curl_setopt($ch, CURLOPT_POST, true);
            if ($data) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
            }
        }
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // トークン期限切れの場合はリフレッシュして再試行
        if ($httpCode === 401) {
            if ($this->refreshAccessToken()) {
                return $this->apiRequest($method, $url, $data);
            }
        }
        
        return json_decode($response, true) ?: [];
    }
    
    /**
     * API リクエスト（Raw）
     */
    private function apiRequestRaw(string $method, string $url, string $body, string $contentType): array {
        $headers = [
            'Authorization: Bearer ' . $this->accessToken,
            'Content-Type: ' . $contentType,
            'Content-Length: ' . strlen($body)
        ];
        
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        // トークン期限切れの場合はリフレッシュして再試行
        if ($httpCode === 401) {
            if ($this->refreshAccessToken()) {
                return $this->apiRequestRaw($method, $url, $body, $contentType);
            }
        }
        
        return json_decode($response, true) ?: [];
    }
    
    /**
     * HTTP POST
     */
    private function httpPost(string $url, array $data): array {
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
        
        $response = curl_exec($ch);
        curl_close($ch);
        
        return json_decode($response, true) ?: [];
    }
    
    /**
     * 設定を保存
     */
    private function saveSetting(string $key, string $value): void {
        $stmt = $this->db->prepare("
            INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $stmt->execute([$key, $value]);
    }
    
    /**
     * 設定を取得
     */
    public function getSetting(string $key): ?string {
        $stmt = $this->db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row['setting_value'] ?? null;
    }
}

/**
 * Google Drive インスタンスを取得
 */
function getGoogleDrive($db): GoogleDrive {
    return new GoogleDrive($db);
}
