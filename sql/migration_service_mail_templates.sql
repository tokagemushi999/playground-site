-- =============================================
-- サービス関連メールテンプレートの追加
-- =============================================

-- 既存のテンプレートを確認してなければ追加
INSERT IGNORE INTO mail_templates (template_key, name, subject, body, description, is_active) VALUES

-- 見積もり依頼受信（クリエイター向け）
('service_inquiry_creator', 'サービス見積もり依頼（クリエイター向け）', 
'【ぷれぐら！】新しい見積もり依頼が届きました',
'{creator_name}様

新しい見積もり依頼が届きました。

■ サービス: {service_title}
■ 取引コード: {transaction_code}
■ 依頼者: {customer_name}様
■ 依頼タイトル: {request_title}

【依頼内容】
{request_detail}

■ 希望予算: {request_budget}
■ 希望納期: {request_deadline}

下記URLから詳細を確認し、見積もりを作成してください。
{transaction_url}

--
ぷれぐら！ PLAYGROUND
{site_url}',
'サービス見積もり依頼がクリエイターに届いた時', 1),

-- 見積もり依頼受信確認（顧客向け）
('service_inquiry_customer', 'サービス見積もり依頼受付（顧客向け）',
'【ぷれぐら！】見積もり依頼を受け付けました',
'{customer_name}様

見積もり依頼を受け付けました。

■ サービス: {service_title}
■ 取引コード: {transaction_code}
■ クリエイター: {creator_name}様

クリエイターからの見積もりをお待ちください。
見積もりが届きましたらメールでお知らせいたします。

取引の確認はこちら:
{transaction_url}

--
ぷれぐら！ PLAYGROUND
{site_url}',
'サービス見積もり依頼が受け付けられた時（顧客向け）', 1),

-- 見積もり送信通知（顧客向け）
('service_quote_customer', 'サービス見積もり送信（顧客向け）',
'【ぷれぐら！】見積もりが届きました',
'{customer_name}様

ご依頼いただいたサービスの見積もりが届きました。

■ サービス: {service_title}
■ 取引コード: {transaction_code}
■ クリエイター: {creator_name}様

■ 見積もり金額: ¥{quote_amount}
■ 納品予定: {delivery_date}

内容をご確認の上、承諾またはご質問をお願いいたします。
{transaction_url}

--
ぷれぐら！ PLAYGROUND
{site_url}',
'見積もりが顧客に送信された時', 1),

-- 見積もり承諾通知（クリエイター向け）
('service_quote_accepted_creator', 'サービス見積もり承諾（クリエイター向け）',
'【ぷれぐら！】見積もりが承諾されました',
'{creator_name}様

見積もりが承諾されました！

■ サービス: {service_title}
■ 取引コード: {transaction_code}
■ 依頼者: {customer_name}様
■ 金額: ¥{quote_amount}

決済完了後、制作を開始してください。
{transaction_url}

--
ぷれぐら！ PLAYGROUND
{site_url}',
'見積もりが承諾された時（クリエイター向け）', 1),

-- 決済完了通知（クリエイター向け）
('service_paid_creator', 'サービス決済完了（クリエイター向け）',
'【ぷれぐら！】決済が完了しました - 制作を開始してください',
'{creator_name}様

決済が完了しました。制作を開始してください。

■ サービス: {service_title}
■ 取引コード: {transaction_code}
■ 依頼者: {customer_name}様
■ 金額: ¥{paid_amount}

制作が完了したら、取引ページから納品してください。
{transaction_url}

--
ぷれぐら！ PLAYGROUND
{site_url}',
'決済が完了した時（クリエイター向け）', 1),

-- 決済完了通知（顧客向け）
('service_paid_customer', 'サービス決済完了（顧客向け）',
'【ぷれぐら！】決済が完了しました',
'{customer_name}様

決済が完了しました。

■ サービス: {service_title}
■ 取引コード: {transaction_code}
■ クリエイター: {creator_name}様
■ 金額: ¥{paid_amount}

クリエイターが制作を開始します。
納品までしばらくお待ちください。

取引の確認:
{transaction_url}

--
ぷれぐら！ PLAYGROUND
{site_url}',
'決済が完了した時（顧客向け）', 1),

-- メッセージ受信通知
('service_message', 'サービス取引メッセージ受信',
'【ぷれぐら！】新しいメッセージが届きました',
'{recipient_name}様

取引に関する新しいメッセージが届きました。

■ 取引コード: {transaction_code}
■ サービス: {service_title}

メッセージを確認してください。
{transaction_url}

--
ぷれぐら！ PLAYGROUND
{site_url}',
'取引でメッセージを受信した時', 1),

-- 納品通知（顧客向け）
('service_delivered_customer', 'サービス納品通知（顧客向け）',
'【ぷれぐら！】納品されました',
'{customer_name}様

ご依頼の制作物が納品されました！

■ サービス: {service_title}
■ 取引コード: {transaction_code}
■ クリエイター: {creator_name}様

内容をご確認の上、問題なければ「承認」をお願いいたします。
修正が必要な場合は「修正依頼」から連絡してください。

{transaction_url}

--
ぷれぐら！ PLAYGROUND
{site_url}',
'クリエイターが納品した時（顧客向け）', 1),

-- 取引完了通知（クリエイター向け）
('service_completed_creator', 'サービス取引完了（クリエイター向け）',
'【ぷれぐら！】取引が完了しました',
'{creator_name}様

取引が完了しました。お疲れ様でした！

■ サービス: {service_title}
■ 取引コード: {transaction_code}
■ 依頼者: {customer_name}様
■ 金額: ¥{paid_amount}

売上はダッシュボードから確認できます。

--
ぷれぐら！ PLAYGROUND
{site_url}',
'取引が完了した時（クリエイター向け）', 1),

-- 取引完了通知（顧客向け）
('service_completed_customer', 'サービス取引完了（顧客向け）',
'【ぷれぐら！】取引が完了しました',
'{customer_name}様

取引が完了しました。ご利用ありがとうございました！

■ サービス: {service_title}
■ 取引コード: {transaction_code}
■ クリエイター: {creator_name}様

よろしければ、クリエイターへのレビューをお願いいたします。
{review_url}

またのご利用をお待ちしております。

--
ぷれぐら！ PLAYGROUND
{site_url}',
'取引が完了した時（顧客向け）', 1),

-- 修正依頼通知（クリエイター向け）
('service_revision_creator', 'サービス修正依頼（クリエイター向け）',
'【ぷれぐら！】修正依頼が届きました',
'{creator_name}様

納品物に対する修正依頼が届きました。

■ サービス: {service_title}
■ 取引コード: {transaction_code}
■ 依頼者: {customer_name}様

詳細を確認し、対応をお願いいたします。
{transaction_url}

--
ぷれぐら！ PLAYGROUND
{site_url}',
'顧客から修正依頼があった時（クリエイター向け）', 1),

-- リマインダー（クリエイター向け）
('service_reminder_creator', 'サービス取引リマインダー（クリエイター向け）',
'【ぷれぐら！】対応待ちの取引があります',
'{creator_name}様

対応待ちの取引があります。

■ サービス: {service_title}
■ 取引コード: {transaction_code}
■ 依頼者: {customer_name}様
■ ステータス: {status_label}

早めの対応をお願いいたします。
{transaction_url}

--
ぷれぐら！ PLAYGROUND
{site_url}',
'クリエイターへのリマインダー', 1);
