<?php
/**
 * 記事個別ページ
 */

require_once 'includes/db.php';
require_once 'includes/sanitize.php';
require_once 'includes/site-settings.php';
require_once 'includes/seo-tags.php';

$db = getDB();
$article = null;

// IDまたはスラッグで記事を取得
if (isset($_GET['id']) && is_numeric($_GET['id'])) {
    $stmt = $db->prepare("SELECT * FROM articles WHERE id = ? AND is_active = 1");
    $stmt->execute([$_GET['id']]);
    $article = $stmt->fetch();
} elseif (isset($_GET['slug'])) {
    $stmt = $db->prepare("SELECT * FROM articles WHERE slug = ? AND is_active = 1");
    $stmt->execute([$_GET['slug']]);
    $article = $stmt->fetch();
}

if (!$article) {
    header("Location: /");
    exit;
}

$authorCreator = null;
if (!empty($article['creator_id'])) {
    $stmt = $db->prepare("SELECT id, name, slug, image FROM creators WHERE id = ? AND is_active = 1");
    $stmt->execute([$article['creator_id']]);
    $authorCreator = $stmt->fetch();
} elseif (!empty($article['author'])) {
    $stmt = $db->prepare("SELECT id, name, slug, image FROM creators WHERE name = ? AND is_active = 1");
    $stmt->execute([$article['author']]);
    $authorCreator = $stmt->fetch();
}

$relatedWork = null;
if (!empty($article['related_work_id'])) {
    $stmt = $db->prepare("SELECT w.*, c.name as creator_name, c.id as creator_id, c.slug as creator_slug FROM works w LEFT JOIN creators c ON w.creator_id = c.id WHERE w.id = ?");
    $stmt->execute([$article['related_work_id']]);
    $relatedWork = $stmt->fetch();
}

// 関連コレクション
$relatedCollection = null;
if (!empty($article['related_collection_id'])) {
    $stmt = $db->prepare("
        SELECT col.*, c.name as creator_name, c.slug as creator_slug,
               (SELECT COUNT(*) FROM works w WHERE w.collection_id = col.id) as sticker_count,
               (SELECT w.image FROM works w WHERE w.collection_id = col.id ORDER BY w.sort_order LIMIT 1) as first_image
        FROM collections col
        LEFT JOIN creators c ON col.creator_id = c.id
        WHERE col.id = ?
    ");
    $stmt->execute([$article['related_collection_id']]);
    $relatedCollection = $stmt->fetch();
}

// 関連記事（同じタイプの記事を3件）
$stmt = $db->prepare("SELECT * FROM articles WHERE is_active = 1 AND id != ? AND article_type = ? ORDER BY published_at DESC LIMIT 3");
$stmt->execute([$article['id'], $article['article_type'] ?? 'blog']);
$relatedArticles = $stmt->fetchAll();

// タイプラベル
$typeLabels = [
    'blog' => 'ブログ',
    'diary' => '日記',
    'interview' => 'インタビュー',
    'news' => 'ニュース',
    'feature' => '特集',
];
$typeColors = [
    'blog' => 'bg-blue-500',
    'diary' => 'bg-pink-500',
    'interview' => 'bg-purple-500',
    'news' => 'bg-green-500',
    'feature' => 'bg-orange-500',
];
$type = $article['article_type'] ?? 'blog';

// OGP用のURL生成
$protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = $_SERVER['HTTP_HOST'] ?? 'localhost';
$baseUrl = $protocol . '://' . $host;
$articleUrl = !empty($article['slug']) 
    ? $baseUrl . '/article/' . $article['slug']
    : $baseUrl . '/article.php?id=' . $article['id'];

// OGP画像（WebPはLINE/Twitter等で認識されないため、JPG/PNG版を優先）
$ogImage = getOgImageUrl($article['image'] ?? '', $baseUrl);
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
    <?php $faviconInfo = getFaviconInfo($db); ?>
    <link rel="icon" href="<?= htmlspecialchars($faviconInfo['path']) ?>" type="<?= htmlspecialchars($faviconInfo['type']) ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($faviconInfo['path']) ?>">
    <title><?= htmlspecialchars($article['title']) ?> | ぷれぐら！PLAYGROUND</title>
    <meta name="description" content="<?= htmlspecialchars($article['excerpt'] ?? '') ?>">
    
    <!-- OGP (Open Graph Protocol) -->
    <meta property="og:type" content="article">
    <meta property="og:title" content="<?= htmlspecialchars($article['title']) ?>">
    <meta property="og:description" content="<?= htmlspecialchars($article['excerpt'] ?? '') ?>">
    <meta property="og:url" content="<?= htmlspecialchars($articleUrl) ?>">
    <meta property="og:image" content="<?= htmlspecialchars($ogImage) ?>">
    <meta property="og:image:width" content="1200">
    <meta property="og:image:height" content="630">
    <meta property="og:site_name" content="ぷれぐら！PLAYGROUND">
    <meta property="og:locale" content="ja_JP">
    
    <!-- Twitter Card -->
    <meta name="twitter:card" content="summary_large_image">
    <meta name="twitter:title" content="<?= htmlspecialchars($article['title']) ?>">
    <meta name="twitter:description" content="<?= htmlspecialchars($article['excerpt'] ?? '') ?>">
    <meta name="twitter:image" content="<?= htmlspecialchars($ogImage) ?>">
    
    <?php outputSeoTags($db); ?>
    
    <!-- Canonical URL -->
    <link rel="canonical" href="<?= htmlspecialchars($articleUrl) ?>">
    
    <!-- Preconnect -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="preconnect" href="https://cdn.tailwindcss.com">
    <link rel="preconnect" href="https://cdnjs.cloudflare.com">
    
    <!-- Tailwind CSS（同期読み込み - レイアウト崩れ防止） -->
    <script src="https://cdn.tailwindcss.com"></script>
    
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" media="print" onload="this.media='all'">
    <noscript><link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css"></noscript>
    <link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@400;700&family=Dela+Gothic+One&display=swap" rel="stylesheet" media="print" onload="this.media='all'">
    <noscript><link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@400;700&family=Dela+Gothic+One&display=swap" rel="stylesheet"></noscript>
    <style>
        body { font-family: 'Zen Maru Gothic', sans-serif; }
        .font-display { font-family: 'Dela Gothic One', cursive; }
        
        /* 記事本文スタイル - 読みやすさ重視 */
        .article-content {
            font-size: 15px;
            line-height: 1.8;
            font-weight: 500;
            color: #374151;
        }
        .article-content h1 { 
            font-size: 1.4rem; 
            font-weight: 700; 
            margin: 1.5rem 0 0.75rem;
            line-height: 1.4;
        }
        .article-content h2 { 
            font-size: 1.2rem; 
            font-weight: 700; 
            margin: 1.5rem 0 0.5rem; 
            border-bottom: 2px solid #fbbf24; 
            padding-bottom: 0.4rem;
            line-height: 1.4;
        }
        .article-content h3 { 
            font-size: 1.1rem; 
            font-weight: 700; 
            margin: 1rem 0 0.4rem;
            line-height: 1.4;
        }
        .article-content p { 
            margin: 0.5rem 0; 
        }
        /* 空の段落（改行のみ）は余白を小さく */
        .article-content p:empty,
        .article-content p br:only-child {
            margin: 0.25rem 0;
        }
        .article-content p + p {
            margin-top: 0.5rem;
        }
        .article-content ul, .article-content ol { 
            margin: 0.75rem 0; 
            padding-left: 1.5rem; 
        }
        .article-content li { 
            margin: 0.3rem 0; 
        }
        .article-content ul li { list-style-type: disc; }
        .article-content ol li { list-style-type: decimal; }
        .article-content blockquote { 
            border-left: 4px solid #fbbf24; 
            padding: 0.75rem 1rem; 
            margin: 1rem 0; 
            background: #fef9c3;
            border-radius: 0 8px 8px 0;
        }
        /* 空行（段落分け）は小さめに */
        .article-content p.empty-line {
            margin: 0.4rem 0;
            height: 0.4rem;
        }
        .article-content img { 
            max-width: 100%; 
            height: auto; 
            border-radius: 12px; 
            margin: 1rem 0;
        }
        .article-content a { color: #8b5cf6; text-decoration: underline; word-break: break-all; }
        .article-content a:hover { color: #7c3aed; }
        .article-content pre { 
            background: #1f2937; 
            color: #f3f4f6; 
            padding: 0.75rem; 
            border-radius: 8px; 
            overflow-x: auto;
            margin: 1rem 0;
            font-size: 0.85rem;
        }
        .article-content code { 
            background: #e5e7eb; 
            padding: 0.2rem 0.4rem; 
            border-radius: 4px; 
            font-size: 0.9em;
            word-break: break-all;
        }
        .article-content pre code {
            background: none;
            padding: 0;
        }
        .article-content iframe {
            width: 100%;
            aspect-ratio: 16/9;
            border-radius: 12px;
            margin: 1rem 0;
        }
        
        /* PC向けスタイル */
        @media (min-width: 768px) {
            .article-content h1 { font-size: 2rem; margin: 2rem 0 1rem; }
            .article-content h2 { font-size: 1.5rem; margin: 1.5rem 0 1rem; }
            .article-content h3 { font-size: 1.25rem; margin: 1.25rem 0 0.75rem; }
            .article-content p { font-size: 1rem; }
            .article-content li { font-size: 1rem; }
            .article-content blockquote { padding: 1rem 1.5rem; margin: 1.5rem 0; font-size: 1rem; }
            .article-content img { margin: 1.5rem 0; }
            .article-content pre { padding: 1rem; font-size: 0.9rem; }
        }
    </style>
</head>
<body class="bg-gray-50">
    <!-- Header -->
    <header class="bg-white border-b border-gray-200 sticky top-0 z-50">
        <div class="max-w-4xl mx-auto px-4 md:px-6 py-3 md:py-4 flex justify-between items-center">
            <a href="/" class="font-display text-lg md:text-xl text-gray-800">
                ぷれぐら！<span class="text-yellow-400">PLAYGROUND</span>
            </a>
            <a href="/#tab-magazine" class="text-gray-600 hover:text-gray-800 font-bold text-xs md:text-sm">
                <i class="fas fa-arrow-left mr-1 md:mr-2"></i><span class="hidden sm:inline">MEDIAに戻る</span><span class="sm:hidden">戻る</span>
            </a>
        </div>
    </header>
    
    <!-- Hero Image -->
    <?php 
    $heroImgSrc = normalizeImagePath($article['image'] ?? '');
    if (!empty($heroImgSrc)): 
    ?>
    <div class="w-full max-w-4xl mx-auto px-4 md:px-6 pt-6">
        <div class="relative w-full" style="aspect-ratio: 1200/630;">
            <img src="<?= htmlspecialchars($heroImgSrc) ?>" 
                alt="<?= htmlspecialchars($article['title']) ?>"
                class="w-full h-full object-cover rounded-2xl shadow-lg">
        </div>
    </div>
    <?php endif; ?>
    
    <!-- Article Content -->
    <main class="max-w-4xl mx-auto px-4 md:px-6 py-8 md:py-12">
        <!-- Meta -->
        <div class="flex flex-wrap items-center gap-2 md:gap-4 mb-4 md:mb-6">
            <span class="<?= $typeColors[$type] ?? 'bg-gray-500' ?> text-white text-xs md:text-sm font-bold px-2 md:px-3 py-1 rounded-full">
                <?= $typeLabels[$type] ?? $type ?>
            </span>
            <?php if (!empty($article['category'])): ?>
            <span class="bg-gray-200 text-gray-700 text-xs md:text-sm font-bold px-2 md:px-3 py-1 rounded-full">
                <?= htmlspecialchars($article['category']) ?>
            </span>
            <?php endif; ?>
            <span class="text-gray-500 text-xs md:text-sm">
                <i class="far fa-clock mr-1"></i>
                <?= date('Y年n月j日', strtotime($article['published_at'])) ?>
            </span>
        </div>
        
        <!-- Title -->
        <h1 class="font-bold text-lg md:text-2xl lg:text-2xl text-gray-800 mb-4 md:mb-6 leading-snug">
            <?= htmlspecialchars($article['title']) ?>
        </h1>
        
        <!-- Author -->
        <?php if (!empty($article['author'])): ?>
        <div class="flex items-center gap-3 mb-6 md:mb-8 pb-6 md:pb-8 border-b border-gray-200">
            <?php 
            $authorUrl = ($authorCreator && !empty($authorCreator['slug'])) 
                ? '/creator/' . $authorCreator['slug'] 
                : ($authorCreator ? 'creator.php?id=' . $authorCreator['id'] : '#');
            $authorImgSrc = normalizeImagePath(($authorCreator && !empty($authorCreator['image'])) ? $authorCreator['image'] : '');
            ?>
            <?php if ($authorCreator && !empty($authorImgSrc)): ?>
            <a href="<?= htmlspecialchars($authorUrl) ?>">
                <img src="<?= htmlspecialchars($authorImgSrc) ?>" 
                     alt="<?= htmlspecialchars($article['author']) ?>"
                     class="w-10 h-10 md:w-12 md:h-12 rounded-full object-cover">
            </a>
            <?php else: ?>
            <div class="w-10 h-10 md:w-12 md:h-12 bg-yellow-400 rounded-full flex items-center justify-center text-gray-800 font-bold text-sm md:text-base">
                <?= mb_substr($article['author'], 0, 1) ?>
            </div>
            <?php endif; ?>
            <div>
                <?php if ($authorCreator): ?>
                <a href="<?= htmlspecialchars($authorUrl) ?>" class="font-bold text-gray-800 text-sm md:text-base hover:text-yellow-600">
                    <?= htmlspecialchars($authorCreator['name']) ?>
                </a>
                <?php else: ?>
                <p class="font-bold text-gray-800 text-sm md:text-base"><?= htmlspecialchars($article['author']) ?></p>
                <?php endif; ?>
                <p class="text-xs md:text-sm text-gray-500">著者</p>
            </div>
        </div>
        <?php endif; ?>
        
        <?php 
        // 関連の表示位置
        $relatedPosition = $article['related_position'] ?? 'top';
        
        // 関連コンテンツのHTML生成
        ob_start();
        ?>
        <?php if ($relatedWork): ?>
        <?php 
        $relatedWorkImgSrc = normalizeImagePath($relatedWork['image'] ?? '');
        ?>
        <div class="mb-6 p-4 md:p-6 bg-gradient-to-r from-purple-50 to-pink-50 rounded-2xl border border-purple-100 cursor-pointer hover:shadow-lg transition"
             onclick="openWorkModal(<?= $relatedWork['id'] ?>)">
            <p class="text-xs font-bold text-purple-600 mb-3 flex items-center gap-2">
                <i class="fas fa-palette"></i>この記事で紹介している作品
            </p>
            <div class="flex items-center gap-4 group">
                <?php if (!empty($relatedWorkImgSrc)): ?>
                <img src="<?= htmlspecialchars($relatedWorkImgSrc) ?>" 
                     alt="<?= htmlspecialchars($relatedWork['title']) ?>"
                     class="w-20 h-20 md:w-24 md:h-24 object-cover rounded-xl shadow-md group-hover:shadow-lg transition">
                <?php else: ?>
                <div class="w-20 h-20 md:w-24 md:h-24 bg-gray-200 rounded-xl flex items-center justify-center">
                    <i class="fas fa-image text-gray-400 text-2xl"></i>
                </div>
                <?php endif; ?>
                <div class="flex-1">
                    <h3 class="font-bold text-gray-800 text-sm md:text-base group-hover:text-purple-600 transition">
                        <?= htmlspecialchars($relatedWork['title']) ?>
                    </h3>
                    <?php if (!empty($relatedWork['creator_name'])): ?>
                    <p class="text-xs md:text-sm text-gray-500 mt-1">
                        by <?= htmlspecialchars($relatedWork['creator_name']) ?>
                    </p>
                    <?php endif; ?>
                    <p class="text-xs text-purple-500 mt-2 flex items-center gap-1">
                        <i class="fas fa-expand-alt mr-1"></i>タップして詳細を見る
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($relatedCollection): ?>
        <?php 
        $collectionImgSrc = normalizeImagePath($relatedCollection['first_image'] ?? '');
        ?>
        <div class="mb-6 p-4 md:p-6 bg-gradient-to-r from-green-50 to-emerald-50 rounded-2xl border border-green-100 cursor-pointer hover:shadow-lg transition collection-card-wrapper"
             onclick="openStickerGroupModalWithAnimation(this, <?= $relatedCollection['id'] ?>)">
            <p class="text-xs font-bold text-green-600 mb-3 flex items-center gap-2">
                <i class="fas fa-layer-group"></i>この記事で紹介しているコレクション
            </p>
            <div class="flex items-center gap-4 group">
                <?php if (!empty($collectionImgSrc)): ?>
                <img src="<?= htmlspecialchars($collectionImgSrc) ?>" 
                     alt="<?= htmlspecialchars($relatedCollection['title']) ?>"
                     class="w-20 h-20 md:w-24 md:h-24 object-cover rounded-xl shadow-md group-hover:shadow-lg transition">
                <?php else: ?>
                <div class="w-20 h-20 md:w-24 md:h-24 bg-gray-200 rounded-xl flex items-center justify-center">
                    <i class="fas fa-layer-group text-gray-400 text-2xl"></i>
                </div>
                <?php endif; ?>
                <div class="flex-1">
                    <h3 class="font-bold text-gray-800 text-sm md:text-base group-hover:text-green-600 transition">
                        <?= htmlspecialchars($relatedCollection['title']) ?>
                    </h3>
                    <p class="text-xs md:text-sm text-gray-500 mt-1">
                        <?= (int)($relatedCollection['sticker_count'] ?? 0) ?>枚
                        <?php if (!empty($relatedCollection['creator_name'])): ?>
                         · by <?= htmlspecialchars($relatedCollection['creator_name']) ?>
                        <?php endif; ?>
                    </p>
                    <p class="text-xs text-green-500 mt-2 flex items-center gap-1">
                        <i class="fas fa-expand-alt mr-1"></i>タップして詳細を見る
                    </p>
                </div>
            </div>
        </div>
        <?php endif; ?>
        <?php $relatedContentHtml = ob_get_clean(); ?>
        
        <!-- 関連コンテンツ（上部表示の場合） -->
        <?php if ($relatedPosition === 'top' && ($relatedWork || $relatedCollection)): ?>
        <?= $relatedContentHtml ?>
        <?php endif; ?>
        
        <!-- Content -->
        <article class="article-content text-gray-700">
            <?= sanitizeArticleContent($article['content']) ?>
        </article>
        
        <!-- 関連コンテンツ（下部表示の場合） -->
        <?php if ($relatedPosition === 'bottom' && ($relatedWork || $relatedCollection)): ?>
        <div class="mt-8 pt-8 border-t border-gray-200">
            <?= $relatedContentHtml ?>
        </div>
        <?php endif; ?>
        
        <!-- Share -->
        <div class="mt-8 md:mt-12 pt-6 md:pt-8 border-t border-gray-200">
            <p class="text-xs md:text-sm font-bold text-gray-500 mb-4">この記事をシェア</p>
            <div class="flex gap-3">
                <!-- ネイティブシェア（AirDrop対応） -->
                <button onclick="shareArticle()" 
                    class="w-10 h-10 bg-gray-200 text-gray-700 rounded-full flex items-center justify-center hover:bg-gray-300 transition"
                    title="シェア">
                    <i class="fas fa-share-alt"></i>
                </button>
                <!-- リンクコピー -->
                <button onclick="copyArticleLink()" 
                    class="w-10 h-10 bg-gray-200 text-gray-700 rounded-full flex items-center justify-center hover:bg-gray-300 transition"
                    title="リンクをコピー">
                    <i class="fas fa-link"></i>
                </button>
                <!-- X -->
                <a href="https://twitter.com/intent/tweet?url=<?= urlencode('https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) ?>&text=<?= urlencode($article['title']) ?>" 
                    target="_blank" 
                    class="w-10 h-10 bg-gray-900 text-white rounded-full flex items-center justify-center hover:bg-gray-700 transition">
                    <svg xmlns="http://www.w3.org/2000/svg" width="16" height="16" fill="currentColor" viewBox="0 0 16 16">
                        <path d="M12.6.75h2.454l-5.36 6.142L16 15.25h-4.937l-3.867-5.07-4.425 5.07H.316l5.733-6.57L0 .75h5.063l3.495 4.633L12.601.75Zm-.86 13.028h1.36L4.323 2.145H2.865l8.875 11.633Z"/>
                    </svg>
                </a>
                <!-- Facebook -->
                <a href="https://www.facebook.com/sharer/sharer.php?u=<?= urlencode('https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) ?>" 
                    target="_blank"
                    class="w-10 h-10 bg-blue-600 text-white rounded-full flex items-center justify-center hover:bg-blue-700 transition">
                    <i class="fab fa-facebook-f"></i>
                </a>
                <!-- LINE -->
                <a href="https://line.me/R/msg/text/?<?= urlencode($article['title'] . ' ' . 'https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI']) ?>" 
                    target="_blank"
                    class="w-10 h-10 bg-green-500 text-white rounded-full flex items-center justify-center hover:bg-green-600 transition">
                    <i class="fab fa-line"></i>
                </a>
            </div>
            <!-- コピー完了トースト -->
            <div id="copy-toast" class="hidden fixed bottom-24 left-1/2 -translate-x-1/2 bg-gray-900 text-white px-4 py-2 rounded-full text-sm font-bold shadow-lg z-50">
                <i class="fas fa-check mr-2"></i>リンクをコピーしました
            </div>
        </div>
        
        <script>
        async function shareArticle() {
            const shareData = {
                title: '<?= addslashes(htmlspecialchars($article['title'])) ?>',
                text: '<?= addslashes(htmlspecialchars($article['title'])) ?> | ぷれぐら！PLAYGROUND',
                url: window.location.href
            };
            
            if (navigator.share) {
                try {
                    await navigator.share(shareData);
                } catch (err) {
                    console.log('Share cancelled:', err);
                }
            } else {
                copyArticleLink();
            }
        }
        
        function copyArticleLink() {
            navigator.clipboard.writeText(window.location.href).then(() => {
                showCopyToast();
            }).catch(() => {
                const textarea = document.createElement('textarea');
                textarea.value = window.location.href;
                document.body.appendChild(textarea);
                textarea.select();
                document.execCommand('copy');
                document.body.removeChild(textarea);
                showCopyToast();
            });
        }
        
        function showCopyToast() {
            const toast = document.getElementById('copy-toast');
            toast.classList.remove('hidden');
            setTimeout(() => toast.classList.add('hidden'), 2000);
        }
        </script>
        
        <!-- Related Articles -->
        <?php if (!empty($relatedArticles)): ?>
        <div class="mt-12 md:mt-16">
            <h2 class="font-display text-xl md:text-2xl text-gray-800 mb-6 md:mb-8">関連記事</h2>
            <div class="grid grid-cols-1 sm:grid-cols-2 md:grid-cols-3 gap-4 md:gap-6">
                <?php foreach ($relatedArticles as $related): ?>
                <?php 
                $relatedUrl = !empty($related['slug']) ? '/article/' . $related['slug'] : 'article.php?id=' . $related['id'];
                $relatedImgSrc = normalizeImagePath($related['image'] ?? '');
                ?>
                <a href="<?= htmlspecialchars($relatedUrl) ?>" 
                    class="bg-white border border-gray-200 rounded-2xl overflow-hidden hover:-translate-y-1 transition-transform shadow-sm hover:shadow-md">
                    <div class="h-28 md:h-32 relative">
                        <?php if (!empty($relatedImgSrc)): ?>
                        <img src="<?= htmlspecialchars($relatedImgSrc) ?>" class="w-full h-full object-cover">
                        <?php else: ?>
                        <div class="w-full h-full bg-gray-200 flex items-center justify-center">
                            <i class="fas fa-image text-gray-400 text-2xl"></i>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="p-3 md:p-4">
                        <p class="text-xs font-bold text-gray-400 mb-1 md:mb-2">
                            <?= date('Y/m/d', strtotime($related['published_at'])) ?>
                        </p>
                        <h3 class="font-bold text-gray-800 text-xs md:text-sm line-clamp-2">
                            <?= htmlspecialchars($related['title']) ?>
                        </h3>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- Back to MEDIA -->
        <div class="mt-12 md:mt-16 text-center">
            <a href="/#tab-magazine" 
                class="inline-flex items-center gap-2 bg-yellow-400 hover:bg-yellow-500 text-gray-900 font-bold px-6 md:px-8 py-3 md:py-4 rounded-full transition text-sm md:text-base">
                <i class="fas fa-arrow-left"></i>
                MEDIAに戻る
            </a>
        </div>
    </main>
    
    <!-- モーダル -->
    <?php include 'includes/modals.php'; ?>
    
    <?php
    // モーダル用データ準備
    $modalWorks = [];
    $modalCollections = [];
    
    if ($relatedWork) {
        // 作品の全ページを取得
        $stmt = $db->prepare("SELECT image, page_number FROM work_pages WHERE work_id = ? ORDER BY page_number");
        $stmt->execute([$relatedWork['id']]);
        $workPages = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $relatedWork['pages'] = $workPages;
        $modalWorks[] = $relatedWork;
    }
    
    if ($relatedCollection) {
        // コレクション内の作品を取得
        $stmt = $db->prepare("SELECT * FROM works WHERE collection_id = ? ORDER BY sort_order, id");
        $stmt->execute([$relatedCollection['id']]);
        $collectionWorks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $relatedCollection['stickers'] = $collectionWorks;
        $modalCollections[] = $relatedCollection;
    }
    ?>
    
    <script>
    window.ModalConfig = {
        works: <?= json_encode($modalWorks) ?>,
        collections: <?= json_encode($modalCollections) ?>,
        creatorName: '<?= htmlspecialchars($relatedWork['creator_name'] ?? $relatedCollection['creator_name'] ?? '') ?>',
        baseImagePath: '/'
    };
    </script>
    
    <!-- Footer -->
    <footer class="bg-gray-900 text-white py-8 md:py-12 mt-12 md:mt-16">
        <div class="max-w-4xl mx-auto px-4 md:px-6 text-center">
            <p class="font-display text-lg md:text-xl mb-4">ぷれぐら！<span class="text-yellow-400">PLAYGROUND</span></p>
            <p class="text-gray-400 text-xs md:text-sm">&copy; 2025 ぷれぐら！PLAYGROUND. All rights reserved.</p>
        </div>
    </footer>
</body>
</html>
