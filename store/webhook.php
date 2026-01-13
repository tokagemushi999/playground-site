<?php
/**
 * Stripe Webhook エンドポイント
 * 
 * 設定方法:
 * 1. Stripeダッシュボード → Webhooks → エンドポイントを追加
 * 2. URL: https://yoursite.com/store/webhook.php
 * 3. イベント: checkout.session.completed, payment_intent.succeeded
 * 4. 署名シークレットをstripe-config.phpに設定
 */

require_once '../includes/db.php';
require_once '../includes/stripe-config.php';
require_once '../includes/mail.php';
require_once '../includes/google-drive.php';
require_once '../includes/document-template.php';
require_once '../includes/site-settings.php';
require_once '../includes/formatting.php';

// Stripeからのリクエストのみ許可
$payload = @file_get_contents('php://input');
$sigHeader = $_SERVER['HTTP_STRIPE_SIGNATURE'] ?? '';

try {
    initStripe();
    
    $event = \Stripe\Webhook::constructEvent(
        $payload, $sigHeader, STRIPE_WEBHOOK_SECRET
    );
} catch (\UnexpectedValueException $e) {
    http_response_code(400);
    exit('Invalid payload');
} catch (\Stripe\Exception\SignatureVerificationException $e) {
    http_response_code(400);
    exit('Invalid signature');
}

$db = getDB();

// イベント処理
switch ($event->type) {
    case 'checkout.session.completed':
        $session = $event->data->object;
        $orderId = $session->metadata->order_id ?? null;
        
        if ($orderId && $session->payment_status === 'paid') {
            processOrderCompletion($db, $orderId, $session->payment_intent);
        }
        break;
        
    case 'payment_intent.succeeded':
        $paymentIntent = $event->data->object;
        
        // Payment IntentでorderId検索
        $stmt = $db->prepare("SELECT id FROM orders WHERE stripe_payment_intent_id = ? AND payment_status = 'pending'");
        $stmt->execute([$paymentIntent->id]);
        $order = $stmt->fetch();
        
        if ($order) {
            processOrderCompletion($db, $order['id'], $paymentIntent->id);
        }
        break;
        
    case 'payment_intent.payment_failed':
        $paymentIntent = $event->data->object;
        
        // 支払い失敗を記録
        $stmt = $db->prepare("UPDATE orders SET payment_status = 'failed' WHERE stripe_payment_intent_id = ?");
        $stmt->execute([$paymentIntent->id]);
        break;
        
    case 'charge.refunded':
        $charge = $event->data->object;
        
        // 返金処理
        $stmt = $db->prepare("SELECT id FROM orders WHERE stripe_charge_id = ?");
        $stmt->execute([$charge->id]);
        $order = $stmt->fetch();
        
        if ($order) {
            $refundStatus = $charge->refunded ? 'refunded' : 'partial_refund';
            $stmt = $db->prepare("UPDATE orders SET payment_status = ?, order_status = 'refunded' WHERE id = ?");
            $stmt->execute([$refundStatus, $order['id']]);
        }
        break;
}

http_response_code(200);
echo json_encode(['received' => true]);

/**
 * 注文完了処理
 */
function processOrderCompletion($db, $orderId, $paymentIntentId) {
    $db->beginTransaction();
    
    try {
        // 既に処理済みかチェック
        $stmt = $db->prepare("SELECT * FROM orders WHERE id = ? AND payment_status = 'pending' FOR UPDATE");
        $stmt->execute([$orderId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$order) {
            $db->rollBack();
            return;
        }
        
        // 注文ステータスを更新
        $stmt = $db->prepare("
            UPDATE orders 
            SET payment_status = 'paid', 
                order_status = 'confirmed',
                stripe_payment_intent_id = ?,
                paid_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$paymentIntentId, $orderId]);
        
        // デジタル商品を本棚に追加
        $stmt = $db->prepare("
            SELECT oi.*, p.related_work_id 
            FROM order_items oi 
            JOIN products p ON oi.product_id = p.id
            WHERE oi.order_id = ? AND oi.product_type = 'digital'
        ");
        $stmt->execute([$orderId]);
        $digitalItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($digitalItems as $item) {
            $stmt = $db->prepare("
                INSERT IGNORE INTO member_bookshelf (member_id, product_id, order_id)
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$order['member_id'], $item['product_id'], $orderId]);
        }
        
        // 在庫を減らす
        $stmt = $db->prepare("
            SELECT oi.product_id, oi.quantity 
            FROM order_items oi 
            WHERE oi.order_id = ? AND oi.product_type = 'physical'
        ");
        $stmt->execute([$orderId]);
        $physicalItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($physicalItems as $item) {
            $stmt = $db->prepare("
                UPDATE products 
                SET stock_quantity = stock_quantity - ? 
                WHERE id = ? AND stock_quantity IS NOT NULL
            ");
            $stmt->execute([$item['quantity'], $item['product_id']]);
            
            // 在庫切れチェック
            $stmt = $db->prepare("UPDATE products SET stock_status = 'out_of_stock' WHERE id = ? AND stock_quantity <= 0");
            $stmt->execute([$item['product_id']]);
        }
        
        $db->commit();
        
        // 購入完了メール送信
        try {
            // 会員情報取得
            $stmt = $db->prepare("SELECT * FROM members WHERE id = ?");
            $stmt->execute([$order['member_id']]);
            $member = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // 注文商品取得
            $stmt = $db->prepare("SELECT * FROM order_items WHERE order_id = ?");
            $stmt->execute([$orderId]);
            $orderItems = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // 最新の注文情報取得
            $stmt = $db->prepare("SELECT * FROM orders WHERE id = ?");
            $stmt->execute([$orderId]);
            $orderDetail = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // フォーマット済み金額を追加
            $orderDetail['subtotal_formatted'] = formatNumber($orderDetail['subtotal'], '0');
            $orderDetail['shipping_fee_formatted'] = formatNumber($orderDetail['shipping_fee'], '0');
            $orderDetail['total_formatted'] = formatNumber($orderDetail['total'], '0');
            
            if ($member && $orderItems) {
                // 購入者にメール送信
                sendOrderConfirmationMail($orderDetail, $orderItems, $member);
                
                // 管理者に通知
                sendNewOrderNotificationToAdmin($orderDetail, $orderItems, $member);
            }
            
            // 領収書をGoogle Driveに自動保存
            try {
                $gdrive = getGoogleDrive($db);
                if ($gdrive->isConnected()) {
                    $paidAt = $orderDetail['paid_at'] ?? date('Y-m-d H:i:s');
                    $year = (int)date('Y', strtotime($paidAt));
                    $month = (int)date('n', strtotime($paidAt));
                    
                    $folderId = $gdrive->getMonthlyFolder('receipts', $year, $month);
                    if ($folderId) {
                        $filename = sprintf('領収書_%s_%s.html', 
                            $orderDetail['order_number'],
                            date('Y-m-d', strtotime($paidAt)));
                        
                        // サイト設定を取得
                        $settings = getSiteSettings();
                        $siteName = $settings['site_name'] ?? 'ショップ';
                        $shopName = getSiteSetting($db, 'store_business_name', $siteName);
                        $shopInvoiceNumber = getSiteSetting($db, 'store_invoice_number', '');
                        
                        // きれいな領収書HTMLを生成
                        $receiptHtml = generateReceiptHtml($orderDetail, $orderItems, $shopName, $shopInvoiceNumber);
                        
                        $result = $gdrive->uploadPdfContent($receiptHtml, $filename, $folderId);
                        
                        if ($result) {
                            // 保存履歴を記録
                            $stmt = $db->prepare("INSERT INTO document_archives 
                                (document_type, reference_id, reference_type, filename, gdrive_file_id)
                                VALUES ('receipt', ?, 'order', ?, ?)");
                            $stmt->execute([$orderId, $filename, $result['id']]);
                        }
                    }
                }
            } catch (Exception $e) {
                // Google Drive保存エラーは無視
                error_log('Receipt Drive save error: ' . $e->getMessage());
            }
            
        } catch (Exception $e) {
            // メール送信失敗はログに記録するが、処理は継続
            error_log('Order confirmation mail error: ' . $e->getMessage());
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        error_log('Webhook order processing error: ' . $e->getMessage());
    }
}
