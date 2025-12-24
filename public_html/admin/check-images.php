<?php
/**
 * 画像サイズ確認ツール
 */
require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAuth();

$uploadDir = dirname(__DIR__) . '/uploads/works/pages/';
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>画像サイズ確認 - 管理画面</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@400;500;700&display=swap" rel="stylesheet">
    <style>body { font-family: 'Zen Maru Gothic', sans-serif; }</style>
</head>
<body class="bg-gray-50">
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="lg:ml-64 p-8 pt-20 lg:pt-8">
        <h1 class="text-2xl font-bold mb-6"><i class="fas fa-chart-bar mr-2"></i>マンガ画像サイズ確認</h1>
        
        <div class="bg-white rounded-lg shadow p-6 mb-6">
        
        <?php
        // ディレクトリの合計サイズ
        $totalSize = 0;
        $files = [];
        
        if (is_dir($uploadDir)) {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($uploadDir)
            );
            
            foreach ($iterator as $file) {
                if ($file->isFile() && preg_match('/\.(webp|jpg|jpeg|png|gif)$/i', $file->getFilename())) {
                    $size = $file->getSize();
                    $totalSize += $size;
                    $files[] = [
                        'name' => $file->getFilename(),
                        'size' => $size,
                        'path' => $file->getPathname()
                    ];
                }
            }
            
            // サイズ順にソート
            usort($files, function($a, $b) {
                return $b['size'] - $a['size'];
            });
        }
        
        $totalMB = round($totalSize / 1024 / 1024, 2);
        $avgSize = count($files) > 0 ? round(($totalSize / count($files)) / 1024, 1) : 0;
        ?>
        
        <div class="grid grid-cols-3 gap-4 mb-6">
            <div class="bg-blue-50 p-4 rounded-lg text-center">
                <div class="text-3xl font-bold text-blue-600"><?= count($files) ?></div>
                <div class="text-sm text-gray-600">総ファイル数</div>
            </div>
            <div class="bg-green-50 p-4 rounded-lg text-center">
                <div class="text-3xl font-bold text-green-600"><?= $totalMB ?> MB</div>
                <div class="text-sm text-gray-600">合計サイズ</div>
            </div>
            <div class="bg-yellow-50 p-4 rounded-lg text-center">
                <div class="text-3xl font-bold text-yellow-600"><?= $avgSize ?> KB</div>
                <div class="text-sm text-gray-600">平均サイズ</div>
            </div>
        </div>
        
        <h2 class="text-xl font-bold mb-4">📁 大きいファイル TOP 30</h2>
        
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead class="bg-gray-100">
                    <tr>
                        <th class="p-2 text-left">#</th>
                        <th class="p-2 text-left">ファイル名</th>
                        <th class="p-2 text-right">サイズ</th>
                        <th class="p-2 text-center">状態</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach (array_slice($files, 0, 30) as $i => $file): ?>
                    <?php 
                    $sizeKB = round($file['size'] / 1024, 1);
                    $status = 'bg-green-100 text-green-800';
                    $statusText = 'OK';
                    if ($sizeKB > 500) {
                        $status = 'bg-red-100 text-red-800';
                        $statusText = '大きすぎ';
                    } elseif ($sizeKB > 200) {
                        $status = 'bg-yellow-100 text-yellow-800';
                        $statusText = 'やや大';
                    }
                    ?>
                    <tr class="border-b">
                        <td class="p-2"><?= $i + 1 ?></td>
                        <td class="p-2 font-mono text-xs"><?= htmlspecialchars($file['name']) ?></td>
                        <td class="p-2 text-right font-bold"><?= $sizeKB ?> KB</td>
                        <td class="p-2 text-center">
                            <span class="px-2 py-1 rounded text-xs <?= $status ?>"><?= $statusText ?></span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        
        <div class="mt-6 p-4 bg-gray-50 rounded-lg">
            <h3 class="font-bold mb-2">📝 目安</h3>
            <ul class="text-sm text-gray-600 space-y-1">
                <li>✅ 100KB以下: 快適</li>
                <li>⚠️ 200KB以下: 許容範囲</li>
                <li>🔴 500KB以上: 重たい、最適化推奨</li>
            </ul>
        </div>
        
        <div class="mt-6 flex gap-4">
            <a href="optimize-images.php" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded">
                <i class="fas fa-compress mr-1"></i>画像を最適化
            </a>
        </div>
        </div>
    </main>
</body>
</html>
