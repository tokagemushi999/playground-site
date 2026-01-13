<?php
/**
 * サービス詳細ページ（ココナラ風）
 */
session_start();
require_once '../../includes/db.php';
require_once '../../includes/site-settings.php';
require_once '../../includes/member-auth.php';
require_once '../../includes/cart.php';

$db = getDB();

// サービス取得
$serviceId = $_GET['id'] ?? 0;
$serviceSlug = $_GET['slug'] ?? '';

if ($serviceId) {
    $service = getService((int)$serviceId);
} elseif ($serviceSlug) {
    $service = getServiceBySlug($serviceSlug);
}

if (!$service || $service['status'] !== 'active') {
    header('Location: index.php');
    exit;
}

// 承認されていないサービスは表示しない（運営プレビュー用にクエリパラメータで回避可能）
$approvalStatus = $service['approval_status'] ?? 'approved';
if ($approvalStatus !== 'approved' && !isset($_GET['preview'])) {
    header('Location: index.php');
    exit;
}

// 閲覧数をインクリメント
incrementServiceViewCount($service['id']);

// お気に入り状態チェック
$isFavorite = false;
if (isLoggedIn()) {
    try {
        $member = getCurrentMember();
        if ($member) {
            $stmt = $db->prepare("SELECT id FROM service_favorites WHERE member_id = ? AND service_id = ?");
            $stmt->execute([$member['id'], $service['id']]);
            $isFavorite = (bool)$stmt->fetch();
        }
    } catch (PDOException $e) {}
}

// 関連データ取得
$plans = getServicePlans($service['id']);
$options = [];
$images = [];
$reviews = [];
$linkedWorks = [];

try {
    // オプション
    $stmt = $db->prepare("SELECT * FROM service_options WHERE service_id = ? AND is_active = 1 ORDER BY sort_order");
    $stmt->execute([$service['id']]);
    $options = $stmt->fetchAll();
} catch (PDOException $e) {}

try {
    // レビュー
    $stmt = $db->prepare("SELECT * FROM service_reviews WHERE service_id = ? AND is_published = 1 ORDER BY created_at DESC LIMIT 5");
    $stmt->execute([$service['id']]);
    $reviews = $stmt->fetchAll();
} catch (PDOException $e) {}

try {
    // 参考作品
    $stmt = $db->prepare("
        SELECT w.id, w.title, w.image, w.category
        FROM service_works sw
        JOIN works w ON sw.work_id = w.id
        WHERE sw.service_id = ?
        ORDER BY sw.sort_order
    ");
    $stmt->execute([$service['id']]);
    $linkedWorks = $stmt->fetchAll();
} catch (PDOException $e) {}

// クリエイター情報
$creator = null;
if ($service['creator_id']) {
    $stmt = $db->prepare("SELECT * FROM creators WHERE id = ?");
    $stmt->execute([$service['creator_id']]);
    $creator = $stmt->fetch();
}

// 最安プラン
$minPrice = $service['base_price'];
if (!empty($plans)) {
    $prices = array_column($plans, 'price');
    $minPrice = min($prices);
}

$pageTitle = $service['title'];
require_once '../includes/header.php';
?>

<style>
.service-gallery-thumb {
    cursor: pointer;
    transition: all 0.2s;
    border: 2px solid transparent;
}
.service-gallery-thumb:hover,
.service-gallery-thumb.active {
    border-color: #10B981;
}
.spec-table tr:nth-child(even) {
    background-color: #f9fafb;
}
.spec-table td {
    padding: 12px 16px;
}
.spec-table td:first-child {
    color: #6b7280;
    font-weight: 500;
    width: 120px;
}
.tag {
    display: inline-block;
    padding: 4px 12px;
    background: #f3f4f6;
    border-radius: 4px;
    font-size: 12px;
    color: #374151;
    margin: 2px;
}
</style>

<div class="max-w-6xl mx-auto px-4 py-6">
    <!-- パンくず -->
    <nav class="text-sm mb-4">
        <ol class="flex items-center gap-2 text-gray-500 flex-wrap">
            <li><a href="/store/" class="hover:text-green-600">ストア</a></li>
            <li>&gt;</li>
            <li><a href="index.php" class="hover:text-green-600">サービス</a></li>
            <?php if (!empty($service['category'])): ?>
            <li>&gt;</li>
            <li><a href="index.php?category=<?= urlencode($service['category']) ?>" class="hover:text-green-600"><?= htmlspecialchars($service['category']) ?></a></li>
            <?php endif; ?>
        </ol>
    </nav>
    
    <!-- タイトル -->
    <div class="flex items-start justify-between gap-4 mb-4">
        <h1 class="text-xl md:text-2xl font-bold text-gray-800"><?= htmlspecialchars($service['title']) ?></h1>
        <button onclick="toggleFavorite(<?= $service['id'] ?>)" 
                id="favoriteBtn"
                class="flex-shrink-0 w-10 h-10 rounded-full border-2 flex items-center justify-center transition
                       <?= $isFavorite ? 'bg-red-50 border-red-400 text-red-500' : 'bg-white border-gray-200 text-gray-400 hover:border-red-400 hover:text-red-500' ?>">
            <i class="<?= $isFavorite ? 'fas' : 'far' ?> fa-heart text-lg"></i>
        </button>
    </div>
    <?php if (!empty($service['description'])): ?>
    <p class="text-gray-600 mb-4"><?= htmlspecialchars($service['description']) ?></p>
    <?php endif; ?>
    
    <!-- 評価・実績 -->
    <div class="flex items-center gap-4 mb-6 text-sm">
        <?php if (!empty($service['rating_avg']) && $service['rating_avg'] > 0): ?>
        <div class="flex items-center gap-1">
            <span class="text-yellow-400">
                <?php for ($i = 1; $i <= 5; $i++): ?>
                <i class="fas fa-star<?= $i <= round($service['rating_avg']) ? '' : '-o' ?>"></i>
                <?php endfor; ?>
            </span>
            <span class="font-bold"><?= number_format($service['rating_avg'], 1) ?></span>
            <span class="text-gray-500">(<?= $service['rating_count'] ?? 0 ?>)</span>
        </div>
        <?php endif; ?>
        <?php if (!empty($service['order_count'])): ?>
        <span class="text-gray-500">販売実績 <?= number_format($service['order_count']) ?>件</span>
        <?php endif; ?>
    </div>
    
    <div class="grid lg:grid-cols-3 gap-8">
        <!-- 左側：メインコンテンツ -->
        <div class="lg:col-span-2 space-y-6">
            
            <!-- メイン画像・ギャラリー -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="aspect-video bg-gray-100 relative" id="mainImageContainer">
                    <?php if (!empty($service['thumbnail_image'])): ?>
                    <img src="/<?= htmlspecialchars($service['thumbnail_image']) ?>" 
                         class="w-full h-full object-cover" id="mainImage"
                         alt="<?= htmlspecialchars($service['title']) ?>">
                    <?php elseif (!empty($linkedWorks[0]['image'])): ?>
                    <img src="/<?= htmlspecialchars($linkedWorks[0]['image']) ?>" 
                         class="w-full h-full object-cover" id="mainImage"
                         alt="<?= htmlspecialchars($service['title']) ?>">
                    <?php else: ?>
                    <div class="w-full h-full flex items-center justify-center">
                        <i class="fas fa-paint-brush text-6xl text-gray-300"></i>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($linkedWorks) && count($linkedWorks) > 1): ?>
                <div class="p-3 border-t flex gap-2 overflow-x-auto">
                    <?php foreach ($linkedWorks as $i => $work): ?>
                    <div class="service-gallery-thumb w-16 h-16 flex-shrink-0 rounded overflow-hidden <?= $i === 0 ? 'active' : '' ?>"
                         onclick="changeMainImage('/<?= htmlspecialchars($work['image']) ?>', this)">
                        <img src="/<?= htmlspecialchars($work['image']) ?>" class="w-full h-full object-cover">
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- クリエイター情報 -->
            <?php if ($creator): ?>
            <div class="flex items-center gap-4 p-4 bg-white rounded-xl shadow-sm border border-gray-100">
                <a href="/creator/<?= htmlspecialchars($creator['slug'] ?? $creator['id']) ?>">
                    <?php if (!empty($creator['image'])): ?>
                    <img src="/<?= htmlspecialchars($creator['image']) ?>" class="w-14 h-14 rounded-full object-cover">
                    <?php else: ?>
                    <div class="w-14 h-14 rounded-full bg-gray-200 flex items-center justify-center">
                        <i class="fas fa-user text-gray-400"></i>
                    </div>
                    <?php endif; ?>
                </a>
                <div class="flex-1">
                    <a href="/creator/<?= htmlspecialchars($creator['slug'] ?? $creator['id']) ?>" class="font-bold text-gray-800 hover:text-green-600">
                        <?= htmlspecialchars($creator['name']) ?>
                    </a>
                    <?php if (!empty($creator['bio'])): ?>
                    <p class="text-sm text-gray-500 line-clamp-1"><?= htmlspecialchars($creator['bio']) ?></p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <!-- タブナビゲーション -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="border-b flex overflow-x-auto">
                    <button class="tab-btn px-4 sm:px-6 py-3 font-bold text-green-600 border-b-2 border-green-600 whitespace-nowrap text-sm sm:text-base" data-tab="content">サービス内容</button>
                    <button class="tab-btn px-4 sm:px-6 py-3 font-bold text-gray-500 hover:text-gray-700 whitespace-nowrap text-sm sm:text-base" data-tab="notes">購入時のお願い</button>
                    <?php if (!empty($reviews)): ?>
                    <button class="tab-btn px-4 sm:px-6 py-3 font-bold text-gray-500 hover:text-gray-700 whitespace-nowrap text-sm sm:text-base" data-tab="reviews">評価・感想</button>
                    <?php endif; ?>
                </div>
                
                <!-- サービス内容タブ -->
                <div class="tab-content p-6" id="tab-content">
                    <?php if (!empty($service['description_detail'])): ?>
                    <div class="prose max-w-none whitespace-pre-wrap text-gray-700">
<?= nl2br(htmlspecialchars($service['description_detail'])) ?>
                    </div>
                    <?php else: ?>
                    <p class="text-gray-500">詳細な説明はまだ登録されていません。</p>
                    <?php endif; ?>
                    
                    <?php if (!empty($service['workflow'])): ?>
                    <div class="mt-8 pt-6 border-t">
                        <h3 class="font-bold text-gray-800 mb-4"><i class="fas fa-tasks text-green-500 mr-2"></i>制作の流れ</h3>
                        <div class="whitespace-pre-wrap text-gray-700"><?= nl2br(htmlspecialchars($service['workflow'])) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- 購入時のお願いタブ -->
                <div class="tab-content p-6 hidden" id="tab-notes">
                    <?php if (!empty($service['purchase_notes'])): ?>
                    <div class="whitespace-pre-wrap text-gray-700"><?= nl2br(htmlspecialchars($service['purchase_notes'])) ?></div>
                    <?php else: ?>
                    <p class="text-gray-500">購入時の注意事項はまだ登録されていません。</p>
                    <?php endif; ?>
                </div>
                
                <!-- レビュータブ -->
                <?php if (!empty($reviews)): ?>
                <div class="tab-content p-6 hidden" id="tab-reviews">
                    <div class="space-y-4">
                        <?php foreach ($reviews as $review): ?>
                        <div class="border-b pb-4">
                            <div class="flex items-center gap-2 mb-2">
                                <span class="text-yellow-400">
                                    <?php for ($i = 1; $i <= 5; $i++): ?>
                                    <i class="fas fa-star<?= $i <= $review['rating'] ? '' : '-o' ?> text-sm"></i>
                                    <?php endfor; ?>
                                </span>
                                <span class="text-sm text-gray-500"><?= date('Y年n月', strtotime($review['created_at'])) ?></span>
                            </div>
                            <?php if (!empty($review['comment'])): ?>
                            <p class="text-gray-700"><?= nl2br(htmlspecialchars($review['comment'])) ?></p>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
            
            <!-- 参考作品 -->
            <?php if (!empty($linkedWorks)): ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="font-bold text-gray-800 mb-4"><i class="fas fa-images text-purple-500 mr-2"></i>参考作品</h3>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-3">
                    <?php foreach ($linkedWorks as $work): ?>
                    <div class="aspect-square rounded-lg overflow-hidden bg-gray-100 cursor-pointer hover:opacity-80 transition"
                         onclick="openWorkModal(<?= $work['id'] ?>)">
                        <img src="/<?= htmlspecialchars($work['image']) ?>" class="w-full h-full object-cover">
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
        </div>
        
        <!-- 右側：購入サイドバー -->
        <div class="lg:col-span-1">
            <div class="sticky top-4 space-y-4">
                <!-- 価格・購入ボタン -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <div class="text-3xl font-bold text-gray-800 mb-2">
                        ¥<?= number_format($minPrice) ?>
                        <span class="text-sm font-normal text-gray-500">〜</span>
                    </div>
                    
                    <div class="space-y-2 mb-4 text-sm text-gray-600">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-clock w-5 text-center text-gray-400"></i>
                            <span>お届け日数 <?= $service['delivery_days'] ?>日〜</span>
                        </div>
                        <div class="flex items-center gap-2">
                            <i class="fas fa-redo w-5 text-center text-gray-400"></i>
                            <span>無料修正 <?= $service['revision_limit'] ?>回</span>
                        </div>
                    </div>
                    
                    <a href="/store/request/<?= $service['id'] ?>" 
                       class="block w-full py-3 bg-green-500 text-white text-center font-bold rounded-lg hover:bg-green-600 transition">
                        <i class="fas fa-envelope mr-2"></i>見積もり・相談する
                    </a>
                </div>
                
                <!-- 提供詳細 -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                    <table class="w-full spec-table text-sm">
                        <?php if (!empty($service['provision_format'])): ?>
                        <tr>
                            <td>提供形式</td>
                            <td><?= htmlspecialchars($service['provision_format']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td>商用利用</td>
                            <td><?= ($service['commercial_use'] ?? 1) ? '<span class="text-green-600">✓</span>' : '<span class="text-gray-400">—</span>' ?></td>
                        </tr>
                        <tr>
                            <td>二次利用</td>
                            <td><?= ($service['secondary_use'] ?? 1) ? '<span class="text-green-600">✓</span>' : '<span class="text-gray-400">—</span>' ?></td>
                        </tr>
                        <?php if (!empty($service['planning_included']) || !empty($service['bgm_included'])): ?>
                        <tr>
                            <td>企画・構成</td>
                            <td><?= ($service['planning_included'] ?? 0) ? '<span class="text-green-600">✓</span>' : '<span class="text-gray-400">—</span>' ?></td>
                        </tr>
                        <tr>
                            <td>BGM・音声</td>
                            <td><?= ($service['bgm_included'] ?? 0) ? '<span class="text-green-600">✓</span>' : '<span class="text-gray-400">—</span>' ?></td>
                        </tr>
                        <?php endif; ?>
                        <tr>
                            <td>無料修正回数</td>
                            <td><?= $service['revision_limit'] ?? $service['free_revisions'] ?? 3 ?>回</td>
                        </tr>
                        <tr>
                            <td>ラフ提案数</td>
                            <td><?= $service['draft_proposals'] ?? 1 ?>案</td>
                        </tr>
                        <tr>
                            <td>お届け日数</td>
                            <td><?= $service['delivery_days'] ?>日（予定）</td>
                        </tr>
                        <?php if (!empty($service['style'])): ?>
                        <tr>
                            <td>スタイル</td>
                            <td><?= htmlspecialchars($service['style']) ?></td>
                        </tr>
                        <?php endif; ?>
                        <?php if (!empty($service['file_formats'])): ?>
                        <tr>
                            <td>ファイル形式</td>
                            <td><?= htmlspecialchars($service['file_formats']) ?></td>
                        </tr>
                        <?php endif; ?>
                    </table>
                    
                    <?php if (!empty($service['usage_tags'])): ?>
                    <div class="p-4 border-t">
                        <div class="text-sm text-gray-500 mb-2">用途</div>
                        <div>
                            <?php foreach (explode(',', $service['usage_tags']) as $tag): ?>
                            <span class="tag"><?= htmlspecialchars(trim($tag)) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($service['genre_tags'])): ?>
                    <div class="p-4 border-t">
                        <div class="text-sm text-gray-500 mb-2">ジャンル</div>
                        <div>
                            <?php foreach (explode(',', $service['genre_tags']) as $tag): ?>
                            <span class="tag"><?= htmlspecialchars(trim($tag)) ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <?php if (!empty($options)): ?>
                <!-- 有料オプション -->
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                    <h4 class="font-bold text-gray-800 mb-3"><i class="fas fa-plus-circle text-pink-500 mr-2"></i>有料オプション</h4>
                    <div class="space-y-2">
                        <?php foreach ($options as $option): ?>
                        <div class="flex justify-between items-center text-sm py-2 border-b last:border-0">
                            <span class="text-gray-700"><?= htmlspecialchars($option['name']) ?></span>
                            <span class="text-pink-600 font-bold">+ ¥<?= number_format($option['price']) ?></span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
            </div>
        </div>
    </div>
</div>

<script>
// 画像切り替え
function changeMainImage(src, thumb) {
    document.getElementById('mainImage').src = src;
    document.querySelectorAll('.service-gallery-thumb').forEach(el => el.classList.remove('active'));
    thumb.classList.add('active');
}

// タブ切り替え
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        const tabId = btn.dataset.tab;
        
        // ボタンのスタイル
        document.querySelectorAll('.tab-btn').forEach(b => {
            b.classList.remove('text-green-600', 'border-b-2', 'border-green-600');
            b.classList.add('text-gray-500');
        });
        btn.classList.add('text-green-600', 'border-b-2', 'border-green-600');
        btn.classList.remove('text-gray-500');
        
        // コンテンツの表示
        document.querySelectorAll('.tab-content').forEach(c => c.classList.add('hidden'));
        document.getElementById('tab-' + tabId).classList.remove('hidden');
    });
});

// お気に入りトグル
async function toggleFavorite(serviceId) {
    try {
        const response = await fetch('/store/api/service-favorites.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `action=toggle&service_id=${serviceId}`
        });
        const data = await response.json();
        
        if (data.login_required) {
            if (confirm('お気に入り機能を使うにはログインが必要です。ログインページに移動しますか？')) {
                window.location.href = '/store/login.php?redirect=' + encodeURIComponent(window.location.href);
            }
            return;
        }
        
        if (data.success) {
            const btn = document.getElementById('favoriteBtn');
            const icon = btn.querySelector('i');
            
            if (data.is_favorite) {
                btn.classList.remove('bg-white', 'border-gray-200', 'text-gray-400');
                btn.classList.add('bg-red-50', 'border-red-400', 'text-red-500');
                icon.classList.remove('far');
                icon.classList.add('fas');
            } else {
                btn.classList.add('bg-white', 'border-gray-200', 'text-gray-400');
                btn.classList.remove('bg-red-50', 'border-red-400', 'text-red-500');
                icon.classList.add('far');
                icon.classList.remove('fas');
            }
        }
    } catch (error) {
        console.error('Error:', error);
    }
}
</script>

<?php require_once '../includes/footer.php'; ?>
