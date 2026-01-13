<?php
/**
 * SEOチェッカー v2.0
 * サイト全体のSEO設定状況を診断（パフォーマンス・セキュリティ含む）
 */

require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/site-settings.php';
requireAuth();

$db = getDB();

// サイト設定取得
$siteUrl = getSiteSetting($db, 'site_url', '');
$siteName = getSiteSetting($db, 'site_name', '');
$siteDescription = getSiteSetting($db, 'site_description', '');
$ogImage = getSiteSetting($db, 'og_image', '');
$favicon = getSiteSetting($db, 'favicon', '');
$gaId = getSiteSetting($db, 'google_analytics_id', '');
$gsc = getSiteSetting($db, 'google_search_console', '');

$checks = [];
$score = 0;
$maxScore = 0;

$categoryScores = [
    'basic' => ['score' => 0, 'max' => 0, 'label' => '基本設定'],
    'ogp' => ['score' => 0, 'max' => 0, 'label' => 'OGP・画像'],
    'analytics' => ['score' => 0, 'max' => 0, 'label' => '解析'],
    'content' => ['score' => 0, 'max' => 0, 'label' => 'コンテンツ'],
    'technical' => ['score' => 0, 'max' => 0, 'label' => '技術SEO'],
    'performance' => ['score' => 0, 'max' => 0, 'label' => '速度'],
    'security' => ['score' => 0, 'max' => 0, 'label' => 'セキュリティ'],
];

// Helper function
function addCheck(&$checks, &$score, &$maxScore, &$categoryScores, $key, $status, $label, $message, $points, $maxPoints, $category) {
    $checks[$key] = ['status' => $status, 'label' => $label, 'message' => $message, 'points' => $points, 'category' => $category];
    $score += $points;
    $maxScore += $maxPoints;
    $categoryScores[$category]['score'] += $points;
    $categoryScores[$category]['max'] += $maxPoints;
}

// ====== 基本設定 ======
if (!empty($siteName)) {
    addCheck($checks, $score, $maxScore, $categoryScores, 'site_name', 'ok', 'サイト名', $siteName, 10, 10, 'basic');
} else {
    addCheck($checks, $score, $maxScore, $categoryScores, 'site_name', 'error', 'サイト名', '未設定', 0, 10, 'basic');
}

if (!empty($siteDescription)) {
    $descLen = mb_strlen($siteDescription);
    if ($descLen >= 50 && $descLen <= 160) {
        addCheck($checks, $score, $maxScore, $categoryScores, 'site_description', 'ok', 'サイト説明', "{$descLen}文字", 10, 10, 'basic');
    } else {
        addCheck($checks, $score, $maxScore, $categoryScores, 'site_description', 'warning', 'サイト説明', "{$descLen}文字（推奨50〜160）", 5, 10, 'basic');
    }
} else {
    addCheck($checks, $score, $maxScore, $categoryScores, 'site_description', 'error', 'サイト説明', '未設定', 0, 10, 'basic');
}

if (!empty($siteUrl)) {
    addCheck($checks, $score, $maxScore, $categoryScores, 'site_url', 'ok', 'サイトURL', $siteUrl, 5, 5, 'basic');
} else {
    addCheck($checks, $score, $maxScore, $categoryScores, 'site_url', 'error', 'サイトURL', '未設定', 0, 5, 'basic');
}

// ====== OGP設定 ======
if (!empty($ogImage)) {
    $ogImagePath = '../' . ltrim($ogImage, '/');
    if (file_exists($ogImagePath)) {
        $imageInfo = @getimagesize($ogImagePath);
        if ($imageInfo && $imageInfo[0] >= 1200 && $imageInfo[1] >= 630) {
            addCheck($checks, $score, $maxScore, $categoryScores, 'og_image', 'ok', 'OGP画像', "{$imageInfo[0]}×{$imageInfo[1]}px", 10, 10, 'ogp');
        } else {
            addCheck($checks, $score, $maxScore, $categoryScores, 'og_image', 'warning', 'OGP画像', '推奨: 1200×630px以上', 5, 10, 'ogp');
        }
    } else {
        addCheck($checks, $score, $maxScore, $categoryScores, 'og_image', 'error', 'OGP画像', 'ファイルなし', 0, 10, 'ogp');
    }
} else {
    addCheck($checks, $score, $maxScore, $categoryScores, 'og_image', 'error', 'OGP画像', '未設定', 0, 10, 'ogp');
}

// OGP JPG版
if (!empty($ogImage) && strtolower(pathinfo($ogImage, PATHINFO_EXTENSION)) === 'webp') {
    $jpgPath = preg_replace('/\.webp$/i', '.jpg', '../' . ltrim($ogImage, '/'));
    if (file_exists($jpgPath)) {
        addCheck($checks, $score, $maxScore, $categoryScores, 'og_image_jpg', 'ok', 'OGP(JPG版)', 'X/Twitter用あり', 5, 5, 'ogp');
    } else {
        addCheck($checks, $score, $maxScore, $categoryScores, 'og_image_jpg', 'warning', 'OGP(JPG版)', 'WebPのJPG版なし', 0, 5, 'ogp');
    }
} elseif (!empty($ogImage)) {
    addCheck($checks, $score, $maxScore, $categoryScores, 'og_image_jpg', 'ok', 'OGP形式', strtoupper(pathinfo($ogImage, PATHINFO_EXTENSION)), 5, 5, 'ogp');
} else {
    $categoryScores['ogp']['max'] += 5;
    $maxScore += 5;
}

// Favicon
if (!empty($favicon) && file_exists('../' . ltrim($favicon, '/'))) {
    addCheck($checks, $score, $maxScore, $categoryScores, 'favicon', 'ok', 'ファビコン', '設定済み', 5, 5, 'ogp');
} else {
    addCheck($checks, $score, $maxScore, $categoryScores, 'favicon', 'warning', 'ファビコン', '未設定', 0, 5, 'ogp');
}

// ====== アクセス解析 ======
if (!empty($gaId)) {
    addCheck($checks, $score, $maxScore, $categoryScores, 'google_analytics', 'ok', 'Google Analytics', $gaId, 10, 10, 'analytics');
} else {
    addCheck($checks, $score, $maxScore, $categoryScores, 'google_analytics', 'warning', 'Google Analytics', '未設定', 0, 10, 'analytics');
}

if (!empty($gsc)) {
    addCheck($checks, $score, $maxScore, $categoryScores, 'search_console', 'ok', 'Search Console', '認証済み', 10, 10, 'analytics');
} else {
    addCheck($checks, $score, $maxScore, $categoryScores, 'search_console', 'warning', 'Search Console', '未設定', 0, 10, 'analytics');
}

// ====== コンテンツ品質 ======
try {
    $worksTotal = $db->query("SELECT COUNT(*) FROM works WHERE is_active = 1")->fetchColumn();
    $worksWithDesc = $db->query("SELECT COUNT(*) FROM works WHERE is_active = 1 AND description IS NOT NULL AND description != ''")->fetchColumn();
    if ($worksTotal > 0) {
        $descRate = round(($worksWithDesc / $worksTotal) * 100);
        $status = $descRate >= 80 ? 'ok' : ($descRate >= 50 ? 'warning' : 'error');
        $points = $descRate >= 80 ? 10 : ($descRate >= 50 ? 5 : 0);
        addCheck($checks, $score, $maxScore, $categoryScores, 'works_description', $status, '作品説明文', "{$descRate}%設定", $points, 10, 'content');
    } else {
        addCheck($checks, $score, $maxScore, $categoryScores, 'works_description', 'skip', '作品説明文', '作品なし', 0, 10, 'content');
    }
} catch (Exception $e) {
    addCheck($checks, $score, $maxScore, $categoryScores, 'works_description', 'skip', '作品説明文', 'チェック不可', 0, 10, 'content');
}

try {
    $articlesTotal = $db->query("SELECT COUNT(*) FROM articles WHERE is_active = 1")->fetchColumn();
    if ($articlesTotal > 0) {
        addCheck($checks, $score, $maxScore, $categoryScores, 'articles_count', 'ok', '記事コンテンツ', "{$articlesTotal}件", 10, 10, 'content');
    } else {
        addCheck($checks, $score, $maxScore, $categoryScores, 'articles_count', 'warning', '記事コンテンツ', 'なし', 0, 10, 'content');
    }
} catch (Exception $e) {
    addCheck($checks, $score, $maxScore, $categoryScores, 'articles_count', 'skip', '記事コンテンツ', 'チェック不可', 0, 10, 'content');
}

try {
    $creatorsTotal = $db->query("SELECT COUNT(*) FROM creators WHERE is_active = 1")->fetchColumn();
    $creatorsWithImage = $db->query("SELECT COUNT(*) FROM creators WHERE is_active = 1 AND profile_image IS NOT NULL AND profile_image != ''")->fetchColumn();
    $creatorsWithBio = $db->query("SELECT COUNT(*) FROM creators WHERE is_active = 1 AND bio IS NOT NULL AND bio != ''")->fetchColumn();
    if ($creatorsTotal > 0) {
        $imageRate = round(($creatorsWithImage / $creatorsTotal) * 100);
        $bioRate = round(($creatorsWithBio / $creatorsTotal) * 100);
        $status = ($imageRate >= 80 && $bioRate >= 80) ? 'ok' : (($imageRate >= 50 || $bioRate >= 50) ? 'warning' : 'error');
        $points = ($imageRate >= 80 && $bioRate >= 80) ? 10 : (($imageRate >= 50 || $bioRate >= 50) ? 5 : 0);
        addCheck($checks, $score, $maxScore, $categoryScores, 'creators_meta', $status, 'クリエイター情報', "画像{$imageRate}% 紹介{$bioRate}%", $points, 10, 'content');
    } else {
        addCheck($checks, $score, $maxScore, $categoryScores, 'creators_meta', 'skip', 'クリエイター情報', 'クリエイターなし', 0, 10, 'content');
    }
} catch (Exception $e) {
    addCheck($checks, $score, $maxScore, $categoryScores, 'creators_meta', 'skip', 'クリエイター情報', 'チェック不可', 0, 10, 'content');
}

// ====== 技術的SEO ======
if (file_exists('../robots.txt')) {
    $robotsContent = file_get_contents('../robots.txt');
    if (strpos($robotsContent, 'Sitemap:') !== false) {
        addCheck($checks, $score, $maxScore, $categoryScores, 'robots_txt', 'ok', 'robots.txt', 'サイトマップ指定あり', 5, 5, 'technical');
    } else {
        addCheck($checks, $score, $maxScore, $categoryScores, 'robots_txt', 'warning', 'robots.txt', 'サイトマップ指定なし', 3, 5, 'technical');
    }
} else {
    addCheck($checks, $score, $maxScore, $categoryScores, 'robots_txt', 'warning', 'robots.txt', '未作成', 0, 5, 'technical');
}

if (file_exists('../sitemap.xml') || file_exists('../sitemap.php')) {
    addCheck($checks, $score, $maxScore, $categoryScores, 'sitemap', 'ok', 'sitemap.xml', '存在します', 5, 5, 'technical');
} else {
    addCheck($checks, $score, $maxScore, $categoryScores, 'sitemap', 'warning', 'sitemap.xml', '未作成', 0, 5, 'technical');
}

if (file_exists('../manifest.json')) {
    $manifest = @json_decode(file_get_contents('../manifest.json'), true);
    if ($manifest && !empty($manifest['name'])) {
        addCheck($checks, $score, $maxScore, $categoryScores, 'manifest', 'ok', 'manifest.json', 'PWA対応', 5, 5, 'technical');
    } else {
        addCheck($checks, $score, $maxScore, $categoryScores, 'manifest', 'warning', 'manifest.json', '内容不正', 2, 5, 'technical');
    }
} else {
    addCheck($checks, $score, $maxScore, $categoryScores, 'manifest', 'warning', 'manifest.json', '未作成', 0, 5, 'technical');
}

$indexContent = @file_get_contents('../index.php');
if ($indexContent && strpos($indexContent, 'application/ld+json') !== false) {
    addCheck($checks, $score, $maxScore, $categoryScores, 'structured_data', 'ok', '構造化データ', 'JSON-LDあり', 5, 5, 'technical');
} else {
    addCheck($checks, $score, $maxScore, $categoryScores, 'structured_data', 'warning', '構造化データ', '未設定', 0, 5, 'technical');
}

if ($indexContent && strpos($indexContent, 'rel="canonical"') !== false) {
    addCheck($checks, $score, $maxScore, $categoryScores, 'canonical', 'ok', 'canonical URL', '設定あり', 5, 5, 'technical');
} else {
    addCheck($checks, $score, $maxScore, $categoryScores, 'canonical', 'warning', 'canonical URL', '未設定', 0, 5, 'technical');
}

// ====== パフォーマンス ======
try {
    $uploadsDir = '../uploads/';
    $webpCount = 0;
    $totalImages = 0;
    if (is_dir($uploadsDir)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsDir));
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $ext = strtolower($file->getExtension());
                if (in_array($ext, ['jpg', 'jpeg', 'png', 'gif', 'webp'])) {
                    $totalImages++;
                    if ($ext === 'webp') $webpCount++;
                }
            }
        }
    }
    if ($totalImages > 0) {
        $webpRate = round(($webpCount / $totalImages) * 100);
        $status = $webpRate >= 70 ? 'ok' : ($webpRate >= 30 ? 'warning' : 'error');
        $points = $webpRate >= 70 ? 10 : ($webpRate >= 30 ? 5 : 0);
        addCheck($checks, $score, $maxScore, $categoryScores, 'webp_usage', $status, 'WebP使用率', "{$webpRate}%", $points, 10, 'performance');
    } else {
        addCheck($checks, $score, $maxScore, $categoryScores, 'webp_usage', 'skip', 'WebP使用率', '画像なし', 0, 10, 'performance');
    }
} catch (Exception $e) {
    addCheck($checks, $score, $maxScore, $categoryScores, 'webp_usage', 'skip', 'WebP使用率', 'チェック不可', 0, 10, 'performance');
}

$htaccessContent = @file_get_contents('../.htaccess');
if ($htaccessContent && strpos($htaccessContent, 'mod_deflate') !== false) {
    addCheck($checks, $score, $maxScore, $categoryScores, 'gzip', 'ok', 'GZIP圧縮', '有効', 5, 5, 'performance');
} else {
    addCheck($checks, $score, $maxScore, $categoryScores, 'gzip', 'warning', 'GZIP圧縮', '未設定', 0, 5, 'performance');
}

if ($htaccessContent && strpos($htaccessContent, 'mod_expires') !== false) {
    addCheck($checks, $score, $maxScore, $categoryScores, 'browser_cache', 'ok', 'キャッシュ設定', '有効', 5, 5, 'performance');
} else {
    addCheck($checks, $score, $maxScore, $categoryScores, 'browser_cache', 'warning', 'キャッシュ設定', '未設定', 0, 5, 'performance');
}

if ($indexContent && strpos($indexContent, 'cdn.tailwindcss.com') !== false) {
    addCheck($checks, $score, $maxScore, $categoryScores, 'cdn_usage', 'warning', 'CSS配信', 'CDN（ビルド版推奨）', 3, 5, 'performance');
} else {
    addCheck($checks, $score, $maxScore, $categoryScores, 'cdn_usage', 'ok', 'CSS配信', 'ローカル', 5, 5, 'performance');
}

if ($indexContent && strpos($indexContent, 'loading="lazy"') !== false) {
    addCheck($checks, $score, $maxScore, $categoryScores, 'lazy_loading', 'ok', '遅延読み込み', '有効', 5, 5, 'performance');
} else {
    addCheck($checks, $score, $maxScore, $categoryScores, 'lazy_loading', 'warning', '遅延読み込み', '未設定', 0, 5, 'performance');
}

// ====== セキュリティ ======
if (!empty($siteUrl) && strpos($siteUrl, 'https://') === 0) {
    addCheck($checks, $score, $maxScore, $categoryScores, 'https', 'ok', 'HTTPS', 'SSL設定済み', 10, 10, 'security');
} else {
    addCheck($checks, $score, $maxScore, $categoryScores, 'https', 'error', 'HTTPS', '未設定', 0, 10, 'security');
}

if ($htaccessContent && (strpos($htaccessContent, 'X-Frame-Options') !== false || strpos($htaccessContent, 'FilesMatch') !== false)) {
    addCheck($checks, $score, $maxScore, $categoryScores, 'security_headers', 'ok', 'セキュリティ設定', '設定あり', 5, 5, 'security');
} else {
    addCheck($checks, $score, $maxScore, $categoryScores, 'security_headers', 'warning', 'セキュリティ設定', '要確認', 0, 5, 'security');
}

if (file_exists('.htaccess')) {
    addCheck($checks, $score, $maxScore, $categoryScores, 'admin_protection', 'ok', '管理画面保護', '.htaccessあり', 5, 5, 'security');
} else {
    addCheck($checks, $score, $maxScore, $categoryScores, 'admin_protection', 'warning', '管理画面保護', '.htaccessなし', 0, 5, 'security');
}

// スコア計算
$scorePercent = $maxScore > 0 ? round(($score / $maxScore) * 100) : 0;
if ($scorePercent >= 90) { $scoreGrade = 'A'; $scoreColor = 'text-green-600'; $scoreBg = 'bg-green-100'; $scoreMessage = '素晴らしい！SEO設定は最適化されています。'; }
elseif ($scorePercent >= 70) { $scoreGrade = 'B'; $scoreColor = 'text-blue-600'; $scoreBg = 'bg-blue-100'; $scoreMessage = '良好です。いくつかの改善点があります。'; }
elseif ($scorePercent >= 50) { $scoreGrade = 'C'; $scoreColor = 'text-yellow-600'; $scoreBg = 'bg-yellow-100'; $scoreMessage = '改善が必要です。'; }
else { $scoreGrade = 'D'; $scoreColor = 'text-red-600'; $scoreBg = 'bg-red-100'; $scoreMessage = '要改善。多くの設定が不足しています。'; }

$pageTitle = "SEOチェッカー";
include "includes/header.php";
?>
<div class="mb-8">
    <h2 class="text-2xl font-bold text-gray-800">SEOチェッカー v2.0</h2>
    <p class="text-gray-500">サイトのSEO・パフォーマンス・セキュリティを総合診断</p>
</div>

<div class="<?= $scoreBg ?> rounded-2xl p-8 mb-8 text-center">
    <div class="flex items-center justify-center gap-8">
        <div class="<?= $scoreColor ?>">
            <div class="text-8xl font-bold"><?= $scoreGrade ?></div>
            <div class="text-2xl"><?= $scorePercent ?>%</div>
        </div>
        <div class="text-left">
            <div class="text-xl font-bold text-gray-800">総合スコア: <?= $score ?> / <?= $maxScore ?></div>
            <p class="text-gray-600 mt-2"><?= $scoreMessage ?></p>
        </div>
    </div>
</div>

<div class="grid grid-cols-2 md:grid-cols-4 lg:grid-cols-7 gap-4 mb-8">
    <?php foreach ($categoryScores as $key => $cat): 
        $catPercent = $cat['max'] > 0 ? round(($cat['score'] / $cat['max']) * 100) : 0;
        $catColor = $catPercent >= 80 ? 'bg-green-500' : ($catPercent >= 50 ? 'bg-yellow-500' : 'bg-red-500');
    ?>
    <div class="bg-white rounded-xl p-4 shadow-sm text-center">
        <div class="text-xs text-gray-500 mb-1"><?= $cat['label'] ?></div>
        <div class="text-2xl font-bold"><?= $catPercent ?>%</div>
        <div class="w-full bg-gray-200 rounded-full h-2 mt-2">
            <div class="<?= $catColor ?> h-2 rounded-full" style="width: <?= $catPercent ?>%"></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="grid gap-6">
    <?php
    $categories = [
        'basic' => ['title' => '基本設定', 'icon' => 'fa-cog', 'color' => 'blue'],
        'ogp' => ['title' => 'OGP・画像設定', 'icon' => 'fa-image', 'color' => 'purple'],
        'analytics' => ['title' => 'アクセス解析', 'icon' => 'fa-chart-line', 'color' => 'green'],
        'content' => ['title' => 'コンテンツ品質', 'icon' => 'fa-file-alt', 'color' => 'orange'],
        'technical' => ['title' => '技術的SEO', 'icon' => 'fa-code', 'color' => 'indigo'],
        'performance' => ['title' => 'パフォーマンス', 'icon' => 'fa-tachometer-alt', 'color' => 'cyan'],
        'security' => ['title' => 'セキュリティ', 'icon' => 'fa-shield-alt', 'color' => 'red'],
    ];
    foreach ($categories as $catKey => $catInfo):
        $catChecks = array_filter($checks, fn($c) => ($c['category'] ?? '') === $catKey);
        if (empty($catChecks)) continue;
    ?>
    <div class="bg-white rounded-xl shadow-sm overflow-hidden">
        <div class="bg-gray-50 px-6 py-4 border-b">
            <h3 class="font-bold text-gray-700"><i class="fas <?= $catInfo['icon'] ?> mr-2"></i><?= $catInfo['title'] ?></h3>
        </div>
        <div class="divide-y">
            <?php foreach ($catChecks as $key => $check): ?>
            <div class="px-6 py-4 flex items-center justify-between hover:bg-gray-50">
                <div class="flex items-center gap-3">
                    <?php if ($check['status'] === 'ok'): ?>
                        <span class="w-8 h-8 rounded-full bg-green-100 text-green-600 flex items-center justify-center"><i class="fas fa-check"></i></span>
                    <?php elseif ($check['status'] === 'warning'): ?>
                        <span class="w-8 h-8 rounded-full bg-yellow-100 text-yellow-600 flex items-center justify-center"><i class="fas fa-exclamation"></i></span>
                    <?php elseif ($check['status'] === 'error'): ?>
                        <span class="w-8 h-8 rounded-full bg-red-100 text-red-600 flex items-center justify-center"><i class="fas fa-times"></i></span>
                    <?php else: ?>
                        <span class="w-8 h-8 rounded-full bg-gray-100 text-gray-400 flex items-center justify-center"><i class="fas fa-minus"></i></span>
                    <?php endif; ?>
                    <div>
                        <div class="font-medium text-gray-800"><?= htmlspecialchars($check['label']) ?></div>
                        <div class="text-sm text-gray-500"><?= htmlspecialchars($check['message']) ?></div>
                    </div>
                </div>
                <div class="text-sm font-medium <?= $check['points'] > 0 ? 'text-green-600' : 'text-gray-400' ?>">+<?= $check['points'] ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<div class="mt-8 bg-gray-50 rounded-xl p-6">
    <h3 class="font-bold text-gray-800 mb-4"><i class="fas fa-external-link-alt mr-2"></i>外部診断ツール</h3>
    <div class="grid md:grid-cols-2 lg:grid-cols-3 gap-4">
        <a href="https://pagespeed.web.dev/analysis?url=<?= urlencode($siteUrl ?: 'https://tokagemushi.jp') ?>" target="_blank" class="flex items-center gap-3 bg-white rounded-lg p-4 hover:shadow-md transition-shadow">
            <img src="https://www.gstatic.com/pagespeed/insights/ui/logo/favicon_48.png" alt="PageSpeed" class="w-8 h-8">
            <div><div class="font-medium">PageSpeed Insights</div><div class="text-sm text-gray-500">ページ速度診断</div></div>
        </a>
        <a href="https://search.google.com/search-console" target="_blank" class="flex items-center gap-3 bg-white rounded-lg p-4 hover:shadow-md transition-shadow">
            <i class="fas fa-search text-blue-600 text-2xl"></i>
            <div><div class="font-medium">Search Console</div><div class="text-sm text-gray-500">検索パフォーマンス</div></div>
        </a>
        <a href="https://cards-dev.twitter.com/validator" target="_blank" class="flex items-center gap-3 bg-white rounded-lg p-4 hover:shadow-md transition-shadow">
            <i class="fab fa-twitter text-blue-400 text-2xl"></i>
            <div><div class="font-medium">Twitter Card Validator</div><div class="text-sm text-gray-500">Xカード確認</div></div>
        </a>
        <a href="https://developers.facebook.com/tools/debug/" target="_blank" class="flex items-center gap-3 bg-white rounded-lg p-4 hover:shadow-md transition-shadow">
            <i class="fab fa-facebook text-blue-600 text-2xl"></i>
            <div><div class="font-medium">Facebook Debugger</div><div class="text-sm text-gray-500">OGP確認</div></div>
        </a>
        <a href="https://validator.w3.org/nu/?doc=<?= urlencode($siteUrl ?: 'https://tokagemushi.jp') ?>" target="_blank" class="flex items-center gap-3 bg-white rounded-lg p-4 hover:shadow-md transition-shadow">
            <i class="fas fa-code text-orange-600 text-2xl"></i>
            <div><div class="font-medium">W3C Validator</div><div class="text-sm text-gray-500">HTML検証</div></div>
        </a>
        <a href="https://www.ssllabs.com/ssltest/" target="_blank" class="flex items-center gap-3 bg-white rounded-lg p-4 hover:shadow-md transition-shadow">
            <i class="fas fa-lock text-green-600 text-2xl"></i>
            <div><div class="font-medium">SSL Labs</div><div class="text-sm text-gray-500">SSL証明書診断</div></div>
        </a>
    </div>
</div>

<div class="mt-8 bg-gradient-to-r from-purple-50 to-blue-50 rounded-xl p-6">
    <h3 class="font-bold text-gray-800 mb-4"><i class="fas fa-chart-line mr-2"></i>閲覧数を増やすためのガイド</h3>
    <div class="grid md:grid-cols-2 gap-6">
        <div>
            <h4 class="font-medium text-purple-700 mb-2">SEOで土台を固める</h4>
            <ul class="text-sm text-gray-600 space-y-1">
                <li>✓ このチェッカーの項目をすべて緑にする</li>
                <li>✓ Search Consoleでサイトマップを送信</li>
                <li>✓ PageSpeedスコアを50以上に改善</li>
            </ul>
        </div>
        <div>
            <h4 class="font-medium text-blue-700 mb-2">コンテンツを充実させる</h4>
            <ul class="text-sm text-gray-600 space-y-1">
                <li>✓ 定期的に新しい作品・記事を追加</li>
                <li>✓ 作品に詳しい説明文を追加</li>
                <li>✓ クリエイターのプロフィールを充実</li>
            </ul>
        </div>
        <div>
            <h4 class="font-medium text-green-700 mb-2">SNSで拡散する</h4>
            <ul class="text-sm text-gray-600 space-y-1">
                <li>✓ X/Twitterで作品を定期的に投稿</li>
                <li>✓ Pixivなど他プラットフォームと連携</li>
                <li>✓ ハッシュタグを活用</li>
            </ul>
        </div>
        <div>
            <h4 class="font-medium text-orange-700 mb-2">分析して改善する</h4>
            <ul class="text-sm text-gray-600 space-y-1">
                <li>✓ Google Analyticsで人気ページを分析</li>
                <li>✓ Search Consoleで検索キーワードを確認</li>
                <li>✓ 反応の良いコンテンツを増やす</li>
            </ul>
        </div>
    </div>
</div>

<?php include "includes/footer.php"; ?>
