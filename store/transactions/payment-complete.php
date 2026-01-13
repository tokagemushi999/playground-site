<?php
/**
 * 決済完了ページ
 */
session_start();
require_once '../../includes/db.php';
require_once '../../includes/site-settings.php';
require_once '../../includes/member-auth.php';
require_once '../../includes/transactions.php';

$code = $_GET['code'] ?? '';
$transaction = $code ? getTransactionByCode($code) : null;

if (!$transaction || !in_array($transaction['status'], ['paid', 'in_progress', 'delivered', 'completed'])) {
    header('Location: /store/');
    exit;
}

$pageTitle = '決済完了';
require_once '../includes/header.php';
?>

<div class="max-w-2xl mx-auto px-4 py-16 text-center">
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8">
        <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6">
            <i class="fas fa-check text-4xl text-green-500"></i>
        </div>
        
        <h1 class="text-2xl font-bold text-gray-800 mb-4">決済が完了しました</h1>
        
        <p class="text-gray-600 mb-6">
            ご購入ありがとうございます。<br>
            クリエイターが制作を開始しますので、しばらくお待ちください。
        </p>
        
        <div class="bg-gray-50 rounded-lg p-4 mb-6 text-left">
            <div class="flex justify-between mb-2">
                <span class="text-gray-500">取引コード</span>
                <span class="font-mono font-bold"><?= htmlspecialchars($transaction['transaction_code']) ?></span>
            </div>
            <div class="flex justify-between mb-2">
                <span class="text-gray-500">サービス</span>
                <span class="font-bold"><?= htmlspecialchars($transaction['service_title']) ?></span>
            </div>
            <div class="flex justify-between">
                <span class="text-gray-500">お支払い金額</span>
                <span class="font-bold text-green-600"><?= formatPrice($transaction['total_amount']) ?></span>
            </div>
        </div>
        
        <div class="space-y-3">
            <a href="/store/transactions/<?= htmlspecialchars($transaction['transaction_code']) ?>" 
               class="block w-full py-3 bg-green-500 text-white font-bold rounded-lg hover:bg-green-600 transition">
                <i class="fas fa-comments mr-2"></i>取引詳細・メッセージを見る
            </a>
            
            <a href="/store/mypage.php" class="block w-full py-3 bg-gray-200 text-gray-700 font-bold rounded-lg hover:bg-gray-300 transition">
                マイページに戻る
            </a>
        </div>
        
        <div class="mt-8 text-left">
            <h3 class="font-bold text-gray-800 mb-3">今後の流れ</h3>
            <div class="space-y-3">
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 bg-green-500 text-white rounded-full flex items-center justify-center flex-shrink-0 text-sm font-bold">✓</div>
                    <div>
                        <p class="font-bold text-gray-700">決済完了</p>
                    </div>
                </div>
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 bg-blue-500 text-white rounded-full flex items-center justify-center flex-shrink-0 text-sm font-bold">2</div>
                    <div>
                        <p class="font-bold text-gray-700">制作開始</p>
                        <p class="text-sm text-gray-500">クリエイターが制作を開始します</p>
                    </div>
                </div>
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 bg-gray-200 text-gray-500 rounded-full flex items-center justify-center flex-shrink-0 text-sm font-bold">3</div>
                    <div>
                        <p class="font-bold text-gray-700">納品</p>
                        <p class="text-sm text-gray-500">完成品が納品されます</p>
                    </div>
                </div>
                <div class="flex items-start gap-3">
                    <div class="w-8 h-8 bg-gray-200 text-gray-500 rounded-full flex items-center justify-center flex-shrink-0 text-sm font-bold">4</div>
                    <div>
                        <p class="font-bold text-gray-700">納品承認</p>
                        <p class="text-sm text-gray-500">内容を確認して承認すると完了です</p>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once '../includes/footer.php'; ?>
