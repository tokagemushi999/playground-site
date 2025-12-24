<?php
/**
 * GIF画像のDBパスを修復するスクリプト
 * WebPに更新されたGIFのパスを元のGIFに戻す
 */

require_once '../includes/db.php';
require_once '../includes/auth.php';

if (php_sapi_name() !== 'cli') {
    requireAuth();
}

$db = getDB();
$isCli = php_sapi_name() === 'cli';

function output($message, $isCli) {
    if ($isCli) {
        echo $message . "\n";
    } else {
        echo htmlspecialchars($message) . "<br>\n";
    }
}

if (!$isCli) {
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <title>GIF修復 - 管理画面</title>
    <?php include 'includes/site-head.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-50">
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="lg:ml-64 p-8 pt-20 lg:pt-8">
        <div class="max-w-4xl">
            <h1 class="text-2xl font-bold mb-6"><i class="fas fa-wrench mr-2"></i>GIF画像パス修復</h1>
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <div class="bg-gray-900 text-green-400 p-4 rounded-lg font-mono text-sm overflow-auto max-h-96">
<?php
}

output("=== GIF画像パス修復 ===", $isCli);
output("", $isCli);

$tables = [
    'creators' => ['image', 'header_image'],
    'works' => ['image'],
    'work_pages' => ['image'],
    'articles' => ['image']
];

$totalFixed = 0;
$serverBasePath = dirname(__DIR__);

// まず、サーバー上に存在するGIFファイルを全てスキャン
$existingGifs = [];
$uploadsDir = $serverBasePath . '/uploads';
if (is_dir($uploadsDir)) {
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($uploadsDir));
    foreach ($iterator as $file) {
        if ($file->isFile() && preg_match('/\.gif$/i', $file->getFilename())) {
            $relativePath = str_replace($serverBasePath . '/', '', $file->getPathname());
            $existingGifs[strtolower($relativePath)] = $relativePath;
        }
    }
}
output("サーバー上のGIFファイル: " . count($existingGifs) . "件", $isCli);
output("", $isCli);

foreach ($tables as $table => $columns) {
    foreach ($columns as $column) {
        try {
            // .webpになっているレコードを全て取得
            $stmt = $db->query("SELECT id, $column FROM $table WHERE $column LIKE '%.webp'");
            $rows = $stmt->fetchAll();
            
            $fixed = 0;
            foreach ($rows as $row) {
                $webpPath = $row[$column];
                $webpFullPath = $serverBasePath . '/' . ltrim($webpPath, '/');
                
                // WebPファイルが存在しない場合、元のファイルを探す
                if (!file_exists($webpFullPath)) {
                    // 拡張子を変えて探す
                    $basePath = preg_replace('/\.webp$/i', '', $webpPath);
                    $found = false;
                    
                    foreach (['.gif', '.GIF', '.jpg', '.jpeg', '.png', '.PNG', '.JPG', '.JPEG'] as $ext) {
                        $testPath = $basePath . $ext;
                        $testFullPath = $serverBasePath . '/' . ltrim($testPath, '/');
                        
                        if (file_exists($testFullPath)) {
                            $updateStmt = $db->prepare("UPDATE $table SET $column = ? WHERE id = ?");
                            $updateStmt->execute([$testPath, $row['id']]);
                            $fixed++;
                            output("[$table] ID:{$row['id']} → $ext に修復 (WebPなし)", $isCli);
                            $found = true;
                            break;
                        }
                    }
                    
                    if (!$found) {
                        output("[$table] ID:{$row['id']} 警告: 元ファイルが見つかりません ($webpPath)", $isCli);
                    }
                } else {
                    // WebPは存在するが、GIFの場合はGIFを優先（アニメーション保持のため）
                    $gifPath = preg_replace('/\.webp$/i', '.gif', $webpPath);
                    $gifFullPath = $serverBasePath . '/' . ltrim($gifPath, '/');
                    $GifPath = preg_replace('/\.webp$/i', '.GIF', $webpPath);
                    $GifFullPath = $serverBasePath . '/' . ltrim($GifPath, '/');
                    
                    if (file_exists($gifFullPath)) {
                        $updateStmt = $db->prepare("UPDATE $table SET $column = ? WHERE id = ?");
                        $updateStmt->execute([$gifPath, $row['id']]);
                        $fixed++;
                        output("[$table] ID:{$row['id']} → GIFに戻す", $isCli);
                    } elseif (file_exists($GifFullPath)) {
                        $updateStmt = $db->prepare("UPDATE $table SET $column = ? WHERE id = ?");
                        $updateStmt->execute([$GifPath, $row['id']]);
                        $fixed++;
                        output("[$table] ID:{$row['id']} → GIFに戻す", $isCli);
                    }
                }
            }
            
            if ($fixed > 0) {
                output("$table.$column: {$fixed}件修復", $isCli);
                $totalFixed += $fixed;
            }
        } catch (Exception $e) {
            output("エラー ($table.$column): " . $e->getMessage(), $isCli);
        }
    }
}

output("", $isCli);
output("=== 完了 ===", $isCli);
output("修復件数: $totalFixed", $isCli);

if (!$isCli) {
?>
                </div>
            </div>
            <div class="flex gap-4">
                <a href="fix-gif.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded">
                    <i class="fas fa-sync mr-1"></i>再実行
                </a>
                <a href="check-images.php" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded">
                    <i class="fas fa-chart-bar mr-1"></i>画像確認
                </a>
                <a href="index.php" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded">
                    <i class="fas fa-arrow-left mr-1"></i>ダッシュボード
                </a>
            </div>
        </div>
    </main>
</body>
</html>
<?php
}
