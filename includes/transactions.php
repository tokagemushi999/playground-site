<?php
/**
 * 取引・メッセージ関連のヘルパー関数
 */

// メール送信関数が必要
require_once __DIR__ . '/mail.php';
require_once __DIR__ . '/formatting.php';

/**
 * 取引コード生成
 */
function generateTransactionCode() {
    return 'ST-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));
}

/**
 * 新規取引を作成
 */
function createTransaction($data) {
    $db = getDB();
    
    $code = generateTransactionCode();
    
    $stmt = $db->prepare("
        INSERT INTO service_transactions (
            transaction_code, service_id, creator_id, member_id,
            guest_email, guest_name, status,
            request_title, request_detail, request_budget, request_deadline
        ) VALUES (?, ?, ?, ?, ?, ?, 'inquiry', ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $code,
        $data['service_id'],
        $data['creator_id'],
        $data['member_id'] ?? null,
        $data['guest_email'] ?? null,
        $data['guest_name'] ?? null,
        $data['request_title'] ?? null,
        $data['request_detail'] ?? null,
        $data['request_budget'] ?? null,
        $data['request_deadline'] ?? null
    ]);
    
    return [
        'id' => $db->lastInsertId(),
        'code' => $code
    ];
}

/**
 * 取引を取得
 */
function getTransaction($id) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT t.*, 
               s.title as service_title, s.thumbnail_image as service_image,
               c.name as creator_name, c.image as creator_image, c.email as creator_email,
               m.name as member_name, m.email as member_email
        FROM service_transactions t
        LEFT JOIN services s ON t.service_id = s.id
        LEFT JOIN creators c ON t.creator_id = c.id
        LEFT JOIN members m ON t.member_id = m.id
        WHERE t.id = ?
    ");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * 取引コードで取得
 */
function getTransactionByCode($code) {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT t.*, 
               s.title as service_title, s.thumbnail_image as service_image,
               c.name as creator_name, c.image as creator_image, c.email as creator_email,
               m.name as member_name, m.email as member_email
        FROM service_transactions t
        LEFT JOIN services s ON t.service_id = s.id
        LEFT JOIN creators c ON t.creator_id = c.id
        LEFT JOIN members m ON t.member_id = m.id
        WHERE t.transaction_code = ?
    ");
    $stmt->execute([$code]);
    return $stmt->fetch();
}

/**
 * 取引一覧を取得（顧客用）
 */
function getMemberTransactions($memberId, $status = null) {
    $db = getDB();
    
    try {
        // service_messagesテーブルの存在確認
        $hasMessagesTable = false;
        try {
            $db->query("SELECT 1 FROM service_messages LIMIT 1");
            $hasMessagesTable = true;
        } catch (PDOException $e) {}
        
        $unreadSubquery = $hasMessagesTable 
            ? "(SELECT COUNT(*) FROM service_messages WHERE transaction_id = t.id AND read_by_customer = 0 AND sender_type != 'customer')"
            : "0";
        
        $sql = "
            SELECT t.*, 
                   s.title as service_title, s.thumbnail_image as service_image,
                   c.name as creator_name, c.image as creator_image,
                   $unreadSubquery as unread_count
            FROM service_transactions t
            LEFT JOIN services s ON t.service_id = s.id
            LEFT JOIN creators c ON t.creator_id = c.id
            WHERE t.member_id = ?
        ";
        $params = [$memberId];
        
        if ($status) {
            $sql .= " AND t.status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY t.updated_at DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("getMemberTransactions error: " . $e->getMessage());
        return [];
    }
}

/**
 * 取引一覧を取得（クリエイター用）
 */
function getCreatorTransactions($creatorId, $status = null) {
    $db = getDB();
    
    try {
        // service_messagesテーブルの存在確認
        $hasMessagesTable = false;
        try {
            $db->query("SELECT 1 FROM service_messages LIMIT 1");
            $hasMessagesTable = true;
        } catch (PDOException $e) {}
        
        $unreadSubquery = $hasMessagesTable 
            ? "(SELECT COUNT(*) FROM service_messages WHERE transaction_id = t.id AND read_by_creator = 0 AND sender_type != 'creator')"
            : "0";
        
        $sql = "
            SELECT t.*, 
                   s.title as service_title, s.thumbnail_image as service_image,
                   COALESCE(m.name, t.guest_name) as customer_name,
                   COALESCE(m.email, t.guest_email) as customer_email,
                   $unreadSubquery as unread_count
            FROM service_transactions t
            LEFT JOIN services s ON t.service_id = s.id
            LEFT JOIN members m ON t.member_id = m.id
            WHERE t.creator_id = ?
        ";
        $params = [$creatorId];
        
        if ($status) {
            $sql .= " AND t.status = ?";
            $params[] = $status;
        }
        
        $sql .= " ORDER BY t.updated_at DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("getCreatorTransactions error: " . $e->getMessage());
        return [];
    }
}

/**
 * メッセージを追加
 */
function addTransactionMessage($transactionId, $senderType, $senderId, $senderName, $message, $messageType = 'text', $quoteId = null) {
    $db = getDB();
    
    // 公開範囲の設定
    $visibleToCustomer = 1;
    $visibleToCreator = 1;
    $visibleToAdmin = 1;
    
    // 運営→顧客のみの場合
    // $visibleToCreator = 0;
    // 運営→クリエイターのみの場合
    // $visibleToCustomer = 0;
    
    $stmt = $db->prepare("
        INSERT INTO service_messages (
            transaction_id, sender_type, sender_id, sender_name,
            message, message_type, quote_id,
            visible_to_customer, visible_to_creator, visible_to_admin
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $transactionId,
        $senderType,
        $senderId,
        $senderName,
        $message,
        $messageType,
        $quoteId,
        $visibleToCustomer,
        $visibleToCreator,
        $visibleToAdmin
    ]);
    
    // 取引の更新日時を更新
    $stmt = $db->prepare("UPDATE service_transactions SET updated_at = NOW() WHERE id = ?");
    $stmt->execute([$transactionId]);
    
    return $db->lastInsertId();
}

/**
 * メッセージ一覧を取得
 */
function getTransactionMessages($transactionId, $viewerType = 'admin') {
    $db = getDB();
    
    // テーブル構造を確認してクエリを構築
    $hasVisibleColumns = true;
    $hasAttachmentsTable = true;
    
    try {
        // service_messagesのカラム確認
        $columns = $db->query("SHOW COLUMNS FROM service_messages")->fetchAll(PDO::FETCH_COLUMN);
        $hasVisibleColumns = in_array('visible_to_customer', $columns);
    } catch (PDOException $e) {
        $hasVisibleColumns = false;
    }
    
    try {
        // service_attachmentsのカラム確認
        $attColumns = $db->query("SHOW COLUMNS FROM service_attachments")->fetchAll(PDO::FETCH_COLUMN);
        $hasAttachmentsTable = in_array('original_name', $attColumns);
    } catch (PDOException $e) {
        $hasAttachmentsTable = false;
    }
    
    // クエリを構築
    if ($hasVisibleColumns) {
        $visibleColumn = match($viewerType) {
            'customer' => 'visible_to_customer',
            'creator' => 'visible_to_creator',
            default => 'visible_to_admin'
        };
        $whereClause = "AND m.$visibleColumn = 1";
    } else {
        $whereClause = "";
    }
    
    if ($hasAttachmentsTable) {
        $attachmentSubquery = "(SELECT GROUP_CONCAT(CONCAT(id, ':', original_name, ':', file_path, ':', file_type) SEPARATOR '||') 
                FROM service_attachments WHERE message_id = m.id) as attachments";
    } else {
        $attachmentSubquery = "NULL as attachments";
    }
    
    $sql = "
        SELECT m.*, $attachmentSubquery
        FROM service_messages m
        WHERE m.transaction_id = ? $whereClause
        ORDER BY m.created_at ASC
    ";
    
    $stmt = $db->prepare($sql);
    $stmt->execute([$transactionId]);
    return $stmt->fetchAll();
}

/**
 * メッセージを既読にする
 */
function markMessagesAsRead($transactionId, $readerType) {
    $db = getDB();
    
    // カラムの存在確認
    try {
        $columns = $db->query("SHOW COLUMNS FROM service_messages")->fetchAll(PDO::FETCH_COLUMN);
        if (!in_array('read_by_customer', $columns)) {
            return; // カラムがなければスキップ
        }
    } catch (PDOException $e) {
        return;
    }
    
    $column = match($readerType) {
        'customer' => 'read_by_customer',
        'creator' => 'read_by_creator',
        default => 'read_by_admin'
    };
    $timeColumn = match($readerType) {
        'customer' => 'read_at_customer',
        'creator' => 'read_at_creator',
        default => null
    };
    
    try {
        $sql = "UPDATE service_messages SET $column = 1";
        if ($timeColumn && in_array($timeColumn, $columns)) {
            $sql .= ", $timeColumn = NOW()";
        }
        $sql .= " WHERE transaction_id = ? AND $column = 0";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$transactionId]);
    } catch (PDOException $e) {
        // エラーは無視
    }
}

/**
 * ファイルを添付
 */
function addTransactionAttachment($messageId, $transactionId, $fileData) {
    $db = getDB();
    
    try {
        $stmt = $db->prepare("
            INSERT INTO service_attachments (
                message_id, transaction_id,
                original_name, stored_name, file_path,
                file_size, mime_type, file_type, is_deliverable
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $messageId,
            $transactionId,
            $fileData['original_name'],
            $fileData['stored_name'],
            $fileData['file_path'],
            $fileData['file_size'],
            $fileData['mime_type'],
            $fileData['file_type'],
            $fileData['is_deliverable'] ?? 0
        ]);
        
        return $db->lastInsertId();
    } catch (PDOException $e) {
        // テーブル構造に問題がある場合はスキップ
        error_log("addTransactionAttachment error: " . $e->getMessage());
        return null;
    }
}

/**
 * ファイルタイプを判定
 */
function detectFileType($mimeType) {
    if (str_starts_with($mimeType, 'image/')) return 'image';
    if (str_starts_with($mimeType, 'video/')) return 'video';
    if (str_starts_with($mimeType, 'audio/')) return 'audio';
    if (in_array($mimeType, ['application/pdf', 'application/msword', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'text/plain'])) return 'document';
    if (in_array($mimeType, ['application/zip', 'application/x-rar-compressed', 'application/x-7z-compressed'])) return 'archive';
    return 'other';
}

/**
 * 見積もりを作成
 */
function createQuote($transactionId, $data) {
    $db = getDB();
    
    try {
        // 現在のバージョンを取得
        $stmt = $db->prepare("SELECT MAX(version) FROM service_quotes WHERE transaction_id = ?");
        $stmt->execute([$transactionId]);
        $currentVersion = $stmt->fetchColumn() ?: 0;
        
        $stmt = $db->prepare("
            INSERT INTO service_quotes (
                transaction_id, version,
                quote_items, subtotal, tax_rate, tax_amount, total_amount,
                estimated_days, estimated_deadline, notes, status
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'draft')
        ");
        
        $stmt->execute([
            $transactionId,
            $currentVersion + 1,
            json_encode($data['items'] ?? []),
            $data['subtotal'],
            $data['tax_rate'] ?? 10,
            $data['tax_amount'],
            $data['total_amount'],
            $data['estimated_days'] ?? null,
            $data['estimated_deadline'] ?? null,
            $data['notes'] ?? null
        ]);
        
        return $db->lastInsertId();
    } catch (PDOException $e) {
        // テーブル構造が異なる場合
        error_log("createQuote error: " . $e->getMessage());
        return false;
    }
}

/**
 * 見積もりを取得
 */
function getQuote($quoteId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM service_quotes WHERE id = ?");
    $stmt->execute([$quoteId]);
    $quote = $stmt->fetch();
    if ($quote && $quote['quote_items']) {
        $quote['items'] = json_decode($quote['quote_items'], true);
    }
    return $quote;
}

/**
 * 取引の見積もり一覧
 */
function getTransactionQuotes($transactionId) {
    $db = getDB();
    try {
        // 新しいテーブル構造（transaction_id）を試す
        $stmt = $db->prepare("SELECT * FROM service_quotes WHERE transaction_id = ? ORDER BY version DESC");
        $stmt->execute([$transactionId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        // カラムが存在しない場合は空配列を返す
        return [];
    }
}

/**
 * 取引ステータスを更新
 */
function updateTransactionStatus($transactionId, $status, $additionalData = []) {
    $db = getDB();
    
    $sets = ['status = ?', 'updated_at = NOW()'];
    $params = [$status];
    
    foreach ($additionalData as $key => $value) {
        $sets[] = "$key = ?";
        $params[] = $value;
    }
    
    $params[] = $transactionId;
    
    $sql = "UPDATE service_transactions SET " . implode(', ', $sets) . " WHERE id = ?";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
}

/**
 * 取引関連のメール送信
 */
function sendTransactionEmail($transactionId, $type, $recipientType, $customData = []) {
    $db = getDB();
    require_once __DIR__ . '/mail.php';
    require_once __DIR__ . '/site-settings.php';
    
    $transaction = getTransaction($transactionId);
    if (!$transaction) return false;
    
    // 宛先を決定
    $recipientEmail = match($recipientType) {
        'customer' => $transaction['member_email'] ?? $transaction['guest_email'],
        'creator' => $transaction['creator_email'],
        'admin' => getSiteSetting($db, 'admin_email', 'admin@tokagemushi.jp'),
        default => null
    };
    
    if (!$recipientEmail) return false;
    
    $recipientName = match($recipientType) {
        'customer' => $transaction['member_name'] ?? $transaction['guest_name'],
        'creator' => $transaction['creator_name'],
        'admin' => '運営',
        default => ''
    };
    
    // 件名と本文を生成
    $subject = '';
    $body = '';
    $siteUrl = 'https://tokagemushi.jp';
    
    switch ($type) {
        case 'inquiry_received':
            $subject = "【ぷれぐら！】新しい見積もり依頼が届きました";
            $body = "{$recipientName}様\n\n";
            $body .= "新しい見積もり依頼が届きました。\n\n";
            $body .= "■ サービス: {$transaction['service_title']}\n";
            $body .= "■ 取引コード: {$transaction['transaction_code']}\n";
            $body .= "■ 依頼タイトル: {$transaction['request_title']}\n\n";
            $body .= "詳細を確認して、見積もりを作成してください。\n";
            $body .= "{$siteUrl}/store/transactions/{$transaction['transaction_code']}\n";
            break;
            
        case 'quote_sent':
            $subject = "【ぷれぐら！】見積もりが届きました";
            $body = "{$recipientName}様\n\n";
            $body .= "ご依頼いただいたサービスの見積もりが届きました。\n\n";
            $body .= "■ サービス: {$transaction['service_title']}\n";
            $body .= "■ 取引コード: {$transaction['transaction_code']}\n\n";
            $body .= "内容をご確認ください。\n";
            $body .= "{$siteUrl}/store/transactions/{$transaction['transaction_code']}\n";
            break;
            
        case 'message_received':
            $subject = "【ぷれぐら！】新しいメッセージが届きました";
            $body = "{$recipientName}様\n\n";
            $body .= "取引に関する新しいメッセージが届きました。\n\n";
            $body .= "■ 取引コード: {$transaction['transaction_code']}\n";
            $body .= "■ サービス: {$transaction['service_title']}\n\n";
            $body .= "メッセージを確認してください。\n";
            $body .= "{$siteUrl}/store/transactions/{$transaction['transaction_code']}\n";
            break;
            
        case 'payment_completed':
            $subject = "【ぷれぐら！】決済が完了しました";
            $body = "{$recipientName}様\n\n";
            $body .= "決済が完了しました。制作を開始してください。\n\n";
            $body .= "■ 取引コード: {$transaction['transaction_code']}\n";
            $body .= "■ サービス: {$transaction['service_title']}\n";
            $body .= "■ 金額: " . formatPrice($transaction['total_amount']) . "\n\n";
            break;
            
        default:
            return false;
    }
    
    $body .= "\n--\nぷれぐら！ PLAYGROUND\n{$siteUrl}\n";
    
    // 通知ログを保存
    try {
        $stmt = $db->prepare("
            INSERT INTO service_notifications (
                transaction_id, notification_type, recipient_type, recipient_email, subject, body
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([$transactionId, $type, $recipientType, $recipientEmail, $subject, $body]);
        $notificationId = $db->lastInsertId();
    } catch (PDOException $e) {
        $notificationId = null;
    }
    
    // メール送信
    $result = sendEmail($recipientEmail, $subject, $body);
    
    // 送信結果を更新
    if ($notificationId) {
        try {
            $stmt = $db->prepare("UPDATE service_notifications SET is_sent = ?, sent_at = NOW(), error_message = ? WHERE id = ?");
            $stmt->execute([$result ? 1 : 0, $result ? null : 'Send failed', $notificationId]);
        } catch (PDOException $e) {}
    }
    
    return $result;
}

/**
 * 取引ステータスのラベル
 */
function getTransactionStatusLabel($status) {
    return match($status) {
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
        'refunded' => '返金済み',
        default => $status
    };
}

/**
 * 取引ステータスの色
 */
function getTransactionStatusColor($status) {
    return match($status) {
        'inquiry', 'quote_pending' => 'bg-yellow-100 text-yellow-700',
        'quote_sent' => 'bg-blue-100 text-blue-700',
        'quote_revision' => 'bg-orange-100 text-orange-700',
        'quote_accepted', 'payment_pending' => 'bg-purple-100 text-purple-700',
        'paid', 'in_progress' => 'bg-green-100 text-green-700',
        'delivered' => 'bg-teal-100 text-teal-700',
        'revision_requested' => 'bg-pink-100 text-pink-700',
        'completed' => 'bg-gray-100 text-gray-700',
        'cancelled', 'refunded' => 'bg-red-100 text-red-700',
        default => 'bg-gray-100 text-gray-600'
    };
}

/**
 * 取引キャンセル
 */
function cancelTransaction($transactionId, $cancelledBy, $reason = '') {
    $db = getDB();
    
    $transaction = getTransaction($transactionId);
    if (!$transaction) {
        return ['success' => false, 'error' => '取引が見つかりません'];
    }
    
    // キャンセル可能なステータスを確認
    $cancellableStatuses = ['inquiry', 'quote_pending', 'quote_sent', 'quote_revision', 'quote_accepted', 'payment_pending'];
    if (!in_array($transaction['status'], $cancellableStatuses)) {
        return ['success' => false, 'error' => 'この取引はキャンセルできません（決済済みまたは既にキャンセル済み）'];
    }
    
    // キャンセル処理
    $stmt = $db->prepare("
        UPDATE service_transactions 
        SET status = 'cancelled', 
            cancelled_by = ?,
            cancel_reason = ?,
            cancelled_at = NOW(),
            updated_at = NOW()
        WHERE id = ?
    ");
    $stmt->execute([$cancelledBy, $reason, $transactionId]);
    
    // システムメッセージ追加
    $cancellerName = $cancelledBy === 'customer' ? '顧客' : ($cancelledBy === 'creator' ? 'クリエイター' : '運営');
    $message = "【取引キャンセル】\n{$cancellerName}により取引がキャンセルされました。";
    if ($reason) {
        $message .= "\n理由: {$reason}";
    }
    
    addTransactionMessage($transactionId, 'admin', null, '運営', $message, 'system');
    
    return ['success' => true];
}

/**
 * 返金処理（Stripe経由）
 */
function refundTransaction($transactionId, $refundAmount = null, $reason = '') {
    $db = getDB();
    
    $transaction = getTransaction($transactionId);
    if (!$transaction) {
        return ['success' => false, 'error' => '取引が見つかりません'];
    }
    
    // 決済済みでないと返金不可
    if (empty($transaction['payment_id'])) {
        return ['success' => false, 'error' => '決済情報がありません'];
    }
    
    // 既に返金済み
    if ($transaction['status'] === 'refunded') {
        return ['success' => false, 'error' => '既に返金済みです'];
    }
    
    try {
        require_once __DIR__ . '/stripe-config.php';
        $stripe = getStripeClient();
        
        $refundParams = [
            'payment_intent' => $transaction['payment_id']
        ];
        
        // 部分返金の場合
        if ($refundAmount && $refundAmount < $transaction['total_amount']) {
            $refundParams['amount'] = $refundAmount;
        }
        
        $refund = $stripe->refunds->create($refundParams);
        
        // ステータス更新
        $finalAmount = $refundAmount ?? $transaction['total_amount'];
        $stmt = $db->prepare("
            UPDATE service_transactions 
            SET status = 'refunded', 
                refund_amount = ?,
                refund_id = ?,
                refunded_at = NOW(),
                updated_at = NOW()
        WHERE id = ?
        ");
        $stmt->execute([$finalAmount, $refund->id, $transactionId]);
        
        // システムメッセージ追加
        $message = "【返金処理完了】\n返金額: " . formatPrice($finalAmount);
        if ($reason) {
            $message .= "\n理由: {$reason}";
        }
        addTransactionMessage($transactionId, 'admin', null, '運営', $message, 'system');
        
        // メール通知
        sendTransactionEmail($transactionId, 'refund_completed', 'customer');
        
        return ['success' => true, 'refund_id' => $refund->id];
        
    } catch (\Stripe\Exception\ApiErrorException $e) {
        return ['success' => false, 'error' => 'Stripeエラー: ' . $e->getMessage()];
    } catch (Exception $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * キャンセルポリシーを取得
 */
function getCancellationPolicy($status) {
    $policies = [
        'inquiry' => [
            'can_cancel' => true,
            'refund_rate' => 100,
            'message' => '見積もり依頼中はいつでもキャンセル可能です。'
        ],
        'quote_sent' => [
            'can_cancel' => true,
            'refund_rate' => 100,
            'message' => '見積もり検討中はキャンセル可能です。'
        ],
        'payment_pending' => [
            'can_cancel' => true,
            'refund_rate' => 100,
            'message' => '決済前はキャンセル可能です。'
        ],
        'paid' => [
            'can_cancel' => true,
            'refund_rate' => 80,
            'message' => '制作開始後のキャンセルは80%の返金となります。'
        ],
        'in_progress' => [
            'can_cancel' => true,
            'refund_rate' => 50,
            'message' => '制作途中のキャンセルは50%の返金となります。'
        ],
        'delivered' => [
            'can_cancel' => false,
            'refund_rate' => 0,
            'message' => '納品後のキャンセルはできません。'
        ],
        'completed' => [
            'can_cancel' => false,
            'refund_rate' => 0,
            'message' => '取引完了後のキャンセルはできません。'
        ]
    ];
    
    return $policies[$status] ?? [
        'can_cancel' => false,
        'refund_rate' => 0,
        'message' => 'この状態ではキャンセルできません。'
    ];
}

/**
 * ゲストアクセストークン生成
 */
function generateGuestAccessToken($transactionId) {
    $db = getDB();
    
    $token = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+7 days'));
    
    $stmt = $db->prepare("
        UPDATE service_transactions 
        SET guest_access_token = ?, 
            guest_token_expires = ?
        WHERE id = ?
    ");
    $stmt->execute([$token, $expiresAt, $transactionId]);
    
    return $token;
}

/**
 * ゲストアクセストークン検証
 */
function validateGuestAccessToken($token) {
    $db = getDB();
    
    $stmt = $db->prepare("
        SELECT * FROM service_transactions 
        WHERE guest_access_token = ? 
        AND guest_token_expires > NOW()
    ");
    $stmt->execute([$token]);
    
    return $stmt->fetch();
}
