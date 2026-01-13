<?php
/**
 * 管理画面共通ヘッダー
 * 
 * 使用方法: 
 * $pageTitle = 'ページタイトル';
 * $extraCss = '<style>...</style>'; // オプション
 * $extraHead = '...'; // オプション（追加のhead内容）
 * include 'includes/header.php';
 */

// 必要な変数が設定されていない場合のデフォルト値
$pageTitle = $pageTitle ?? 'ページ';
$extraCss = $extraCss ?? '';
$extraHead = $extraHead ?? '';
$pwaThemeColor = getSiteSetting($db, 'pwa_theme_color', '#ffffff'); 
$backyardFavicon = getBackyardFaviconInfo($db);
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="theme-color" content="<?= htmlspecialchars($pwaThemeColor) ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="ぷれぐら！管理">
    <meta name="mobile-web-app-capable" content="yes">
    <title><?= htmlspecialchars($pageTitle) ?> | 管理画面</title>
    <link rel="manifest" href="/admin/manifest.json">
    <link rel="icon" href="<?= htmlspecialchars($backyardFavicon['path']) ?>" type="<?= htmlspecialchars($backyardFavicon['type']) ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($backyardFavicon['path']) ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Zen Maru Gothic', sans-serif; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .tab-btn.active { background: #FBBF24; color: #1F2937; }
    </style>
    <?= $extraCss ?>
    <?= $extraHead ?>
</head>
<body class="bg-gray-50">
    <?php include __DIR__ . '/sidebar.php'; ?>
    
    <!-- Main Content -->
    <main class="lg:ml-64 p-4 sm:p-6 lg:p-8 pt-20 lg:pt-8">
