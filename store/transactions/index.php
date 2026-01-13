<?php
/**
 * 取引一覧・詳細ページ（顧客用）
 */
session_start();
require_once '../../includes/db.php';
require_once '../../includes/site-settings.php';
require_once '../../includes/member-auth.php';
require_once '../../includes/transactions.php';
require_once '../../includes/csrf.php';

$db = getDB();

// ログイン必須
$member = getCurrentMember();
if (!$member) {
    header('Location: /store/login.php?redirect=' . urlencode($_SERVER['REQUEST_URI']));
    exit;
}

$transactionCode = $_GET['code'] ?? null;

// 個別取引の場合
if ($transactionCode) {
    $transaction = getTransactionByCode($transactionCode);
    
    // アクセス権限チェック
    if (!$transaction || $transaction['member_id'] != $member['id']) {
        header('Location: /store/transactions/');
        exit;
    }
    
    // メッセージを既読に
    markMessagesAsRead($transaction['id'], 'customer');
    
    // メッセージ送信処理
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
        if (validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $message = trim($_POST['message'] ?? '');
            
            if (!empty($message)) {
                $messageId = addTransactionMessage(
                    $transaction['id'],
                    'customer',
                    $member['id'],
                    $member['name'],
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
                
                header('Location: /store/transactions/' . $transactionCode . '#messages');
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
                    $member['id'],
                    $member['name'],
                    '見積もりを承諾しました。',
                    'system'
                );
                
                header('Location: /store/transactions/' . $transactionCode . '?accepted=1');
                exit;
            }
        }
    }
    
    // 見積もり修正依頼
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_revision'])) {
        if (validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $revisionMessage = trim($_POST['revision_message'] ?? '');
            
            if (!empty($revisionMessage)) {
                updateTransactionStatus($transaction['id'], 'quote_revision');
                
                addTransactionMessage(
                    $transaction['id'],
                    'customer',
                    $member['id'],
                    $member['name'],
                    "【見積もり修正依頼】\n" . $revisionMessage
                );
                
                sendTransactionEmail($transaction['id'], 'message_received', 'creator');
                
                header('Location: /store/transactions/' . $transactionCode);
                exit;
            }
        }
    }
    
    // 納品承認処理
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['accept_delivery'])) {
        if (validateCsrfToken($_POST['csrf_token'] ?? '')) {
            // ステータスを完了に更新
            updateTransactionStatus($transaction['id'], 'completed', [
                'completed_at' => date('Y-m-d H:i:s')
            ]);
            
            // システムメッセージを追加
            addTransactionMessage(
                $transaction['id'],
                'customer',
                $member['id'],
                $member['name'],
                '納品を承認しました。ありがとうございました！',
                'system'
            );
            
            // クリエイターにメール通知
            sendTransactionEmail($transaction['id'], 'message_received', 'creator');
            
            header('Location: /store/transactions/' . $transactionCode . '?completed=1');
            exit;
        }
    }
    
    // 納品物の修正依頼
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['request_delivery_revision'])) {
        if (validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $revisionMessage = trim($_POST['delivery_revision_message'] ?? '');
            
            if (!empty($revisionMessage)) {
                updateTransactionStatus($transaction['id'], 'revision_requested');
                
                addTransactionMessage(
                    $transaction['id'],
                    'customer',
                    $member['id'],
                    $member['name'],
                    "【納品物の修正依頼】\n" . $revisionMessage
                );
                
                sendTransactionEmail($transaction['id'], 'message_received', 'creator');
                
                header('Location: /store/transactions/' . $transactionCode . '?revision_requested=1');
                exit;
            }
        }
    }
    
    // データ取得
    $messages = getTransactionMessages($transaction['id'], 'customer');
    $quotes = getTransactionQuotes($transaction['id']);
    $latestQuote = !empty($quotes) ? $quotes[0] : null;
    
    $pageTitle = '取引詳細 - ' . $transaction['transaction_code'];
} else {
    // 一覧
    $status = $_GET['status'] ?? null;
    $transactions = getMemberTransactions($member['id'], $status);
    $pageTitle = '取引一覧';
}

require_once '../includes/header.php';
?>

<?php if ($transactionCode && $transaction): ?>
<!-- 取引詳細ページ -->
<div class="max-w-4xl mx-auto px-4 py-6">
    <!-- ヘッダー -->
    <div class="mb-6">
        <a href="/store/transactions/" class="text-gray-500 hover:text-gray-700 mb-2 inline-block">
            <i class="fas fa-arrow-left mr-1"></i>取引一覧に戻る
        </a>
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
    
    <?php if (isset($_GET['revision_requested'])): ?>
    <div class="bg-yellow-50 border border-yellow-200 text-yellow-700 p-4 rounded-lg mb-6">
        <i class="fas fa-info-circle mr-2"></i>修正依頼を送信しました。クリエイターの対応をお待ちください。
    </div>
    <?php endif; ?>
    
    <div class="grid lg:grid-cols-3 gap-6">
        <!-- 左側：メッセージ -->
        <div class="lg:col-span-2 space-y-6">
            <!-- 見積もり表示（最新） -->
            <?php if ($latestQuote && $latestQuote['status'] === 'sent' && $transaction['status'] === 'quote_sent'): ?>
            <div class="bg-white rounded-xl shadow-sm border border-blue-200 p-6">
                <h3 class="font-bold text-gray-800 mb-4">
                    <i class="fas fa-file-invoice text-blue-500 mr-2"></i>見積もり（v<?= $latestQuote['version'] ?>）
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
                    <div class="flex justify-between">
                        <span>小計</span>
                        <span><?= formatPrice($latestQuote['subtotal']) ?></span>
                    </div>
                    <div class="flex justify-between text-sm text-gray-500">
                        <span>消費税（<?= $latestQuote['tax_rate'] ?>%）</span>
                        <span><?= formatPrice($latestQuote['tax_amount']) ?></span>
                    </div>
                    <div class="flex justify-between font-bold text-lg pt-2 border-t">
                        <span>合計</span>
                        <span class="text-green-600"><?= formatPrice($latestQuote['total_amount']) ?></span>
                    </div>
                </div>
                
                <?php if (!empty($latestQuote['estimated_days'])): ?>
                <div class="mt-4 p-3 bg-gray-50 rounded-lg text-sm">
                    <i class="fas fa-clock mr-1"></i>納品予定: 承諾後 約<?= $latestQuote['estimated_days'] ?>日
                </div>
                <?php endif; ?>
                
                <?php if (!empty($latestQuote['notes'])): ?>
                <div class="mt-4 p-3 bg-yellow-50 rounded-lg text-sm">
                    <div class="font-bold mb-1">備考:</div>
                    <?= nl2br(htmlspecialchars($latestQuote['notes'])) ?>
                </div>
                <?php endif; ?>
                
                <!-- 承諾・修正依頼ボタン -->
                <div class="mt-6 flex gap-3">
                    <form method="POST" class="flex-1">
                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                        <input type="hidden" name="quote_id" value="<?= $latestQuote['id'] ?>">
                        <button type="submit" name="accept_quote" value="1"
                                onclick="return confirm('この見積もりを承諾しますか？')"
                                class="w-full py-3 bg-green-500 text-white font-bold rounded-lg hover:bg-green-600 transition">
                            <i class="fas fa-check mr-2"></i>見積もりを承諾する
                        </button>
                    </form>
                    <button type="button" onclick="document.getElementById('revisionModal').classList.remove('hidden')"
                            class="px-6 py-3 bg-orange-500 text-white font-bold rounded-lg hover:bg-orange-600 transition">
                        <i class="fas fa-edit mr-1"></i>修正依頼
                    </button>
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
            
            <!-- 納品承認（納品済みの場合） -->
            <?php if ($transaction['status'] === 'delivered'): ?>
            <div class="bg-white rounded-xl shadow-sm border border-teal-200 p-6">
                <h3 class="font-bold text-gray-800 mb-4">
                    <i class="fas fa-gift text-teal-500 mr-2"></i>納品物を確認してください
                </h3>
                <p class="text-gray-600 mb-4">
                    クリエイターから納品がありました。内容をご確認いただき、問題がなければ承認をお願いします。
                    修正が必要な場合は修正依頼をお送りください。
                </p>
                
                <div class="flex gap-3">
                    <form method="POST" class="flex-1">
                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                        <button type="submit" name="accept_delivery" value="1"
                                onclick="return confirm('納品を承認しますか？承認後は取引が完了となります。')"
                                class="w-full py-3 bg-teal-500 text-white font-bold rounded-lg hover:bg-teal-600 transition">
                            <i class="fas fa-check mr-2"></i>納品を承認する
                        </button>
                    </form>
                    <button type="button" onclick="document.getElementById('deliveryRevisionModal').classList.remove('hidden')"
                            class="px-6 py-3 bg-orange-500 text-white font-bold rounded-lg hover:bg-orange-600 transition">
                        <i class="fas fa-edit mr-1"></i>修正依頼
                    </button>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- 取引完了表示 -->
            <?php if ($transaction['status'] === 'completed'): ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-6 text-center">
                <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                    <i class="fas fa-check-circle text-3xl text-green-500"></i>
                </div>
                <h3 class="font-bold text-gray-800 mb-2">取引が完了しました</h3>
                <p class="text-gray-600 text-sm">ご利用ありがとうございました。</p>
                <?php if (!empty($transaction['completed_at'])): ?>
                <p class="text-gray-400 text-xs mt-2">完了日: <?= date('Y/n/j H:i', strtotime($transaction['completed_at'])) ?></p>
                <?php endif; ?>
                
                <?php 
                // レビュー確認
                $existingReview = null;
                try {
                    $reviewStmt = $db->prepare("SELECT * FROM service_reviews WHERE transaction_id = ?");
                    $reviewStmt->execute([$transaction['id']]);
                    $existingReview = $reviewStmt->fetch();
                } catch (PDOException $e) {}
                ?>
                
                <?php if (!$existingReview): ?>
                <a href="/store/transactions/review.php?transaction=<?= htmlspecialchars($transaction['transaction_code']) ?>" 
                   class="inline-block mt-4 px-6 py-2 bg-yellow-500 text-white font-bold rounded-lg hover:bg-yellow-600 transition">
                    <i class="fas fa-star mr-1"></i>レビューを投稿する
                </a>
                <?php else: ?>
                <div class="mt-4 p-3 bg-yellow-50 rounded-lg text-left">
                    <div class="flex items-center gap-1 mb-1">
                        <?php for ($i = 1; $i <= 5; $i++): ?>
                        <i class="fas fa-star text-sm <?= $i <= $existingReview['rating'] ? 'text-yellow-400' : 'text-gray-300' ?>"></i>
                        <?php endfor; ?>
                        <span class="ml-2 text-sm font-bold text-gray-700"><?= $existingReview['rating'] ?>.0</span>
                    </div>
                    <?php if (!empty($existingReview['title'])): ?>
                    <p class="font-bold text-gray-800 text-sm"><?= htmlspecialchars($existingReview['title']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($existingReview['comment'])): ?>
                    <p class="text-gray-600 text-sm mt-1"><?= nl2br(htmlspecialchars($existingReview['comment'])) ?></p>
                    <?php endif; ?>
                    <p class="text-xs text-gray-400 mt-2">投稿日: <?= date('Y/n/j', strtotime($existingReview['created_at'])) ?></p>
                </div>
                <?php endif; ?>
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
                        <div class="max-w-[80%] <?= $msg['sender_type'] === 'customer' ? 'bg-green-100' : ($msg['sender_type'] === 'admin' ? 'bg-purple-100' : 'bg-gray-100') ?> rounded-lg p-3">
                            <div class="flex items-center gap-2 mb-1">
                                <span class="text-xs font-bold <?= $msg['sender_type'] === 'customer' ? 'text-green-700' : ($msg['sender_type'] === 'admin' ? 'text-purple-700' : 'text-gray-700') ?>">
                                    <?= htmlspecialchars($msg['sender_name']) ?>
                                    <?php if ($msg['sender_type'] === 'admin'): ?>
                                    <span class="bg-purple-500 text-white text-[10px] px-1 rounded ml-1">運営</span>
                                    <?php endif; ?>
                                </span>
                                <span class="text-xs text-gray-400"><?= date('n/j H:i', strtotime($msg['created_at'])) ?></span>
                            </div>
                            
                            <?php if ($msg['message_type'] === 'system'): ?>
                            <p class="text-sm text-gray-600 italic"><?= nl2br(htmlspecialchars($msg['message'])) ?></p>
                            <?php else: ?>
                            <p class="text-sm text-gray-800 whitespace-pre-wrap"><?= nl2br(htmlspecialchars($msg['message'])) ?></p>
                            <?php endif; ?>
                            
                            <?php if (!empty($msg['attachments'])): ?>
                            <div class="mt-2 space-y-1">
                                <?php foreach (explode('||', $msg['attachments']) as $att): 
                                    list($attId, $attName, $attPath, $attType) = explode(':', $att);
                                ?>
                                <a href="/<?= htmlspecialchars($attPath) ?>" target="_blank" 
                                   class="flex items-center gap-2 text-xs text-blue-600 hover:text-blue-800 bg-white p-2 rounded">
                                    <i class="fas fa-<?= $attType === 'image' ? 'image' : ($attType === 'document' ? 'file-alt' : 'file') ?>"></i>
                                    <?= htmlspecialchars($attName) ?>
                                </a>
                                <?php endforeach; ?>
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
                                       onchange="updateFileLabel(this)">
                            </label>
                            <span id="fileLabel" class="text-xs text-gray-500"></span>
                            
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
            <!-- クリエイター情報 -->
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
                    <div>
                        <div class="font-bold"><?= htmlspecialchars($transaction['creator_name']) ?></div>
                    </div>
                </div>
            </div>
            
            <!-- 依頼内容 -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                <h4 class="font-bold text-gray-800 mb-3">依頼内容</h4>
                <div class="space-y-2 text-sm">
                    <?php if (!empty($transaction['request_title'])): ?>
                    <div>
                        <span class="text-gray-500">タイトル:</span>
                        <div class="font-medium"><?= htmlspecialchars($transaction['request_title']) ?></div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($transaction['request_budget'])): ?>
                    <div>
                        <span class="text-gray-500">希望予算:</span>
                        <span class="font-medium"><?= formatPrice($transaction['request_budget']) ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($transaction['request_deadline'])): ?>
                    <div>
                        <span class="text-gray-500">希望納期:</span>
                        <span class="font-medium"><?= date('Y/n/j', strtotime($transaction['request_deadline'])) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <!-- 取引履歴 -->
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

<!-- 修正依頼モーダル -->
<div id="revisionModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-xl max-w-lg w-full p-6">
        <h3 class="font-bold text-lg mb-4">見積もり修正依頼</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <textarea name="revision_message" rows="4" required
                      placeholder="修正してほしい点を具体的にお書きください..."
                      class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-orange-400 outline-none mb-4"></textarea>
            <div class="flex gap-3">
                <button type="submit" name="request_revision" value="1"
                        class="flex-1 py-2 bg-orange-500 text-white font-bold rounded-lg hover:bg-orange-600">
                    送信
                </button>
                <button type="button" onclick="document.getElementById('revisionModal').classList.add('hidden')"
                        class="px-6 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400">
                    キャンセル
                </button>
            </div>
        </form>
    </div>
</div>

<!-- 納品修正依頼モーダル -->
<div id="deliveryRevisionModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-xl max-w-lg w-full p-6">
        <h3 class="font-bold text-lg mb-4">納品物の修正依頼</h3>
        <form method="POST">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <textarea name="delivery_revision_message" rows="4" required
                      placeholder="修正してほしい点を具体的にお書きください..."
                      class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-orange-400 outline-none mb-4"></textarea>
            <div class="flex gap-3">
                <button type="submit" name="request_delivery_revision" value="1"
                        class="flex-1 py-2 bg-orange-500 text-white font-bold rounded-lg hover:bg-orange-600">
                    修正依頼を送信
                </button>
                <button type="button" onclick="document.getElementById('deliveryRevisionModal').classList.add('hidden')"
                        class="px-6 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400">
                    キャンセル
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function updateFileLabel(input) {
    const label = document.getElementById('fileLabel');
    if (input.files.length > 0) {
        label.textContent = input.files.length + '件のファイルを選択';
    } else {
        label.textContent = '';
    }
}

// メッセージ一覧を下にスクロール
document.addEventListener('DOMContentLoaded', function() {
    const list = document.getElementById('messageList');
    if (list) {
        list.scrollTop = list.scrollHeight;
    }
});
</script>

<?php else: ?>
<!-- 取引一覧ページ -->
<div class="max-w-4xl mx-auto px-4 py-6">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">
        <i class="fas fa-handshake text-green-500 mr-2"></i>取引一覧
    </h1>
    
    <!-- フィルター -->
    <div class="flex gap-2 mb-6 overflow-x-auto pb-2">
        <a href="?status=" class="px-4 py-2 rounded-full text-sm font-bold whitespace-nowrap <?= !$status ? 'bg-green-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">
            すべて
        </a>
        <a href="?status=inquiry" class="px-4 py-2 rounded-full text-sm font-bold whitespace-nowrap <?= $status === 'inquiry' ? 'bg-yellow-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">
            問い合わせ中
        </a>
        <a href="?status=quote_sent" class="px-4 py-2 rounded-full text-sm font-bold whitespace-nowrap <?= $status === 'quote_sent' ? 'bg-blue-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">
            見積もり確認待ち
        </a>
        <a href="?status=in_progress" class="px-4 py-2 rounded-full text-sm font-bold whitespace-nowrap <?= $status === 'in_progress' ? 'bg-green-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">
            制作中
        </a>
        <a href="?status=completed" class="px-4 py-2 rounded-full text-sm font-bold whitespace-nowrap <?= $status === 'completed' ? 'bg-gray-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200' ?>">
            完了
        </a>
    </div>
    
    <!-- 取引リスト -->
    <?php if (empty($transactions)): ?>
    <div class="text-center py-16 bg-white rounded-xl shadow-sm border border-gray-100">
        <i class="fas fa-handshake text-gray-300 text-5xl mb-4"></i>
        <p class="text-gray-500">取引履歴がありません</p>
        <a href="/store/services/" class="inline-block mt-4 px-6 py-2 bg-green-500 text-white rounded-lg font-bold hover:bg-green-600">
            サービスを探す
        </a>
    </div>
    <?php else: ?>
    <div class="space-y-4">
        <?php foreach ($transactions as $t): ?>
        <a href="/store/transactions/<?= htmlspecialchars($t['transaction_code']) ?>" 
           class="block bg-white rounded-xl shadow-sm border border-gray-100 p-4 hover:shadow-md transition">
            <div class="flex items-start gap-4">
                <!-- サムネイル -->
                <div class="w-16 h-16 bg-gray-100 rounded-lg overflow-hidden flex-shrink-0">
                    <?php if (!empty($t['service_image'])): ?>
                    <img src="/<?= htmlspecialchars($t['service_image']) ?>" class="w-full h-full object-cover">
                    <?php else: ?>
                    <div class="w-full h-full flex items-center justify-center">
                        <i class="fas fa-paint-brush text-gray-300"></i>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="flex-1 min-w-0">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <h3 class="font-bold text-gray-800 truncate"><?= htmlspecialchars($t['service_title']) ?></h3>
                            <p class="text-sm text-gray-500"><?= htmlspecialchars($t['creator_name']) ?></p>
                        </div>
                        <span class="px-2 py-1 rounded-full text-xs font-bold flex-shrink-0 <?= getTransactionStatusColor($t['status']) ?>">
                            <?= getTransactionStatusLabel($t['status']) ?>
                        </span>
                    </div>
                    
                    <div class="flex items-center gap-4 mt-2 text-sm text-gray-500">
                        <span><?= htmlspecialchars($t['transaction_code']) ?></span>
                        <span><?= date('Y/n/j', strtotime($t['created_at'])) ?></span>
                        <?php if (!empty($t['total_amount'])): ?>
                        <span class="text-green-600 font-bold"><?= formatPrice($t['total_amount']) ?></span>
                        <?php endif; ?>
                        <?php if ($t['unread_count'] > 0): ?>
                        <span class="bg-red-500 text-white text-xs px-2 py-0.5 rounded-full">
                            <?= $t['unread_count'] ?>件の新着
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
                
                <i class="fas fa-chevron-right text-gray-300"></i>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php require_once '../includes/footer.php'; ?>
