<?php
/**
 * 見積もり依頼完了ページ
 */
session_start();
require_once '../../includes/db.php';
require_once '../../includes/site-settings.php';
require_once '../../includes/member-auth.php';
require_once '../../includes/transactions.php';

$code = $_GET['code'] ?? '';
$transaction = $code ? getTransactionByCode($code) : null;

if (!$transaction) {
    header('Location: /store/services/');
    exit;
}

$pageTitle = '見積もり依頼完了';
require_once '../includes/header.php';
?>

<div class="max-w-2xl mx-auto px-4 py-16 text-center">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8">
        <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6">
            <i class="fas fa-check text-4xl text-green-500"></i>
        </div>
        
        <h1 class="text-2xl font-bold text-gray-800 mb-4">見積もり依頼を送信しました</h1>
        
        <p class="text-gray-600 mb-6">
            クリエイターに見積もり依頼が送信されました。<br>
            見積もりが届くまでしばらくお待ちください。
        </p>
        
        <div class="bg-gray-50 rounded-lg p-4 mb-6">
            <p class="text-sm text-gray-500 mb-1">取引コード</p>
            <p class="text-xl font-mono font-bold text-gray-800"><?= htmlspecialchars($transaction['transaction_code']) ?></p>
        </div>
        
        <div class="space-y-3">
            <?php if (getCurrentMember()): ?>
            <a href="/store/transactions/<?= htmlspecialchars($transaction['transaction_code']) ?>" 
               class="block w-full py-3 bg-green-500 text-white font-bold rounded-lg hover:bg-green-600 transition">
                <i class="fas fa-comments mr-2"></i>取引詳細・メッセージを見る
            </a>
            <?php else: ?>
            <p class="text-sm text-gray-500 mb-4">
                メールアドレス宛に取引の進捗をお知らせします。<br>
                メッセージの確認は、メール内のリンクからアクセスできます。
            </p>
            <?php endif; ?>
            
            <a href="/store/services/" class="block w-full py-3 bg-gray-200 text-gray-700 font-bold rounded-lg hover:bg-gray-300 transition">
                サービス一覧に戻る
            </a>
        </div>
        
        <div class="mt-8 text-left">
            <h3 class="font-bold text-gray-800 mb-3">今後の流れ</h3>
            <div class="space-y-3">
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 bg-green-500 text-white rounded-full flex items-center justify-center flex-shrink-0 text-sm font-bold">✓</div>
                    <div>
                        <p class="font-bold text-gray-700">1. 見積もり依頼</p>
                        <p class="text-sm text-gray-500">完了しました</p>
                    </div>
                </div>
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 bg-gray-200 text-gray-500 rounded-full flex items-center justify-center flex-shrink-0 text-sm font-bold">2</div>
                    <div>
                        <p class="font-bold text-gray-700">2. 見積もり受信</p>
                        <p class="text-sm text-gray-500">クリエイターから見積もりが届きます</p>
                    </div>
                </div>
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 bg-gray-200 text-gray-500 rounded-full flex items-center justify-center flex-shrink-0 text-sm font-bold">3</div>
                    <div>
                        <p class="font-bold text-gray-700">3. 見積もり承諾</p>
                        <p class="text-sm text-gray-500">内容を確認して承諾します</p>
                    </div>
                </div>
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 bg-gray-200 text-gray-500 rounded-full flex items-center justify-center flex-shrink-0 text-sm font-bold">4</div>
                    <div>
                        <p class="font-bold text-gray-700">4. 決済</p>
                        <p class="text-sm text-gray-500">承諾後に決済を行います</p>
                    </div>
                </div>
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 bg-gray-200 text-gray-500 rounded-full flex items-center justify-center flex-shrink-0 text-sm font-bold">5</div>
                    <div>
                        <p class="font-bold text-gray-700">5. 制作・納品</p>
                        <p class="text-sm text-gray-500">メッセージでやり取りしながら完成へ</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
