<?php
/**
 * 既存画像の一括WebP変換スクリプト
 * 
 * 使用方法:
 * 1. ブラウザからアクセス: /admin/convert-images.php
 * 2. コマンドライン: php convert-images.php
 * 
 * 注意: 大量の画像がある場合は時間がかかります
 */

require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/image-helper.php';

// ブラウザからのアクセス時は認証必須
if (php_sapi_name() !== 'cli') {
    requireAuth();
}

// タイムアウトを延長
set_time_limit(300);
ini_set('memory_limit', '512M');

$isCli = php_sapi_name() === 'cli';
$uploadDir = dirname(__DIR__) . '/uploads';

// オプション
$deleteOriginal = isset($_GET['delete']) || (isset($argv[1]) && $argv[1] === '--delete');
$dryRun = isset($_GET['dry-run']) || (isset($argv[1]) && $argv[1] === '--dry-run');
$updateDb = isset($_GET['update-db']) || (isset($argv[1]) && $argv[1] === '--update-db');

function output($message, $isCli) {
    if ($isCli) {
        echo $message . "\n";
    } else {
        echo htmlspecialchars($message) . "<br>\n";
        ob_flush();
        flush();
    }
}

// HTML出力開始
if (!$isCli) {
    ?>
    <!DOCTYPE html>
    <html lang="ja">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>画像変換 - 管理画面</title>
        <script src="https://cdn.tailwindcss.com"></script>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
        <link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@400;500;700&display=swap" rel="stylesheet">
        <style>body { font-family: 'Zen Maru Gothic', sans-serif; }</style>
    </head>
    <body class="bg-gray-50">
        <?php include 'includes/sidebar.php'; ?>
        
        <main class="lg:ml-64 p-8 pt-20 lg:pt-8">
            <h1 class="text-2xl font-bold mb-6"><i class="fas fa-sync mr-2"></i>画像一括WebP変換</h1>
            
            <div class="bg-white rounded-lg shadow p-6 mb-6">
                <div class="mb-6 p-4 bg-blue-50 rounded-lg">
                    <h2 class="font-bold text-blue-800 mb-2"><i class="fas fa-info-circle mr-1"></i>オプション</h2>
                    <ul class="text-sm text-blue-700 space-y-1">
                        <li><code>?dry-run</code> - 変換せずに対象ファイルを確認</li>
                        <li><code>?delete</code> - 変換後に元ファイルを削除</li>
                        <li><code>?update-db</code> - データベースのパスも更新</li>
                    </ul>
                </div>
                
                <?php if ($dryRun): ?>
                <div class="mb-4 p-3 bg-yellow-100 text-yellow-800 rounded">
                    <i class="fas fa-exclamation-triangle mr-1"></i>ドライラン - 実際の変換は行いません
                </div>
                <?php endif; ?>
                
                <div class="bg-gray-900 text-green-400 p-4 rounded-lg font-mono text-sm overflow-auto max-h-96">
    <?php
}

// GD拡張が有効か確認
if (!extension_loaded('gd')) {
    output("エラー: GD拡張がインストールされていません", $isCli);
    exit(1);
}

if (!function_exists('imagewebp')) {
    output("エラー: WebPサポートが有効ではありません", $isCli);
    exit(1);
}

output("=== 画像一括WebP変換 ===", $isCli);
output("対象ディレクトリ: $uploadDir", $isCli);
output("", $isCli);

// 変換対象の拡張子（GIFはアニメーションの可能性があるため除外）
$extensions = ['jpg', 'jpeg', 'png'];

// 統計
$stats = [
    'total' => 0,
    'converted' => 0,
    'skipped' => 0,
    'failed' => 0,
    'saved_bytes' => 0
];

// ファイル一覧を取得
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($uploadDir, RecursiveDirectoryIterator::SKIP_DOTS)
);

$filesToConvert = [];

foreach ($iterator as $file) {
    if ($file->isFile()) {
        $ext = strtolower($file->getExtension());
        if (in_array($ext, $extensions)) {
            $filesToConvert[] = $file->getPathname();
        }
    }
}

$stats['total'] = count($filesToConvert);
output("変換対象ファイル数: " . $stats['total'], $isCli);
output("", $isCli);

foreach ($filesToConvert as $srcPath) {
    $relativePath = str_replace($uploadDir . '/', '', $srcPath);
    $webpPath = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $srcPath);
    
    // 既にWebP版が存在する場合はスキップ
    if (file_exists($webpPath)) {
        output("[スキップ] $relativePath - WebP版が既に存在", $isCli);
        $stats['skipped']++;
        continue;
    }
    
    if ($dryRun) {
        output("[変換予定] $relativePath", $isCli);
        $stats['converted']++;
        continue;
    }
    
    // 変換実行
    $originalSize = filesize($srcPath);
    $result = ImageHelper::convertToWebP($srcPath, $webpPath);
    
    if ($result) {
        $newSize = filesize($webpPath);
        $saved = $originalSize - $newSize;
        $savedPercent = round(($saved / $originalSize) * 100, 1);
        
        $stats['converted']++;
        $stats['saved_bytes'] += $saved;
        
        output("[変換成功] $relativePath → " . basename($webpPath) . " (削減: {$savedPercent}%)", $isCli);
        
        // 元ファイルを削除
        if ($deleteOriginal) {
            unlink($srcPath);
            output("  → 元ファイルを削除", $isCli);
        }
    } else {
        $stats['failed']++;
        output("[変換失敗] $relativePath", $isCli);
    }
}

// データベース更新
if ($updateDb && !$dryRun) {
    output("", $isCli);
    output("=== データベース更新 ===", $isCli);
    
    $db = getDB();
    $tables = [
        'creators' => ['image', 'header_image'],
        'works' => ['image'],
        'work_pages' => ['image'],
        'articles' => ['image']
    ];
    
    foreach ($tables as $table => $columns) {
        foreach ($columns as $column) {
            try {
                // WebP版が存在するパスを更新
                $stmt = $db->query("SELECT id, $column FROM $table WHERE $column IS NOT NULL AND $column != ''");
                $rows = $stmt->fetchAll();
                
                $updated = 0;
                foreach ($rows as $row) {
                    $path = $row[$column];
                    if (preg_match('/\.(jpg|jpeg|png|gif)$/i', $path)) {
                        $webpPath = preg_replace('/\.(jpg|jpeg|png|gif)$/i', '.webp', $path);
                        $fullPath = dirname(__DIR__) . '/' . ltrim($webpPath, '/');
                        
                        if (file_exists($fullPath)) {
                            $updateStmt = $db->prepare("UPDATE $table SET $column = ? WHERE id = ?");
                            $updateStmt->execute([$webpPath, $row['id']]);
                            $updated++;
                        }
                    }
                }
                
                if ($updated > 0) {
                    output("$table.$column: {$updated}件更新", $isCli);
                }
            } catch (Exception $e) {
                output("エラー ($table.$column): " . $e->getMessage(), $isCli);
            }
        }
    }
}

// 結果表示
output("", $isCli);
output("=== 結果 ===", $isCli);
output("対象ファイル: " . $stats['total'], $isCli);
output("変換成功: " . $stats['converted'], $isCli);
output("スキップ: " . $stats['skipped'], $isCli);
output("失敗: " . $stats['failed'], $isCli);

if ($stats['saved_bytes'] > 0) {
    $savedMB = round($stats['saved_bytes'] / 1024 / 1024, 2);
    output("削減容量: {$savedMB} MB", $isCli);
}

// HTML出力終了
if (!$isCli) {
    ?>
                </div>
                
                <div class="flex gap-4 mt-6">
                    <a href="convert-images.php" class="bg-blue-500 hover:bg-blue-600 text-white px-4 py-2 rounded">
                        <i class="fas fa-sync mr-1"></i>再実行
                    </a>
                    <a href="convert-images.php?update-db" class="bg-green-500 hover:bg-green-600 text-white px-4 py-2 rounded">
                        <i class="fas fa-database mr-1"></i>変換＋DB更新
                    </a>
                    <a href="convert-images.php?delete&update-db" class="bg-red-500 hover:bg-red-600 text-white px-4 py-2 rounded">
                        <i class="fas fa-trash mr-1"></i>変換＋元削除＋DB更新
                    </a>
                </div>
            </div>
        </main>
    </body>
    </html>
    <?php
}
