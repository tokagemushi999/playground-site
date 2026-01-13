<?php
/**
 * ストア問い合わせフォーム
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/member-auth.php';
require_once '../includes/site-settings.php';
require_once '../includes/csrf.php';
require_once '../includes/mail.php';
require_once '../includes/defaults.php';

$db = getDB();
$settings = getSiteSettings();
$siteName = $settings['site_name'] ?? 'ぷれぐら！STORE';

$member = getCurrentMember();
$message = '';
$error = '';
$sent = false;

// カテゴリ一覧
$categories = [
    'order' => 'ご注文について',
    'shipping' => '配送について',
    'product' => '商品について',
    'account' => 'アカウントについて',
    'payment' => 'お支払いについて',
    'return' => '返品・交換について',
    'other' => 'その他',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF検証
    if (!verifyCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = '不正なリクエストです。ページを再読み込みしてください。';
    } else {
        $name = trim($_POST['name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $category = trim($_POST['category'] ?? '');
        $orderNumber = trim($_POST['order_number'] ?? '');
        $subject = trim($_POST['subject'] ?? '');
        $messageBody = trim($_POST['message'] ?? '');
        
        // バリデーション
        if (empty($name)) {
            $error = 'お名前を入力してください';
        } elseif (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = '有効なメールアドレスを入力してください';
        } elseif (empty($category)) {
            $error = 'お問い合わせ種別を選択してください';
        } elseif (empty($subject)) {
            $error = '件名を入力してください';
        } elseif (empty($messageBody)) {
            $error = 'お問い合わせ内容を入力してください';
        } else {
            // データベースに保存
            try {
                // 件名を組み立て
                $fullSubject = '[' . ($categories[$category] ?? 'その他') . '] ' . $subject;
                if (!empty($orderNumber)) {
                    $fullSubject .= ' (注文番号: ' . $orderNumber . ')';
                }
                
                // 詳細を組み立て
                $details = '';
                if (!empty($orderNumber)) {
                    $details .= "【注文番号】\n{$orderNumber}\n\n";
                }
                $details .= "【お問い合わせ内容】\n{$messageBody}";
                
                $stmt = $db->prepare("
                    INSERT INTO inquiries 
                    (name, email, genre, details, status, created_at) 
                    VALUES (?, ?, ?, ?, 'new', NOW())
                ");
                $stmt->execute([
                    $name,
                    $email,
                    $fullSubject,
                    $details
                ]);
                $inquiryId = $db->lastInsertId();
                
                // お客様にメール送信
                try {
                    sendContactConfirmationMail($name, $email, $fullSubject, $details);
                } catch (Exception $e) {
                    error_log("Contact confirmation mail error: " . $e->getMessage());
                }
                
                // 管理者にメール送信
                try {
                    sendContactNotificationToAdmin($name, $email, $fullSubject, $details, $inquiryId);
                } catch (Exception $e) {
                    error_log("Contact admin notification error: " . $e->getMessage());
                }
                
                $sent = true;
                $message = 'お問い合わせを受け付けました。確認メールをお送りしましたのでご確認ください。';
                
            } catch (PDOException $e) {
                error_log("Contact form error: " . $e->getMessage());
                $error = '送信中にエラーが発生しました。しばらく経ってから再度お試しください。';
            }
        }
    }
}

// CSRFトークン生成
$csrfToken = generateCsrfToken();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php include 'includes/pwa-meta.php'; ?>
    <title>お問い合わせ - <?= htmlspecialchars($siteName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50 min-h-screen pb-20 lg:pb-0">
    <?php include 'includes/header.php'; ?>
    
    <main class="max-w-2xl mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold text-gray-800 mb-6">
            <i class="fas fa-envelope text-orange-500 mr-2"></i>お問い合わせ
        </h1>
        
        <?php if ($sent): ?>
        <!-- 送信完了 -->
        <div class="bg-white rounded-xl shadow-sm p-8 text-center">
            <div class="w-16 h-16 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-check text-3xl text-green-500"></i>
            </div>
            <h2 class="text-xl font-bold text-gray-800 mb-2">送信完了</h2>
            <p class="text-gray-600 mb-6"><?= htmlspecialchars($message) ?></p>
            <p class="text-sm text-gray-500 mb-6">
                通常2〜3営業日以内にご返信いたします。<br>
                しばらく経っても返信がない場合は、迷惑メールフォルダをご確認ください。
            </p>
            <div class="flex flex-col sm:flex-row gap-3 justify-center">
                <a href="/store/" class="inline-flex items-center justify-center gap-2 px-6 py-2 bg-orange-500 text-white rounded-lg hover:bg-orange-600 transition">
                    <i class="fas fa-store"></i> ストアに戻る
                </a>
                <a href="/store/contact.php" class="inline-flex items-center justify-center gap-2 px-6 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
                    <i class="fas fa-plus"></i> 新しいお問い合わせ
                </a>
            </div>
        </div>
        <?php else: ?>
        <!-- フォーム -->
        <div class="bg-white rounded-xl shadow-sm p-6">
            <?php if ($error): ?>
            <div class="bg-red-50 border border-red-200 text-red-700 px-4 py-3 rounded-lg mb-6">
                <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
            </div>
            <?php endif; ?>
            
            <form method="POST" class="space-y-5">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
                
                <!-- お名前 -->
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">
                        お名前 <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="name" required
                           value="<?= htmlspecialchars($member['name'] ?? $_POST['name'] ?? '') ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-300 outline-none"
                           placeholder="山田 太郎">
                </div>
                
                <!-- メールアドレス -->
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">
                        メールアドレス <span class="text-red-500">*</span>
                    </label>
                    <input type="email" name="email" required
                           value="<?= htmlspecialchars($member['email'] ?? $_POST['email'] ?? '') ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-300 outline-none"
                           placeholder="example@email.com">
                </div>
                
                <!-- お問い合わせ種別 -->
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">
                        お問い合わせ種別 <span class="text-red-500">*</span>
                    </label>
                    <select name="category" required
                            class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-300 outline-none">
                        <option value="">選択してください</option>
                        <?php foreach ($categories as $key => $label): ?>
                        <option value="<?= htmlspecialchars($key) ?>" <?= ($_POST['category'] ?? '') === $key ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <!-- 注文番号（任意） -->
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">
                        注文番号 <span class="text-gray-400 font-normal">（お持ちの場合）</span>
                    </label>
                    <input type="text" name="order_number"
                           value="<?= htmlspecialchars($_POST['order_number'] ?? $_GET['order'] ?? '') ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-300 outline-none"
                           placeholder="ORD-XXXXXXXX">
                    <p class="text-xs text-gray-500 mt-1">ご注文に関するお問い合わせの場合、注文番号をご入力いただくとスムーズです</p>
                </div>
                
                <!-- 件名 -->
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">
                        件名 <span class="text-red-500">*</span>
                    </label>
                    <input type="text" name="subject" required
                           value="<?= htmlspecialchars($_POST['subject'] ?? '') ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-300 outline-none"
                           placeholder="お問い合わせの件名">
                </div>
                
                <!-- お問い合わせ内容 -->
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">
                        お問い合わせ内容 <span class="text-red-500">*</span>
                    </label>
                    <textarea name="message" required rows="6"
                              class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-300 outline-none resize-none"
                              placeholder="お問い合わせ内容を詳しくご記入ください"><?= htmlspecialchars($_POST['message'] ?? '') ?></textarea>
                </div>
                
                <!-- 送信ボタン -->
                <button type="submit" class="w-full py-3 bg-orange-500 hover:bg-orange-600 text-white rounded-lg font-bold transition">
                    <i class="fas fa-paper-plane mr-2"></i>送信する
                </button>
            </form>
        </div>
        
        <!-- よくある質問へのリンク -->
        <?php
        // FAQをDBから取得
        $contactFaqs = [];
        try {
            $stmt = $db->query("SELECT question, answer FROM store_faq WHERE is_published = 1 ORDER BY sort_order LIMIT 5");
            $contactFaqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            // テーブルがない場合はデフォルト
            $defaultFaqs = DEFAULT_STORE_FAQ;
            $contactFaqs = array_slice($defaultFaqs, 0, 5);
        }
        ?>
        <div class="mt-6 bg-blue-50 border border-blue-200 rounded-xl p-4">
            <h3 class="font-bold text-blue-800 mb-2">
                <i class="fas fa-lightbulb mr-2"></i>お問い合わせの前に
            </h3>
            <p class="text-sm text-blue-700 mb-3">
                よくあるご質問をご確認いただくと、すぐに解決できる場合があります。
            </p>
            <div class="space-y-2 text-sm">
                <?php foreach ($contactFaqs as $faq): ?>
                <details class="bg-white rounded-lg p-3">
                    <summary class="font-bold text-gray-700 cursor-pointer"><?= htmlspecialchars($faq['question']) ?></summary>
                    <p class="mt-2 text-gray-600"><?= nl2br(htmlspecialchars($faq['answer'])) ?></p>
                </details>
                <?php endforeach; ?>
            </div>
            <a href="/store/faq.php" class="inline-block mt-3 text-blue-600 hover:text-blue-800 text-sm font-bold">
                <i class="fas fa-arrow-right mr-1"></i>すべてのFAQを見る
            </a>
        </div>
        <?php endif; ?>
    </main>
    
    <?php include 'includes/footer.php'; ?>
</body>
</html>
