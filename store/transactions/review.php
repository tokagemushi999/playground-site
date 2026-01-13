<?php
/**
 * レビュー投稿ページ
 */
session_start();
require_once '../../includes/db.php';
require_once '../../includes/csrf.php';
require_once '../../includes/site-settings.php';
require_once '../../includes/member-auth.php';
require_once '../../includes/transactions.php';

$db = getDB();
$member = getCurrentMember();

$transactionCode = $_GET['transaction'] ?? '';
$transaction = $transactionCode ? getTransactionByCode($transactionCode) : null;

// アクセス権限チェック
if (!$transaction) {
    header('Location: /store/');
    exit;
}

// 会員の場合は所有者確認
if ($member && $transaction['member_id'] && $transaction['member_id'] != $member['id']) {
    header('Location: /store/');
    exit;
}

// 完了済み取引のみレビュー可能
if ($transaction['status'] !== 'completed') {
    header('Location: /store/transactions/' . $transactionCode);
    exit;
}

// 既存レビュー確認
$existingReview = null;
$stmt = $db->prepare("SELECT * FROM service_reviews WHERE transaction_id = ?");
$stmt->execute([$transaction['id']]);
$existingReview = $stmt->fetch();

if ($existingReview) {
    // 既にレビュー済み
    header('Location: /store/transactions/' . $transactionCode . '?review_exists=1');
    exit;
}

$error = '';
$success = false;

// レビュー投稿処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = '不正なリクエストです。';
    } else {
        $rating = (int)($_POST['rating'] ?? 0);
        $title = trim($_POST['title'] ?? '');
        $comment = trim($_POST['comment'] ?? '');
        
        if ($rating < 1 || $rating > 5) {
            $error = '評価を選択してください。';
        } else {
            try {
                $stmt = $db->prepare("
                    INSERT INTO service_reviews (
                        transaction_id, service_id, creator_id, member_id, guest_name,
                        rating, title, comment
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $transaction['id'],
                    $transaction['service_id'],
                    $transaction['creator_id'],
                    $member ? $member['id'] : null,
                    $member ? null : ($transaction['guest_name'] ?? 'ゲスト'),
                    $rating,
                    $title,
                    $comment
                ]);
                
                // クリエイターにメール通知
                sendTransactionEmail($transaction['id'], 'message_received', 'creator');
                
                header('Location: /store/transactions/' . $transactionCode . '?review_posted=1');
                exit;
                
            } catch (PDOException $e) {
                $error = 'エラーが発生しました。';
            }
        }
    }
}

$pageTitle = 'レビュー投稿';
require_once '../includes/header.php';
?>

<div class="max-w-2xl mx-auto px-4 py-8">
    <a href="/store/transactions/<?= htmlspecialchars($transactionCode) ?>" class="text-gray-500 hover:text-gray-700 mb-4 inline-block">
        <i class="fas fa-arrow-left mr-1"></i>取引詳細に戻る
    </a>
    
    <h1 class="text-2xl font-bold text-gray-800 mb-6">
        <i class="fas fa-star text-yellow-500 mr-2"></i>レビューを投稿
    </h1>
    
    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-600 p-4 rounded-lg mb-6">
        <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>
    
    <!-- サービス情報 -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-6">
        <div class="flex items-center gap-4">
            <div class="w-16 h-16 bg-gray-100 rounded-lg overflow-hidden">
                <?php if (!empty($transaction['service_image'])): ?>
                <img src="/<?= htmlspecialchars($transaction['service_image']) ?>" class="w-full h-full object-cover">
                <?php else: ?>
                <div class="w-full h-full flex items-center justify-center">
                    <i class="fas fa-paint-brush text-gray-300"></i>
                </div>
                <?php endif; ?>
            </div>
            <div>
                <h2 class="font-bold text-gray-800"><?= htmlspecialchars($transaction['service_title']) ?></h2>
                <p class="text-sm text-gray-500"><?= htmlspecialchars($transaction['creator_name']) ?></p>
            </div>
        </div>
    </div>
    
    <!-- レビューフォーム -->
    <form method="POST" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        
        <!-- 評価 -->
        <div class="mb-6">
            <label class="block text-sm font-bold text-gray-700 mb-3">評価 <span class="text-red-500">*</span></label>
            <div class="flex gap-2" id="ratingStars">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <label class="cursor-pointer">
                    <input type="radio" name="rating" value="<?= $i ?>" class="hidden" required>
                    <i class="fas fa-star text-4xl text-gray-300 hover:text-yellow-400 transition star-icon" data-rating="<?= $i ?>"></i>
                </label>
                <?php endfor; ?>
            </div>
            <p class="text-sm text-gray-500 mt-2" id="ratingText">クリックで評価を選択</p>
        </div>
        
        <!-- タイトル -->
        <div class="mb-4">
            <label class="block text-sm font-bold text-gray-700 mb-2">タイトル（任意）</label>
            <input type="text" name="title" maxlength="100"
                   placeholder="例: 素晴らしい対応でした！"
                   class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
        </div>
        
        <!-- コメント -->
        <div class="mb-6">
            <label class="block text-sm font-bold text-gray-700 mb-2">コメント（任意）</label>
            <textarea name="comment" rows="5"
                      placeholder="サービスの感想やクリエイターへのメッセージをお書きください..."
                      class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"></textarea>
        </div>
        
        <button type="submit" class="w-full py-4 bg-yellow-500 text-white font-bold rounded-lg hover:bg-yellow-600 transition">
            <i class="fas fa-paper-plane mr-2"></i>レビューを投稿する
        </button>
    </form>
</div>

<script>
document.querySelectorAll('.star-icon').forEach(star => {
    star.addEventListener('click', function() {
        const rating = parseInt(this.dataset.rating);
        const stars = document.querySelectorAll('.star-icon');
        
        stars.forEach((s, index) => {
            if (index < rating) {
                s.classList.remove('text-gray-300');
                s.classList.add('text-yellow-400');
            } else {
                s.classList.remove('text-yellow-400');
                s.classList.add('text-gray-300');
            }
        });
        
        const texts = ['', '不満', 'やや不満', '普通', '満足', '大満足'];
        document.getElementById('ratingText').textContent = texts[rating] + '（' + rating + '点）';
    });
    
    star.addEventListener('mouseenter', function() {
        const rating = parseInt(this.dataset.rating);
        const stars = document.querySelectorAll('.star-icon');
        
        stars.forEach((s, index) => {
            if (index < rating) {
                s.classList.add('text-yellow-400');
            }
        });
    });
    
    star.addEventListener('mouseleave', function() {
        const selected = document.querySelector('input[name="rating"]:checked');
        const selectedRating = selected ? parseInt(selected.value) : 0;
        const stars = document.querySelectorAll('.star-icon');
        
        stars.forEach((s, index) => {
            if (index < selectedRating) {
                s.classList.remove('text-gray-300');
                s.classList.add('text-yellow-400');
            } else {
                s.classList.remove('text-yellow-400');
                s.classList.add('text-gray-300');
            }
        });
    });
});
</script>

<?php require_once '../includes/footer.php'; ?>
