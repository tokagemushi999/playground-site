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
     * OGP用にJPG版も同時生成
     * 
     * @param string $tmpFile アップロードされた一時ファイルパス
     * @param string $destDir 保存先ディレクトリ
     * @param string $baseName ファイル名（拡張子なし）
     * @param array $options オプション（resize, quality, keepOriginal, generateJpg）
     * @return array|false 成功時は ['webp' => path, 'original' => path, 'jpg' => path]、失敗時はfalse
     */
    public static function processUpload($tmpFile, $destDir, $baseName, $options = []) {
        $resize = $options['resize'] ?? true;
        $quality = $options['quality'] ?? self::WEBP_QUALITY;
        $keepOriginal = $options['keepOriginal'] ?? false;
        $generateJpg = $options['generateJpg'] ?? true; // OGP用にデフォルトでJPGも生成
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
        
        // OGP用にJPG版も生成（LINE/Twitter/Slack等はWebPを認識しないため）
        if ($generateJpg && function_exists('imagewebp')) {
            $jpgPath = $destDir . '/' . $baseName . '.jpg';
            imagejpeg($srcImage, $jpgPath, self::JPEG_QUALITY);
            $result['jpg'] = $jpgPath;
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
    
    /**
     * GIFが動画に変換済みかチェックし、動画版のパスを返す
     * 
     * @param string $path 画像パス
     * @param string $basePath サーバー上のベースパス（省略時は自動検出）
     * @return array ['type' => 'image'|'video', 'src' => path, 'webm' => path|null, 'mp4' => path|null, 'gif' => path|null]
     */
    public static function getMediaInfo($path, $basePath = null) {
        if (empty($path)) {
            return ['type' => 'image', 'src' => ''];
        }
        
        // ベースパスの自動検出
        if ($basePath === null) {
            $basePath = dirname(__DIR__); // includesの親ディレクトリ = public_html
        }
        
        // GIF以外は通常の画像として返す
        if (!preg_match('/\.gif$/i', $path)) {
            return ['type' => 'image', 'src' => $path];
        }
        
        // 動画版のパスを生成
        $webmPath = preg_replace('/\.gif$/i', '.webm', $path);
        $mp4Path = preg_replace('/\.gif$/i', '.mp4', $path);
        
        // フルパスでファイル存在チェック
        $fullPath = rtrim($basePath, '/') . '/' . ltrim($path, '/');
        $fullWebmPath = rtrim($basePath, '/') . '/' . ltrim($webmPath, '/');
        $fullMp4Path = rtrim($basePath, '/') . '/' . ltrim($mp4Path, '/');
        
        $hasWebm = file_exists($fullWebmPath);
        $hasMp4 = file_exists($fullMp4Path);
        $hasGif = file_exists($fullPath);
        
        // 動画版が存在する場合
        if ($hasWebm || $hasMp4) {
            return [
                'type' => 'video',
                'src' => $hasWebm ? $webmPath : $mp4Path,
                'webm' => $hasWebm ? $webmPath : null,
                'mp4' => $hasMp4 ? $mp4Path : null,
                'gif' => $hasGif ? $path : null,
            ];
        }
        
        // 動画版がない場合は通常のGIFとして返す
        return ['type' => 'image', 'src' => $path];
    }
    
    /**
     * 画像または動画を表示するHTMLを生成
     * 動画の場合はループ・自動再生・ミュートで表示
     * 
     * @param string $path 画像/動画パス
     * @param string $alt 代替テキスト
     * @param string $class CSSクラス
     * @param string $style インラインスタイル
     * @param string $basePath サーバー上のベースパス
     * @return string HTML
     */
    public static function renderMedia($path, $alt = '', $class = '', $style = '', $basePath = null) {
        $media = self::getMediaInfo($path, $basePath);
        
        $classAttr = $class ? ' class="' . htmlspecialchars($class) . '"' : '';
        $styleAttr = $style ? ' style="' . htmlspecialchars($style) . '"' : '';
        $altAttr = htmlspecialchars($alt);
        
        if ($media['type'] === 'video') {
            // 動画として表示
            $html = '<video autoplay loop muted playsinline' . $classAttr . $styleAttr . '>';
            if ($media['webm']) {
                $html .= '<source src="' . htmlspecialchars($media['webm']) . '" type="video/webm">';
            }
            if ($media['mp4']) {
                $html .= '<source src="' . htmlspecialchars($media['mp4']) . '" type="video/mp4">';
            }
            // フォールバック: 動画非対応ブラウザ向けにGIF
            if ($media['gif']) {
                $html .= '<img src="' . htmlspecialchars($media['gif']) . '" alt="' . $altAttr . '"' . $classAttr . $styleAttr . '>';
            }
            $html .= '</video>';
            return $html;
        }
        
        // 通常の画像として表示
        return '<img src="' . htmlspecialchars($media['src']) . '" alt="' . $altAttr . '"' . $classAttr . $styleAttr . ' loading="lazy">';
    }
}

/**
 * 画像パスからメディアHTMLを生成（シンプル版）
 * .webmまたは.gifパスを受け取り、適切なタグを返す
 * - .webm/.mp4 → <video>タグ
 * - .gif → 同名の.webmがあれば<video>、なければ<img>
 * - その他 → <img>タグ
 * 
 * @param string $path 画像/動画パス
 * @param string $class CSSクラス
 * @param string $alt alt属性
 * @param string $style インラインスタイル
 * @return string HTML
 */
function renderMediaTag($path, $class = '', $alt = '', $style = '') {
    if (empty($path)) {
        return '';
    }
    
    $baseDir = dirname(__DIR__); // public_html
    $path = '/' . ltrim($path, '/');
    
    $classAttr = $class ? ' class="' . htmlspecialchars($class) . '"' : '';
    $styleAttr = $style ? ' style="' . htmlspecialchars($style) . '"' : '';
    $altAttr = htmlspecialchars($alt);
    
    // ループ再生用の属性
    $videoEvents = ' onloadedmetadata="this.play()" onended="this.currentTime=0;this.play()"';
    
    // .webmファイルの場合 → videoタグ（大文字小文字両対応）
    if (preg_match('/\.webm$/i', $path)) {
        // 元のGIFパスを生成（大文字小文字両方チェック）
        $gifPathLower = preg_replace('/\.webm$/i', '.gif', $path);
        $gifPathUpper = preg_replace('/\.webm$/i', '.GIF', $path);
        $fallback = '';
        if (file_exists($baseDir . $gifPathLower)) {
            $fallback = '<img src="' . htmlspecialchars($gifPathLower) . '" alt="' . $altAttr . '"' . $classAttr . $styleAttr . '>';
        } elseif (file_exists($baseDir . $gifPathUpper)) {
            $fallback = '<img src="' . htmlspecialchars($gifPathUpper) . '" alt="' . $altAttr . '"' . $classAttr . $styleAttr . '>';
        }
        return '<video autoplay loop muted playsinline' . $classAttr . $styleAttr . $videoEvents . '>'
             . '<source src="' . htmlspecialchars($path) . '" type="video/webm">'
             . $fallback
             . '</video>';
    }
    
    // .mp4ファイルの場合 → videoタグ
    if (preg_match('/\.mp4$/i', $path)) {
        return '<video autoplay loop muted playsinline' . $classAttr . $styleAttr . $videoEvents . '>'
             . '<source src="' . htmlspecialchars($path) . '" type="video/mp4">'
             . '</video>';
    }
    
    // .gifファイルの場合 → 同名.webmがあればvideo、なければimg（大文字小文字両対応）
    if (preg_match('/\.gif$/i', $path)) {
        $webmPath = preg_replace('/\.gif$/i', '.webm', $path);
        $fullWebmPath = $baseDir . $webmPath;
        
        if (file_exists($fullWebmPath)) {
            return '<video autoplay loop muted playsinline' . $classAttr . $styleAttr . $videoEvents . '>'
                 . '<source src="' . htmlspecialchars($webmPath) . '" type="video/webm">'
                 . '<img src="' . htmlspecialchars($path) . '" alt="' . $altAttr . '"' . $classAttr . $styleAttr . '>'
                 . '</video>';
        }
    }
    
    // その他 → imgタグ
    return '<img src="' . htmlspecialchars($path) . '" alt="' . $altAttr . '"' . $classAttr . $styleAttr . ' loading="lazy">';
}

/**
 * WebMの存在チェック
 * - .webmの場合は実体の存在
 * - .gifの場合は同名の.webmの存在
 *
 * @param string $path 画像/動画パス
 * @param string|null $basePath サーバー上のベースパス（省略時は自動検出）
 * @return bool
 */
function checkWebmExists($path, $basePath = null) {
    if (empty($path)) {
        return false;
    }

    if ($basePath === null) {
        $basePath = dirname(__DIR__); // public_html
    }

    $path = '/' . ltrim($path, '/');

    if (preg_match('/\.webm$/i', $path)) {
        return file_exists(rtrim($basePath, '/') . $path);
    }

    if (preg_match('/\.gif$/i', $path)) {
        $webmPath = preg_replace('/\.gif$/i', '.webm', $path);
        return file_exists(rtrim($basePath, '/') . $webmPath);
    }

    return false;
}
