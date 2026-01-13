<?php
/**
 * メディア関連の共通ユーティリティ
 */

/**
 * 画像パスに対応するWebMが存在するか判定する。
 *
 * @param string $imagePath 画像パス（/assets/... 等）
 * @param string|null $baseDir 判定基準のディレクトリ。指定がない場合はリポジトリルート。
 * @return bool
 */
function checkWebmExists(string $imagePath, ?string $baseDir = null): bool {
    if ($imagePath === '') {
        return false;
    }

    $baseDir = $baseDir ?? dirname(__DIR__);
    $path = '/' . ltrim($imagePath, '/');

    if (preg_match('/\.webm$/i', $path)) {
        return file_exists($baseDir . $path);
    }

    if (preg_match('/\.gif$/i', $path)) {
        $webmPath = preg_replace('/\.gif$/i', '.webm', $path);
        return file_exists($baseDir . $webmPath);
    }

    return false;
}

/**
 * 配列の各要素にwebm_existsフラグを付与する。
 *
 * @param array $items
 * @param string $imageKey
 * @param string $flagKey
 * @return array
 */
function appendWebmExistsFlag(array $items, string $imageKey, string $flagKey = 'webm_exists'): array {
    foreach ($items as $index => $item) {
        $items[$index][$flagKey] = checkWebmExists($item[$imageKey] ?? '');
    }

    return $items;
}
