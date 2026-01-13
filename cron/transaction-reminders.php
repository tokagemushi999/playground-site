<?php
/**
 * 自動リマインダーメール送信スクリプト
 * Cronで毎日1回実行: 0 9 * * * php /path/to/cron/transaction-reminders.php
 */

// CLI実行チェック
if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

require_once __DIR__ . '/../includes/db.php';
require_once __DIR__ . '/../includes/mail.php';
require_once __DIR__ . '/../includes/transactions.php';

$db = getDB();
$now = new DateTime();
$sentCount = 0;

echo "=== Transaction Reminder Script ===\n";
echo "Start: " . $now->format('Y-m-d H:i:s') . "\n\n";

// 1. 見積もり送信後3日未回答 → 顧客にリマインド
echo "Checking quote reminders...\n";
$stmt = $db->query("
    SELECT t.*, s.title as service_title, c.name as creator_name, c.email as creator_email,
           COALESCE(m.email, t.guest_email) as customer_email,
           COALESCE(m.name, t.guest_name) as customer_name
    FROM service_transactions t
    LEFT JOIN services s ON t.service_id = s.id
    LEFT JOIN creators c ON t.creator_id = c.id
    LEFT JOIN members m ON t.member_id = m.id
    WHERE t.status = 'quote_sent'
    AND t.updated_at < DATE_SUB(NOW(), INTERVAL 3 DAY)
    AND NOT EXISTS (
        SELECT 1 FROM service_notifications n 
        WHERE n.transaction_id = t.id 
        AND n.notification_type = 'quote_reminder' 
        AND n.created_at > DATE_SUB(NOW(), INTERVAL 3 DAY)
    )
");

foreach ($stmt as $t) {
    echo "  - Transaction {$t['transaction_code']}: Quote sent 3+ days ago\n";
    
    $subject = "【リマインド】見積もりをご確認ください - {$t['service_title']}";
    $body = "
{$t['customer_name']} 様

見積もりをお送りしてから3日が経過しました。
ご確認いただけましたでしょうか？

■ サービス: {$t['service_title']}
■ クリエイター: {$t['creator_name']}
■ 取引コード: {$t['transaction_code']}

見積もり内容をご確認のうえ、ご承諾またはご質問がございましたらお知らせください。

▼ 取引詳細を確認する
https://tokagemushi.jp/store/transactions/{$t['transaction_code']}

---
ぷれぐら！PLAYGROUND
";
    
    if (!empty($t['customer_email'])) {
        sendMail($t['customer_email'], $subject, $body);
        
        // 通知ログ
        $logStmt = $db->prepare("
            INSERT INTO service_notifications (transaction_id, notification_type, recipient_type, recipient_email, subject, body, is_sent, sent_at)
            VALUES (?, 'quote_reminder', 'customer', ?, ?, ?, 1, NOW())
        ");
        $logStmt->execute([$t['id'], $t['customer_email'], $subject, $body]);
        
        $sentCount++;
        echo "    → Reminder sent to {$t['customer_email']}\n";
    }
}

// 2. 制作開始後7日間進捗報告なし → クリエイターにリマインド
echo "\nChecking progress reminders...\n";
$stmt = $db->query("
    SELECT t.*, s.title as service_title, c.name as creator_name, c.email as creator_email,
           COALESCE(m.name, t.guest_name) as customer_name
    FROM service_transactions t
    LEFT JOIN services s ON t.service_id = s.id
    LEFT JOIN creators c ON t.creator_id = c.id
    LEFT JOIN members m ON t.member_id = m.id
    WHERE t.status IN ('paid', 'in_progress')
    AND t.paid_at < DATE_SUB(NOW(), INTERVAL 7 DAY)
    AND NOT EXISTS (
        SELECT 1 FROM service_messages sm 
        WHERE sm.transaction_id = t.id 
        AND sm.sender_type = 'creator' 
        AND sm.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
    )
    AND NOT EXISTS (
        SELECT 1 FROM service_notifications n 
        WHERE n.transaction_id = t.id 
        AND n.notification_type = 'progress_reminder' 
        AND n.created_at > DATE_SUB(NOW(), INTERVAL 7 DAY)
    )
");

foreach ($stmt as $t) {
    echo "  - Transaction {$t['transaction_code']}: No progress update for 7+ days\n";
    
    $subject = "【リマインド】進捗報告のお願い - {$t['service_title']}";
    $body = "
{$t['creator_name']} 様

以下の取引について、7日以上進捗報告がありません。
お客様に現在の状況をお知らせいただけますでしょうか。

■ サービス: {$t['service_title']}
■ お客様: {$t['customer_name']}
■ 取引コード: {$t['transaction_code']}

定期的な進捗報告は、お客様の安心につながります。

▼ 取引詳細を確認する
https://tokagemushi.jp/creator-dashboard/transaction-detail.php?id={$t['id']}

---
ぷれぐら！PLAYGROUND 運営
";
    
    if (!empty($t['creator_email'])) {
        sendMail($t['creator_email'], $subject, $body);
        
        // 通知ログ
        $logStmt = $db->prepare("
            INSERT INTO service_notifications (transaction_id, notification_type, recipient_type, recipient_email, subject, body, is_sent, sent_at)
            VALUES (?, 'progress_reminder', 'creator', ?, ?, ?, 1, NOW())
        ");
        $logStmt->execute([$t['id'], $t['creator_email'], $subject, $body]);
        
        $sentCount++;
        echo "    → Reminder sent to {$t['creator_email']}\n";
    }
}

// 3. 納品後5日未承認 → 顧客にリマインド
echo "\nChecking delivery approval reminders...\n";
$stmt = $db->query("
    SELECT t.*, s.title as service_title, c.name as creator_name,
           COALESCE(m.email, t.guest_email) as customer_email,
           COALESCE(m.name, t.guest_name) as customer_name
    FROM service_transactions t
    LEFT JOIN services s ON t.service_id = s.id
    LEFT JOIN creators c ON t.creator_id = c.id
    LEFT JOIN members m ON t.member_id = m.id
    WHERE t.status = 'delivered'
    AND t.delivered_at < DATE_SUB(NOW(), INTERVAL 5 DAY)
    AND NOT EXISTS (
        SELECT 1 FROM service_notifications n 
        WHERE n.transaction_id = t.id 
        AND n.notification_type = 'delivery_reminder' 
        AND n.created_at > DATE_SUB(NOW(), INTERVAL 5 DAY)
    )
");

foreach ($stmt as $t) {
    echo "  - Transaction {$t['transaction_code']}: Delivery not approved for 5+ days\n";
    
    $subject = "【リマインド】納品物のご確認をお願いします - {$t['service_title']}";
    $body = "
{$t['customer_name']} 様

納品から5日が経過しました。
納品物のご確認はお済みでしょうか？

■ サービス: {$t['service_title']}
■ クリエイター: {$t['creator_name']}
■ 取引コード: {$t['transaction_code']}

内容に問題がなければ「納品を承認」ボタンをクリックしてください。
修正が必要な場合は「修正依頼」からご連絡ください。

▼ 取引詳細を確認する
https://tokagemushi.jp/store/transactions/{$t['transaction_code']}

---
ぷれぐら！PLAYGROUND
";
    
    if (!empty($t['customer_email'])) {
        sendMail($t['customer_email'], $subject, $body);
        
        // 通知ログ
        $logStmt = $db->prepare("
            INSERT INTO service_notifications (transaction_id, notification_type, recipient_type, recipient_email, subject, body, is_sent, sent_at)
            VALUES (?, 'delivery_reminder', 'customer', ?, ?, ?, 1, NOW())
        ");
        $logStmt->execute([$t['id'], $t['customer_email'], $subject, $body]);
        
        $sentCount++;
        echo "    → Reminder sent to {$t['customer_email']}\n";
    }
}

echo "\n=== Complete ===\n";
echo "Reminders sent: {$sentCount}\n";
echo "End: " . (new DateTime())->format('Y-m-d H:i:s') . "\n";
