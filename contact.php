<?php
/**
 * 問い合わせフォーム処理 - データベース連携版
 * Xサーバー用 - リダイレクト方式
 */

session_start();
require_once 'includes/db.php';
require_once 'includes/csrf.php';

mb_language("Japanese");
mb_internal_encoding("UTF-8");

$db = getDB();

function getSiteSetting($db, $key, $default = '') {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : $default;
    } catch (Exception $e) {
        return $default;
    }
}

$site_name = getSiteSetting($db, 'site_name', 'ぷれぐら！');
$site_subtitle = getSiteSetting($db, 'site_subtitle', 'PLAYGROUND');
$to_email = getSiteSetting($db, 'contact_email', 'info@tokagemushi.jp');
$site_url = getSiteSetting($db, 'site_url', 'https://tokagemushi.jp');

$from_email = 'info@tokagemushi.jp';
$from_name = $site_name . ' ' . $site_subtitle;

// POSTリクエストのみ受付
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

// CSRFトークン検証
requireCsrfToken();

// フォームデータ取得（改行を保持するため生データを取得）
$nominated_creator = trim($_POST['nominated_creator'] ?? '');
$category = trim($_POST['category'] ?? '');
$budget = trim($_POST['budget'] ?? '');
$deadline = trim($_POST['deadline'] ?? '');
$purpose = trim($_POST['purpose'] ?? '');
$usage = trim($_POST['usage'] ?? '');
$details = trim($_POST['details'] ?? '');
$name = trim($_POST['name'] ?? '');
$email = filter_var($_POST['email'] ?? '', FILTER_SANITIZE_EMAIL);

$company_name = trim($_POST['company_name'] ?? '');
$project_name = trim($_POST['project_name'] ?? '');
$usage_permission = trim($_POST['usage_permission'] ?? '');

if (empty($purpose) && !empty($usage)) {
    $purpose = $usage;
}

// バリデーション
$errors = [];
if (empty($category)) $errors[] = 'ご依頼カテゴリを選択してください。';
if (empty($details)) $errors[] = 'ご依頼内容の詳細を入力してください。';
if (empty($name)) $errors[] = 'お名前を入力してください。';
if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = '有効なメールアドレスを入力してください。';

if (!empty($errors)) {
    $_SESSION['contact_error'] = implode('<br>', $errors);
    header('Location: index.php#tab-request');
    exit;
}

// デバッグログ（問題解決後は削除可能）
error_log("=== Contact Form Debug ===");
error_log("Company Name: " . $company_name);
error_log("Name: " . $name);
error_log("Email: " . $email);
error_log("Category: " . $category);
error_log("Budget: " . ($budget ?: 'NULL'));
error_log("Deadline: " . ($deadline ?: 'NULL'));
error_log("Purpose: " . ($purpose ?: 'NULL'));
error_log("Details: " . $details);

// データベースに保存
try {
    $stmt = $db->prepare("
        INSERT INTO inquiries 
        (nominated_creator, genre, budget, deadline, purpose, details, name, email, company_name, project_name, usage_permission, status) 
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'new')
    ");
    $stmt->execute([
        $nominated_creator ?: null,
        $category, // categoryをgenreカラムに保存
        $budget ?: null, // 空の場合はNULLとして保存
        $deadline ?: null,
        $purpose ?: null,
        $details,
        $name,
        $email,
        $company_name ?: null,
        $project_name ?: null,
        $usage_permission ?: null
    ]);
    $inquiry_id = $db->lastInsertId();
} catch (Exception $e) {
    error_log("Inquiry save failed: " . $e->getMessage());
    $_SESSION['contact_error'] = 'データベースエラーが発生しました。しばらく経ってから再度お試しください。';
    header('Location: index.php#tab-request');
    exit;
}

function send_japanese_mail($to, $subject, $body, $from_email, $from_name, $reply_to = null) {
    // 差出人名をBase64エンコード（RFC 2047）
    $encoded_from_name = '=?UTF-8?B?' . base64_encode($from_name) . '?=';
    
    // ヘッダー
    $headers = "From: {$encoded_from_name} <{$from_email}>\r\n";
    if ($reply_to) {
        $headers .= "Reply-To: {$reply_to}\r\n";
    }
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: base64\r\n";
    $headers .= "X-Mailer: PHP/" . phpversion();
    
    // 件名をBase64エンコード
    $encoded_subject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    
    // 本文をBase64エンコード
    $encoded_body = base64_encode($body);
    
    return mail($to, $encoded_subject, $encoded_body, $headers);
}

$subject = "【{$site_name}】新規お問い合わせ #{$inquiry_id}";

$body = "------------------------------------------------------------\n";
$body .= "{$site_name} {$site_subtitle} - 新規お問い合わせ #{$inquiry_id}\n";
$body .= "------------------------------------------------------------\n\n";

$body .= "【お客様情報】\n";
$body .= "法人名/個人名: {$company_name}\n";
$body .= "ご担当者様: {$name}\n";
$body .= "メールアドレス: {$email}\n\n";

if (!empty($project_name)) {
    $body .= "【案件名】\n{$project_name}\n\n";
}

$body .= "【基本情報】\n";
$body .= "ご依頼カテゴリ: {$category}\n";
if (!empty($budget)) {
    $body .= "ご予算: {$budget}\n";
} else {
    $body .= "ご予算: 未定\n";
}
$body .= "\n";

$body .= "【詳細】\n";
$body .= "希望納期/実施日: " . ($deadline ?: "未定") . "\n";
$body .= "使用用途: " . ($purpose ?: "未指定") . "\n";
if (!empty($usage_permission)) {
    $body .= "個人活動での利用許諾: {$usage_permission}\n";
}
$body .= "\n";

$body .= "【お問い合わせ内容】\n";
$body .= ($details ?: "なし") . "\n\n";

if (!empty($nominated_creator)) {
    $body .= "【ご指名クリエイター】\n{$nominated_creator}\n\n";
}

$body .= "------------------------------------------------------------\n";
$body .= "送信日時: " . date('Y年m月d日 H:i:s') . "\n";
$body .= "管理画面: {$site_url}/admin/inquiries.php\n";

$mail_success = send_japanese_mail($to_email, $subject, $body, $from_email, $from_name, $email);

if (!$mail_success) {
    error_log("Mail send failed to: " . $to_email);
}

$auto_subject = "【{$site_name}】お問い合わせありがとうございます";

$auto_body = "{$name} 様\n\n";
$auto_body .= "この度は当サイト、クリエイターへお問い合わせいただき、\n";
$auto_body .= "誠にありがとうございます。\n\n";
$auto_body .= "以下の内容でお問い合わせを受け付けました。\n";
$auto_body .= "担当者より2〜3営業日以内にご連絡いたします。\n\n";
$auto_body .= "------------------------------------------------------------\n";
$auto_body .= "【お問い合わせ内容】\n";
$auto_body .= "------------------------------------------------------------\n\n";

if (!empty($company_name)) {
    $auto_body .= "法人名/個人名: {$company_name}\n";
}
if (!empty($project_name)) {
    $auto_body .= "案件名: {$project_name}\n";
}
$auto_body .= "ご依頼カテゴリ: {$category}\n";
if (!empty($budget)) {
    $auto_body .= "ご予算: {$budget}\n";
}
if (!empty($deadline)) {
    $auto_body .= "希望納期/実施日: {$deadline}\n";
}
if (!empty($purpose)) {
    $auto_body .= "使用用途: {$purpose}\n";
}
$auto_body .= "\n【お問い合わせ詳細】\n";
$auto_body .= ($details ?: "なし") . "\n\n";
if (!empty($nominated_creator)) {
    $auto_body .= "【ご指名クリエイター】\n{$nominated_creator}\n\n";
}
$auto_body .= "------------------------------------------------------------\n\n";
$auto_body .= "※このメールは自動送信です。\n";
$auto_body .= "※このメールに直接返信しないでください。\n\n";
$auto_body .= "{$site_name} {$site_subtitle}\n";
$auto_body .= "{$site_url}\n";

send_japanese_mail($email, $auto_subject, $auto_body, $from_email, $from_name);

$_SESSION['contact_success'] = 'お問い合わせを受け付けました。確認メールをお送りしましたので、ご確認ください。';
header('Location: index.php#tab-request');
exit;
?>
