<?php
/**
 * OGP用JPG画像一括生成ツール
 * WebP画像からJPG版を生成（LINE/Twitter/Slack等のOGP対応）
 */
session_start();
require_once '../includes/auth.php';
require_once '../includes/db.php';
require_once '../includes/site-settings.php';
require_once '../includes/image-helper.php';

requireAuth();

$db = getDB();
$message = '';
$error = '';
$results = [];

// 対象ディレクトリ
$targetDirs = [
    'uploads/articles' => '記事画像',
    'uploads/works' => '作品画像',
    'uploads/creators' => 'クリエイター画像',
    'uploads/products' => '商品画像',
    'uploads/site' => 'サイト設定画像',
];

$baseDir = dirname(__DIR__);

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate'])) {
    $generated = 0;
    $skipped = 0;
    $errors = [];
    
    foreach ($targetDirs as $dir => $label) {
        $fullDir = $baseDir . '/' . $dir;
        if (!is_dir($fullDir)) continue;
        
        $files = glob($fullDir . '/*.webp');
        foreach ($files as $webpFile) {
            $jpgFile = preg_replace('/\.webp$/i', '.jpg', $webpFile);
            
            // 既にJPG版が存在する場合はスキップ
            if (file_exists($jpgFile)) {
                $skipped++;
                continue;
            }
            
            // WebPからJPGを生成
            try {
                // ファイルサイズチェック
                $fileSize = @filesize($webpFile);
                if ($fileSize === false || $fileSize < 100) {
                    $errors[] = basename($webpFile) . ': ファイルが小さすぎるか破損';
                    continue;
                }
                
                // ファイルの実際の形式を判定
                $imageInfo = @getimagesize($webpFile);
                
                // getimagesizeが失敗した場合、ファイルヘッダーから判定を試みる
                if ($imageInfo === false) {
                    $handle = @fopen($webpFile, 'rb');
                    if ($handle) {
                        $header = fread($handle, 12);
                        fclose($handle);
                        
                        // マジックバイトで形式を判定
                        if (substr($header, 0, 4) === 'RIFF' && substr($header, 8, 4) === 'WEBP') {
                            // 有効なWebPヘッダーだがGDで読めない
                            $errors[] = basename($webpFile) . ': WebP形式だがGDで処理不可（アニメーションWebP等）';
                        } elseif (substr($header, 0, 3) === "\xFF\xD8\xFF") {
                            // JPEG
                            $image = @imagecreatefromjpeg($webpFile);
                            if ($image !== false) {
                                goto process_image;
                            }
                            $errors[] = basename($webpFile) . ': JPEG形式だが読み込み失敗';
                        } elseif (substr($header, 0, 8) === "\x89PNG\r\n\x1a\n") {
                            // PNG
                            $image = @imagecreatefrompng($webpFile);
                            if ($image !== false) {
                                goto process_image;
                            }
                            $errors[] = basename($webpFile) . ': PNG形式だが読み込み失敗';
                        } else {
                            $errors[] = basename($webpFile) . ': 不明な形式または破損（スキップ）';
                        }
                    } else {
                        $errors[] = basename($webpFile) . ': ファイルを開けません';
                    }
                    continue;
                }
                
                $mimeType = $imageInfo['mime'] ?? '';
                $image = false;
                
                // MIMEタイプに応じて読み込み
                switch ($mimeType) {
                    case 'image/webp':
                        $image = @imagecreatefromwebp($webpFile);
                        break;
                    case 'image/jpeg':
                        $image = @imagecreatefromjpeg($webpFile);
                        break;
                    case 'image/png':
                        $image = @imagecreatefrompng($webpFile);
                        break;
                    case 'image/gif':
                        $image = @imagecreatefromgif($webpFile);
                        break;
                    default:
                        // 拡張子がwebpでもMIMEが不明な場合、各形式を試す
                        $image = @imagecreatefromwebp($webpFile);
                        if ($image === false) {
                            $image = @imagecreatefromjpeg($webpFile);
                        }
                        if ($image === false) {
                            $image = @imagecreatefrompng($webpFile);
                        }
                        if ($image === false) {
                            $image = @imagecreatefromgif($webpFile);
                        }
                }
                
                if ($image === false) {
                    $errors[] = basename($webpFile) . ': 読み込み失敗（形式: ' . $mimeType . '）';
                    continue;
                }
                
                process_image:
                
                // 白背景で合成（透明部分対策）
                $width = imagesx($image);
                $height = imagesy($image);
                $jpgImage = imagecreatetruecolor($width, $height);
                $white = imagecolorallocate($jpgImage, 255, 255, 255);
                imagefill($jpgImage, 0, 0, $white);
                imagecopy($jpgImage, $image, 0, 0, 0, 0, $width, $height);
                
                if (imagejpeg($jpgImage, $jpgFile, 85)) {
                    $generated++;
                    $results[] = [
                        'dir' => $label,
                        'file' => basename($jpgFile),
                        'status' => 'success'
                    ];
                } else {
                    $errors[] = basename($webpFile) . ': 保存失敗';
                }
                
                imagedestroy($image);
                imagedestroy($jpgImage);
                
            } catch (Exception $e) {
                $errors[] = basename($webpFile) . ': ' . $e->getMessage();
            }
        }
    }
    
    $message = "{$generated}件のJPGを作成しました";
    if ($skipped > 0) {
        $message .= "（{$skipped}件は既存のためスキップ）";
    }
    if (!empty($errors)) {
        $error = 'エラー: ' . implode(', ', array_slice($errors, 0, 5));
        if (count($errors) > 5) {
            $error .= ' 他' . (count($errors) - 5) . '件';
        }
    }
}

// 現在の状況を確認
$stats = [];
$totalWebp = 0;
$totalJpg = 0;
$totalMissing = 0;

foreach ($targetDirs as $dir => $label) {
    $fullDir = $baseDir . '/' . $dir;
    if (!is_dir($fullDir)) {
        $stats[$label] = ['webp' => 0, 'jpg' => 0, 'missing' => 0];
        continue;
    }
    
    $webpFiles = glob($fullDir . '/*.webp');
    $jpgCount = 0;
    $missingJpg = 0;
    
    foreach ($webpFiles as $webpFile) {
        $jpgFile = preg_replace('/\.webp$/i', '.jpg', $webpFile);
        if (file_exists($jpgFile)) {
            $jpgCount++;
        } else {
            $missingJpg++;
        }
    }
    
    $stats[$label] = [
        'webp' => count($webpFiles),
        'jpg' => $jpgCount,
        'missing' => $missingJpg
    ];
    
    $totalWebp += count($webpFiles);
    $totalJpg += $jpgCount;
    $totalMissing += $missingJpg;
}

$pwaThemeColor = getSiteSetting($db, 'pwa_theme_color', '#ffffff');
$backyardFavicon = getBackyardFaviconInfo($db);

$pageTitle = 'OGP画像生成';
include 'includes/header.php';
?>
        <h1 class="text-2xl font-bold mb-6"><i class="fas fa-share-alt text-blue-500 mr-2"></i>OGP用JPG画像生成</h1>
        
        <?php if ($message): ?>
        <div class="bg-green-100 border border-green-300 text-green-700 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="bg-red-100 border border-red-300 text-red-700 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-exclamation-circle mr-2"></i><?= $error ?>
        </div>
        <?php endif; ?>
        
        <div class="bg-white rounded-xl shadow-sm p-6 mb-6">
            <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 mb-6">
                <p class="text-blue-800">
                    <i class="fas fa-info-circle mr-2"></i>
                    <strong>なぜJPG版が必要？</strong><br>
                    <span class="text-sm">LINE、Twitter、SlackなどはOGP画像としてWebP形式を正しく認識しない場合があります。このツールで既存のWebP画像からJPG版を生成することで、SNS共有時に正しい画像が表示されるようになります。</span>
                </p>
            </div>
            
            <!-- サマリー -->
            <div class="grid grid-cols-3 gap-4 mb-6">
                <div class="bg-gray-50 p-4 rounded-lg text-center">
                    <div class="text-3xl font-bold text-gray-600"><?= $totalWebp ?></div>
                    <div class="text-sm text-gray-500">WebP画像</div>
                </div>
                <div class="bg-green-50 p-4 rounded-lg text-center">
                    <div class="text-3xl font-bold text-green-600"><?= $totalJpg ?></div>
                    <div class="text-sm text-gray-500">JPG版あり</div>
                </div>
                <div class="bg-<?= $totalMissing > 0 ? 'red' : 'gray' ?>-50 p-4 rounded-lg text-center">
                    <div class="text-3xl font-bold text-<?= $totalMissing > 0 ? 'red' : 'gray' ?>-600"><?= $totalMissing ?></div>
                    <div class="text-sm text-gray-500">未生成</div>
                </div>
            </div>
            
            <!-- 詳細テーブル -->
            <h2 class="text-lg font-bold mb-4">📁 ディレクトリ別状況</h2>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="p-3 text-left font-bold">ディレクトリ</th>
                            <th class="p-3 text-center font-bold">WebP</th>
                            <th class="p-3 text-center font-bold">JPG版あり</th>
                            <th class="p-3 text-center font-bold">未生成</th>
                            <th class="p-3 text-center font-bold">状態</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($stats as $label => $stat): ?>
                        <tr class="border-b hover:bg-gray-50">
                            <td class="p-3 font-medium"><?= htmlspecialchars($label) ?></td>
                            <td class="p-3 text-center"><?= $stat['webp'] ?></td>
                            <td class="p-3 text-center text-green-600 font-bold"><?= $stat['jpg'] ?></td>
                            <td class="p-3 text-center <?= $stat['missing'] > 0 ? 'text-red-600 font-bold' : 'text-gray-400' ?>"><?= $stat['missing'] ?></td>
                            <td class="p-3 text-center">
                                <?php if ($stat['webp'] === 0): ?>
                                <span class="px-2 py-1 rounded text-xs bg-gray-100 text-gray-600">画像なし</span>
                                <?php elseif ($stat['missing'] === 0): ?>
                                <span class="px-2 py-1 rounded text-xs bg-green-100 text-green-700"><i class="fas fa-check mr-1"></i>完了</span>
                                <?php else: ?>
                                <span class="px-2 py-1 rounded text-xs bg-yellow-100 text-yellow-700"><i class="fas fa-exclamation-triangle mr-1"></i>要生成</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- アクションボタン -->
            <div class="mt-6 flex flex-wrap gap-4">
                <?php if ($totalMissing > 0): ?>
                <form method="POST">
                    <button type="submit" name="generate" class="bg-yellow-400 hover:bg-yellow-500 text-gray-900 px-6 py-3 rounded-lg font-bold transition">
                        <i class="fas fa-cogs mr-2"></i>JPG版を一括生成（<?= $totalMissing ?>件）
                    </button>
                </form>
                <?php else: ?>
                <div class="bg-green-100 text-green-700 px-4 py-3 rounded-lg">
                    <i class="fas fa-check-circle mr-2"></i>すべてのWebP画像にJPG版が存在します。OGP対応は完了しています。
                </div>
                <?php endif; ?>
            </div>
            
            <?php if (!empty($results)): ?>
            <!-- 生成結果 -->
            <div class="mt-6 pt-6 border-t">
                <h2 class="text-lg font-bold mb-4">✅ 生成結果</h2>
                <div class="max-h-64 overflow-y-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-100 sticky top-0">
                            <tr>
                                <th class="p-2 text-left">ディレクトリ</th>
                                <th class="p-2 text-left">ファイル名</th>
                                <th class="p-2 text-center">状態</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($results as $r): ?>
                            <tr class="border-b">
                                <td class="p-2"><?= htmlspecialchars($r['dir']) ?></td>
                                <td class="p-2 font-mono text-xs"><?= htmlspecialchars($r['file']) ?></td>
                                <td class="p-2 text-center">
                                    <span class="px-2 py-1 rounded text-xs bg-green-100 text-green-700">生成完了</span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>
        </div>
        
        <div class="bg-white rounded-xl shadow-sm p-6">
            <h2 class="text-lg font-bold mb-4">📝 OGP画像生成後の手順</h2>
            <ol class="list-decimal list-inside space-y-2 text-gray-600">
                <li>上のボタンでJPG版を一括生成</li>
                <li><a href="https://poker.line.naver.jp/" target="_blank" class="text-blue-500 hover:underline">LINE Page Poker</a> で該当URLのキャッシュをクリア</li>
                <li>LINEやTwitterで再度共有してOGP画像が正しく表示されるか確認</li>
            </ol>
        </div>

<?php include 'includes/footer.php'; ?>
