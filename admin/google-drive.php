<?php
/**
 * Google Drive連携設定
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/site-settings.php';
require_once '../includes/google-drive.php';
requireAuth();

$db = getDB();
$gdrive = getGoogleDrive($db);
$message = '';
$error = '';

// 設定保存
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_config'])) {
    $clientId = trim($_POST['client_id'] ?? '');
    $clientSecret = trim($_POST['client_secret'] ?? '');
    $redirectUri = trim($_POST['redirect_uri'] ?? '');
    
    // 保存
    $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute(['gdrive_client_id', $clientId]);
    $stmt->execute(['gdrive_client_secret', $clientSecret]);
    $stmt->execute(['gdrive_redirect_uri', $redirectUri]);
    
    $message = '設定を保存しました。';
    $gdrive = getGoogleDrive($db); // リロード
}

// フォルダ構造作成
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['setup_folders'])) {
    try {
        $folders = $gdrive->setupFolderStructure();
        $message = 'フォルダ構造を作成しました。';
    } catch (Exception $e) {
        $error = 'フォルダ作成エラー: ' . $e->getMessage();
    }
}

// 接続解除
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disconnect'])) {
    $gdrive->disconnect();
    $message = 'Google Driveとの接続を解除しました。';
    $gdrive = getGoogleDrive($db); // リロード
}

// 現在の設定を取得
$clientId = $gdrive->getSetting('gdrive_client_id') ?? '';
$clientSecret = $gdrive->getSetting('gdrive_client_secret') ?? '';
$redirectUri = $gdrive->getSetting('gdrive_redirect_uri') ?? '';
$rootFolderId = $gdrive->getSetting('gdrive_root_folder_id') ?? '';

$pageTitle = "Google Drive連携";
include "includes/header.php";
?>
        <div class="flex justify-between items-center mb-8">
            <div>
                <h2 class="text-2xl font-bold text-gray-800">Google Drive連携</h2>
                <p class="text-gray-500">書類の自動バックアップ設定</p>
            </div>
        </div>
        
        <?php if ($message): ?>
        <div class="bg-green-50 border border-green-200 text-green-600 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>
        
        <!-- 接続ステータス -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
            <h3 class="font-bold text-gray-800 mb-4">
                <i class="fab fa-google-drive text-yellow-500 mr-2"></i>接続ステータス
            </h3>
            
            <?php if ($gdrive->isConnected()): ?>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <span class="w-3 h-3 bg-green-500 rounded-full"></span>
                    <span class="text-green-700 font-bold">接続済み</span>
                    <?php if ($rootFolderId): ?>
                    <a href="https://drive.google.com/drive/folders/<?= htmlspecialchars($rootFolderId) ?>" 
                       target="_blank" class="text-blue-500 text-sm hover:underline">
                        <i class="fas fa-external-link-alt mr-1"></i>フォルダを開く
                    </a>
                    <?php endif; ?>
                </div>
                <form method="POST">
                    <button type="submit" name="disconnect" value="1"
                            onclick="return confirm('接続を解除しますか？')"
                            class="px-4 py-2 bg-red-100 text-red-700 rounded-lg hover:bg-red-200 transition">
                        <i class="fas fa-unlink mr-2"></i>接続解除
                    </button>
                </form>
            </div>
            
            <!-- フォルダ構造 -->
            <div class="mt-6 border-t pt-4">
                <h4 class="font-bold text-gray-700 mb-3">保存フォルダ</h4>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-3">
                    <?php
                    $folders = [
                        'receipts' => ['領収書', 'fa-receipt', 'green'],
                        'payment_notices' => ['支払通知書', 'fa-file-invoice-dollar', 'blue'],
                        'invoices' => ['請求書', 'fa-file-invoice', 'purple'],
                        'contracts' => ['契約書', 'fa-file-contract', 'orange'],
                        'withholding' => ['源泉徴収', 'fa-landmark', 'red'],
                        'orders' => ['注文データ', 'fa-shopping-cart', 'gray']
                    ];
                    foreach ($folders as $key => $info):
                        $folderId = $gdrive->getSetting("gdrive_folder_{$key}");
                    ?>
                    <div class="flex items-center gap-2 p-3 bg-gray-50 rounded-lg">
                        <i class="fas <?= $info[1] ?> text-<?= $info[2] ?>-500"></i>
                        <span class="text-sm"><?= $info[0] ?></span>
                        <?php if ($folderId): ?>
                        <i class="fas fa-check text-green-500 text-xs ml-auto"></i>
                        <?php else: ?>
                        <i class="fas fa-times text-red-500 text-xs ml-auto"></i>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>
                </div>
                
                <form method="POST" class="mt-4">
                    <button type="submit" name="setup_folders" value="1"
                            class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition">
                        <i class="fas fa-folder-plus mr-2"></i>フォルダ構造を作成/更新
                    </button>
                </form>
            </div>
            
            <?php elseif ($gdrive->isConfigured()): ?>
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <span class="w-3 h-3 bg-yellow-500 rounded-full"></span>
                    <span class="text-yellow-700 font-bold">未接続（設定済み）</span>
                </div>
                <a href="<?= htmlspecialchars($gdrive->getAuthUrl()) ?>" 
                   class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600 transition">
                    <i class="fab fa-google mr-2"></i>Googleアカウントで接続
                </a>
            </div>
            <?php else: ?>
            <div class="flex items-center gap-3">
                <span class="w-3 h-3 bg-gray-400 rounded-full"></span>
                <span class="text-gray-600">未設定</span>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- API設定 -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
            <h3 class="font-bold text-gray-800 mb-4">
                <i class="fas fa-cog text-gray-500 mr-2"></i>API設定
            </h3>
            
            <form method="POST">
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">クライアントID</label>
                        <input type="text" name="client_id" value="<?= htmlspecialchars($clientId) ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-400 outline-none"
                               placeholder="xxxxx.apps.googleusercontent.com">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">クライアントシークレット</label>
                        <input type="password" name="client_secret" value="<?= htmlspecialchars($clientSecret) ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-400 outline-none"
                               placeholder="GOCSPX-xxxxx">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">リダイレクトURI</label>
                        <input type="url" name="redirect_uri" value="<?= htmlspecialchars($redirectUri) ?>"
                               class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-400 outline-none"
                               placeholder="https://yoursite.com/admin/google-drive-callback.php">
                        <p class="text-xs text-gray-500 mt-1">Google Cloud ConsoleのOAuth設定で同じURIを登録してください</p>
                    </div>
                    
                    <button type="submit" name="save_config" value="1"
                            class="px-6 py-2 bg-green-500 text-white rounded-lg font-bold hover:bg-green-600 transition">
                        <i class="fas fa-save mr-2"></i>設定を保存
                    </button>
                </div>
            </form>
        </div>
        
        <!-- 設定手順 -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <h3 class="font-bold text-gray-800 mb-4">
                <i class="fas fa-info-circle text-blue-500 mr-2"></i>設定手順
            </h3>
            
            <ol class="space-y-3 text-sm text-gray-600">
                <li class="flex gap-3">
                    <span class="w-6 h-6 bg-blue-500 text-white rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0">1</span>
                    <span><a href="https://console.cloud.google.com/" target="_blank" class="text-blue-500 hover:underline">Google Cloud Console</a>でプロジェクトを作成</span>
                </li>
                <li class="flex gap-3">
                    <span class="w-6 h-6 bg-blue-500 text-white rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0">2</span>
                    <span>「APIとサービス」→「ライブラリ」から「Google Drive API」を有効化</span>
                </li>
                <li class="flex gap-3">
                    <span class="w-6 h-6 bg-blue-500 text-white rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0">3</span>
                    <span>「APIとサービス」→「認証情報」→「認証情報を作成」→「OAuthクライアントID」</span>
                </li>
                <li class="flex gap-3">
                    <span class="w-6 h-6 bg-blue-500 text-white rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0">4</span>
                    <span>アプリケーションの種類：「ウェブアプリケーション」を選択</span>
                </li>
                <li class="flex gap-3">
                    <span class="w-6 h-6 bg-blue-500 text-white rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0">5</span>
                    <span>「承認済みのリダイレクトURI」に上記のリダイレクトURIを追加</span>
                </li>
                <li class="flex gap-3">
                    <span class="w-6 h-6 bg-blue-500 text-white rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0">6</span>
                    <span>クライアントIDとクライアントシークレットをこのページに入力</span>
                </li>
                <li class="flex gap-3">
                    <span class="w-6 h-6 bg-blue-500 text-white rounded-full flex items-center justify-center text-xs font-bold flex-shrink-0">7</span>
                    <span>「Googleアカウントで接続」ボタンをクリックして認証</span>
                </li>
            </ol>
        </div>
    </main>
<?php include "includes/footer.php"; ?>
