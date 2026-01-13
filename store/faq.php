<?php
/**
 * よくある質問（FAQ）ページ
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/member-auth.php';
require_once '../includes/cart.php';
require_once '../includes/site-settings.php';
require_once '../includes/defaults.php';

$db = getDB();

// FAQ取得
$faqs = [];
$faqCategories = [];
try {
    $stmt = $db->query("SELECT * FROM store_faq WHERE is_published = 1 ORDER BY sort_order, id");
    $faqs = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // カテゴリごとにグループ化
    foreach ($faqs as $faq) {
        $cat = $faq['category'];
        if (!isset($faqCategories[$cat])) {
            $faqCategories[$cat] = [];
        }
        $faqCategories[$cat][] = $faq;
    }
} catch (PDOException $e) {
    // テーブルがない場合はデフォルトFAQを使用
    foreach (DEFAULT_STORE_FAQ as $faq) {
        $cat = $faq['category'];
        if (!isset($faqCategories[$cat])) {
            $faqCategories[$cat] = [];
        }
        $faqCategories[$cat][] = $faq;
    }
}

$selectedCategory = $_GET['category'] ?? '';

$settings = getSiteSettings();
$siteName = $settings['site_name'] ?? 'ぷれぐら！PLAYGROUND';
$cartCount = getCartCount();

$pageTitle = 'よくある質問';
include 'includes/header.php';
?>

<div class="max-w-3xl mx-auto">
    <h1 class="text-2xl font-bold text-gray-800 mb-6">
        <i class="fas fa-question-circle text-store-primary mr-2"></i>よくある質問
    </h1>
    
    <!-- カテゴリタブ -->
    <div class="flex gap-2 mb-6 overflow-x-auto pb-2">
        <a href="?" class="flex-shrink-0 px-4 py-2 rounded-full text-sm <?= !$selectedCategory ? 'bg-store-primary text-white' : 'bg-white text-gray-600 border' ?>">
            すべて
        </a>
        <?php foreach (array_keys($faqCategories) as $cat): ?>
        <a href="?category=<?= urlencode($cat) ?>" 
           class="flex-shrink-0 px-4 py-2 rounded-full text-sm <?= $selectedCategory === $cat ? 'bg-store-primary text-white' : 'bg-white text-gray-600 border' ?>">
            <?= htmlspecialchars($cat) ?>
        </a>
        <?php endforeach; ?>
    </div>
    
    <!-- FAQ一覧 -->
    <div class="space-y-6">
        <?php foreach ($faqCategories as $category => $items): ?>
        <?php if ($selectedCategory && $selectedCategory !== $category) continue; ?>
        <section>
            <h2 class="text-lg font-bold text-gray-800 mb-3 flex items-center gap-2">
                <span class="w-1 h-6 bg-store-primary rounded"></span>
                <?= htmlspecialchars($category) ?>
            </h2>
            <div class="bg-white rounded-xl shadow-sm overflow-hidden">
                <?php foreach ($items as $i => $faq): ?>
                <details class="group <?= $i > 0 ? 'border-t border-gray-100' : '' ?>">
                    <summary class="flex items-center justify-between gap-4 px-4 py-4 cursor-pointer hover:bg-gray-50 transition">
                        <span class="flex items-start gap-3">
                            <span class="text-store-primary font-bold">Q.</span>
                            <span class="font-bold text-gray-800"><?= htmlspecialchars($faq['question']) ?></span>
                        </span>
                        <i class="fas fa-chevron-down text-gray-400 transition group-open:rotate-180"></i>
                    </summary>
                    <div class="px-4 pb-4 pt-0">
                        <div class="flex items-start gap-3 pl-0 ml-6 border-l-2 border-store-primary/20 pl-4">
                            <span class="text-store-primary font-bold">A.</span>
                            <p class="text-gray-600 text-sm leading-relaxed"><?= nl2br(htmlspecialchars($faq['answer'])) ?></p>
                        </div>
                    </div>
                </details>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endforeach; ?>
    </div>
    
    <!-- お問い合わせへの導線 -->
    <section class="mt-8 bg-gradient-to-r from-orange-50 to-yellow-50 rounded-xl p-6 text-center">
        <i class="fas fa-headset text-4xl text-store-primary mb-3"></i>
        <h3 class="text-lg font-bold text-gray-800 mb-2">解決しませんでしたか？</h3>
        <p class="text-sm text-gray-600 mb-4">
            お気軽にお問い合わせください。<br>
            通常2〜3営業日以内にご返信いたします。
        </p>
        <a href="/store/contact.php" class="inline-flex items-center gap-2 px-6 py-3 bg-store-primary text-white rounded-full font-bold hover:bg-orange-600 transition">
            <i class="fas fa-envelope"></i> お問い合わせ
        </a>
    </section>
</div>

<style>
details summary::-webkit-details-marker {
    display: none;
}
details summary {
    list-style: none;
}
</style>

<?php include 'includes/footer.php'; ?>
