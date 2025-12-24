<?php
/**
 * サイト設定取得ヘルパー
 */

function getSiteSetting($db, $key, $default = '') {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : $default;
    } catch (Exception $e) {
        error_log("Error fetching site setting '$key': " . $e->getMessage());
        return $default;
    }
}

function normalizeSiteAssetPath($path) {
    if (empty($path)) {
        return '';
    }

    if (preg_match('/^https?:\\/\\//', $path)) {
        return $path;
    }

    return '/' . ltrim($path, '/');
}

function getFaviconInfo($db, $default = '/favicon.png') {
    $favicon = getSiteSetting($db, 'favicon', $default);
    $faviconPath = normalizeSiteAssetPath($favicon ?: $default);
    $ext = strtolower(pathinfo($faviconPath, PATHINFO_EXTENSION));

    $type = match ($ext) {
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'webp' => 'image/webp',
        'jpg', 'jpeg' => 'image/jpeg',
        default => 'image/png',
    };

    return [
        'path' => $faviconPath,
        'type' => $type,
    ];
}

function getBackyardFaviconInfo($db, $default = '/favicon.png') {
    $favicon = getSiteSetting($db, 'favicon', $default);
    $faviconPath = normalizeSiteAssetPath($favicon ?: $default);

    if (preg_match('/^https?:\\/\\//', $faviconPath)) {
        $backyardPath = $faviconPath;
    } else {
        $info = pathinfo($faviconPath);
        $extension = $info['extension'] ?? '';
        $filename = $info['filename'] ?? 'favicon';
        $dirname = $info['dirname'] ?? '';

        if ($extension !== '') {
            $backyardPath = rtrim($dirname, '/') . '/' . $filename . '-backyard.' . $extension;
        } else {
            $backyardPath = $faviconPath;
        }
    }

    if (!preg_match('/^https?:\\/\\//', $backyardPath)) {
        $localPath = dirname(__DIR__) . $backyardPath;
        if (!file_exists($localPath)) {
            $backyardPath = $faviconPath;
        }
    }

    $ext = strtolower(pathinfo($backyardPath, PATHINFO_EXTENSION));
    $type = match ($ext) {
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        'webp' => 'image/webp',
        'jpg', 'jpeg' => 'image/jpeg',
        default => 'image/png',
    };

    return [
        'path' => $backyardPath,
        'type' => $type,
    ];
}
