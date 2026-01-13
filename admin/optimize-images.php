<?php
/**
 * マンガ画像最適化ツール
 * 大きすぎる画像をリサイズ＆WebP変換
 */
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/site-settings.php';
requireAuth();

set_time_limit(600); // 10分
ini_set('memory_limit', '1024M');

$db = getDB();
$uploadDir = dirname(__DIR__) . '/uploads/works/pages/';

// 設定
$maxWidth = isset($_GET['width']) ? (int)$_GET['width'] : 1400;
$quality = isset($_GET['quality']) ? (int)$_GET['quality'] : 82;
$dryRun = isset($_GET['dry-run']);
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50;
$minSize = isset($_GET['min-size']) ? (int)$_GET['min-size'] * 1024 : 300 * 1024;

$message = '';
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['optimize'])) {
    $processedCount = 0;
    $savedBytes = 0;
    $errors = [];
    
    // 大きいファイルを取得
    $files = [];
    if (is_dir($uploadDir)) {
        $iterator = new DirectoryIterator($uploadDir);
        foreach ($iterator as $file) {
            if ($file->isFile() && preg_match('/\.(jpg|jpeg|png|webp)$/i', $file->getFilename())) {
                $size = $file->getSize();
                if ($size >= $minSize) {
                    $files[] = [
                        'name' => $file->getFilename(),
                        'path' => $file->getPathname(),
                        'size' => $size
                    ];
                }
            }
        }
    }
    
    usort($files, function($a, $b) { return $b['size'] - $a['size']; });
    $files = array_slice($files, 0, $limit);
    
    foreach ($files as $file) {
        $srcPath = $file['path'];
        $originalSize = $file['size'];
        
        $imageInfo = @getimagesize($srcPath);
        if (!$imageInfo) {
            $errors[] = $file['name'] . ': 画像情報を取得できません';
            continue;
        }
        
        $srcWidth = $imageInfo[0];
        $srcHeight = $imageInfo[1];
        $mimeType = $imageInfo['mime'];
        
        if ($dryRun) {
            $newWidth = $srcWidth > $maxWidth ? $maxWidth : $srcWidth;
            $ratio = $newWidth / $srcWidth;
            $newHeight = (int)($srcHeight * $ratio);
            
            $results[] = [
                'name' => $file['name'],
                'original_size' => $originalSize,
                'original_dim' => "{$srcWidth}x{$srcHeight}",
                'new_dim' => "{$newWidth}x{$newHeight}",
                'status' => 'プレビュー'
            ];
            continue;
        }
        
        switch ($mimeType) {
            case 'image/jpeg':
                $srcImage = @imagecreatefromjpeg($srcPath);
                break;
            case 'image/png':
                $srcImage = @imagecreatefrompng($srcPath);
                break;
            case 'image/webp':
                $srcImage = @imagecreatefromwebp($srcPath);
                break;
            default:
                $errors[] = $file['name'] . ': 非対応形式';
                continue 2;
        }
        
        if (!$srcImage) {
            $errors[] = $file['name'] . ': 画像を読み込めません';
            continue;
        }
        
        $newWidth = $srcWidth > $maxWidth ? $maxWidth : $srcWidth;
        $ratio = $newWidth / $srcWidth;
        $newHeight = (int)($srcHeight * $ratio);
        
        $newImage = imagecreatetruecolor($newWidth, $newHeight);
        imagealphablending($newImage, false);
        imagesavealpha($newImage, true);
        
        imagecopyresampled($newImage, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight);
        
        $tempPath = $srcPath . '.temp.webp';
        imagewebp($newImage, $tempPath, $quality);
        
        imagedestroy($srcImage);
        imagedestroy($newImage);
        
        $newSize = filesize($tempPath);
        
        if ($newSize < $originalSize) {
            $webpPath = preg_replace('/\.(jpg|jpeg|png|webp)$/i', '.webp', $srcPath);
            rename($tempPath, $webpPath);
            
            if ($webpPath !== $srcPath && file_exists($srcPath)) {
                unlink($srcPath);
            }
            
            $saved = $originalSize - $newSize;
            $savedBytes += $saved;
            $processedCount++;
            
            $results[] = [
                'name' => $file['name'],
                'original_size' => $originalSize,
                'new_size' => $newSize,
                'saved' => $saved,
                'original_dim' => "{$srcWidth}x{$srcHeight}",
                'new_dim' => "{$newWidth}x{$newHeight}",
                'status' => '最適化完了'
            ];
        } else {
            unlink($tempPath);
            $results[] = [
                'name' => $file['name'],
                'original_size' => $originalSize,
                'new_size' => $newSize,
                'status' => 'スキップ（圧縮効果なし）'
            ];
        }
    }
    
    $savedMB = round($savedBytes / 1024 / 1024, 2);
    $message = "処理完了: {$processedCount}件最適化、{$savedMB}MB削減";
    if (!empty($errors)) {
        $message .= " / エラー: " . count($errors) . "件";
    }
}

// 現在の大きいファイル一覧を取得
$largeFiles = [];
if (is_dir($uploadDir)) {
    $iterator = new DirectoryIterator($uploadDir);
    foreach ($iterator as $file) {
        if ($file->isFile() && preg_match('/\.(jpg|jpeg|png|webp)$/i', $file->getFilename())) {
            $size = $file->getSize();
            if ($size >= $minSize) {
                $largeFiles[] = [
                    'name' => $file->getFilename(),
                    'size' => $size
                ];
            }
        }
    }
}
usort($largeFiles, function($a, $b) { return $b['size'] - $a['size']; });
$totalLargeFiles = count($largeFiles);

$pageTitle = '画像最適化';
include 'includes/header.php';
?>
        <h1 class="text-2xl font-bold mb-6"><i class="fas fa-compress mr-2"></i>マンガ画像最適化ツール</h1>
        
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <?php if ($message): ?>
            <div class="mb-4 p-3 bg-green-100 text-green-800 rounded">
                <i class="fas fa-check-circle mr-1"></i><?= htmlspecialchars($message) ?>
            </div>
            <?php endif; ?>
            
            <!-- 設定フォーム -->
            <form method="GET" class="mb-6 p-4 bg-gray-50 rounded-lg">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-bold mb-1">最大横幅</label>
                        <input type="number" name="width" value="<?= $maxWidth ?>" class="w-full p-2 border rounded">
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-1">品質 (1-100)</label>
                        <input type="number" name="quality" value="<?= $quality ?>" min="1" max="100" class="w-full p-2 border rounded">
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-1">処理件数</label>
                        <input type="number" name="limit" value="<?= $limit ?>" class="w-full p-2 border rounded">
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-1">最小サイズ (KB)</label>
                        <input type="number" name="min-size" value="<?= $minSize/1024 ?>" class="w-full p-2 border rounded">
                    </div>
                </div>
                <div class="flex gap-2">
                    <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded">
                        <i class="fas fa-cog mr-1"></i>設定を更新
                    </button>
                    <button type="submit" name="dry-run" value="1" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded">
                        <i class="fas fa-search mr-1"></i>プレビュー
                    </button>
                </div>
            </form>
            
            <!-- 最適化実行 -->
            <form method="POST" class="mb-6">
                <input type="hidden" name="width" value="<?= $maxWidth ?>">
                <input type="hidden" name="quality" value="<?= $quality ?>">
                <input type="hidden" name="limit" value="<?= $limit ?>">
                <button type="submit" name="optimize" class="bg-red-500 hover:bg-red-600 text-white px-6 py-3 rounded font-bold"
                    onclick="return confirm('上位<?= $limit ?>件の大きいファイルを最適化します。よろしいですか？')">
                    <i class="fas fa-compress mr-1"></i>最適化を実行（上位<?= $limit ?>件）
                </button>
            </form>
            
            <!-- 処理結果 -->
            <?php if (!empty($results)): ?>
            <div class="mb-6">
                <h2 class="text-xl font-bold mb-4">処理結果</h2>
                <table class="w-full text-sm">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="p-2 text-left">ファイル名</th>
                            <th class="p-2 text-right">元サイズ</th>
                            <th class="p-2 text-right">新サイズ</th>
                            <th class="p-2 text-right">削減</th>
                            <th class="p-2 text-left">状態</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $r): ?>
                        <tr class="border-b">
                            <td class="p-2 font-mono text-xs"><?= htmlspecialchars($r['name']) ?></td>
                            <td class="p-2 text-right"><?= round($r['original_size']/1024) ?> KB</td>
                            <td class="p-2 text-right"><?= isset($r['new_size']) ? round($r['new_size']/1024) . ' KB' : '-' ?></td>
                            <td class="p-2 text-right font-bold text-green-600"><?= isset($r['saved']) ? round($r['saved']/1024) . ' KB' : '-' ?></td>
                            <td class="p-2">
                                <span class="px-2 py-1 rounded text-xs <?= strpos($r['status'], '完了') !== false ? 'bg-green-100 text-green-800' : 'bg-gray-100 text-gray-800' ?>">
                                    <?= $r['status'] ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <!-- 現在の大きいファイル -->
            <h2 class="text-xl font-bold mb-4">
                最適化対象ファイル（<?= $totalLargeFiles ?>件）
                <span class="text-sm font-normal text-gray-500">- <?= $minSize/1024 ?>KB以上</span>
            </h2>
            
            <?php if (empty($largeFiles)): ?>
            <p class="text-green-600 font-bold"><i class="fas fa-check-circle mr-1"></i>最適化が必要なファイルはありません</p>
            <?php else: ?>
            <div class="overflow-auto max-h-96">
                <table class="w-full text-sm">
                    <thead class="bg-gray-100 sticky top-0">
                        <tr>
                            <th class="p-2 text-left">#</th>
                            <th class="p-2 text-left">ファイル名</th>
                            <th class="p-2 text-right">サイズ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($largeFiles, 0, 100) as $i => $f): ?>
                        <tr class="border-b">
                            <td class="p-2"><?= $i + 1 ?></td>
                            <td class="p-2 font-mono text-xs"><?= htmlspecialchars($f['name']) ?></td>
                            <td class="p-2 text-right font-bold text-red-600"><?= round($f['size']/1024) ?> KB</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>

<?php include 'includes/footer.php'; ?>
