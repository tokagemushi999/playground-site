<?php
/**
 * 画像処理ヘルパー
 * - WebP変換
 * - リサイズ
 * - 最適化
 */

class ImageHelper {
    // 最大幅/高さ（これより大きい画像はリサイズ）
    const MAX_WIDTH = 1920;
    const MAX_HEIGHT = 1920;
    
    // WebP品質（0-100）
    const WEBP_QUALITY = 85;
    
    // JPEG品質（0-100）
    const JPEG_QUALITY = 85;
    
    // サムネイル用の最大サイズ
    const THUMB_MAX_WIDTH = 800;
    const THUMB_MAX_HEIGHT = 800;
    
    /**
     * アップロードされた画像をWebPに変換して保存
     * GIFはアニメーションの可能性があるためそのまま保持
     * 
     * @param string $tmpFile アップロードされた一時ファイルパス
     * @param string $destDir 保存先ディレクトリ
     * @param string $baseName ファイル名（拡張子なし）
     * @param array $options オプション（resize, quality, keepOriginal）
     * @return array|false 成功時は ['webp' => path, 'original' => path]、失敗時はfalse
     */
    public static function processUpload($tmpFile, $destDir, $baseName, $options = []) {
        $resize = $options['resize'] ?? true;
        $quality = $options['quality'] ?? self::WEBP_QUALITY;
        $keepOriginal = $options['keepOriginal'] ?? false;
        $maxWidth = $options['maxWidth'] ?? self::MAX_WIDTH;
        $maxHeight = $options['maxHeight'] ?? self::MAX_HEIGHT;
        
        // ディレクトリが存在しなければ作成
        if (!is_dir($destDir)) {
            mkdir($destDir, 0755, true);
        }
        
        // 画像タイプを取得
        $imageInfo = @getimagesize($tmpFile);
        if ($imageInfo === false) {
            return false;
        }
        
        $mimeType = $imageInfo['mime'];
        $srcWidth = $imageInfo[0];
        $srcHeight = $imageInfo[1];
        
        // GIFはアニメーションの可能性があるためそのまま保存
        if ($mimeType === 'image/gif') {
            $gifPath = $destDir . '/' . $baseName . '.gif';
            if (move_uploaded_file($tmpFile, $gifPath) || copy($tmpFile, $gifPath)) {
                return [
                    'gif' => $gifPath,
                    'path' => $gifPath
                ];
            }
            return false;
        }
        
        // 元画像を読み込み
        $srcImage = self::createImageFromFile($tmpFile, $mimeType);
        if ($srcImage === false) {
            return false;
        }
        
        // リサイズが必要かチェック
        $newWidth = $srcWidth;
        $newHeight = $srcHeight;
        
        if ($resize && ($srcWidth > $maxWidth || $srcHeight > $maxHeight)) {
            $ratio = min($maxWidth / $srcWidth, $maxHeight / $srcHeight);
            $newWidth = (int)($srcWidth * $ratio);
            $newHeight = (int)($srcHeight * $ratio);
            
            // リサイズ
            $dstImage = imagecreatetruecolor($newWidth, $newHeight);
            
            // 透明度を保持（PNG/WebP用）
            imagealphablending($dstImage, false);
            imagesavealpha($dstImage, true);
            $transparent = imagecolorallocatealpha($dstImage, 0, 0, 0, 127);
            imagefilledrectangle($dstImage, 0, 0, $newWidth, $newHeight, $transparent);
            
            imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight);
            imagedestroy($srcImage);
            $srcImage = $dstImage;
        }
        
        $result = [];
        
        // WebPで保存
        $webpPath = $destDir . '/' . $baseName . '.webp';
        if (function_exists('imagewebp')) {
            imagewebp($srcImage, $webpPath, $quality);
            $result['webp'] = $webpPath;
            $result['path'] = $webpPath; // メインパス
        } else {
            // WebPが使えない場合はJPEGで保存
            $jpegPath = $destDir . '/' . $baseName . '.jpg';
            imagejpeg($srcImage, $jpegPath, self::JPEG_QUALITY);
            $result['jpeg'] = $jpegPath;
            $result['path'] = $jpegPath;
        }
        
        // 元の形式でも保存（オプション）
        if ($keepOriginal) {
            $ext = self::getExtensionFromMime($mimeType);
            $originalPath = $destDir . '/' . $baseName . '.' . $ext;
            self::saveImageAs($srcImage, $originalPath, $mimeType);
            $result['original'] = $originalPath;
        }
        
        imagedestroy($srcImage);
        
        return $result;
    }
    
    /**
     * 既存の画像ファイルをWebPに変換
     * 
     * @param string $srcPath 元ファイルパス
     * @param string $destPath 保存先パス（省略時は元ファイルと同じ場所に.webp）
     * @param int $quality 品質
     * @return string|false 成功時はWebPファイルパス、失敗時はfalse
     */
    public static function convertToWebP($srcPath, $destPath = null, $quality = null) {
        if (!file_exists($srcPath)) {
            return false;
        }
        
        $quality = $quality ?? self::WEBP_QUALITY;
        
        if ($destPath === null) {
            $pathInfo = pathinfo($srcPath);
            $destPath = $pathInfo['dirname'] . '/' . $pathInfo['filename'] . '.webp';
        }
        
        // 既にWebPなら何もしない
        $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
        if ($ext === 'webp') {
            return $srcPath;
        }
        
        $imageInfo = @getimagesize($srcPath);
        if ($imageInfo === false) {
            return false;
        }
        
        $srcImage = self::createImageFromFile($srcPath, $imageInfo['mime']);
        if ($srcImage === false) {
            return false;
        }
        
        // 透明度を保持
        imagealphablending($srcImage, true);
        imagesavealpha($srcImage, true);
        
        if (function_exists('imagewebp')) {
            imagewebp($srcImage, $destPath, $quality);
            imagedestroy($srcImage);
            return $destPath;
        }
        
        imagedestroy($srcImage);
        return false;
    }
    
    /**
     * ディレクトリ内の全画像をWebPに一括変換
     * 
     * @param string $dir 対象ディレクトリ
     * @param bool $recursive サブディレクトリも処理するか
     * @param bool $deleteOriginal 元ファイルを削除するか
     * @return array 変換結果 ['success' => [], 'failed' => [], 'skipped' => []]
     */
    public static function batchConvertToWebP($dir, $recursive = true, $deleteOriginal = false) {
        $result = [
            'success' => [],
            'failed' => [],
            'skipped' => []
        ];
        
        if (!is_dir($dir)) {
            return $result;
        }
        
        $extensions = ['jpg', 'jpeg', 'png']; // GIFはアニメーションの可能性があるため除外
        $iterator = $recursive 
            ? new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir))
            : new DirectoryIterator($dir);
        
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $ext = strtolower($file->getExtension());
                if (in_array($ext, $extensions)) {
                    $srcPath = $file->getPathname();
                    $webpPath = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $srcPath);
                    
                    // 既にWebP版が存在する場合はスキップ
                    if (file_exists($webpPath)) {
                        $result['skipped'][] = $srcPath;
                        continue;
                    }
                    
                    $converted = self::convertToWebP($srcPath, $webpPath);
                    if ($converted) {
                        $result['success'][] = [
                            'original' => $srcPath,
                            'webp' => $webpPath
                        ];
                        
                        if ($deleteOriginal) {
                            unlink($srcPath);
                        }
                    } else {
                        $result['failed'][] = $srcPath;
                    }
                }
            }
        }
        
        return $result;
    }
    
    /**
     * サムネイルを生成
     * 
     * @param string $srcPath 元画像パス
     * @param string $destPath 保存先パス
     * @param int $maxWidth 最大幅
     * @param int $maxHeight 最大高さ
     * @return bool
     */
    public static function createThumbnail($srcPath, $destPath, $maxWidth = null, $maxHeight = null) {
        $maxWidth = $maxWidth ?? self::THUMB_MAX_WIDTH;
        $maxHeight = $maxHeight ?? self::THUMB_MAX_HEIGHT;
        
        $imageInfo = @getimagesize($srcPath);
        if ($imageInfo === false) {
            return false;
        }
        
        $srcImage = self::createImageFromFile($srcPath, $imageInfo['mime']);
        if ($srcImage === false) {
            return false;
        }
        
        $srcWidth = $imageInfo[0];
        $srcHeight = $imageInfo[1];
        
        // リサイズ比率を計算
        $ratio = min($maxWidth / $srcWidth, $maxHeight / $srcHeight);
        if ($ratio >= 1) {
            // 元画像が小さい場合はそのまま
            $ratio = 1;
        }
        
        $newWidth = (int)($srcWidth * $ratio);
        $newHeight = (int)($srcHeight * $ratio);
        
        $dstImage = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($dstImage, false);
        imagesavealpha($dstImage, true);
        $transparent = imagecolorallocatealpha($dstImage, 0, 0, 0, 127);
        imagefilledrectangle($dstImage, 0, 0, $newWidth, $newHeight, $transparent);
        
        imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight);
        
        // WebPで保存
        $ext = strtolower(pathinfo($destPath, PATHINFO_EXTENSION));
        if ($ext === 'webp' && function_exists('imagewebp')) {
            imagewebp($dstImage, $destPath, self::WEBP_QUALITY);
        } else {
            imagejpeg($dstImage, $destPath, self::JPEG_QUALITY);
        }
        
        imagedestroy($srcImage);
        imagedestroy($dstImage);
        
        return true;
    }
    
    /**
     * MIMEタイプから画像リソースを作成
     */
    private static function createImageFromFile($filePath, $mimeType) {
        switch ($mimeType) {
            case 'image/jpeg':
                return imagecreatefromjpeg($filePath);
            case 'image/png':
                return imagecreatefrompng($filePath);
            case 'image/gif':
                return imagecreatefromgif($filePath);
            case 'image/webp':
                if (function_exists('imagecreatefromwebp')) {
                    return imagecreatefromwebp($filePath);
                }
                return false;
            default:
                return false;
        }
    }
    
    /**
     * MIMEタイプから拡張子を取得
     */
    private static function getExtensionFromMime($mimeType) {
        $map = [
            'image/jpeg' => 'jpg',
            'image/png' => 'png',
            'image/gif' => 'gif',
            'image/webp' => 'webp'
        ];
        return $map[$mimeType] ?? 'jpg';
    }
    
    /**
     * 指定形式で画像を保存
     */
    private static function saveImageAs($image, $path, $mimeType) {
        switch ($mimeType) {
            case 'image/jpeg':
                imagejpeg($image, $path, self::JPEG_QUALITY);
                break;
            case 'image/png':
                imagepng($image, $path, 6);
                break;
            case 'image/gif':
                imagegif($image, $path);
                break;
            case 'image/webp':
                if (function_exists('imagewebp')) {
                    imagewebp($image, $path, self::WEBP_QUALITY);
                }
                break;
        }
    }
    
    /**
     * 画像パスを正規化（WebP版があればそちらを返す）
     * 
     * @param string $path 画像パス
     * @param string $basePath サーバー上のベースパス
     * @return string
     */
    public static function getOptimizedPath($path, $basePath = '') {
        if (empty($path)) {
            return $path;
        }
        
        // 既にWebPの場合はそのまま
        if (preg_match('/\.webp$/i', $path)) {
            return $path;
        }
        
        // WebP版のパスを生成
        $webpPath = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $path);
        
        // サーバー上のフルパスでファイル存在チェック
        if ($basePath) {
            $fullWebpPath = rtrim($basePath, '/') . '/' . ltrim($webpPath, '/');
            if (file_exists($fullWebpPath)) {
                return $webpPath;
            }
        }
        
        return $path;
    }
}
