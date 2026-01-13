<?php
/**
 * GIF→WebM変換ツール
 * ブラウザ上でGIFをCanvas録画してWebMに変換
 * 一括変換・削除・上書き対応
 */
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/site-settings.php';
requireAuth();

$db = getDB();

// 検索対象ディレクトリ
$searchDirs = [
    dirname(__DIR__) . '/uploads/',
    dirname(__DIR__) . '/images/',
    dirname(__DIR__) . '/assets/images/',
];

// GIFファイルを検索
function findGifFiles($dirs) {
    $gifFiles = [];
    foreach ($dirs as $dir) {
        if (!is_dir($dir)) continue;
        
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        
        foreach ($iterator as $file) {
            if ($file->isFile() && strtolower($file->getExtension()) === 'gif') {
                $isAnimated = isAnimatedGif($file->getPathname());
                if ($isAnimated) {
                    $gifFiles[] = [
                        'path' => $file->getPathname(),
                        'relative' => str_replace(dirname(__DIR__), '', $file->getPathname()),
                        'size' => $file->getSize(),
                        'webm_exists' => file_exists(preg_replace('/\.gif$/i', '.webm', $file->getPathname())),
                    ];
                }
            }
        }
    }
    
    usort($gifFiles, function($a, $b) { return $b['size'] - $a['size']; });
    return $gifFiles;
}

// アニメーションGIFかチェック
function isAnimatedGif($filename) {
    if (!($fh = @fopen($filename, 'rb'))) {
        return false;
    }
    $count = 0;
    while (!feof($fh) && $count < 2) {
        $chunk = fread($fh, 1024 * 100);
        $count += preg_match_all('#\x00\x21\xF9\x04.{4}\x00[\x2C\x21]#s', $chunk, $matches);
    }
    fclose($fh);
    return $count > 1;
}

function formatSize($bytes) {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1024 * 1024) return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1024 / 1024, 2) . ' MB';
}

// APIリクエスト処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    // WebMファイルを保存
    if ($action === 'save_webm') {
        $gifPath = $_POST['gif_path'] ?? '';
        $webmData = $_POST['webm_data'] ?? '';
        $deleteOriginal = ($_POST['delete_original'] ?? '0') === '1';
        $overwrite = ($_POST['overwrite'] ?? '0') === '1';
        
        if (empty($gifPath) || empty($webmData)) {
            echo json_encode(['success' => false, 'error' => 'データが不足しています']);
            exit;
        }
        
        // Base64デコード
        $webmData = str_replace('data:video/webm;base64,', '', $webmData);
        $webmBinary = base64_decode($webmData);
        
        if ($webmBinary === false) {
            echo json_encode(['success' => false, 'error' => 'Base64デコードに失敗しました']);
            exit;
        }
        
        // WebMファイルパス
        $webmPath = preg_replace('/\.gif$/i', '.webm', $gifPath);
        
        // 既存WebMがあり、上書きしない場合はスキップ
        if (file_exists($webmPath) && !$overwrite) {
            echo json_encode(['success' => false, 'error' => '既にWebMが存在します', 'exists' => true]);
            exit;
        }
        
        // 保存
        if (file_put_contents($webmPath, $webmBinary) === false) {
            echo json_encode(['success' => false, 'error' => 'ファイルの保存に失敗しました']);
            exit;
        }
        
        $originalSize = filesize($gifPath);
        $newSize = filesize($webmPath);
        
        // 元ファイル削除
        if ($deleteOriginal && file_exists($gifPath)) {
            unlink($gifPath);
        }
        
        echo json_encode([
            'success' => true,
            'original_size' => $originalSize,
            'new_size' => $newSize,
            'reduction' => round((1 - $newSize / $originalSize) * 100, 1),
            'deleted' => $deleteOriginal
        ]);
        exit;
    }
    
    // GIFファイルを削除
    if ($action === 'delete_gif') {
        $gifPath = $_POST['gif_path'] ?? '';
        
        if (empty($gifPath) || !file_exists($gifPath)) {
            echo json_encode(['success' => false, 'error' => 'ファイルが見つかりません']);
            exit;
        }
        
        if (unlink($gifPath)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => '削除に失敗しました']);
        }
        exit;
    }
    
    // WebMファイルを削除
    if ($action === 'delete_webm') {
        $gifPath = $_POST['gif_path'] ?? '';
        $webmPath = preg_replace('/\.gif$/i', '.webm', $gifPath);
        
        if (empty($webmPath) || !file_exists($webmPath)) {
            echo json_encode(['success' => false, 'error' => 'WebMファイルが見つかりません']);
            exit;
        }
        
        if (unlink($webmPath)) {
            echo json_encode(['success' => true]);
        } else {
            echo json_encode(['success' => false, 'error' => '削除に失敗しました']);
        }
        exit;
    }
    
    // DBの画像参照をWebMに更新
    if ($action === 'apply_webm') {
        $results = applyWebmToDatabase($db);
        echo json_encode(['success' => true, 'results' => $results]);
        exit;
    }
    
    // DBのWebM参照をGIFに戻す
    if ($action === 'revert_to_gif') {
        $deleteWebm = isset($_POST['delete_webm']) && $_POST['delete_webm'] === '1';
        $results = revertToGifInDatabase($db, $deleteWebm);
        echo json_encode(['success' => true, 'results' => $results]);
        exit;
    }
    
    // 現在のDB参照状況を取得
    if ($action === 'get_db_status') {
        $status = getDatabaseMediaStatus($db);
        echo json_encode(['success' => true, 'status' => $status]);
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => '不明なアクション']);
    exit;
}

/**
 * DBの画像参照をWebMに更新
 * ※注意: この方法ではなく、表示側で自動判定する方式を推奨
 */
function applyWebmToDatabase($db) {
    $baseDir = dirname(__DIR__);
    $results = ['updated' => 0, 'skipped' => 0, 'tables' => []];
    
    // 更新対象テーブルとカラム
    $targets = [
        ['table' => 'works', 'columns' => ['image', 'back_image']],
        ['table' => 'articles', 'columns' => ['thumbnail']],
        ['table' => 'creators', 'columns' => ['icon', 'header_image']],
        ['table' => 'sticker_groups', 'columns' => ['thumbnail']],
    ];
    
    foreach ($targets as $target) {
        $table = $target['table'];
        $tableUpdated = 0;
        
        foreach ($target['columns'] as $column) {
            // GIF参照を検索
            $sql = "SELECT id, {$column} FROM {$table} WHERE {$column} LIKE '%.gif'";
            
            try {
                $stmt = $db->query($sql);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($rows as $row) {
                    $gifPath = $row[$column];
                    $webmPath = preg_replace('/\.gif$/i', '.webm', $gifPath);
                    $fullWebmPath = $baseDir . '/' . ltrim($webmPath, '/');
                    
                    // WebMファイルが存在する場合のみ更新
                    if (file_exists($fullWebmPath)) {
                        $updateSql = "UPDATE {$table} SET {$column} = ? WHERE id = ?";
                        $updateStmt = $db->prepare($updateSql);
                        $updateStmt->execute([$webmPath, $row['id']]);
                        $tableUpdated++;
                        $results['updated']++;
                    } else {
                        $results['skipped']++;
                    }
                }
            } catch (Exception $e) {
                // テーブルが存在しない場合などはスキップ
            }
        }
        
        if ($tableUpdated > 0) {
            $results['tables'][$table] = $tableUpdated;
        }
    }
    
    return $results;
}

/**
 * DBのWebM参照をGIFに戻す
 * @param PDO $db
 * @param bool $deleteWebm WebMファイルも削除するかどうか
 */
function revertToGifInDatabase($db, $deleteWebm = false) {
    $baseDir = dirname(__DIR__); // public_html
    $results = ['updated' => 0, 'skipped' => 0, 'not_found' => [], 'tables' => [], 'deleted_files' => 0];
    
    // 更新対象テーブルとカラム
    $targets = [
        ['table' => 'works', 'columns' => ['image', 'back_image']],
        ['table' => 'articles', 'columns' => ['thumbnail']],
        ['table' => 'creators', 'columns' => ['icon', 'header_image']],
        ['table' => 'sticker_groups', 'columns' => ['thumbnail']],
    ];
    
    foreach ($targets as $target) {
        $table = $target['table'];
        $tableUpdated = 0;
        
        foreach ($target['columns'] as $column) {
            // WebM参照を検索
            $sql = "SELECT id, {$column} FROM {$table} WHERE {$column} LIKE '%.webm'";
            
            try {
                $stmt = $db->query($sql);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                foreach ($rows as $row) {
                    $webmPath = $row[$column];
                    
                    // 小文字と大文字の両方をチェック
                    $gifPathLower = preg_replace('/\.webm$/i', '.gif', $webmPath);
                    $gifPathUpper = preg_replace('/\.webm$/i', '.GIF', $webmPath);
                    
                    $relativeLower = ltrim($gifPathLower, '/');
                    $relativeUpper = ltrim($gifPathUpper, '/');
                    $fullGifPathLower = $baseDir . '/' . $relativeLower;
                    $fullGifPathUpper = $baseDir . '/' . $relativeUpper;
                    
                    // GIFファイルが存在する場合のみ更新（小文字優先）
                    $foundGifPath = null;
                    if (file_exists($fullGifPathLower)) {
                        $foundGifPath = $gifPathLower;
                    } elseif (file_exists($fullGifPathUpper)) {
                        $foundGifPath = $gifPathUpper;
                    }
                    
                    if ($foundGifPath) {
                        $updateSql = "UPDATE {$table} SET {$column} = ? WHERE id = ?";
                        $updateStmt = $db->prepare($updateSql);
                        $updateStmt->execute([$foundGifPath, $row['id']]);
                        $tableUpdated++;
                        $results['updated']++;
                        
                        // WebMファイルを削除
                        if ($deleteWebm) {
                            $webmFullPath = $baseDir . '/' . ltrim($webmPath, '/');
                            if (file_exists($webmFullPath)) {
                                if (unlink($webmFullPath)) {
                                    $results['deleted_files']++;
                                }
                            }
                        }
                    } else {
                        $results['skipped']++;
                        $results['not_found'][] = $fullGifPathLower . ' (or .GIF)';
                    }
                }
            } catch (Exception $e) {
                // テーブルが存在しない場合などはスキップ
            }
        }
        
        if ($tableUpdated > 0) {
            $results['tables'][$table] = $tableUpdated;
        }
    }
    
    return $results;
}

/**
 * DBの画像参照状況を取得
 */
function getDatabaseMediaStatus($db) {
    $status = ['gif_refs' => 0, 'webm_refs' => 0, 'details' => []];
    
    $targets = [
        ['table' => 'works', 'columns' => ['image', 'back_image'], 'label' => '作品'],
        ['table' => 'articles', 'columns' => ['thumbnail'], 'label' => '記事'],
        ['table' => 'creators', 'columns' => ['icon', 'header_image'], 'label' => 'クリエイター'],
        ['table' => 'sticker_groups', 'columns' => ['thumbnail'], 'label' => 'コレクション'],
    ];
    
    foreach ($targets as $target) {
        $table = $target['table'];
        $gifCount = 0;
        $webmCount = 0;
        
        foreach ($target['columns'] as $column) {
            try {
                $stmt = $db->query("SELECT COUNT(*) FROM {$table} WHERE {$column} LIKE '%.gif'");
                $gifCount += $stmt->fetchColumn();
                
                $stmt = $db->query("SELECT COUNT(*) FROM {$table} WHERE {$column} LIKE '%.webm'");
                $webmCount += $stmt->fetchColumn();
            } catch (Exception $e) {
                // テーブルが存在しない場合などはスキップ
            }
        }
        
        $status['gif_refs'] += $gifCount;
        $status['webm_refs'] += $webmCount;
        $status['details'][$target['label']] = ['gif' => $gifCount, 'webm' => $webmCount];
    }
    
    return $status;
}

// GIFファイル一覧を取得
$gifFiles = findGifFiles($searchDirs);
$totalSize = array_sum(array_column($gifFiles, 'size'));
$convertedCount = count(array_filter($gifFiles, fn($f) => $f['webm_exists']));

$pageTitle = 'GIF→WebM変換';
include 'includes/header.php';
?>
        <h1 class="text-2xl font-bold mb-6"><i class="fas fa-film mr-2"></i>GIF→WebM変換ツール</h1>
        
        <div class="bg-white rounded-lg shadow p-6 mb-6">
            <!-- 説明 -->
            <div class="mb-6 p-4 bg-blue-50 rounded-lg">
                <h3 class="font-bold text-blue-800 mb-2"><i class="fas fa-info-circle mr-1"></i>このツールについて</h3>
                <ul class="text-sm text-blue-700 space-y-1">
                    <li>• アニメーションGIFをWebM動画に変換します</li>
                    <li>• ブラウザ上でGIFを録画して変換するため、サーバーにFFmpegは不要です</li>
                    <li>• 通常70〜90%のファイルサイズ削減が可能です</li>
                    <li>• 一括変換・個別変換・上書き・削除に対応</li>
                </ul>
            </div>
            
            <!-- ステータス -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-6">
                <div class="bg-gray-50 p-4 rounded-lg text-center">
                    <div class="text-2xl font-bold text-purple-600"><?= count($gifFiles) ?></div>
                    <div class="text-sm text-gray-600">アニメーションGIF</div>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg text-center">
                    <div class="text-2xl font-bold text-orange-600"><?= formatSize($totalSize) ?></div>
                    <div class="text-sm text-gray-600">合計サイズ</div>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg text-center">
                    <div class="text-2xl font-bold text-green-600"><?= $convertedCount ?></div>
                    <div class="text-sm text-gray-600">変換済み</div>
                </div>
                <div class="bg-gray-50 p-4 rounded-lg text-center">
                    <div class="text-2xl font-bold text-blue-600"><?= count($gifFiles) - $convertedCount ?></div>
                    <div class="text-sm text-gray-600">未変換</div>
                </div>
            </div>
            
            <!-- DB参照状況・サイト反映 -->
            <div class="mb-6 p-4 bg-yellow-50 border border-yellow-200 rounded-lg">
                <h3 class="font-bold text-yellow-800 mb-3"><i class="fas fa-database mr-1"></i>サイトへの反映</h3>
                <p class="text-sm text-yellow-700 mb-3">
                    変換後、「WebMをサイトに反映」ボタンを押すとデータベースの画像参照がWebMに更新され、サイト上でWebMが表示されるようになります。
                </p>
                
                <!-- DB参照状況 -->
                <div id="dbStatus" class="mb-4 p-3 bg-white rounded border">
                    <div class="flex items-center justify-between mb-2">
                        <span class="font-bold text-sm">現在のDB参照状況</span>
                        <button onclick="refreshDbStatus()" class="text-xs text-blue-600 hover:underline">
                            <i class="fas fa-sync-alt mr-1"></i>更新
                        </button>
                    </div>
                    <div class="grid grid-cols-2 gap-4 text-sm" id="dbStatusContent">
                        <div>GIF参照: <span id="dbGifCount" class="font-bold">-</span>件</div>
                        <div>WebM参照: <span id="dbWebmCount" class="font-bold text-green-600">-</span>件</div>
                    </div>
                    <div id="dbStatusDetails" class="mt-2 text-xs text-gray-500"></div>
                </div>
                
                <!-- 反映ボタン -->
                <div class="flex flex-wrap gap-2">
                    <button onclick="applyWebmToSite()" class="px-4 py-2 bg-green-500 hover:bg-green-600 text-white rounded font-bold text-sm">
                        <i class="fas fa-check mr-1"></i>WebMをサイトに反映
                    </button>
                    <button onclick="revertToGif(false)" class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded text-sm">
                        <i class="fas fa-undo mr-1"></i>GIFに戻す（DB参照のみ）
                    </button>
                    <button onclick="revertToGif(true)" class="px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded text-sm">
                        <i class="fas fa-trash mr-1"></i>GIFに戻す（WebM削除）
                    </button>
                </div>
            </div>
            
            <!-- 変換モーダル -->
            <div id="convertModal" class="fixed inset-0 bg-black bg-opacity-50 hidden z-50 flex items-center justify-center">
                <div class="bg-white rounded-lg shadow-xl p-6 max-w-lg w-full mx-4">
                    <h3 class="text-lg font-bold mb-4" id="modalTitle"><i class="fas fa-sync-alt mr-1"></i>変換中...</h3>
                    
                    <div class="mb-4">
                        <p class="text-sm text-gray-600 mb-2" id="currentFileName">GIFを録画しています</p>
                        <div class="border rounded p-2 bg-gray-100 flex justify-center">
                            <canvas id="recordCanvas" class="max-w-full" style="max-height: 200px;"></canvas>
                        </div>
                        <img id="sourceGif" class="hidden">
                    </div>
                    
                    <div class="mb-4">
                        <div class="w-full bg-gray-200 rounded-full h-3">
                            <div id="progressBar" class="bg-blue-500 h-3 rounded-full transition-all" style="width: 0%"></div>
                        </div>
                        <p id="progressText" class="text-sm text-gray-600 mt-1">準備中...</p>
                    </div>
                    
                    <!-- 一括変換進捗 -->
                    <div id="batchProgress" class="hidden mb-4 p-3 bg-gray-50 rounded">
                        <p class="text-sm font-bold mb-1">一括変換進捗</p>
                        <p class="text-sm text-gray-600"><span id="batchCurrent">0</span> / <span id="batchTotal">0</span> 件完了</p>
                    </div>
                    
                    <div id="resultArea" class="hidden mb-4 p-3 bg-green-50 rounded">
                        <p class="font-bold text-green-800 mb-2"><i class="fas fa-check-circle mr-1"></i>変換完了</p>
                        <div class="text-sm text-green-700">
                            <span id="resultOriginal"></span> → <span id="resultNew" class="font-bold"></span>
                            (<span id="resultReduction"></span>削減)
                        </div>
                    </div>
                    
                    <div id="errorArea" class="hidden mb-4 p-3 bg-red-50 rounded">
                        <p class="font-bold text-red-800"><i class="fas fa-exclamation-circle mr-1"></i>エラー</p>
                        <p id="errorMessage" class="text-sm text-red-700"></p>
                    </div>
                    
                    <div class="flex justify-end gap-2">
                        <button id="closeModalBtn" class="px-4 py-2 bg-gray-500 hover:bg-gray-600 text-white rounded">
                            閉じる
                        </button>
                    </div>
                </div>
            </div>
            
            <?php if (empty($gifFiles)): ?>
            <div class="p-4 bg-green-50 rounded-lg">
                <p class="text-green-800"><i class="fas fa-check-circle mr-1"></i>アニメーションGIFは見つかりませんでした</p>
            </div>
            <?php else: ?>
            
            <!-- オプション・一括操作 -->
            <div class="mb-4 p-4 bg-gray-50 rounded-lg">
                <div class="flex flex-wrap items-center gap-4 mb-3">
                    <label class="flex items-center gap-2">
                        <input type="checkbox" id="selectAll" class="rounded">
                        <span class="text-sm font-bold">すべて選択</span>
                    </label>
                    <span class="text-gray-400">|</span>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" id="deleteOriginal" class="rounded">
                        <span class="text-sm text-red-600">変換後に元のGIFを削除</span>
                    </label>
                    <label class="flex items-center gap-2">
                        <input type="checkbox" id="overwriteExisting" class="rounded">
                        <span class="text-sm text-orange-600">既存WebMを上書き</span>
                    </label>
                </div>
                <div class="flex flex-wrap gap-2">
                    <button onclick="batchConvert()" class="px-4 py-2 bg-blue-500 hover:bg-blue-600 text-white rounded text-sm font-bold">
                        <i class="fas fa-sync-alt mr-1"></i>選択したGIFを一括変換
                    </button>
                    <button onclick="batchDeleteGif()" class="px-4 py-2 bg-red-500 hover:bg-red-600 text-white rounded text-sm">
                        <i class="fas fa-trash mr-1"></i>選択したGIFを削除
                    </button>
                </div>
            </div>
            
            <!-- ファイル一覧 -->
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead class="bg-gray-100">
                        <tr>
                            <th class="p-2 text-left w-8">
                                <input type="checkbox" id="headerCheckAll" class="rounded">
                            </th>
                            <th class="p-2 text-left">プレビュー</th>
                            <th class="p-2 text-left">ファイルパス</th>
                            <th class="p-2 text-right">サイズ</th>
                            <th class="p-2 text-center">WebM</th>
                            <th class="p-2 text-center">操作</th>
                        </tr>
                    </thead>
                    <tbody id="gifList">
                        <?php foreach ($gifFiles as $gif): ?>
                        <tr class="border-b hover:bg-gray-50 gif-row" 
                            data-path="<?= htmlspecialchars($gif['path']) ?>"
                            data-relative="<?= htmlspecialchars($gif['relative']) ?>"
                            data-size="<?= $gif['size'] ?>"
                            data-webm="<?= $gif['webm_exists'] ? '1' : '0' ?>">
                            <td class="p-2">
                                <input type="checkbox" class="gif-checkbox rounded">
                            </td>
                            <td class="p-2">
                                <img src="<?= htmlspecialchars($gif['relative']) ?>" alt="" class="h-12 w-auto rounded gif-preview" loading="lazy">
                            </td>
                            <td class="p-2 font-mono text-xs break-all"><?= htmlspecialchars($gif['relative']) ?></td>
                            <td class="p-2 text-right font-bold <?= $gif['size'] > 1024*1024 ? 'text-red-600' : ($gif['size'] > 500*1024 ? 'text-orange-600' : '') ?>">
                                <?= formatSize($gif['size']) ?>
                            </td>
                            <td class="p-2 text-center webm-status">
                                <?php if ($gif['webm_exists']): ?>
                                <span class="text-green-600" title="WebM変換済み"><i class="fas fa-check"></i></span>
                                <?php else: ?>
                                <span class="text-gray-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="p-2 text-center">
                                <div class="flex gap-1 justify-center flex-wrap">
                                    <button onclick="convertSingle(this.closest('tr'))" 
                                            class="px-2 py-1 bg-green-500 hover:bg-green-600 text-white rounded text-xs" title="WebMに変換">
                                        <i class="fas fa-exchange-alt"></i>
                                    </button>
                                    <button onclick="deleteGif(this.closest('tr'))" 
                                            class="px-2 py-1 bg-red-500 hover:bg-red-600 text-white rounded text-xs" title="GIFを削除">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                    <?php if ($gif['webm_exists']): ?>
                                    <button onclick="deleteWebm(this.closest('tr'))" 
                                            class="px-2 py-1 bg-orange-500 hover:bg-orange-600 text-white rounded text-xs" title="WebMを削除">
                                        <i class="fas fa-video-slash"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            <?php endif; ?>
        </div>

<!-- GIF解析ライブラリは動的にロード -->
<script>
// グローバル変数を先に宣言
let parseGIF = null;
let decompressFrames = null;
let gifuctLoaded = false;

const modal = document.getElementById('convertModal');
const canvas = document.getElementById('recordCanvas');
const ctx = canvas.getContext('2d');
const sourceGif = document.getElementById('sourceGif');
const progressBar = document.getElementById('progressBar');
const progressText = document.getElementById('progressText');
const resultArea = document.getElementById('resultArea');
const errorArea = document.getElementById('errorArea');
const batchProgress = document.getElementById('batchProgress');

let isConverting = false;
let conversionQueue = [];

// gifuct-jsを動的にロード
function loadGifuct() {
    return new Promise((resolve, reject) => {
        if (gifuctLoaded) {
            resolve();
            return;
        }
        
        const script = document.createElement('script');
        script.src = 'https://cdn.jsdelivr.net/npm/gifuct-js@2.1.2/dist/gifuct-js.umd.js';
        script.onload = () => {
            if (window.gifuct) {
                parseGIF = window.gifuct.parseGIF;
                decompressFrames = window.gifuct.decompressFrames;
                gifuctLoaded = true;
                resolve();
            } else {
                reject(new Error('gifuct-js の読み込みに失敗しました'));
            }
        };
        script.onerror = () => reject(new Error('gifuct-js のダウンロードに失敗しました'));
        document.head.appendChild(script);
    });
}

// チェックボックス連動
document.getElementById('selectAll')?.addEventListener('change', (e) => {
    document.querySelectorAll('.gif-checkbox').forEach(cb => cb.checked = e.target.checked);
    document.getElementById('headerCheckAll').checked = e.target.checked;
});

document.getElementById('headerCheckAll')?.addEventListener('change', (e) => {
    document.querySelectorAll('.gif-checkbox').forEach(cb => cb.checked = e.target.checked);
    document.getElementById('selectAll').checked = e.target.checked;
});

document.getElementById('closeModalBtn').addEventListener('click', () => {
    if (!isConverting) {
        modal.classList.add('hidden');
        location.reload();
    }
});

function formatSize(bytes) {
    if (bytes < 1024) return bytes + ' B';
    if (bytes < 1024 * 1024) return (bytes / 1024).toFixed(1) + ' KB';
    return (bytes / 1024 / 1024).toFixed(2) + ' MB';
}

// 単体変換
function convertSingle(row) {
    const relativePath = row.dataset.relative;
    const fullPath = row.dataset.path;
    conversionQueue = [{ relativePath, fullPath, row }];
    startConversion();
}

// 一括変換
function batchConvert() {
    const selectedRows = document.querySelectorAll('.gif-row');
    conversionQueue = [];
    
    selectedRows.forEach(row => {
        if (row.querySelector('.gif-checkbox').checked) {
            conversionQueue.push({
                relativePath: row.dataset.relative,
                fullPath: row.dataset.path,
                row: row
            });
        }
    });
    
    if (conversionQueue.length === 0) {
        alert('変換するGIFを選択してください');
        return;
    }
    
    startConversion();
}

async function startConversion() {
    isConverting = true;
    modal.classList.remove('hidden');
    resultArea.classList.add('hidden');
    errorArea.classList.add('hidden');
    
    const isBatch = conversionQueue.length > 1;
    
    if (isBatch) {
        batchProgress.classList.remove('hidden');
        document.getElementById('batchTotal').textContent = conversionQueue.length;
        document.getElementById('batchCurrent').textContent = '0';
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-sync-alt mr-1"></i>一括変換中...';
    } else {
        batchProgress.classList.add('hidden');
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-sync-alt mr-1"></i>変換中...';
    }
    
    let successCount = 0;
    let errorCount = 0;
    let totalSaved = 0;
    
    for (let i = 0; i < conversionQueue.length; i++) {
        const item = conversionQueue[i];
        
        if (isBatch) {
            document.getElementById('batchCurrent').textContent = i;
        }
        
        document.getElementById('currentFileName').textContent = item.relativePath;
        
        try {
            const result = await convertGif(item.relativePath, item.fullPath);
            if (result.success) {
                successCount++;
                totalSaved += result.original_size - result.new_size;
                
                // 行のステータス更新
                item.row.dataset.webm = '1';
                item.row.querySelector('.webm-status').innerHTML = '<span class="text-green-600"><i class="fas fa-check"></i></span>';
                
                // 削除オプションが有効なら行を削除
                if (result.deleted) {
                    item.row.remove();
                }
            } else {
                errorCount++;
            }
        } catch (e) {
            errorCount++;
        }
    }
    
    // 完了表示
    if (isBatch) {
        document.getElementById('batchCurrent').textContent = conversionQueue.length;
        document.getElementById('modalTitle').innerHTML = '<i class="fas fa-check-circle mr-1"></i>一括変換完了';
        
        resultArea.classList.remove('hidden');
        resultArea.innerHTML = `
            <p class="font-bold text-green-800 mb-2"><i class="fas fa-check-circle mr-1"></i>一括変換完了</p>
            <div class="text-sm text-green-700">
                成功: ${successCount}件 / 失敗: ${errorCount}件<br>
                合計 ${formatSize(totalSaved)} 削減
            </div>
        `;
    }
    
    isConverting = false;
}

async function convertGif(relativePath, fullPath) {
    progressBar.style.width = '0%';
    progressText.textContent = 'ライブラリを読み込み中...';
    
    const deleteOriginal = document.getElementById('deleteOriginal').checked;
    const overwrite = document.getElementById('overwriteExisting').checked;
    
    return new Promise(async (resolve, reject) => {
        try {
            // gifuct-jsをロード
            await loadGifuct();
            
            progressText.textContent = 'GIFを読み込み中...';
            
            // GIFファイルをfetchで取得
            const response = await fetch(relativePath + '?t=' + Date.now());
            const arrayBuffer = await response.arrayBuffer();
            
            progressBar.style.width = '10%';
            progressText.textContent = 'GIFフレームを解析中...';
            
            // gifuct-jsでGIFを解析
            const gif = parseGIF(arrayBuffer);
            const frames = decompressFrames(gif, true);
            
            if (frames.length === 0) {
                throw new Error('GIFフレームが見つかりません');
            }
            
            progressBar.style.width = '20%';
            progressText.textContent = `${frames.length}フレームを録画中...`;
            
            // キャンバスサイズ設定
            canvas.width = gif.lsd.width;
            canvas.height = gif.lsd.height;
            
            // 合成用の一時キャンバス
            const tempCanvas = document.createElement('canvas');
            tempCanvas.width = canvas.width;
            tempCanvas.height = canvas.height;
            const tempCtx = tempCanvas.getContext('2d');
            
            // MediaRecorder設定
            const stream = canvas.captureStream(30);
            const mimeType = MediaRecorder.isTypeSupported('video/webm;codecs=vp9') 
                ? 'video/webm;codecs=vp9' 
                : 'video/webm';
            const recorder = new MediaRecorder(stream, { mimeType, videoBitsPerSecond: 2500000 });
            const chunks = [];
            
            recorder.ondataavailable = (e) => {
                if (e.data.size > 0) chunks.push(e.data);
            };
            
            // フレームを順番に描画
            let frameIndex = 0;
            const totalDuration = frames.reduce((sum, f) => sum + (f.delay || 100), 0);
            // 最低1ループ、できれば2ループ以上録画（シームレスなループのため）
            let loopsNeeded = Math.max(2, Math.ceil(3000 / totalDuration));
            loopsNeeded = Math.min(loopsNeeded, 10); // 最大10ループ
            
            console.log(`GIF: ${frames.length}フレーム, 1ループ${totalDuration}ms, ${loopsNeeded}ループ録画`);
            
            // 前のフレームを保持（差分描画用）
            let previousImageData = null;
            
            const drawNextFrame = () => {
                return new Promise((res) => {
                    const frame = frames[frameIndex % frames.length];
                    const delay = Math.max(frame.delay || 100, 20); // 最低20ms（MediaRecorder対応）
                    
                    // フレームをImageDataとして作成
                    const imageData = new ImageData(
                        new Uint8ClampedArray(frame.patch),
                        frame.dims.width,
                        frame.dims.height
                    );
                    
                    // disposalMethodに応じた処理
                    // 0: 何もしない, 1: 何もしない, 2: 背景でクリア, 3: 前のフレームに戻す
                    if (frame.disposalType === 2) {
                        tempCtx.clearRect(frame.dims.left, frame.dims.top, frame.dims.width, frame.dims.height);
                    } else if (frame.disposalType === 3 && previousImageData) {
                        tempCtx.putImageData(previousImageData, 0, 0);
                    }
                    
                    // 現在の状態を保存（disposal type 3用）
                    if (frame.disposalType === 3) {
                        previousImageData = tempCtx.getImageData(0, 0, canvas.width, canvas.height);
                    }
                    
                    // 一時キャンバスにフレームを描画
                    tempCtx.putImageData(imageData, frame.dims.left, frame.dims.top);
                    
                    // メインキャンバスにコピー
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    ctx.drawImage(tempCanvas, 0, 0);
                    
                    // 進捗更新
                    const currentLoop = Math.floor(frameIndex / frames.length);
                    const progress = 20 + (frameIndex / (frames.length * loopsNeeded)) * 60;
                    progressBar.style.width = progress + '%';
                    progressText.textContent = `フレーム ${(frameIndex % frames.length) + 1}/${frames.length} (ループ ${currentLoop + 1}/${loopsNeeded})`;
                    
                    frameIndex++;
                    
                    setTimeout(res, delay);
                });
            };
            
            // 最初のフレームを描画してから録画開始
            await drawNextFrame();
            recorder.start();
            
            // 残りのフレームを描画
            const totalFrames = frames.length * loopsNeeded;
            for (let i = 1; i < totalFrames; i++) {
                await drawNextFrame();
            }
            
            recorder.stop();
            
            // 録画完了を待機
            const webmBlob = await new Promise((res) => {
                recorder.onstop = () => {
                    res(new Blob(chunks, { type: 'video/webm' }));
                };
            });
            
            progressBar.style.width = '85%';
            progressText.textContent = 'サーバーに保存中...';
            
            // Base64に変換
            const webmBase64 = await new Promise((res) => {
                const reader = new FileReader();
                reader.onloadend = () => res(reader.result);
                reader.readAsDataURL(webmBlob);
            });
            
            // サーバーに送信
            const formData = new FormData();
            formData.append('action', 'save_webm');
            formData.append('gif_path', fullPath);
            formData.append('webm_data', webmBase64);
            formData.append('delete_original', deleteOriginal ? '1' : '0');
            formData.append('overwrite', overwrite ? '1' : '0');
            
            const saveResponse = await fetch('', {
                method: 'POST',
                body: formData
            });
            
            const result = await saveResponse.json();
            
            if (result.success) {
                progressBar.style.width = '100%';
                progressText.textContent = '完了';
                
                // 単体の場合のみ結果表示
                if (conversionQueue.length === 1) {
                    document.getElementById('resultOriginal').textContent = formatSize(result.original_size);
                    document.getElementById('resultNew').textContent = formatSize(result.new_size);
                    document.getElementById('resultReduction').textContent = result.reduction + '%';
                    resultArea.classList.remove('hidden');
                }
                
                resolve(result);
            } else {
                throw new Error(result.error || '保存に失敗しました');
            }
            
        } catch (error) {
            console.error(error);
            document.getElementById('errorMessage').textContent = error.message;
            errorArea.classList.remove('hidden');
            reject(error);
        }
    });
}

// GIF削除
async function deleteGif(row) {
    if (!confirm('このGIFファイルを削除しますか？')) return;
    
    const fullPath = row.dataset.path;
    
    const formData = new FormData();
    formData.append('action', 'delete_gif');
    formData.append('gif_path', fullPath);
    
    const response = await fetch('', { method: 'POST', body: formData });
    const result = await response.json();
    
    if (result.success) {
        row.remove();
    } else {
        alert('削除に失敗しました: ' + result.error);
    }
}

// 一括GIF削除
async function batchDeleteGif() {
    const selectedRows = Array.from(document.querySelectorAll('.gif-row')).filter(
        row => row.querySelector('.gif-checkbox').checked
    );
    
    if (selectedRows.length === 0) {
        alert('削除するGIFを選択してください');
        return;
    }
    
    if (!confirm(`選択した${selectedRows.length}件のGIFファイルを削除しますか？`)) return;
    
    for (const row of selectedRows) {
        const formData = new FormData();
        formData.append('action', 'delete_gif');
        formData.append('gif_path', row.dataset.path);
        
        const response = await fetch('', { method: 'POST', body: formData });
        const result = await response.json();
        
        if (result.success) {
            row.remove();
        }
    }
    
    alert('削除が完了しました');
}

// WebM削除
async function deleteWebm(row) {
    if (!confirm('このWebMファイルを削除しますか？')) return;
    
    const fullPath = row.dataset.path;
    
    const formData = new FormData();
    formData.append('action', 'delete_webm');
    formData.append('gif_path', fullPath);
    
    const response = await fetch('', { method: 'POST', body: formData });
    const result = await response.json();
    
    if (result.success) {
        row.dataset.webm = '0';
        row.querySelector('.webm-status').innerHTML = '<span class="text-gray-400">-</span>';
        // WebM削除ボタンを非表示
        const deleteWebmBtn = row.querySelector('button[onclick*="deleteWebm"]');
        if (deleteWebmBtn) deleteWebmBtn.remove();
    } else {
        alert('削除に失敗しました: ' + result.error);
    }
}

// DB参照状況を取得
async function refreshDbStatus() {
    const formData = new FormData();
    formData.append('action', 'get_db_status');
    
    const response = await fetch('', { method: 'POST', body: formData });
    const result = await response.json();
    
    if (result.success) {
        document.getElementById('dbGifCount').textContent = result.status.gif_refs;
        document.getElementById('dbWebmCount').textContent = result.status.webm_refs;
        
        // 詳細表示
        let details = [];
        for (const [label, counts] of Object.entries(result.status.details)) {
            if (counts.gif > 0 || counts.webm > 0) {
                details.push(`${label}: GIF ${counts.gif}件 / WebM ${counts.webm}件`);
            }
        }
        document.getElementById('dbStatusDetails').textContent = details.join(' | ');
    }
}

// WebMをサイトに反映
async function applyWebmToSite() {
    if (!confirm('データベースのGIF参照をWebMに更新します。よろしいですか？')) return;
    
    const formData = new FormData();
    formData.append('action', 'apply_webm');
    
    const response = await fetch('', { method: 'POST', body: formData });
    const result = await response.json();
    
    if (result.success) {
        let message = `${result.results.updated}件の参照をWebMに更新しました`;
        if (result.results.skipped > 0) {
            message += `（${result.results.skipped}件はWebMファイルがないためスキップ）`;
        }
        if (Object.keys(result.results.tables).length > 0) {
            message += '\n\n更新したテーブル:\n';
            for (const [table, count] of Object.entries(result.results.tables)) {
                message += `・${table}: ${count}件\n`;
            }
        }
        alert(message);
        refreshDbStatus();
    } else {
        alert('エラー: ' + result.error);
    }
}

// GIFに戻す
async function revertToGif(deleteWebm = false) {
    const msg = deleteWebm 
        ? 'データベースのWebM参照をGIFに戻し、WebMファイルも削除します。この操作は元に戻せません。よろしいですか？'
        : 'データベースのWebM参照をGIFに戻します。WebMファイルは残るため、表示はWebMのままになります。よろしいですか？';
    
    if (!confirm(msg)) return;
    
    const formData = new FormData();
    formData.append('action', 'revert_to_gif');
    formData.append('delete_webm', deleteWebm ? '1' : '0');
    
    const response = await fetch('', { method: 'POST', body: formData });
    const result = await response.json();
    
    if (result.success) {
        let message = `${result.results.updated}件の参照をGIFに戻しました`;
        if (result.results.deleted_files > 0) {
            message += `\n${result.results.deleted_files}件のWebMファイルを削除しました`;
        }
        if (result.results.skipped > 0) {
            message += `\n（${result.results.skipped}件はGIFファイルがないためスキップ）`;
            if (result.results.not_found && result.results.not_found.length > 0) {
                message += `\n\n見つからなかったファイル（最大5件）:\n`;
                message += result.results.not_found.slice(0, 5).join('\n');
            }
        }
        alert(message);
        refreshDbStatus();
        location.reload(); // ファイルリストを更新
    } else {
        alert('エラー: ' + result.error);
    }
}

// ページ読み込み時にDB状況を取得
document.addEventListener('DOMContentLoaded', refreshDbStatus);
</script>

<?php include 'includes/footer.php'; ?>
