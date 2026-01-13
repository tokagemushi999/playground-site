<?php
/**
 * ストアPWA用メタタグ
 * 各ページの<head>内で include する
 */
$db = getDB();
$faviconInfo = function_exists('getFaviconInfo') ? getFaviconInfo($db) : ['path' => '/uploads/site/favicon.png', 'type' => 'image/png'];
?>
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="theme-color" content="#FF6B35">
    <link rel="icon" href="<?= htmlspecialchars($faviconInfo['path']) ?>" type="<?= htmlspecialchars($faviconInfo['type']) ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($faviconInfo['path']) ?>">
    <link rel="manifest" href="/store/manifest.json">
