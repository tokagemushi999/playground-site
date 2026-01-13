<?php
/**
 * ぷれぐら！PLAYGROUND メインページ
 * レイアウト改善版: スマホ下部ナビゲーション対応（HOME色追加） + 高速化チューニング + ローディング制御
 */

session_start();
require_once 'includes/db.php';
require_once 'includes/csrf.php';
require_once 'includes/site-settings.php';
require_once 'includes/gallery-render.php';

$db = getDB();

$siteSettings = [
    'site_name' => getSiteSetting($db, 'site_name', 'ぷれぐら！'),
    'site_subtitle' => getSiteSetting($db, 'site_subtitle', 'CREATORS PLAYGROUND'),
    'site_description' => getSiteSetting($db, 'site_description', 'クリエイターたちの遊び場'),
    'catchcopy_line1' => getSiteSetting($db, 'catchcopy_line1', '描く'),
    'catchcopy_line2' => getSiteSetting($db, 'catchcopy_line2', 'ことは、'),
    'catchcopy_line3' => getSiteSetting($db, 'catchcopy_line3', '遊び'),
    'catchcopy_line4' => getSiteSetting($db, 'catchcopy_line4', 'だ。'),
    'footer_copyright' => getSiteSetting($db, 'footer_copyright', 'ぷれぐら！PLAYGROUND'),
    'sns_x' => getSiteSetting($db, 'sns_x', ''),
    'sns_instagram' => getSiteSetting($db, 'sns_instagram', ''),
    'sns_youtube' => getSiteSetting($db, 'sns_youtube', ''),
    'sns_tiktok' => getSiteSetting($db, 'sns_tiktok', ''),
    'sns_discord' => getSiteSetting($db, 'sns_discord', ''),
];

// ナビゲーション表示設定
$showLabNav = (int)getSiteSetting($db, 'nav_show_lab', '1');
$showStoreNav = (int)getSiteSetting($db, 'nav_show_store', '1');

// 記事表示設定
$homeArticlesLimit = (int)getSiteSetting($db, 'home_articles_limit', '3');
$featuredArticlesLimit = (int)getSiteSetting($db, 'featured_articles_limit', '3');
$homeIncludeFeatured = (int)getSiteSetting($db, 'home_include_featured', '1');
$homeArticlesLimit = max(0, min(12, $homeArticlesLimit));
$featuredArticlesLimit = max(0, min(12, $featuredArticlesLimit));

// データ取得
$creators = getCreators();
$works = getWorks();
$featuredWorks = getFeaturedWorks();
$articles = getArticles();
$labTools = getLabTools();
$collections = getAllCollectionsWithImages(); // コレクション取得

// サービス取得（show_in_topを考慮）
$topServices = [];
try {
    $topServices = getServices(null, 'active', null, 'top');
    if (empty($topServices)) {
        // show_in_topが設定されていなければ、おすすめサービスを表示
        $topServices = getFeaturedServices(6);
    }
} catch (Exception $e) {
    $topServices = [];
}

// WebM存在チェック関数
function checkWebmExists($imagePath) {
    if (empty($imagePath)) return false;
    $baseDir = __DIR__;
    $path = '/' . ltrim($imagePath, '/');
    
    // .webmパスの場合はそのままtrue
    if (preg_match('/\.webm$/i', $path)) {
        return file_exists($baseDir . $path);
    }
    
    // .gif/.GIFの場合は同名.webmをチェック
    if (preg_match('/\.gif$/i', $path)) {
        $webmPath = preg_replace('/\.gif$/i', '.webm', $path);
        return file_exists($baseDir . $webmPath);
    }
    
    return false;
}

// 各データにwebm_existsフラグを追加
foreach ($works as &$w) {
    $w['webm_exists'] = checkWebmExists($w['image']);
}
foreach ($creators as &$c) {
    $c['webm_exists'] = checkWebmExists($c['image']);
}
foreach ($articles as &$a) {
    $a['webm_exists'] = checkWebmExists($a['thumbnail']);
}
unset($w, $c, $a);

// クリエイターごとのサービス情報を取得
$creatorServices = [];
try {
    $stmt = $db->query("SELECT id, creator_id, title, base_price, thumbnail_image FROM services WHERE status = 'active' ORDER BY creator_id, sort_order");
    while ($row = $stmt->fetch()) {
        if (!isset($creatorServices[$row['creator_id']])) {
            $creatorServices[$row['creator_id']] = [];
        }
        $creatorServices[$row['creator_id']][] = [
            'id' => (int)$row['id'],
            'title' => $row['title'],
            'price' => (int)$row['base_price'],
            'thumbnail' => $row['thumbnail_image']
        ];
    }
} catch (PDOException $e) {}

// 各クリエイターにサービス情報を追加
foreach ($creators as &$c) {
    $c['services'] = $creatorServices[$c['id']] ?? [];
}
unset($c);

// JSONデータ生成
$creatorsJson = json_encode($creators, JSON_UNESCAPED_UNICODE);
$worksJson = json_encode($works, JSON_UNESCAPED_UNICODE);
$articlesJson = json_encode($articles, JSON_UNESCAPED_UNICODE);
$labToolsJson = json_encode($labTools, JSON_UNESCAPED_UNICODE);
$collectionsJson = json_encode($collections, JSON_UNESCAPED_UNICODE);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes, viewport-fit=cover">
    
    <title><?= htmlspecialchars($siteSettings['site_name']) ?> - <?= htmlspecialchars($siteSettings['site_subtitle']) ?></title>
    <meta name="description" content="<?= htmlspecialchars($siteSettings['site_description']) ?>">
    <meta name="keywords" content="クリエイター,イラスト,漫画,動画,制作依頼,<?= htmlspecialchars($siteSettings['site_name']) ?>">
    
    <!-- OGP (Open Graph Protocol) -->
    <meta property="og:title" content="<?= htmlspecialchars($siteSettings['site_name']) ?>">
    <meta property="og:type" content="website">
    <meta property="og:url" content="<?= htmlspecialchars(getCurrentUrl()) ?>">
    <?php 
    $baseUrl = getBaseUrl();
    $ogImageSetting = getSiteSetting($db, 'og_image', '/assets/images/ogp.jpg');
    $ogImageUrl = getOgImageUrl($ogImageSetting, $baseUrl);
    ?>
    <meta property="og:image" content="<?= htmlspecialchars($ogImageUrl) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:description" content="<?= htmlspecialchars($siteSettings['site_description']) ?>">
    <meta property="og:site_name" content="<?= htmlspecialchars($siteSettings['site_name']) ?>">
    <meta property="og:locale" content="ja_JP">
    
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($siteSettings['site_name']) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($siteSettings['site_description']) ?>">
    <meta name="twitter:image" content="<?= $ogImageUrl ?>">
    
    <?php 
    // Google Search Console 認証
    $searchConsole = getSiteSetting($db, 'google_search_console', '');
    if (!empty($searchConsole)): ?>
    <meta name="google-site-verification" content="<?= htmlspecialchars($searchConsole) ?>">
    <?php endif; ?>
    
    <?php 
    // Google Analytics（遅延読み込み）
    $gaId = getSiteSetting($db, 'google_analytics_id', '');
    if (!empty($gaId)): ?>
    <script>
        // GA4を遅延読み込み（ユーザー操作または3秒後）
        (function() {
            var loaded = false;
            function loadGA() {
                if (loaded) return;
                loaded = true;
                var s = document.createElement('script');
                s.async = true;
                s.src = 'https://www.googletagmanager.com/gtag/js?id=<?= htmlspecialchars($gaId) ?>';
                document.head.appendChild(s);
                window.dataLayer = window.dataLayer || [];
                function gtag(){dataLayer.push(arguments);}
                window.gtag = gtag;
                gtag('js', new Date());
                gtag('config', '<?= htmlspecialchars($gaId) ?>');
            }
            // ユーザー操作時または3秒後に読み込み
            ['scroll', 'click', 'touchstart', 'mousemove'].forEach(function(e) {
                document.addEventListener(e, loadGA, {once: true, passive: true});
            });
            setTimeout(loadGA, 3000);
        })();
    </script>
    <?php endif; ?>
    
    <!-- Canonical URL -->
    <link rel="canonical" href="<?= htmlspecialchars($baseUrl) ?>/">
    
    <!-- Preconnect（接続を先行確立して高速化） -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdn.tailwindcss.com">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    
    <?php $faviconInfo = getFaviconInfo($db); ?>
    <link rel="icon" href="<?= htmlspecialchars($faviconInfo['path']) ?>" type="<?= htmlspecialchars($faviconInfo['type']) ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($faviconInfo['path']) ?>">
    <link rel="manifest" href="/manifest.json">
    
    <!-- 構造化データ（JSON-LD） -->
    <script type="application/ld+json">
    {
        "@context": "https://schema.org",
        "@type": "WebSite",
        "name": "<?= htmlspecialchars($siteSettings['site_name']) ?>",
        "url": "<?= htmlspecialchars($baseUrl) ?>/",
        "description": "<?= htmlspecialchars($siteSettings['site_description']) ?>",
        "publisher": {
            "@type": "Organization",
            "name": "<?= htmlspecialchars($siteSettings['site_name']) ?>",
            "logo": {
                "@type": "ImageObject",
                "url": "<?= htmlspecialchars($ogImageUrl) ?>"
            }
        }
    }
    </script>
    
    <!-- Tailwind CSS（同期読み込み - レイアウト崩れ防止） -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Zen Maru Gothic', 'sans-serif'],
                        display: ['Dela Gothic One', 'cursive'],
                        pixel: ['VT323', 'monospace'],
                        code: ['VT323', 'monospace'],
                        body: ['Zen Maru Gothic', 'sans-serif']
                    },
                    colors: {
                        'pop-yellow': '#FFD600',
                        'pop-pink': '#FF6B6B',
                        'pop-blue': '#4ECDC4',
                        'pop-purple': '#9D50BB',
                        'pop-black': '#1a1a1a',
                        'lab-green': '#00ff41',
                        'lab-bg': '#050505',
                        'cream-base': '#FDFBF7',
                        'accent-cyan': '#4ECDC4',
                        'accent-red': '#FF6B6B'
                    },
                    boxShadow: {
                        'pop': '4px 4px 0px 0px rgba(0,0,0,0.1)',
                        'card': '0 10px 30px -10px rgba(0, 0, 0, 0.1)'
                    },
                    animation: {
                        'float': 'float 6s ease-in-out infinite',
                        'fade-in-fast': 'fadeInFast 0.15s ease-out forwards'
                    },
                    keyframes: {
                        float: { '0%, 100%': { transform: 'translateY(0)' }, '50%': { transform: 'translateY(-15px)' } },
                        fadeInFast: {
                            '0%': { opacity: '0', transform: 'translateY(5px)' },
                            '100%': { opacity: '1', transform: 'translateY(0)' }
                        }
                    }
                }
            }
        }
    </script>
    
    <!-- フォント（必要最小限に絞る: Zen Maru Gothic + Dela Gothic One + VT323のみ） -->
    <link href="https://fonts.googleapis.com/css2?family=Dela+Gothic+One&family=Zen+Maru+Gothic:wght@400;700&family=VT323&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Dela+Gothic+One&family=Zen+Maru+Gothic:wght@400;700&family=VT323&display=swap" rel="stylesheet"></noscript>
    
    <!-- Font Awesome（非同期読み込み） -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    
    <style>
        body {
            background-color: #FDFBF7;
            background-image: radial-gradient(#E5E7EB 2px, transparent 2px);
            background-size: 30px 30px;
            color: #1a1a1a;
            overflow-x: hidden;
            transition: background-color 0.3s ease;
            font-family: 'Zen Maru Gothic', sans-serif;
            padding-bottom: env(safe-area-inset-bottom);
        }
        
        /* Lab Mode Styling */
        body.lab-mode {
            background-color: #050505;
            background-image: linear-gradient(rgba(0, 255, 65, 0.1) 1px, transparent 1px),
            linear-gradient(90deg, rgba(0, 255, 65, 0.1) 1px, transparent 1px);
            background-size: 40px 40px;
            color: #00ff41;
        }

        /* ============================================
           コレクション表示スタイル
           ============================================ */
        
        /* 共通スタイル */
        .collection-card {
            position: relative;
            aspect-ratio: 1;
            overflow: hidden;
        }
        
        /* ===== スタック型（デフォルト） ===== */
        .sticker-stack {
            perspective: 1000px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .sticker-layer {
            width: 70%;
            height: 70%;
            transition: all 0.35s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        /* 透過PNGの形に沿った白縁（drop-shadowで実現） */
        .sticker-layer img {
            filter: 
                drop-shadow(0 0 0 white)
                drop-shadow(1px 0 0 white)
                drop-shadow(-1px 0 0 white)
                drop-shadow(0 1px 0 white)
                drop-shadow(0 -1px 0 white)
                drop-shadow(2px 2px 4px rgba(0,0,0,0.15));
        }
        /* 通常時：重なって配置 */
        .sticker-layer[data-layer="4"] {
            transform: translate(-12%, -10%) rotate(-5deg);
        }
        .sticker-layer[data-layer="3"] {
            transform: translate(-6%, -5%) rotate(-2deg);
        }
        .sticker-layer[data-layer="2"] {
            transform: translate(6%, 3%) rotate(3deg);
        }
        .sticker-layer[data-layer="1"] {
            transform: translate(0, 0) rotate(0deg);
        }
        /* ホバー時：枚数に応じた広がり（4枚） */
        .sticker-stack[data-count="4"]:hover .sticker-layer[data-layer="4"] {
            transform: translate(-35%, -8%) rotate(-12deg) scale(0.92);
        }
        .sticker-stack[data-count="4"]:hover .sticker-layer[data-layer="3"] {
            transform: translate(-15%, -4%) rotate(-5deg) scale(0.96);
        }
        .sticker-stack[data-count="4"]:hover .sticker-layer[data-layer="2"] {
            transform: translate(18%, 0%) rotate(6deg) scale(0.96);
        }
        .sticker-stack[data-count="4"]:hover .sticker-layer[data-layer="1"] {
            transform: translate(38%, 6%) rotate(10deg) scale(0.92);
        }
        /* ホバー時：3枚の場合 */
        .sticker-stack[data-count="3"]:hover .sticker-layer[data-layer="3"] {
            transform: translate(-25%, -5%) rotate(-8deg) scale(0.94);
        }
        .sticker-stack[data-count="3"]:hover .sticker-layer[data-layer="2"] {
            transform: translate(0%, 0%) rotate(0deg) scale(1);
        }
        .sticker-stack[data-count="3"]:hover .sticker-layer[data-layer="1"] {
            transform: translate(25%, 5%) rotate(8deg) scale(0.94);
        }
        /* ホバー時：2枚の場合 */
        .sticker-stack[data-count="2"]:hover .sticker-layer[data-layer="2"] {
            transform: translate(-15%, -3%) rotate(-5deg) scale(0.96);
        }
        .sticker-stack[data-count="2"]:hover .sticker-layer[data-layer="1"] {
            transform: translate(15%, 3%) rotate(5deg) scale(0.96);
        }
        /* ホバー時：1枚の場合 */
        .sticker-stack[data-count="1"]:hover .sticker-layer[data-layer="1"] {
            transform: scale(1.05);
        }
        /* 従来のgroup:hoverもフォールバックとして残す */
        .group:hover .sticker-layer[data-layer="4"] {
            transform: translate(-35%, -8%) rotate(-12deg) scale(0.92);
        }
        .group:hover .sticker-layer[data-layer="3"] {
            transform: translate(-15%, -4%) rotate(-5deg) scale(0.96);
        }
        .group:hover .sticker-layer[data-layer="2"] {
            transform: translate(18%, 0%) rotate(6deg) scale(0.96);
        }
        .group:hover .sticker-layer[data-layer="1"] {
            transform: translate(38%, 6%) rotate(10deg) scale(0.92);
        }
        
        /* ===== LINEスタンプ用スタック（背面はシルエット） ===== */
        .line-stamp-stack .sticker-layer:not([data-layer="1"]) img {
            filter: 
                brightness(0) 
                drop-shadow(0 0 0 #ccc)
                drop-shadow(1px 0 0 #ccc)
                drop-shadow(-1px 0 0 #ccc)
                drop-shadow(0 1px 0 #ccc)
                drop-shadow(0 -1px 0 #ccc);
            opacity: 0.3;
        }
        .line-stamp-stack .sticker-layer[data-layer="1"] img {
            filter: 
                drop-shadow(0 0 0 white)
                drop-shadow(1px 0 0 white)
                drop-shadow(-1px 0 0 white)
                drop-shadow(0 1px 0 white)
                drop-shadow(0 -1px 0 white)
                drop-shadow(2px 2px 4px rgba(0,0,0,0.15));
        }
        /* ホバー時はシルエットを解除 */
        .line-stamp-stack:hover .sticker-layer img {
            filter: 
                drop-shadow(0 0 0 white)
                drop-shadow(1px 0 0 white)
                drop-shadow(-1px 0 0 white)
                drop-shadow(0 1px 0 white)
                drop-shadow(0 -1px 0 white)
                drop-shadow(2px 2px 4px rgba(0,0,0,0.15));
            opacity: 1;
        }
        
        /* ===== グリッド型（2x2） ===== */
        .collection-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 4px;
            padding: 8px;
            background: #f3f4f6;
            border-radius: 12px;
        }
        .collection-grid .grid-item {
            aspect-ratio: 1;
            border-radius: 6px;
            overflow: hidden;
            background: #fff;
            transition: transform 0.3s ease;
        }
        .collection-grid .grid-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .group:hover .collection-grid .grid-item {
            transform: scale(0.95);
        }
        .group:hover .collection-grid .grid-item:hover {
            transform: scale(1.05);
            z-index: 10;
        }
        
        /* ===== アルバム型（フォルダ） ===== */
        .collection-album {
            position: relative;
            padding: 10px;
        }
        .collection-album .album-folder {
            position: relative;
            background: linear-gradient(145deg, #fbbf24 0%, #f59e0b 100%);
            border-radius: 4px 12px 12px 12px;
            padding: 12px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .collection-album .album-folder::before {
            content: '';
            position: absolute;
            top: -10px;
            left: 0;
            width: 40%;
            height: 12px;
            background: linear-gradient(145deg, #fbbf24 0%, #f59e0b 100%);
            border-radius: 6px 6px 0 0;
        }
        .collection-album .album-papers {
            position: relative;
            background: #fff;
            border-radius: 4px;
            padding: 4px;
            min-height: 80px;
        }
        .collection-album .paper {
            position: absolute;
            width: 90%;
            height: 90%;
            background: #fff;
            border-radius: 4px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            overflow: hidden;
            transition: all 0.3s ease;
        }
        .collection-album .paper:nth-child(1) {
            top: 8%;
            left: 5%;
            transform: rotate(-3deg);
            z-index: 1;
        }
        .collection-album .paper:nth-child(2) {
            top: 4%;
            left: 8%;
            transform: rotate(2deg);
            z-index: 2;
        }
        .collection-album .paper:nth-child(3) {
            top: 0;
            left: 5%;
            transform: rotate(0deg);
            z-index: 3;
        }
        .collection-album .paper img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .group:hover .collection-album .paper:nth-child(1) {
            transform: rotate(-8deg) translateY(-5px);
        }
        .group:hover .collection-album .paper:nth-child(2) {
            transform: rotate(5deg) translateY(-8px) translateX(5px);
        }
        .group:hover .collection-album .paper:nth-child(3) {
            transform: rotate(0deg) translateY(-12px);
        }

        /* 高速化のためのスタイル変更 */
        .tab-content { 
            display: none; 
            opacity: 0; 
            /* transitionは削除し、active時にanimationを適用 */
        }
        .tab-content.active { 
            display: block; 
            /* 0.15秒でサッと表示 */
            animation: fadeInFast 0.15s ease-out forwards; 
        }
        
        /* Keyframes for CSS (Tailwind設定が効かない場合のフォールバック) */
        @keyframes fadeInFast {
            from { opacity: 0; transform: translateY(5px); }
            to { opacity: 1; transform: translateY(0); }
        }

        /* Custom Scrollbar for Pickup Slider */
        .pickup-slider {
            overflow-x: auto;
            scroll-snap-type: x mandatory;
            -webkit-overflow-scrolling: touch;
            scrollbar-width: none;
        }
        .pickup-slider::-webkit-scrollbar { display: none; }
        .pickup-slide { scroll-snap-align: center; }

        #loader {
            position: fixed; inset: 0; z-index: 9999;
            background-color: #FFD600;
            display: flex; align-items: center; justify-content: center;
            transition: opacity 0.5s ease, visibility 0.5s;
        }
        
        /* ローダーのフォント変化アニメーション - 全体で統一 */
        .loader-text {
            display: block;
            animation: fontMorph 3s infinite;
        }
        @keyframes fontMorph {
            0%, 14% { font-family: 'Dela Gothic One', cursive; }
            14.1%, 28% { font-family: 'Hachi Maru Pop', cursive; }
            28.1%, 42% { font-family: 'Reggae One', sans-serif; }
            42.1%, 56% { font-family: 'RocknRoll One', sans-serif; }
            56.1%, 70% { font-family: 'Yusei Magic', sans-serif; }
            70.1%, 84% { font-family: 'Stick', sans-serif; }
            84.1%, 100% { font-family: 'VT323', monospace; }
        }

        /* Responsive Navigation - Mobile: bottom, PC: top */
        @media (max-width: 1023px) {
            #bottom-nav { display: block; }
            #top-nav { display: none; }
        }
        @media (min-width: 1024px) {
            #bottom-nav { display: none; }
            #top-nav { display: block; }
            body { padding-bottom: 0; }
            main { padding-top: 6rem; }
        }

        /* Bottom Nav Styling */
        .nav-item.active .nav-icon-box { transform: translateY(-4px); }
        .nav-item.active .nav-label { font-weight: 900; }
        
        /* Active Colors for Bottom Nav */
        .nav-item[data-target="home"].active .nav-icon { color: #FFD600; }
        .nav-item[data-target="home"].active .nav-label { color: #FFD600; }

        .nav-item[data-target="creators"].active .nav-icon { color: #db2777; }
        .nav-item[data-target="creators"].active .nav-label { color: #db2777; }
        
        .nav-item[data-target="gallery"].active .nav-icon { color: #2563eb; }
        .nav-item[data-target="gallery"].active .nav-label { color: #2563eb; }
        
        .nav-item[data-target="store"].active .nav-icon { color: #16a34a; }
        .nav-item[data-target="store"].active .nav-label { color: #16a34a; }
        
        .nav-item[data-target="magazine"].active .nav-icon { color: #9333ea; }
        .nav-item[data-target="magazine"].active .nav-label { color: #9333ea; }
        
        /* Request Button Active Style */
        .nav-item[data-target="request"].active .nav-icon-box { 
            background-color: #FF6B6B; 
            box-shadow: 0 4px 12px rgba(255, 107, 107, 0.4);
        }
        .nav-item[data-target="request"].active .nav-icon { color: white; }
        .nav-item[data-target="request"].active .nav-label { color: #FF6B6B; }

        /* PC Top Nav item styling */
        .top-nav-btn { transition: all 0.2s ease; }
        .top-nav-btn.active { font-weight: 700; }
        .top-nav-btn[data-target="home"].active { background-color: #fef9c3; color: #ca8a04; }
        .top-nav-btn[data-target="creators"].active { background-color: #fce7f3; color: #db2777; }
        .top-nav-btn[data-target="gallery"].active { background-color: #dbeafe; color: #2563eb; }
        .top-nav-btn[data-target="store"].active { background-color: #dcfce7; color: #16a34a; }
        .top-nav-btn[data-target="magazine"].active { background-color: #f3e8ff; color: #9333ea; }

        /* Lab Mode Bottom Nav Overrides */
        body.lab-mode #bottom-nav {
            background-color: rgba(5, 5, 5, 0.95);
            border-top-color: #00ff41;
        }
        body.lab-mode .nav-icon, body.lab-mode .nav-label { color: #333; }
        body.lab-mode .nav-item.active .nav-icon, 
        body.lab-mode .nav-item.active .nav-label { 
            color: #00ff41; 
            text-shadow: 0 0 5px rgba(0,255,65,0.5); 
        }
        
        /* Lab Mode Top Nav Overrides */
        body.lab-mode #top-nav-container {
            background-color: rgba(5, 5, 5, 0.95);
            border-color: #00ff41;
        }
        body.lab-mode #top-nav-logo {
            color: white;
        }
        body.lab-mode #top-nav-logo:hover {
            color: #00ff41;
        }
        body.lab-mode .top-nav-btn {
            color: #888;
        }
        body.lab-mode .top-nav-btn:hover {
            color: #00ff41;
            background-color: rgba(0, 255, 65, 0.1);
        }
        body.lab-mode .top-nav-btn[data-target="lab"].active {
            background-color: rgba(0, 255, 65, 0.2);
            color: #00ff41;
        }
    </style>
</head>
<body class="font-body bg-cream-base overflow-x-hidden selection:bg-pop-yellow selection:text-black">

    <div id="loader">
        <div class="text-4xl md:text-7xl text-white text-center leading-tight drop-shadow-md">
            <div class="loader-text">CREATORS</div>
            <div class="loader-text">PLAYGROUND</div>
        </div>
    </div>
    <script>
        // 訪問済みならローダーを即座に非表示にする（チラつき防止）
        if (sessionStorage.getItem('visited')) {
            document.getElementById('loader').style.display = 'none';
        }
    </script>

    <header class="lg:hidden fixed top-0 left-0 right-0 z-50 p-4 flex justify-between items-start pointer-events-none">
        <a href="#" onclick="switchTab('home')" id="top-logo" class="pointer-events-auto bg-white/90 backdrop-blur shadow-md border border-gray-100 rounded-full px-4 py-2 font-display text-sm md:text-base text-pop-black hover:text-pop-blue transition-all group flex items-center gap-2 hover:scale-105 active:scale-95">
            <i class="fas fa-shapes text-pop-yellow group-hover:rotate-12 transition-transform"></i>
            <span>ぷれぐら！</span>
        </a>

        <div class="flex items-center gap-2">
            <?php if ($showStoreNav): ?>
            <a href="/store/" class="pointer-events-auto h-12 rounded-full bg-green-600 text-white shadow-lg px-4 flex items-center justify-center hover:bg-green-700 hover:scale-105 active:scale-95 transition-all font-bold text-sm">
                <i class="fas fa-store mr-2"></i>STORE
            </a>
            <?php endif; ?>
            
            <?php if ($showLabNav): ?>
            <button onclick="switchTab('lab')" id="lab-trigger" class="pointer-events-auto w-12 h-12 rounded-full bg-gray-900 text-lab-green border border-lab-green/50 shadow-lg flex items-center justify-center hover:shadow-green-500/50 hover:scale-110 active:scale-95 transition-all group overflow-hidden relative">
                <div class="absolute inset-0 bg-lab-green/20 scale-0 group-hover:scale-100 rounded-full transition-transform duration-300"></div>
                <i class="fas fa-flask text-xl relative z-10 group-hover:animate-pulse"></i>
            </button>
            <?php endif; ?>
        </div>
    </header>

    <nav id="bottom-nav" class="fixed bottom-0 left-0 right-0 z-50 bg-white/95 backdrop-blur-md border-t border-gray-200 pb-[env(safe-area-inset-bottom)] transition-all duration-300 shadow-[0_-5px_20px_rgba(0,0,0,0.05)]">
        <div class="max-w-3xl mx-auto px-2">
            <div class="flex justify-around items-center h-16 md:h-20">
                <button onclick="switchTab('home')" class="nav-item flex-1 flex flex-col items-center justify-center gap-1 group py-1" data-target="home">
                    <div class="nav-icon-box relative p-1 rounded-xl transition-transform duration-300">
                        <i class="nav-icon fas fa-home text-xl md:text-2xl text-gray-400 transition-colors duration-300"></i>
                    </div>
                    <span class="nav-label text-[10px] md:text-xs font-bold text-gray-400 transition-colors duration-300 tracking-wide">HOME</span>
                </button>

                <button onclick="switchTab('creators')" class="nav-item flex-1 flex flex-col items-center justify-center gap-1 group py-1" data-target="creators">
                    <div class="nav-icon-box relative p-1 rounded-xl transition-transform duration-300">
                        <i class="nav-icon fas fa-users text-xl md:text-2xl text-gray-400 transition-colors duration-300"></i>
                    </div>
                    <span class="nav-label text-[10px] md:text-xs font-bold text-gray-400 transition-colors duration-300 tracking-wide">MEMBER</span>
                </button>

                <button onclick="switchTab('gallery')" class="nav-item flex-1 flex flex-col items-center justify-center gap-1 group py-1" data-target="gallery">
                    <div class="nav-icon-box relative p-1 rounded-xl transition-transform duration-300">
                        <i class="nav-icon fas fa-images text-xl md:text-2xl text-gray-400 transition-colors duration-300"></i>
                    </div>
                    <span class="nav-label text-[10px] md:text-xs font-bold text-gray-400 transition-colors duration-300 tracking-wide">GALLERY</span>
                </button>

                <button onclick="switchTab('magazine')" class="nav-item flex-1 flex flex-col items-center justify-center gap-1 group py-1" data-target="magazine">
                    <div class="nav-icon-box relative p-1 rounded-xl transition-transform duration-300">
                        <i class="nav-icon fas fa-newspaper text-xl md:text-2xl text-gray-400 transition-colors duration-300"></i>
                    </div>
                    <span class="nav-label text-[10px] md:text-xs font-bold text-gray-400 transition-colors duration-300 tracking-wide">MEDIA</span>
                </button>

                <button onclick="switchTab('request')" class="nav-item flex-1 flex flex-col items-center justify-center gap-1 group py-1" data-target="request">
                    <div class="nav-icon-box relative bg-pop-pink/10 p-2 rounded-xl transition-all duration-300">
                        <i class="nav-icon fas fa-paper-plane text-xl md:text-2xl text-pop-pink transition-colors duration-300"></i>
                    </div>
                    <span class="nav-label text-[10px] md:text-xs font-bold text-pop-pink transition-colors duration-300 tracking-wide">REQUEST</span>
                </button>
            </div>
        </div>
    </nav>

    <!-- PC用上部ナビゲーション -->
    <nav id="top-nav" class="fixed top-4 left-4 right-4 z-50 transition-all duration-300">
        <div id="top-nav-container" class="bg-white/90 backdrop-blur-md border border-gray-200 rounded-full shadow-card px-6 py-3 flex justify-between items-center max-w-7xl mx-auto transition-colors duration-300">
            <a href="#" onclick="switchTab('home')" id="top-nav-logo" class="font-display text-xl md:text-2xl text-pop-black hover:text-pop-blue transition-colors group">
                <i class="fas fa-shapes text-pop-yellow mr-2 group-hover:rotate-12 transition-transform"></i>ぷれぐら！<span class="text-sm ml-1 opacity-60 font-sans">PLAYGROUND</span>
            </a>
            
            <div class="flex items-center gap-2">
                <button onclick="switchTab('home')" class="top-nav-btn px-4 py-2 rounded-full hover:bg-yellow-100 hover:text-yellow-600 transition-all text-gray-600" data-target="home">
                    <i class="fas fa-home mr-2"></i>HOME
                </button>
                <button onclick="switchTab('creators')" class="top-nav-btn px-4 py-2 rounded-full hover:bg-pink-100 hover:text-pink-600 transition-all text-gray-600" data-target="creators">
                    <i class="fas fa-users mr-2"></i>MEMBER
                </button>
                <button onclick="switchTab('gallery')" class="top-nav-btn px-4 py-2 rounded-full hover:bg-blue-100 hover:text-blue-600 transition-all text-gray-600" data-target="gallery">
                    <i class="fas fa-images mr-2"></i>GALLERY
                </button>
                <?php if ($showStoreNav): ?>
                <a href="/store/" class="top-nav-btn px-4 py-2 rounded-full hover:bg-green-100 hover:text-green-600 transition-all text-gray-600" data-target="store">
                    <i class="fas fa-store mr-2"></i>STORE
                </a>
                <?php endif; ?>
                <?php if ($showLabNav): ?>
                <button onclick="switchTab('lab')" class="top-nav-btn px-4 py-2 rounded-full bg-gray-900 text-green-400 hover:bg-gray-800 transition-all flex items-center font-code tracking-widest" data-target="lab">
                    <i class="fas fa-flask mr-2"></i>THE LAB
                </button>
                <?php endif; ?>
                <button onclick="switchTab('magazine')" class="top-nav-btn px-4 py-2 rounded-full hover:bg-purple-100 hover:text-purple-600 transition-all text-gray-600" data-target="magazine">
                    <i class="fas fa-newspaper mr-2"></i>MEDIA
                </button>
            </div>

            <button onclick="switchTab('request')" class="bg-pop-pink text-white px-6 py-2 rounded-full shadow-pop hover:shadow-none hover:translate-y-[2px] transition-all font-display tracking-wider text-sm">
                <i class="fas fa-paper-plane mr-2"></i>REQUEST
            </button>
        </div>
    </nav>

    <main class="pt-20 pb-28 lg:pt-28 lg:pb-8 min-h-screen">

        <div id="tab-home" class="tab-content active">
            <section class="w-full flex flex-col justify-center items-center min-h-[70vh] relative overflow-hidden">
                <div class="absolute top-20 left-10 w-64 h-64 bg-pop-blue/20 rounded-full blur-3xl animate-float"></div>
                <div class="absolute bottom-40 right-10 w-80 h-80 bg-pop-pink/20 rounded-full blur-3xl animate-float" style="animation-delay: 2s;"></div>
                <div class="absolute top-1/2 left-1/2 -translate-x-1/2 -translate-y-1/2 w-96 h-96 bg-pop-yellow/20 rounded-full blur-3xl animate-float" style="animation-delay: 4s;"></div>

                <div class="w-full bg-white border-y border-gray-200 py-8 md:py-10 relative mb-16 overflow-hidden mt-4">
                    <div class="absolute top-3 left-3 md:top-4 md:left-6 z-20">
                        <div class="bg-pop-yellow text-white font-bold px-4 py-1.5 text-xs tracking-widest rounded-lg shadow-md transform -rotate-3">
                            ✦ PICK UP
                        </div>
                    </div>
                    <div class="pickup-slider flex py-6 md:py-8 overflow-x-auto snap-x snap-mandatory scroll-smooth cursor-grab active:cursor-grabbing" 
                         id="pickup-slider"></div>
                    <div id="pickup-dots" class="flex justify-center gap-2 mt-2"></div>
                </div>

                <section id="home-articles-section" class="w-full max-w-7xl mx-auto px-6 mb-12 hidden">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center gap-2">
                            <i class="fas fa-newspaper text-gray-400 text-sm"></i>
                            <h3 class="text-sm font-bold text-gray-500 tracking-wide">PICK UP ARTICLES</h3>
                        </div>
                        <button type="button" onclick="switchTab('magazine')" class="text-xs font-bold text-gray-400 hover:text-gray-600 transition">
                            もっと見る <i class="fas fa-chevron-right ml-1"></i>
                        </button>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 md:gap-5" id="home-articles-grid"></div>
                </section>

                <section class="catchcopy-section py-16 md:py-24 text-center">
                    <div class="inline-block px-6 py-2 bg-white/80 backdrop-blur rounded-full text-sm tracking-widest text-gray-600 mb-8">
                        CREATIVE ARCHIVE & LAB
                    </div>
                    <h2 class="text-4xl md:text-6xl lg:text-7xl font-black leading-tight">
                        <span class="text-accent-cyan"><?= htmlspecialchars($siteSettings['catchcopy_line1']) ?></span><?= htmlspecialchars($siteSettings['catchcopy_line2']) ?><br>
                        <span class="text-accent-red"><?= htmlspecialchars($siteSettings['catchcopy_line3']) ?></span><?= htmlspecialchars($siteSettings['catchcopy_line4']) ?>
                    </h2>
                </section>
            </section>
        </div>

        <div id="tab-creators" class="tab-content">
            <section class="max-w-7xl mx-auto px-6">
                <div class="text-center mb-10">
                    <h2 class="font-bold text-3xl md:text-4xl text-pink-500 mb-1">MEMBER</h2>
                    <p class="text-gray-400 font-bold tracking-widest text-xs">厳選されたクリエイターたち</p>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-4 md:gap-6 lg:gap-8" id="creator-grid"></div>
            </section>
        </div>

        <div id="tab-gallery" class="tab-content">
            <section class="max-w-7xl mx-auto px-4 md:px-6">
                <div class="text-center mb-6 md:mb-10">
                    <h2 class="font-bold text-3xl md:text-4xl text-pop-blue mb-1">GALLERY</h2>
                    <p class="text-gray-400 font-bold tracking-widest text-xs">作品アーカイブ</p>
                </div>
                <div class="flex flex-wrap justify-center gap-2 mb-8" id="gallery-filters">
                    <button class="gallery-filter active bg-pop-blue text-white px-4 py-2 rounded-full text-sm font-bold transition-all" data-category="all">すべて</button>
                    <?php 
                    $galleryCats = getGalleryCategoryOrder($db);
                    foreach ($galleryCats as $gcat): 
                    ?>
                    <button class="gallery-filter bg-white border border-gray-200 text-gray-600 px-4 py-2 rounded-full text-sm font-bold" data-category="<?= $gcat['id'] ?>"><?= htmlspecialchars($gcat['name']) ?></button>
                    <?php endforeach; ?>
                </div>
                <div id="gallery-content">
                    <?= renderGallery(null, true, true, $db) ?>
                </div>
            </section>
        </div>

        <div id="tab-lab" class="tab-content">
            <section class="max-w-7xl mx-auto px-6">
                <div class="flex flex-col md:flex-row justify-between items-end mb-16 border-b border-lab-green/30 pb-6">
                    <div>
                        <h2 class="font-code text-6xl md:text-8xl text-lab-green mb-2 tracking-widest animate-pulse">THE LAB</h2>
                        <p class="font-code text-white text-xl tracking-wider">> EXPERIMENTAL_TOOLS_</p>
                    </div>
                    <div class="text-gray-500 font-code text-sm mt-4 md:mt-0 text-right">System: Offline<br>Mode: Standby</div>
                </div>
                <div class="grid grid-cols-1 md:grid-cols-3 gap-6" id="lab-grid"></div>
            </section>
        </div>

        <div id="tab-magazine" class="tab-content">
            <section class="max-w-7xl mx-auto px-6">
                <div class="text-center mb-6">
                    <h2 class="font-bold text-3xl md:text-4xl text-pop-purple mb-1">MEDIA</h2>
                    <p class="text-gray-400 font-bold tracking-widest text-xs">ブログ・日記・インタビュー</p>
                </div>
                <div class="flex justify-center gap-2 mb-10 flex-wrap" id="media-filters">
                    <button class="media-filter active px-4 py-2 rounded-full text-sm font-bold transition" data-type="all">すべて</button>
                    <button class="media-filter px-4 py-2 rounded-full text-sm font-bold transition" data-type="blog">ブログ</button>
                    <button class="media-filter px-4 py-2 rounded-full text-sm font-bold transition" data-type="diary">日記</button>
                    <button class="media-filter px-4 py-2 rounded-full text-sm font-bold transition" data-type="interview">インタビュー</button>
                    <button class="media-filter px-4 py-2 rounded-full text-sm font-bold transition" data-type="news">ニュース</button>
                    <button class="media-filter px-4 py-2 rounded-full text-sm font-bold transition" data-type="feature">特集</button>
                </div>
                <div id="featured-articles-section" class="mb-8 hidden">
                    <div class="flex items-center gap-2 mb-4">
                        <i class="fas fa-star text-yellow-400"></i>
                        <h3 class="text-sm font-bold text-gray-500 tracking-wide">注目記事</h3>
                    </div>
                    <div class="grid grid-cols-2 md:grid-cols-3 gap-4 md:gap-5" id="featured-magazine-grid"></div>
                </div>
                <div class="grid grid-cols-2 md:grid-cols-3 gap-4 md:gap-5" id="magazine-grid"></div>
            </section>
        </div>

        <div id="tab-request" class="tab-content">
            <!-- コンタクトフォーム -->
            <section class="max-w-5xl mx-auto px-4 md:px-6 py-6 md:py-10 relative">
                <div class="bg-white rounded-2xl md:rounded-[3rem] shadow-card border border-gray-100 p-5 md:p-12 relative overflow-visible">
                    <div class="text-center mb-8 md:mb-16">
                        <span class="bg-pop-pink text-white px-4 py-1 rounded-full text-xs font-bold tracking-widest mb-4 inline-block">CONTACT FORM</span>
                        <h2 class="font-display text-2xl md:text-4xl mb-4 text-pop-black">JOB REQUEST</h2>
                        <p class="font-bold text-gray-500 mt-4 text-sm md:text-base">制作依頼・ご相談はこちらから。</p>
                    </div>
                    
                    <?php if (isset($_SESSION['contact_error'])): ?>
                    <div class="mb-8 p-4 bg-red-50 border-2 border-red-200 rounded-xl">
                        <p class="text-sm text-red-600"><?= nl2br(htmlspecialchars($_SESSION['contact_error'])) ?></p>
                    </div>
                    <?php unset($_SESSION['contact_error']); endif; ?>
                    
                    <?php if (isset($_SESSION['contact_success'])): ?>
                    <div class="mb-8 p-4 bg-green-50 border-2 border-green-200 rounded-xl">
                        <p class="text-sm text-green-600"><?= htmlspecialchars($_SESSION['contact_success']) ?></p>
                    </div>
                    <?php unset($_SESSION['contact_success']); endif; ?>
                    
                    <form id="contact-form" class="space-y-8 md:space-y-12" action="contact.php" method="POST">
                        <?= csrfField() ?>
                        <div id="nomination-field" class="hidden bg-pop-yellow/10 p-4 md:p-6 rounded-xl md:rounded-2xl border border-pop-yellow">
                            <label class="block font-bold text-sm mb-2 text-pop-black"><i class="fas fa-check-circle text-pop-yellow mr-2"></i>ご指名クリエイター</label>
                            <div class="flex items-center gap-3 md:gap-4 bg-white p-3 rounded-xl border border-gray-200">
                                <input type="text" id="nominated-creator" name="nominated_creator" class="flex-1 font-bold outline-none text-gray-700 min-w-0" readonly>
                                <button type="button" onclick="clearNomination()" class="flex-shrink-0 text-xs font-bold text-gray-400 hover:text-red-500">解除</button>
                            </div>
                            
                            <!-- サービス選択（サービスがあるクリエイターの場合のみ表示） -->
                            <div id="service-selection-field" class="hidden mt-4">
                                <label class="block font-bold text-sm mb-2 text-pop-black"><i class="fas fa-paint-brush text-pop-purple mr-2"></i>ご希望のサービス（任意）</label>
                                <select id="selected-service" name="service_id" onchange="updateServiceLink()" class="w-full bg-white border border-gray-200 rounded-xl px-3 md:px-4 py-3 font-bold text-gray-700 focus:border-pop-purple outline-none text-sm md:text-base">
                                    <option value="">選択しない（カスタム依頼）</option>
                                </select>
                                <div id="service-detail-link" class="hidden mt-2 text-right">
                                    <a id="service-detail-url" href="#" target="_blank" class="inline-flex items-center gap-1 text-xs md:text-sm text-pop-purple hover:underline">
                                        <i class="fas fa-external-link-alt"></i>
                                        このサービスについて詳しく見る
                                    </a>
                                </div>
                            </div>
                        </div>
                        
                        <div>
                            <h3 class="font-display text-lg md:text-xl border-b-2 border-gray-100 pb-2 mb-4 md:mb-6 text-gray-400">01. ご依頼内容</h3>
                            <div class="mb-6">
                                <label class="block font-bold text-gray-700 mb-3 md:mb-4 text-sm md:text-base">ご依頼カテゴリ</label>
                                <div class="grid grid-cols-2 md:grid-cols-4 gap-2 md:gap-3">
                                    <?php $categories = ['イラスト', 'マンガ', '動画', 'その他']; ?>
                                    <?php foreach($categories as $cat): ?>
                                    <label class="cursor-pointer">
                                        <input type="radio" name="category" value="<?= $cat ?>" class="peer hidden" required>
                                        <span class="custom-radio block w-full text-center py-2 md:py-3 px-3 md:px-4 rounded-xl border-2 border-gray-200 font-bold transition-all peer-checked:border-pop-yellow peer-checked:bg-pop-yellow/10 hover:border-pop-yellow text-sm md:text-base"><?= $cat ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="mb-6">
                                <label class="block font-bold text-gray-700 mb-2 text-sm md:text-base">ご依頼内容の詳細</label>
                                <textarea name="details" rows="6" required placeholder="詳細を入力..." class="w-full bg-white border-2 border-gray-200 rounded-lg px-3 md:px-4 py-3 font-bold focus:border-pop-blue outline-none transition-colors resize-none text-sm md:text-base"></textarea>
                            </div>
                        </div>

                        <div>
                            <h3 class="font-display text-lg md:text-xl border-b-2 border-gray-100 pb-2 mb-4 md:mb-6 text-gray-400">02. 予算・スケジュール</h3>
                            <div class="mb-6">
                                <label class="block font-bold text-gray-700 mb-3 md:mb-4 text-sm md:text-base">ご予算感</label>
                                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-5 gap-2 md:gap-3">
                                    <?php $budgets = ['〜5万円', '5〜10万円', '10〜30万円', '30万円〜', '要相談']; ?>
                                    <?php foreach($budgets as $b): ?>
                                    <label class="cursor-pointer">
                                        <input type="radio" name="budget" value="<?= $b ?>" class="peer hidden">
                                        <span class="custom-radio block w-full text-center py-2 px-2 md:px-4 rounded-xl border-2 border-gray-200 text-xs md:text-sm font-bold transition-all peer-checked:border-pop-yellow peer-checked:bg-pop-yellow/10 hover:border-pop-yellow"><?= $b ?></span>
                                    </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
                                <div>
                                    <label class="block font-bold text-gray-700 mb-2 text-sm md:text-base">希望納期</label>
                                    <input type="date" name="deadline" class="w-full bg-white border-2 border-gray-200 rounded-lg px-3 md:px-4 py-3 font-bold focus:border-pop-blue outline-none text-sm md:text-base">
                                </div>
                                <div>
                                    <label class="block font-bold text-gray-700 mb-2 text-sm md:text-base">使用用途</label>
                                    <input type="text" name="purpose" placeholder="SNS投稿など" class="w-full bg-white border-2 border-gray-200 rounded-lg px-3 md:px-4 py-3 font-bold focus:border-pop-blue outline-none text-sm md:text-base">
                                </div>
                            </div>
                        </div>
                        
                        <div>
                            <h3 class="font-display text-lg md:text-xl border-b-2 border-gray-100 pb-2 mb-4 md:mb-6 text-gray-400">03. お客様情報</h3>
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6 mb-4 md:mb-6">
                                <div>
                                    <label class="block font-bold text-gray-700 mb-2 text-sm md:text-base">法人名 / 個人名</label>
                                    <input type="text" name="company_name" required class="w-full bg-white border-2 border-gray-200 rounded-lg px-3 md:px-4 py-3 font-bold focus:border-pop-blue outline-none text-sm md:text-base">
                                </div>
                                <div>
                                    <label class="block font-bold text-gray-700 mb-2 text-sm md:text-base">お名前</label>
                                    <input type="text" name="name" required class="w-full bg-white border-2 border-gray-200 rounded-lg px-3 md:px-4 py-3 font-bold focus:border-pop-blue outline-none text-sm md:text-base">
                                </div>
                            </div>
                            <div>
                                <label class="block font-bold text-gray-700 mb-2 text-sm md:text-base">メールアドレス</label>
                                <input type="email" name="email" required class="w-full bg-white border-2 border-gray-200 rounded-lg px-3 md:px-4 py-3 font-bold focus:border-pop-blue outline-none text-sm md:text-base">
                            </div>
                        </div>

                        <div class="text-center pt-6 md:pt-8">
                            <button type="submit" class="w-full md:w-auto bg-pop-pink text-white px-8 md:px-16 py-4 md:py-5 rounded-full shadow-pop hover:shadow-none hover:translate-y-[2px] transition-all font-display text-base md:text-lg tracking-widest">
                                <i class="fas fa-paper-plane mr-2 md:mr-3"></i>送信する
                            </button>
                        </div>
                    </form>
                </div>
            </section>
        </div>
    </main>

    <div id="modal" class="fixed inset-0 bg-black/80 z-[100] hidden items-center justify-center p-4" onclick="closeModal()">
        <div class="bg-white rounded-[2rem] max-w-4xl w-full max-h-[90vh] overflow-y-auto relative shadow-2xl" onclick="event.stopPropagation()">
            <button onclick="closeModal()" class="absolute top-4 right-4 w-10 h-10 flex items-center justify-center bg-gray-100 rounded-full text-gray-500 hover:bg-gray-200 transition-colors z-10"><i class="fas fa-times"></i></button>
            <div class="flex flex-col md:flex-row">
                <div class="w-full md:w-1/3 p-6 bg-gray-50 flex flex-col items-center text-center">
                    <img id="m-img" src="/placeholder.svg" class="w-32 h-32 object-cover rounded-full border-4 border-white shadow-lg mb-4">
                    <h3 id="m-name" class="font-display text-2xl mb-1 text-gray-800"></h3>
                    <p id="m-role" class="text-sm font-bold text-gray-400 mb-4"></p>
                    <p id="m-bio" class="text-sm text-gray-500 leading-relaxed mb-6"></p>
                    <button onclick="requestFromModal()" class="bg-pop-pink text-white px-6 py-2 rounded-full font-bold text-sm shadow-pop hover:shadow-none transition-all"><i class="fas fa-paper-plane mr-2"></i>依頼する</button>
                </div>
                <div class="w-full md:w-2/3 p-6">
                    <h4 class="font-display text-lg mb-4 text-gray-400">WORKS</h4>
                    <div id="m-gallery" class="grid grid-cols-2 md:grid-cols-3 gap-4"></div>
                </div>
            </div>
        </div>
    </div>

    <div id="work-modal" class="fixed inset-0 bg-black/80 z-[100] hidden items-center justify-center p-4">
        <div class="absolute inset-0" onclick="closeWorkModal()"></div>
        <div class="bg-white rounded-2xl max-w-3xl w-full max-h-[90vh] overflow-y-auto relative z-10 shadow-2xl" onclick="event.stopPropagation()">
            <button onclick="closeWorkModal()" class="absolute top-4 right-4 w-10 h-10 flex items-center justify-center bg-gray-100 rounded-full text-gray-500 hover:bg-gray-200 transition-colors z-10"><i class="fas fa-times"></i></button>
            <div id="w-media" class="relative bg-gray-100"><img id="w-img" src="/placeholder.svg" class="w-full h-auto max-h-[50vh] object-contain bg-gray-100"></div>
            <div id="w-read-manga" class="hidden px-6 pt-4">
                <button class="w-full bg-pop-purple hover:bg-purple-600 text-white py-4 rounded-xl font-bold text-lg transition-colors flex items-center justify-center gap-3">
                    <i class="fas fa-book-open text-xl"></i><span>このマンガを読む</span><span class="bg-white/20 px-3 py-1 rounded-full text-sm"><span id="w-manga-pages">0</span>P</span>
                </button>
            </div>
            <!-- 購入ボタンエリア -->
            <div id="w-purchase" class="hidden px-6 pt-4">
                <a id="w-purchase-btn" href="/store/product.php" class="w-full bg-gradient-to-r from-green-500 to-emerald-500 hover:from-green-600 hover:to-emerald-600 text-white py-4 rounded-xl font-bold text-lg transition-all flex items-center justify-center gap-3 shadow-lg hover:shadow-xl">
                    <i class="fas fa-shopping-cart text-xl"></i>
                    <span>この作品を購入</span>
                    <span id="w-purchase-price" class="bg-white/20 px-3 py-1 rounded-full text-sm">¥0</span>
                </a>
            </div>
            <div class="p-6 md:p-8">
                <h2 id="w-title" class="font-display text-2xl mb-4"></h2>
                <p id="w-desc" class="text-gray-600 mb-6 whitespace-pre-wrap"></p>
                <div class="flex items-center justify-between border-t border-gray-100 pt-6">
                    <a id="w-creator-link" href="#" class="flex items-center gap-3 hover:bg-gray-50 p-2 rounded-xl transition-colors">
                        <img id="w-creator-img" src="/placeholder.svg" class="w-10 h-10 rounded-full object-cover">
                        <span id="w-creator-name" class="font-bold text-gray-700"></span>
                    </a>
                    <div class="flex items-center gap-2">
                        <button onclick="shareWork()" class="w-9 h-9 flex items-center justify-center rounded-full bg-gray-100 hover:bg-gray-200 text-gray-600 transition-colors"><i class="fas fa-share-alt"></i></button>
                        <button onclick="copyWorkLink()" class="w-9 h-9 flex items-center justify-center rounded-full bg-gray-100 hover:bg-gray-200 text-gray-600 transition-colors"><i class="fas fa-link"></i></button>
                        <a id="w-share-x" href="#" target="_blank" class="w-9 h-9 flex items-center justify-center rounded-full bg-gray-100 hover:bg-gray-200 text-gray-600 transition-colors"><svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg></a>
                    </div>
                </div>
                <div id="w-copy-toast" class="hidden fixed bottom-24 left-1/2 -translate-x-1/2 bg-gray-900 text-white px-4 py-2 rounded-full text-sm font-bold shadow-lg z-50"><i class="fas fa-check mr-2"></i>リンクをコピーしました</div>
            </div>
        </div>
    </div>

    <div id="lab-modal" class="fixed inset-0 bg-black z-[100] hidden items-center justify-center">
        <button onclick="closeLabModal()" class="absolute top-4 right-4 text-lab-green font-code text-xl hover:text-white z-20">[ CLOSE ]</button>
        <div class="w-full h-full flex items-center justify-center">
            <div class="text-center">
                <div class="text-lab-green font-code text-6xl mb-6 animate-pulse">&lt;/&gt;</div>
                <h2 class="text-lab-green font-code text-3xl mb-4">COMING SOON</h2>
                <p class="text-lab-green/60 font-code text-sm">このツールは現在開発中です</p>
                <p class="text-lab-green/40 font-code text-xs mt-2">// Stay tuned for updates</p>
            </div>
        </div>
    </div>


    <?php include 'includes/modals.php'; ?>


    <script>
        const creators = <?= $creatorsJson ?>;
        const allWorks = <?= $worksJson ?>;
        const articles = <?= $articlesJson ?>;
        const labTools = <?= $labToolsJson ?>;
        const collections = <?= $collectionsJson ?>;
        const HOME_ARTICLES_LIMIT = <?= (int)$homeArticlesLimit ?>;
        const FEATURED_ARTICLES_LIMIT = <?= (int)$featuredArticlesLimit ?>;
        const HOME_INCLUDE_FEATURED = <?= (int)$homeIncludeFeatured ?>;
        
        let currentWorkId = null;
        let currentCreator = null;
        
        // モーダル共通設定
        window.ModalConfig = {
            works: allWorks,
            collections: collections,
            creatorName: '',
            baseImagePath: '/'
        };

        function getImagePath(img) {
            if (!img) return 'https://images.unsplash.com/photo-1513245543132-31f507417b26?q=80&w=600&auto=format&fit=crop';
            if (img.startsWith('http')) return img;
            if (img.startsWith('/')) return img;
            return '/' + img;
        }
        
        // 画像/動画パスから適切なメディア要素を返す
        // webmExistsがtrueの場合はvideoタグを生成
        function getMediaElement(img, className = '', style = '', alt = '', webmExists = false) {
            if (!img) return `<img src="https://images.unsplash.com/photo-1513245543132-31f507417b26?q=80&w=600&auto=format&fit=crop" class="${className}" style="${style}" alt="${alt}" loading="lazy">`;
            
            const path = getImagePath(img);
            
            // .webmパスの場合、または.gif/.GIFでwebmExistsがtrueの場合
            const isWebm = path.toLowerCase().endsWith('.webm');
            const isGif = path.toLowerCase().endsWith('.gif');
            
            if (isWebm || (isGif && webmExists)) {
                const webmPath = isWebm ? path : path.replace(/\.gif$/i, '.webm');
                const classAttr = className ? ` class="${className}"` : '';
                const styleAttr = style ? ` style="${style}"` : '';
                // onloadedmetadataでplay()を呼び、endedでループ
                return `<video autoplay loop muted playsinline${classAttr}${styleAttr} onloadedmetadata="this.play()" onended="this.currentTime=0;this.play()"><source src="${webmPath}" type="video/webm"><img src="${path}" alt="${alt}"></video>`;
            }
            
            return `<img src="${path}" class="${className}" style="${style}" alt="${alt}" loading="lazy">`;
        }

        // Updated switchTab function for Bottom Nav and Top Nav (PC)
        function switchTab(tabId) {
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            const target = document.getElementById('tab-' + tabId);
            if(target) target.classList.add('active');
            
            const body = document.body;
            const topLogo = document.getElementById('top-logo');
            const labTrigger = document.getElementById('lab-trigger');

            // モバイル用ボトムナビのアクティブ状態
            document.querySelectorAll('.nav-item').forEach(btn => {
                btn.classList.remove('active');
                if (btn.dataset.target === tabId) {
                    btn.classList.add('active');
                    const icon = btn.querySelector('.nav-icon');
                    if(icon) {
                        icon.classList.add('animate-bounce');
                        setTimeout(() => icon.classList.remove('animate-bounce'), 500);
                    }
                }
            });

            // PC用トップナビのアクティブ状態
            document.querySelectorAll('.top-nav-btn').forEach(btn => {
                btn.classList.remove('active');
                if (btn.dataset.target === tabId) {
                    btn.classList.add('active');
                }
            });

            if (tabId === 'lab') {
                body.classList.add('lab-mode');
                if (topLogo) {
                    topLogo.classList.remove('bg-white/90', 'text-pop-black', 'border-gray-100');
                    topLogo.classList.add('bg-black/50', 'text-white', 'border-lab-green/30');
                }
                if (labTrigger) {
                    labTrigger.classList.add('ring-2', 'ring-lab-green', 'ring-offset-2', 'ring-offset-black');
                }
            } else {
                body.classList.remove('lab-mode');
                if (topLogo) {
                    topLogo.classList.add('bg-white/90', 'text-pop-black', 'border-gray-100');
                    topLogo.classList.remove('bg-black/50', 'text-white', 'border-lab-green/30');
                }
                if (labTrigger) {
                    labTrigger.classList.remove('ring-2', 'ring-lab-green', 'ring-offset-2', 'ring-offset-black');
                }
            }
            window.scrollTo({ top: 0 }); // behavior: 'smooth' を削除して即時スクロールに変更
        }

        // ... (Existing helper functions: initContents, renderGallery, etc.) ...
        function initContents() {
            const cGrid = document.getElementById('creator-grid');
            if(cGrid) {
                cGrid.innerHTML = creators.map(c => `
                    <a href="${c.slug ? '/creator/' + c.slug : 'creator.php?id=' + c.id}" class="bg-white border border-gray-200 rounded-2xl md:rounded-3xl p-3 md:p-4 cursor-pointer hover:-translate-y-2 transition-all shadow-card hover:shadow-pop block active:scale-[0.98] group">
                        <div class="relative w-full aspect-square rounded-xl md:rounded-2xl overflow-hidden mb-3 md:mb-4 bg-gray-100">
                            ${getMediaElement(c.image, 'w-full h-full object-cover group-hover:scale-105 transition-transform duration-300', '', '', c.webm_exists)}
                            <div class="absolute bottom-2 right-2 bg-white/90 backdrop-blur px-2 py-1 text-xs font-bold rounded-lg text-gray-600">${c.role || 'クリエイター'}</div>
                        </div>
                        <h3 class="font-display text-base md:text-lg text-gray-800 truncate">${c.name}</h3>
                    </a>`).join('');
            }
            // ギャラリーフィルター（PHPでレンダリングされたカテゴリを表示/非表示）
            document.querySelectorAll('.gallery-filter').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.querySelectorAll('.gallery-filter').forEach(b => {
                        b.classList.remove('active', 'bg-pop-blue', 'text-white');
                        b.classList.add('bg-white', 'text-gray-600', 'border-gray-200');
                    });
                    btn.classList.add('active', 'bg-pop-blue', 'text-white');
                    btn.classList.remove('bg-white', 'text-gray-600', 'border-gray-200');
                    
                    const category = btn.dataset.category;
                    document.querySelectorAll('.gallery-category').forEach(section => {
                        if (category === 'all' || section.dataset.category === category) {
                            section.style.display = 'block';
                        } else {
                            section.style.display = 'none';
                        }
                    });
                });
            });
            const lGrid = document.getElementById('lab-grid');
            if(lGrid) {
                const defaultTools = [
                    { id: '3d-mannequin', name: "3D Pose Mannequin (Beta)", description: "Webで動くデッサン人形。", icon: "fas fa-cube", tool_type: '3d-mannequin' },
                    { id: 'color-gen', name: "Cyber Palette Gen", description: "ランダムに配色を生成。", icon: "fas fa-palette", tool_type: 'color-gen' },
                    { id: 'timer', name: "Focus Timer", description: "作業用タイマー。", icon: "fas fa-hourglass-half", tool_type: 'timer' }
                ];
                const tools = labTools.length > 0 ? labTools : defaultTools;
                lGrid.innerHTML = tools.map((t) => `
                    <div class="bg-lab-bg border border-lab-green p-6 cursor-pointer hover:bg-lab-green/10 transition-colors group" onclick="openLabTool('${t.tool_type || t.id}')">
                        <div class="text-4xl text-lab-green mb-4"><i class="${t.icon || 'fas fa-flask'}"></i></div>
                        <h3 class="font-code text-xl text-white mb-2">> ${t.name}_</h3>
                        <p class="font-code text-gray-400 text-sm">${t.description}</p>
                    </div>`).join('');
            }
            renderArticles();
            renderHomeArticles();
            document.querySelectorAll('.media-filter').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.querySelectorAll('.media-filter').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    renderArticles(btn.dataset.type);
                });
            });
            initPickupSlider();
        }

        const typeColors = {'blog':'bg-blue-500','diary':'bg-pink-500','interview':'bg-purple-500','news':'bg-green-500','feature':'bg-orange-500'};
        const typeLabels = {'blog':'ブログ','diary':'日記','interview':'インタビュー','news':'ニュース','feature':'特集'};
        function renderArticleCard(a, isLarge = false) {
            const type = a.article_type || 'blog';
            const colorClass = typeColors[type] || 'bg-pop-purple';
            const label = typeLabels[type] || a.category || 'FEATURE';
            const isFeatured = Number(a.is_featured) === 1;
            const isHome = Number(a.is_home) === 1;
            const badges = [];
            if (isFeatured) badges.push(`<div class="bg-yellow-400 text-gray-900 text-xs font-bold px-1.5 py-0.5 rounded shadow-sm"><i class="fas fa-star mr-0.5"></i>注目</div>`);
            if (isHome) badges.push(`<div class="bg-blue-500 text-white text-xs font-bold px-1.5 py-0.5 rounded shadow-sm"><i class="fas fa-house mr-0.5"></i>HOME</div>`);
            const badgeStack = badges.length ? `<div class="absolute top-2 right-2 flex flex-col items-end gap-0.5">${badges.join('')}</div>` : '';
            const altText = a.title ? a.title : '記事画像';
            
            // HOME/MEDIA共通カード（サムネ比率 1200:630）
            return `<a href="${a.slug ? '/article/' + a.slug : 'article.php?id=' + a.id}" class="bg-white border border-gray-200 rounded-xl overflow-hidden hover:-translate-y-1 transition-transform shadow-sm hover:shadow-md block group"><div class="relative border-b border-gray-100 overflow-hidden" style="aspect-ratio: 1200/630;"><img src="${getImagePath(a.image)}" class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300" alt="${altText}" loading="lazy"><div class="absolute top-2 left-2 ${colorClass} text-white text-xs font-bold px-2 py-0.5 rounded shadow-sm">${label}</div>${badgeStack}</div><div class="p-2.5"><h3 class="font-bold text-sm leading-snug text-gray-800 line-clamp-2">${a.title}<\/h3><\/div><\/a>`;
        }
        function sortArticlesByDateDesc(a, b) {
            const da = a.published_at ? new Date(a.published_at).getTime() : 0;
            const db = b.published_at ? new Date(b.published_at).getTime() : 0;
            if (db !== da) return db - da;
            return (Number(b.id)||0) - (Number(a.id)||0);
        }
        function toIntOrNull(v) { if(v===null||v===undefined||v==='') return null; const n=parseInt(v,10); return Number.isNaN(n)?null:n; }
        function sortByOptionalOrderThenDate(orderKey) {
            return (a, b) => {
                const oa = toIntOrNull(a[orderKey]); const ob = toIntOrNull(b[orderKey]);
                if (oa!==null && ob!==null && oa!==ob) return oa - ob;
                if (oa!==null && ob===null) return -1;
                if (oa===null && ob!==null) return 1;
                return sortArticlesByDateDesc(a, b);
            };
        }
        const sortFeaturedArticles = sortByOptionalOrderThenDate('featured_order');
        const sortHomeArticlesByHomeOrder = sortByOptionalOrderThenDate('home_order');
        function uniqById(list) {
            const seen = new Set(); const out = [];
            for (const it of (list||[])) {
                const id = it && (it.id!==undefined ? String(it.id) : null);
                if (!id) continue;
                if (seen.has(id)) continue;
                seen.add(id);
                out.push(it);
            }
            return out;
        }
        function renderArticles(filter = 'all') {
            const mGrid = document.getElementById('magazine-grid');
            const featuredSection = document.getElementById('featured-articles-section');
            const featuredGrid = document.getElementById('featured-magazine-grid');
            const list = (filter === 'all') ? articles : articles.filter(a => (a.article_type || 'blog') === filter);
            const featured = list.filter(a => Number(a.is_featured) === 1).slice().sort(sortFeaturedArticles);
            const others = list.filter(a => Number(a.is_featured) !== 1).slice().sort(sortArticlesByDateDesc);
            const canShowFeaturedSection = (filter === 'all') && FEATURED_ARTICLES_LIMIT > 0 && featured.length > 0 && featuredSection && featuredGrid;
            if (featuredSection && featuredGrid) {
                if (canShowFeaturedSection) {
                    featuredSection.classList.remove('hidden');
                    featuredGrid.innerHTML = featured.slice(0, FEATURED_ARTICLES_LIMIT).map(renderArticleCard).join('');
                } else {
                    featuredSection.classList.add('hidden');
                    featuredGrid.innerHTML = '';
                }
            }
            if (!mGrid) return;
            const mainList = canShowFeaturedSection ? others : featured.concat(others);
            if (mainList.length > 0) mGrid.innerHTML = mainList.map(renderArticleCard).join('');
            else mGrid.innerHTML = `<div class="col-span-3 text-center py-12 text-gray-400"><i class="fas fa-newspaper text-4xl mb-4"></i><p>${(canShowFeaturedSection && featured.length > 0) ? '他の記事がありません' : '記事がありません'}</p></div>`;
        }
        function renderHomeArticles() {
            const section = document.getElementById('home-articles-section');
            const grid = document.getElementById('home-articles-grid');
            if (!section || !grid) return;
            if (HOME_ARTICLES_LIMIT <= 0) { section.classList.add('hidden'); grid.innerHTML = ''; return; }
            const featured = articles.filter(a => Number(a.is_featured) === 1).slice().sort(sortFeaturedArticles);
            const pinned = articles.filter(a => Number(a.is_home) === 1).slice().sort(sortHomeArticlesByHomeOrder);
            let list = HOME_INCLUDE_FEATURED ? uniqById(pinned.concat(featured)) : uniqById(pinned);
            list = list.slice().sort((a, b) => {
                const aPinned = Number(a.is_home) === 1; const bPinned = Number(b.is_home) === 1;
                if (aPinned && bPinned) return sortHomeArticlesByHomeOrder(a, b);
                if (aPinned && !bPinned) return -1;
                if (!aPinned && bPinned) return 1;
                return sortFeaturedArticles(a, b);
            });
            const out = list.slice(0, HOME_ARTICLES_LIMIT);
            if (out.length > 0) { section.classList.remove('hidden'); grid.innerHTML = out.map(a => renderArticleCard(a)).join(''); }
            else { section.classList.add('hidden'); grid.innerHTML = ''; }
        }
        function initPickupSlider() {
            const pickupSlider = document.getElementById('pickup-slider');
            const pickupDots = document.getElementById('pickup-dots');
            if (!pickupSlider || !pickupDots) return;
            const featuredWorks = allWorks.filter(w => w.is_featured).sort((a, b) => (a.sort_order || 0) - (b.sort_order || 0));
            const pickupWorks = featuredWorks.length > 0 ? featuredWorks : allWorks.slice(0, 5);
            if (pickupWorks.length === 0) return;
            
            const isMobile = window.innerWidth < 768;
            const slideWidth = isMobile ? 280 : 400; // PCで大きく表示
            const gap = isMobile ? 32 : 48; // モバイルでもgapを大きく
            const containerWidth = pickupSlider.parentElement.offsetWidth;
            const sidePadding = Math.max(16, (containerWidth - slideWidth) / 2);
            
            pickupSlider.style.paddingLeft = sidePadding + 'px';
            pickupSlider.style.paddingRight = sidePadding + 'px';
            
            pickupSlider.innerHTML = pickupWorks.map((w, i) => {
                const author = creators.find(c => c.id === w.creator_id);
                const cropPosition = w.crop_position || 'center';
                const hasMangaPages = w.manga_pages && w.manga_pages.length > 0;
                const categories = (w.category || '').split(',').filter(c => c);
                const categoryLabels = {'illustration':'イラスト','manga':'マンガ','video':'動画','animation':'アニメ','3d':'3D','other':'その他'};
                const firstCategory = categories[0] || '';
                const categoryLabel = categoryLabels[firstCategory] || firstCategory;
                let badge = '';
                if (hasMangaPages) badge = `<div class="absolute top-2 right-2 bg-pop-purple/90 text-white text-xs px-2 py-1 rounded font-bold z-10">${w.manga_pages.length}P</div>`;
                else if (categoryLabel) badge = `<div class="absolute top-2 right-2 bg-black/50 text-white text-xs px-2 py-1 rounded font-medium z-10">${categoryLabel}</div>`;
                const altText = w.title ? (w.title + (author ? ' - ' + author.name : '')) : 'ピックアップ作品';
                
                // WebM対応
                const mediaHtml = getMediaElement(w.image, 'w-full h-full object-cover', `object-position: ${cropPosition}`, altText, w.webm_exists);
                
                return `<div class="pickup-slide flex-shrink-0 snap-center cursor-pointer group" style="width: ${slideWidth}px; margin-right: ${gap}px; scroll-snap-align: center; transition: none;" data-work-id="${w.id}" data-index="${i}"><div class="relative aspect-video rounded-2xl overflow-hidden shadow-lg" style="transition: transform 0.15s ease-out;">${mediaHtml}<div class="absolute inset-x-0 bottom-0 h-16 bg-gradient-to-t from-black/50 to-transparent"></div><div class="absolute bottom-2 left-3 right-3"><h4 class="text-white font-bold text-sm truncate drop-shadow-lg">${w.title || ''}</h4><p class="text-white/70 text-xs truncate drop-shadow">${author ? author.name : ''}</p></div>${badge}</div></div>`;
            }).join('');
            
            pickupDots.innerHTML = pickupWorks.map((_, i) => `<button class="pickup-dot w-2 h-2 rounded-full transition-all duration-300 ${i === 0 ? 'bg-pop-yellow w-6' : 'bg-gray-300 hover:bg-gray-400'}" data-index="${i}"></button>`).join('');
            
            // ドットクリック時のスクロール - 正確な位置計算
            pickupDots.querySelectorAll('.pickup-dot').forEach(dot => {
                dot.addEventListener('click', () => {
                    const index = parseInt(dot.dataset.index);
                    const targetScrollLeft = index * (slideWidth + gap);
                    pickupSlider.scrollTo({ left: targetScrollLeft, behavior: 'smooth' });
                });
            });
            
            // スケール効果をリアルタイムで適用する関数 - 修正版
            function updateSlideScales() {
                const scrollLeft = pickupSlider.scrollLeft;
                // スライダーの実際の表示領域の幅を取得
                const sliderWidth = pickupSlider.offsetWidth;
                const viewportCenter = sliderWidth / 2;
                
                document.querySelectorAll('.pickup-slide').forEach((slide, index) => {
                    // 各スライドの左端位置（パディング込み）
                    const slideLeftEdge = sidePadding + index * (slideWidth + gap);
                    // スライドの中心位置
                    const slideCenter = slideLeftEdge + slideWidth / 2;
                    
                    // 現在の表示領域内でのスライド中心位置
                    const slidePositionInViewport = slideCenter - scrollLeft;
                    
                    // ビューポート中央からの距離
                    const distanceFromCenter = Math.abs(slidePositionInViewport - viewportCenter);
                    
                    // 距離に応じてスケールを計算（中央で1.15、離れるほど0.95まで - 控えめに）
                    const maxDistance = slideWidth * 1.5;
                    const normalizedDistance = Math.min(distanceFromCenter / maxDistance, 1);
                    const scale = 1.15 - (normalizedDistance * 0.2);
                    
                    const innerDiv = slide.querySelector('div');
                    if (innerDiv) {
                        innerDiv.style.transform = `scale(${scale})`;
                    }
                });
            }
            
            // スクロール中にリアルタイムでスケール更新
            pickupSlider.addEventListener('scroll', () => {
                updateSlideScales();
                
                // ドット更新
                const currentIndex = Math.round(pickupSlider.scrollLeft / (slideWidth + gap));
                pickupDots.querySelectorAll('.pickup-dot').forEach((dot, i) => {
                    if (i === currentIndex) { 
                        dot.classList.add('bg-pop-yellow', 'w-6'); 
                        dot.classList.remove('bg-gray-300', 'w-2'); 
                    } else { 
                        dot.classList.remove('bg-pop-yellow', 'w-6'); 
                        dot.classList.add('bg-gray-300', 'w-2'); 
                    }
                });
            });
            
            // マウスドラッグ操作
            let isDown = false; let startX; let scrollLeftStart; let hasMoved = false;
            pickupSlider.addEventListener('mousedown', (e) => { isDown = true; hasMoved = false; pickupSlider.style.cursor = 'grabbing'; startX = e.pageX - pickupSlider.offsetLeft; scrollLeftStart = pickupSlider.scrollLeft; });
            pickupSlider.addEventListener('mouseleave', () => { isDown = false; pickupSlider.style.cursor = 'grab'; });
            pickupSlider.addEventListener('mouseup', () => { isDown = false; pickupSlider.style.cursor = 'grab'; });
            pickupSlider.addEventListener('mousemove', (e) => { if (!isDown) return; e.preventDefault(); const x = e.pageX - pickupSlider.offsetLeft; const walk = (x - startX) * 1.5; if (Math.abs(walk) > 5) hasMoved = true; pickupSlider.scrollLeft = scrollLeftStart - walk; });
            
            // クリック時のモーダル表示
            pickupSlider.addEventListener('click', (e) => {
                if (hasMoved) { hasMoved = false; return; }
                const slide = e.target.closest('.pickup-slide');
                if (slide) { const workId = parseInt(slide.dataset.workId); if (workId) openWorkModal(workId); }
            });
            
            // 初期スケール適用
            updateSlideScales();
        }

        function openCreatorModal(id) {
            closeWorkModal();
            const c = creators.find(x => x.id === id);
            if (!c) return;
            currentCreator = c;
            document.getElementById('m-name').innerText = c.name;
            document.getElementById('m-img').src = getImagePath(c.image);
            document.getElementById('m-role').innerText = c.role || 'クリエイター';
            document.getElementById('m-bio').innerText = c.description || '';
            const cWorks = allWorks.filter(w => w.creator_id === id);
            document.getElementById('m-gallery').innerHTML = cWorks.map(w => `<div class="aspect-square rounded-xl overflow-hidden cursor-pointer hover:opacity-80 transition-opacity bg-gray-100" onclick="openWorkModal(${w.id})">${getMediaElement(w.image, 'w-full h-full object-cover', '', '', w.webm_exists)}</div>`).join('');
            document.getElementById('modal').classList.remove('hidden');
            document.getElementById('modal').classList.add('flex');
        }
        function closeModal() { document.getElementById('modal').classList.add('hidden'); document.getElementById('modal').classList.remove('flex'); }
        function getYouTubeVideoId(url) { if (!url) return null; const match = url.match(/(?:youtube\.com\/watch\?v=|youtu\.be\/|youtube\.com\/embed\/)([^&\?\/]+)/); return match ? match[1] : null; }
        function openWorkModal(id) {
            const work = allWorks.find(w => w.id === id);
            if (!work) return;
            currentWorkId = id;
            const c = creators.find(x => x.id === work.creator_id);
            const mediaContainer = document.getElementById('w-media');
            const readMangaBtn = document.getElementById('w-read-manga');
            const purchaseArea = document.getElementById('w-purchase');
            const youtubeId = getYouTubeVideoId(work.youtube_url);
            const hasCustomImage = work.image && !work.image.includes('youtube.com') && !work.image.includes('img.youtube.com');
            const useImage = (work.thumbnail_type === 'image' && hasCustomImage) || !youtubeId;
            
            // マンガ読むボタン
            if (work.manga_pages && work.manga_pages.length > 0) {
                readMangaBtn.classList.remove('hidden');
                const pageCount = work.manga_pages.length;
                
                // 商品紐付けがある場合は「試し読み」表示
                if (work.product_id && work.product) {
                    const previewPages = work.product.preview_pages || 3;
                    readMangaBtn.querySelector('button').innerHTML = `
                        <i class="fas fa-book-open text-xl"></i>
                        <span>試し読みする</span>
                        <span class="bg-white/20 px-3 py-1 rounded-full text-sm">${previewPages}P無料</span>
                    `;
                } else {
                    readMangaBtn.querySelector('button').innerHTML = `
                        <i class="fas fa-book-open text-xl"></i>
                        <span>このマンガを読む</span>
                        <span class="bg-white/20 px-3 py-1 rounded-full text-sm">${pageCount}P</span>
                    `;
                }
                readMangaBtn.querySelector('button').onclick = function() { window.location.href = '/manga/' + work.id; };
            } else { 
                readMangaBtn.classList.add('hidden'); 
            }
            
            // 購入ボタン表示
            if (work.product_id && work.product) {
                purchaseArea.classList.remove('hidden');
                document.getElementById('w-purchase-btn').href = '/store/product.php?id=' + work.product_id;
                document.getElementById('w-purchase-price').textContent = '¥' + Number(work.product.price).toLocaleString();
            } else {
                purchaseArea.classList.add('hidden');
            }
            
            if (youtubeId && !useImage) {
                mediaContainer.innerHTML = `<div class="relative w-full" style="padding-bottom: 56.25%;"><iframe src="https://www.youtube.com/embed/${youtubeId}?autoplay=1" class="absolute inset-0 w-full h-full" frameborder="0" allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" allowfullscreen></iframe></div>`;
            } else {
                const mediaHtml = getMediaElement(work.image, 'w-full h-auto max-h-[50vh] object-contain bg-gray-100', '', '', work.webm_exists);
                mediaContainer.innerHTML = mediaHtml + (youtubeId ? `<a href="https://www.youtube.com/watch?v=${youtubeId}" target="_blank" class="absolute bottom-4 right-4 bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-full font-bold text-sm transition-colors"><i class="fab fa-youtube mr-2"></i>動画を見る</a>` : '');
            }
            document.getElementById('w-title').innerText = work.title || '';
            document.getElementById('w-desc').innerText = work.description || '';
            if (c) {
                document.getElementById('w-creator-img').src = getImagePath(c.image);
                document.getElementById('w-creator-name').innerText = c.name;
                document.getElementById('w-creator-link').href = c.slug ? `/creator/${c.slug}` : `creator.php?id=${c.id}`;
            }
            const shareUrl = encodeURIComponent(window.location.origin + '/index.php?work=' + id);
            const shareText = encodeURIComponent(work.title + ' | ぷれぐら！PLAYGROUND');
            document.getElementById('w-share-x').href = `https://twitter.com/intent/tweet?url=${shareUrl}&text=${shareText}`;
            document.getElementById('work-modal').classList.remove('hidden');
            document.getElementById('work-modal').classList.add('flex');
        }
        function closeWorkModal() {
            const mediaContainer = document.getElementById('w-media');
            if (mediaContainer.querySelector('iframe')) mediaContainer.innerHTML = '';
            mediaContainer.innerHTML = `<img id="w-img" src="/placeholder.svg" class="w-full h-auto max-h-[50vh] object-contain bg-gray-100">`;
            document.getElementById('work-modal').classList.add('hidden');
            document.getElementById('work-modal').classList.remove('flex');
        }
        async function shareWork() {
            if (!currentWorkId) return;
            const work = allWorks.find(w => w.id === currentWorkId);
            if (!work) return;
            const shareData = { title: work.title, text: work.title + ' | ぷれぐら！PLAYGROUND', url: window.location.origin + '/index.php?work=' + currentWorkId };
            if (navigator.share) { try { await navigator.share(shareData); } catch (err) { console.log('Share cancelled', err); } } else { copyWorkLink(); }
        }
        function copyWorkLink() {
            if (!currentWorkId) return;
            const url = window.location.origin + '/index.php?work=' + currentWorkId;
            navigator.clipboard.writeText(url).then(() => {
                const toast = document.getElementById('w-copy-toast');
                toast.classList.remove('hidden');
                setTimeout(() => toast.classList.add('hidden'), 2000);
            });
        }
        function nominateCreator(creatorId) {
            const creator = creators.find(c => c.id === creatorId);
            if (!creator) return;
            document.getElementById('nominated-creator').value = creator.name;
            document.getElementById('nomination-field').classList.remove('hidden');
            
            // サービス選択の更新
            const serviceField = document.getElementById('service-selection-field');
            const serviceSelect = document.getElementById('selected-service');
            const serviceLinkDiv = document.getElementById('service-detail-link');
            
            // サービスがあるクリエイターの場合はプルダウンを表示
            if (creator.services && creator.services.length > 0) {
                serviceSelect.innerHTML = '<option value="">選択しない（カスタム依頼）</option>';
                creator.services.forEach(s => {
                    const option = document.createElement('option');
                    option.value = s.id;
                    option.textContent = s.title + ' - ¥' + s.price.toLocaleString() + '〜';
                    serviceSelect.appendChild(option);
                });
                serviceField.classList.remove('hidden');
            } else {
                serviceField.classList.add('hidden');
            }
            serviceLinkDiv.classList.add('hidden');
            
            document.getElementById('nomination-field').scrollIntoView({ behavior: 'smooth', block: 'center' });
        }
        function clearNomination() { 
            document.getElementById('nominated-creator').value = ''; 
            document.getElementById('nomination-field').classList.add('hidden');
            document.getElementById('service-selection-field').classList.add('hidden');
            document.getElementById('selected-service').value = '';
            document.getElementById('service-detail-link').classList.add('hidden');
        }
        function updateServiceLink() {
            const serviceId = document.getElementById('selected-service').value;
            const linkDiv = document.getElementById('service-detail-link');
            const linkUrl = document.getElementById('service-detail-url');
            
            if (serviceId) {
                linkUrl.href = '/store/services/detail.php?id=' + serviceId;
                linkDiv.classList.remove('hidden');
            } else {
                linkDiv.classList.add('hidden');
            }
        }
        function requestFromModal() { if (currentCreator) { nominateCreator(currentCreator.id); switchTab('request'); closeModal(); } }
        function openLabTool(toolType) { 
            const modal = document.getElementById('lab-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }
        function closeLabModal() { document.getElementById('lab-modal').classList.add('hidden'); document.getElementById('lab-modal').classList.remove('flex'); }
        


        window.addEventListener('DOMContentLoaded', () => {
            initContents();
            
            // ローディングアニメーション制御 (アニメーションは初回のみ)
            const loader = document.getElementById('loader');
            if (loader) {
                if (!sessionStorage.getItem('site_visited')) {
                    // 初回アクセス時
                    setTimeout(() => {
                        loader.style.opacity = '0';
                        loader.style.visibility = 'hidden';
                        setTimeout(() => {
                            loader.remove();
                            sessionStorage.setItem('site_visited', 'true');
                        }, 500);
                    }, 800);
                } else {
                    // 訪問済みの場合、念のためDOMから削除
                    loader.remove();
                }
            }

            const urlParams = new URLSearchParams(window.location.search);
            const nominatedId = urlParams.get('nominated');
            const creatorName = urlParams.get('creator');
            const hash = window.location.hash;
            const savedTab = sessionStorage.getItem('activeTab');
            if (savedTab) { sessionStorage.removeItem('activeTab'); switchTab(savedTab.replace('tab-', '')); }
            else if (hash) { switchTab(hash.replace('#tab-', '')); }
            else if (creatorName || nominatedId) { switchTab('request'); }
            else { switchTab('home'); }
            
            // クリエイター名が直接指定された場合
            if (creatorName) {
                const decodedName = decodeURIComponent(creatorName);
                document.getElementById('nominated-creator').value = decodedName;
                document.getElementById('nomination-field').classList.remove('hidden');
                
                // 名前でクリエイターを検索してサービスを表示
                const creator = creators.find(c => c.name === decodedName);
                if (creator && creator.services && creator.services.length > 0) {
                    const serviceField = document.getElementById('service-selection-field');
                    const serviceSelect = document.getElementById('selected-service');
                    
                    serviceSelect.innerHTML = '<option value="">選択しない（カスタム依頼）</option>';
                    creator.services.forEach(s => {
                        const option = document.createElement('option');
                        option.value = s.id;
                        option.textContent = s.title + ' - ¥' + s.price.toLocaleString() + '〜';
                        serviceSelect.appendChild(option);
                    });
                    serviceField.classList.remove('hidden');
                }
            }
            // クリエイターIDが指定された場合
            else if (nominatedId) {
                const creator = creators.find(c => c.id == nominatedId);
                if (creator) {
                    setTimeout(() => { nominateCreator(creator.id); }, 100);
                }
            }
        });
    </script>
    
    <footer class="bg-gray-900 text-white py-12 pb-28 lg:pb-12">
        <div class="max-w-7xl mx-auto px-6">
            <div class="grid grid-cols-1 md:grid-cols-4 gap-8 mb-8">
                <div class="md:col-span-2">
                    <h3 class="font-display text-2xl mb-3"><i class="fas fa-shapes text-pop-yellow mr-2"></i><?= htmlspecialchars($siteSettings['site_name']) ?></h3>
                    <p class="text-gray-400 text-sm mb-4"><?= htmlspecialchars($siteSettings['site_subtitle']) ?></p>
                    <p class="text-gray-500 text-xs leading-relaxed"><?= htmlspecialchars($siteSettings['site_description']) ?></p>
                </div>
                <nav>
                    <h4 class="font-bold text-sm mb-4 text-gray-300">サイトマップ</h4>
                    <ul class="space-y-2 text-sm">
                        <li><a href="#" onclick="switchTab('home')" class="text-gray-400 hover:text-pop-yellow transition-colors">ホーム</a></li>
                        <li><a href="#" onclick="switchTab('creators')" class="text-gray-400 hover:text-pop-yellow transition-colors">クリエイター一覧</a></li>
                        <li><a href="#" onclick="switchTab('gallery')" class="text-gray-400 hover:text-pop-yellow transition-colors">ギャラリー</a></li>
                        <li><a href="#" onclick="switchTab('magazine')" class="text-gray-400 hover:text-pop-yellow transition-colors">メディア</a></li>
                        <li><a href="#" onclick="switchTab('request')" class="text-gray-400 hover:text-pop-yellow transition-colors">お仕事依頼</a></li>
                    </ul>
                </nav>
                <div>
                    <h4 class="font-bold text-sm mb-4 text-gray-300">フォローする</h4>
                    <div class="flex gap-4">
                        <?php if (!empty($siteSettings['sns_x'])): ?>
                        <a href="<?= htmlspecialchars($siteSettings['sns_x']) ?>" target="_blank" class="text-white hover:text-pop-yellow transition-colors"><svg class="w-5 h-5" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg></a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="border-t border-gray-800 pt-8 flex flex-col md:flex-row justify-between items-center text-gray-500 text-xs">
                <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($siteSettings['footer_copyright']) ?>. All Rights Reserved.</p>
                <div class="flex gap-4 mt-4 md:mt-0"><a href="privacy.php" class="hover:text-gray-300">プライバシーポリシー</a><a href="terms.php" class="hover:text-gray-300">利用規約</a></div>
            </div>
        </div>
    </footer>
    
    <!-- PWA インストールバナー -->
    <div id="pwa-install-banner" class="fixed bottom-0 left-0 right-0 bg-gray-900 text-white p-4 transform translate-y-full transition-transform duration-300 z-50 safe-area-pb">
        <div class="max-w-lg mx-auto">
            <!-- 通常表示（Android / 初期状態） -->
            <div id="pwa-banner-default" class="flex items-center gap-4">
                <div class="w-12 h-12 bg-pop-yellow rounded-xl flex items-center justify-center shrink-0">
                    <i class="fas fa-mobile-alt text-gray-900 text-xl"></i>
                </div>
                <div class="flex-1 min-w-0">
                    <p class="font-bold text-sm">アプリとして使えます</p>
                    <p class="text-xs text-gray-400 hidden" id="pwa-ios-hint">下の<span class="inline-flex items-center mx-1"><i class="fas fa-share-from-square"></i></span>から「ホーム画面に追加」</p>
                    <p class="text-xs text-gray-400" id="pwa-android-hint">ホーム画面に追加すると、より快適に閲覧できます</p>
                </div>
                <button id="pwa-install-btn" class="bg-pop-yellow text-gray-900 font-bold px-4 py-2 rounded-lg text-sm whitespace-nowrap hover:bg-yellow-400 transition-colors">
                    追加
                </button>
                <button id="pwa-install-close" class="text-gray-500 hover:text-white p-2">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        </div>
    </div>
    
    <script>
    // PWA インストールバナー
    (function() {
        let deferredPrompt = null;
        const banner = document.getElementById('pwa-install-banner');
        const installBtn = document.getElementById('pwa-install-btn');
        const closeBtn = document.getElementById('pwa-install-close');
        const iosHint = document.getElementById('pwa-ios-hint');
        const androidHint = document.getElementById('pwa-android-hint');
        
        // 既にインストール済み、または閉じた場合は表示しない
        if (localStorage.getItem('pwa-banner-dismissed') || window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone) {
            return;
        }
        
        // iOS判定
        const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent) && !window.MSStream;
        const isSafari = /^((?!chrome|android).)*safari/i.test(navigator.userAgent);
        
        // Android: beforeinstallpromptイベントをキャッチ
        window.addEventListener('beforeinstallprompt', (e) => {
            e.preventDefault();
            deferredPrompt = e;
            showBanner();
        });
        
        // iOS Safari: 手順案内モードで表示
        if (isIOS && isSafari) {
            iosHint.classList.remove('hidden');
            androidHint.classList.add('hidden');
            installBtn.style.display = 'none'; // iOSでは追加ボタン不要
            setTimeout(() => showBanner(), 2000);
        }
        
        function showBanner() {
            banner.classList.remove('translate-y-full');
        }
        
        function hideBanner() {
            banner.classList.add('translate-y-full');
            localStorage.setItem('pwa-banner-dismissed', '1');
        }
        
        installBtn.addEventListener('click', async () => {
            if (deferredPrompt) {
                deferredPrompt.prompt();
                const { outcome } = await deferredPrompt.userChoice;
                if (outcome === 'accepted') {
                    hideBanner();
                }
                deferredPrompt = null;
            }
        });
        
        closeBtn.addEventListener('click', hideBanner);
    })();
    </script>
</body>
</html>
