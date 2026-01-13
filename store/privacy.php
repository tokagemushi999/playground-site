<?php
/**
 * プライバシーポリシー
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/site-settings.php';

$settings = getSiteSettings();
$siteName = $settings['site_name'] ?? 'CREATORS PLAYGROUND';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php include 'includes/pwa-meta.php'; ?>
    <title>プライバシーポリシー - <?= htmlspecialchars($siteName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-white shadow-sm">
        <div class="max-w-4xl mx-auto px-4 py-4">
            <a href="/" class="text-xl font-bold text-gray-800"><?= htmlspecialchars($siteName) ?></a>
        </div>
    </header>

    <main class="max-w-3xl mx-auto px-4 py-8">
        <h1 class="text-2xl font-bold text-gray-800 mb-8">プライバシーポリシー</h1>
        
        <div class="bg-white rounded-xl shadow-sm p-8 prose prose-gray max-w-none">
            <p class="text-gray-600 mb-6">
                <?= htmlspecialchars($siteName) ?>（以下「当サービス」）は、ユーザーの個人情報の取扱いについて、以下のとおりプライバシーポリシーを定めます。
            </p>
            
            <h2 class="text-xl font-bold text-gray-800 mt-8 mb-4">1. 収集する情報</h2>
            <p class="text-gray-600 mb-4">当サービスでは、以下の情報を収集することがあります。</p>
            <ul class="list-disc pl-6 text-gray-600 mb-6 space-y-2">
                <li>氏名、メールアドレス、パスワード（会員登録時）</li>
                <li>配送先住所、電話番号（商品購入時）</li>
                <li>クレジットカード情報（決済代行会社を通じて処理）</li>
                <li>アクセスログ、Cookie情報</li>
            </ul>
            
            <h2 class="text-xl font-bold text-gray-800 mt-8 mb-4">2. 情報の利用目的</h2>
            <p class="text-gray-600 mb-4">収集した情報は、以下の目的で利用します。</p>
            <ul class="list-disc pl-6 text-gray-600 mb-6 space-y-2">
                <li>サービスの提供、運営、改善</li>
                <li>商品の発送、お問い合わせへの対応</li>
                <li>重要なお知らせの送付</li>
                <li>不正利用の防止</li>
                <li>統計データの作成（個人を特定しない形式）</li>
            </ul>
            
            <h2 class="text-xl font-bold text-gray-800 mt-8 mb-4">3. 情報の第三者提供</h2>
            <p class="text-gray-600 mb-6">
                当サービスは、以下の場合を除き、個人情報を第三者に提供することはありません。
            </p>
            <ul class="list-disc pl-6 text-gray-600 mb-6 space-y-2">
                <li>ユーザーの同意がある場合</li>
                <li>法令に基づく場合</li>
                <li>商品発送のため配送業者に必要な情報を提供する場合</li>
                <li>決済処理のため決済代行会社に必要な情報を提供する場合</li>
            </ul>
            
            <h2 class="text-xl font-bold text-gray-800 mt-8 mb-4">4. 情報の安全管理</h2>
            <p class="text-gray-600 mb-6">
                当サービスは、個人情報の漏洩、滅失、毀損を防止するため、適切なセキュリティ対策を講じます。
                クレジットカード情報はStripe社のセキュアな決済システムを通じて処理され、当サービスのサーバーには保存されません。
            </p>
            
            <h2 class="text-xl font-bold text-gray-800 mt-8 mb-4">5. Cookieの使用</h2>
            <p class="text-gray-600 mb-6">
                当サービスでは、ログイン状態の維持やサービスの改善のためにCookieを使用しています。
                ブラウザの設定でCookieを無効にすることができますが、一部の機能が利用できなくなる場合があります。
            </p>
            
            <h2 class="text-xl font-bold text-gray-800 mt-8 mb-4">6. 個人情報の開示・訂正・削除</h2>
            <p class="text-gray-600 mb-6">
                ユーザーは、マイページから自身の登録情報を確認・変更することができます。
                アカウントの削除をご希望の場合は、お問い合わせフォームよりご連絡ください。
            </p>
            
            <h2 class="text-xl font-bold text-gray-800 mt-8 mb-4">7. プライバシーポリシーの変更</h2>
            <p class="text-gray-600 mb-6">
                当サービスは、必要に応じて本ポリシーを変更することがあります。
                重要な変更がある場合は、サービス上でお知らせします。
            </p>
            
            <h2 class="text-xl font-bold text-gray-800 mt-8 mb-4">8. お問い合わせ</h2>
            <p class="text-gray-600 mb-6">
                本ポリシーに関するお問い合わせは、<a href="/contact.php" class="text-blue-600 hover:underline">お問い合わせフォーム</a>よりご連絡ください。
            </p>
            
            <p class="text-gray-500 text-sm mt-8">
                制定日: <?= date('Y年n月j日') ?>
            </p>
        </div>
        
        <div class="mt-8 text-center">
            <a href="/store/" class="text-blue-600 hover:underline">
                <i class="fas fa-arrow-left mr-1"></i>ストアに戻る
            </a>
        </div>
    </main>
    
    <footer class="bg-white border-t mt-12 py-8">
        <div class="max-w-4xl mx-auto px-4 text-center text-sm text-gray-500">
            <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($siteName) ?></p>
        </div>
    </footer>
</body>
</html>
