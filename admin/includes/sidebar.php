<?php
/**
 * 管理画面共通サイドバー
 */

// 現在のページを取得
$currentPage = basename($_SERVER['PHP_SELF']);

// 新規問い合わせ数を取得
$sidebarDb = getDB();
$inquiriesNew = 0;
$ordersNew = 0;

try {
    // 問い合わせ数
    $columns = $sidebarDb->query("SHOW COLUMNS FROM inquiries")->fetchAll(PDO::FETCH_COLUMN);
    $hasIsArchived = in_array('is_archived', $columns);
    $hasStatus = in_array('status', $columns);
    
    if ($hasStatus && $hasIsArchived) {
        $inquiriesNew = $sidebarDb->query("SELECT COUNT(*) FROM inquiries WHERE status = 'new' AND (is_archived = 0 OR is_archived IS NULL)")->fetchColumn();
    } elseif ($hasStatus) {
        $inquiriesNew = $sidebarDb->query("SELECT COUNT(*) FROM inquiries WHERE status = 'new'")->fetchColumn();
    } else {
        $inquiriesNew = $sidebarDb->query("SELECT COUNT(*) FROM inquiries")->fetchColumn();
    }
    
    // 新規注文数（pending: 決済待ち, paid: 決済完了・未処理）
    $ordersNew = $sidebarDb->query("SELECT COUNT(*) FROM orders WHERE order_status IN ('pending', 'paid') AND payment_status = 'paid'")->fetchColumn();
} catch (PDOException $e) {
    $inquiriesNew = 0;
    $ordersNew = 0;
}

// アクティブ状態のクラスを返すヘルパー関数
function sidebarActiveClass($currentPage, $targetPage, $colorClass) {
    return $currentPage === $targetPage 
        ? $colorClass . ' text-white font-bold' 
        : 'hover:bg-gray-800';
}
?>
<!-- Sidebar Toggle Button (Mobile) -->
<button id="sidebar-toggle" class="lg:hidden fixed top-4 left-4 z-50 bg-gray-900 text-white p-3 rounded-lg shadow-lg hover:bg-gray-800 transition">
    <i class="fas fa-bars"></i>
</button>

<!-- Sidebar Overlay -->
<div id="sidebar-overlay" class="lg:hidden fixed inset-0 bg-black bg-opacity-50 z-40 hidden"></div>

<!-- Sidebar -->
<aside id="sidebar" class="fixed left-0 top-0 h-full w-64 bg-gray-900 text-white z-50 transform -translate-x-full lg:translate-x-0 transition-transform duration-300 flex flex-col">
    <!-- ヘッダー（固定） -->
    <div class="flex items-center justify-between p-6 pb-4">
        <div>
            <h1 class="text-xl font-bold">ぷれぐら!</h1>
            <p class="text-gray-400 text-sm">管理画面</p>
        </div>
        <button id="sidebar-close" class="lg:hidden text-gray-400 hover:text-white">
            <i class="fas fa-times text-xl"></i>
        </button>
    </div>
    
    <!-- ナビゲーション（スクロール可能） -->
    <nav class="flex-1 overflow-y-auto px-6 pb-4 space-y-1">
        <!-- コンテンツ管理セクション（青色） -->
        <p class="text-gray-500 text-xs font-bold px-4 pt-2 mb-2 uppercase tracking-wider">コンテンツ</p>
        <a href="index.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'index.php', 'bg-blue-500') ?>">
            <i class="fas fa-home w-5"></i> ダッシュボード
        </a>
        <a href="creators.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'creators.php', 'bg-blue-500') ?>">
            <i class="fas fa-users w-5"></i> メンバー管理
        </a>
        <a href="works.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'works.php', 'bg-blue-500') ?>">
            <i class="fas fa-images w-5"></i> 作品管理
        </a>
        <a href="collections.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'collections.php', 'bg-blue-500') ?>">
            <i class="fas fa-layer-group w-5"></i> コレクション管理
        </a>
        <a href="articles.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'articles.php', 'bg-blue-500') ?>">
            <i class="fas fa-newspaper w-5"></i> 記事管理
        </a>
        <a href="sort-order.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'sort-order.php', 'bg-blue-500') ?>">
            <i class="fas fa-sort w-5"></i> 表示順管理
        </a>
        
        <!-- 運営・設定セクション（紫色） -->
        <div class="pt-3 mt-3 border-t border-gray-700">
            <p class="text-gray-500 text-xs font-bold px-4 mb-2 uppercase tracking-wider">運営</p>
            <a href="inquiries.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'inquiries.php', 'bg-purple-500') ?>">
                <i class="fas fa-envelope w-5"></i> 問い合わせ
                <?php if ($inquiriesNew > 0): ?>
                <span class="bg-red-500 text-white text-xs px-2 py-1 rounded-full ml-auto font-bold"><?= $inquiriesNew ?></span>
                <?php endif; ?>
            </a>
            <a href="settings.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'settings.php', 'bg-purple-500') ?>">
                <i class="fas fa-cog w-5"></i> サイト設定
            </a>
            <a href="seo-checker.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'seo-checker.php', 'bg-purple-500') ?>">
                <i class="fas fa-search w-5"></i> SEOチェッカー
            </a>
            <a href="security.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'security.php', 'bg-purple-500') ?>">
                <i class="fas fa-shield-alt w-5"></i> セキュリティ
            </a>
        </div>
        
        <!-- ストアセクション（緑色） -->
        <div class="pt-3 mt-3 border-t border-gray-700">
            <p class="text-gray-500 text-xs font-bold px-4 mb-2 uppercase tracking-wider">ストア</p>
            <a href="products.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'products.php', 'bg-green-500') ?>">
                <i class="fas fa-store w-5"></i> 商品管理
            </a>
            <a href="orders.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'orders.php', 'bg-green-500') ?>">
                <i class="fas fa-receipt w-5"></i> 注文管理
                <?php if ($ordersNew > 0): ?>
                <span class="bg-red-500 text-white text-xs px-2 py-1 rounded-full ml-auto font-bold animate-pulse"><?= $ordersNew ?></span>
                <?php endif; ?>
            </a>
            <a href="members.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'members.php', 'bg-green-500') ?>">
                <i class="fas fa-user-friends w-5"></i> 会員管理
            </a>
            <a href="services.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'services.php', 'bg-green-500') ?>">
                <i class="fas fa-paint-brush w-5"></i> サービス管理
            </a>
            <a href="service-categories.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'service-categories.php', 'bg-green-500') ?>">
                <i class="fas fa-layer-group w-5"></i> サービスカテゴリ
            </a>
            <a href="transactions.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'transactions.php', 'bg-green-500') ?>">
                <i class="fas fa-handshake w-5"></i> 取引管理
            </a>
            <a href="store-settings.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'store-settings.php', 'bg-green-500') ?>">
                <i class="fas fa-cog w-5"></i> ストア設定
            </a>
            <a href="mail-templates.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'mail-templates.php', 'bg-green-500') ?>">
                <i class="fas fa-envelope w-5"></i> メールテンプレート
            </a>
            <a href="store-categories.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'store-categories.php', 'bg-green-500') ?>">
                <i class="fas fa-tags w-5"></i> カテゴリ管理
            </a>
            <a href="store-announcements.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'store-announcements.php', 'bg-green-500') ?>">
                <i class="fas fa-bullhorn w-5"></i> お知らせ管理
            </a>
            <a href="store-faq.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'store-faq.php', 'bg-green-500') ?>">
                <i class="fas fa-question-circle w-5"></i> FAQ管理
            </a>
            <a href="creator-sales.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'creator-sales.php', 'bg-green-500') ?>">
                <i class="fas fa-chart-line w-5"></i> 商品売上管理
            </a>
            <a href="creator-service-sales.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'creator-service-sales.php', 'bg-green-500') ?>">
                <i class="fas fa-paint-brush w-5"></i> サービス売上管理
            </a>
            <a href="contracts.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'contracts.php', 'bg-green-500') ?>">
                <i class="fas fa-file-contract w-5"></i> 契約書管理
            </a>
            <a href="google-drive.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'google-drive.php', 'bg-green-500') ?>">
                <i class="fab fa-google-drive w-5"></i> Google Drive
            </a>
        </div>
        
        <!-- ツールセクション（オレンジ色） -->
        <div class="pt-3 mt-3 border-t border-gray-700">
            <p class="text-gray-500 text-xs font-bold px-4 mb-2 uppercase tracking-wider">ツール</p>
            <a href="check-images.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'check-images.php', 'bg-orange-500') ?>">
                <i class="fas fa-chart-bar w-5"></i> 画像サイズ確認
            </a>
            <a href="optimize-images.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'optimize-images.php', 'bg-orange-500') ?>">
                <i class="fas fa-compress w-5"></i> 画像最適化
            </a>
            <a href="convert-images.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'convert-images.php', 'bg-orange-500') ?>">
                <i class="fas fa-sync w-5"></i> WebP変換
            </a>
            <a href="gif-to-webm.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'gif-to-webm.php', 'bg-orange-500') ?>">
                <i class="fas fa-film w-5"></i> GIF→WebM変換
            </a>
            <a href="generate-ogp-images.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= sidebarActiveClass($currentPage, 'generate-ogp-images.php', 'bg-orange-500') ?>">
                <i class="fas fa-share-alt w-5"></i> OGP画像生成
            </a>
        </div>
    </nav>
    
    <!-- フッター（固定） -->
    <div class="p-6 pt-2 border-t border-gray-700 space-y-1">
        <a href="../" target="_blank" class="flex items-center gap-3 px-4 py-2.5 text-gray-400 hover:text-white transition">
            <i class="fas fa-external-link-alt w-5"></i> サイトを表示
        </a>
        <a href="logout.php" class="flex items-center gap-3 px-4 py-2.5 text-gray-400 hover:text-white transition">
            <i class="fas fa-sign-out-alt w-5"></i> ログアウト
        </a>
    </div>
</aside>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sidebar-overlay');
    const toggleBtn = document.getElementById('sidebar-toggle');
    const closeBtn = document.getElementById('sidebar-close');
    const nav = sidebar ? sidebar.querySelector('nav') : null;
    
    // スクロール位置の保存キー
    const SCROLL_KEY = 'admin_sidebar_scroll';
    
    // スクロール位置を復元
    if (nav) {
        const savedScroll = sessionStorage.getItem(SCROLL_KEY);
        if (savedScroll) {
            nav.scrollTop = parseInt(savedScroll, 10);
        }
        
        // スクロール位置を保存
        nav.addEventListener('scroll', function() {
            sessionStorage.setItem(SCROLL_KEY, nav.scrollTop);
        });
    }
    
    // リンククリック時にスクロール位置を保存
    if (sidebar) {
        sidebar.querySelectorAll('a').forEach(function(link) {
            link.addEventListener('click', function() {
                if (nav) {
                    sessionStorage.setItem(SCROLL_KEY, nav.scrollTop);
                }
            });
        });
    }
    
    function openSidebar() {
        sidebar.classList.remove('-translate-x-full');
        overlay.classList.remove('hidden');
    }
    
    function closeSidebar() {
        sidebar.classList.add('-translate-x-full');
        overlay.classList.add('hidden');
    }
    
    if (toggleBtn) toggleBtn.addEventListener('click', openSidebar);
    if (closeBtn) closeBtn.addEventListener('click', closeSidebar);
    if (overlay) overlay.addEventListener('click', closeSidebar);
});
</script>
