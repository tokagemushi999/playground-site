<?php
/**
 * マンガ画像最適化ツール
 * 大きすぎる画像をリサイズ＆WebP変換
 */
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAuth();

set_time_limit(600); // 10分
ini_set('memory_limit', '1024M');

$db = getDB();
$uploadDir = dirname(__DIR__) . '/uploads/works/pages/';

// 設定
$maxWidth = isset($_GET['width']) ? (int)$_GET['width'] : 1400; // 横幅の最大値
$quality = isset($_GET['quality']) ? (int)$_GET['quality'] : 82; // WebP品質
$dryRun = isset($_GET['dry-run']);
$limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 50; // 一度に処理する件数
$minSize = isset($_GET['min-size']) ? (int)$_GET['min-size'] * 1024 : 300 * 1024; // 最小サイズ（これ以上のみ処理）

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
    
    // サイズ順にソート
    usort($files, function($a, $b) {
        return $b['size'] - $a['size'];
    });
    
    // 上位N件を処理
    $files = array_slice($files, 0, $limit);
    
    foreach ($files as $file) {
        $srcPath = $file['path'];
        $originalSize = $file['size'];
        
        // 画像情報を取得
        $imageInfo = @getimagesize($srcPath);
        if (!$imageInfo) {
            $errors[] = $file['name'] . ': 画像情報を取得できません';
            continue;
        }
        
        $srcWidth = $imageInfo[0];
        $srcHeight = $imageInfo[1];
        $mimeType = $imageInfo['mime'];
        
        // ドライランの場合
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
        
        // 元画像を読み込み
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
                $errors[] = $file['name'] . ': 未対応の形式';
                continue 2;
        }
        
        if (!$srcImage) {
            $errors[] = $file['name'] . ': 画像を読み込めません';
            continue;
        }
        
        // リサイズが必要か判定
        $newWidth = $srcWidth;
        $newHeight = $srcHeight;
        
        if ($srcWidth > $maxWidth) {
            $ratio = $maxWidth / $srcWidth;
            $newWidth = $maxWidth;
            $newHeight = (int)($srcHeight * $ratio);
            
            // リサイズ
            $dstImage = imagecreatetruecolor($newWidth, $newHeight);
            imagealphablending($dstImage, false);
            imagesavealpha($dstImage, true);
            imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newWidth, $newHeight, $srcWidth, $srcHeight);
            imagedestroy($srcImage);
            $srcImage = $dstImage;
        }
        
        // WebPで保存（一時ファイル）
        $baseName = pathinfo($file['name'], PATHINFO_FILENAME);
        $webpPath = $uploadDir . $baseName . '.webp';
        $tempPath = $uploadDir . $baseName . '_temp.webp';
        
        if (!imagewebp($srcImage, $tempPath, $quality)) {
            imagedestroy($srcImage);
            $errors[] = $file['name'] . ': WebP変換に失敗';
            continue;
        }
        imagedestroy($srcImage);
        
        $newSize = filesize($tempPath);
        
        // 新しいファイルが小さい場合のみ置き換え
        if ($newSize < $originalSize) {
            // 元のファイルを削除（元がWebPでない場合）
            $ext = strtolower(pathinfo($srcPath, PATHINFO_EXTENSION));
            
            // WebPファイルとして配置
            if (file_exists($webpPath) && $webpPath !== $srcPath) {
                unlink($webpPath);
            }
            rename($tempPath, $webpPath);
            
            // 元ファイルがWebPでなければ削除
            if ($ext !== 'webp' && file_exists($srcPath)) {
                unlink($srcPath);
            }
            
            // DBを更新
            $oldDbPath = 'uploads/works/pages/' . $file['name'];
            $newDbPath = 'uploads/works/pages/' . $baseName . '.webp';
            $stmt = $db->prepare("UPDATE work_pages SET image = ? WHERE image = ?");
            $stmt->execute([$newDbPath, $oldDbPath]);
            
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
            // 新しいファイルの方が大きい場合は削除
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
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>画像最適化 - 管理画面</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@400;500;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Zen Maru Gothic', sans-serif; }</style>
</head>
<body class="bg-gray-50">
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="lg:ml-64 p-8 pt-20 lg:pt-8">
        <h1 class="text-2xl font-bold mb-6"><i class="fas fa-compress mr-2"></i>マンガ画像最適化ツール</h1>
        
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <?php if ($message): ?>
            <div class="mb-6 p-4 bg-green-100 text-green-800 rounded-lg">
                <?= htmlspecialchars($message) ?>
            </div>
            <?php endif; ?>
            
            <div class="mb-6 p-4 bg-blue-50 rounded-lg">
                <h2 class="font-bold text-blue-800 mb-2">📊 現在の状況</h2>
                <p class="text-blue-700">
                    <?= number_format($minSize / 1024) ?>KB以上の大きいファイル: <strong><?= $totalLargeFiles ?>件</strong>
                </p>
            </div>
            
            <form method="POST" class="mb-6">
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
                    <div>
                        <label class="block text-sm font-bold mb-1">最大横幅</label>
                        <select name="width" class="w-full border rounded p-2" onchange="location.href='?width='+this.value+'&quality=<?= $quality ?>&limit=<?= $limit ?>&min-size=<?= $minSize/1024 ?>'">
                            <option value="1200" <?= $maxWidth == 1200 ? 'selected' : '' ?>>1200px</option>
                            <option value="1400" <?= $maxWidth == 1400 ? 'selected' : '' ?>>1400px（推奨）</option>
                            <option value="1600" <?= $maxWidth == 1600 ? 'selected' : '' ?>>1600px</option>
                            <option value="1920" <?= $maxWidth == 1920 ? 'selected' : '' ?>>1920px</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-1">品質</label>
                        <select name="quality" class="w-full border rounded p-2" onchange="location.href='?width=<?= $maxWidth ?>&quality='+this.value+'&limit=<?= $limit ?>&min-size=<?= $minSize/1024 ?>'">
                            <option value="75" <?= $quality == 75 ? 'selected' : '' ?>>75（軽量）</option>
                            <option value="82" <?= $quality == 82 ? 'selected' : '' ?>>82（推奨）</option>
                            <option value="85" <?= $quality == 85 ? 'selected' : '' ?>>85（高品質）</option>
                            <option value="90" <?= $quality == 90 ? 'selected' : '' ?>>90（最高品質）</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-1">処理件数</label>
                        <select name="limit" class="w-full border rounded p-2" onchange="location.href='?width=<?= $maxWidth ?>&quality=<?= $quality ?>&limit='+this.value+'&min-size=<?= $minSize/1024 ?>'">
                            <option value="20" <?= $limit == 20 ? 'selected' : '' ?>>20件</option>
                            <option value="50" <?= $limit == 50 ? 'selected' : '' ?>>50件</option>
                            <option value="100" <?= $limit == 100 ? 'selected' : '' ?>>100件</option>
                            <option value="200" <?= $limit == 200 ? 'selected' : '' ?>>200件</option>
                        </select>
                    </div>
                    <div>
                        <label class="block text-sm font-bold mb-1">最小サイズ</label>
                        <select name="min-size" class="w-full border rounded p-2" onchange="location.href='?width=<?= $maxWidth ?>&quality=<?= $quality ?>&limit=<?= $limit ?>&min-size='+this.value">
                            <option value="200" <?= $minSize/1024 == 200 ? 'selected' : '' ?>>200KB以上</option>
                            <option value="300" <?= $minSize/1024 == 300 ? 'selected' : '' ?>>300KB以上</option>
                            <option value="500" <?= $minSize/1024 == 500 ? 'selected' : '' ?>>500KB以上</option>
                            <option value="1000" <?= $minSize/1024 == 1000 ? 'selected' : '' ?>>1MB以上</option>
                        </select>
                    </div>
                </div>
                
                <div class="flex gap-4">
                    <button type="submit" name="optimize" value="1" 
                            class="bg-red-500 hover:bg-red-600 text-white px-6 py-3 rounded-lg font-bold"
                            onclick="return confirm('画像を最適化します。元には戻せません。よろしいですか？')">
                        🚀 最適化を実行（上位<?= $limit ?>件）
                    </button>
                    <a href="?dry-run&width=<?= $maxWidth ?>&quality=<?= $quality ?>&limit=<?= $limit ?>&min-size=<?= $minSize/1024 ?>" 
                       class="bg-gray-500 hover:bg-gray-600 text-white px-6 py-3 rounded-lg font-bold">
                        👁️ プレビュー
                    </a>
                </div>
            </form>
            
            <?php if (!empty($results)): ?>
            <h2 class="text-xl font-bold mb-4">📋 処理結果</h2>
            <div class="overflow-x-auto mb-6">
                <table class="w-full text-sm">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="p-2 text-left">ファイル名</th>
                            <th class="p-2 text-right">元サイズ</th>
                            <th class="p-2 text-right">新サイズ</th>
                            <th class="p-2 text-right">削減</th>
                            <th class="p-2 text-center">解像度</th>
                            <th class="p-2 text-center">状態</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($results as $r): ?>
                        <tr class="border-b">
                            <td class="p-2 font-mono text-xs"><?= htmlspecialchars($r['name']) ?></td>
                            <td class="p-2 text-right"><?= round($r['original_size']/1024) ?>KB</td>
                            <td class="p-2 text-right"><?= isset($r['new_size']) ? round($r['new_size']/1024).'KB' : '-' ?></td>
                            <td class="p-2 text-right text-green-600 font-bold">
                                <?= isset($r['saved']) ? '-'.round($r['saved']/1024).'KB' : '-' ?>
                            </td>
                            <td class="p-2 text-center text-xs">
                                <?= $r['original_dim'] ?? '' ?> → <?= $r['new_dim'] ?? '' ?>
                            </td>
                            <td class="p-2 text-center">
                                <span class="px-2 py-1 rounded text-xs bg-blue-100 text-blue-800"><?= $r['status'] ?></span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            
            <h2 class="text-xl font-bold mb-4">📁 大きいファイル一覧（上位20件）</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="p-2 text-left">#</th>
                            <th class="p-2 text-left">ファイル名</th>
                            <th class="p-2 text-right">サイズ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach (array_slice($largeFiles, 0, 20) as $i => $f): ?>
                        <tr class="border-b">
                            <td class="p-2"><?= $i + 1 ?></td>
                            <td class="p-2 font-mono text-xs"><?= htmlspecialchars($f['name']) ?></td>
                            <td class="p-2 text-right font-bold text-red-600"><?= round($f['size']/1024) ?> KB</td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </main>
</body>
</html>
