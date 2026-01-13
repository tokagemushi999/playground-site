<?php
/**
 * 二要素認証設定ページ
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/site-settings.php';
requireAuth();

$db = getDB();
$message = '';
$error = '';
$showQR = false;
$secret = '';
$qrUrl = '';

// 現在の管理者情報を取得
$stmt = $db->prepare("SELECT * FROM admins WHERE id = ?");
$stmt->execute([$_SESSION['admin_id']]);
$admin = $stmt->fetch(PDO::FETCH_ASSOC);

$totpEnabled = !empty($admin['totp_secret']);

// 二要素認証を有効化
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['enable_totp'])) {
    $secret = $_POST['secret'];
    $code = $_POST['verify_code'];
    
    // コードを検証
    if (verifyTOTP($secret, $code)) {
        $stmt = $db->prepare("UPDATE admins SET totp_secret = ? WHERE id = ?");
        $stmt->execute([$secret, $_SESSION['admin_id']]);
        $message = '二要素認証を有効にしました';
        $totpEnabled = true;
    } else {
        $error = '認証コードが正しくありません。もう一度お試しください。';
        $showQR = true;
        $qrUrl = getTOTPUrl($secret, $admin['email']);
    }
}

// 二要素認証を無効化
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['disable_totp'])) {
    $code = $_POST['verify_code'];
    
    if (verifyTOTP($admin['totp_secret'], $code)) {
        $stmt = $db->prepare("UPDATE admins SET totp_secret = NULL WHERE id = ?");
        $stmt->execute([$_SESSION['admin_id']]);
        $message = '二要素認証を無効にしました';
        $totpEnabled = false;
    } else {
        $error = '認証コードが正しくありません。';
    }
}

// QRコード表示リクエスト
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['show_qr'])) {
    $secret = generateTOTPSecret();
    $qrUrl = getTOTPUrl($secret, $admin['email']);
    $showQR = true;
}

$pageTitle = '二要素認証設定';
$extraHead = '<script src="https://cdnjs.cloudflare.com/ajax/libs/qrcodejs/1.0.0/qrcode.min.js"></script>';
include 'includes/header.php';
?>
        <h1 class="text-2xl font-bold text-gray-800 mb-6">
            <i class="fas fa-shield-alt text-green-500 mr-2"></i>二要素認証設定
        </h1>
        
        <?php if ($message): ?>
        <div class="bg-green-100 border border-green-300 text-green-700 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
            
        <?php if ($error): ?>
        <div class="bg-red-100 border border-red-300 text-red-700 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>
            
        <div class="max-w-2xl">
            <!-- 現在のステータス -->
            <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
                <div class="flex items-center justify-between">
                    <div>
                        <h2 class="text-lg font-bold text-gray-800 mb-1">二要素認証</h2>
                        <p class="text-sm text-gray-500">
                            ログイン時に認証アプリのコードが必要になります
                        </p>
                    </div>
                    <div>
                        <?php if ($totpEnabled): ?>
                        <span class="inline-flex items-center gap-1 px-4 py-2 bg-green-100 text-green-700 rounded-full font-bold">
                            <i class="fas fa-check-circle"></i> 有効
                        </span>
                        <?php else: ?>
                        <span class="inline-flex items-center gap-1 px-4 py-2 bg-gray-100 text-gray-500 rounded-full font-bold">
                            <i class="fas fa-times-circle"></i> 無効
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            
            <?php if ($showQR): ?>
            <!-- QRコード表示・設定 -->
            <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4">
                    <i class="fas fa-qrcode mr-2"></i>認証アプリを設定
                </h3>
                
                <div class="space-y-6">
                    <div class="text-center">
                        <p class="text-gray-600 mb-4">
                            Google Authenticator または Microsoft Authenticator で<br>
                            以下のQRコードをスキャンしてください
                        </p>
                        
                        <!-- QRコード -->
                        <div class="inline-block p-4 bg-white border-2 border-gray-200 rounded-lg">
                            <div id="qrcode" class="w-48 h-48 flex items-center justify-center"></div>
                        </div>
                        
                        <div class="mt-4 p-3 bg-gray-100 rounded-lg">
                            <p class="text-xs text-gray-500 mb-1">手動入力用シークレットキー</p>
                            <code class="text-sm font-mono font-bold text-gray-800"><?= htmlspecialchars($secret) ?></code>
                        </div>
                    </div>
                    
                    <form method="POST" class="space-y-4">
                        <input type="hidden" name="secret" value="<?= htmlspecialchars($secret) ?>">
                        
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">
                                認証アプリに表示されている6桁のコードを入力
                            </label>
                            <input type="text" name="verify_code" required
                                pattern="[0-9]{6}" maxlength="6" inputmode="numeric"
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-400 outline-none text-center text-xl tracking-widest"
                                placeholder="000000">
                        </div>
                        
                        <button type="submit" name="enable_totp" 
                            class="w-full bg-green-500 hover:bg-green-600 text-white font-bold py-3 px-4 rounded-lg transition">
                            <i class="fas fa-check mr-2"></i>二要素認証を有効にする
                        </button>
                    </form>
                </div>
            </div>
            
            <?php elseif ($totpEnabled): ?>
            <!-- 無効化フォーム -->
            <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4">
                    <i class="fas fa-times-circle text-red-500 mr-2"></i>二要素認証を無効にする
                </h3>
                
                <div class="bg-yellow-50 border border-yellow-200 text-yellow-700 px-4 py-3 rounded-lg mb-4 text-sm">
                    <i class="fas fa-exclamation-triangle mr-2"></i>
                    二要素認証を無効にすると、アカウントのセキュリティが低下します。
                </div>
                
                <form method="POST" class="space-y-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">
                            認証コードを入力して確認
                        </label>
                        <input type="text" name="verify_code" required
                            pattern="[0-9]{6}" maxlength="6" inputmode="numeric"
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-red-400 outline-none text-center text-xl tracking-widest"
                            placeholder="000000">
                    </div>
                    
                    <button type="submit" name="disable_totp" 
                        class="w-full bg-red-500 hover:bg-red-600 text-white font-bold py-3 px-4 rounded-lg transition"
                        onclick="return confirm('本当に二要素認証を無効にしますか？')">
                        <i class="fas fa-times mr-2"></i>二要素認証を無効にする
                    </button>
                </form>
            </div>
            
            <?php else: ?>
            <!-- 有効化ボタン -->
            <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4">
                    <i class="fas fa-plus-circle text-green-500 mr-2"></i>二要素認証を設定する
                </h3>
                
                <div class="mb-6">
                    <h4 class="font-bold text-gray-700 mb-2">必要なもの</h4>
                    <ul class="text-sm text-gray-600 space-y-2">
                        <li><i class="fas fa-check text-green-500 mr-2"></i>スマートフォン</li>
                        <li><i class="fas fa-check text-green-500 mr-2"></i>認証アプリ（Google Authenticator, Microsoft Authenticator など）</li>
                    </ul>
                </div>
                
                <form method="POST">
                    <button type="submit" name="show_qr" 
                        class="w-full bg-green-500 hover:bg-green-600 text-white font-bold py-3 px-4 rounded-lg transition">
                        <i class="fas fa-qrcode mr-2"></i>設定を開始
                    </button>
                </form>
            </div>
            <?php endif; ?>
            
            <!-- 説明 -->
            <div class="bg-blue-50 rounded-xl p-6">
                <h3 class="font-bold text-blue-800 mb-3">
                    <i class="fas fa-info-circle mr-2"></i>二要素認証とは
                </h3>
                <p class="text-sm text-blue-700 leading-relaxed">
                    二要素認証（2FA）は、パスワードに加えて認証アプリで生成される6桁のコードを入力することで、
                    アカウントのセキュリティを大幅に向上させます。
                    たとえパスワードが漏洩しても、認証アプリがなければログインできません。
                </p>
            </div>
        </div>

<?php if ($showQR): ?>
<script>
document.addEventListener('DOMContentLoaded', function() {
    var qrcode = new QRCode(document.getElementById("qrcode"), {
        text: "<?= addslashes($qrUrl) ?>",
        width: 192,
        height: 192,
        colorDark: "#000000",
        colorLight: "#ffffff",
        correctLevel: QRCode.CorrectLevel.L
    });
});
</script>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
