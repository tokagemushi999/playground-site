<?php
/**
 * ゲストアクセスページ
 * ワンタイムURLで取引詳細を閲覧
 */
session_start();
require_once '../../includes/db.php';
require_once '../../includes/csrf.php';
require_once '../../includes/site-settings.php';
require_once '../../includes/transactions.php';

$db = getDB();

$token = $_GET['token'] ?? '';

if (empty($token)) {
    header('Location: /store/');
    exit;
}

// トークン検証
$transaction = validateGuestAccessToken($token);

if (!$transaction) {
    $pageTitle = 'アクセスエラー';
    require_once '../includes/header.php';
    ?>
    <div class="max-w-xl mx-auto px-4 py-16 text-center">
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8">
            <div class="w-16 h-16 bg-red-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-exclamation-triangle text-3xl text-red-500"></i>
            </div>
            <h1 class="text-xl font-bold text-gray-800 mb-4">アクセスできません</h1>
            <p class="text-gray-600 mb-6">
                このリンクは無効または期限切れです。<br>
                お心当たりがある場合は、メールからのリンクを再度ご確認ください。
            </p>
            <a href="/" class="text-green-600 hover:underline">トップページに戻る</a>
        </div>
    </div>
    <?php
    require_once '../includes/footer.php';
    exit;
}

// メッセージ送信処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    if (validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $message = trim($_POST['message'] ?? '');
        
        if (!empty($message)) {
            $messageId = addTransactionMessage(
                $transaction['id'],
                'customer',
                null,
                $transaction['guest_name'] ?? 'ゲスト',
                $message
            );
            
            // ファイルアップロード処理
            if (!empty($_FILES['attachments']['name'][0])) {
                $uploadDir = '../../uploads/transactions/' . $transaction['id'] . '/';
                if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                
                foreach ($_FILES['attachments']['name'] as $i => $name) {
                    if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                        $tmpName = $_FILES['attachments']['tmp_name'][$i];
                        $originalName = basename($name);
                        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                        $storedName = time() . '_' . uniqid() . '.' . $ext;
                        $filePath = $uploadDir . $storedName;
                        
                        if (move_uploaded_file($tmpName, $filePath)) {
                            $mimeType = $_FILES['attachments']['type'][$i];
                            $fileSize = $_FILES['attachments']['size'][$i];
                            
                            addTransactionAttachment($messageId, $transaction['id'], [
                                'original_name' => $originalName,
                                'stored_name' => $storedName,
                                'file_path' => 'uploads/transactions/' . $transaction['id'] . '/' . $storedName,
                                'file_size' => $fileSize,
                                'mime_type' => $mimeType,
                                'file_type' => detectFileType($mimeType)
                            ]);
                        }
                    }
                }
            }
            
            // クリエイターにメール通知
            sendTransactionEmail($transaction['id'], 'message_received', 'creator');
            
            header('Location: /store/transactions/guest.php?token=' . $token . '#messages');
            exit;
        }
    }
}

// 見積もり承諾処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_quote'])) {
    if (validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $quoteId = (int)$_POST['quote_id'];
        $quote = getQuote($quoteId);
        
        if ($quote && $quote['transaction_id'] == $transaction['id']) {
            // 見積もりを承諾
            $stmt = $db->prepare("UPDATE service_quotes SET status = 'accepted', accepted_at = NOW() WHERE id = ?");
            $stmt->execute([$quoteId]);
            
            // 取引ステータスを更新
            updateTransactionStatus($transaction['id'], 'payment_pending', [
                'final_price' => $quote['subtotal'],
                'tax_amount' => $quote['tax_amount'],
                'total_amount' => $quote['total_amount']
            ]);
            
            // システムメッセージを追加
            addTransactionMessage(
                $transaction['id'],
                'customer',
                null,
                $transaction['guest_name'] ?? 'ゲスト',
                '見積もりを承諾しました。',
                'system'
            );
            
            header('Location: /store/transactions/guest.php?token=' . $token . '&accepted=1');
            exit;
        }
    }
}

// 納品承認処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_delivery'])) {
    if (validateCsrfToken($_POST['csrf_token'] ?? '')) {
        updateTransactionStatus($transaction['id'], 'completed', [
            'completed_at' => date('Y-m-d H:i:s')
        ]);
        
        addTransactionMessage(
            $transaction['id'],
            'customer',
            null,
            $transaction['guest_name'] ?? 'ゲスト',
            '納品を承認しました。ありがとうございました！',
            'system'
        );
        
        sendTransactionEmail($transaction['id'], 'message_received', 'creator');
        
        header('Location: /store/transactions/guest.php?token=' . $token . '&completed=1');
        exit;
    }
}

// データ取得
$transaction = getTransaction($transaction['id']); // フル情報を再取得
$messages = getTransactionMessages($transaction['id'], 'customer');
$quotes = getTransactionQuotes($transaction['id']);
$latestQuote = !empty($quotes) ? $quotes[0] : null;

$pageTitle = '取引詳細 - ' . $transaction['transaction_code'];
require_once '../includes/header.php';
?>

<div class="max-w-4xl mx-auto px-4 py-6">
    <!-- ヘッダー -->
    <div class="mb-6">
        <div class="bg-blue-50 border border-blue-200 text-blue-700 p-3 rounded-lg mb-4 text-sm">
            <i class="fas fa-info-circle mr-1"></i>ゲストアクセス中です。このリンクの有効期限は7日間です。
        </div>
        
        <div class="flex items-start justify-between">
            <div>
                <h1 class="text-xl font-bold text-gray-800"><?= htmlspecialchars($transaction['service_title']) ?></h1>
                <p class="text-gray-500 text-sm">取引コード: <?= htmlspecialchars($transaction['transaction_code']) ?></p>
            </div>
            <span class="px-3 py-1 rounded-full text-sm font-bold <?= getTransactionStatusColor($transaction['status']) ?>">
                <?= getTransactionStatusLabel($transaction['status']) ?>
            </span>
        </div>
    </div>
    
    <?php if (isset($_GET['accepted'])): ?>
    <div class="bg-green-50 border border-green-200 text-green-700 p-4 rounded-lg mb-6">
        <i class="fas fa-check-circle mr-2"></i>見積もりを承諾しました。決済を進めてください。
    </div>
    <?php endif; ?>
    
    <?php if (isset($_GET['completed'])): ?>
    <div class="bg-green-50 border border-green-200 text-green-700 p-4 rounded-lg mb-6">
        <i class="fas fa-check-circle mr-2"></i>納品を承認しました。取引が完了しました！
    </div>
    <?php endif; ?>
    
    <div class="grid lg:grid-cols-3 gap-6">
        <!-- 左側：メッセージ -->
        <div class="lg:col-span-2 space-y-6">
            <!-- 見積もり表示（最新） -->
            <?php if ($latestQuote && $latestQuote['status'] === 'sent' && $transaction['status'] === 'quote_sent'): ?>
            <div class="bg-white rounded-xl shadow-sm border border-blue-200 p-6">
                <h3 class="font-bold text-gray-800 mb-4">
                    <i class="fas fa-file-invoice text-blue-500 mr-2"></i>見積もり
                </h3>
                
                <?php 
                $items = json_decode($latestQuote['quote_items'], true) ?: [];
                if (!empty($items)):
                ?>
                <table class="w-full mb-4">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="text-left p-2 text-sm">項目</th>
                            <th class="text-right p-2 text-sm">金額</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($items as $item): ?>
                        <tr class="border-b">
                            <td class="p-2"><?= htmlspecialchars($item['name'] ?? '') ?></td>
                            <td class="p-2 text-right"><?= formatPrice($item['price'] ?? 0) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php endif; ?>
                
                <div class="border-t pt-4 space-y-2">
                    <div class="flex justify-between font-bold text-lg">
                        <span>合計</span>
                        <span class="text-green-600"><?= formatPrice($latestQuote['total_amount']) ?></span>
                    </div>
                </div>
                
                <div class="mt-4 flex gap-3">
                    <form method="POST" class="flex-1">
                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                        <input type="hidden" name="quote_id" value="<?= $latestQuote['id'] ?>">
                        <button type="submit" name="accept_quote" value="1"
                                onclick="return confirm('この見積もりを承諾しますか？')"
                                class="w-full py-3 bg-green-500 text-white font-bold rounded-lg hover:bg-green-600 transition">
                            <i class="fas fa-check mr-2"></i>承諾する
                        </button>
                    </form>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- 決済ボタン -->
            <?php if ($transaction['status'] === 'payment_pending'): ?>
            <div class="bg-white rounded-xl shadow-sm border border-green-200 p-6">
                <h3 class="font-bold text-gray-800 mb-4">
                    <i class="fas fa-credit-card text-green-500 mr-2"></i>決済
                </h3>
                <p class="text-gray-600 mb-4">見積もりが承諾されました。決済を完了して制作を開始しましょう。</p>
                <div class="text-2xl font-bold text-green-600 mb-4">
                    <?= formatPrice($transaction['total_amount']) ?>
                </div>
                <a href="/store/transactions/checkout.php?transaction=<?= $transaction['id'] ?>" 
                   class="block w-full py-3 bg-green-500 text-white text-center font-bold rounded-lg hover:bg-green-600 transition">
                    <i class="fas fa-lock mr-2"></i>決済に進む
                </a>
            </div>
            <?php endif; ?>
            
            <!-- 納品承認 -->
            <?php if ($transaction['status'] === 'delivered'): ?>
            <div class="bg-white rounded-xl shadow-sm border border-teal-200 p-6">
                <h3 class="font-bold text-gray-800 mb-4">
                    <i class="fas fa-gift text-teal-500 mr-2"></i>納品物を確認してください
                </h3>
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <button type="submit" name="accept_delivery" value="1"
                            onclick="return confirm('納品を承認しますか？')"
                            class="w-full py-3 bg-teal-500 text-white font-bold rounded-lg hover:bg-teal-600 transition">
                        <i class="fas fa-check mr-2"></i>納品を承認する
                    </button>
                </form>
            </div>
            <?php endif; ?>
            
            <!-- メッセージ一覧 -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100" id="messages">
                <div class="p-4 border-b">
                    <h3 class="font-bold text-gray-800">
                        <i class="fas fa-comments text-purple-500 mr-2"></i>メッセージ
                    </h3>
                </div>
                
                <div class="p-4 space-y-4 max-h-[500px] overflow-y-auto" id="messageList">
                    <?php if (empty($messages)): ?>
                    <p class="text-gray-500 text-center py-8">まだメッセージはありません</p>
                    <?php else: ?>
                    <?php foreach ($messages as $msg): ?>
                    <div class="flex <?= $msg['sender_type'] === 'customer' ? 'justify-end' : 'justify-start' ?>">
                        <div class="max-w-[80%] <?= $msg['sender_type'] === 'customer' ? 'bg-green-100' : 'bg-gray-100' ?> rounded-lg p-3">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="text-xs font-bold text-gray-700"><?= htmlspecialchars($msg['sender_name']) ?></span>
                                <span class="text-xs text-gray-400"><?= date('n/j H:i', strtotime($msg['created_at'])) ?></span>
                            </div>
                            <p class="text-sm text-gray-800 whitespace-pre-wrap"><?= nl2br(htmlspecialchars($msg['message'])) ?></p>
                            
                            <?php if (!empty($msg['attachments'])): ?>
                            <div class="mt-2 space-y-1">
                                <?php foreach (explode('||', $msg['attachments']) as $att): 
                                    $parts = explode(':', $att);
                                    if (count($parts) >= 4):
                                        list($attId, $attName, $attPath, $attType) = $parts;
                                ?>
                                <a href="/<?= htmlspecialchars($attPath) ?>" target="_blank" 
                                   class="flex items-center gap-2 text-xs text-blue-600 hover:text-blue-800 bg-white p-2 rounded">
                                    <i class="fas fa-file"></i><?= htmlspecialchars($attName) ?>
                                </a>
                                <?php endif; endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </div>
                
                <!-- メッセージ入力 -->
                <?php if (!in_array($transaction['status'], ['completed', 'cancelled', 'refunded'])): ?>
                <form method="POST" enctype="multipart/form-data" class="p-4 border-t">
                    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                    <div class="space-y-3">
                        <textarea name="message" rows="3" required
                                  placeholder="メッセージを入力..."
                                  class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-400 outline-none"></textarea>
                        
                        <div class="flex items-center justify-between">
                            <label class="flex items-center gap-2 cursor-pointer text-sm text-gray-600 hover:text-gray-800">
                                <i class="fas fa-paperclip"></i>
                                <span>ファイルを添付</span>
                                <input type="file" name="attachments[]" multiple class="hidden">
                            </label>
                            
                            <button type="submit" name="send_message" value="1"
                                    class="px-6 py-2 bg-green-500 text-white font-bold rounded-lg hover:bg-green-600 transition">
                                <i class="fas fa-paper-plane mr-1"></i>送信
                            </button>
                        </div>
                    </div>
                </form>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- 右側：取引情報 -->
        <div class="space-y-4">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                <h4 class="font-bold text-gray-800 mb-3">クリエイター</h4>
                <div class="flex items-center gap-3">
                    <?php if (!empty($transaction['creator_image'])): ?>
                    <img src="/<?= htmlspecialchars($transaction['creator_image']) ?>" class="w-12 h-12 rounded-full object-cover">
                    <?php else: ?>
                    <div class="w-12 h-12 rounded-full bg-gray-200 flex items-center justify-center">
                        <i class="fas fa-user text-gray-400"></i>
                    </div>
                    <?php endif; ?>
                    <div class="font-bold"><?= htmlspecialchars($transaction['creator_name']) ?></div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                <h4 class="font-bold text-gray-800 mb-3">取引情報</h4>
                <div class="space-y-2 text-sm">
                    <div class="flex justify-between">
                        <span class="text-gray-500">作成日</span>
                        <span><?= date('Y/n/j H:i', strtotime($transaction['created_at'])) ?></span>
                    </div>
                    <?php if (!empty($transaction['total_amount'])): ?>
                    <div class="flex justify-between">
                        <span class="text-gray-500">金額</span>
                        <span class="font-bold text-green-600"><?= formatPrice($transaction['total_amount']) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const list = document.getElementById('messageList');
    if (list) list.scrollTop = list.scrollHeight;
});
</script>

<?php require_once '../includes/footer.php'; ?>
