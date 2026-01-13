<?php
$siteName = 'ぷれぐら！クリエイターダッシュボード';
$creator = $creator ?? getCurrentCreator();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle ?? 'ダッシュボード') ?> - <?= $siteName ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .sidebar-link.active {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
            color: white;
        }
        @media (max-width: 768px) {
            .sidebar-overlay { display: none; }
            .sidebar-overlay.active { display: block; }
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="md:hidden fixed top-0 left-0 right-0 z-40 bg-white shadow-sm">
        <div class="flex items-center justify-between px-4 py-3">
            <button onclick="toggleSidebar()" class="text-gray-600 p-2"><i class="fas fa-bars text-xl"></i></button>
            <a href="/creator-dashboard/" class="font-bold text-gray-800">クリエイターダッシュボード</a>
            <div class="w-10"></div>
        </div>
    </header>
    <div id="sidebarOverlay" class="sidebar-overlay fixed inset-0 bg-black bg-opacity-50 z-40 md:hidden" onclick="toggleSidebar()"></div>
    <div class="flex">
        <aside id="sidebar" class="w-64 bg-white shadow-lg fixed h-full overflow-y-auto z-50 transform -translate-x-full md:translate-x-0 transition-transform duration-300">
            <div class="p-4 border-b flex items-center justify-between">
                <a href="/creator-dashboard/" class="flex items-center gap-2">
                    <div class="w-10 h-10 bg-gradient-to-br from-green-400 to-green-600 rounded-lg flex items-center justify-center text-white font-bold">C</div>
                    <div><div class="font-bold text-gray-800 text-sm">クリエイター</div><div class="text-xs text-gray-500">ダッシュボード</div></div>
                </a>
                <button onclick="toggleSidebar()" class="md:hidden text-gray-400 p-1"><i class="fas fa-times"></i></button>
            </div>
            <?php if ($creator): ?>
            <div class="p-4 border-b bg-gray-50">
                <div class="flex items-center gap-3">
                    <?php if (!empty($creator['image'])): ?>
                    <img src="/<?= htmlspecialchars($creator['image']) ?>" class="w-10 h-10 rounded-full object-cover">
                    <?php else: ?>
                    <div class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center"><i class="fas fa-user text-gray-400"></i></div>
                    <?php endif; ?>
                    <div>
                        <div class="font-bold text-gray-800 text-sm"><?= htmlspecialchars($creator['name']) ?></div>
                        <a href="/creator/<?= htmlspecialchars($creator['slug'] ?? $creator['id']) ?>" target="_blank" class="text-xs text-green-600 hover:underline"><i class="fas fa-external-link-alt mr-1"></i>公開ページ</a>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <nav class="p-4 space-y-1">
                <?php
                $currentPage = basename($_SERVER['PHP_SELF']);
                function isActivePage($pages) { global $currentPage; return in_array($currentPage, (array)$pages); }
                ?>
                <p class="text-xs font-bold text-gray-400 uppercase tracking-wider px-4 pt-2 pb-1">メイン</p>
                <a href="/creator-dashboard/" class="sidebar-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-700 hover:bg-gray-100 transition <?= isActivePage('index.php') ? 'active' : '' ?>"><i class="fas fa-home w-5 text-center"></i><span>ダッシュボード</span></a>
                <a href="/creator-dashboard/transactions.php" class="sidebar-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-700 hover:bg-gray-100 transition <?= isActivePage(['transactions.php', 'transaction-detail.php']) ? 'active' : '' ?>"><i class="fas fa-handshake w-5 text-center"></i><span>取引管理</span><?php if ($creator) { try { $db = getDB(); $stmt = $db->prepare("SELECT COUNT(*) FROM service_messages m JOIN service_transactions t ON m.transaction_id = t.id WHERE t.creator_id = ? AND m.read_by_creator = 0 AND m.sender_type != 'creator'"); $stmt->execute([$creator['id']]); $unreadCount = $stmt->fetchColumn(); if ($unreadCount > 0): ?><span class="ml-auto bg-red-500 text-white text-xs px-2 py-0.5 rounded-full"><?= $unreadCount ?></span><?php endif; } catch (PDOException $e) {} } ?></a>
                <p class="text-xs font-bold text-gray-400 uppercase tracking-wider px-4 pt-4 pb-1">コンテンツ</p>
                <a href="/creator-dashboard/services.php" class="sidebar-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-700 hover:bg-gray-100 transition <?= isActivePage('services.php') ? 'active' : '' ?>"><i class="fas fa-paint-brush w-5 text-center"></i><span>サービス管理</span></a>
                <a href="/creator-dashboard/products.php" class="sidebar-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-700 hover:bg-gray-100 transition <?= isActivePage('products.php') ? 'active' : '' ?>"><i class="fas fa-box w-5 text-center"></i><span>商品管理</span></a>
                <a href="/creator-dashboard/works.php" class="sidebar-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-700 hover:bg-gray-100 transition <?= isActivePage('works.php') ? 'active' : '' ?>"><i class="fas fa-images w-5 text-center"></i><span>作品管理</span></a>
                <a href="/creator-dashboard/collections.php" class="sidebar-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-700 hover:bg-gray-100 transition <?= isActivePage('collections.php') ? 'active' : '' ?>"><i class="fas fa-layer-group w-5 text-center"></i><span>コレクション</span></a>
                <p class="text-xs font-bold text-gray-400 uppercase tracking-wider px-4 pt-4 pb-1">収益・契約</p>
                <a href="/creator-dashboard/earnings.php" class="sidebar-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-700 hover:bg-gray-100 transition <?= isActivePage('earnings.php') ? 'active' : '' ?>"><i class="fas fa-wallet w-5 text-center"></i><span>売上レポート</span></a>
                <a href="/creator-dashboard/contracts.php" class="sidebar-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-700 hover:bg-gray-100 transition <?= isActivePage('contracts.php') ? 'active' : '' ?>"><i class="fas fa-file-contract w-5 text-center"></i><span>契約書</span></a>
                <p class="text-xs font-bold text-gray-400 uppercase tracking-wider px-4 pt-4 pb-1">設定</p>
                <a href="/creator-dashboard/profile.php" class="sidebar-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-700 hover:bg-gray-100 transition <?= isActivePage('profile.php') ? 'active' : '' ?>"><i class="fas fa-user-circle w-5 text-center"></i><span>プロフィール編集</span></a>
                <a href="/creator-dashboard/settings.php" class="sidebar-link flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-700 hover:bg-gray-100 transition <?= isActivePage('settings.php') ? 'active' : '' ?>"><i class="fas fa-cog w-5 text-center"></i><span>アカウント設定</span></a>
                <div class="border-t my-4"></div>
                <a href="/" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-gray-500 hover:bg-gray-100 transition"><i class="fas fa-globe w-5 text-center"></i><span>サイトトップ</span></a>
                <a href="/creator-dashboard/logout.php" class="flex items-center gap-3 px-4 py-2.5 rounded-lg text-red-500 hover:bg-red-50 transition"><i class="fas fa-sign-out-alt w-5 text-center"></i><span>ログアウト</span></a>
            </nav>
        </aside>
        <main class="flex-1 md:ml-64 p-4 md:p-6 pt-16 md:pt-6">
