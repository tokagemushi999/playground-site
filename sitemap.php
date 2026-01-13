<?php
/**
 * サイトマップ自動生成
 * アクセスするとsitemap.xmlを動的に生成
 */

require_once 'includes/db.php';
require_once 'includes/site-settings.php';

$db = getDB();
$siteUrl = rtrim(getSiteSetting($db, 'site_url', 'https://tokagemushi.jp'), '/');

header('Content-Type: application/xml; charset=utf-8');

echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";

// トップページ
echo '    <url>' . "\n";
echo '        <loc>' . htmlspecialchars($siteUrl) . '/</loc>' . "\n";
echo '        <changefreq>daily</changefreq>' . "\n";
echo '        <priority>1.0</priority>' . "\n";
echo '    </url>' . "\n";

// お問い合わせ
echo '    <url>' . "\n";
echo '        <loc>' . htmlspecialchars($siteUrl) . '/contact.php</loc>' . "\n";
echo '        <changefreq>monthly</changefreq>' . "\n";
echo '        <priority>0.5</priority>' . "\n";
echo '    </url>' . "\n";

// 記事ページ
try {
    $stmt = $db->query("SELECT id, updated_at FROM articles WHERE is_active = 1 ORDER BY updated_at DESC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $lastmod = date('Y-m-d', strtotime($row['updated_at']));
        echo '    <url>' . "\n";
        echo '        <loc>' . htmlspecialchars($siteUrl) . '/article.php?id=' . $row['id'] . '</loc>' . "\n";
        echo '        <lastmod>' . $lastmod . '</lastmod>' . "\n";
        echo '        <changefreq>weekly</changefreq>' . "\n";
        echo '        <priority>0.8</priority>' . "\n";
        echo '    </url>' . "\n";
    }
} catch (Exception $e) {}

// 作品ページ（漫画ビューアー）
try {
    $stmt = $db->query("SELECT id, updated_at FROM works WHERE is_active = 1 ORDER BY updated_at DESC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $lastmod = date('Y-m-d', strtotime($row['updated_at']));
        echo '    <url>' . "\n";
        echo '        <loc>' . htmlspecialchars($siteUrl) . '/manga-viewer.php?id=' . $row['id'] . '</loc>' . "\n";
        echo '        <lastmod>' . $lastmod . '</lastmod>' . "\n";
        echo '        <changefreq>weekly</changefreq>' . "\n";
        echo '        <priority>0.8</priority>' . "\n";
        echo '    </url>' . "\n";
    }
} catch (Exception $e) {}

// クリエイターページ
try {
    $stmt = $db->query("SELECT id, updated_at FROM creators WHERE is_active = 1 ORDER BY updated_at DESC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $lastmod = date('Y-m-d', strtotime($row['updated_at']));
        echo '    <url>' . "\n";
        echo '        <loc>' . htmlspecialchars($siteUrl) . '/creator.php?id=' . $row['id'] . '</loc>' . "\n";
        echo '        <lastmod>' . $lastmod . '</lastmod>' . "\n";
        echo '        <changefreq>weekly</changefreq>' . "\n";
        echo '        <priority>0.7</priority>' . "\n";
        echo '    </url>' . "\n";
    }
} catch (Exception $e) {}

// ストアページ
echo '    <url>' . "\n";
echo '        <loc>' . htmlspecialchars($siteUrl) . '/store/</loc>' . "\n";
echo '        <changefreq>daily</changefreq>' . "\n";
echo '        <priority>0.9</priority>' . "\n";
echo '    </url>' . "\n";

echo '    <url>' . "\n";
echo '        <loc>' . htmlspecialchars($siteUrl) . '/store/faq.php</loc>' . "\n";
echo '        <changefreq>monthly</changefreq>' . "\n";
echo '        <priority>0.5</priority>' . "\n";
echo '    </url>' . "\n";

echo '    <url>' . "\n";
echo '        <loc>' . htmlspecialchars($siteUrl) . '/store/tokushoho.php</loc>' . "\n";
echo '        <changefreq>monthly</changefreq>' . "\n";
echo '        <priority>0.4</priority>' . "\n";
echo '    </url>' . "\n";

echo '    <url>' . "\n";
echo '        <loc>' . htmlspecialchars($siteUrl) . '/store/terms.php</loc>' . "\n";
echo '        <changefreq>monthly</changefreq>' . "\n";
echo '        <priority>0.4</priority>' . "\n";
echo '    </url>' . "\n";

echo '    <url>' . "\n";
echo '        <loc>' . htmlspecialchars($siteUrl) . '/store/privacy.php</loc>' . "\n";
echo '        <changefreq>monthly</changefreq>' . "\n";
echo '        <priority>0.4</priority>' . "\n";
echo '    </url>' . "\n";

// 商品ページ
try {
    $stmt = $db->query("SELECT id, updated_at FROM store_products WHERE is_active = 1 ORDER BY updated_at DESC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $lastmod = date('Y-m-d', strtotime($row['updated_at']));
        echo '    <url>' . "\n";
        echo '        <loc>' . htmlspecialchars($siteUrl) . '/store/product.php?id=' . $row['id'] . '</loc>' . "\n";
        echo '        <lastmod>' . $lastmod . '</lastmod>' . "\n";
        echo '        <changefreq>weekly</changefreq>' . "\n";
        echo '        <priority>0.7</priority>' . "\n";
        echo '    </url>' . "\n";
    }
} catch (Exception $e) {}

echo '</urlset>' . "\n";
