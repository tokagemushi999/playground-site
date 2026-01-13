<?php
/**
 * クリエイター一覧ページ
 */
require_once '../includes/db.php';
require_once '../includes/site-settings.php';

$db = getDB();

// フィルター
$nominated = isset($_GET['nominated']) ? 1 : 0; // リクエスト受付中のみ
$category = $_GET['category'] ?? '';
$search = $_GET['q'] ?? '';

// クエリ構築
$where = ['c.is_active = 1'];
$params = [];

// accepts_requestsカラムの存在確認
$hasAcceptsRequests = false;
try {
    $columns = $db->query("SHOW COLUMNS FROM creators")->fetchAll(PDO::FETCH_COLUMN);
    $hasAcceptsRequests = in_array('accepts_requests', $columns);
} catch (PDOException $e) {}

if ($nominated && $hasAcceptsRequests) {
    $where[] = 'c.accepts_requests = 1';
}

if (!empty($search)) {
    $where[] = '(c.name LIKE ? OR c.bio LIKE ?)';
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$whereClause = implode(' AND ', $where);

// クリエイター一覧取得
$sql = "
    SELECT c.*, 
           (SELECT COUNT(*) FROM works w WHERE w.creator_id = c.id AND w.is_active = 1) as work_count,
           (SELECT COUNT(*) FROM services s WHERE s.creator_id = c.id AND s.status = 'active') as service_count
    FROM creators c
    WHERE $whereClause
    ORDER BY c.sort_order, c.name
";
$stmt = $db->prepare($sql);
$stmt->execute($params);
$creators = $stmt->fetchAll();

// カテゴリ一覧（作品カテゴリから取得）
$categories = $db->query("SELECT DISTINCT category FROM works WHERE category IS NOT NULL AND category != '' ORDER BY category")->fetchAll(PDO::FETCH_COLUMN);

$pageTitle = $nominated ? 'リクエスト受付中のクリエイター' : 'クリエイター一覧';
$pageDescription = 'ぷれぐら！で活動するクリエイター一覧です。';

// ヘッダー
include '../store/includes/header.php';
?>

<div class="max-w-6xl mx-auto px-4 py-8">
    <!-- ヘッダー -->
    <div class="mb-8">
        <h1 class="text-2xl md:text-3xl font-bold text-gray-800 mb-2">
            <?php if ($nominated): ?>
            <i class="fas fa-hand-paper text-green-500 mr-2"></i>リクエスト受付中のクリエイター
            <?php else: ?>
            <i class="fas fa-users text-purple-500 mr-2"></i>クリエイター一覧
            <?php endif; ?>
        </h1>
        <p class="text-gray-600">イラスト、アニメーション、デザインなど様々な分野のクリエイターが活動しています。</p>
    </div>
    
    <!-- フィルター -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-6">
        <form method="GET" class="flex flex-wrap gap-4 items-center">
            <!-- 検索 -->
            <div class="flex-1 min-w-[200px]">
                <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" 
                       placeholder="クリエイター名で検索..."
                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-purple-400 outline-none">
            </div>
            
            <!-- リクエスト受付フィルター -->
            <label class="flex items-center gap-2 cursor-pointer">
                <input type="checkbox" name="nominated" value="1" <?= $nominated ? 'checked' : '' ?>
                       onchange="this.form.submit()"
                       class="w-5 h-5 text-green-500 rounded">
                <span class="text-sm font-medium text-gray-700">
                    <i class="fas fa-check-circle text-green-500 mr-1"></i>リクエスト受付中のみ
                </span>
            </label>
            
            <button type="submit" class="px-4 py-2 bg-purple-500 text-white rounded-lg font-bold hover:bg-purple-600 transition">
                <i class="fas fa-search mr-1"></i>検索
            </button>
            
            <?php if ($nominated || !empty($search)): ?>
            <a href="index.php" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg hover:bg-gray-300 transition">
                クリア
            </a>
            <?php endif; ?>
        </form>
    </div>
    
    <!-- クリエイター一覧 -->
    <?php if (empty($creators)): ?>
    <div class="text-center py-16 bg-white rounded-xl shadow-sm border border-gray-100">
        <i class="fas fa-users text-gray-300 text-5xl mb-4"></i>
        <p class="text-gray-500">該当するクリエイターが見つかりませんでした。</p>
    </div>
    <?php else: ?>
    <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-6">
        <?php foreach ($creators as $creator): ?>
        <a href="/creator/<?= htmlspecialchars($creator['slug'] ?? $creator['id']) ?>" 
           class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden hover:shadow-md transition group">
            <!-- カバー画像 -->
            <div class="h-24 bg-gradient-to-r from-purple-400 to-pink-400 relative">
                <?php if (!empty($creator['cover_image'])): ?>
                <img src="/<?= htmlspecialchars($creator['cover_image']) ?>" class="w-full h-full object-cover">
                <?php endif; ?>
            </div>
            
            <!-- プロフィール -->
            <div class="px-4 pb-4 relative">
                <!-- アイコン -->
                <div class="w-20 h-20 rounded-full border-4 border-white bg-gray-100 -mt-10 relative overflow-hidden shadow-lg">
                    <?php if (!empty($creator['image'])): ?>
                    <img src="/<?= htmlspecialchars($creator['image']) ?>" class="w-full h-full object-cover">
                    <?php else: ?>
                    <div class="w-full h-full flex items-center justify-center bg-gray-200">
                        <i class="fas fa-user text-gray-400 text-2xl"></i>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- リクエスト受付バッジ -->
                <?php if ($hasAcceptsRequests && !empty($creator['accepts_requests'])): ?>
                <span class="absolute top-2 right-4 px-2 py-1 bg-green-500 text-white text-xs font-bold rounded-full">
                    <i class="fas fa-check mr-1"></i>受付中
                </span>
                <?php endif; ?>
                
                <!-- 名前・説明 -->
                <h3 class="font-bold text-lg text-gray-800 mt-2 group-hover:text-purple-600 transition">
                    <?= htmlspecialchars($creator['name']) ?>
                </h3>
                
                <?php if (!empty($creator['bio'])): ?>
                <p class="text-sm text-gray-500 mt-1 line-clamp-2"><?= htmlspecialchars($creator['bio']) ?></p>
                <?php endif; ?>
                
                <!-- 統計 -->
                <div class="flex gap-4 mt-3 text-sm text-gray-500">
                    <?php if ($creator['work_count'] > 0): ?>
                    <span><i class="fas fa-images mr-1"></i><?= $creator['work_count'] ?>作品</span>
                    <?php endif; ?>
                    <?php if ($creator['service_count'] > 0): ?>
                    <span><i class="fas fa-paint-brush mr-1"></i><?= $creator['service_count'] ?>サービス</span>
                    <?php endif; ?>
                </div>
                
                <!-- SNSリンク -->
                <?php if (!empty($creator['twitter']) || !empty($creator['instagram'])): ?>
                <div class="flex gap-3 mt-3">
                    <?php if (!empty($creator['twitter'])): ?>
                    <span class="text-gray-400"><i class="fab fa-twitter"></i></span>
                    <?php endif; ?>
                    <?php if (!empty($creator['instagram'])): ?>
                    <span class="text-gray-400"><i class="fab fa-instagram"></i></span>
                    <?php endif; ?>
                    <?php if (!empty($creator['youtube'])): ?>
                    <span class="text-gray-400"><i class="fab fa-youtube"></i></span>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            </div>
        </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
    
    <!-- 補足情報 -->
    <div class="mt-12 bg-gradient-to-r from-purple-50 to-pink-50 rounded-xl p-6 border border-purple-100">
        <h2 class="font-bold text-lg text-gray-800 mb-3">
            <i class="fas fa-info-circle text-purple-500 mr-2"></i>クリエイターへの依頼について
        </h2>
        <ul class="text-gray-600 space-y-2">
            <li><i class="fas fa-check text-green-500 mr-2"></i>「受付中」のクリエイターにはサービスページから見積もり依頼ができます</li>
            <li><i class="fas fa-check text-green-500 mr-2"></i>見積もり内容を確認してから決済するので安心です</li>
            <li><i class="fas fa-check text-green-500 mr-2"></i>メッセージ機能でクリエイターと直接やり取りできます</li>
            <li><i class="fas fa-check text-green-500 mr-2"></i>納品完了後に評価をお願いしています</li>
        </ul>
    </div>
</div>

<?php include '../store/includes/footer.php'; ?>
