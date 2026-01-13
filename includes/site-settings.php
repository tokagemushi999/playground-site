<?php
/**
 * サイト設定取得ヘルパー
 */

/**
 * 画像パスを正規化（先頭に/を付ける）
 */
function normalizeImagePath($path) {
    if (empty($path)) return '';
    if (strpos($path, 'http') === 0) return $path;
    if (strpos($path, '/') === 0) return $path;
    return '/' . $path;
}

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

/**
 * OGP用の画像パスを取得（WebPはLINE/Twitter等で認識されないためJPG/PNGを優先）
 * 
 * @param string $imagePath 画像パス
 * @param string $baseUrl ベースURL（https://example.com）
 * @param string $defaultPath デフォルト画像パス
 * @return string 完全なOGP画像URL
 */
function getOgImageUrl($imagePath, $baseUrl, $defaultPath = '/assets/images/default-ogp.png') {
    if (empty($imagePath)) {
        return $baseUrl . $defaultPath;
    }
    
    $normalizedPath = normalizeImagePath($imagePath);
    
    // WebPの場合、JPG/PNG版を探す
    if (preg_match('/\.webp$/i', $normalizedPath)) {
        $jpgPath = preg_replace('/\.webp$/i', '.jpg', $normalizedPath);
        $pngPath = preg_replace('/\.webp$/i', '.png', $normalizedPath);
        $serverBasePath = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__);
        
        if (file_exists($serverBasePath . $jpgPath)) {
            return $baseUrl . $jpgPath;
        } elseif (file_exists($serverBasePath . $pngPath)) {
            return $baseUrl . $pngPath;
        }
        // JPG/PNG版がなければWebPをそのまま使用
    }
    
    return $baseUrl . $normalizedPath;
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
