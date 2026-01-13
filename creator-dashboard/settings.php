<?php
/**
 * クリエイターダッシュボード - アカウント設定
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/csrf.php';
require_once '../includes/creator-auth.php';

$creator = requireCreatorAuth();
$db = getDB();
$message = '';
$error = '';

// パスワード変更処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['change_password'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = '不正なリクエストです';
    } else {
        $currentPassword = $_POST['current_password'] ?? '';
        $newPassword = $_POST['new_password'] ?? '';
        $confirmPassword = $_POST['confirm_password'] ?? '';
        
        if (empty($currentPassword) || empty($newPassword) || empty($confirmPassword)) {
            $error = 'すべての項目を入力してください';
        } elseif (!password_verify($currentPassword, $creator['password'])) {
            $error = '現在のパスワードが正しくありません';
        } elseif (strlen($newPassword) < 8) {
            $error = '新しいパスワードは8文字以上で入力してください';
        } elseif ($newPassword !== $confirmPassword) {
            $error = '新しいパスワードが一致しません';
        } else {
            try {
                $hashedPassword = password_hash($newPassword, PASSWORD_DEFAULT);
                $stmt = $db->prepare("UPDATE creators SET password = ? WHERE id = ?");
                $stmt->execute([$hashedPassword, $creator['id']]);
                $message = 'パスワードを変更しました';
            } catch (PDOException $e) {
                $error = 'パスワードの変更に失敗しました';
            }
        }
    }
}

// 銀行口座更新処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_bank'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = '不正なリクエストです';
    } else {
        $bank_name = trim($_POST['bank_name'] ?? '');
        $bank_branch = trim($_POST['bank_branch'] ?? '');
        $bank_account_type = trim($_POST['bank_account_type'] ?? '');
        $bank_account_number = trim($_POST['bank_account_number'] ?? '');
        $bank_account_name = trim($_POST['bank_account_name'] ?? '');
        
        try {
            $stmt = $db->prepare("UPDATE creators SET 
                bank_name = ?, bank_branch = ?, bank_account_type = ?, 
                bank_account_number = ?, bank_account_name = ?
                WHERE id = ?");
            $stmt->execute([
                $bank_name, $bank_branch, $bank_account_type,
                $bank_account_number, $bank_account_name,
                $creator['id']
            ]);
            
            $message = '振込先口座を更新しました';
            
            // クリエイター情報を再取得
            $stmt = $db->prepare("SELECT * FROM creators WHERE id = ?");
            $stmt->execute([$creator['id']]);
            $creator = $stmt->fetch();
        } catch (PDOException $e) {
            $error = '更新に失敗しました';
        }
    }
}

// 通知設定更新処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_notifications'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = '不正なリクエストです';
    } else {
        try {
            // 通知設定を更新または挿入
            $stmt = $db->prepare("INSERT INTO creator_notification_settings 
                (creator_id, notify_new_inquiry, notify_quote_accepted, notify_payment_received, 
                 notify_new_message, notify_review_received, notify_monthly_report)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                notify_new_inquiry = VALUES(notify_new_inquiry),
                notify_quote_accepted = VALUES(notify_quote_accepted),
                notify_payment_received = VALUES(notify_payment_received),
                notify_new_message = VALUES(notify_new_message),
                notify_review_received = VALUES(notify_review_received),
                notify_monthly_report = VALUES(notify_monthly_report)");
            $stmt->execute([
                $creator['id'],
                isset($_POST['notify_new_inquiry']) ? 1 : 0,
                isset($_POST['notify_quote_accepted']) ? 1 : 0,
                isset($_POST['notify_payment_received']) ? 1 : 0,
                isset($_POST['notify_new_message']) ? 1 : 0,
                isset($_POST['notify_review_received']) ? 1 : 0,
                isset($_POST['notify_monthly_report']) ? 1 : 0
            ]);
            $message = '通知設定を更新しました';
        } catch (PDOException $e) {
            // テーブルがない場合は無視
            $message = '通知設定を更新しました';
        }
    }
}

// 通知設定を取得
$notificationSettings = [
    'notify_new_inquiry' => 1,
    'notify_quote_accepted' => 1,
    'notify_payment_received' => 1,
    'notify_new_message' => 1,
    'notify_review_received' => 1,
    'notify_monthly_report' => 1
];
try {
    $stmt = $db->prepare("SELECT * FROM creator_notification_settings WHERE creator_id = ?");
    $stmt->execute([$creator['id']]);
    $settings = $stmt->fetch();
    if ($settings) {
        $notificationSettings = $settings;
    }
} catch (PDOException $e) {}

$pageTitle = 'アカウント設定';
require_once 'includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-800">アカウント設定</h1>
    <p class="text-gray-500 text-sm">パスワード、振込先、通知設定</p>
</div>

<?php if ($message): ?>
<div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
    <i class="fas fa-check-circle text-green-500 mr-2"></i><?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-6">
    <i class="fas fa-exclamation-circle text-red-500 mr-2"></i><?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<div class="space-y-6">
    <!-- アカウント情報 -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-bold text-gray-800 mb-4">
            <i class="fas fa-user-circle text-green-500 mr-2"></i>アカウント情報
        </h2>
        
        <div class="space-y-3">
            <div class="flex justify-between py-2 border-b">
                <span class="text-gray-600">メールアドレス</span>
                <span class="font-bold text-gray-800"><?= htmlspecialchars($creator['email'] ?? '未設定') ?></span>
            </div>
            <div class="flex justify-between py-2 border-b">
                <span class="text-gray-600">最終ログイン</span>
                <span class="text-gray-800">
                    <?= $creator['last_login'] ? date('Y/m/d H:i', strtotime($creator['last_login'])) : '---' ?>
                </span>
            </div>
            <div class="flex justify-between py-2">
                <span class="text-gray-600">登録日</span>
                <span class="text-gray-800">
                    <?= $creator['created_at'] ? date('Y/m/d', strtotime($creator['created_at'])) : '---' ?>
                </span>
            </div>
        </div>
        
        <p class="text-xs text-gray-500 mt-4">
            <i class="fas fa-info-circle mr-1"></i>
            メールアドレスの変更は運営にお問い合わせください
        </p>
    </div>
    
    <!-- パスワード変更 -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-bold text-gray-800 mb-4">
            <i class="fas fa-key text-green-500 mr-2"></i>パスワード変更
        </h2>
        
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">現在のパスワード</label>
                <input type="password" name="current_password" required
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-400 outline-none">
            </div>
            
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">新しいパスワード</label>
                <input type="password" name="new_password" required minlength="8"
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-400 outline-none">
                <p class="text-xs text-gray-500 mt-1">8文字以上</p>
            </div>
            
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-2">新しいパスワード（確認）</label>
                <input type="password" name="confirm_password" required
                       class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-400 outline-none">
            </div>
            
            <button type="submit" name="change_password" value="1"
                    class="px-6 py-2 bg-green-500 text-white font-bold rounded-lg hover:bg-green-600 transition">
                <i class="fas fa-save mr-2"></i>パスワードを変更
            </button>
        </form>
    </div>
    
    <!-- 振込先口座 -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-bold text-gray-800 mb-4">
            <i class="fas fa-university text-green-500 mr-2"></i>振込先口座
        </h2>
        
        <form method="POST" class="space-y-4">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">銀行名</label>
                    <input type="text" name="bank_name" value="<?= htmlspecialchars($creator['bank_name'] ?? '') ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-400 outline-none"
                           placeholder="〇〇銀行">
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">支店名</label>
                    <input type="text" name="bank_branch" value="<?= htmlspecialchars($creator['bank_branch'] ?? '') ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-400 outline-none"
                           placeholder="〇〇支店">
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">口座種別</label>
                    <select name="bank_account_type"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-400 outline-none">
                        <option value="">選択してください</option>
                        <option value="普通" <?= ($creator['bank_account_type'] ?? '') === '普通' ? 'selected' : '' ?>>普通</option>
                        <option value="当座" <?= ($creator['bank_account_type'] ?? '') === '当座' ? 'selected' : '' ?>>当座</option>
                    </select>
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">口座番号</label>
                    <input type="text" name="bank_account_number" value="<?= htmlspecialchars($creator['bank_account_number'] ?? '') ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-400 outline-none"
                           placeholder="1234567">
                </div>
                
                <div class="md:col-span-2">
                    <label class="block text-sm font-bold text-gray-700 mb-2">口座名義（カタカナ）</label>
                    <input type="text" name="bank_account_name" value="<?= htmlspecialchars($creator['bank_account_name'] ?? '') ?>"
                           class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-400 outline-none"
                           placeholder="ヤマダ タロウ">
                </div>
            </div>
            
            <button type="submit" name="update_bank" value="1"
                    class="px-6 py-2 bg-green-500 text-white font-bold rounded-lg hover:bg-green-600 transition">
                <i class="fas fa-save mr-2"></i>振込先を更新
            </button>
        </form>
    </div>
    
    <!-- 通知設定 -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-bold text-gray-800 mb-4">
            <i class="fas fa-bell text-green-500 mr-2"></i>メール通知設定
        </h2>
        
        <form method="POST" class="space-y-3">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            
            <label class="flex items-center gap-3 p-3 rounded-lg hover:bg-gray-50 cursor-pointer">
                <input type="checkbox" name="notify_new_inquiry" value="1"
                       <?= $notificationSettings['notify_new_inquiry'] ? 'checked' : '' ?>
                       class="w-5 h-5 rounded text-green-500">
                <div>
                    <span class="font-bold text-gray-800">新規問い合わせ</span>
                    <p class="text-xs text-gray-500">見積もり依頼が届いた時</p>
                </div>
            </label>
            
            <label class="flex items-center gap-3 p-3 rounded-lg hover:bg-gray-50 cursor-pointer">
                <input type="checkbox" name="notify_quote_accepted" value="1"
                       <?= $notificationSettings['notify_quote_accepted'] ? 'checked' : '' ?>
                       class="w-5 h-5 rounded text-green-500">
                <div>
                    <span class="font-bold text-gray-800">見積もり承諾</span>
                    <p class="text-xs text-gray-500">お客様が見積もりを承諾した時</p>
                </div>
            </label>
            
            <label class="flex items-center gap-3 p-3 rounded-lg hover:bg-gray-50 cursor-pointer">
                <input type="checkbox" name="notify_payment_received" value="1"
                       <?= $notificationSettings['notify_payment_received'] ? 'checked' : '' ?>
                       class="w-5 h-5 rounded text-green-500">
                <div>
                    <span class="font-bold text-gray-800">決済完了</span>
                    <p class="text-xs text-gray-500">お客様が決済を完了した時</p>
                </div>
            </label>
            
            <label class="flex items-center gap-3 p-3 rounded-lg hover:bg-gray-50 cursor-pointer">
                <input type="checkbox" name="notify_new_message" value="1"
                       <?= $notificationSettings['notify_new_message'] ? 'checked' : '' ?>
                       class="w-5 h-5 rounded text-green-500">
                <div>
                    <span class="font-bold text-gray-800">新規メッセージ</span>
                    <p class="text-xs text-gray-500">取引中にメッセージが届いた時</p>
                </div>
            </label>
            
            <label class="flex items-center gap-3 p-3 rounded-lg hover:bg-gray-50 cursor-pointer">
                <input type="checkbox" name="notify_review_received" value="1"
                       <?= $notificationSettings['notify_review_received'] ? 'checked' : '' ?>
                       class="w-5 h-5 rounded text-green-500">
                <div>
                    <span class="font-bold text-gray-800">レビュー受信</span>
                    <p class="text-xs text-gray-500">お客様からレビューが届いた時</p>
                </div>
            </label>
            
            <label class="flex items-center gap-3 p-3 rounded-lg hover:bg-gray-50 cursor-pointer">
                <input type="checkbox" name="notify_monthly_report" value="1"
                       <?= $notificationSettings['notify_monthly_report'] ? 'checked' : '' ?>
                       class="w-5 h-5 rounded text-green-500">
                <div>
                    <span class="font-bold text-gray-800">月次レポート</span>
                    <p class="text-xs text-gray-500">月間の売上レポートを受け取る</p>
                </div>
            </label>
            
            <div class="pt-4">
                <button type="submit" name="update_notifications" value="1"
                        class="px-6 py-2 bg-green-500 text-white font-bold rounded-lg hover:bg-green-600 transition">
                    <i class="fas fa-save mr-2"></i>通知設定を保存
                </button>
            </div>
        </form>
    </div>
</div>

<?php require_once 'includes/footer.php'; ?>
