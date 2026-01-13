<?php
/**
 * メール送信ヘルパー（Gmail SMTP対応 + テンプレート対応）
 */

require_once __DIR__ . '/shipping.php';
require_once __DIR__ . '/formatting.php';

// SMTP設定（二重定義を防ぐ）
if (!defined('SMTP_HOST')) {
    define('SMTP_HOST', 'smtp.gmail.com');
    define('SMTP_PORT', 587);
    define('SMTP_USER', 'nitta@tokagemushi.jp');
    define('SMTP_PASS', 'lukzajuifebjyzxf');
    define('SMTP_FROM_EMAIL', 'store@tokagemushi.jp');
    define('SMTP_FROM_NAME', 'ぷれぐら！STORE');
}

// サイトURL
if (!defined('SITE_URL')) {
    define('SITE_URL', 'https://tokagemushi.jp');
    define('STORE_URL', SITE_URL . '/store/');
    define('ADMIN_URL', SITE_URL . '/admin/');
}

/**
 * メールテンプレート取得
 */
function getMailTemplate($templateKey) {
    try {
        require_once __DIR__ . '/db.php';
        $db = getDB();
        $stmt = $db->prepare("SELECT * FROM mail_templates WHERE template_key = ? AND is_active = 1");
        $stmt->execute([$templateKey]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Exception $e) {
        error_log("Mail template error: " . $e->getMessage());
        return null;
    }
}

/**
 * テンプレート変数を置換
 */
function replaceTemplateVars($text, $vars) {
    foreach ($vars as $key => $value) {
        $text = str_replace('{' . $key . '}', $value, $text);
    }
    return $text;
}

/**
 * SMTP経由でメール送信
 */
function sendMail($to, $subject, $body, $fromEmail = null, $fromName = null) {
    $fromEmail = $fromEmail ?: SMTP_FROM_EMAIL;
    $fromName = $fromName ?: SMTP_FROM_NAME;
    
    try {
        // ソケット接続
        $socket = fsockopen('tls://' . SMTP_HOST, 465, $errno, $errstr, 30);
        if (!$socket) {
            // TLS接続失敗時はSTARTTLSを試行
            $socket = fsockopen(SMTP_HOST, SMTP_PORT, $errno, $errstr, 30);
            if (!$socket) {
                throw new Exception("接続失敗: $errstr ($errno)");
            }
            
            smtpGetResponse($socket);
            smtpCommand($socket, "EHLO " . gethostname());
            smtpCommand($socket, "STARTTLS");
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            smtpCommand($socket, "EHLO " . gethostname());
        } else {
            smtpGetResponse($socket);
            smtpCommand($socket, "EHLO " . gethostname());
        }
        
        // 認証
        smtpCommand($socket, "AUTH LOGIN");
        smtpCommand($socket, base64_encode(SMTP_USER));
        smtpCommand($socket, base64_encode(SMTP_PASS));
        
        // 送信者・受信者
        smtpCommand($socket, "MAIL FROM:<" . SMTP_USER . ">");
        smtpCommand($socket, "RCPT TO:<$to>");
        
        // データ開始
        smtpCommand($socket, "DATA");
        
        // メールヘッダーと本文
        $message = "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <$fromEmail>\r\n";
        $message .= "To: $to\r\n";
        $message .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
        $message .= "MIME-Version: 1.0\r\n";
        $message .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $message .= "Content-Transfer-Encoding: base64\r\n";
        $message .= "\r\n";
        $message .= chunk_split(base64_encode($body));
        $message .= "\r\n.\r\n";
        
        fwrite($socket, $message);
        smtpGetResponse($socket);
        
        smtpCommand($socket, "QUIT");
        fclose($socket);
        
        error_log("Mail sent successfully to: $to");
        return true;
        
    } catch (Exception $e) {
        error_log("Mail send error: " . $e->getMessage());
        if (isset($socket) && $socket) {
            fclose($socket);
        }
        return false;
    }
}

function smtpCommand($socket, $command) {
    fwrite($socket, $command . "\r\n");
    return smtpGetResponse($socket);
}

function smtpGetResponse($socket) {
    $response = '';
    while ($line = fgets($socket, 515)) {
        $response .= $line;
        if (substr($line, 3, 1) == ' ') break;
    }
    
    $code = substr($response, 0, 3);
    if ($code[0] == '4' || $code[0] == '5') {
        throw new Exception("SMTP Error: $response");
    }
    
    return $response;
}

/**
 * テンプレートを使用してメール送信
 */
function sendTemplateMail($templateKey, $to, $vars = []) {
    $template = getMailTemplate($templateKey);
    
    if (!$template) {
        error_log("Mail template not found or inactive: $templateKey");
        return false;
    }
    
    // 共通変数を追加
    $vars['store_url'] = STORE_URL;
    $vars['mypage_url'] = STORE_URL . 'mypage.php';
    $vars['bookshelf_url'] = STORE_URL . 'bookshelf.php';
    
    $subject = replaceTemplateVars($template['subject'], $vars);
    $body = replaceTemplateVars($template['body'], $vars);
    
    return sendMail($to, $subject, $body);
}

/**
 * 会員登録完了メール
 */
function sendMemberRegistrationMail($member) {
    $vars = [
        'member_name' => $member['name'],
        'member_email' => $member['email'],
        'register_date' => date('Y年m月d日 H:i'),
    ];
    
    return sendTemplateMail('member_register', $member['email'], $vars);
}

/**
 * パスワードリセットメール
 */
function sendPasswordResetMail($member, $resetUrl) {
    $vars = [
        'member_name' => $member['name'],
        'reset_url' => $resetUrl,
        'expire_hours' => '24',
    ];
    
    return sendTemplateMail('password_reset', $member['email'], $vars);
}

/**
 * 購入完了メールを送信
 */
function sendOrderConfirmationMail($order, $orderItems, $member) {
    // 商品リスト作成
    $itemsList = '';
    foreach ($orderItems as $item) {
        $type = $item['product_type'] === 'digital' ? 'デジタル' : '物販';
        $itemsList .= "  ・{$item['product_name']}（{$type}）\n";
        $itemsList .= "    " . formatPrice($item['price'] ?? 0) . " × {$item['quantity']}点 = " . formatPrice($item['subtotal'] ?? 0) . "\n";
    }
    
    // デジタル商品セクション
    $digitalSection = '';
    $hasDigital = false;
    foreach ($orderItems as $item) {
        if ($item['product_type'] === 'digital') {
            $hasDigital = true;
            break;
        }
    }
    if ($hasDigital) {
        $digitalSection = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
■ デジタル商品のご利用について
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

デジタル商品は「本棚」からすぐにご覧いただけます。

▼ 本棚はこちら
" . STORE_URL . "bookshelf.php";
    }
    
    // 領収書セクション
    $invoiceSection = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
■ 領収書について
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

領収書はマイページの注文詳細からいつでもご確認いただけます。

▼ 領収書を表示
" . STORE_URL . "invoice.php?id={$order['id']}";
    
    // 配送セクション
    $shippingSection = '';
    $hasPhysical = false;
    foreach ($orderItems as $item) {
        if ($item['product_type'] === 'physical') {
            $hasPhysical = true;
            break;
        }
    }
    if ($hasPhysical && !empty($order['shipping_postal_code'])) {
        $shippingSection = "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━
■ 配送について
━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━

物販商品は発送準備が整い次第、発送いたします。
発送完了後、追跡番号をメールでお知らせいたします。

お届け先:
〒{$order['shipping_postal_code']}
{$order['shipping_prefecture']}{$order['shipping_city']}{$order['shipping_address1']}
{$order['shipping_address2']}
{$order['shipping_name']} 様
TEL: {$order['shipping_phone']}";
    }
    
    $vars = [
        'member_name' => $member['name'],
        'order_number' => $order['order_number'],
        'order_date' => $order['created_at'],
        'order_items' => $itemsList,
        'subtotal' => formatNumber($order['subtotal'] ?? 0, '0'),
        'shipping_fee' => formatNumber($order['shipping_fee'] ?? 0, '0'),
        'total' => formatNumber($order['total'] ?? 0, '0'),
        'digital_section' => $digitalSection,
        'invoice_section' => $invoiceSection,
        'shipping_section' => $shippingSection,
    ];
    
    return sendTemplateMail('order_complete', $member['email'], $vars);
}

/**
 * 発送完了メールを送信
 */
function sendShippingNotificationMail($order, $orderItems, $member) {
    // 物販商品リスト作成
    $itemsList = '';
    foreach ($orderItems as $item) {
        if ($item['product_type'] === 'physical') {
            $itemsList .= "  ・{$item['product_name']} × {$item['quantity']}点\n";
        }
    }
    
    // 配送先
    $shippingAddress = "〒{$order['shipping_postal_code']}\n";
    $shippingAddress .= "{$order['shipping_prefecture']}{$order['shipping_city']}{$order['shipping_address1']}\n";
    if (!empty($order['shipping_address2'])) {
        $shippingAddress .= "{$order['shipping_address2']}\n";
    }
    $shippingAddress .= "{$order['shipping_name']} 様";
    
    $carrierCode = $order['shipping_carrier'] ?? '';
    $carrierName = getShippingCarrierName($carrierCode, $order['shipping_carrier'] ?? '未設定');

    $trackingNumber = $order['tracking_number'] ?? '';
    $trackingUrl = getTrackingUrl($carrierCode, $trackingNumber);
    
    $vars = [
        'member_name' => $member['name'],
        'order_number' => $order['order_number'],
        'order_items' => $itemsList,
        'shipping_carrier' => $carrierName,
        'tracking_number' => $trackingNumber ?: '（なし）',
        'tracking_url' => $trackingUrl,
        'shipping_address' => $shippingAddress,
    ];
    
    return sendTemplateMail('order_shipped', $member['email'], $vars);
}

/**
 * 管理者に新規注文通知を送信
 */
function sendNewOrderNotificationToAdmin($order, $orderItems, $member, $adminEmail = null) {
    $adminEmail = $adminEmail ?: SMTP_USER;
    
    $itemsList = '';
    foreach ($orderItems as $item) {
        $type = $item['product_type'] === 'digital' ? 'デジタル' : '物販';
        $itemsList .= "  ・{$item['product_name']}（{$type}） × {$item['quantity']}点\n";
    }
    
    $vars = [
        'order_number' => $order['order_number'],
        'order_date' => $order['created_at'],
        'total' => formatNumber($order['total'] ?? 0, '0'),
        'member_name' => $member['name'],
        'member_email' => $member['email'],
        'order_items' => $itemsList,
        'admin_order_url' => ADMIN_URL . 'orders.php?view=' . $order['id'],
    ];
    
    return sendTemplateMail('admin_new_order', $adminEmail, $vars);
}

/**
 * お問い合わせ確認メール（お客様向け）
 */
function sendContactConfirmationMail($name, $email, $subject, $messageBody) {
    $vars = [
        'name' => $name,
        'email' => $email,
        'subject' => $subject,
        'message' => $messageBody,
    ];
    
    return sendTemplateMail('contact_confirm', $email, $vars);
}

/**
 * お問い合わせ通知メール（管理者向け）
 */
function sendContactNotificationToAdmin($name, $email, $subject, $messageBody, $inquiryId = null) {
    $vars = [
        'name' => $name,
        'email' => $email,
        'subject' => $subject,
        'message' => $messageBody,
        'admin_inquiry_url' => ADMIN_URL . 'inquiries.php' . ($inquiryId ? '?view=' . $inquiryId : ''),
    ];
    
    return sendTemplateMail('admin_contact', SMTP_USER, $vars);
}

/**
 * sendMail のエイリアス（互換性のため）
 */
function sendEmail($to, $subject, $body, $fromEmail = null, $fromName = null) {
    return sendMail($to, $subject, $body, $fromEmail, $fromName);
}
