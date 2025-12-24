<?php
/**
 * クリエイター個別ページ
 */

require_once 'includes/db.php';

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

// 画像パスを正規化するヘルパー関数
function normalizeImagePath($path) {
    if (empty($path)) return '';
    if (strpos($path, 'http') === 0) return $path;
    if (strpos($path, '/') === 0) return $path;
    return '/' . $path;
}

// このクリエイターの作品を取得
$stmt = $db->prepare("SELECT * FROM works WHERE is_active = 1 AND creator_id = ? ORDER BY sort_order ASC, id DESC");
$stmt->execute([$creator['id']]);
$creatorWorks = $stmt->fetchAll();

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
$stickerGroups = getAllStickerGroupsWithImages($creator['id']);

// ステッカー総数を計算
$totalStickerCount = 0;
foreach ($stickerGroups as $group) {
    $totalStickerCount += $group['sticker_count'] ?? (is_array($group['stickers'] ?? null) ? count($group['stickers']) : 0);
}

if (!empty($stickerGroups) && !isset($worksByCategory['illust']) && !isset($worksByCategory['illustration'])) {
    $worksByCategory['illustration'] = [];
}

$stmt = $db->prepare("SELECT * FROM articles WHERE is_active = 1 AND author = ? ORDER BY published_at DESC LIMIT 6");
$stmt->execute([$creator['name']]);
$creatorArticles = $stmt->fetchAll();

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
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <link rel="manifest" href="/manifest.json">
<title><?= htmlspecialchars($creator['name']) ?> | ぷれぐら！PLAYGROUND</title>
    <meta name="description" content="<?= htmlspecialchars($creator['bio'] ?? '') ?>">
    
    <!-- Favicon設定を追加 -->
    <link rel="icon" type="image/png" href="/favicon.png">
    <link rel="apple-touch-icon" href="/favicon.png">
    
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@400;500;700&family=Dela+Gothic+One&display=swap" rel="stylesheet">
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    fontFamily: {
                        sans: ['Zen Maru Gothic', 'sans-serif'],
                        display: ['Dela Gothic One', 'cursive'],
                    },
                    colors: {
                        'pop-yellow': '#FFD600',
                        'pop-pink': '#FF6B6B',
                        'pop-blue': '#4ECDC4',
                        'pop-purple': '#9D50BB',
                        'pop-black': '#1a1a1a',
                    },
                }
            }
        }
    </script>
    <style>
        body { 
            font-family: 'Zen Maru Gothic', sans-serif; 
            background-color: #FDFBF7;
            background-image: radial-gradient(#E5E7EB 2px, transparent 2px);
            background-size: 30px 30px;
        }
        .font-display { font-family: 'Dela Gothic One', cursive; }
        
        /* ステッカースタック - 重なり＋ホバーで広がる */
        .sticker-stack {
            perspective: 1000px;
        }
        .sticker-layer {
            width: 65%;
            height: 65%;
            transition: all 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
        }
        /* 通常時：重なって配置（後ろのレイヤーほどずれる） */
        .sticker-layer[data-layer="4"] {
            transform: translate(-20%, -15%) rotate(-8deg);
        }
        .sticker-layer[data-layer="3"] {
            transform: translate(-10%, -8%) rotate(-4deg);
        }
        .sticker-layer[data-layer="2"] {
            transform: translate(10%, 5%) rotate(5deg);
        }
        .sticker-layer[data-layer="1"] {
            transform: translate(0, 0) rotate(0deg);
        }
        /* ホバー時：扇状に広がる */
        .group:hover .sticker-layer[data-layer="4"] {
            transform: translate(-55%, -10%) rotate(-15deg) scale(0.85);
        }
        .group:hover .sticker-layer[data-layer="3"] {
            transform: translate(-30%, -5%) rotate(-8deg) scale(0.9);
        }
        .group:hover .sticker-layer[data-layer="2"] {
            transform: translate(35%, 0%) rotate(10deg) scale(0.9);
        }
        .group:hover .sticker-layer[data-layer="1"] {
            transform: translate(5%, 5%) rotate(2deg) scale(1.05);
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
    <main class="max-w-4xl mx-auto px-6 py-12">
        <!-- Works Section - カテゴリごとに分けて表示 -->
        <?php if (!empty($creatorWorks)): ?>
        <section class="mb-16">
            <h2 class="font-display text-2xl text-gray-800 mb-8 flex items-center gap-3">
                <span class="w-8 h-1 bg-pop-yellow rounded"></span>
                WORKS
            </h2>
            
            <?php foreach ($worksByCategory as $cat => $works): ?>
            <?php 
            // イラストカテゴリの場合はステッカー数を加算
            $categoryCount = count($works);
            if (($cat === 'illust' || $cat === 'illustration') && $totalStickerCount > 0) {
                $categoryCount += $totalStickerCount;
            }
            ?>
            <div class="mb-10">
                <div class="flex items-center justify-between mb-4">
                    <h3 class="text-lg font-bold text-gray-700 flex items-center gap-2">
                        <?php if ($cat === 'illust' || $cat === 'illustration'): ?>
                        <i class="fas fa-paint-brush text-pop-pink"></i>
                        <?php elseif ($cat === 'manga'): ?>
                        <i class="fas fa-book-open text-pop-purple"></i>
                        <?php elseif ($cat === 'movie' || $cat === 'animation'): ?>
                        <i class="fas fa-video text-red-500"></i>
                        <?php else: ?>
                        <i class="fas fa-folder text-pop-blue"></i>
                        <?php endif; ?>
                        <?= htmlspecialchars($categoryLabels[$cat] ?? $cat) ?>
                        <span class="text-sm text-gray-400 font-normal">(<?= $categoryCount ?>)</span>
                    </h3>
                </div>
                
                <?php if ($cat === 'manga'): ?>
                <div class="grid grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-4">
                    <?php foreach ($works as $work): ?>
                    <?php $mangaImgSrc = normalizeImagePath($work['image'] ?? ''); ?>
                    <div class="group cursor-pointer" onclick="openWorkModal(<?= $work['id'] ?>)">
                        <div class="aspect-[3/4] rounded-xl overflow-hidden bg-gray-100 shadow-md group-hover:shadow-lg transition-shadow relative">
                            <?php if (!empty($mangaImgSrc)): ?>
                            <img src="<?= htmlspecialchars($mangaImgSrc) ?>" 
                                class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                            <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center">
                                <i class="fas fa-book-open text-gray-300 text-3xl"></i>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($work['is_manga']) && !empty($work['pages'])): ?>
                            <span class="absolute top-2 left-2 bg-pop-purple text-white text-xs font-bold px-2 py-1 rounded">
                                <?= count($work['pages']) ?>P
                            </span>
                            <?php endif; ?>
                        </div>
                        <p class="mt-2 font-bold text-gray-700 text-xs truncate"><?= htmlspecialchars($work['title']) ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php elseif ($cat === 'movie' || $cat === 'animation'): ?>
                <div class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                    <?php foreach ($works as $work): ?>
                    <?php 
                    $cropPos = $work['crop_position'] ?? '50% 50%';
                    $videoImgSrc = normalizeImagePath($work['image'] ?? '');
                    ?>
                    <div class="group cursor-pointer" onclick="openWorkModal(<?= $work['id'] ?>)">
                        <div class="aspect-video rounded-2xl overflow-hidden bg-gray-900 shadow-md group-hover:shadow-lg transition-shadow relative">
                            <?php if (!empty($videoImgSrc)): ?>
                            <img src="<?= htmlspecialchars($videoImgSrc) ?>" 
                                class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300"
                                style="object-position: <?= htmlspecialchars($cropPos) ?>">
                            <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center">
                                <i class="fas fa-video text-gray-500 text-4xl"></i>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($work['youtube_url'])): ?>
                            <span class="absolute top-3 left-3 bg-red-600 text-white text-xs font-bold px-2 py-1 rounded-lg flex items-center gap-1">
                                <i class="fab fa-youtube"></i> YouTube
                            </span>
                            <?php endif; ?>
                        </div>
                        <p class="mt-3 font-bold text-gray-700 text-sm truncate"><?= htmlspecialchars($work['title']) ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                    <?php foreach ($works as $work): ?>
                    <?php $imgSrc = normalizeImagePath($work['image'] ?? ''); ?>
                    <div class="group cursor-pointer" onclick="openWorkModal(<?= $work['id'] ?>)">
                        <div class="aspect-square rounded-2xl overflow-hidden bg-gray-100 shadow-md group-hover:shadow-lg transition-shadow relative">
                            <?php if (!empty($imgSrc)): ?>
                            <img src="<?= htmlspecialchars($imgSrc) ?>" 
                                class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-300">
                            <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center">
                                <i class="fas fa-image text-gray-300 text-4xl"></i>
                            </div>
                            <?php endif; ?>
                            <?php if (!empty($work['youtube_url'])): ?>
                            <span class="absolute top-2 right-2 bg-red-500 text-white text-xs font-bold px-2 py-1 rounded">
                                <i class="fab fa-youtube"></i>
                            </span>
                            <?php endif; ?>
                        </div>
                        <p class="mt-3 font-bold text-gray-700 text-sm truncate"><?= htmlspecialchars($work['title']) ?></p>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php 
                    // イラストカテゴリーの場合、ステッカーグループも表示
                    if (($cat === 'illust' || $cat === 'illustration') && !empty($stickerGroups)):
                        foreach ($stickerGroups as $group):
                            // stack_images の中から画像があるものだけ（最大4枚）
                            $stackImagesRaw = $group['stack_images'] ?? [];
                            $stackImages = [];
                            foreach ($stackImagesRaw as $s) {
                                if (!empty($s['image'])) $stackImages[] = $s;
                                if (count($stackImages) >= 4) break;
                            }

                            // 代表画像が空の場合は stack の先頭にフォールバック
                            $representativeImage = !empty($group['representative']['image'])
                                ? $group['representative']['image']
                                : (!empty($stackImages[0]['image']) ? $stackImages[0]['image'] : '');

                            $stickerCount = $group['sticker_count'] ?? (is_array($group['stickers'] ?? null) ? count($group['stickers']) : 0);

                            // 最大4枚のレイヤー（後ろから前へ）
                            $layers = [];
                            if (!empty($stackImages[3]['image'])) $layers[] = $stackImages[3]['image'];
                            if (!empty($stackImages[2]['image'])) $layers[] = $stackImages[2]['image'];
                            if (!empty($stackImages[1]['image'])) $layers[] = $stackImages[1]['image'];
                            $layers[] = $representativeImage; // 一番前
                    ?>
                    <div class="group cursor-pointer" onclick="openStickerGroupModal(<?= $group['id'] ?>)">
                        <div class="aspect-square relative">
                            <!-- 重なったステッカー -->
                            <div class="sticker-stack absolute inset-0 flex items-center justify-center">
                                <?php 
                                $totalLayers = count($layers);
                                foreach ($layers as $idx => $layerImg): 
                                    $layerNum = $totalLayers - $idx; // 後ろから番号付け
                                    $zIndex = $idx + 1;
                                ?>
                                <div class="sticker-layer absolute" 
                                     style="z-index: <?= $zIndex ?>;" 
                                     data-layer="<?= $layerNum ?>">
                                    <img src="<?= htmlspecialchars(normalizeImagePath($layerImg)) ?>" 
                                         class="w-full h-full object-contain drop-shadow-lg" alt="">
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- 枚数バッジ -->
                            <div class="absolute top-2 right-2 bg-gradient-to-r from-purple-500 to-pink-500 text-white text-xs px-2 py-1 rounded-full font-bold flex items-center gap-1 z-20 shadow-lg">
                                <i class="fas fa-layer-group"></i>
                                <span><?= $stickerCount ?>枚</span>
                            </div>
                        </div>
                        <p class="mt-3 font-bold text-gray-700 text-sm truncate"><?= htmlspecialchars($group['title']) ?></p>
                    </div>
                    <?php 
                        endforeach;
                    endif;
                    ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </section>
        <?php endif; ?>
        
        <!-- Articles Section 追加 -->
        <?php if (!empty($creatorArticles)): ?>
        <section class="mb-16">
            <h2 class="font-display text-2xl text-gray-800 mb-8 flex items-center gap-3">
                <span class="w-8 h-1 bg-pop-pink rounded"></span>
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
        
        <!-- Request Button -->
        <div class="text-center mb-16 py-8 bg-white rounded-3xl border border-gray-200 shadow-sm">
            <p class="text-gray-500 mb-4 font-bold">このクリエイターに制作を依頼しませんか？</p>
            <a href="index.php?nominated=<?= $creator['id'] ?>#tab-request" 
                class="inline-flex items-center gap-2 bg-pop-pink hover:bg-pink-500 text-white font-bold px-8 py-4 rounded-full transition shadow-lg hover:shadow-xl">
                <i class="fas fa-paper-plane"></i>
                <?= htmlspecialchars($creator['name']) ?>さんに依頼する
            </a>
        </div>
        
        <!-- Other Creators -->
        <?php if (!empty($otherCreators)): ?>
        <section>
            <h2 class="font-display text-2xl text-gray-800 mb-8 flex items-center gap-3">
                <span class="w-8 h-1 bg-pop-blue rounded"></span>
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
        <div class="mt-16 text-center">
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
            <button onclick="closeWorkModal()" class="absolute top-4 right-4 w-10 h-10 flex items-center justify-center bg-gray-100 rounded-full text-gray-500 hover:bg-gray-200 transition-colors z-10">
                <i class="fas fa-times"></i>
            </button>
            <!-- 画像/動画表示エリア -->
            <div id="w-media" class="relative bg-gray-100">
                <img id="w-img" src="/placeholder.svg" class="w-full h-auto max-h-[50vh] object-contain bg-gray-100">
            </div>
            <!-- 漫画を読むボタン -->
            <div id="w-read-manga" class="hidden p-4">
                <button class="w-full bg-pop-purple hover:bg-purple-600 text-white py-4 rounded-xl font-bold text-lg transition-colors flex items-center justify-center gap-3">
                    <i class="fas fa-book-open text-xl"></i>
                    <span>このマンガを読む</span>
                    <span class="bg-white/20 px-3 py-1 rounded-full text-sm"><span id="w-manga-pages">0</span>P</span>
                </button>
            </div>
            <div class="p-6 md:p-8">
                <h2 id="w-title" class="font-display text-2xl mb-4"></h2>
                <p id="w-desc" class="text-gray-600 mb-6 whitespace-pre-wrap"></p>
                <div class="flex items-center justify-between border-t border-gray-100 pt-6">
                    <span id="w-date" class="text-sm text-gray-400"></span>
                    <!-- シェアボタン群 -->
                    <div class="flex items-center gap-2">
                        <button onclick="shareWork()" class="w-9 h-9 flex items-center justify-center rounded-full bg-gray-100 hover:bg-gray-200 text-gray-600 transition-colors" title="シェア">
                            <i class="fas fa-share-alt"></i>
                        </button>
                        <button onclick="copyWorkLink()" class="w-9 h-9 flex items-center justify-center rounded-full bg-gray-100 hover:bg-gray-200 text-gray-600 transition-colors" title="リンクをコピー">
                            <i class="fas fa-link"></i>
                        </button>
                        <a id="w-share-x" href="#" target="_blank" class="w-9 h-9 flex items-center justify-center rounded-full bg-gray-100 hover:bg-gray-200 text-gray-600 transition-colors" title="Xでシェア">
                            <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                        </a>
                    </div>
                </div>
            </div>
        </div>
        <!-- コピー完了トースト -->
        <div id="w-copy-toast" class="hidden fixed bottom-24 left-1/2 -translate-x-1/2 bg-gray-900 text-white px-4 py-2 rounded-full text-sm font-bold shadow-lg z-[110]">
            <i class="fas fa-check mr-2"></i>リンクをコピーしました
        </div>
    </div>
    
    <!-- ステッカーグループモーダル（第1階層） -->
    <div id="sticker-group-modal" class="fixed inset-0 bg-black/90 z-[100] hidden items-center justify-center p-2 sm:p-4" onclick="event.target === this && closeStickerGroupModal()">
        <div class="bg-white rounded-2xl sm:rounded-3xl max-w-4xl w-full max-h-[95vh] sm:max-h-[90vh] overflow-hidden shadow-2xl relative flex flex-col" onclick="event.stopPropagation()">
            <!-- ヘッダー（スマホでコンパクト） -->
            <div class="bg-gradient-to-r from-purple-500 to-pink-500 px-3 py-2 sm:px-6 sm:py-4 flex items-center justify-between flex-shrink-0">
                <div class="flex items-center gap-2 sm:gap-3 min-w-0">
                    <div class="w-7 h-7 sm:w-9 sm:h-9 rounded-full bg-white/20 backdrop-blur flex items-center justify-center flex-shrink-0">
                        <i class="fas fa-layer-group text-white text-sm sm:text-base"></i>
                    </div>
                    <h2 id="sticker-group-title" class="font-display text-base sm:text-xl text-white truncate">ステッカー</h2>
                    <span id="sticker-group-count" class="bg-white/20 backdrop-blur text-white text-xs sm:text-sm font-bold px-2 py-0.5 sm:px-3 sm:py-1 rounded-full flex-shrink-0"></span>
                </div>
                <button onclick="closeStickerGroupModal()" class="w-8 h-8 sm:w-10 sm:h-10 rounded-full bg-white/10 hover:bg-white/20 backdrop-blur flex items-center justify-center transition-colors flex-shrink-0">
                    <i class="fas fa-times text-white text-lg sm:text-xl"></i>
                </button>
            </div>
            
            <!-- ステッカーグリッド（画面内に収める） -->
            <div id="sticker-group-scroll" class="p-3 sm:p-6 overflow-y-auto flex-1 min-h-0">
                <div id="sticker-group-grid" class="grid gap-2 sm:gap-4"></div>
            </div>
        </div>
    </div>

    <!-- ステッカー詳細モーダル（第2階層） -->
    <div id="sticker-detail-modal" class="fixed inset-0 bg-black/95 z-[110] hidden items-center justify-center p-4" onclick="event.target === this && closeStickerDetailModal()">
        <div class="bg-white rounded-3xl max-w-2xl w-full max-h-[90vh] overflow-y-auto shadow-2xl relative p-6" onclick="event.stopPropagation()">
            <button onclick="closeStickerDetailModal()" class="absolute top-4 right-4 w-10 h-10 rounded-full bg-gray-100 hover:bg-gray-200 flex items-center justify-center transition-colors z-10">
                <i class="fas fa-times text-gray-600 text-xl"></i>
            </button>
            <div id="sticker-detail-container"></div>
        </div>
    </div>

    <style>
        /* ステッカーカード3D裏返しアニメーション */
        .sticker-card {
            perspective: 1000px;
            transform-style: preserve-3d;
            transition: transform 0.6s;
        }
        .sticker-card-front,
        .sticker-card-back {
            backface-visibility: hidden;
        }
        .sticker-card-back {
            transform: rotateY(180deg);
        }
    </style>
    
    <!-- Footer -->
    <footer class="bg-gray-900 text-white py-12 mt-16">
        <div class="max-w-4xl mx-auto px-6 text-center">
            <p class="font-display text-xl mb-4">ぷれぐら！<span class="text-pop-yellow">PLAYGROUND</span></p>
            <p class="text-gray-400 text-sm">&copy; 2025 ぷれぐら！PLAYGROUND. All rights reserved.</p>
        </div>
    </footer>
    
    <script>
        // Works data for modal
        const works = <?= json_encode($creatorWorks, JSON_UNESCAPED_UNICODE) ?>;
        
        // 画像パスを正規化する関数
        function getImagePath(img) {
            if (!img) return '/placeholder.svg';
            if (img.startsWith('http')) return img;
            // 相対パスの場合、先頭のスラッシュを確認
            return img.startsWith('/') ? img : '/' + img;
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
            const youtubeId = extractYoutubeId(work.youtube_url);
            const hasCustomImage = work.image && !work.image.includes('youtube.com') && !work.image.includes('img.youtube.com');
            const useImage = (work.thumbnail_type === 'image' && hasCustomImage) || !youtubeId;
            
            // 漫画ボタンの表示/非表示（index.phpと同じ挙動）
            if (work.pages && work.pages.length > 0) {
                readMangaBtn.classList.remove('hidden');
                document.getElementById('w-manga-pages').innerText = work.pages.length;
                readMangaBtn.querySelector('button').onclick = function() { 
                    window.location.href = '/manga/' + work.id; 
                };
            } else {
                readMangaBtn.classList.add('hidden');
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
                // 通常画像（YouTube動画がある場合は動画リンクも表示）
                let imgSrc = getImagePath(work.image);
                mediaContainer.innerHTML = `<img src="${imgSrc}" class="w-full h-auto max-h-[50vh] object-contain bg-gray-100">${youtubeId ? `<a href="https://www.youtube.com/watch?v=${youtubeId}" target="_blank" class="absolute bottom-4 right-4 bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded-full font-bold text-sm transition-colors"><i class="fab fa-youtube mr-2"></i>動画を見る</a>` : ''}`;
            }
            
            document.getElementById('w-title').innerText = work.title || '';
            document.getElementById('w-desc').innerText = work.description || '';
            document.getElementById('w-date').innerText = work.created_at ? new Date(work.created_at).toLocaleDateString('ja-JP') : '';
            
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
        
        // ステッカーモーダル関数
        // ステッカーグループデータ
        const stickerGroups = <?= json_encode($stickerGroups, JSON_UNESCAPED_UNICODE) ?>;
                let currentStickerGroup = null;

        // --- ステッカー一覧：枚数に応じて列数を決定 ---
        function calcStickerGridCols(count) {
            const n = Math.max(1, parseInt(count, 10) || 1);
            const isMobile = window.innerWidth < 640;
            
            // スマホは最大2列
            if (isMobile) {
                return Math.min(2, n);
            }
            
            // PCは枚数ベースで列数を決定
            if (n <= 1) return 1;
            if (n <= 4) return 2;  // 4枚以下: 2列 (2×2)
            if (n <= 9) return 3;  // 9枚以下: 3列 (3×3)
            if (n <= 16) return 4; // 16枚以下: 4列 (4×4)
            if (n <= 25) return 5; // 25枚以下: 5列 (5×5)
            return 6; // それ以上: 6列
        }
        function applyStickerGridLayout(stickerCount) {
            const grid = document.getElementById('sticker-group-grid');
            if (!grid) return;
            const cols = calcStickerGridCols(stickerCount);
            grid.style.gridTemplateColumns = `repeat(${cols}, minmax(0, 1fr))`;
        }
        
        // ステッカーグループモーダルを開く
        function openStickerGroupModal(groupId) {
            const group = stickerGroups.find(g => g.id === groupId);
            if (!group) return;

            currentStickerGroup = group;
            const modal = document.getElementById('sticker-group-modal');
            const title = document.getElementById('sticker-group-title');
            const grid = document.getElementById('sticker-group-grid');
            const count = document.getElementById('sticker-group-count');

            title.textContent = group.title || 'ステッカー';

            const rawStickers = group.stickers || [];
            const stickers = Array.isArray(rawStickers) ? rawStickers : Object.values(rawStickers || {});
            const stickerCount = (group.sticker_count || stickers.length || 0);
            count.textContent = stickerCount + '枚';

            // 作品数に応じて列数を可変（最大列数あり）
            applyStickerGridLayout(stickers.length);

            const stickersHtml = stickers.map(sticker => {
                const cropPosition = sticker.crop_position || 'center';
                return `
                    <div class="group cursor-pointer" onclick="openStickerDetailModal(${sticker.id})">
                        <div class="aspect-square overflow-hidden group-hover:scale-105 transition-transform duration-200 relative">
                            <img src="${getImagePath(sticker.image)}" 
                                class="w-full h-full object-contain" 
                                style="object-position: ${cropPosition}" 
                                loading="lazy" alt="">
                            <div class="absolute inset-0 bg-gradient-to-t from-black/60 to-transparent opacity-0 group-hover:opacity-100 transition-opacity flex items-end p-2">
                                <span class="text-white text-xs font-bold truncate">${sticker.title || ''}</span>
                            </div>
                        </div>
                    </div>
                `;
            }).join('');

            grid.innerHTML = stickersHtml;

            // スクロール位置を先頭へ
            const scroller = document.getElementById('sticker-group-scroll');
            if (scroller) scroller.scrollTop = 0;

            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.style.overflow = 'hidden';
        }
        
        function closeStickerGroupModal() {
            const modal = document.getElementById('sticker-group-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.body.style.overflow = '';
            currentStickerGroup = null;
        }

        // モーダル表示中に画面サイズが変わったら列数を再計算
        window.addEventListener('resize', () => {
            if (!currentStickerGroup) return;
            const rawStickers = currentStickerGroup.stickers || [];
            const stickers = Array.isArray(rawStickers) ? rawStickers : Object.values(rawStickers || {});
            applyStickerGridLayout(stickers.length);
        });
        
        // ステッカー詳細モーダルを開く
        function openStickerDetailModal(stickerId) {
            const sticker = works.find(w => w.id === stickerId);
            if (!sticker) return;
            
            const modal = document.getElementById('sticker-detail-modal');
            const container = document.getElementById('sticker-detail-container');
            const hasBackImage = sticker.back_image && sticker.back_image.trim() !== '';
            
            const html = `
                <div class="flex flex-col items-center">
                    <div class="relative mb-4" id="sticker-card-container">
                        <div class="sticker-card w-64 h-64 sm:w-80 sm:h-80 md:w-96 md:h-96 relative" id="sticker-card">
                            <div class="sticker-card-front absolute inset-0 rounded-2xl overflow-hidden shadow-2xl">
                                <img src="${getImagePath(sticker.image)}" class="w-full h-full object-contain bg-white">
                            </div>
                            ${hasBackImage ? `
                            <div class="sticker-card-back absolute inset-0 rounded-2xl overflow-hidden shadow-2xl">
                                <img src="${getImagePath(sticker.back_image)}" class="w-full h-full object-contain bg-white">
                            </div>
                            ` : ''}
                        </div>
                    </div>
                    <h2 class="text-xl md:text-2xl font-bold text-gray-800 mb-2 text-center">${sticker.title || 'ステッカー'}</h2>
                    <p class="text-sm text-gray-600 mb-4"><i class="fas fa-user mr-1"></i><?= htmlspecialchars($creator['name']) ?></p>
                    ${sticker.description ? `<p class="text-sm text-gray-600 text-center mb-4 max-w-md">${sticker.description}</p>` : ''}
                    ${hasBackImage ? `
                    <button onclick="flipStickerCard()" class="bg-gradient-to-r from-purple-500 to-pink-500 hover:from-purple-600 hover:to-pink-600 text-white px-6 py-2 rounded-full font-bold transition-all hover:scale-105 shadow-lg">
                        <i class="fas fa-sync-alt mr-2"></i>裏面を見る
                    </button>
                    ` : ''}
                </div>
            `;
            
            container.innerHTML = html;
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        }
        
        function closeStickerDetailModal() {
            const modal = document.getElementById('sticker-detail-modal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            isStickerFlipped = false; // リセット
        }
        
        // ステッカーカードを裏返す
        let isStickerFlipped = false;
        function flipStickerCard() {
            const card = document.getElementById('sticker-card');
            isStickerFlipped = !isStickerFlipped;
            if (isStickerFlipped) {
                card.style.transform = 'rotateY(180deg)';
            } else {
                card.style.transform = 'rotateY(0deg)';
            }
        }
        
        // キーボード操作（ESCでモーダル閉じる）
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape') {
                const detailModal = document.getElementById('sticker-detail-modal');
                const groupModal = document.getElementById('sticker-group-modal');
                
                if (!detailModal.classList.contains('hidden')) {
                    closeStickerDetailModal();
                } else if (!groupModal.classList.contains('hidden')) {
                    closeStickerGroupModal();
                } else {
                    closeWorkModal();
                }
            }
        });


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
