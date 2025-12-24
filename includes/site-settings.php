<?php
/**
 * サイト設定の共通ヘルパー
 */

require_once __DIR__ . '/db.php';

if (!function_exists('getSiteSetting')) {
    function getSiteSetting($db, $key, $default = '') {
        try {
            $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
            $stmt->execute([$key]);
            $result = $stmt->fetchColumn();
            return $result !== false ? $result : $default;
        } catch (Exception $e) {
            error_log("Error fetching site setting '{$key}': " . $e->getMessage());
            return $default;
        }
    }
}

function normalizeSiteAssetPath($path, $fallback) {
    $trimmed = trim((string)$path);
    if ($trimmed === '') {
        return $fallback;
    }
    if (strpos($trimmed, 'http://') === 0 || strpos($trimmed, 'https://') === 0 || strpos($trimmed, '/') === 0) {
        return $trimmed;
    }
    return '/' . ltrim($trimmed, '/');
}

function getSiteFaviconData($db, $fallback = '/favicon.png') {
    $favicon = normalizeSiteAssetPath(getSiteSetting($db, 'favicon', $fallback), $fallback);
    $path = parse_url($favicon, PHP_URL_PATH) ?: $favicon;
    $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    $type = match ($ext) {
        'svg' => 'image/svg+xml',
        'ico' => 'image/x-icon',
        default => 'image/png',
    };

    return [
        'href' => $favicon,
        'type' => $type,
        'apple_touch' => $favicon,
    ];
}
