<?php
/**
 * 利用規約
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
    <title>利用規約 - <?= htmlspecialchars($siteName) ?></title>
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
        <h1 class="text-2xl font-bold text-gray-800 mb-8">利用規約</h1>
        
        <div class="bg-white rounded-xl shadow-sm p-8 prose prose-gray max-w-none">
            <p class="text-gray-600 mb-6">
                この利用規約（以下「本規約」）は、<?= htmlspecialchars($siteName) ?>（以下「当サービス」）の利用条件を定めるものです。
                ユーザーの皆様には、本規約に従って当サービスをご利用いただきます。
            </p>
            
            <h2 class="text-xl font-bold text-gray-800 mt-8 mb-4">第1条（適用）</h2>
            <p class="text-gray-600 mb-6">
                本規約は、ユーザーと当サービス運営者との間の当サービスの利用に関わる一切の関係に適用されるものとします。
            </p>
            
            <h2 class="text-xl font-bold text-gray-800 mt-8 mb-4">第2条（会員登録）</h2>
            <ol class="list-decimal pl-6 text-gray-600 mb-6 space-y-2">
                <li>会員登録を希望する方は、本規約に同意の上、所定の方法で登録を行うものとします。</li>
                <li>当サービスは、以下の場合に会員登録を拒否することがあります。
                    <ul class="list-disc pl-6 mt-2 space-y-1">
                        <li>虚偽の情報を登録した場合</li>
                        <li>過去に規約違反等により登録を抹消されたことがある場合</li>
                        <li>その他、当サービスが不適当と判断した場合</li>
                    </ul>
                </li>
            </ol>
            
            <h2 class="text-xl font-bold text-gray-800 mt-8 mb-4">第3条（アカウント管理）</h2>
            <ol class="list-decimal pl-6 text-gray-600 mb-6 space-y-2">
                <li>ユーザーは、自己の責任においてアカウント情報を管理するものとします。</li>
                <li>アカウント情報の管理不十分、第三者の使用等による損害について、当サービスは一切の責任を負いません。</li>
            </ol>
            
            <h2 class="text-xl font-bold text-gray-800 mt-8 mb-4">第4条（商品の購入）</h2>
            <ol class="list-decimal pl-6 text-gray-600 mb-6 space-y-2">
                <li>ユーザーは、当サービスの定める方法により商品を購入することができます。</li>
                <li>売買契約は、当サービスが注文を承諾した時点で成立するものとします。</li>
                <li>デジタル商品は、その性質上、購入後の返品・返金はお受けできません。</li>
            </ol>
            
            <h2 class="text-xl font-bold text-gray-800 mt-8 mb-4">第5条（デジタル商品の利用）</h2>
            <ol class="list-decimal pl-6 text-gray-600 mb-6 space-y-2">
                <li>購入したデジタル商品は、ユーザー個人の私的利用に限り閲覧することができます。</li>
                <li>以下の行為は禁止されています。
                    <ul class="list-disc pl-6 mt-2 space-y-1">
                        <li>商品の複製、転載、再配布</li>
                        <li>商品の改変、二次利用</li>
                        <li>アカウントの共有、譲渡</li>
                        <li>技術的保護手段の回避</li>
                    </ul>
                </li>
            </ol>
            
            <h2 class="text-xl font-bold text-gray-800 mt-8 mb-4">第6条（禁止事項）</h2>
            <p class="text-gray-600 mb-4">ユーザーは、以下の行為をしてはなりません。</p>
            <ul class="list-disc pl-6 text-gray-600 mb-6 space-y-2">
                <li>法令または公序良俗に違反する行為</li>
                <li>犯罪行為に関連する行為</li>
                <li>当サービスのサーバーやネットワークに過度な負荷をかける行為</li>
                <li>当サービスの運営を妨害する行為</li>
                <li>不正アクセス、クラッキング行為</li>
                <li>他のユーザーに成りすます行為</li>
                <li>その他、当サービスが不適切と判断する行為</li>
            </ul>
            
            <h2 class="text-xl font-bold text-gray-800 mt-8 mb-4">第7条（サービスの変更・停止）</h2>
            <p class="text-gray-600 mb-6">
                当サービスは、事前の通知なく、サービスの内容を変更し、または提供を停止することができるものとします。
                これによりユーザーに生じた損害について、当サービスは一切の責任を負いません。
            </p>
            
            <h2 class="text-xl font-bold text-gray-800 mt-8 mb-4">第8条（免責事項）</h2>
            <ol class="list-decimal pl-6 text-gray-600 mb-6 space-y-2">
                <li>当サービスは、本サービスに事実上または法律上の瑕疵がないことを保証しません。</li>
                <li>当サービスは、ユーザーに生じたあらゆる損害について、一切の責任を負いません。ただし、消費者契約法の適用がある場合はこの限りではありません。</li>
            </ol>
            
            <h2 class="text-xl font-bold text-gray-800 mt-8 mb-4">第9条（規約の変更）</h2>
            <p class="text-gray-600 mb-6">
                当サービスは、必要と判断した場合には、ユーザーに通知することなく本規約を変更することができます。
                変更後の規約は、当サービスに掲載した時点で効力を生じるものとします。
            </p>
            
            <h2 class="text-xl font-bold text-gray-800 mt-8 mb-4">第10条（準拠法・裁判管轄）</h2>
            <p class="text-gray-600 mb-6">
                本規約の解釈にあたっては日本法を準拠法とします。
                当サービスに関して紛争が生じた場合には、東京地方裁判所を第一審の専属的合意管轄裁判所とします。
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
