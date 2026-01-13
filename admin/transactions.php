<?php
/**
 * 取引管理（管理画面）
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/site-settings.php';
require_once '../includes/transactions.php';
requireAuth();

$db = getDB();
$message = '';
$error = '';

// 取引詳細表示
$transactionId = $_GET['id'] ?? null;
$transaction = null;
$messages = [];
$quotes = [];

if ($transactionId) {
    $transaction = getTransaction((int)$transactionId);
    if ($transaction) {
        $messages = getTransactionMessages($transaction['id'], 'admin');
        $quotes = getTransactionQuotes($transaction['id']);
        
        // 管理者として既読に
        markMessagesAsRead($transaction['id'], 'admin');
    }
}

// メッセージ送信
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_message'])) {
    if (validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $txId = (int)$_POST['transaction_id'];
        $messageText = trim($_POST['message'] ?? '');
        $sendTo = $_POST['send_to'] ?? 'both'; // both, customer, creator
        
        if (!empty($messageText)) {
            // 公開範囲を設定
            $visibleToCustomer = in_array($sendTo, ['both', 'customer']) ? 1 : 0;
            $visibleToCreator = in_array($sendTo, ['both', 'creator']) ? 1 : 0;
            
            $stmt = $db->prepare("
                INSERT INTO service_messages (
                    transaction_id, sender_type, sender_name, message, message_type,
                    visible_to_customer, visible_to_creator, visible_to_admin
                ) VALUES (?, 'admin', '運営', ?, 'text', ?, ?, 1)
            ");
            $stmt->execute([$txId, $messageText, $visibleToCustomer, $visibleToCreator]);
            
            // 通知メール
            if ($visibleToCustomer) {
                sendTransactionEmail($txId, 'message_received', 'customer');
            }
            if ($visibleToCreator) {
                sendTransactionEmail($txId, 'message_received', 'creator');
            }
            
            $message = 'メッセージを送信しました。';
        }
    }
    header('Location: transactions.php?id=' . $txId . '&sent=1');
    exit;
}

// 見積もり作成（クリエイター代理）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_quote'])) {
    if (validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $txId = (int)$_POST['transaction_id'];
        
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
        
        $taxRate = (float)($_POST['tax_rate'] ?? 10);
        $taxAmount = floor($subtotal * $taxRate / 100);
        $totalAmount = $subtotal + $taxAmount;
        $estimatedDays = (int)($_POST['estimated_days'] ?? 7);
        $notes = trim($_POST['quote_notes'] ?? '');
        
        $quoteId = createQuote($txId, [
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
        updateTransactionStatus($txId, 'quote_sent');
        
        // システムメッセージを追加
        addTransactionMessage($txId, 'admin', null, '運営', '見積もりを送信しました。', 'quote', $quoteId);
        
        // 顧客にメール通知
        sendTransactionEmail($txId, 'quote_sent', 'customer');
        
        $message = '見積もりを作成・送信しました。';
        header('Location: transactions.php?id=' . $txId . '&quote_sent=1');
        exit;
    }
}

// ステータス更新
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_status'])) {
    if (validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $txId = (int)$_POST['transaction_id'];
        $newStatus = $_POST['new_status'];
        
        updateTransactionStatus($txId, $newStatus);
        $message = 'ステータスを更新しました。';
        header('Location: transactions.php?id=' . $txId);
        exit;
    }
}

// キャンセル処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['cancel_transaction'])) {
    if (validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $txId = (int)$_POST['transaction_id'];
        $reason = trim($_POST['cancel_reason'] ?? '');
        
        $result = cancelTransaction($txId, 'admin', $reason);
        
        if ($result['success']) {
            $message = '取引をキャンセルしました。';
        } else {
            $error = $result['error'];
        }
        
        header('Location: transactions.php?id=' . $txId . ($error ? '&error=' . urlencode($error) : '&cancelled=1'));
        exit;
    }
}

// 返金処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['refund_transaction'])) {
    if (validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $txId = (int)$_POST['transaction_id'];
        $refundAmount = !empty($_POST['refund_amount']) ? (int)$_POST['refund_amount'] : null;
        $reason = trim($_POST['refund_reason'] ?? '');
        
        $result = refundTransaction($txId, $refundAmount, $reason);
        
        if ($result['success']) {
            $message = '返金処理が完了しました。';
        } else {
            $error = $result['error'];
        }
        
        header('Location: transactions.php?id=' . $txId . ($error ? '&error=' . urlencode($error) : '&refunded=1'));
        exit;
    }
}

// ゲストアクセスURL生成
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_guest_url'])) {
    if (validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $txId = (int)$_POST['transaction_id'];
        
        $token = generateGuestAccessToken($txId);
        $guestUrl = 'https://tokagemushi.jp/store/transactions/guest.php?token=' . $token;
        
        $message = 'ゲストアクセスURLを生成しました。';
        header('Location: transactions.php?id=' . $txId . '&guest_url_generated=1');
        exit;
    }
}

// 一覧取得
$statusFilter = $_GET['status'] ?? '';
$sql = "
    SELECT t.*, 
           s.title as service_title,
           c.name as creator_name,
           COALESCE(m.name, t.guest_name) as customer_name,
           (SELECT COUNT(*) FROM service_messages WHERE transaction_id = t.id AND read_by_admin = 0) as unread_count
    FROM service_transactions t
    LEFT JOIN services s ON t.service_id = s.id
    LEFT JOIN creators c ON t.creator_id = c.id
    LEFT JOIN members m ON t.member_id = m.id
";
$params = [];
if ($statusFilter) {
    $sql .= " WHERE t.status = ?";
    $params[] = $statusFilter;
}
$sql .= " ORDER BY t.updated_at DESC";

$stmt = $db->prepare($sql);
$stmt->execute($params);
$transactions = $stmt->fetchAll();

// 統計
$stats = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN status IN ('inquiry', 'quote_pending', 'quote_revision') THEN 1 ELSE 0 END) as pending,
        SUM(CASE WHEN status = 'quote_sent' THEN 1 ELSE 0 END) as quote_sent,
        SUM(CASE WHEN status IN ('paid', 'in_progress', 'revision_requested') THEN 1 ELSE 0 END) as in_progress,
        SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) as delivered,
        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed
    FROM service_transactions
")->fetch();

$pageTitle = '取引管理';
include 'includes/header.php';
?>

<?php if ($transaction): ?>
<!-- 取引詳細 -->
<div class="mb-6">
    <a href="transactions.php" class="text-gray-500 hover:text-gray-700">
        <i class="fas fa-arrow-left mr-1"></i>一覧に戻る
    </a>
</div>

<?php if (isset($_GET['sent']) || isset($_GET['quote_sent'])): ?>
<div class="bg-green-50 border border-green-200 text-green-700 p-4 rounded-lg mb-6">
    <i class="fas fa-check-circle mr-2"></i><?= isset($_GET['quote_sent']) ? '見積もりを送信しました。' : 'メッセージを送信しました。' ?>
</div>
<?php endif; ?>

<div class="grid lg:grid-cols-3 gap-6">
    <!-- 左側：メッセージ -->
    <div class="lg:col-span-2 space-y-6">
        <!-- 取引情報ヘッダー -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <div class="flex items-start justify-between">
                <div>
                    <h2 class="text-xl font-bold text-gray-800"><?= htmlspecialchars($transaction['service_title']) ?></h2>
                    <p class="text-gray-500 text-sm"><?= htmlspecialchars($transaction['transaction_code']) ?></p>
                </div>
                <span class="px-3 py-1 rounded-full text-sm font-bold <?= getTransactionStatusColor($transaction['status']) ?>">
                    <?= getTransactionStatusLabel($transaction['status']) ?>
                </span>
            </div>
            
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mt-4 text-sm">
                <div>
                    <span class="text-gray-500">顧客</span>
                    <div class="font-medium"><?= htmlspecialchars($transaction['member_name'] ?? $transaction['guest_name'] ?? '-') ?></div>
                </div>
                <div>
                    <span class="text-gray-500">クリエイター</span>
                    <div class="font-medium"><?= htmlspecialchars($transaction['creator_name']) ?></div>
                </div>
                <div>
                    <span class="text-gray-500">金額</span>
                    <div class="font-medium text-green-600"><?= $transaction['total_amount'] ? '¥' . number_format($transaction['total_amount']) : '-' ?></div>
                </div>
                <div>
                    <span class="text-gray-500">作成日</span>
                    <div class="font-medium"><?= date('Y/n/j', strtotime($transaction['created_at'])) ?></div>
                </div>
            </div>
        </div>
        
        <!-- 見積もり作成（未送信の場合） -->
        <?php if (in_array($transaction['status'], ['inquiry', 'quote_pending', 'quote_revision'])): ?>
        <div class="bg-white rounded-xl shadow-sm border border-orange-200 p-6">
            <h3 class="font-bold text-gray-800 mb-4">
                <i class="fas fa-file-invoice text-orange-500 mr-2"></i>見積もり作成
            </h3>
            
            <form method="POST" id="quoteForm">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="transaction_id" value="<?= $transaction['id'] ?>">
                
                <div id="quoteItems" class="space-y-2 mb-4">
                    <div class="flex gap-2 quote-item">
                        <input type="text" name="item_name[]" placeholder="項目名" required
                               class="flex-1 px-3 py-2 border rounded-lg">
                        <input type="number" name="item_price[]" placeholder="金額" required min="0"
                               class="w-32 px-3 py-2 border rounded-lg">
                        <button type="button" onclick="removeQuoteItem(this)" class="text-red-500 px-2">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                </div>
                
                <button type="button" onclick="addQuoteItem()" class="text-blue-500 text-sm mb-4">
                    <i class="fas fa-plus mr-1"></i>項目を追加
                </button>
                
                <div class="grid md:grid-cols-3 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">税率（%）</label>
                        <input type="number" name="tax_rate" value="10" min="0" max="100"
                               class="w-full px-3 py-2 border rounded-lg">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">納品予定日数</label>
                        <input type="number" name="estimated_days" value="7" min="1"
                               class="w-full px-3 py-2 border rounded-lg">
                    </div>
                </div>
                
                <div class="mb-4">
                    <label class="block text-sm font-bold text-gray-700 mb-1">備考</label>
                    <textarea name="quote_notes" rows="3"
                              class="w-full px-3 py-2 border rounded-lg"></textarea>
                </div>
                
                <button type="submit" name="create_quote" value="1"
                        class="px-6 py-2 bg-orange-500 text-white font-bold rounded-lg hover:bg-orange-600">
                    <i class="fas fa-paper-plane mr-1"></i>見積もりを送信
                </button>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- メッセージ一覧 -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100">
            <div class="p-4 border-b">
                <h3 class="font-bold text-gray-800">
                    <i class="fas fa-comments text-purple-500 mr-2"></i>メッセージ
                </h3>
            </div>
            
            <div class="p-4 space-y-4 max-h-[400px] overflow-y-auto">
                <?php if (empty($messages)): ?>
                <p class="text-gray-500 text-center py-8">まだメッセージはありません</p>
                <?php else: ?>
                <?php foreach ($messages as $msg): ?>
                <div class="p-3 rounded-lg <?= $msg['sender_type'] === 'customer' ? 'bg-blue-50' : ($msg['sender_type'] === 'creator' ? 'bg-green-50' : 'bg-purple-50') ?>">
                    <div class="flex items-center gap-2 mb-1">
                        <span class="text-xs font-bold <?= $msg['sender_type'] === 'customer' ? 'text-blue-700' : ($msg['sender_type'] === 'creator' ? 'text-green-700' : 'text-purple-700') ?>">
                            <?php if ($msg['sender_type'] === 'customer'): ?>
                            <i class="fas fa-user mr-1"></i>顧客
                            <?php elseif ($msg['sender_type'] === 'creator'): ?>
                            <i class="fas fa-palette mr-1"></i>クリエイター
                            <?php else: ?>
                            <i class="fas fa-crown mr-1"></i>運営
                            <?php endif; ?>
                            <?= htmlspecialchars($msg['sender_name']) ?>
                        </span>
                        <span class="text-xs text-gray-400"><?= date('Y/n/j H:i', strtotime($msg['created_at'])) ?></span>
                        
                        <!-- 公開範囲表示 -->
                        <span class="text-xs text-gray-400">
                            [<?= $msg['visible_to_customer'] ? '顧客○' : '顧客×' ?> / <?= $msg['visible_to_creator'] ? 'クリエイター○' : 'クリエイター×' ?>]
                        </span>
                    </div>
                    <p class="text-sm text-gray-800 whitespace-pre-wrap"><?= nl2br(htmlspecialchars($msg['message'])) ?></p>
                    
                    <?php if (!empty($msg['attachments'])): ?>
                    <div class="mt-2 space-y-1">
                        <?php foreach (explode('||', $msg['attachments']) as $att): 
                            $parts = explode(':', $att);
                            if (count($parts) >= 4):
                                list($attId, $attName, $attPath, $attType) = $parts;
                        ?>
                        <a href="../<?= htmlspecialchars($attPath) ?>" target="_blank" 
                           class="flex items-center gap-2 text-xs text-blue-600 hover:text-blue-800 bg-white p-2 rounded">
                            <i class="fas fa-<?= $attType === 'image' ? 'image' : 'file' ?>"></i>
                            <?= htmlspecialchars($attName) ?>
                        </a>
                        <?php endif; endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
            
            <!-- 運営からメッセージ送信 -->
            <form method="POST" class="p-4 border-t">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="transaction_id" value="<?= $transaction['id'] ?>">
                
                <div class="mb-3">
                    <label class="block text-sm font-bold text-gray-700 mb-1">送信先</label>
                    <div class="flex gap-4">
                        <label class="flex items-center gap-2">
                            <input type="radio" name="send_to" value="both" checked>
                            <span class="text-sm">両方</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="radio" name="send_to" value="customer">
                            <span class="text-sm">顧客のみ</span>
                        </label>
                        <label class="flex items-center gap-2">
                            <input type="radio" name="send_to" value="creator">
                            <span class="text-sm">クリエイターのみ</span>
                        </label>
                    </div>
                </div>
                
                <textarea name="message" rows="2" required
                          placeholder="運営からのメッセージ..."
                          class="w-full px-4 py-2 border rounded-lg mb-3"></textarea>
                
                <button type="submit" name="send_message" value="1"
                        class="px-4 py-2 bg-purple-500 text-white font-bold rounded-lg hover:bg-purple-600">
                    <i class="fas fa-paper-plane mr-1"></i>送信
                </button>
            </form>
        </div>
    </div>
    
    <!-- 右側：ステータス管理 -->
    <div class="space-y-4">
        <!-- ステータス変更 -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <h4 class="font-bold text-gray-800 mb-3">ステータス変更</h4>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="transaction_id" value="<?= $transaction['id'] ?>">
                
                <select name="new_status" class="w-full px-3 py-2 border rounded-lg mb-3">
                    <?php
                    $statuses = [
                        'inquiry' => '問い合わせ中',
                        'quote_pending' => '見積もり待ち',
                        'quote_sent' => '見積もり送信済み',
                        'quote_revision' => '見積もり修正依頼',
                        'quote_accepted' => '見積もり承諾',
                        'payment_pending' => '決済待ち',
                        'paid' => '決済完了',
                        'in_progress' => '制作中',
                        'delivered' => '納品済み',
                        'revision_requested' => '修正依頼',
                        'completed' => '完了',
                        'cancelled' => 'キャンセル',
                        'refunded' => '返金済み'
                    ];
                    foreach ($statuses as $value => $label):
                    ?>
                    <option value="<?= $value ?>" <?= $transaction['status'] === $value ? 'selected' : '' ?>>
                        <?= $label ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                
                <button type="submit" name="update_status" value="1"
                        class="w-full py-2 bg-blue-500 text-white font-bold rounded-lg hover:bg-blue-600">
                    更新
                </button>
            </form>
        </div>
        
        <!-- 連絡先情報 -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <h4 class="font-bold text-gray-800 mb-3">連絡先</h4>
            <div class="space-y-3 text-sm">
                <div>
                    <span class="text-gray-500">顧客メール</span>
                    <div class="font-medium"><?= htmlspecialchars($transaction['member_email'] ?? $transaction['guest_email'] ?? '-') ?></div>
                </div>
                <div>
                    <span class="text-gray-500">クリエイターメール</span>
                    <div class="font-medium"><?= htmlspecialchars($transaction['creator_email'] ?? '-') ?></div>
                </div>
            </div>
        </div>
        
        <!-- 依頼内容 -->
        <?php if (!empty($transaction['request_detail'])): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <h4 class="font-bold text-gray-800 mb-3">依頼内容</h4>
            <div class="text-sm text-gray-700 whitespace-pre-wrap"><?= nl2br(htmlspecialchars($transaction['request_detail'])) ?></div>
        </div>
        <?php endif; ?>
        
        <!-- 見積もり履歴 -->
        <?php if (!empty($quotes)): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <h4 class="font-bold text-gray-800 mb-3">見積もり履歴</h4>
            <div class="space-y-2">
                <?php foreach ($quotes as $q): ?>
                <div class="p-2 bg-gray-50 rounded">
                    <div class="flex justify-between text-sm">
                        <span>v<?= $q['version'] ?></span>
                        <span class="font-bold">¥<?= number_format($q['total_amount']) ?></span>
                    </div>
                    <div class="text-xs text-gray-500"><?= $q['status'] ?> - <?= date('Y/n/j H:i', strtotime($q['created_at'])) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- ゲストアクセスURL -->
        <?php if (!empty($transaction['guest_email']) && empty($transaction['member_id'])): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
            <h4 class="font-bold text-gray-800 mb-3">
                <i class="fas fa-link text-blue-500 mr-1"></i>ゲストアクセス
            </h4>
            <?php if (!empty($transaction['guest_access_token']) && strtotime($transaction['guest_token_expires']) > time()): ?>
            <div class="text-xs mb-2">
                <input type="text" readonly 
                       value="https://tokagemushi.jp/store/transactions/guest.php?token=<?= htmlspecialchars($transaction['guest_access_token']) ?>"
                       class="w-full px-2 py-1 bg-gray-50 border rounded text-gray-600"
                       onclick="this.select()">
                <p class="text-gray-500 mt-1">有効期限: <?= date('Y/n/j H:i', strtotime($transaction['guest_token_expires'])) ?></p>
            </div>
            <?php endif; ?>
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="transaction_id" value="<?= $transaction['id'] ?>">
                <button type="submit" name="generate_guest_url" value="1"
                        class="w-full py-2 bg-blue-500 text-white text-sm font-bold rounded-lg hover:bg-blue-600">
                    <i class="fas fa-sync-alt mr-1"></i>URL生成/更新
                </button>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- キャンセル・返金 -->
        <?php 
        $policy = getCancellationPolicy($transaction['status']);
        if ($policy['can_cancel'] || in_array($transaction['status'], ['paid', 'in_progress', 'delivered'])): 
        ?>
        <div class="bg-white rounded-xl shadow-sm border border-red-200 p-4">
            <h4 class="font-bold text-red-600 mb-3">
                <i class="fas fa-exclamation-triangle mr-1"></i>キャンセル・返金
            </h4>
            
            <?php if ($policy['can_cancel'] && !in_array($transaction['status'], ['paid', 'in_progress', 'delivered', 'completed', 'cancelled', 'refunded'])): ?>
            <!-- キャンセル（決済前） -->
            <form method="POST" class="mb-3">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="transaction_id" value="<?= $transaction['id'] ?>">
                <textarea name="cancel_reason" rows="2" placeholder="キャンセル理由..."
                          class="w-full px-3 py-2 border rounded-lg text-sm mb-2"></textarea>
                <button type="submit" name="cancel_transaction" value="1"
                        onclick="return confirm('この取引をキャンセルしますか？')"
                        class="w-full py-2 bg-red-500 text-white text-sm font-bold rounded-lg hover:bg-red-600">
                    キャンセル
                </button>
            </form>
            <?php endif; ?>
            
            <?php if (in_array($transaction['status'], ['paid', 'in_progress', 'delivered']) && !empty($transaction['payment_id'])): ?>
            <!-- 返金（決済後） -->
            <form method="POST">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="transaction_id" value="<?= $transaction['id'] ?>">
                <div class="mb-2">
                    <label class="text-xs text-gray-500">返金額（空欄で全額）</label>
                    <input type="number" name="refund_amount" placeholder="<?= $transaction['total_amount'] ?>"
                           max="<?= $transaction['total_amount'] ?>" min="1"
                           class="w-full px-3 py-2 border rounded-lg text-sm">
                </div>
                <textarea name="refund_reason" rows="2" placeholder="返金理由..."
                          class="w-full px-3 py-2 border rounded-lg text-sm mb-2"></textarea>
                <button type="submit" name="refund_transaction" value="1"
                        onclick="return confirm('返金処理を実行しますか？この操作は取り消せません。')"
                        class="w-full py-2 bg-orange-500 text-white text-sm font-bold rounded-lg hover:bg-orange-600">
                    <i class="fas fa-undo mr-1"></i>返金処理
                </button>
                <p class="text-xs text-gray-500 mt-1">※Stripe経由で自動返金されます</p>
            </form>
            <?php endif; ?>
            
            <?php if ($transaction['status'] === 'cancelled'): ?>
            <p class="text-sm text-red-600">この取引はキャンセル済みです</p>
            <?php if (!empty($transaction['cancel_reason'])): ?>
            <p class="text-xs text-gray-500 mt-1">理由: <?= htmlspecialchars($transaction['cancel_reason']) ?></p>
            <?php endif; ?>
            <?php endif; ?>
            
            <?php if ($transaction['status'] === 'refunded'): ?>
            <p class="text-sm text-orange-600">この取引は返金済みです</p>
            <p class="text-xs text-gray-500 mt-1">返金額: ¥<?= number_format($transaction['refund_amount'] ?? 0) ?></p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function addQuoteItem() {
    const container = document.getElementById('quoteItems');
    const div = document.createElement('div');
    div.className = 'flex gap-2 quote-item';
    div.innerHTML = `
        <input type="text" name="item_name[]" placeholder="項目名" required class="flex-1 px-3 py-2 border rounded-lg">
        <input type="number" name="item_price[]" placeholder="金額" required min="0" class="w-32 px-3 py-2 border rounded-lg">
        <button type="button" onclick="removeQuoteItem(this)" class="text-red-500 px-2"><i class="fas fa-times"></i></button>
    `;
    container.appendChild(div);
}

function removeQuoteItem(btn) {
    const items = document.querySelectorAll('.quote-item');
    if (items.length > 1) {
        btn.closest('.quote-item').remove();
    }
}
</script>

<?php else: ?>
<!-- 取引一覧 -->
<?= renderPageHeader('取引管理', 'サービス取引を管理します') ?>

<!-- 統計 -->
<?= renderTransactionStats($stats) ?>

<!-- フィルター -->
<?php
$filters = [
    '' => ['label' => 'すべて', 'color' => 'green'],
    'pending' => ['label' => '対応待ち', 'color' => 'yellow', 'count' => $stats['pending'] ?? 0],
    'quote_sent' => ['label' => '見積もり中', 'color' => 'blue', 'count' => $stats['quote_sent'] ?? 0],
    'in_progress' => ['label' => '制作中', 'color' => 'green', 'count' => $stats['in_progress'] ?? 0],
    'delivered' => ['label' => '納品済', 'color' => 'purple', 'count' => $stats['delivered'] ?? 0],
    'completed' => ['label' => '完了', 'color' => 'gray'],
];
echo renderFilterTabs($filters, $statusFilter);
?>

<!-- 取引一覧 -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <table class="w-full">
        <thead class="bg-gray-50"><tr>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">取引コード</th>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600 hidden md:table-cell">サービス</th>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">顧客</th>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600 hidden lg:table-cell">クリエイター</th>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">ステータス</th>
            <th class="px-4 py-3 text-right text-sm font-bold text-gray-600 hidden sm:table-cell">金額</th>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600 hidden lg:table-cell">開始日</th>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600 hidden lg:table-cell">納品予定</th>
            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600 hidden md:table-cell">更新日</th>
        </tr></thead>
        <tbody class="divide-y divide-gray-100">
            <?php if (empty($transactions)): ?>
            <?= renderEmptyRow(9, '該当する取引がありません') ?>
            <?php endif; ?>
            <?php foreach ($transactions as $t): ?>
            <?= renderTransactionRow($t, true) ?>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
