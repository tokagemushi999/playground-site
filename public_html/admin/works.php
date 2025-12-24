<?php
/**
 * 作品管理
 */

require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/image-helper.php';
requireAuth();

$db = getDB();
$message = '';

// AJAX: PDFページを1枚ずつアップロード
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['ajax_action'])) {
    header('Content-Type: application/json');
    
    if ($_POST['ajax_action'] === 'upload_pdf_page') {
        $workId = (int)$_POST['work_id'];
        $pageNumber = (int)$_POST['page_number'];
        $totalPages = (int)$_POST['total_pages'];
        $imageData = $_POST['image_data'] ?? '';
        
        if (!$workId || !$pageNumber || !$imageData) {
            echo json_encode(['success' => false, 'error' => 'Invalid data']);
            exit;
        }
        
        $uploadDir = '../uploads/works/pages/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // 1ページ目の場合、既存ページを削除
        if ($pageNumber === 1) {
            $stmt = $db->prepare("SELECT image FROM work_pages WHERE work_id = ?");
            $stmt->execute([$workId]);
            $oldPages = $stmt->fetchAll();
            foreach ($oldPages as $oldPage) {
                if ($oldPage['image'] && file_exists('../' . $oldPage['image'])) {
                    @unlink('../' . $oldPage['image']);
                }
            }
            $stmt = $db->prepare("DELETE FROM work_pages WHERE work_id = ?");
            $stmt->execute([$workId]);
        }
        
        // Base64データをデコードして保存
        $base64Data = preg_replace('/^data:image\/\w+;base64,/', '', $imageData);
        $decodedData = base64_decode($base64Data);
        
        if ($decodedData !== false) {
            // 一時ファイルに保存してWebP変換
            $tempFile = tempnam(sys_get_temp_dir(), 'pdf_page_');
            file_put_contents($tempFile, $decodedData);
            
            $baseName = uniqid('page_');
            $result = ImageHelper::processUpload(
                $tempFile,
                $uploadDir,
                $baseName,
                ['maxWidth' => 1920, 'maxHeight' => 2560]
            );
            unlink($tempFile);
            
            if ($result && isset($result['path'])) {
                $stmt = $db->prepare("INSERT INTO work_pages (work_id, page_number, image) VALUES (?, ?, ?)");
                $stmt->execute([$workId, $pageNumber, 'uploads/works/pages/' . basename($result['path'])]);
                echo json_encode(['success' => true, 'page' => $pageNumber]);
                exit;
            }
        }
        
        echo json_encode(['success' => false, 'error' => 'Failed to save image']);
        exit;
    }
    
    if ($_POST['ajax_action'] === 'clear_pdf_pages') {
        $workId = (int)$_POST['work_id'];
        if ($workId) {
            $stmt = $db->prepare("SELECT image FROM work_pages WHERE work_id = ?");
            $stmt->execute([$workId]);
            $oldPages = $stmt->fetchAll();
            foreach ($oldPages as $oldPage) {
                if ($oldPage['image'] && file_exists('../' . $oldPage['image'])) {
                    @unlink('../' . $oldPage['image']);
                }
            }
            $stmt = $db->prepare("DELETE FROM work_pages WHERE work_id = ?");
            $stmt->execute([$workId]);
        }
        echo json_encode(['success' => true]);
        exit;
    }
    
    echo json_encode(['success' => false, 'error' => 'Unknown action']);
    exit;
}

if (isset($_POST['bulk_archive']) && !empty($_POST['selected_works'])) {
    $ids = $_POST['selected_works'];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("UPDATE works SET is_active = 0 WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $message = count($ids) . '件の作品をアーカイブしました。';
}

if (isset($_POST['bulk_restore']) && !empty($_POST['selected_works'])) {
    $ids = $_POST['selected_works'];
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = $db->prepare("UPDATE works SET is_active = 1 WHERE id IN ($placeholders)");
    $stmt->execute($ids);
    $message = count($ids) . '件の作品を復元しました。';
}

// 単体削除（アーカイブ）
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $stmt = $db->prepare("UPDATE works SET is_active = 0 WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    $message = '作品をアーカイブしました。';
}

// 作品保存処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save'])) {
    $id = $_POST['id'] ?? null;
    $title = $_POST['title'] ?? '';
    $creator_id = $_POST['creator_id'] ?: null;
    $description = $_POST['description'] ?? '';
    $categories = $_POST['categories'] ?? [];
    $category = is_array($categories) ? implode(',', $categories) : '';
    $tags = $_POST['tags'] ?? '';
    $is_featured = isset($_POST['is_featured']) ? 1 : 0;
    $is_omake_sticker = isset($_POST['is_omake_sticker']) ? 1 : 0;
    $sticker_group_id = $_POST['sticker_group_id'] ?: null;
    $sticker_order = (int)($_POST['sticker_order'] ?? 0);
    $sort_order = (int)($_POST['sort_order'] ?? 0);
    $youtube_url = $_POST['youtube_url'] ?? '';
    $thumbnail_type = $_POST['thumbnail_type'] ?? 'image';
    $is_manga = isset($_POST['is_manga']) ? 1 : 0;
    $reading_direction = $_POST['reading_direction'] ?? 'rtl';
    $view_mode = $_POST['view_mode'] ?? 'page';
    $viewer_theme = $_POST['viewer_theme'] ?? 'dark';
    $first_page_single = isset($_POST['first_page_single']) ? 1 : 0;
    $crop_position = $_POST['crop_position'] ?? '50% 50%';
    
    // 画像アップロード処理（WebP変換）
    $image = $_POST['current_image'] ?? '';
    if (!empty($_FILES['image']['name'])) {
        $uploadDir = '../uploads/works/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $baseName = uniqid('work_');
        $result = ImageHelper::processUpload(
            $_FILES['image']['tmp_name'],
            $uploadDir,
            $baseName,
            ['maxWidth' => 1200, 'maxHeight' => 1200]
        );
        if ($result && isset($result['path'])) {
            $image = 'uploads/works/' . basename($result['path']);
        }
    }
    
    // 裏面画像アップロード処理
    $back_image = $_POST['current_back_image'] ?? '';
    if (!empty($_FILES['back_image']['name'])) {
        $uploadDir = '../uploads/works/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $baseName = uniqid('work_back_');
        $result = ImageHelper::processUpload(
            $_FILES['back_image']['tmp_name'],
            $uploadDir,
            $baseName,
            ['maxWidth' => 1200, 'maxHeight' => 1200]
        );
        if ($result && isset($result['path'])) {
            $back_image = 'uploads/works/' . basename($result['path']);
        }
    }
    
    if ($thumbnail_type === 'youtube' && $youtube_url && empty($_FILES['image']['name']) && empty($image)) {
        $videoId = extractYoutubeId($youtube_url);
        if ($videoId) {
            $image = "https://img.youtube.com/vi/{$videoId}/maxresdefault.jpg";
        }
    }
    
    if ($id) {
        $stmt = $db->prepare("UPDATE works SET title=?, creator_id=?, description=?, image=?, back_image=?, category=?, tags=?, is_featured=?, is_omake_sticker=?, sticker_group_id=?, sticker_order=?, sort_order=?, youtube_url=?, thumbnail_type=?, is_manga=?, reading_direction=?, view_mode=?, viewer_theme=?, first_page_single=?, crop_position=? WHERE id=?");
        $stmt->execute([$title, $creator_id, $description, $image, $back_image, $category, $tags, $is_featured, $is_omake_sticker, $sticker_group_id, $sticker_order, $sort_order, $youtube_url, $thumbnail_type, $is_manga, $reading_direction, $view_mode, $viewer_theme, $first_page_single, $crop_position, $id]);
        $message = '作品を更新しました。';
    } else {
        $stmt = $db->prepare("INSERT INTO works (creator_id, title, description, category, image, back_image, youtube_url, is_featured, is_omake_sticker, sticker_group_id, sticker_order, sort_order, is_manga, reading_direction, view_mode, viewer_theme, first_page_single, crop_position) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $stmt->execute([$creator_id, $title, $description, $category, $image, $back_image, $youtube_url, $is_featured, $is_omake_sticker, $sticker_group_id, $sticker_order, $sort_order, $is_manga, $reading_direction, $view_mode, $viewer_theme, $first_page_single, $crop_position]);
        $id = $db->lastInsertId();
        $message = '作品を追加しました。';
    }
    
    if ($is_manga && $id && !empty($_FILES['manga_pages']['name'][0])) {
        $uploadDir = '../uploads/works/pages/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // 既存ページ数を取得
        $stmt = $db->prepare("SELECT MAX(page_number) FROM work_pages WHERE work_id = ?");
        $stmt->execute([$id]);
        $maxPage = (int)$stmt->fetchColumn();
        
        $pageNumber = $maxPage;
        foreach ($_FILES['manga_pages']['name'] as $index => $name) {
            if (!empty($name) && $_FILES['manga_pages']['error'][$index] === UPLOAD_ERR_OK) {
                $baseName = uniqid('page_');
                $result = ImageHelper::processUpload(
                    $_FILES['manga_pages']['tmp_name'][$index],
                    $uploadDir,
                    $baseName,
                    ['maxWidth' => 1920, 'maxHeight' => 2560] // マンガ用に大きめ
                );
                if ($result && isset($result['path'])) {
                    $pageNumber++;
                    $stmt = $db->prepare("INSERT INTO work_pages (work_id, page_number, image) VALUES (?, ?, ?)");
                    $stmt->execute([$id, $pageNumber, 'uploads/works/pages/' . basename($result['path'])]);
                }
            }
        }
    }
    
    // PDF変換済み画像のアップロード処理（クライアントサイドで変換されたもの - Base64形式）
    if ($is_manga && $id && !empty($_POST['pdf_pages_data'])) {
        $uploadDir = '../uploads/works/pages/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        // 既存のページを全て削除
        $stmt = $db->prepare("SELECT image FROM work_pages WHERE work_id = ?");
        $stmt->execute([$id]);
        $oldPages = $stmt->fetchAll();
        foreach ($oldPages as $oldPage) {
            if ($oldPage['image'] && file_exists('../' . $oldPage['image'])) {
                @unlink('../' . $oldPage['image']);
            }
        }
        $stmt = $db->prepare("DELETE FROM work_pages WHERE work_id = ?");
        $stmt->execute([$id]);
        
        // Base64データをデコードして保存
        $pagesData = json_decode($_POST['pdf_pages_data'], true);
        $pageNumber = 0;
        
        if (is_array($pagesData)) {
            foreach ($pagesData as $pageData) {
                if (!empty($pageData)) {
                    // data:image/jpeg;base64,... の形式からデータ部分を抽出
                    $base64Data = preg_replace('/^data:image\/\w+;base64,/', '', $pageData);
                    $imageData = base64_decode($base64Data);
                    
                    if ($imageData !== false) {
                        $pageNumber++;
                        $filename = uniqid('page_') . '.jpg';
                        $filepath = $uploadDir . $filename;
                        
                        if (file_put_contents($filepath, $imageData)) {
                            $stmt = $db->prepare("INSERT INTO work_pages (work_id, page_number, image) VALUES (?, ?, ?)");
                            $stmt->execute([$id, $pageNumber, 'uploads/works/pages/' . $filename]);
                        }
                    }
                }
            }
        }
        
        if ($pageNumber > 0) {
            $message = "PDFから{$pageNumber}ページを保存しました。";
        }
    }
    
    // PDFファイルのアップロード処理（サーバーサイド変換 - フォールバック）
    if ($is_manga && $id && !empty($_FILES['manga_pdf']['name']) && $_FILES['manga_pdf']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = '../uploads/works/pages/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        
        $ext = strtolower(pathinfo($_FILES['manga_pdf']['name'], PATHINFO_EXTENSION));
        if ($ext === 'pdf') {
            $tempPdfPath = $_FILES['manga_pdf']['tmp_name'];
            
            // Imagickが使用可能かチェック
            if (class_exists('Imagick')) {
                try {
                    // 既存のページを全て削除
                    $stmt = $db->prepare("SELECT image FROM work_pages WHERE work_id = ?");
                    $stmt->execute([$id]);
                    $oldPages = $stmt->fetchAll();
                    foreach ($oldPages as $oldPage) {
                        if ($oldPage['image'] && file_exists('../' . $oldPage['image'])) {
                            @unlink('../' . $oldPage['image']);
                        }
                    }
                    $stmt = $db->prepare("DELETE FROM work_pages WHERE work_id = ?");
                    $stmt->execute([$id]);
                    
                    // PDFの各ページを画像に変換
                    $imagick = new Imagick();
                    $imagick->setResolution(150, 150); // 解像度を設定
                    $imagick->readImage($tempPdfPath);
                    
                    $pageCount = $imagick->getNumberImages();
                    $savedPages = 0;
                    
                    for ($i = 0; $i < $pageCount; $i++) {
                        $imagick->setIteratorIndex($i);
                        $imagick->setImageFormat('jpg');
                        $imagick->setImageCompressionQuality(85);
                        
                        // 透過背景を白に
                        $imagick->setImageBackgroundColor('white');
                        $imagick->setImageAlphaChannel(Imagick::ALPHACHANNEL_REMOVE);
                        
                        $filename = uniqid('page_') . '.jpg';
                        $savePath = $uploadDir . $filename;
                        
                        if ($imagick->writeImage($savePath)) {
                            $pageNumber = $i + 1;
                            $stmt = $db->prepare("INSERT INTO work_pages (work_id, page_number, image) VALUES (?, ?, ?)");
                            $stmt->execute([$id, $pageNumber, 'uploads/works/pages/' . $filename]);
                            $savedPages++;
                        }
                    }
                    
                    $imagick->clear();
                    $imagick->destroy();
                    
                    $message = "PDFから{$savedPages}ページを抽出しました。";
                } catch (Exception $e) {
                    $message = 'PDFの処理中にエラーが発生しました: ' . $e->getMessage();
                }
            } else {
                // Imagickが使えない場合はexecでpdftoppmを試す
                $pdfInfo = shell_exec("pdfinfo " . escapeshellarg($tempPdfPath) . " 2>&1");
                
                if (strpos($pdfInfo, 'Pages:') !== false) {
                    // 既存のページを全て削除
                    $stmt = $db->prepare("SELECT image FROM work_pages WHERE work_id = ?");
                    $stmt->execute([$id]);
                    $oldPages = $stmt->fetchAll();
                    foreach ($oldPages as $oldPage) {
                        if ($oldPage['image'] && file_exists('../' . $oldPage['image'])) {
                            @unlink('../' . $oldPage['image']);
                        }
                    }
                    $stmt = $db->prepare("DELETE FROM work_pages WHERE work_id = ?");
                    $stmt->execute([$id]);
                    
                    // pdftoppmで変換
                    $outputPrefix = $uploadDir . 'pdf_' . uniqid();
                    $cmd = "pdftoppm -jpeg -r 150 " . escapeshellarg($tempPdfPath) . " " . escapeshellarg($outputPrefix);
                    exec($cmd, $output, $returnCode);
                    
                    if ($returnCode === 0) {
                        // 生成されたJPGファイルを収集
                        $generatedFiles = glob($outputPrefix . '*.jpg');
                        sort($generatedFiles, SORT_NATURAL);
                        
                        $pageNumber = 0;
                        foreach ($generatedFiles as $file) {
                            $pageNumber++;
                            $newFilename = uniqid('page_') . '.jpg';
                            $newPath = $uploadDir . $newFilename;
                            rename($file, $newPath);
                            
                            $stmt = $db->prepare("INSERT INTO work_pages (work_id, page_number, image) VALUES (?, ?, ?)");
                            $stmt->execute([$id, $pageNumber, 'uploads/works/pages/' . $newFilename]);
                        }
                        
                        $message = "PDFから{$pageNumber}ページを抽出しました。";
                    } else {
                        $message = 'PDFの変換に失敗しました。サーバーにpdftoppmがインストールされていない可能性があります。';
                    }
                } else {
                    $message = 'PDFの変換に失敗しました。サーバーにImagickまたはpoppler-utilsがインストールされていません。';
                }
            }
        }
    }
    
    // ページ削除
    if (!empty($_POST['delete_pages'])) {
        $deleteIds = is_array($_POST['delete_pages']) ? $_POST['delete_pages'] : [$_POST['delete_pages']];
        foreach ($deleteIds as $pageId) {
            $stmt = $db->prepare("SELECT image FROM work_pages WHERE id = ?");
            $stmt->execute([$pageId]);
            $pageImage = $stmt->fetchColumn();
            if ($pageImage && file_exists('../' . $pageImage)) {
                unlink('../' . $pageImage);
            }
            $stmt = $db->prepare("DELETE FROM work_pages WHERE id = ?");
            $stmt->execute([$pageId]);
        }
    }
    
    // ページ順序更新
    if (!empty($_POST['page_order']) && $id) {
        $pageIds = array_filter(explode(',', $_POST['page_order']));
        if (!empty($pageIds)) {
            // まず全ページを一時的に負の値に設定
            $stmt = $db->prepare("UPDATE work_pages SET page_number = -page_number WHERE work_id = ?");
            $stmt->execute([$id]);
            
            // 新しい順序で更新
            $pageNum = 1;
            foreach ($pageIds as $pageId) {
                $stmt = $db->prepare("UPDATE work_pages SET page_number = ? WHERE id = ? AND work_id = ?");
                $stmt->execute([$pageNum, $pageId, $id]);
                $pageNum++;
            }
        }
    }
    
    // 編集画面にリダイレクト
    header("Location: works.php?edit={$id}&saved=1");
    exit;
}

// YouTube ID抽出
function extractYoutubeId($url) {
    preg_match('/(?:youtube\.com\/(?:watch\?v=|embed\/)|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $matches);
    return $matches[1] ?? null;
}

// データ取得
$showArchived = isset($_GET['archived']);
if ($showArchived) {
    $works = $db->query("SELECT w.*, c.name as creator_name FROM works w LEFT JOIN creators c ON w.creator_id = c.id WHERE w.is_active = 0 ORDER BY w.id DESC")->fetchAll();
} else {
    $works = $db->query("SELECT w.*, c.name as creator_name FROM works w LEFT JOIN creators c ON w.creator_id = c.id WHERE w.is_active = 1 ORDER BY w.sort_order DESC, w.id DESC")->fetchAll();
}

$creators = $db->query("SELECT id, name, image FROM creators WHERE is_active = 1 ORDER BY name")->fetchAll();

// ステッカーグループ一覧を取得
$stickerGroups = $db->query("SELECT id, title FROM sticker_groups WHERE is_active = 1 ORDER BY sort_order ASC, title ASC")->fetchAll();

$editWork = null;
$mangaPages = [];
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM works WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editWork = $stmt->fetch();
    
    if ($editWork) {
        $stmt = $db->prepare("SELECT * FROM work_pages WHERE work_id = ? ORDER BY page_number");
        $stmt->execute([$editWork['id']]);
        $mangaPages = $stmt->fetchAll();
    }
}

// 保存完了メッセージ
if (isset($_GET['saved'])) {
    $message = '作品を保存しました。';
}

$categoryOptions = [
    'illustration' => 'イラスト',
    'manga' => 'マンガ',
    'animation' => 'アニメーション',
    'video' => '動画',
    'music' => '音楽',
    'live2d' => 'Live2D',
    'logo' => 'ロゴ',
    'web' => 'Webデザイン',
    'other' => 'その他'
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>作品管理 | 管理画面</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@400;500;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.min.js"></script>
    <script>
        pdfjsLib.GlobalWorkerOptions.workerSrc = 'https://cdnjs.cloudflare.com/ajax/libs/pdf.js/3.11.174/pdf.worker.min.js';
    </script>
    <style>
        body { font-family: 'Zen Maru Gothic', sans-serif; }
        .tab-content { display: none; }
        .tab-content.active { display: block; }
        .tab-btn.active { background: #FBBF24; color: #1F2937; }
        .sortable-ghost { opacity: 0.4; }
    </style>
</head>
<body class="bg-gray-50">
    <?php include 'includes/sidebar.php'; ?>
    
    <!-- Main Content -->
    <main class="lg:ml-64 p-8 pt-20 lg:pt-8">
        <!-- Updated header and removed old sidebar -->
        <div class="flex justify-between items-center mb-8">
            <div>
                <h2 class="text-2xl font-bold text-gray-800">作品管理</h2>
                <p class="text-gray-500">作品の追加・編集・漫画ページ管理</p>
            </div>
            <?php if (!$editWork && !isset($_GET['new'])): ?>
            <a href="works.php?new=1" class="bg-yellow-400 hover:bg-yellow-500 text-gray-900 font-bold px-6 py-3 rounded-lg transition">
                <i class="fas fa-plus mr-2"></i>新規追加
            </a>
            <?php endif; ?>
        </div>
        
        <?php if ($message): ?>
        <div class="bg-green-50 border border-green-200 text-green-600 px-4 py-3 rounded-lg mb-6">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
        
        <?php if ($editWork || isset($_GET['new'])): ?>
        <!-- 編集・新規フォーム -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
            <div class="flex justify-between items-center mb-6">
                <h3 class="font-bold text-gray-800 text-lg">
                    <?= $editWork ? '作品編集' : '新規作品' ?>
                </h3>
                <a href="works.php" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times text-xl"></i>
                </a>
            </div>
            
            <!-- タブをJavaScriptベースに変更（URLベースではなく） -->
            <div class="flex gap-2 mb-6 border-b border-gray-200 pb-4">
                <button type="button" onclick="switchTab('basic')" id="tab-btn-basic" class="tab-btn active px-4 py-2 rounded-lg font-bold text-sm transition">
                    <i class="fas fa-info-circle mr-1"></i>基本情報
                </button>
                <button type="button" onclick="switchTab('manga')" id="tab-btn-manga" class="tab-btn px-4 py-2 rounded-lg font-bold text-sm bg-gray-100 hover:bg-gray-200 transition">
                    <i class="fas fa-book-open mr-1"></i>漫画設定
                </button>
            </div>
            
            <!-- 1つのフォームに全フィールドを含める -->
            <form method="POST" enctype="multipart/form-data">
                <input type="hidden" name="id" value="<?= $editWork['id'] ?? '' ?>">
                <input type="hidden" name="current_image" value="<?= htmlspecialchars($editWork['image'] ?? '') ?>">
                
                <!-- 基本情報タブ -->
                <div id="tab-basic" class="tab-content space-y-6 active">
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">タイトル</label>
                            <input type="text" name="title" value="<?= htmlspecialchars($editWork['title'] ?? '') ?>" 
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">クリエイター</label>
                            <select name="creator_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                                <option value="">選択してください</option>
                                <?php foreach ($creators as $c): ?>
                                    <option value="<?= $c['id'] ?>" <?= ($editWork['creator_id'] ?? '') == $c['id'] ? 'selected' : '' ?>><?= htmlspecialchars($c['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">説明</label>
                        <textarea name="description" rows="3" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"><?= htmlspecialchars($editWork['description'] ?? '') ?></textarea>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">カテゴリ</label>
                            <div class="flex flex-wrap gap-3">
                                <?php 
                                $selectedCategories = explode(',', $editWork['category'] ?? '');
                                foreach ($categoryOptions as $val => $label): 
                                ?>
                                    <label class="inline-flex items-center cursor-pointer">
                                        <input type="checkbox" name="categories[]" value="<?= $val ?>" 
                                            <?= in_array($val, $selectedCategories) ? 'checked' : '' ?> 
                                            class="rounded text-yellow-500 focus:ring-yellow-400 mr-2">
                                        <span class="text-sm"><?= $label ?></span>
                                    </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">タグ（カンマ区切り）</label>
                            <input type="text" name="tags" value="<?= htmlspecialchars($editWork['tags'] ?? '') ?>" 
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                placeholder="例: キャラデザ,オリジナル">
                        </div>
                    </div>
                    
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">サムネイル種別</label>
                            <select name="thumbnail_type" id="thumbnail_type" onchange="toggleThumbnailFields()" 
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                                <option value="image" <?= ($editWork['thumbnail_type'] ?? 'image') === 'image' ? 'selected' : '' ?>>画像</option>
                                <option value="youtube" <?= ($editWork['thumbnail_type'] ?? '') === 'youtube' ? 'selected' : '' ?>>YouTube</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">表示順</label>
                            <input type="number" name="sort_order" value="<?= $editWork['sort_order'] ?? 0 ?>" 
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                        </div>
                    </div>
                    
                    <!-- CHANGE: ピックアップ表示チェックボックスを追加 -->
                    <div class="flex items-center gap-3 p-4 bg-yellow-50 rounded-lg border border-yellow-200">
                        <input type="checkbox" name="is_featured" id="is_featured" 
                            <?= ($editWork['is_featured'] ?? false) ? 'checked' : '' ?>
                            class="w-5 h-5 text-yellow-500 rounded focus:ring-yellow-400">
                        <div>
                            <label for="is_featured" class="text-sm font-bold text-gray-800 cursor-pointer">ピックアップに表示</label>
                            <p class="text-xs text-gray-500">トップページのピックアップスライダーに表示します</p>
                        </div>
                    </div>
                    
                    <!-- ステッカー設定 -->
                    <div class="p-4 bg-purple-50 rounded-lg border border-purple-200">
                        <div class="flex items-center gap-3 mb-4">
                            <input type="checkbox" name="is_omake_sticker" id="is_omake_sticker" 
                                <?= ($editWork['is_omake_sticker'] ?? false) ? 'checked' : '' ?>
                                onchange="toggleStickerFields()"
                                class="w-5 h-5 text-purple-500 rounded focus:ring-purple-400">
                            <div>
                                <label for="is_omake_sticker" class="text-sm font-bold text-gray-800 cursor-pointer">ステッカーとして表示</label>
                                <p class="text-xs text-gray-500">正方形の小さなステッカーとして、イラストセクション内に表示します</p>
                            </div>
                        </div>
                        
                        <div id="sticker_fields" style="display: <?= ($editWork['is_omake_sticker'] ?? false) ? 'block' : 'none' ?>;" class="space-y-4 pt-4 border-t border-purple-200">
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">
                                    ステッカーグループ
                                    <a href="sticker_groups.php" target="_blank" class="text-xs text-purple-500 hover:underline ml-2">
                                        <i class="fas fa-external-link-alt"></i> グループ管理
                                    </a>
                                </label>
                                <select name="sticker_group_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-400 outline-none">
                                    <option value="">選択してください</option>
                                    <?php foreach ($stickerGroups as $group): ?>
                                    <option value="<?= $group['id'] ?>" <?= ($editWork['sticker_group_id'] ?? '') == $group['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($group['title']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <p class="text-xs text-gray-500 mt-1">グループに属さない場合は、単独ステッカーとして表示されます</p>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">グループ内表示順</label>
                                <input type="number" name="sticker_order" value="<?= $editWork['sticker_order'] ?? 0 ?>" 
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-400 outline-none">
                                <p class="text-xs text-gray-500 mt-1">グループ内での表示順序（数字が小さいほど前に表示）</p>
                            </div>
                            
                            <!-- 表面・裏面画像を横並びで表示 -->
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-3">
                                    <i class="fas fa-images mr-1 text-purple-500"></i>ステッカー画像
                                </label>
                                <div class="grid grid-cols-2 gap-3 sm:gap-4">
                                    <!-- 表面 -->
                                    <div class="bg-white rounded-lg border-2 border-gray-200 p-2 sm:p-3">
                                        <div class="text-center mb-2">
                                            <span class="inline-block bg-gray-700 text-white text-xs font-bold px-2 sm:px-3 py-1 rounded-full">表面</span>
                                        </div>
                                        <?php if (!empty($editWork['image'])): ?>
                                        <div class="relative mb-2 sm:mb-3">
                                            <img src="../<?= htmlspecialchars($editWork['image']) ?>" 
                                                class="w-full aspect-square object-cover rounded-lg border border-gray-300">
                                        </div>
                                        <?php else: ?>
                                        <div class="w-full aspect-square bg-gray-100 rounded-lg border-2 border-dashed border-gray-300 flex items-center justify-center mb-2 sm:mb-3">
                                            <div class="text-center text-gray-400">
                                                <i class="fas fa-image text-2xl sm:text-3xl mb-1 sm:mb-2"></i>
                                                <p class="text-xs">未設定</p>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        <input type="file" name="image" accept="image/*" 
                                            class="w-full text-xs px-1 sm:px-2 py-1 sm:py-1.5 border border-gray-300 rounded-lg">
                                        <p class="text-xs text-gray-500 mt-1 text-center hidden sm:block">サムネイルに使用</p>
                                    </div>
                                    
                                    <!-- 裏面 -->
                                    <div class="bg-white rounded-lg border-2 border-purple-200 p-2 sm:p-3">
                                        <div class="text-center mb-2">
                                            <span class="inline-block bg-purple-600 text-white text-xs font-bold px-2 sm:px-3 py-1 rounded-full">裏面</span>
                                        </div>
                                        <?php if (!empty($editWork['back_image'])): ?>
                                        <div class="relative mb-2 sm:mb-3">
                                            <img src="../<?= htmlspecialchars($editWork['back_image']) ?>" 
                                                class="w-full aspect-square object-cover rounded-lg border border-purple-300">
                                        </div>
                                        <?php else: ?>
                                        <div class="w-full aspect-square bg-purple-50 rounded-lg border-2 border-dashed border-purple-300 flex items-center justify-center mb-2 sm:mb-3">
                                            <div class="text-center text-purple-400">
                                                <i class="fas fa-image text-2xl sm:text-3xl mb-1 sm:mb-2"></i>
                                                <p class="text-xs">任意</p>
                                            </div>
                                        </div>
                                        <?php endif; ?>
                                        <input type="file" name="back_image" accept="image/*" 
                                            class="w-full text-xs px-1 sm:px-2 py-1 sm:py-1.5 border border-purple-300 rounded-lg">
                                        <input type="hidden" name="current_back_image" value="<?= htmlspecialchars($editWork['back_image'] ?? '') ?>">
                                        <p class="text-xs text-purple-600 mt-1 text-center hidden sm:block">裏返し機能用</p>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <!-- 通常画像（ステッカーでない場合のみ表示） -->
                    <div id="image_field">
                        <label class="block text-sm font-bold text-gray-700 mb-2">サムネイル画像</label>
                        <div class="flex gap-4 items-start">
                            <?php if (!empty($editWork['image'])): ?>
                            <img src="../<?= htmlspecialchars($editWork['image']) ?>" class="w-32 h-24 object-cover rounded-lg border hover:border-yellow-400 transition">
                            <?php endif; ?>
                            <input type="file" name="image" accept="image/*" 
                                class="flex-1 px-4 py-2 border border-gray-300 rounded-lg">
                        </div>
                    </div>
                    
                    <div id="youtube_field" class="hidden">
                        <label class="block text-sm font-bold text-gray-700 mb-2">YouTube URL</label>
                        <input type="text" name="youtube_url" value="<?= htmlspecialchars($editWork['youtube_url'] ?? '') ?>" 
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none" 
                            placeholder="https://www.youtube.com/watch?v=...">
                    </div>
                    
                    <!-- CHANGE: トリミング位置をXYスライダーで柔軟に設定 -->
                    <div class="mb-6">
                        <label class="block text-sm font-bold text-gray-700 mb-3">
                            <i class="fas fa-crop-alt mr-1 text-yellow-500"></i>トリミング位置（ピックアップ表示用）
                        </label>
                        <p class="text-xs text-gray-500 mb-3">横長にトリミングされるときに、画像のどの部分を表示するか設定します</p>
                        
                        <?php 
                        // 既存のcrop_positionからXY値を取得
                        $cropPos = $editWork['crop_position'] ?? '50% 50%';
                        // 旧形式の場合は変換
                        $oldToNew = [
                            'center' => '50% 50%', 'top left' => '0% 0%', 'top' => '50% 0%', 'top right' => '100% 0%',
                            'left' => '0% 50%', 'right' => '100% 50%', 'bottom left' => '0% 100%', 
                            'bottom' => '50% 100%', 'bottom right' => '100% 100%'
                        ];
                        if (isset($oldToNew[$cropPos])) {
                            $cropPos = $oldToNew[$cropPos];
                        }
                        preg_match('/(\d+)%\s*(\d+)%/', $cropPos, $matches);
                        $cropX = isset($matches[1]) ? (int)$matches[1] : 50;
                        $cropY = isset($matches[2]) ? (int)$matches[2] : 50;
                        ?>
                        
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <!-- スライダーコントロール -->
                            <div class="space-y-4">
                                <div>
                                    <div class="flex justify-between items-center mb-1">
                                        <span class="text-xs font-bold text-gray-600">横位置 (X)</span>
                                        <span id="crop-x-value" class="text-xs font-bold text-yellow-600"><?= $cropX ?>%</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs text-gray-400">左</span>
                                        <input type="range" id="crop-x-slider" min="0" max="100" value="<?= $cropX ?>" 
                                            class="flex-1 h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-yellow-500"
                                            oninput="updateCropPosition()">
                                        <span class="text-xs text-gray-400">右</span>
                                    </div>
                                </div>
                                <div>
                                    <div class="flex justify-between items-center mb-1">
                                        <span class="text-xs font-bold text-gray-600">縦位置 (Y)</span>
                                        <span id="crop-y-value" class="text-xs font-bold text-yellow-600"><?= $cropY ?>%</span>
                                    </div>
                                    <div class="flex items-center gap-2">
                                        <span class="text-xs text-gray-400">上</span>
                                        <input type="range" id="crop-y-slider" min="0" max="100" value="<?= $cropY ?>" 
                                            class="flex-1 h-2 bg-gray-200 rounded-lg appearance-none cursor-pointer accent-yellow-500"
                                            oninput="updateCropPosition()">
                                        <span class="text-xs text-gray-400">下</span>
                                    </div>
                                </div>
                                <!-- プリセットボタン -->
                                <div class="flex flex-wrap gap-2 pt-2">
                                    <button type="button" onclick="setCropPreset(0,0)" class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 rounded">左上</button>
                                    <button type="button" onclick="setCropPreset(50,0)" class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 rounded">上</button>
                                    <button type="button" onclick="setCropPreset(100,0)" class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 rounded">右上</button>
                                    <button type="button" onclick="setCropPreset(0,50)" class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 rounded">左</button>
                                    <button type="button" onclick="setCropPreset(50,50)" class="text-xs px-2 py-1 bg-yellow-100 hover:bg-yellow-200 rounded font-bold">中央</button>
                                    <button type="button" onclick="setCropPreset(100,50)" class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 rounded">右</button>
                                    <button type="button" onclick="setCropPreset(0,100)" class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 rounded">左下</button>
                                    <button type="button" onclick="setCropPreset(50,100)" class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 rounded">下</button>
                                    <button type="button" onclick="setCropPreset(100,100)" class="text-xs px-2 py-1 bg-gray-100 hover:bg-gray-200 rounded">右下</button>
                                </div>
                                <!-- Hidden input for form submission -->
                                <input type="hidden" name="crop_position" id="crop-position-input" value="<?= $cropX ?>% <?= $cropY ?>%">
                            </div>
                            
                            <!-- プレビュー -->
                            <?php if (!empty($editWork['image'])): ?>
                            <div>
                                <p class="text-xs text-gray-500 mb-2">プレビュー（16:9）</p>
                                <div class="relative bg-gray-100 rounded-lg overflow-hidden" style="aspect-ratio: 16/9;">
                                    <img id="crop-preview-img" src="../<?= htmlspecialchars($editWork['image']) ?>" 
                                        class="w-full h-full object-cover"
                                        style="object-position: <?= $cropX ?>% <?= $cropY ?>%;">
                                    <!-- ポジションインジケーター -->
                                    <div id="crop-indicator" class="absolute w-4 h-4 bg-yellow-500 rounded-full border-2 border-white shadow-lg transform -translate-x-1/2 -translate-y-1/2 pointer-events-none"
                                        style="left: <?= $cropX ?>%; top: <?= $cropY ?>%;"></div>
                                </div>
                                <p class="text-xs text-gray-400 mt-1 text-center">黄色い点が表示の中心になります</p>
                            </div>
                            <?php else: ?>
                            <div class="flex items-center justify-center bg-gray-100 rounded-lg" style="aspect-ratio: 16/9;">
                                <p class="text-xs text-gray-400">画像をアップロードするとプレビューが表示されます</p>
                            </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <!-- タブ2: 漫画設定 -->
                <div id="tab-manga" class="tab-content space-y-6">
                    <div class="flex items-center gap-4 p-4 bg-purple-50 rounded-lg">
                        <label class="flex items-center cursor-pointer">
                            <input type="checkbox" name="is_manga" id="is_manga" 
                                <?= ($editWork['is_manga'] ?? 0) ? 'checked' : '' ?> 
                                class="rounded text-purple-500 focus:ring-purple-400 mr-2"
                                onchange="toggleMangaOptions()">
                            <span class="font-bold text-purple-800">この作品は漫画です</span>
                        </label>
                    </div>
                    
                    <div id="manga_options" class="<?= ($editWork['is_manga'] ?? 0) ? '' : 'hidden' ?> space-y-6">
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">読み方向</label>
                            <select name="reading_direction" class="w-full max-w-xs px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                                <option value="rtl" <?= ($editWork['reading_direction'] ?? 'rtl') === 'rtl' ? 'selected' : '' ?>>右から左（日本の漫画）</option>
                                <option value="ltr" <?= ($editWork['reading_direction'] ?? 'rtl') === 'ltr' ? 'selected' : '' ?>>左から右（海外コミック）</option>
                            </select>
                        </div>
                        
                        <!-- ビューモードとテーマ設定を追加 -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">ビューモード</label>
                                <select name="view_mode" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                                    <option value="page" <?= ($editWork['view_mode'] ?? 'page') === 'page' ? 'selected' : '' ?>>ページめくり</option>
                                    <option value="scroll" <?= ($editWork['view_mode'] ?? 'page') === 'scroll' ? 'selected' : '' ?>>縦読み（スクロール）</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">ビューアテーマ</label>
                                <select name="viewer_theme" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                                    <option value="dark" <?= ($editWork['viewer_theme'] ?? 'dark') === 'dark' ? 'selected' : '' ?>>ダーク</option>
                                    <option value="light" <?= ($editWork['viewer_theme'] ?? 'dark') === 'light' ? 'selected' : '' ?>>ライト</option>
                                    <option value="sepia" <?= ($editWork['viewer_theme'] ?? 'dark') === 'sepia' ? 'selected' : '' ?>>セピア</option>
                                </select>
                            </div>
                        </div>
                        
                        <!-- CHANGE: 1ページ目の見開き設定を追加 -->
                        <div class="mt-4">
                            <label class="flex items-center gap-3 cursor-pointer">
                                <input type="checkbox" name="first_page_single" value="1" 
                                    <?= ($editWork['first_page_single'] ?? 1) ? 'checked' : '' ?>
                                    class="w-5 h-5 rounded border-gray-300 text-purple-600 focus:ring-purple-500">
                                <span class="text-sm text-gray-700">1ページ目を単ページで表示（表紙用）</span>
                            </label>
                            <p class="text-xs text-gray-500 mt-1 ml-8">見開き表示時、1ページ目を単独で表示します。チェックを外すと1-2ページが見開きになります。</p>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">ページを追加（画像ファイル）</label>
                            <input type="file" name="manga_pages[]" multiple accept="image/*" 
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg">
                            <p class="text-xs text-gray-500 mt-1">
                                PNG, JPG, WebPなどの画像ファイルを複数選択できます。
                            </p>
                        </div>
                        
                        <!-- PDFアップロード専用エリア（クライアントサイド変換） -->
                        <div class="p-4 bg-purple-50 rounded-lg border-2 border-dashed border-purple-200">
                            <label class="block text-sm font-bold text-purple-700 mb-2">
                                <i class="fas fa-file-pdf mr-2"></i>PDFファイルをアップロード（自動ページ分割）
                            </label>
                            <input type="file" id="pdf_file_input" accept=".pdf" 
                                class="w-full px-4 py-2 border border-purple-300 rounded-lg bg-white"
                                onchange="handlePdfUpload(this)">
                            <input type="hidden" name="pdf_pages_data" id="pdf_pages_data" value="">
                            
                            <!-- 変換進捗表示 -->
                            <div id="pdf_progress" class="hidden mt-4">
                                <div class="flex items-center gap-2 mb-2">
                                    <i class="fas fa-spinner fa-spin text-purple-600"></i>
                                    <span id="pdf_progress_text" class="text-sm text-purple-700">PDFを変換中...</span>
                                </div>
                                <div class="w-full bg-purple-200 rounded-full h-2">
                                    <div id="pdf_progress_bar" class="bg-purple-600 h-2 rounded-full transition-all" style="width: 0%"></div>
                                </div>
                            </div>
                            
                            <!-- 変換結果プレビュー -->
                            <div id="pdf_preview" class="hidden mt-4">
                                <div class="flex items-center justify-between mb-2">
                                    <span class="text-sm font-bold text-purple-700">
                                        <i class="fas fa-check-circle text-green-500 mr-1"></i>
                                        <span id="pdf_page_count">0</span>ページを読み込みました
                                    </span>
                                    <button type="button" onclick="clearPdfPages()" class="text-xs text-red-500 hover:text-red-700">
                                        <i class="fas fa-times mr-1"></i>クリア
                                    </button>
                                </div>
                                <div id="pdf_preview_grid" class="grid grid-cols-6 sm:grid-cols-8 md:grid-cols-10 gap-2 max-h-48 overflow-y-auto p-2 bg-white rounded-lg border border-purple-200">
                                </div>
                            </div>
                            
                            <p class="text-xs text-purple-600 mt-2">
                                <strong>※ PDFは自動的に各ページに分割されます。</strong><br>
                                サーバーの設定に関わらず、ブラウザ上で画像に変換されます。
                            </p>
                        </div>
                        
                        <?php if (!empty($mangaPages)): ?>
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">
                                現在のページ（<?= count($mangaPages) ?>ページ）
                                <span class="text-xs font-normal text-gray-500 ml-2">ドラッグで並び替え可能</span>
                            </label>
                            <div id="page-list" class="grid grid-cols-4 sm:grid-cols-6 md:grid-cols-8 gap-3">
                                <?php foreach ($mangaPages as $page): 
                                    $isPdf = strtolower(pathinfo($page['image'], PATHINFO_EXTENSION)) === 'pdf';
                                ?>
                                <div class="relative group cursor-move" data-page-id="<?= $page['id'] ?>">
                                    <?php if ($isPdf): ?>
                                    <div class="w-full aspect-[3/4] bg-purple-100 rounded-lg border flex flex-col items-center justify-center hover:border-yellow-400 transition">
                                        <i class="fas fa-file-pdf text-4xl text-purple-500 mb-2"></i>
                                        <span class="text-xs text-purple-700 font-bold">PDF</span>
                                    </div>
                                    <?php else: ?>
                                    <img src="../<?= htmlspecialchars($page['image']) ?>" 
                                        class="w-full aspect-[3/4] object-cover rounded-lg border hover:border-yellow-400 transition">
                                    <?php endif; ?>
                                    <div class="absolute top-1 left-1 bg-black/70 text-white text-xs px-2 py-0.5 rounded"><?= $page['page_number'] ?></div>
                                    <label class="absolute top-1 right-1 cursor-pointer">
                                        <input type="checkbox" name="delete_pages[]" value="<?= $page['id'] ?>" class="sr-only peer">
                                        <span class="flex items-center justify-center w-6 h-6 bg-white/90 rounded-full text-gray-400 peer-checked:bg-red-500 peer-checked:text-white hover:bg-red-100 transition">
                                            <i class="fas fa-trash text-xs"></i>
                                        </span>
                                    </label>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <input type="hidden" name="page_order" id="page_order">
                            <p class="text-xs text-gray-500 mt-2">
                                <i class="fas fa-info-circle mr-1"></i>チェックしたページは保存時に削除されます
                            </p>
                        </div>
                        <?php else: ?>
                        <div class="text-center py-8 bg-gray-50 rounded-lg">
                            <i class="fas fa-book-open text-4xl text-gray-300 mb-3"></i>
                            <p class="text-gray-500">まだページがありません</p>
                            <p class="text-sm text-gray-400">上のファイル選択からページを追加してください</p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="mt-8 pt-6 border-t flex gap-4">
                    <button type="submit" name="save" class="bg-yellow-400 hover:bg-yellow-500 text-gray-900 font-bold px-8 py-3 rounded-lg transition">
                        <i class="fas fa-save mr-2"></i>保存
                    </button>
                    <a href="works.php" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-6 py-3 rounded-lg transition">キャンセル</a>
                </div>
            </form>
        </div>
        
        <?php else: ?>
        <!-- 作品一覧 -->
        <div class="flex gap-4 mb-6">
            <a href="works.php" class="px-4 py-2 rounded-lg text-sm font-bold transition <?= !$showArchived ? 'bg-yellow-400 text-gray-900' : 'bg-white text-gray-600 hover:bg-gray-100' ?>">
                公開中
            </a>
            <a href="works.php?archived=1" class="px-4 py-2 rounded-lg text-sm font-bold transition <?= $showArchived ? 'bg-gray-600 text-white' : 'bg-white text-gray-600 hover:bg-gray-100' ?>">
                アーカイブ
            </a>
        </div>
        
        <form method="POST" id="bulk-form">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="p-4 border-b flex gap-2 bg-gray-50">
                    <?php if ($showArchived): ?>
                        <button type="submit" name="bulk_restore" class="bg-green-500 text-white px-4 py-2 rounded-lg text-sm font-bold hover:bg-green-600 disabled:opacity-50 transition" disabled id="restore-btn">
                            <i class="fas fa-undo mr-1"></i>選択を復元
                        </button>
                    <?php else: ?>
                        <button type="submit" name="bulk_archive" class="bg-gray-500 text-white px-4 py-2 rounded-lg text-sm font-bold hover:bg-gray-600 disabled:opacity-50 transition" disabled id="archive-btn">
                            <i class="fas fa-archive mr-1"></i>選択をアーカイブ
                        </button>
                    <?php endif; ?>
                </div>
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left w-10">
                                <input type="checkbox" id="select-all" class="rounded text-yellow-500 focus:ring-yellow-400">
                            </th>
                            <th class="px-4 py-3 text-left w-20">画像</th>
                            <th class="px-4 py-3 text-left">タイトル</th>
                            <th class="px-4 py-3 text-left hidden md:table-cell">クリエイター</th>
                            <th class="px-4 py-3 text-left hidden md:table-cell">カテゴリ</th>
                            <th class="px-4 py-3 text-center w-24">操作</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($works as $work): ?>
                        <tr class="border-t hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <input type="checkbox" name="selected_works[]" value="<?= $work['id'] ?>" class="rounded text-yellow-500 focus:ring-yellow-400 work-checkbox">
                            </td>
                            <td class="px-4 py-3">
                                <?php if ($work['image']): ?>
                                    <img src="../<?= htmlspecialchars($work['image']) ?>" class="w-16 h-12 object-cover rounded">
                                <?php else: ?>
                                    <div class="w-16 h-12 bg-gray-200 rounded flex items-center justify-center text-gray-400">
                                        <i class="fas fa-image"></i>
                                    </div>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-medium text-gray-800"><?= htmlspecialchars($work['title'] ?: '無題') ?></div>
                                <div class="flex gap-1 mt-1">
                                    <?php if ($work['is_featured']): ?>
                                        <span class="text-xs bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded font-bold">PICK UP</span>
                                    <?php endif; ?>
                                    <?php if ($work['is_manga'] ?? 0): ?>
                                        <span class="text-xs bg-purple-100 text-purple-700 px-2 py-0.5 rounded">漫画</span>
                                    <?php endif; ?>
                                    <?php if ($work['thumbnail_type'] === 'youtube'): ?>
                                        <span class="text-xs bg-red-100 text-red-700 px-2 py-0.5 rounded">YouTube</span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td class="px-4 py-3 hidden md:table-cell text-gray-600"><?= htmlspecialchars($work['creator_name'] ?? '-') ?></td>
                            <td class="px-4 py-3 hidden md:table-cell text-gray-600 text-sm"><?= htmlspecialchars($work['category'] ?? '-') ?></td>
                            <td class="px-4 py-3 text-center">
                                <a href="?edit=<?= $work['id'] ?>" class="text-yellow-600 hover:text-yellow-700 mr-2" title="編集">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <?php if ($work['is_manga'] ?? 0): ?>
                                <a href="work-insert-pages.php?work_id=<?= $work['id'] ?>" class="text-purple-500 hover:text-purple-700 mr-2" title="挿入ページ">
                                    <i class="fas fa-ad"></i>
                                </a>
                                <?php endif; ?>
                                <?php if (!$showArchived): ?>
                                    <a href="?delete=<?= $work['id'] ?>" onclick="return confirm('アーカイブしますか？')" class="text-gray-400 hover:text-red-500" title="アーカイブ">
                                        <i class="fas fa-archive"></i>
                                    </a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (empty($works)): ?>
                        <tr>
                            <td colspan="6" class="px-4 py-12 text-center text-gray-500">
                                <i class="fas fa-images text-4xl text-gray-300 mb-3"></i>
                                <p>作品がありません</p>
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </form>
        <?php endif; ?>
    </main>

    <script>
        function switchTab(tabName) {
            // タブコンテンツの切り替え
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            document.getElementById('tab-' + tabName).classList.add('active');
            
            // タブボタンのスタイル切り替え
            document.querySelectorAll('.tab-btn').forEach(btn => {
                btn.classList.remove('active');
                btn.classList.add('bg-gray-100', 'hover:bg-gray-200');
            });
            const activeBtn = document.getElementById('tab-btn-' + tabName);
            activeBtn.classList.add('active');
            activeBtn.classList.remove('bg-gray-100', 'hover:bg-gray-200');
        }
        
        // サムネイル種別切り替え
        function toggleThumbnailFields() {
            const thumbTypeEl = document.getElementById('thumbnail_type');
            // 要素が存在しない場合は何もしない（ガード句を追加）
            if (!thumbTypeEl) return; 

            const type = thumbTypeEl.value;
            const imageField = document.getElementById('image_field');
            const youtubeField = document.getElementById('youtube_field');

            if (imageField) imageField.classList.toggle('hidden', type === 'youtube');
            if (youtubeField) youtubeField.classList.toggle('hidden', type !== 'youtube');
        }

        // ステッカーフィールド切り替え
        function toggleStickerFields() {
            const isStickerEl = document.getElementById('is_omake_sticker');
            const stickerFields = document.getElementById('sticker_fields');
            const imageField = document.getElementById('image_field');
            // 要素が存在する場合のみ実行
            if (isStickerEl && stickerFields) {
                const isSticker = isStickerEl.checked;
                stickerFields.style.display = isSticker ? 'block' : 'none';
                // ステッカー時は通常の画像フィールドを非表示（ステッカー内に表面画像があるため）
                if (imageField) {
                    imageField.style.display = isSticker ? 'none' : 'block';
                }
            }
        }
        
        // ページ読み込み時にも実行
        document.addEventListener('DOMContentLoaded', function() {
            toggleStickerFields();
        });
        
        // 漫画オプション表示切り替え
        function toggleMangaOptions() {
            const isManga = document.getElementById('is_manga').checked;
            document.getElementById('manga_options').classList.toggle('hidden', !isManga);
        }
        
        // CHANGE: トリミング位置のスライダー制御
        function updateCropPosition() {
            const x = document.getElementById('crop-x-slider').value;
            const y = document.getElementById('crop-y-slider').value;
            
            document.getElementById('crop-x-value').textContent = x + '%';
            document.getElementById('crop-y-value').textContent = y + '%';
            document.getElementById('crop-position-input').value = x + '% ' + y + '%';
            
            const previewImg = document.getElementById('crop-preview-img');
            if (previewImg) {
                previewImg.style.objectPosition = x + '% ' + y + '%';
            }
            
            const indicator = document.getElementById('crop-indicator');
            if (indicator) {
                indicator.style.left = x + '%';
                indicator.style.top = y + '%';
            }
        }

        function setCropPreset(x, y) {
            document.getElementById('crop-x-slider').value = x;
            document.getElementById('crop-y-slider').value = y;
            updateCropPosition();
        }

        // 既存のupdateCropPreview関数を置き換え
        function updateCropPreview(pos) {
            // 旧形式から新形式への変換
            const posMap = {
                'center': [50, 50], 'top left': [0, 0], 'top': [50, 0], 'top right': [100, 0],
                'left': [0, 50], 'right': [100, 50], 'bottom left': [0, 100], 
                'bottom': [50, 100], 'bottom right': [100, 100]
            };
            if (posMap[pos]) {
                setCropPreset(posMap[pos][0], posMap[pos][1]);
            }
        }

        // 初期表示時にサムネイル種別を反映
        document.addEventListener('DOMContentLoaded', function() {
            toggleThumbnailFields();
            
            // ページ並び替え
            const pageList = document.getElementById('page-list');
            if (pageList) {
                new Sortable(pageList, {
                    animation: 150,
                    ghostClass: 'sortable-ghost',
                    onEnd: function() {
                        const order = Array.from(pageList.children).map(el => el.dataset.pageId);
                        document.getElementById('page_order').value = order.join(',');
                    }
                });
            }
            
            // 一括選択
            const selectAll = document.getElementById('select-all');
            const checkboxes = document.querySelectorAll('.work-checkbox');
            const bulkBtn = document.getElementById('archive-btn') || document.getElementById('restore-btn');
            
            if (selectAll) {
                selectAll.addEventListener('change', function() {
                    checkboxes.forEach(cb => cb.checked = this.checked);
                    updateBulkButton();
                });
            }
            
            checkboxes.forEach(cb => {
                cb.addEventListener('change', updateBulkButton);
            });
            
            function updateBulkButton() {
                const checked = document.querySelectorAll('.work-checkbox:checked').length;
                if (bulkBtn) {
                    bulkBtn.disabled = checked === 0;
                }
            }
        });
        
        // PDF変換用の変数
        let convertedPagesBase64 = [];
        let pdfUploadPending = false;
        
        // PDFアップロード処理
        async function handlePdfUpload(input) {
            if (!input.files || !input.files[0]) return;
            
            const file = input.files[0];
            if (file.type !== 'application/pdf') {
                alert('PDFファイルを選択してください');
                input.value = '';
                return;
            }
            
            // UIを更新
            document.getElementById('pdf_progress').classList.remove('hidden');
            document.getElementById('pdf_preview').classList.add('hidden');
            document.getElementById('pdf_progress_bar').style.width = '0%';
            document.getElementById('pdf_progress_text').textContent = 'PDFを読み込み中...';
            
            try {
                const arrayBuffer = await file.arrayBuffer();
                const pdf = await pdfjsLib.getDocument({ data: arrayBuffer }).promise;
                const numPages = pdf.numPages;
                
                convertedPagesBase64 = [];
                const previewGrid = document.getElementById('pdf_preview_grid');
                previewGrid.innerHTML = '';
                
                for (let i = 1; i <= numPages; i++) {
                    document.getElementById('pdf_progress_text').textContent = `ページ ${i}/${numPages} を変換中...`;
                    document.getElementById('pdf_progress_bar').style.width = `${(i / numPages) * 100}%`;
                    
                    const page = await pdf.getPage(i);
                    const scale = 2.0; // 高解像度で描画
                    const viewport = page.getViewport({ scale });
                    
                    const canvas = document.createElement('canvas');
                    const context = canvas.getContext('2d');
                    canvas.height = viewport.height;
                    canvas.width = viewport.width;
                    
                    await page.render({
                        canvasContext: context,
                        viewport: viewport
                    }).promise;
                    
                    // CanvasをBase64に変換
                    const base64Data = canvas.toDataURL('image/jpeg', 0.85);
                    convertedPagesBase64.push(base64Data);
                    
                    // プレビュー画像を追加
                    const previewImg = document.createElement('img');
                    previewImg.src = base64Data;
                    previewImg.className = 'w-full aspect-[3/4] object-cover rounded border border-purple-200';
                    previewImg.title = `ページ ${i}`;
                    
                    const previewContainer = document.createElement('div');
                    previewContainer.className = 'relative';
                    previewContainer.innerHTML = `
                        <div class="absolute top-0 left-0 bg-black/70 text-white text-xs px-1 rounded-br">${i}</div>
                    `;
                    previewContainer.prepend(previewImg);
                    previewGrid.appendChild(previewContainer);
                }
                
                // PDFページがあることをフラグで記録
                pdfUploadPending = true;
                document.getElementById('pdf_pages_data').value = numPages; // ページ数だけ保存
                
                // 完了
                document.getElementById('pdf_progress').classList.add('hidden');
                document.getElementById('pdf_preview').classList.remove('hidden');
                document.getElementById('pdf_page_count').textContent = numPages;
                
            } catch (error) {
                console.error('PDF変換エラー:', error);
                alert('PDFの変換中にエラーが発生しました: ' + error.message);
                document.getElementById('pdf_progress').classList.add('hidden');
            }
            
            // オリジナルの入力をクリア
            input.value = '';
        }
        
        // PDFページをクリア
        function clearPdfPages() {
            convertedPagesBase64 = [];
            pdfUploadPending = false;
            document.getElementById('pdf_pages_data').value = '';
            document.getElementById('pdf_preview').classList.add('hidden');
            document.getElementById('pdf_preview_grid').innerHTML = '';
        }
        
        // フォーム送信時にPDFページをAJAXでアップロード
        document.addEventListener('DOMContentLoaded', function() {
            const form = document.querySelector('form[enctype="multipart/form-data"]');
            if (form) {
                form.addEventListener('submit', async function(e) {
                    if (!pdfUploadPending || convertedPagesBase64.length === 0) {
                        return; // 通常のフォーム送信
                    }
                    
                    e.preventDefault();
                    
                    // 作品IDを取得（新規の場合は先に作品を保存する必要がある）
                    let workId = document.querySelector('input[name="id"]').value;
                    
                    if (!workId) {
                        alert('PDFをアップロードするには、先に作品を保存してください。\n一度「保存」を押してから、再度PDFをアップロードしてください。');
                        pdfUploadPending = false;
                        document.getElementById('pdf_pages_data').value = '';
                        form.submit();
                        return;
                    }
                    
                    // 進捗表示を更新
                    document.getElementById('pdf_progress').classList.remove('hidden');
                    document.getElementById('pdf_preview').classList.add('hidden');
                    
                    const totalPages = convertedPagesBase64.length;
                    let uploadedPages = 0;
                    
                    try {
                        for (let i = 0; i < totalPages; i++) {
                            document.getElementById('pdf_progress_text').textContent = `ページ ${i + 1}/${totalPages} をアップロード中...`;
                            document.getElementById('pdf_progress_bar').style.width = `${((i + 1) / totalPages) * 100}%`;
                            
                            const formData = new FormData();
                            formData.append('ajax_action', 'upload_pdf_page');
                            formData.append('work_id', workId);
                            formData.append('page_number', i + 1);
                            formData.append('total_pages', totalPages);
                            formData.append('image_data', convertedPagesBase64[i]);
                            
                            const response = await fetch('works.php', {
                                method: 'POST',
                                body: formData
                            });
                            
                            const result = await response.json();
                            if (result.success) {
                                uploadedPages++;
                            } else {
                                console.error('Upload failed for page', i + 1, result.error);
                            }
                        }
                        
                        // アップロード完了後、ページをリロード
                        document.getElementById('pdf_progress_text').textContent = `${uploadedPages}ページのアップロード完了！`;
                        
                        // フラグをクリアして通常送信（他のフォームデータを保存）
                        pdfUploadPending = false;
                        convertedPagesBase64 = [];
                        document.getElementById('pdf_pages_data').value = '';
                        
                        // 少し待ってからリダイレクト
                        setTimeout(() => {
                            window.location.href = 'works.php?edit=' + workId + '&saved=1';
                        }, 500);
                        
                    } catch (error) {
                        console.error('Upload error:', error);
                        alert('アップロード中にエラーが発生しました: ' + error.message);
                        document.getElementById('pdf_progress').classList.add('hidden');
                        document.getElementById('pdf_preview').classList.remove('hidden');
                    }
                });
            }
        });
    </script>
</body>
</html>
