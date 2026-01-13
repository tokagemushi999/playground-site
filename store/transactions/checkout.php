<?php
/**
 * 取引決済ページ（Stripe）
 */
session_start();
require_once '../../includes/db.php';
require_once '../../includes/csrf.php';
require_once '../../includes/site-settings.php';
require_once '../../includes/member-auth.php';
require_once '../../includes/transactions.php';
require_once '../../includes/stripe-config.php';

$db = getDB();
$member = getCurrentMember();

$transactionId = (int)($_GET['transaction'] ?? 0);
$transaction = getTransaction($transactionId);

// アクセス権限チェック
if (!$transaction) {
    header('Location: /store/');
    exit;
}

// 会員の場合はID一致を確認
if ($member && $transaction['member_id'] && $transaction['member_id'] != $member['id']) {
    header('Location: /store/');
    exit;
}

// 決済待ちステータスのみ
if ($transaction['status'] !== 'payment_pending') {
    header('Location: /store/transactions/' . $transaction['transaction_code']);
    exit;
}

// 金額チェック
if (empty($transaction['total_amount']) || $transaction['total_amount'] <= 0) {
    die('金額が設定されていません。');
}

$error = '';
$stripePublicKey = getStripePublicKey();

// Stripe決済処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['payment_method_id'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = '不正なリクエストです。';
    } else {
        try {
            $stripe = getStripeClient();
            
            // PaymentIntent作成
            $paymentIntent = $stripe->paymentIntents->create([
                'amount' => $transaction['total_amount'],
                'currency' => 'jpy',
                'payment_method' => $_POST['payment_method_id'],
                'confirm' => true,
                'description' => 'サービス取引: ' . $transaction['transaction_code'],
                'metadata' => [
                    'transaction_id' => $transaction['id'],
                    'transaction_code' => $transaction['transaction_code'],
                    'service_id' => $transaction['service_id'],
                    'creator_id' => $transaction['creator_id']
                ],
                'automatic_payment_methods' => [
                    'enabled' => true,
                    'allow_redirects' => 'never'
                ]
            ]);
            
            if ($paymentIntent->status === 'succeeded') {
                // 決済成功 - ステータス更新
                updateTransactionStatus($transaction['id'], 'paid', [
                    'payment_method' => 'stripe',
                    'payment_id' => $paymentIntent->id,
                    'paid_at' => date('Y-m-d H:i:s')
                ]);
                
                // システムメッセージ
                addTransactionMessage(
                    $transaction['id'],
                    'customer',
                    $member ? $member['id'] : null,
                    $member ? $member['name'] : ($transaction['guest_name'] ?? 'ゲスト'),
                    '決済が完了しました。制作開始をお待ちください。',
                    'system'
                );
                
                // クリエイターにメール通知
                sendTransactionEmail($transaction['id'], 'payment_completed', 'creator');
                
                // 完了ページにリダイレクト
                header('Location: /store/transactions/payment-complete.php?code=' . $transaction['transaction_code']);
                exit;
            } else {
                $error = '決済処理中にエラーが発生しました。';
            }
            
        } catch (\Stripe\Exception\CardException $e) {
            $error = 'カードエラー: ' . $e->getMessage();
        } catch (\Exception $e) {
            $error = '決済エラー: ' . $e->getMessage();
        }
    }
}

$pageTitle = '決済 - ' . $transaction['transaction_code'];
require_once '../includes/header.php';
?>

<div class="max-w-2xl mx-auto px-4 py-8">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">
        <i class="fas fa-credit-card text-green-500 mr-2"></i>決済
    </h1>
    
    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-600 p-4 rounded-lg mb-6">
        <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>
    
    <!-- 取引情報 -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
        <h2 class="font-bold text-gray-800 mb-4">ご注文内容</h2>
        
        <div class="flex items-start gap-4 mb-4 pb-4 border-b">
            <div class="w-16 h-16 bg-gray-100 rounded-lg overflow-hidden flex-shrink-0">
                <?php if (!empty($transaction['service_image'])): ?>
                <img src="/<?= htmlspecialchars($transaction['service_image']) ?>" class="w-full h-full object-cover">
                <?php else: ?>
                <div class="w-full h-full flex items-center justify-center">
                    <i class="fas fa-paint-brush text-gray-300"></i>
                </div>
                <?php endif; ?>
            </div>
            <div class="flex-1">
                <h3 class="font-bold text-gray-800"><?= htmlspecialchars($transaction['service_title']) ?></h3>
                <p class="text-sm text-gray-500"><?= htmlspecialchars($transaction['creator_name']) ?></p>
                <p class="text-sm text-gray-400 mt-1"><?= htmlspecialchars($transaction['transaction_code']) ?></p>
            </div>
        </div>
        
        <div class="space-y-2">
            <?php if ($transaction['final_price']): ?>
            <div class="flex justify-between">
                <span class="text-gray-600">小計</span>
                <span><?= formatPrice($transaction['final_price']) ?></span>
            </div>
            <?php endif; ?>
            <?php if ($transaction['tax_amount']): ?>
            <div class="flex justify-between text-sm">
                <span class="text-gray-500">消費税</span>
                <span><?= formatPrice($transaction['tax_amount']) ?></span>
            </div>
            <?php endif; ?>
            <div class="flex justify-between font-bold text-lg pt-2 border-t">
                <span>合計</span>
                <span class="text-green-600"><?= formatPrice($transaction['total_amount']) ?></span>
            </div>
        </div>
    </div>
    
    <!-- 決済フォーム -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <h2 class="font-bold text-gray-800 mb-4">お支払い方法</h2>
        
        <form id="payment-form" method="POST">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="payment_method_id" id="payment_method_id">
            
            <div id="card-element" class="p-4 border rounded-lg mb-4"></div>
            <div id="card-errors" class="text-red-500 text-sm mb-4"></div>
            
            <button type="submit" id="submit-button" 
                    class="w-full py-4 bg-green-500 text-white font-bold rounded-lg hover:bg-green-600 transition disabled:opacity-50 disabled:cursor-not-allowed">
                <span id="button-text">
                    <i class="fas fa-lock mr-2"></i><?= formatPrice($transaction['total_amount']) ?> を支払う
                </span>
                <span id="spinner" class="hidden">
                    <i class="fas fa-spinner fa-spin mr-2"></i>処理中...
                </span>
            </button>
        </form>
        
        <div class="mt-4 text-center">
            <img src="https://stripe.com/img/v3/home/twitter.png" alt="Stripe" class="h-6 inline opacity-50">
            <p class="text-xs text-gray-400 mt-2">安全な決済はStripeにより保護されています</p>
        </div>
    </div>
    
    <div class="mt-4 text-center">
        <a href="/store/transactions/<?= htmlspecialchars($transaction['transaction_code']) ?>" class="text-gray-500 hover:text-gray-700">
            <i class="fas fa-arrow-left mr-1"></i>取引詳細に戻る
        </a>
    </div>
</div>

<script src="https://js.stripe.com/v3/"></script>
<script>
const stripe = Stripe('<?= $stripePublicKey ?>');
const elements = stripe.elements();

const style = {
    base: {
        color: '#32325d',
        fontFamily: '"Helvetica Neue", Helvetica, sans-serif',
        fontSmoothing: 'antialiased',
        fontSize: '16px',
        '::placeholder': {
            color: '#aab7c4'
        }
    },
    invalid: {
        color: '#fa755a',
        iconColor: '#fa755a'
    }
};

const cardElement = elements.create('card', { style: style });
cardElement.mount('#card-element');

cardElement.on('change', function(event) {
    const displayError = document.getElementById('card-errors');
    if (event.error) {
        displayError.textContent = event.error.message;
    } else {
        displayError.textContent = '';
    }
});

const form = document.getElementById('payment-form');
const submitButton = document.getElementById('submit-button');
const buttonText = document.getElementById('button-text');
const spinner = document.getElementById('spinner');

form.addEventListener('submit', async function(event) {
    event.preventDefault();
    
    submitButton.disabled = true;
    buttonText.classList.add('hidden');
    spinner.classList.remove('hidden');
    
    const { paymentMethod, error } = await stripe.createPaymentMethod({
        type: 'card',
        card: cardElement,
    });
    
    if (error) {
        document.getElementById('card-errors').textContent = error.message;
        submitButton.disabled = false;
        buttonText.classList.remove('hidden');
        spinner.classList.add('hidden');
    } else {
        document.getElementById('payment_method_id').value = paymentMethod.id;
        form.submit();
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>
