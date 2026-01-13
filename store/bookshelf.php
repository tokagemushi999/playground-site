<?php
/**
 * 本棚ページ - 購入済みデジタル商品
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/member-auth.php';
require_once '../includes/site-settings.php';
require_once '../includes/cart.php';

requireMemberAuth();

$member = getCurrentMember();
$db = getDB();

// 本棚のアイテムを取得
$stmt = $db->prepare("
    SELECT 
        b.*,
        p.name,
        p.image,
        p.slug,
        p.related_work_id,
        p.description,
        (SELECT COUNT(*) FROM work_pages wp WHERE wp.work_id = p.related_work_id) as page_count
    FROM member_bookshelf b
    JOIN products p ON b.product_id = p.id
    WHERE b.member_id = ?
    ORDER BY b.created_at DESC
");
$stmt->execute([$member['id']]);
$bookshelfItems = $stmt->fetchAll(PDO::FETCH_ASSOC);

$settings = getSiteSettings();
$siteName = $settings['site_name'] ?? 'CREATORS PLAYGROUND';
$cartCount = function_exists('getCartCount') ? getCartCount() : 0;

$pageTitle = '本棚';
include 'includes/header.php';
?>

<!-- パンくずリスト -->
<nav class="text-sm text-gray-500 mb-6">
    <a href="/store/mypage.php" class="hover:text-store-primary">マイページ</a>
    <span class="mx-2">/</span>
    <span class="text-gray-800">本棚</span>
</nav>

<h1 class="text-xl font-bold text-gray-800 mb-6">
    <i class="fas fa-book text-store-primary mr-2"></i>本棚
</h1>

<?php if (empty($bookshelfItems)): ?>
<!-- 空の状態 -->
<div class="bg-white rounded-lg shadow-sm p-12 text-center">
    <div class="w-20 h-20 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-6">
        <i class="fas fa-book-open text-4xl text-gray-300"></i>
    </div>
    <h2 class="text-lg font-bold text-gray-800 mb-2">本棚は空です</h2>
    <p class="text-gray-500 mb-6">購入したデジタル作品がここに並びます</p>
    <a href="/store/" class="inline-block px-6 py-2 bg-store-primary text-white rounded font-bold hover:bg-orange-600 transition">
        <i class="fas fa-store mr-2"></i>ストアを見る
    </a>
</div>
<?php else: ?>
<!-- 本棚グリッド -->
<div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
    <?php foreach ($bookshelfItems as $item): ?>
    <div class="group">
    <?php 
        // 作品IDがあれば漫画ビューアーへ、なければ商品詳細へ
        $linkUrl = $item['related_work_id'] ? "/manga/{$item['related_work_id']}" : "/store/product.php?id={$item['product_id']}";
        ?>
        <a href="<?= $linkUrl ?>" class="block relative">
            <div class="aspect-[3/4] rounded-lg overflow-hidden shadow-sm group-hover:shadow-md transition-shadow bg-gray-100">
                <?php if ($item['image']): ?>
                <img src="/<?= htmlspecialchars($item['image']) ?>" 
                     alt="<?= htmlspecialchars($item['name']) ?>"
                     class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                <?php else: ?>
                <div class="w-full h-full flex items-center justify-center">
                    <i class="fas fa-book text-4xl text-gray-300"></i>
                </div>
                <?php endif; ?>
                
                <!-- ホバー時のオーバーレイ -->
                <div class="absolute inset-0 bg-black/60 opacity-0 group-hover:opacity-100 transition-opacity flex items-center justify-center">
                    <span class="bg-white text-gray-800 px-4 py-2 rounded-full font-bold text-sm">
                        <i class="fas fa-book-reader mr-1"></i>読む
                    </span>
                </div>
                
                <!-- ページ数バッジ -->
                <?php if ($item['page_count'] > 0): ?>
                <div class="absolute bottom-2 right-2">
                    <span class="bg-green-500 text-white text-xs font-bold px-2 py-0.5 rounded">
                        全<?= $item['page_count'] ?>P
                    </span>
                </div>
                <?php endif; ?>
            </div>
            
            <?php if ($item['is_favorite']): ?>
            <div class="absolute top-2 right-2 w-7 h-7 bg-pink-500 rounded-full flex items-center justify-center shadow">
                <i class="fas fa-heart text-white text-xs"></i>
            </div>
            <?php endif; ?>
        </a>
        
        <div class="mt-2">
            <h3 class="font-bold text-gray-800 text-sm truncate"><?= htmlspecialchars($item['name']) ?></h3>
            <?php if ($item['last_read_at']): ?>
            <p class="text-xs text-gray-500 mt-1">
                <i class="fas fa-clock mr-1"></i>
                <?= date('n/j', strtotime($item['last_read_at'])) ?>に読んだ
                <?php if ($item['last_read_page']): ?>
                (<?= $item['last_read_page'] ?>P)
                <?php endif; ?>
            </p>
            <?php else: ?>
            <p class="text-xs text-green-600 mt-1">
                <i class="fas fa-sparkles mr-1"></i>未読
            </p>
            <?php endif; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<?php include 'includes/footer.php'; ?>
