<?php
/**
 * ストア共通ヘッダー（PC:上部ナビ、スマホ:下部ナビ）
 */
if (!isset($siteName)) {
    $settings = getSiteSettings();
    $siteName = $settings['site_name'] ?? 'ぷれぐら！PLAYGROUND';
}
if (!isset($cartCount)) {
    $cartCount = function_exists('getCartCount') ? getCartCount() : 0;
}
$currentPage = basename($_SERVER['PHP_SELF'], '.php');
$isLoggedInUser = isLoggedIn();

// ファビコン情報を取得
$db = getDB();
$faviconInfo = function_exists('getFaviconInfo') ? getFaviconInfo($db) : ['path' => '/uploads/site/favicon.png', 'type' => 'image/png'];

// OGP用のURL生成
$baseUrl = getBaseUrl();
$currentUrl = getCurrentUrl();

// OGP画像（商品ページの場合は商品画像、それ以外はサイト設定のOGP画像）
// WebPはLINE/Twitter等で認識されないため、JPG/PNG版を優先
$siteOgImage = getSiteSetting($db, 'og_image', '/assets/images/default-ogp.png');
$ogImage = getOgImageUrl($siteOgImage, $baseUrl);
$ogDescription = 'ぷれぐら！PLAYGROUNDのオンラインストア';
if (isset($product) && is_array($product)) {
    if (!empty($product['image'])) {
        $ogImage = getOgImageUrl($product['image'], $baseUrl);
    }
    $ogDescription = !empty($product['description']) 
        ? mb_substr(strip_tags($product['description']), 0, 100) 
        : ($product['name'] ?? $ogDescription);
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="theme-color" content="#FF6B35">
    <title><?= htmlspecialchars($pageTitle ?? 'ストア') ?> - <?= htmlspecialchars($siteName) ?></title>
    <meta name="description" content="<?= htmlspecialchars(mb_substr(strip_tags($ogDescription), 0, 120)) ?>">
    
    <!-- OGP (Open Graph Protocol) -->
    <meta property="og:type" content="<?= isset($product) ? 'product' : 'website' ?>">
    <meta property="og:title" content="<?= htmlspecialchars($pageTitle ?? 'ストア') ?> - <?= htmlspecialchars($siteName) ?>">
    <meta property="og:description" content="<?= htmlspecialchars(mb_substr(strip_tags($ogDescription), 0, 120)) ?>">
    <meta property="og:url" content="<?= htmlspecialchars($currentUrl) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">
    <meta property="og:site_name" content="<?= htmlspecialchars($siteName) ?>">
    <meta property="og:locale" content="ja_JP">
    <?php if (isset($product) && !empty($product['price'])): ?>
    <meta property="product:price:amount" content="<?= (int)$product['price'] ?>">
    <meta property="product:price:currency" content="JPY">
    <?php endif; ?>
    
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($pageTitle ?? 'ストア') ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars(mb_substr(strip_tags($ogDescription), 0, 120)) ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($ogImage) ?>">
    
    <?php 
    // SEOタグ（Google Analytics / Search Console）
    if (function_exists('outputSeoTags')) {
        outputSeoTags($db);
    } else {
        // seo-tags.phpが読み込まれていない場合の直接出力
        $searchConsole = getSiteSetting($db, 'google_search_console', '');
        if (!empty($searchConsole)) {
            echo '<meta name="google-site-verification" content="' . htmlspecialchars($searchConsole) . '">' . "\n";
        }
        $gaId = getSiteSetting($db, 'google_analytics_id', '');
        if (!empty($gaId)) {
            // GA4を遅延読み込み
            echo '<script>
            (function(){var loaded=false;function loadGA(){if(loaded)return;loaded=true;var s=document.createElement("script");s.async=true;s.src="https://www.googletagmanager.com/gtag/js?id=' . htmlspecialchars($gaId) . '";document.head.appendChild(s);window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}window.gtag=gtag;gtag("js",new Date());gtag("config","' . htmlspecialchars($gaId) . '");}["scroll","click","touchstart","mousemove"].forEach(function(e){document.addEventListener(e,loadGA,{once:true,passive:true});});setTimeout(loadGA,3000);})();
            </script>' . "\n";
        }
    }
    ?>
    
    <!-- ファビコン・PWA -->
    <link rel="icon" href="<?= htmlspecialchars($faviconInfo['path']) ?>" type="<?= htmlspecialchars($faviconInfo['type']) ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($faviconInfo['path']) ?>">
    <link rel="manifest" href="/store/manifest.json">
    
    <!-- Tailwind CSS（同期読み込み - レイアウト崩れ防止） -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'store-primary': '#FF6B35',
                        'store-secondary': '#4CAF50'
                    }
                }
            }
        }
    </script>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    
    <style>
        .btn-cart {
            background: linear-gradient(135deg, #4CAF50 0%, #45a049 100%);
            color: white;
        }
        .btn-cart:hover {
            background: linear-gradient(135deg, #45a049 0%, #3d8b40 100%);
        }
        
        /* レスポンシブナビ - モバイル: 下部、PC: 上部 */
        @media (max-width: 1023px) {
            #store-bottom-nav { display: block; }
            #store-top-nav { display: none; }
        }
        @media (min-width: 1024px) {
            #store-bottom-nav { display: none !important; }
            #store-top-nav { display: flex; }
            body { padding-bottom: 0; }
        }
        
        /* PC上部ナビのアクティブ */
        .top-nav-link.active {
            color: #FF6B35;
            border-bottom: 2px solid #FF6B35;
        }
        
        /* 下部ナビのアクティブ状態 - アニメーション付き */
        .store-nav-item .nav-icon-box {
            transition: transform 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        .store-nav-item.active .nav-icon-box {
            transform: translateY(-4px);
            animation: navBounce 0.4s ease-out;
        }
        .store-nav-item.active i {
            animation: navPop 0.3s ease-out;
        }
        
        /* ナビゲーションアニメーション */
        @keyframes navBounce {
            0% { transform: translateY(0); }
            40% { transform: translateY(-8px); }
            60% { transform: translateY(-2px); }
            80% { transform: translateY(-5px); }
            100% { transform: translateY(-4px); }
        }
        
        @keyframes navPop {
            0% { transform: scale(1); }
            50% { transform: scale(1.2); }
            100% { transform: scale(1); }
        }
        
        /* タップ時のリップルエフェクト */
        .store-nav-item {
            position: relative;
            overflow: hidden;
        }
        .store-nav-item::after {
            content: '';
            position: absolute;
            top: 50%;
            left: 50%;
            width: 0;
            height: 0;
            background: rgba(255, 107, 53, 0.2);
            border-radius: 50%;
            transform: translate(-50%, -50%);
            transition: width 0.3s, height 0.3s, opacity 0.3s;
            opacity: 0;
        }
        .store-nav-item:active::after {
            width: 100px;
            height: 100px;
            opacity: 1;
            transition: 0s;
        }
        
        /* ホバー効果（PC） */
        @media (hover: hover) {
            .store-nav-item:hover .nav-icon-box {
                transform: translateY(-2px);
            }
            .store-nav-item:hover i {
                color: #FF6B35;
            }
        }
    </style>
</head>
<body class="bg-gray-50 min-h-screen">
    <!-- PC用上部ヘッダー -->
    <header class="bg-white shadow-sm sticky top-0 z-50">
        <div class="max-w-6xl mx-auto px-4 py-3">
            <div class="flex items-center justify-between gap-4">
                <!-- ロゴ -->
                <a href="/store/" class="flex items-center gap-2 shrink-0">
                    <span class="font-bold text-xl text-gray-800">ぷれぐら！</span>
                    <span class="bg-store-primary text-white text-xs px-2 py-0.5 rounded font-bold">STORE</span>
                </a>
                
                <!-- PC用検索フォーム -->
                <form action="/store/" method="GET" class="hidden lg:flex flex-1 max-w-md mx-4">
                    <div class="relative w-full">
                        <input type="text" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" 
                               placeholder="商品を検索..." 
                               class="w-full pl-10 pr-4 py-2 border border-gray-300 rounded-full text-sm focus:outline-none focus:ring-2 focus:ring-store-primary focus:border-transparent">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <?php if (!empty($_GET['q'])): ?>
                        <a href="/store/" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                            <i class="fas fa-times"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </form>
                
                <!-- PC用ナビゲーション -->
                <nav id="store-top-nav" class="hidden lg:flex shrink-0">
                    <ul class="flex items-center gap-1">
                        <?php if (empty($filterCreator)): ?>
                        <li>
                            <a href="/store/" class="top-nav-link block px-3 py-2 font-medium text-sm <?= $currentPage === 'index' ? 'active' : 'text-gray-600 hover:text-store-primary' ?>">
                                商品一覧
                            </a>
                        </li>
                        <?php endif; ?>
                        <li>
                            <a href="/store/services/" class="top-nav-link block px-3 py-2 font-medium text-sm <?= strpos($_SERVER['REQUEST_URI'], '/services') !== false ? 'active' : 'text-gray-600 hover:text-store-primary' ?>">
                                サービス
                            </a>
                        </li>
                        <?php if ($isLoggedInUser): ?>
                        <li>
                            <a href="/store/bookshelf.php" class="top-nav-link block px-3 py-2 font-medium text-sm <?= $currentPage === 'bookshelf' ? 'active' : 'text-gray-600 hover:text-store-primary' ?>">
                                本棚
                            </a>
                        </li>
                        <li>
                            <a href="/store/transactions/" class="top-nav-link block px-3 py-2 font-medium text-sm <?= strpos($_SERVER['REQUEST_URI'], '/transactions') !== false ? 'active' : 'text-gray-600 hover:text-store-primary' ?>">
                                取引
                            </a>
                        </li>
                        <li>
                            <a href="/store/orders.php" class="top-nav-link block px-3 py-2 font-medium text-sm <?= in_array($currentPage, ['orders', 'order']) ? 'active' : 'text-gray-600 hover:text-store-primary' ?>">
                                注文履歴
                            </a>
                        </li>
                        <li>
                            <a href="/store/mypage.php" class="top-nav-link block px-3 py-2 font-medium text-sm <?= in_array($currentPage, ['mypage', 'profile', 'address']) ? 'active' : 'text-gray-600 hover:text-store-primary' ?>">
                                マイページ
                            </a>
                        </li>
                        <?php endif; ?>
                    </ul>
                </nav>
                
                <!-- 右側アイコン -->
                <div class="hidden lg:flex items-center gap-2 shrink-0">
                    <a href="/store/cart.php" class="relative p-2 <?= $currentPage === 'cart' ? 'text-store-primary' : 'text-gray-600 hover:text-store-primary' ?>">
                        <i class="fas fa-shopping-cart text-lg"></i>
                        <?php if ($cartCount > 0): ?>
                        <span class="absolute -top-1 -right-1 bg-red-500 text-white text-xs w-5 h-5 rounded-full flex items-center justify-center font-bold"><?= min($cartCount, 99) ?></span>
                        <?php endif; ?>
                    </a>
                    <?php if (!$isLoggedInUser): ?>
                    <a href="/store/login.php" class="ml-2 px-4 py-2 bg-store-primary text-white text-sm rounded font-medium hover:bg-orange-600">
                        ログイン
                    </a>
                    <?php endif; ?>
                    <a href="/" class="ml-2 p-2 text-gray-400 hover:text-gray-600 text-sm border-l border-gray-200 pl-4">
                        <i class="fas fa-home mr-1"></i>ぷれぐら！へ
                    </a>
                </div>
                
                <!-- モバイル用：検索アイコン + ぷれぐらへのリンク -->
                <div class="lg:hidden flex items-center gap-3">
                    <button type="button" id="mobile-search-toggle" class="text-gray-600 hover:text-store-primary p-1">
                        <i class="fas fa-search text-lg"></i>
                    </button>
                    <a href="/" class="text-gray-400 hover:text-gray-600 text-sm">
                        <i class="fas fa-home"></i>
                    </a>
                </div>
            </div>
            
            <!-- モバイル用検索フォーム（トグル表示） -->
            <div id="mobile-search-container" class="lg:hidden hidden mt-3 pb-1">
                <form action="/store/" method="GET">
                    <div class="relative">
                        <input type="text" name="q" value="<?= htmlspecialchars($_GET['q'] ?? '') ?>" 
                               placeholder="商品を検索..." 
                               class="w-full pl-10 pr-10 py-2 border border-gray-300 rounded-full text-sm focus:outline-none focus:ring-2 focus:ring-store-primary focus:border-transparent"
                               id="mobile-search-input">
                        <i class="fas fa-search absolute left-3 top-1/2 -translate-y-1/2 text-gray-400"></i>
                        <button type="submit" class="absolute right-3 top-1/2 -translate-y-1/2 text-store-primary">
                            <i class="fas fa-arrow-right"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </header>
    
    <script>
    // モバイル検索トグル
    document.addEventListener('DOMContentLoaded', function() {
        const toggle = document.getElementById('mobile-search-toggle');
        const container = document.getElementById('mobile-search-container');
        const input = document.getElementById('mobile-search-input');
        
        if (toggle && container) {
            toggle.addEventListener('click', function() {
                container.classList.toggle('hidden');
                if (!container.classList.contains('hidden') && input) {
                    input.focus();
                }
            });
        }
    });
    </script>
    
    <main class="max-w-6xl mx-auto px-4 py-6">
