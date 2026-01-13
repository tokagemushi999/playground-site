<?php
/**
 * クリエイター個別ページ
 */

require_once 'includes/db.php';
require_once 'includes/site-settings.php';
require_once 'includes/seo-tags.php';
require_once 'includes/gallery-render.php';
require_once 'includes/formatting.php';
require_once 'includes/image-helper.php';

$db = getDB();
$creator = null;

// IDまたはスラッグでクリエイターを取得
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $stmt = $db->prepare("SELECT * FROM creators WHERE id = ? AND is_active = 1");
    $stmt->execute([$_GET['id']]);
    $creator = $stmt->fetch();
} elseif (isset($_GET['slug'])) {
    $stmt = $db->prepare("SELECT * FROM creators WHERE slug = ? AND is_active = 1");
    $stmt->execute([$_GET['slug']]);
    $creator = $stmt->fetch();
}

if (!$creator) {
    header("Location: index.php#tab-creators");
    exit;
}

// このクリエイターの作品を取得（LINEスタンプは除外）
$stmt = $db->prepare("SELECT * FROM works WHERE is_active = 1 AND creator_id = ? AND (work_type IS NULL OR work_type != 'line_stamp') ORDER BY sort_order ASC, id DESC");
$stmt->execute([$creator['id']]);
$creatorWorks = $stmt->fetchAll();

// 作品にwebm_existsフラグを追加
foreach ($creatorWorks as &$w) {
    $w['webm_exists'] = checkWebmExists($w['image']);
}
unset($w);

// N+1問題の解消: マンガ作品のページを一括取得
$mangaWorkIds = [];
foreach ($creatorWorks as $work) {
    if ($work['is_manga']) {
        $mangaWorkIds[] = $work['id'];
    }
}

// ページ情報を一括取得
$pagesByWorkId = [];
if (!empty($mangaWorkIds)) {
    $inQuery = implode(',', array_fill(0, count($mangaWorkIds), '?'));
    $pageStmt = $db->prepare("
        SELECT work_id, id, page_number, image as image_path 
        FROM work_pages 
        WHERE work_id IN ($inQuery) 
        ORDER BY page_number ASC
    ");
    $pageStmt->execute($mangaWorkIds);
    
    while ($row = $pageStmt->fetch()) {
        $pagesByWorkId[$row['work_id']][] = $row;
    }
}

// データ結合
foreach ($creatorWorks as &$work) {
    $work['pages'] = isset($pagesByWorkId[$work['id']]) ? $pagesByWorkId[$work['id']] : [];
}
unset($work);

// 商品情報を付与
$creatorWorks = attachProductInfo($creatorWorks);

$worksByCategory = [];
$omakeStickerWorks = []; // おまけシール用
$categoryLabels = [
    'illust' => 'イラスト',
    'illustration' => 'イラスト',
    'manga' => 'マンガ',
    'movie' => '動画',
    'animation' => 'アニメーション',
    'other' => 'その他'
];
foreach ($creatorWorks as $work) {
    // ステッカーは別配列に（グループに属さない単独ステッカーも含む）
    if (isset($work['is_omake_sticker']) && $work['is_omake_sticker']) {
        $omakeStickerWorks[] = $work;
    } else {
        $cat = $work['category'] ?: 'other';
        if (!isset($worksByCategory[$cat])) {
            $worksByCategory[$cat] = [];
        }
        $worksByCategory[$cat][] = $work;
    }
}

// このクリエイターのステッカーグループを取得
$collections = getAllCollectionsWithImages($creator['id']);

// ステッカー総数を計算
$totalStickerCount = 0;
foreach ($collections as $group) {
    $totalStickerCount += $group['sticker_count'] ?? (is_array($group['stickers'] ?? null) ? count($group['stickers']) : 0);
}

if (!empty($collections) && !isset($worksByCategory['illust']) && !isset($worksByCategory['illustration'])) {
    $worksByCategory['illustration'] = [];
}

$stmt = $db->prepare("SELECT * FROM articles WHERE is_active = 1 AND author = ? ORDER BY published_at DESC LIMIT 6");
$stmt->execute([$creator['name']]);
$creatorArticles = $stmt->fetchAll();

// このクリエイターのサービスを取得
$creatorServices = [];
try {
    $creatorServices = getCreatorServices($creator['id'], false, 'creator_page');
} catch (Exception $e) {
    $creatorServices = [];
}

// このクリエイターの商品（グッズ）を取得
$creatorProducts = [];
try {
    // show_in_* カラムが存在するか確認
    $hasProductDisplaySettings = false;
    try {
        $productColumns = $db->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
        $hasProductDisplaySettings = in_array('show_in_creator_page', $productColumns);
    } catch (PDOException $e) {
        $hasProductDisplaySettings = false;
    }
    
    $productSql = "SELECT * FROM products WHERE creator_id = ? AND is_published = 1";
    if ($hasProductDisplaySettings) {
        $productSql .= " AND (show_in_creator_page = 1 OR show_in_creator_page IS NULL)";
    }
    $productSql .= " ORDER BY id DESC LIMIT 8";
    
    $stmt = $db->prepare($productSql);
    $stmt->execute([$creator['id']]);
    $creatorProducts = $stmt->fetchAll();
} catch (Exception $e) {
    $creatorProducts = [];
}

// 他のクリエイター（同じページに表示用、最大4件）
$stmt = $db->prepare("SELECT * FROM creators WHERE is_active = 1 AND id != ? ORDER BY sort_order ASC LIMIT 4");
$stmt->execute([$creator['id']]);
$otherCreators = $stmt->fetchAll();

// 記事タイプの色とラベル
$typeColors = [
    'blog' => 'bg-blue-500',
    'diary' => 'bg-pink-500',
    'interview' => 'bg-purple-500',
    'news' => 'bg-green-500',
    'feature' => 'bg-orange-500'
];
$typeLabels = [
    'blog' => 'ブログ',
    'diary' => '日記',
    'interview' => 'インタビュー',
    'news' => 'ニュース',
    'feature' => '特集'
];

// OGP用のURL生成
$baseUrl = getBaseUrl();
$creatorUrl = !empty($creator['slug']) 
    ? $baseUrl . '/creator/' . $creator['slug']
    : $baseUrl . '/creator.php?id=' . $creator['id'];
// OGP画像（WebPはLINE/Twitter等で認識されないため、JPG/PNG版を優先）
$ogImage = getOgImageUrl($creator['image'] ?? '', $baseUrl);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="theme-color" content="#FFD600">
    <link rel="manifest" href="/manifest.json">
    <title><?= htmlspecialchars($creator['name']) ?> | ぷれぐら！PLAYGROUND</title>
    <meta name="description" content="<?= htmlspecialchars($creator['bio'] ?? '') ?>">
    
    <!-- OGP (Open Graph Protocol) -->
    <meta property="og:type" content="profile">
    <meta property="og:title" content="<?= htmlspecialchars($creator['name']) ?> | ぷれぐら！PLAYGROUND">
    <meta property="og:description" content="<?= htmlspecialchars($creator['bio'] ?? '') ?>">
    <meta property="og:url" content="<?= htmlspecialchars($creatorUrl) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">
    <meta property="og:site_name" content="ぷれぐら！PLAYGROUND">
    <meta property="og:locale" content="ja_JP">
    
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($creator['name']) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($creator['bio'] ?? '') ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($ogImage) ?>">
    
    <?php outputSeoTags($db); ?>
    
    <!-- Canonical URL -->
    <link rel="canonical" href="<?= htmlspecialchars($creatorUrl) ?>">
    
    <!-- Preconnect -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdn.tailwindcss.com">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    
    <?php $faviconInfo = getFaviconInfo($db); ?>
    <link rel="icon" href="<?= htmlspecialchars($faviconInfo['path']) ?>" type="<?= htmlspecialchars($faviconInfo['type']) ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($faviconInfo['path']) ?>">
    
    <!-- Tailwind CSS（同期読み込み - レイアウト崩れ防止） -->
    <script src="https://cdn.tailwindcss.com"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Zen Maru Gothic', 'sans-serif'],
                        display: ['Dela Gothic One', 'cursive']
                    },
                    colors: {
                        'pop-yellow': '#FFD600',
                        'pop-pink': '#FF6B6B',
                        'pop-blue': '#4ECDC4',
                        'pop-purple': '#9D50BB',
                        'pop-black': '#1a1a1a'
                    }
                }
            }
        }
    </script>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@400;700&family=Dela+Gothic+One&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@400;700&family=Dela+Gothic+One&display=swap" rel="stylesheet"></noscript>
    
    <style>
        body { 
            font-family: 'Zen Maru Gothic', sans-serif; 
            background-color: #FDFBF7;
            background-image: radial-gradient(#E5E7EB 2px, transparent 2px);
            background-size: 30px 30px;
        }
        .font-display { font-family: 'Dela Gothic One', cursive; }
        
        /* ============================================
           コレクション表示スタイル
           ============================================ */
        
        /* 共通スタイル */
        .collection-card {
            position: relative;
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
        
        /* 漫画ビューアー用スタイル */
        .manga-viewer {
            background: #000;
            touch-action: pan-y pinch-zoom;
        }
        .manga-page {
            max-height: 80vh;
            max-width: 100%;
            object-fit: contain;
        }
        .manga-spread {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 2px;
        }
        .manga-spread img {
            max-height: 80vh;
            max-width: 49%;
            object-fit: contain;
        }
    </style>
</head>
<body class="antialiased">
    <!-- Header（article.phpと同じスタイル） -->
    <header class="bg-white border-b border-gray-200 sticky top-0 z-50">
        <div class="max-w-4xl mx-auto px-6 py-4 flex justify-between items-center">
            <a href="/" class="font-display text-xl text-gray-800">
                ぷれぐら！<span class="text-pop-yellow">PLAYGROUND</span>
            </a>
            <!-- ルートに戻り、JSでタブ切り替え -->
            <a href="/#tab-creators" onclick="sessionStorage.setItem('activeTab', 'creators');" class="text-gray-600 hover:text-gray-800 font-bold text-sm">
                <i class="fas fa-arrow-left mr-2"></i>戻る
            </a>
        </div>
    </header>
    
    <!-- Hero Section with Creator Image -->
    <div class="w-full bg-gradient-to-b from-pop-yellow/20 to-transparent py-16 relative overflow-hidden">
        <div class="absolute top-10 left-10 w-64 h-64 bg-pop-blue/10 rounded-full blur-3xl"></div>
        <div class="absolute bottom-10 right-10 w-80 h-80 bg-pop-pink/10 rounded-full blur-3xl"></div>
        
        <div class="max-w-4xl mx-auto px-6 relative z-10">
            <div class="flex flex-col md:flex-row items-center gap-8">
                <!-- Profile Image -->
                <div class="shrink-0">
                    <?php 
                    $creatorImgSrc = normalizeImagePath($creator['image'] ?? '');
                    ?>
                    <?php if (!empty($creatorImgSrc)): ?>
                    <img src="<?= htmlspecialchars($creatorImgSrc) ?>" 
                        class="w-40 h-40 md:w-48 md:h-48 object-cover rounded-full border-4 border-white shadow-xl">
                    <?php else: ?>
                    <div class="w-40 h-40 md:w-48 md:h-48 bg-pop-yellow rounded-full border-4 border-white shadow-xl flex items-center justify-center">
                        <span class="font-display text-5xl text-white"><?= mb_substr($creator['name'], 0, 1) ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Creator Info -->
                <div class="text-center md:text-left">
                    <?php if (!empty($creator['role'])): ?>
                    <span class="inline-block bg-pop-blue text-white text-sm font-bold px-4 py-1 rounded-full mb-4">
                        <?= htmlspecialchars($creator['role']) ?>
                    </span>
                    <?php endif; ?>
                    
                    <h1 class="font-display text-4xl md:text-5xl text-gray-800 mb-4">
                        <?= htmlspecialchars($creator['name']) ?>
                    </h1>
                    
                    <?php if (!empty($creator['bio'])): ?>
                    <p class="text-gray-600 text-lg leading-relaxed max-w-xl">
                        <?= nl2br(htmlspecialchars($creator['bio'])) ?>
                    </p>
                    <?php endif; ?>
                    
                    <!-- Social Links -->
                    <div class="flex flex-wrap gap-3 mt-6 justify-center md:justify-start">
                        <?php if (!empty($creator['twitter'])): 
                            $twitterUrl = $creator['twitter'];
                            // @usernameまたはusernameの場合はURLに変換
                            if (!preg_match('/^https?:\/\//', $twitterUrl)) {
                                $twitterUrl = 'https://x.com/' . ltrim($twitterUrl, '@');
                            }
                        ?>
                        <a href="<?= htmlspecialchars($twitterUrl) ?>" target="_blank" 
                            class="w-10 h-10 border-2 border-gray-800 text-gray-800 rounded-full flex items-center justify-center hover:bg-gray-800 hover:text-white transition" title="X (Twitter)">
                            <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" viewBox="0 0 24 24" fill="currentColor">
                                <path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/>
                            </svg>
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($creator['instagram'])): ?>
                        <a href="<?= htmlspecialchars($creator['instagram']) ?>" target="_blank"
                            class="w-10 h-10 border-2 border-pink-500 text-pink-500 rounded-full flex items-center justify-center hover:bg-gradient-to-br hover:from-purple-500 hover:to-pink-500 hover:text-white hover:border-transparent transition" title="Instagram">
                            <i class="fab fa-instagram"></i>
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($creator['pixiv'])): ?>
                        <a href="https://www.pixiv.net/users/<?= htmlspecialchars($creator['pixiv']) ?>" target="_blank"
                            class="w-10 h-10 border-2 border-blue-500 text-blue-500 rounded-full flex items-center justify-center hover:bg-blue-500 hover:text-white transition" title="Pixiv">
                            <i class="fas fa-paintbrush"></i>
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($creator['youtube'])): ?>
                        <a href="<?= htmlspecialchars($creator['youtube']) ?>" target="_blank"
                            class="w-10 h-10 border-2 border-red-500 text-red-500 rounded-full flex items-center justify-center hover:bg-red-500 hover:text-white transition" title="YouTube">
                            <i class="fab fa-youtube"></i>
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($creator['tiktok'])): ?>
                        <a href="https://www.tiktok.com/<?= htmlspecialchars($creator['tiktok']) ?>" target="_blank"
                            class="w-10 h-10 border-2 border-gray-800 text-gray-800 rounded-full flex items-center justify-center hover:bg-gray-800 hover:text-white transition" title="TikTok">
                            <i class="fab fa-tiktok"></i>
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($creator['booth'])): ?>
                        <a href="<?= htmlspecialchars($creator['booth']) ?>" target="_blank"
                            class="w-10 h-10 border-2 border-red-400 text-red-400 rounded-full flex items-center justify-center hover:bg-red-400 hover:text-white transition" title="BOOTH">
                            <i class="fas fa-store"></i>
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($creator['skeb'])): ?>
                        <a href="https://skeb.jp/@<?= htmlspecialchars($creator['skeb']) ?>" target="_blank"
                            class="w-10 h-10 border-2 border-cyan-500 text-cyan-500 rounded-full flex items-center justify-center hover:bg-cyan-500 hover:text-white transition" title="Skeb">
                            <i class="fas fa-palette"></i>
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($creator['website'])): ?>
                        <a href="<?= htmlspecialchars($creator['website']) ?>" target="_blank"
                            class="w-10 h-10 border-2 border-pop-blue text-pop-blue rounded-full flex items-center justify-center hover:bg-pop-blue hover:text-white transition" title="Webサイト">
                            <i class="fas fa-globe"></i>
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($creator['discord'])): ?>
                        <a href="<?= htmlspecialchars($creator['discord']) ?>" target="_blank"
                            class="w-10 h-10 border-2 rounded-full flex items-center justify-center hover:text-white transition" style="border-color:#5865F2; color:#5865F2;" onmouseover="this.style.backgroundColor='#5865F2'" onmouseout="this.style.backgroundColor='transparent'" title="Discord">
                            <i class="fab fa-discord"></i>
                        </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <!-- Main Content -->
    <main class="max-w-7xl mx-auto px-4 md:px-6 py-12">
        <!-- Works Section - カテゴリごとに分けて表示 -->
        <?php if (!empty($creatorWorks) || !empty($collections)): ?>
        <section class="mb-16">
            <h2 class="text-base font-bold text-gray-500 tracking-widest mb-8 flex items-center gap-3">
                <span class="w-6 h-0.5 bg-pop-yellow rounded"></span>
                WORKS
            </h2>
            
            <?= renderGallery($creator['id'], false, true, $db) ?>
        </section>
        <?php endif; ?>
        
        
        <!-- Articles Section 追加 -->
        <?php if (!empty($creatorArticles)): ?>
        <section class="mb-16 max-w-4xl mx-auto">
            <h2 class="text-base font-bold text-gray-500 tracking-widest mb-8 flex items-center gap-3">
                <span class="w-6 h-0.5 bg-pop-pink rounded"></span>
                ARTICLES
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <?php foreach ($creatorArticles as $article): ?>
                <?php 
                $type = $article['article_type'] ?? 'blog';
                $colorClass = $typeColors[$type] ?? 'bg-pop-purple';
                $label = $typeLabels[$type] ?? $article['category'] ?? 'FEATURE';
                $articleUrl = !empty($article['slug']) ? '/article/' . $article['slug'] : 'article.php?id=' . $article['id'];
                $articleImgSrc = normalizeImagePath($article['image'] ?? '');
                ?>
                <a href="<?= htmlspecialchars($articleUrl) ?>" 
                    class="bg-white border border-gray-200 rounded-2xl overflow-hidden hover:-translate-y-1 transition-transform shadow-sm hover:shadow-md flex">
                    <div class="w-32 h-32 shrink-0 relative">
                        <?php if (!empty($articleImgSrc)): ?>
                        <img src="<?= htmlspecialchars($articleImgSrc) ?>" class="w-full h-full object-cover">
                        <?php else: ?>
                        <div class="w-full h-full bg-gray-100 flex items-center justify-center">
                            <i class="fas fa-newspaper text-gray-300 text-2xl"></i>
                        </div>
                        <?php endif; ?>
                        <div class="absolute top-2 left-2 <?= $colorClass ?> text-white text-xs font-bold px-2 py-1 rounded">
                            <?= htmlspecialchars($label) ?>
                        </div>
                    </div>
                    <div class="p-4 flex flex-col justify-center">
                        <h3 class="font-bold text-gray-800 text-sm line-clamp-2 mb-2"><?= htmlspecialchars($article['title']) ?></h3>
                        <p class="text-xs text-gray-400">
                            <?= date('Y.m.d', strtotime($article['published_at'])) ?>
                        </p>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
        
        <!-- Services Section -->
        <?php if (!empty($creatorServices)): ?>
        <section class="mb-16 max-w-4xl mx-auto">
            <h2 class="text-base font-bold text-gray-500 tracking-widest mb-8 flex items-center gap-3">
                <span class="w-6 h-0.5 bg-pop-purple rounded"></span>
                SERVICES
            </h2>
            
            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <?php foreach ($creatorServices as $service): ?>
                <a href="/store/services/detail.php?id=<?= $service['id'] ?>" 
                   class="bg-white border border-gray-200 rounded-2xl overflow-hidden hover:-translate-y-1 transition-transform shadow-sm hover:shadow-md group">
                    <div class="aspect-video bg-gray-100 relative overflow-hidden flex items-center justify-center">
                        <?php if (!empty($service['thumbnail_image'])): ?>
                        <img src="/<?= htmlspecialchars($service['thumbnail_image']) ?>" alt="" class="w-full h-full object-cover">
                        <?php else: ?>
                        <i class="fas fa-paint-brush text-gray-300 text-4xl"></i>
                        <?php endif; ?>
                        <?php if (!empty($service['category_name'])): ?>
                        <span class="absolute top-3 left-3 px-2 py-1 bg-white/90 backdrop-blur rounded-full text-xs font-bold text-gray-700">
                            <?= htmlspecialchars($service['category_name']) ?>
                        </span>
                        <?php endif; ?>
                    </div>
                    <div class="p-4">
                        <h3 class="font-bold text-gray-800 text-lg mb-2 group-hover:text-pop-purple transition line-clamp-2">
                            <?= htmlspecialchars($service['title']) ?>
                        </h3>
                        <?php if (!empty($service['description'])): ?>
                        <p class="text-sm text-gray-500 mb-3 line-clamp-2"><?= htmlspecialchars($service['description']) ?></p>
                        <?php endif; ?>
                        <div class="flex items-center justify-between">
                            <span class="text-pop-purple font-bold text-lg">
                                <?= formatPrice($service['min_price'] ?? $service['base_price']) ?>〜
                            </span>
                            <div class="flex items-center gap-3 text-sm text-gray-500">
                                <?php if (!empty($service['avg_rating'])): ?>
                                <span class="text-yellow-400">
                                    <i class="fas fa-star"></i>
                                    <?= formatNumber($service['avg_rating'] ?? 0, '0.0', 1) ?>
                                </span>
                                <?php endif; ?>
                                <span>
                                    <i class="fas fa-clock mr-1"></i><?= $service['delivery_days'] ?>日
                                </span>
                            </div>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            
            <div class="text-center mt-6">
                <a href="/store/services/?creator=<?= $creator['id'] ?>" 
                   class="inline-flex items-center gap-2 text-pop-purple hover:text-purple-700 font-bold">
                    すべてのサービスを見る
                    <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        </section>
        <?php endif; ?>
        
        <!-- Products Section (グッズ) -->
        <?php if (!empty($creatorProducts)): ?>
        <section class="mb-16 max-w-4xl mx-auto">
            <h2 class="text-base font-bold text-gray-500 tracking-widest mb-8 flex items-center gap-3">
                <span class="w-6 h-0.5 bg-pop-yellow rounded"></span>
                GOODS
            </h2>
            
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                <?php foreach ($creatorProducts as $product): ?>
                <?php 
                $productUrl = '/store/product.php?id=' . $product['id'];
                $productImgSrc = normalizeImagePath($product['image'] ?? '');
                ?>
                <a href="<?= htmlspecialchars($productUrl) ?>" 
                   class="bg-white border border-gray-200 rounded-xl overflow-hidden hover:-translate-y-1 transition-transform shadow-sm hover:shadow-md group">
                    <div class="aspect-square bg-gray-100 relative overflow-hidden">
                        <?php if (!empty($productImgSrc)): ?>
                        <img src="<?= htmlspecialchars($productImgSrc) ?>" 
                             class="w-full h-full object-cover group-hover:scale-105 transition duration-300"
                             alt="<?= htmlspecialchars($product['name']) ?>">
                        <?php else: ?>
                        <div class="w-full h-full flex items-center justify-center">
                            <i class="fas fa-box text-gray-300 text-3xl"></i>
                        </div>
                        <?php endif; ?>
                        <?php if (!empty($product['compare_price']) && $product['compare_price'] > $product['price']): ?>
                        <span class="absolute top-2 right-2 bg-red-500 text-white text-xs px-2 py-1 rounded font-bold">SALE</span>
                        <?php endif; ?>
                    </div>
                    <div class="p-3">
                        <h3 class="font-bold text-gray-800 text-sm mb-1 line-clamp-2 group-hover:text-pop-yellow transition">
                            <?= htmlspecialchars($product['name']) ?>
                        </h3>
                        <div class="flex items-center gap-2">
                            <span class="text-pop-yellow font-bold"><?= formatPrice($product['price'] ?? 0) ?></span>
                            <?php if (!empty($product['compare_price']) && $product['compare_price'] > $product['price']): ?>
                            <span class="text-gray-400 text-xs line-through"><?= formatPrice($product['compare_price'] ?? 0) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
            
            <div class="text-center mt-6">
                <a href="/store/?creator=<?= $creator['id'] ?>" 
                   class="inline-flex items-center gap-2 text-pop-yellow hover:text-yellow-600 font-bold">
                    すべてのグッズを見る
                    <i class="fas fa-arrow-right"></i>
                </a>
            </div>
        </section>
        <?php endif; ?>
        
        <!-- Request Button -->
        <div class="text-center mb-16 py-8 bg-white rounded-3xl border border-gray-200 shadow-sm max-w-4xl mx-auto">
            <p class="text-gray-500 mb-4 font-bold">このクリエイターに制作を依頼しませんか？</p>
            <a href="/?creator=<?= urlencode($creator['name']) ?>#tab-request" 
                class="inline-flex items-center gap-2 bg-pop-pink hover:bg-pink-500 text-white font-bold px-8 py-4 rounded-full transition shadow-lg hover:shadow-xl">
                <i class="fas fa-paper-plane"></i>
                <?= htmlspecialchars($creator['name']) ?>さんに問い合わせる
            </a>
        </div>
        
        <!-- Other Creators -->
        <?php if (!empty($otherCreators)): ?>
        <section class="max-w-4xl mx-auto">
            <h2 class="text-base font-bold text-gray-500 tracking-widest mb-8 flex items-center gap-3">
                <span class="w-6 h-0.5 bg-pop-blue rounded"></span>
                OTHER MEMBERS
            </h2>
            
            <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                <?php foreach ($otherCreators as $other): ?>
                <?php 
                $otherUrl = !empty($other['slug']) ? '/creator/' . $other['slug'] : 'creator.php?id=' . $other['id'];
                $otherImgSrc = normalizeImagePath($other['image'] ?? '');
                ?>
                <a href="<?= htmlspecialchars($otherUrl) ?>" 
                    class="bg-white border border-gray-200 rounded-2xl p-4 text-center hover:-translate-y-1 transition-transform shadow-sm hover:shadow-md">
                    <?php if (!empty($otherImgSrc)): ?>
                    <img src="<?= htmlspecialchars($otherImgSrc) ?>" 
                        class="w-20 h-20 object-cover rounded-full mx-auto mb-3 border-2 border-gray-100">
                    <?php else: ?>
                    <div class="w-20 h-20 bg-pop-yellow rounded-full mx-auto mb-3 flex items-center justify-center">
                        <span class="font-display text-2xl text-white"><?= mb_substr($other['name'], 0, 1) ?></span>
                    </div>
                    <?php endif; ?>
                    <p class="font-bold text-gray-800 text-sm truncate"><?= htmlspecialchars($other['name']) ?></p>
                    <?php if (!empty($other['role'])): ?>
                    <p class="text-xs text-gray-400 mt-1"><?= htmlspecialchars($other['role']) ?></p>
                    <?php endif; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </section>
        <?php endif; ?>
        
        <!-- Back to MEMBER -->
        <div class="mt-16 text-center max-w-4xl mx-auto">
            <a href="/index.php#tab-creators" onclick="sessionStorage.setItem('activeTab', 'creators');"
                class="inline-flex items-center gap-2 bg-pop-yellow hover:bg-yellow-500 text-gray-900 font-bold px-8 py-4 rounded-full transition shadow-lg hover:shadow-xl hover:-translate-y-1">
                <i class="fas fa-arrow-left"></i>
                MEMBERに戻る
            </a>
        </div>
    </main>
    
    <!-- Work Modal -->
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
    

    <?php include 'includes/modals.php'; ?>

    
    <!-- Footer -->
    <footer class="bg-gray-900 text-white py-12 mt-16">
        <div class="max-w-7xl mx-auto px-6 text-center">
            <p class="font-display text-xl mb-4">ぷれぐら！<span class="text-pop-yellow">PLAYGROUND</span></p>
            <p class="text-gray-400 text-sm">&copy; 2025 ぷれぐら！PLAYGROUND. All rights reserved.</p>
        </div>
    </footer>
    
    <script>
        // Works data for modal
        const works = <?= json_encode($creatorWorks, JSON_UNESCAPED_UNICODE) ?>;
        const creator = <?= json_encode($creator, JSON_UNESCAPED_UNICODE) ?>;
        
        // 画像パスを正規化する関数
        function getImagePath(img) {
            if (!img) return '/placeholder.svg';
            if (img.startsWith('http')) return img;
            return img.startsWith('/') ? img : '/' + img;
        }
        
        // 画像/動画パスから適切なメディア要素を返す
        function getMediaElement(img, className = '', style = '', alt = '', webmExists = false) {
            if (!img) return `<img src="/placeholder.svg" class="${className}" style="${style}" alt="${alt}">`;
            
            const path = getImagePath(img);
            const isWebm = path.toLowerCase().endsWith('.webm');
            const isGif = path.toLowerCase().endsWith('.gif');
            
            if (isWebm || (isGif && webmExists)) {
                const webmPath = isWebm ? path : path.replace(/\.gif$/i, '.webm');
                const classAttr = className ? ` class="${className}"` : '';
                const styleAttr = style ? ` style="${style}"` : '';
                return `<video autoplay loop muted playsinline${classAttr}${styleAttr} onloadedmetadata="this.play()" onended="this.currentTime=0;this.play()"><source src="${webmPath}" type="video/webm"><img src="${path}" alt="${alt}"></video>`;
            }
            
            return `<img src="${path}" class="${className}" style="${style}" alt="${alt}" loading="lazy">`;
        }
        
        function extractYoutubeId(url) {
            if (!url) return null;
            const match = url.match(/(?:youtube\.com\/(?:[^\/]+\/.+\/|(?:v|e(?:mbed)?)\/|.*[?&]v=)|youtu\.be\/)([^"&?\/\s]{11})/);
            return match ? match[1] : null;
        }
        
        let currentWorkId = null;
        
        function openWorkModal(id) {
            const work = works.find(w => w.id === id);
            if (!work) return;
            
            currentWorkId = id;
            
            const mediaContainer = document.getElementById('w-media');
            const readMangaBtn = document.getElementById('w-read-manga');
            const purchaseArea = document.getElementById('w-purchase');
            const youtubeId = extractYoutubeId(work.youtube_url);
            const hasCustomImage = work.image && !work.image.includes('youtube.com') && !work.image.includes('img.youtube.com');
            const useImage = (work.thumbnail_type === 'image' && hasCustomImage) || !youtubeId;
            
            // 漫画ボタンの表示/非表示（index.phpと同じ挙動）
            // マンガ読むボタン
            if (work.pages && work.pages.length > 0) {
                readMangaBtn.classList.remove('hidden');
                const pageCount = work.pages.length;
                
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
                readMangaBtn.querySelector('button').onclick = function() { 
                    window.location.href = '/manga/' + work.id; 
                };
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
            
            // YouTube動画の場合
            if (youtubeId && !useImage) {
                mediaContainer.innerHTML = `
                    <div class="relative w-full" style="padding-bottom: 56.25%;">
                        <iframe src="https://www.youtube.com/embed/${youtubeId}?autoplay=1" 
                            class="absolute inset-0 w-full h-full" frameborder="0" 
                            allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture" 
                            allowfullscreen></iframe>
                    </div>
                `;
            } else {
                // WebM対応
                const mediaHtml = getMediaElement(work.image, 'w-full h-auto max-h-[50vh] object-contain bg-gray-100', '', '', work.webm_exists);
                mediaContainer.innerHTML = mediaHtml + (youtubeId ? `<a href="https://www.youtube.com/watch?v=${youtubeId}" target="_blank" class="absolute bottom-4 right-4 bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-full font-bold text-sm transition-colors"><i class="fab fa-youtube mr-2"></i>動画を見る</a>` : '');
            }
            
            document.getElementById('w-title').innerText = work.title || '';
            document.getElementById('w-desc').innerText = work.description || '';
            
            // クリエイター情報を設定
            document.getElementById('w-creator-img').src = getImagePath(creator.image);
            document.getElementById('w-creator-name').innerText = creator.name;
            document.getElementById('w-creator-link').href = creator.slug ? `/creator/${creator.slug}` : `/creator.php?id=${creator.id}`;
            
            // シェアURL設定
            const shareUrl = encodeURIComponent(window.location.origin + '/index.php?work=' + id);
            const shareText = encodeURIComponent((work.title || '') + ' | ぷれぐら！PLAYGROUND');
            document.getElementById('w-share-x').href = `https://twitter.com/intent/tweet?url=${shareUrl}&text=${shareText}`;
            
            const modal = document.getElementById('work-modal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.style.overflow = 'hidden';
        }
        
        function closeWorkModal() {
            currentWorkId = null;
            const modal = document.getElementById('work-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.body.style.overflow = '';
            
            // YouTube動画停止のためにmediaContainerをリセット
            document.getElementById('w-media').innerHTML = '<img id="w-img" src="/placeholder.svg" class="w-full h-auto max-h-[50vh] object-contain bg-gray-100">';
        }
        
        // Web Share API
        async function shareWork() {
            if (!currentWorkId) return;
            const work = works.find(w => w.id === currentWorkId);
            if (!work) return;
            
            const shareData = {
                title: work.title,
                text: (work.title || '') + ' | ぷれぐら！PLAYGROUND',
                url: window.location.origin + '/index.php?work=' + currentWorkId
            };
            
            if (navigator.share) {
                try {
                    await navigator.share(shareData);
                } catch (err) {
                    console.log('Share cancelled or failed:', err);
                }
            } else {
                copyWorkLink();
            }
        }
        
        // リンクをコピー
        function copyWorkLink() {
            if (!currentWorkId) return;
            const url = window.location.origin + '/index.php?work=' + currentWorkId;
            
            navigator.clipboard.writeText(url).then(() => {
                const toast = document.getElementById('w-copy-toast');
                toast.classList.remove('hidden');
                setTimeout(() => toast.classList.add('hidden'), 2000);
            }).catch(err => {
                const textarea = document.createElement('textarea');
                textarea.value = url;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                const toast = document.getElementById('w-copy-toast');
                toast.classList.remove('hidden');
                setTimeout(() => toast.classList.add('hidden'), 2000);
            });
        }
        
        // ステッカーグループデータ
        const collections = <?= json_encode($collections, JSON_UNESCAPED_UNICODE) ?>;
        
        // モーダル共通設定
        window.ModalConfig = {
            works: works,
            collections: collections,
            creatorName: '<?= htmlspecialchars($creator['name'], ENT_QUOTES) ?>',
            baseImagePath: '/'
        };

        // URLハッシュ（#work-123）から作品を直接開く
        // article.php の「作品を見る」リンクが /creator/slug#work-123 になっているため
        window.addEventListener('DOMContentLoaded', () => {
            const hash = window.location.hash || '';
            const match = hash.match(/^#work-(\d+)$/);
            if (!match) return;
            const id = Number(match[1]);
            if (!id) return;
            // 画面描画後にモーダルを開く
            setTimeout(() => openWorkModal(id), 100);
        });
    </script>
</body>
</html>
