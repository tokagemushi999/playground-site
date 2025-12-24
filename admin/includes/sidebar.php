<?php
/**
 * 管理画面共通サイドバー
 */

// 現在のページを取得
$currentPage = basename($_SERVER['PHP_SELF']);

// 新規問い合わせ数を取得
$sidebarDb = getDB();
$inquiriesNew = 0;

try {
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
} catch (PDOException $e) {
    $inquiriesNew = 0;
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
        <a href="index.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= $currentPage === 'index.php' ? 'bg-yellow-400 text-gray-900 font-bold' : 'hover:bg-gray-800' ?>">
            <i class="fas fa-home w-5"></i> ダッシュボード
        </a>
        <a href="creators.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= $currentPage === 'creators.php' ? 'bg-yellow-400 text-gray-900 font-bold' : 'hover:bg-gray-800' ?>">
            <i class="fas fa-users w-5"></i> メンバー管理
        </a>
        <a href="works.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= $currentPage === 'works.php' ? 'bg-yellow-400 text-gray-900 font-bold' : 'hover:bg-gray-800' ?>">
            <i class="fas fa-images w-5"></i> 作品管理
        </a>
        <a href="sticker_groups.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= $currentPage === 'sticker_groups.php' ? 'bg-yellow-400 text-gray-900 font-bold' : 'hover:bg-gray-800' ?>">
            <i class="fas fa-layer-group w-5"></i> グループ管理
        </a>
        <a href="articles.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= $currentPage === 'articles.php' ? 'bg-yellow-400 text-gray-900 font-bold' : 'hover:bg-gray-800' ?>">
            <i class="fas fa-newspaper w-5"></i> 記事管理
        </a>
        <a href="sort-order.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= $currentPage === 'sort-order.php' ? 'bg-yellow-400 text-gray-900 font-bold' : 'hover:bg-gray-800' ?>">
            <i class="fas fa-sort w-5"></i> 表示順管理
        </a>
        <a href="inquiries.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= $currentPage === 'inquiries.php' ? 'bg-yellow-400 text-gray-900 font-bold' : 'hover:bg-gray-800' ?>">
            <i class="fas fa-envelope w-5"></i> 問い合わせ
            <?php if ($inquiriesNew > 0): ?>
            <span class="bg-red-500 text-white text-xs px-2 py-1 rounded-full ml-auto font-bold"><?= $inquiriesNew ?></span>
            <?php endif; ?>
        </a>
        <a href="settings.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= $currentPage === 'settings.php' ? 'bg-yellow-400 text-gray-900 font-bold' : 'hover:bg-gray-800' ?>">
            <i class="fas fa-cog w-5"></i> サイト設定
        </a>
        
        <!-- ツールセクション -->
        <div class="pt-3 mt-3 border-t border-gray-700">
            <p class="text-gray-500 text-xs px-4 mb-2">ツール</p>
            <a href="check-images.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= $currentPage === 'check-images.php' ? 'bg-yellow-400 text-gray-900 font-bold' : 'hover:bg-gray-800' ?>">
                <i class="fas fa-chart-bar w-5"></i> 画像サイズ確認
            </a>
            <a href="optimize-images.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= $currentPage === 'optimize-images.php' ? 'bg-yellow-400 text-gray-900 font-bold' : 'hover:bg-gray-800' ?>">
                <i class="fas fa-compress w-5"></i> 画像最適化
            </a>
            <a href="convert-images.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= $currentPage === 'convert-images.php' ? 'bg-yellow-400 text-gray-900 font-bold' : 'hover:bg-gray-800' ?>">
                <i class="fas fa-sync w-5"></i> WebP変換
            </a>
            <a href="fix-gif.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg transition <?= $currentPage === 'fix-gif.php' ? 'bg-yellow-400 text-gray-900 font-bold' : 'hover:bg-gray-800' ?>">
                <i class="fas fa-wrench w-5"></i> GIF修復
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
