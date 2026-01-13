<?php
/**
 * クリエイター取引詳細ページ
 * - 見積もり作成・送信
 * - メッセージ送受信
 * - ファイルアップロード
 * - 納品機能
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/csrf.php';
require_once '../includes/creator-auth.php';
require_once '../includes/transactions.php';
require_once '../includes/formatting.php';

$creator = requireCreatorAuth();
$db = getDB();

$transactionId = (int)($_GET['id'] ?? 0);
$transaction = getTransaction($transactionId);

// アクセス権限チェック
if (!$transaction || $transaction['creator_id'] != $creator['id']) {
    header('Location: transactions.php');
    exit;
}

$message = '';
$error = '';

// メッセージを既読に
markMessagesAsRead($transaction['id'], 'creator');

// メッセージ送信処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    if (validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $messageText = trim($_POST['message'] ?? '');
        
        if (!empty($messageText)) {
            $messageId = addTransactionMessage(
                $transaction['id'],
                'creator',
                $creator['id'],
                $creator['name'],
                $messageText
            );
            
            // ファイルアップロード処理
            if (!empty($_FILES['attachments']['name'][0])) {
                $uploadDir = '../uploads/transactions/' . $transaction['id'] . '/';
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
            
            // 顧客にメール通知
            sendTransactionEmail($transaction['id'], 'message_received', 'customer');
            
            header('Location: transaction-detail.php?id=' . $transaction['id'] . '#messages');
            exit;
        }
    }
}

// 見積もり作成・送信
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_quote'])) {
    if (validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $items = [];
        $itemNames = $_POST['item_name'] ?? [];
        $itemPrices = $_POST['item_price'] ?? [];
        $subtotal = 0;
        
        foreach ($itemNames as $i => $name) {
            if (!empty($name) && isset($itemPrices[$i])) {
                $price = (int)$itemPrices[$i];
                $items[] = ['name' => $name, 'price' => $price];
                $subtotal += $price;
            }
        }
        
        if (empty($items)) {
            $error = '見積もり項目を1つ以上入力してください。';
        } else {
            $taxRate = (float)($_POST['tax_rate'] ?? 10);
            $taxAmount = floor($subtotal * $taxRate / 100);
            $totalAmount = $subtotal + $taxAmount;
            $estimatedDays = (int)($_POST['estimated_days'] ?? 7);
            $notes = trim($_POST['quote_notes'] ?? '');
            
            $quoteId = createQuote($transaction['id'], [
                'items' => $items,
                'subtotal' => $subtotal,
                'tax_rate' => $taxRate,
                'tax_amount' => $taxAmount,
                'total_amount' => $totalAmount,
                'estimated_days' => $estimatedDays,
                'notes' => $notes
            ]);
            
            // 見積もりを送信
            $stmt = $db->prepare("UPDATE service_quotes SET status = 'sent', sent_at = NOW() WHERE id = ?");
            $stmt->execute([$quoteId]);
            
            // 取引ステータスを更新
            updateTransactionStatus($transaction['id'], 'quote_sent');
            
            // システムメッセージを追加
            addTransactionMessage($transaction['id'], 'creator', $creator['id'], $creator['name'], '見積もりを送信しました。', 'quote', $quoteId);
            
            // 顧客にメール通知
            sendTransactionEmail($transaction['id'], 'quote_sent', 'customer');
            
            $message = '見積もりを送信しました。';
            header('Location: transaction-detail.php?id=' . $transaction['id'] . '&quote_sent=1');
            exit;
        }
    }
}

// 納品処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['deliver'])) {
    if (validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $deliveryMessage = trim($_POST['delivery_message'] ?? '');
        
        // ステータスを納品済みに更新
        updateTransactionStatus($transaction['id'], 'delivered', ['delivered_at' => date('Y-m-d H:i:s')]);
        
        // 納品メッセージを追加
        $msgText = "【納品のお知らせ】\n\n" . ($deliveryMessage ?: '納品ファイルをお送りします。ご確認ください。');
        $messageId = addTransactionMessage(
            $transaction['id'],
            'creator',
            $creator['id'],
            $creator['name'],
            $msgText,
            'system'
        );
        
        // 納品ファイルをアップロード
        if (!empty($_FILES['delivery_files']['name'][0])) {
            $uploadDir = '../uploads/transactions/' . $transaction['id'] . '/delivery/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            
            foreach ($_FILES['delivery_files']['name'] as $i => $name) {
                if ($_FILES['delivery_files']['error'][$i] === UPLOAD_ERR_OK) {
                    $tmpName = $_FILES['delivery_files']['tmp_name'][$i];
                    $originalName = basename($name);
                    $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                    $storedName = time() . '_' . uniqid() . '.' . $ext;
                    $filePath = $uploadDir . $storedName;
                    
                    if (move_uploaded_file($tmpName, $filePath)) {
                        $mimeType = $_FILES['delivery_files']['type'][$i];
                        $fileSize = $_FILES['delivery_files']['size'][$i];
                        
                        addTransactionAttachment($messageId, $transaction['id'], [
                            'original_name' => $originalName,
                            'stored_name' => $storedName,
                            'file_path' => 'uploads/transactions/' . $transaction['id'] . '/delivery/' . $storedName,
                            'file_size' => $fileSize,
                            'mime_type' => $mimeType,
                            'file_type' => detectFileType($mimeType),
                            'is_deliverable' => 1
                        ]);
                    }
                }
            }
        }
        
        // 顧客にメール通知
        sendTransactionEmail($transaction['id'], 'message_received', 'customer');
        
        $message = '納品を送信しました。';
        header('Location: transaction-detail.php?id=' . $transaction['id'] . '&delivered=1');
        exit;
    }
}

// データ再取得
$transaction = getTransaction($transactionId);
$messages = getTransactionMessages($transaction['id'], 'creator');
$quotes = getTransactionQuotes($transaction['id']);
$latestQuote = !empty($quotes) ? $quotes[0] : null;

$pageTitle = '取引詳細 - ' . $transaction['transaction_code'];
require_once 'includes/header.php';
?>

<div class="mb-6">
    <a href="transactions.php" class="text-gray-500 hover:text-gray-700 mb-2 inline-block">
        <i class="fas fa-arrow-left mr-1"></i>取引一覧に戻る
    </a>
    <div class="flex items-start justify-between">
        <div>
            <h1 class="text-xl font-bold text-gray-800"><?= htmlspecialchars($transaction['service_title']) ?></h1>
            <p class="text-gray-500 text-sm"><?= htmlspecialchars($transaction['transaction_code']) ?></p>
        </div>
        <span class="px-3 py-1 rounded-full text-sm font-bold <?= getTransactionStatusColor($transaction['status']) ?>">
            <?= getTransactionStatusLabel($transaction['status']) ?>
        </span>
    </div>
</div>

<?php if (isset($_GET['quote_sent'])): ?>
<div class="bg-green-50 border border-green-200 text-green-700 p-4 rounded-lg mb-6">
    <i class="fas fa-check-circle mr-2"></i>見積もりを送信しました。顧客の承諾をお待ちください。
</div>
<?php endif; ?>

<?php if (isset($_GET['delivered'])): ?>
<div class="bg-green-50 border border-green-200 text-green-700 p-4 rounded-lg mb-6">
    <i class="fas fa-check-circle mr-2"></i>納品を送信しました。顧客の承認をお待ちください。
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="bg-red-50 border border-red-200 text-red-600 p-4 rounded-lg mb-6">
    <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<div class="grid lg:grid-cols-3 gap-6">
    <!-- 左側：メッセージ・見積もり -->
    <div class="lg:col-span-2 space-y-6">
        <!-- 見積もり作成フォーム（対応待ち・修正依頼時） -->
        <?php if (in_array($transaction['status'], ['inquiry', 'quote_pending', 'quote_revision'])): ?>
        <div class="bg-white rounded-xl shadow-sm border border-orange-200 p-6">
            <h3 class="font-bold text-gray-800 mb-4">
                <i class="fas fa-file-invoice text-orange-500 mr-2"></i>見積もりを作成
            </h3>
            
            <form method="POST" id="quoteForm">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                
                <div id="quoteItems" class="space-y-2 mb-4">
                    <div class="flex flex-col sm:flex-row gap-2 quote-item">
                        <input type="text" name="item_name[]" placeholder="項目名（例: 基本制作費）" required
                               class="flex-1 px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                        <div class="flex gap-2">
                            <div class="relative flex-1 sm:flex-none">
                                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">¥</span>
                                <input type="number" name="item_price[]" placeholder="金額" required min="0"
                                       class="w-full sm:w-32 pl-8 pr-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-orange-400 outline-none">
                            </div>
                            <button type="button" onclick="removeQuoteItem(this)" class="text-red-500 px-2 hover:text-red-600">
                                <i class="fas fa-times"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <button type="button" onclick="addQuoteItem()" class="text-orange-500 text-sm mb-4 hover:text-orange-600">
                    <i class="fas fa-plus mr-1"></i>項目を追加
                </button>
                
                <div class="grid md:grid-cols-3 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">税率（%）</label>
                        <input type="number" name="tax_rate" value="10" min="0" max="100"
                               class="w-full px-3 py-2 border rounded-lg text-sm">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">納品予定日数</label>
                        <input type="number" name="estimated_days" value="7" min="1"
                               class="w-full px-3 py-2 border rounded-lg text-sm">
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-bold text-gray-700 mb-1">備考</label>
                    <textarea name="quote_notes" rows="3" placeholder="見積もりに関する補足説明があれば..."
                              class="w-full px-3 py-2 border rounded-lg text-sm"></textarea>
                </div>
                
                <button type="submit" name="send_quote" value="1"
                        onclick="return confirm('この内容で見積もりを送信しますか？')"
                        class="px-6 py-3 bg-orange-500 text-white font-bold rounded-lg hover:bg-orange-600 transition">
                    <i class="fas fa-paper-plane mr-2"></i>見積もりを送信
                </button>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- 納品フォーム（制作中・修正依頼時） -->
        <?php if (in_array($transaction['status'], ['paid', 'in_progress', 'revision_requested'])): ?>
        <div class="bg-white rounded-xl shadow-sm border border-green-200 p-6">
            <h3 class="font-bold text-gray-800 mb-4">
                <i class="fas fa-gift text-green-500 mr-2"></i>納品する
            </h3>
            
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                
                <div class="mb-4">
                    <label class="block text-sm font-bold text-gray-700 mb-1">納品メッセージ</label>
                    <textarea name="delivery_message" rows="3" 
                              placeholder="納品物の説明や確認事項があれば..."
                              class="w-full px-3 py-2 border rounded-lg text-sm"></textarea>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-bold text-gray-700 mb-1">納品ファイル</label>
                    <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center hover:border-green-400 transition">
                        <input type="file" name="delivery_files[]" multiple id="deliveryFileInput" class="hidden">
                        <label for="deliveryFileInput" class="cursor-pointer">
                            <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-2"></i>
                            <p class="text-gray-600">クリックしてファイルを選択</p>
                            <p class="text-xs text-gray-500 mt-1">複数ファイル選択可（ZIP推奨）</p>
                        </label>
                    </div>
                    <div id="deliveryFileList" class="mt-2 space-y-1"></div>
                </div>
                
                <button type="submit" name="deliver" value="1"
                        onclick="return confirm('この内容で納品しますか？')"
                        class="px-6 py-3 bg-green-500 text-white font-bold rounded-lg hover:bg-green-600 transition">
                    <i class="fas fa-check-circle mr-2"></i>納品を送信
                </button>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- メッセージ一覧 -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100" id="messages">
            <div class="p-4 border-b">
                <h3 class="font-bold text-gray-800">
                    <i class="fas fa-comments text-green-500 mr-2"></i>メッセージ
                </h3>
            </div>
            
            <div class="p-4 space-y-4 max-h-[500px] overflow-y-auto" id="messageList">
                <?php if (empty($messages)): ?>
                <p class="text-gray-500 text-center py-8">まだメッセージはありません</p>
                <?php else: ?>
                <?php foreach ($messages as $msg): ?>
                <div class="flex <?= $msg['sender_type'] === 'creator' ? 'justify-end' : 'justify-start' ?>">
                    <div class="max-w-[80%] <?= $msg['sender_type'] === 'creator' ? 'bg-green-100' : ($msg['sender_type'] === 'admin' ? 'bg-purple-100' : 'bg-gray-100') ?> rounded-lg p-3">
                        <div class="flex items-center gap-2 mb-1">
                            <span class="text-xs font-bold <?= $msg['sender_type'] === 'creator' ? 'text-green-700' : ($msg['sender_type'] === 'admin' ? 'text-purple-700' : 'text-blue-700') ?>">
                                <?php if ($msg['sender_type'] === 'customer'): ?>
                                <i class="fas fa-user mr-1"></i>
                                <?php elseif ($msg['sender_type'] === 'admin'): ?>
                                <i class="fas fa-crown mr-1"></i>運営
                                <?php else: ?>
                                <i class="fas fa-palette mr-1"></i>
                                <?php endif; ?>
                                <?= htmlspecialchars($msg['sender_name']) ?>
                            </span>
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
                                <i class="fas fa-<?= $attType === 'image' ? 'image' : ($attType === 'document' ? 'file-alt' : 'file') ?>"></i>
                                <?= htmlspecialchars($attName) ?>
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
                            <input type="file" name="attachments[]" multiple class="hidden" 
                                   onchange="updateFileLabel(this, 'msgFileLabel')">
                        </label>
                        <span id="msgFileLabel" class="text-xs text-gray-500"></span>
                        
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
        <!-- 顧客情報 -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <h4 class="font-bold text-gray-800 mb-3">顧客情報</h4>
            <div class="space-y-2 text-sm">
                <div>
                    <span class="text-gray-500">お名前</span>
                    <div class="font-medium"><?= htmlspecialchars($transaction['member_name'] ?? $transaction['guest_name'] ?? '-') ?></div>
                </div>
                <div>
                    <span class="text-gray-500">メール</span>
                    <div class="font-medium text-xs"><?= htmlspecialchars($transaction['member_email'] ?? $transaction['guest_email'] ?? '-') ?></div>
                </div>
            </div>
        </div>
        
        <!-- 依頼内容 -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <h4 class="font-bold text-gray-800 mb-3">依頼内容</h4>
            <div class="space-y-2 text-sm">
                <?php if (!empty($transaction['request_title'])): ?>
                <div>
                    <span class="text-gray-500">タイトル</span>
                    <div class="font-medium"><?= htmlspecialchars($transaction['request_title']) ?></div>
                </div>
                <?php endif; ?>
                <?php if (!empty($transaction['request_budget'])): ?>
                <div>
                    <span class="text-gray-500">希望予算</span>
                    <span class="font-medium"><?= formatPrice($transaction['request_budget'] ?? 0) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($transaction['request_deadline'])): ?>
                <div>
                    <span class="text-gray-500">希望納期</span>
                    <span class="font-medium"><?= date('Y/n/j', strtotime($transaction['request_deadline'])) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($transaction['request_detail'])): ?>
                <div>
                    <span class="text-gray-500">詳細</span>
                    <div class="mt-1 p-2 bg-gray-50 rounded text-xs whitespace-pre-wrap"><?= nl2br(htmlspecialchars($transaction['request_detail'])) ?></div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- 見積もり履歴 -->
        <?php if (!empty($quotes)): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <h4 class="font-bold text-gray-800 mb-3">見積もり履歴</h4>
            <div class="space-y-2">
                <?php foreach ($quotes as $q): ?>
                <div class="p-2 bg-gray-50 rounded">
                    <div class="flex justify-between text-sm">
                        <span>v<?= $q['version'] ?></span>
                        <span class="font-bold"><?= formatPrice($q['total_amount'] ?? 0) ?></span>
                    </div>
                    <div class="text-xs text-gray-500">
                        <?= $q['status'] === 'accepted' ? '✅承諾済み' : ($q['status'] === 'sent' ? '📤送信済み' : $q['status']) ?>
                        - <?= date('n/j H:i', strtotime($q['created_at'])) ?>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- 取引情報 -->
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
                    <span class="font-bold text-green-600"><?= formatPrice($transaction['total_amount'] ?? 0) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($transaction['paid_at'])): ?>
                <div class="flex justify-between">
                    <span class="text-gray-500">決済日</span>
                    <span><?= date('Y/n/j H:i', strtotime($transaction['paid_at'])) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($transaction['started_at'])): ?>
                <div class="flex justify-between">
                    <span class="text-gray-500">開始日</span>
                    <span><?= date('Y/n/j', strtotime($transaction['started_at'])) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($transaction['delivery_deadline'])): ?>
                <div class="flex justify-between">
                    <span class="text-gray-500">納品期限</span>
                    <?php 
                    $deadline = strtotime($transaction['delivery_deadline']);
                    $isOverdue = $deadline < time() && !in_array($transaction['status'], ['completed', 'cancelled', 'refunded', 'delivered']);
                    ?>
                    <span class="<?= $isOverdue ? 'text-red-600 font-bold' : '' ?>">
                        <?= date('Y/n/j', $deadline) ?>
                        <?php if ($isOverdue): ?><i class="fas fa-exclamation-triangle text-red-500 ml-1"></i><?php endif; ?>
                    </span>
                </div>
                <?php endif; ?>
                <?php if (!empty($transaction['delivered_at'])): ?>
                <div class="flex justify-between">
                    <span class="text-gray-500">納品日</span>
                    <span class="text-blue-600"><?= date('Y/n/j H:i', strtotime($transaction['delivered_at'])) ?></span>
                </div>
                <?php endif; ?>
                <?php if (!empty($transaction['completed_at'])): ?>
                <div class="flex justify-between">
                    <span class="text-gray-500">完了日</span>
                    <span class="text-green-600"><?= date('Y/n/j H:i', strtotime($transaction['completed_at'])) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
function addQuoteItem() {
    const container = document.getElementById('quoteItems');
    const div = document.createElement('div');
    div.className = 'flex flex-col sm:flex-row gap-2 quote-item';
    div.innerHTML = `
        <input type="text" name="item_name[]" placeholder="項目名" required class="flex-1 px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-orange-400 outline-none">
        <div class="flex gap-2">
            <div class="relative flex-1 sm:flex-none">
                <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-400">¥</span>
                <input type="number" name="item_price[]" placeholder="金額" required min="0" class="w-full sm:w-32 pl-8 pr-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-orange-400 outline-none">
            </div>
            <button type="button" onclick="removeQuoteItem(this)" class="text-red-500 px-2 hover:text-red-600"><i class="fas fa-times"></i></button>
        </div>
    `;
    container.appendChild(div);
}

function removeQuoteItem(btn) {
    const items = document.querySelectorAll('.quote-item');
    if (items.length > 1) {
        btn.closest('.quote-item').remove();
    }
}

function updateFileLabel(input, labelId) {
    const label = document.getElementById(labelId);
    if (label && input.files.length > 0) {
        label.textContent = input.files.length + '件のファイルを選択';
    }
}

document.getElementById('deliveryFileInput')?.addEventListener('change', function() {
    const list = document.getElementById('deliveryFileList');
    list.innerHTML = '';
    for (const file of this.files) {
        const div = document.createElement('div');
        div.className = 'flex items-center gap-2 text-sm text-gray-600 bg-white p-2 rounded border';
        div.innerHTML = `<i class="fas fa-file"></i><span class="truncate">${file.name}</span><span class="text-gray-400">(${(file.size / 1024 / 1024).toFixed(2)}MB)</span>`;
        list.appendChild(div);
    }
});

// メッセージリストを下にスクロール
document.addEventListener('DOMContentLoaded', function() {
    const list = document.getElementById('messageList');
    if (list) list.scrollTop = list.scrollHeight;
});
</script>

<?php require_once 'includes/footer.php'; ?>
