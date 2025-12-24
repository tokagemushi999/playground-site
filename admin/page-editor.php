<?php
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/image-helper.php';
requireAuth();

$db = getDB();

$creator_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $db->prepare("SELECT * FROM creators WHERE id = ?");
$stmt->execute([$creator_id]);
$creator = $stmt->fetch();

if (!$creator) {
    header('Location: creators.php');
    exit;
}

try {
    $db->query("SELECT 1 FROM creator_page_elements LIMIT 1");
} catch (PDOException $e) {
    // テーブルが存在しない場合は作成
    $db->exec("CREATE TABLE IF NOT EXISTS creator_page_elements (
        id INT AUTO_INCREMENT PRIMARY KEY,
        creator_id INT NOT NULL,
        element_type VARCHAR(50) NOT NULL,
        content TEXT,
        pos_x DECIMAL(10,2) DEFAULT 0,
        pos_y DECIMAL(10,2) DEFAULT 0,
        width DECIMAL(10,2) DEFAULT 200,
        height DECIMAL(10,2) DEFAULT 100,
        z_index INT DEFAULT 1,
        styles JSON,
        sort_order INT DEFAULT 0,
        is_visible TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
}

// 要素保存処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    
    $action = $_POST['action'] ?? '';
    
    if ($action === 'save_elements') {
        $elements = json_decode($_POST['elements'], true);
        $page_settings = json_decode($_POST['page_settings'], true);
        
        try {
            $stmt = $db->prepare("UPDATE creators SET 
                page_background_color = ?,
                page_background_image = ?,
                page_font_family = ?
                WHERE id = ?");
            $stmt->execute([
                $page_settings['bgColor'] ?? '#ffffff',
                $page_settings['bgImage'] ?? '',
                $page_settings['fontFamily'] ?? "'Zen Maru Gothic', sans-serif",
                $creator_id
            ]);
        } catch (PDOException $e) {
            // カラムが存在しない場合は無視
        }
        
        // 既存の要素を削除
        $stmt = $db->prepare("DELETE FROM creator_page_elements WHERE creator_id = ?");
        $stmt->execute([$creator_id]);
        
        // 新しい要素を保存
        foreach ($elements as $element) {
            $stmt = $db->prepare("INSERT INTO creator_page_elements 
                (creator_id, element_type, content, pos_x, pos_y, width, height, z_index, styles, sort_order) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $creator_id,
                $element['type'],
                $element['content'] ?? '',
                $element['x'],
                $element['y'],
                $element['width'] ?? 200,
                $element['height'] ?? 100,
                $element['zIndex'] ?? 1,
                json_encode($element['styles'] ?? []),
                $element['sortOrder'] ?? 0
            ]);
        }
        
        echo json_encode(['success' => true]);
        exit;
    }
    
    if ($action === 'upload_image') {
        if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
            $uploadDir = '../uploads/pages/';
            if (!file_exists($uploadDir)) {
                mkdir($uploadDir, 0777, true);
            }
            
            $baseName = uniqid();
            $result = ImageHelper::processUpload(
                $_FILES['image']['tmp_name'],
                $uploadDir,
                $baseName,
                ['maxWidth' => 1200, 'maxHeight' => 1200]
            );
            
            if ($result && isset($result['path'])) {
                echo json_encode(['success' => true, 'url' => '/uploads/pages/' . basename($result['path'])]);
            } else {
                echo json_encode(['success' => false, 'error' => 'Upload failed']);
            }
        } else {
            echo json_encode(['success' => false, 'error' => 'No file uploaded']);
        }
        exit;
    }
}

$elements = [];
try {
    $stmt = $db->prepare("SELECT * FROM creator_page_elements WHERE creator_id = ? ORDER BY z_index ASC");
    $stmt->execute([$creator_id]);
    $elements = $stmt->fetchAll();
} catch (PDOException $e) {
    $elements = [];
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ページエディター - <?= htmlspecialchars($creator['name']) ?></title>
    <?php include 'includes/site-head.php'; ?>
    <link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@400;500;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Zen Maru Gothic', sans-serif; background: #1a1a2e; color: #fff; overflow: hidden; }
        
        .editor-container { display: flex; height: 100vh; }
        
        /* サイドバー */
        .sidebar {
            width: 280px;
            background: #16213e;
            border-right: 1px solid #0f3460;
            display: flex;
            flex-direction: column;
        }
        
        .sidebar-header {
            padding: 15px;
            background: #0f3460;
            border-bottom: 1px solid #1a1a2e;
        }
        
        .sidebar-header h2 {
            font-size: 14px;
            margin-bottom: 5px;
        }
        
        .sidebar-header .creator-name {
            font-size: 18px;
            color: #FFD93D;
        }
        
        .sidebar-tabs {
            display: flex;
            border-bottom: 1px solid #0f3460;
        }
        
        .sidebar-tab {
            flex: 1;
            padding: 12px;
            text-align: center;
            cursor: pointer;
            background: #16213e;
            border: none;
            color: #888;
            font-size: 12px;
            transition: all 0.3s;
        }
        
        .sidebar-tab.active {
            background: #0f3460;
            color: #fff;
        }
        
        .sidebar-content {
            flex: 1;
            overflow-y: auto;
            padding: 15px;
        }
        
        .panel { display: none; }
        .panel.active { display: block; }
        
        /* 要素パレット */
        .element-palette {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }
        
        .palette-item {
            background: #0f3460;
            border: 2px dashed #1a4a7a;
            border-radius: 8px;
            padding: 15px 10px;
            text-align: center;
            cursor: grab;
            transition: all 0.3s;
        }
        
        .palette-item:hover {
            background: #1a4a7a;
            border-color: #FFD93D;
        }
        
        .palette-item i {
            font-size: 24px;
            margin-bottom: 8px;
            display: block;
            color: #FFD93D;
        }
        
        .palette-item span {
            font-size: 11px;
        }
        
        /* ページ設定 */
        .setting-group {
            margin-bottom: 20px;
        }
        
        .setting-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 12px;
            color: #aaa;
        }
        
        .setting-group input[type="text"],
        .setting-group input[type="color"],
        .setting-group select {
            width: 100%;
            padding: 10px;
            border: 1px solid #0f3460;
            border-radius: 6px;
            background: #0f3460;
            color: #fff;
            font-size: 14px;
        }
        
        .setting-group input[type="color"] {
            height: 40px;
            cursor: pointer;
        }
        
        /* キャンバスエリア */
        .canvas-area {
            flex: 1;
            display: flex;
            flex-direction: column;
            background: #1a1a2e;
        }
        
        .canvas-toolbar {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 10px 20px;
            background: #16213e;
            border-bottom: 1px solid #0f3460;
        }
        
        .toolbar-left {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .toolbar-btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s;
        }
        
        .toolbar-btn.primary {
            background: #FFD93D;
            color: #000;
        }
        
        .toolbar-btn.primary:hover {
            background: #ffed4a;
        }
        
        .toolbar-btn.secondary {
            background: #0f3460;
            color: #fff;
        }
        
        .toolbar-btn.secondary:hover {
            background: #1a4a7a;
        }
        
        .toolbar-btn.preview {
            background: #00d4aa;
            color: #000;
        }
        
        .toolbar-btn.preview:hover {
            background: #00f4c4;
        }
        
        .zoom-controls {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .zoom-btn {
            width: 32px;
            height: 32px;
            border: 1px solid #0f3460;
            border-radius: 6px;
            background: #16213e;
            color: #fff;
            cursor: pointer;
        }
        
        .zoom-level {
            font-size: 13px;
            color: #888;
        }
        
        /* キャンバス */
        .canvas-wrapper {
            flex: 1;
            overflow: auto;
            padding: 30px;
            display: flex;
            justify-content: center;
            align-items: flex-start;
        }
        
        .canvas {
            width: 800px;
            min-height: 1200px;
            background: #fff;
            position: relative;
            box-shadow: 0 10px 40px rgba(0,0,0,0.3);
            transform-origin: top center;
        }
        
        /* 要素 */
        .element {
            position: absolute;
            cursor: move;
            border: 2px solid transparent;
            transition: border-color 0.2s;
        }
        
        .element:hover,
        .element.selected {
            border-color: #FFD93D;
        }
        
        .element.selected {
            z-index: 9999 !important;
        }
        
        .element-content {
            width: 100%;
            height: 100%;
            overflow: hidden;
        }
        
        .resize-handle {
            position: absolute;
            width: 12px;
            height: 12px;
            background: #FFD93D;
            border: 2px solid #fff;
            border-radius: 50%;
            display: none;
        }
        
        .element.selected .resize-handle {
            display: block;
        }
        
        .resize-handle.nw { top: -6px; left: -6px; cursor: nw-resize; }
        .resize-handle.ne { top: -6px; right: -6px; cursor: ne-resize; }
        .resize-handle.sw { bottom: -6px; left: -6px; cursor: sw-resize; }
        .resize-handle.se { bottom: -6px; right: -6px; cursor: se-resize; }
        
        .element-actions {
            position: absolute;
            top: -35px;
            right: 0;
            display: none;
            gap: 5px;
        }
        
        .element.selected .element-actions {
            display: flex;
        }
        
        .element-action-btn {
            width: 28px;
            height: 28px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        
        .element-action-btn.edit {
            background: #3498db;
            color: #fff;
        }
        
        .element-action-btn.delete {
            background: #e74c3c;
            color: #fff;
        }
        
        /* プロパティパネル */
        .properties-panel {
            width: 280px;
            background: #16213e;
            border-left: 1px solid #0f3460;
            padding: 15px;
            overflow-y: auto;
        }
        
        .properties-panel h3 {
            font-size: 14px;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #0f3460;
        }
        
        .property-group {
            margin-bottom: 15px;
        }
        
        .property-group label {
            display: block;
            font-size: 11px;
            color: #888;
            margin-bottom: 5px;
        }
        
        .property-group input,
        .property-group textarea,
        .property-group select {
            width: 100%;
            padding: 8px;
            border: 1px solid #0f3460;
            border-radius: 4px;
            background: #0f3460;
            color: #fff;
            font-size: 13px;
        }
        
        .property-group textarea {
            min-height: 80px;
            resize: vertical;
        }
        
        /* プレビューモーダル */
        .preview-modal {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.9);
            z-index: 10000;
            padding: 20px;
            overflow: auto;
        }
        
        .preview-modal.active {
            display: block;
        }
        
        .preview-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        
        .preview-header h3 {
            color: #fff;
        }
        
        .preview-close {
            background: #e74c3c;
            color: #fff;
            border: none;
            padding: 10px 20px;
            border-radius: 6px;
            cursor: pointer;
        }
        
        .preview-device-btns {
            display: flex;
            gap: 10px;
        }
        
        .preview-device-btn {
            padding: 8px 16px;
            border: 1px solid #0f3460;
            border-radius: 6px;
            background: #16213e;
            color: #fff;
            cursor: pointer;
        }
        
        .preview-device-btn.active {
            background: #FFD93D;
            color: #000;
            border-color: #FFD93D;
        }
        
        .preview-frame {
            margin: 0 auto;
            background: #fff;
            transition: width 0.3s;
            min-height: 800px;
            position: relative;
        }
        
        .preview-frame.desktop { width: 100%; max-width: 1200px; }
        .preview-frame.tablet { width: 768px; }
        .preview-frame.mobile { width: 375px; }
        
        /* サムネイルプレビュー */
        .thumbnail-preview {
            margin-top: 20px;
            padding: 15px;
            background: #0f3460;
            border-radius: 8px;
        }
        
        .thumbnail-preview h4 {
            font-size: 12px;
            margin-bottom: 10px;
            color: #888;
        }
        
        .thumbnail-canvas {
            width: 100%;
            aspect-ratio: 16/9;
            background: #fff;
            border-radius: 4px;
            position: relative;
            overflow: hidden;
        }
    </style>
</head>
<body>
    <div class="editor-container">
        <!-- サイドバー -->
        <div class="sidebar">
            <div class="sidebar-header">
                <h2>ページエディター</h2>
                <div class="creator-name"><?= htmlspecialchars($creator['name']) ?></div>
            </div>
            
            <div class="sidebar-tabs">
                <button class="sidebar-tab active" data-panel="elements">要素</button>
                <button class="sidebar-tab" data-panel="settings">設定</button>
            </div>
            
            <div class="sidebar-content">
                <!-- 要素パネル -->
                <div class="panel active" id="panel-elements">
                    <div class="element-palette">
                        <div class="palette-item" draggable="true" data-type="text">
                            <i class="fas fa-font"></i>
                            <span>テキスト</span>
                        </div>
                        <div class="palette-item" draggable="true" data-type="heading">
                            <i class="fas fa-heading"></i>
                            <span>見出し</span>
                        </div>
                        <div class="palette-item" draggable="true" data-type="image">
                            <i class="fas fa-image"></i>
                            <span>画像</span>
                        </div>
                        <div class="palette-item" draggable="true" data-type="profile">
                            <i class="fas fa-user-circle"></i>
                            <span>プロフィール</span>
                        </div>
                        <div class="palette-item" draggable="true" data-type="gallery">
                            <i class="fas fa-th"></i>
                            <span>ギャラリー</span>
                        </div>
                        <div class="palette-item" draggable="true" data-type="sns">
                            <i class="fas fa-share-alt"></i>
                            <span>SNSリンク</span>
                        </div>
                        <div class="palette-item" draggable="true" data-type="link">
                            <i class="fas fa-link"></i>
                            <span>リンクボタン</span>
                        </div>
                        <div class="palette-item" draggable="true" data-type="divider">
                            <i class="fas fa-minus"></i>
                            <span>区切り線</span>
                        </div>
                        <div class="palette-item" draggable="true" data-type="spacer">
                            <i class="fas fa-arrows-alt-v"></i>
                            <span>スペーサー</span>
                        </div>
                        <div class="palette-item" draggable="true" data-type="shape">
                            <i class="fas fa-shapes"></i>
                            <span>図形</span>
                        </div>
                    </div>
                    
                    <!-- サムネイルプレビュー -->
                    <div class="thumbnail-preview">
                        <h4><i class="fas fa-eye"></i> プレビュー</h4>
                        <div class="thumbnail-canvas" id="thumbnailCanvas"></div>
                    </div>
                </div>
                
                <!-- 設定パネル -->
                <div class="panel" id="panel-settings">
                    <div class="setting-group">
                        <label>背景色</label>
                        <input type="color" id="bgColor" value="<?= htmlspecialchars($creator['page_background_color'] ?? '#ffffff') ?>">
                    </div>
                    
                    <div class="setting-group">
                        <label>背景画像URL</label>
                        <input type="text" id="bgImage" placeholder="https://..." value="<?= htmlspecialchars($creator['page_background_image'] ?? '') ?>">
                    </div>
                    
                    <div class="setting-group">
                        <label>フォント</label>
                        <select id="fontFamily">
                            <option value="'Zen Maru Gothic', sans-serif">Zen Maru Gothic</option>
                            <option value="'Noto Sans JP', sans-serif">Noto Sans JP</option>
                            <option value="'M PLUS Rounded 1c', sans-serif">M PLUS Rounded 1c</option>
                            <option value="'Kosugi Maru', sans-serif">Kosugi Maru</option>
                            <option value="'Sawarabi Gothic', sans-serif">Sawarabi Gothic</option>
                        </select>
                    </div>
                    
                    <div class="setting-group">
                        <label>ページ幅</label>
                        <select id="pageWidth">
                            <option value="800">標準 (800px)</option>
                            <option value="1000">ワイド (1000px)</option>
                            <option value="100%">フル幅</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- キャンバスエリア -->
        <div class="canvas-area">
            <div class="canvas-toolbar">
                <div class="toolbar-left">
                    <a href="creators.php" class="toolbar-btn secondary">
                        <i class="fas fa-arrow-left"></i> 戻る
                    </a>
                    <button class="toolbar-btn secondary" onclick="undo()">
                        <i class="fas fa-undo"></i>
                    </button>
                    <button class="toolbar-btn secondary" onclick="redo()">
                        <i class="fas fa-redo"></i>
                    </button>
                </div>
                
                <div class="zoom-controls">
                    <button class="zoom-btn" onclick="zoomOut()">-</button>
                    <span class="zoom-level" id="zoomLevel">100%</span>
                    <button class="zoom-btn" onclick="zoomIn()">+</button>
                </div>
                
                <div class="toolbar-right">
                    <button class="toolbar-btn preview" onclick="openPreview()">
                        <i class="fas fa-eye"></i> プレビュー
                    </button>
                    <a href="/creator.php?id=<?= $creator_id ?>" target="_blank" class="toolbar-btn secondary">
                        <i class="fas fa-external-link-alt"></i> 実際のページ
                    </a>
                    <button class="toolbar-btn primary" onclick="saveElements()">
                        <i class="fas fa-save"></i> 保存
                    </button>
                </div>
            </div>
            
            <div class="canvas-wrapper">
                <div class="canvas" id="canvas" style="background: <?= htmlspecialchars($creator['page_background_color'] ?? '#ffffff') ?>;">
                    <!-- 要素がここに配置される -->
                </div>
            </div>
        </div>
        
        <!-- プロパティパネル -->
        <div class="properties-panel" id="propertiesPanel">
            <h3>プロパティ</h3>
            <p style="color: #888; font-size: 13px;">要素を選択してください</p>
        </div>
    </div>
    
    <!-- プレビューモーダル -->
    <div class="preview-modal" id="previewModal">
        <div class="preview-header">
            <h3>プレビュー</h3>
            <div class="preview-device-btns">
                <button class="preview-device-btn active" data-device="desktop">
                    <i class="fas fa-desktop"></i> PC
                </button>
                <button class="preview-device-btn" data-device="tablet">
                    <i class="fas fa-tablet-alt"></i> タブレット
                </button>
                <button class="preview-device-btn" data-device="mobile">
                    <i class="fas fa-mobile-alt"></i> スマホ
                </button>
            </div>
            <button class="preview-close" onclick="closePreview()">
                <i class="fas fa-times"></i> 閉じる
            </button>
        </div>
        <div class="preview-frame desktop" id="previewFrame"></div>
    </div>
    
    <script>
        // 状態管理
        let elements = <?= json_encode(array_map(function($el) {
            return [
                'id' => $el['id'],
                'type' => $el['element_type'],
                'content' => $el['content'],
                'x' => (float)$el['pos_x'], // Ensure float for calculations
                'y' => (float)$el['pos_y'], // Ensure float for calculations
                'width' => (float)$el['width'], // Ensure float for calculations
                'height' => (float)$el['height'], // Ensure float for calculations
                'zIndex' => (int)$el['z_index'], // Ensure int for z-index
                'styles' => json_decode($el['styles'], true) ?? []
            ];
        }, $elements)) ?>;
        
        let selectedElement = null;
        let zoom = 1;
        let history = [];
        let historyIndex = -1;
        let elementIdCounter = <?= count($elements) + 1 ?>;
        
        const canvas = document.getElementById('canvas');
        
        // 初期化
        function init() {
            renderElements();
            setupDragAndDrop();
            setupTabs();
            setupDeviceButtons();
            updateThumbnail();
            // Set initial page settings from creator data
            document.getElementById('bgColor').value = '<?= htmlspecialchars($creator['page_background_color'] ?? '#ffffff') ?>';
            document.getElementById('bgImage').value = '<?= htmlspecialchars($creator['page_background_image'] ?? '') ?>';
            document.getElementById('fontFamily').value = '<?= htmlspecialchars($creator['page_font_family'] ?? "'Zen Maru Gothic', sans-serif") ?>';
        }
        
        // タブ切り替え
        function setupTabs() {
            document.querySelectorAll('.sidebar-tab').forEach(tab => {
                tab.addEventListener('click', () => {
                    document.querySelectorAll('.sidebar-tab').forEach(t => t.classList.remove('active'));
                    document.querySelectorAll('.panel').forEach(p => p.classList.remove('active'));
                    tab.classList.add('active');
                    document.getElementById('panel-' + tab.dataset.panel).classList.add('active');
                });
            });
        }
        
        // ドラッグ&ドロップ設定
        function setupDragAndDrop() {
            document.querySelectorAll('.palette-item').forEach(item => {
                item.addEventListener('dragstart', (e) => {
                    e.dataTransfer.setData('type', item.dataset.type);
                });
            });
            
            canvas.addEventListener('dragover', (e) => {
                e.preventDefault();
            });
            
            canvas.addEventListener('drop', (e) => {
                e.preventDefault();
                const type = e.dataTransfer.getData('type');
                if (type) {
                    const rect = canvas.getBoundingClientRect();
                    const x = (e.clientX - rect.left) / zoom;
                    const y = (e.clientY - rect.top) / zoom;
                    addElement(type, x, y);
                }
            });
        }
        
        // 要素追加
        function addElement(type, x, y) {
            const element = {
                id: 'el_' + elementIdCounter++,
                type: type,
                content: getDefaultContent(type),
                x: Math.round(x),
                y: Math.round(y),
                width: getDefaultWidth(type),
                height: getDefaultHeight(type),
                zIndex: elements.length + 1,
                styles: getDefaultStyles(type)
            };
            
            elements.push(element);
            saveHistory();
            renderElements();
            selectElement(element.id);
            updateThumbnail();
        }
        
        function getDefaultContent(type) {
            switch(type) {
                case 'text': return 'テキストを入力';
                case 'heading': return '見出し';
                case 'link': return 'リンクボタン';
                case 'profile': return '<?= htmlspecialchars($creator['name']) ?>';
                default: return '';
            }
        }
        
        function getDefaultWidth(type) {
            switch(type) {
                case 'text': return 300;
                case 'heading': return 400;
                case 'image': return 300;
                case 'profile': return 250;
                case 'gallery': return 600;
                case 'sns': return 200;
                case 'link': return 200;
                case 'divider': return 400;
                case 'spacer': return 100;
                case 'shape': return 150;
                default: return 200;
            }
        }
        
        function getDefaultHeight(type) {
            switch(type) {
                case 'text': return 100;
                case 'heading': return 60;
                case 'image': return 200;
                case 'profile': return 300;
                case 'gallery': return 400;
                case 'sns': return 50;
                case 'link': return 50;
                case 'divider': return 20;
                case 'spacer': return 50;
                case 'shape': return 150;
                default: return 100;
            }
        }
        
        function getDefaultStyles(type) {
            switch(type) {
                case 'heading':
                    return { fontSize: '28px', fontWeight: 'bold', color: '#333' };
                case 'text':
                    return { fontSize: '16px', color: '#666', lineHeight: '1.8' };
                case 'link':
                    return { backgroundColor: '#FFD93D', color: '#000', borderRadius: '25px', textAlign: 'center' };
                case 'shape':
                    return { backgroundColor: '#FFD93D', borderRadius: '0px' };
                default:
                    return {};
            }
        }
        
        // 要素レンダリング
        function renderElements() {
            const existingEls = canvas.querySelectorAll('.element');
            existingEls.forEach(el => el.remove());
            
            elements.forEach(el => {
                const div = document.createElement('div');
                div.className = 'element';
                div.id = el.id;
                div.style.left = el.x + 'px';
                div.style.top = el.y + 'px';
                div.style.width = el.width + 'px';
                div.style.height = el.height + 'px';
                div.style.zIndex = el.zIndex;
                
                div.innerHTML = `
                    <div class="element-content">${renderElementContent(el)}</div>
                    <div class="resize-handle nw" data-dir="nw"></div>
                    <div class="resize-handle ne" data-dir="ne"></div>
                    <div class="resize-handle sw" data-dir="sw"></div>
                    <div class="resize-handle se" data-dir="se"></div>
                    <div class="element-actions">
                        <button class="element-action-btn edit" onclick="editElement('${el.id}')"><i class="fas fa-edit"></i></button>
                        <button class="element-action-btn delete" onclick="deleteElement('${el.id}')"><i class="fas fa-trash"></i></button>
                    </div>
                `;
                
                // ドラッグ移動
                div.addEventListener('mousedown', (e) => {
                    if (e.target.classList.contains('resize-handle')) return;
                    selectElement(el.id);
                    startDrag(e, el);
                });
                
                // リサイズ
                div.querySelectorAll('.resize-handle').forEach(handle => {
                    handle.addEventListener('mousedown', (e) => {
                        e.stopPropagation();
                        startResize(e, el, handle.dataset.dir);
                    });
                });
                
                canvas.appendChild(div);
            });
        }
        
        function renderElementContent(el) {
            const styles = el.styles || {};
            let styleStr = Object.entries(styles).map(([k, v]) => {
                const prop = k.replace(/([A-Z])/g, '-$1').toLowerCase();
                return `${prop}: ${v}`;
            }).join(';');
            
            switch(el.type) {
                case 'text':
                    return `<p style="${styleStr}; margin: 0; height: 100%; overflow: hidden;">${el.content}</p>`;
                case 'heading':
                    return `<h2 style="${styleStr}; margin: 0;">${el.content}</h2>`;
                case 'image':
                    return el.content ? 
                        `<img src="${el.content}" style="width: 100%; height: 100%; object-fit: cover;">` :
                        `<div style="width: 100%; height: 100%; background: #eee; display: flex; align-items: center; justify-content: center; color: #999;"><i class="fas fa-image" style="font-size: 40px;"></i></div>`;
                case 'profile':
                    return `<div style="text-align: center; padding: 20px;">
                        <div style="width: 100px; height: 100px; border-radius: 50%; background: #eee; margin: 0 auto 15px; overflow: hidden;">
                            ${el.styles.avatar ? `<img src="${el.styles.avatar}" style="width: 100%; height: 100%; object-fit: cover;">` : '<i class="fas fa-user" style="font-size: 50px; line-height: 100px; color: #ccc;"></i>'}
                        </div>
                        <h3 style="margin: 0 0 10px; font-size: 20px;">${el.content}</h3>
                        <p style="margin: 0; color: #666; font-size: 14px;">${el.styles.bio || ''}</p>
                    </div>`;
                case 'sns':
                    return `<div style="display: flex; gap: 15px; justify-content: center; align-items: center; height: 100%;">
                        <a href="#" style="color: #1DA1F2; font-size: 24px;"><i class="fab fa-twitter"></i></a>
                        <a href="#" style="color: #E4405F; font-size: 24px;"><i class="fab fa-instagram"></i></a>
                        <a href="#" style="color: #FF0000; font-size: 24px;"><i class="fab fa-youtube"></i></a>
                    </div>`;
                case 'link':
                    return `<a href="${el.styles.url || '#'}" style="${styleStr}; display: flex; align-items: center; justify-content: center; height: 100%; text-decoration: none; font-weight: bold;">${el.content}</a>`;
                case 'divider':
                    return `<hr style="border: none; border-top: 2px solid ${el.styles.color || '#ddd'}; margin: 0;">`;
                case 'spacer':
                    return '';
                case 'shape':
                    return `<div style="${styleStr}; width: 100%; height: 100%;"></div>`;
                case 'gallery':
                    return `<div style="display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; padding: 10px;">
                        <div style="aspect-ratio: 1; background: #eee;"></div>
                        <div style="aspect-ratio: 1; background: #eee;"></div>
                        <div style="aspect-ratio: 1; background: #eee;"></div>
                    </div>`;
                default:
                    return '';
            }
        }
        
        // 要素選択
        function selectElement(id) {
            document.querySelectorAll('.element').forEach(el => el.classList.remove('selected'));
            const el = document.getElementById(id);
            if (el) {
                el.classList.add('selected');
                selectedElement = elements.find(e => e.id === id);
                showProperties(selectedElement);
            }
        }
        
        // プロパティ表示
        function showProperties(el) {
            const panel = document.getElementById('propertiesPanel');
            if (!el) {
                panel.innerHTML = '<h3>プロパティ</h3><p style="color: #888; font-size: 13px;">要素を選択してください</p>';
                return;
            }
            
            let propsHtml = `<h3>${getTypeName(el.type)}</h3>`;
            
            // 共通プロパティ
            propsHtml += `
                <div class="property-group">
                    <label>X座標</label>
                    <input type="number" value="${el.x}" onchange="updateElementProp('${el.id}', 'x', this.value)">
                </div>
                <div class="property-group">
                    <label>Y座標</label>
                    <input type="number" value="${el.y}" onchange="updateElementProp('${el.id}', 'y', this.value)">
                </div>
                <div class="property-group">
                    <label>幅</label>
                    <input type="number" value="${el.width}" onchange="updateElementProp('${el.id}', 'width', this.value)">
                </div>
                <div class="property-group">
                    <label>高さ</label>
                    <input type="number" value="${el.height}" onchange="updateElementProp('${el.id}', 'height', this.value)">
                </div>
            `;
            
            // タイプ別プロパティ
            if (el.type === 'text' || el.type === 'heading') {
                propsHtml += `
                    <div class="property-group">
                        <label>テキスト</label>
                        <textarea onchange="updateElementProp('${el.id}', 'content', this.value)">${el.content}</textarea>
                    </div>
                    <div class="property-group">
                        <label>文字サイズ</label>
                        <input type="text" value="${el.styles.fontSize || '16px'}" onchange="updateElementStyle('${el.id}', 'fontSize', this.value)">
                    </div>
                    <div class="property-group">
                        <label>文字色</label>
                        <input type="color" value="${el.styles.color || '#333333'}" onchange="updateElementStyle('${el.id}', 'color', this.value)">
                    </div>
                `;
            }
            
            if (el.type === 'image') {
                propsHtml += `
                    <div class="property-group">
                        <label>画像URL</label>
                        <input type="text" value="${el.content}" onchange="updateElementProp('${el.id}', 'content', this.value)">
                    </div>
                    <div class="property-group">
                        <label>画像アップロード</label>
                        <input type="file" accept="image/*" onchange="uploadImage(this, '${el.id}')">
                    </div>
                `;
            }
            
            if (el.type === 'link') {
                propsHtml += `
                    <div class="property-group">
                        <label>ボタンテキスト</label>
                        <input type="text" value="${el.content}" onchange="updateElementProp('${el.id}', 'content', this.value)">
                    </div>
                    <div class="property-group">
                        <label>リンクURL</label>
                        <input type="text" value="${el.styles.url || ''}" onchange="updateElementStyle('${el.id}', 'url', this.value)">
                    </div>
                    <div class="property-group">
                        <label>背景色</label>
                        <input type="color" value="${el.styles.backgroundColor || '#FFD93D'}" onchange="updateElementStyle('${el.id}', 'backgroundColor', this.value)">
                    </div>
                `;
            }
            
            if (el.type === 'shape') {
                propsHtml += `
                    <div class="property-group">
                        <label>背景色</label>
                        <input type="color" value="${el.styles.backgroundColor || '#FFD93D'}" onchange="updateElementStyle('${el.id}', 'backgroundColor', this.value)">
                    </div>
                    <div class="property-group">
                        <label>角丸</label>
                        <input type="text" value="${el.styles.borderRadius || '0px'}" onchange="updateElementStyle('${el.id}', 'borderRadius', this.value)">
                    </div>
                `;
            }
            
            // Profile specific properties
            if (el.type === 'profile') {
                propsHtml += `
                    <div class="property-group">
                        <label>名前</label>
                        <input type="text" value="${el.content}" onchange="updateElementProp('${el.id}', 'content', this.value)">
                    </div>
                    <div class="property-group">
                        <label>プロフィール画像URL</label>
                        <input type="text" value="${el.styles.avatar || ''}" onchange="updateElementStyle('${el.id}', 'avatar', this.value)">
                    </div>
                     <div class="property-group">
                        <label>自己紹介</label>
                        <textarea onchange="updateElementStyle('${el.id}', 'bio', this.value)">${el.styles.bio || ''}</textarea>
                    </div>
                `;
            }
            
            panel.innerHTML = propsHtml;
        }
        
        function getTypeName(type) {
            const names = {
                text: 'テキスト',
                heading: '見出し',
                image: '画像',
                profile: 'プロフィール',
                gallery: 'ギャラリー',
                sns: 'SNSリンク',
                link: 'リンクボタン',
                divider: '区切り線',
                spacer: 'スペーサー',
                shape: '図形'
            };
            return names[type] || type;
        }
        
        // プロパティ更新
        function updateElementProp(id, prop, value) {
            const el = elements.find(e => e.id === id);
            if (el) {
                if (prop === 'x' || prop === 'y' || prop === 'width' || prop === 'height') {
                    el[prop] = parseFloat(value); // Use parseFloat for positional/dimensional properties
                } else {
                    el[prop] = value;
                }
                saveHistory();
                renderElements();
                selectElement(id);
                updateThumbnail();
            }
        }
        
        function updateElementStyle(id, prop, value) {
            const el = elements.find(e => e.id === id);
            if (el) {
                el.styles[prop] = value;
                saveHistory();
                renderElements();
                selectElement(id);
                updateThumbnail();
            }
        }
        
        // ドラッグ
        function startDrag(e, el) {
            const startX = e.clientX;
            const startY = e.clientY;
            const startElX = el.x;
            const startElY = el.y;
            
            function onMove(e) {
                el.x = startElX + (e.clientX - startX) / zoom;
                el.y = startElY + (e.clientY - startY) / zoom;
                renderElements();
                selectElement(el.id);
            }
            
            function onUp() {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                saveHistory();
                updateThumbnail();
            }
            
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        }
        
        // リサイズ
        function startResize(e, el, dir) {
            const startX = e.clientX;
            const startY = e.clientY;
            const startW = el.width;
            const startH = el.height;
            const startElX = el.x;
            const startElY = el.y;
            
            function onMove(e) {
                const dx = (e.clientX - startX) / zoom;
                const dy = (e.clientY - startY) / zoom;
                
                if (dir.includes('e')) el.width = startW + dx;
                if (dir.includes('w')) { el.width = startW - dx; el.x = startElX + dx; }
                if (dir.includes('s')) el.height = startH + dy;
                if (dir.includes('n')) { el.height = startH - dy; el.y = startElY + dy; }
                
                renderElements();
                selectElement(el.id);
            }
            
            function onUp() {
                document.removeEventListener('mousemove', onMove);
                document.removeEventListener('mouseup', onUp);
                saveHistory();
                updateThumbnail();
            }
            
            document.addEventListener('mousemove', onMove);
            document.addEventListener('mouseup', onUp);
        }
        
        // 要素削除
        function deleteElement(id) {
            if (confirm('この要素を削除しますか？')) {
                elements = elements.filter(e => e.id !== id);
                selectedElement = null;
                saveHistory();
                renderElements();
                showProperties(null);
                updateThumbnail();
            }
        }
        
        // 画像アップロード
        function uploadImage(input, id) {
            const file = input.files[0];
            if (!file) return;
            
            const formData = new FormData();
            formData.append('action', 'upload_image');
            formData.append('image', file);
            
            fetch('page-editor.php?id=<?= $creator_id ?>', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    updateElementProp(id, 'content', data.url);
                } else {
                    alert('アップロードに失敗しました');
                }
            });
        }
        
        // 保存
        function saveElements() {
            const pageSettings = {
                bgColor: document.getElementById('bgColor').value,
                bgImage: document.getElementById('bgImage').value,
                fontFamily: document.getElementById('fontFamily').value
            };
            
            // Ensure element positions and dimensions are rounded for saving
            const elementsToSave = elements.map(el => ({
                ...el,
                x: Math.round(el.x),
                y: Math.round(el.y),
                width: Math.round(el.width),
                height: Math.round(el.height)
            }));

            const formData = new FormData();
            formData.append('action', 'save_elements');
            formData.append('elements', JSON.stringify(elementsToSave));
            formData.append('page_settings', JSON.stringify(pageSettings));
            
            fetch('page-editor.php?id=<?= $creator_id ?>', {
                method: 'POST',
                body: formData
            })
            .then(res => res.json())
            .then(data => {
                if (data.success) {
                    alert('保存しました');
                } else {
                    alert('保存に失敗しました');
                }
            });
        }
        
        // ズーム
        function zoomIn() {
            zoom = Math.min(zoom + 0.1, 2);
            canvas.style.transform = `scale(${zoom})`;
            document.getElementById('zoomLevel').textContent = Math.round(zoom * 100) + '%';
            // Update thumbnail scale on zoom
            updateThumbnail();
        }
        
        function zoomOut() {
            zoom = Math.max(zoom - 0.1, 0.5);
            canvas.style.transform = `scale(${zoom})`;
            document.getElementById('zoomLevel').textContent = Math.round(zoom * 100) + '%';
            // Update thumbnail scale on zoom
            updateThumbnail();
        }
        
        // 履歴
        function saveHistory() {
            history = history.slice(0, historyIndex + 1);
            history.push(JSON.stringify(elements));
            historyIndex = history.length - 1;
        }
        
        function undo() {
            if (historyIndex > 0) {
                historyIndex--;
                elements = JSON.parse(history[historyIndex]);
                renderElements();
                updateThumbnail();
                // Clear selection and properties when undoing
                selectedElement = null;
                showProperties(null);
            }
        }
        
        function redo() {
            if (historyIndex < history.length - 1) {
                historyIndex++;
                elements = JSON.parse(history[historyIndex]);
                renderElements();
                updateThumbnail();
                // Clear selection and properties when redoing
                selectedElement = null;
                showProperties(null);
            }
        }
        
        // サムネイルプレビュー更新
        function updateThumbnail() {
            const thumb = document.getElementById('thumbnailCanvas');
            if (!thumb) return; // Ensure thumbnail canvas exists
            
            const scale = thumb.offsetWidth / 800; // Assuming base canvas width is 800px
            thumb.innerHTML = '';
            thumb.style.background = document.getElementById('bgColor').value;
            
            elements.forEach(el => {
                const div = document.createElement('div');
                // Apply styles to thumbnail elements, scaling them appropriately
                let elementStyles = '';
                if (el.type === 'text' || el.type === 'heading') {
                    elementStyles += `font-size: ${el.styles.fontSize || '16px'}; color: ${el.styles.color || '#333333'}; line-height: ${el.styles.lineHeight || '1.8'};`;
                } else if (el.type === 'link' || el.type === 'shape') {
                    elementStyles += `background-color: ${el.styles.backgroundColor || '#FFD93D'}; border-radius: ${el.styles.borderRadius || '0px'};`;
                } else if (el.type === 'image') {
                    elementStyles += 'object-fit: cover;';
                } else if (el.type === 'profile') {
                    elementStyles += `background: #eee; border-radius: 50%;`; // Basic styling for avatar placeholder
                }

                div.style.cssText = `
                    position: absolute;
                    left: ${el.x * scale}px;
                    top: ${el.y * scale}px;
                    width: ${el.width * scale}px;
                    height: ${el.height * scale}px;
                    background: ${el.type === 'image' ? '#ddd' : (el.styles.backgroundColor || '#f0f0f0')};
                    border-radius: ${(parseInt(el.styles.borderRadius) || 0) * scale}px;
                    overflow: hidden;
                    box-sizing: border-box; /* Ensure padding/border are included in the element's total width and height */
                    ${elementStyles}
                `;

                // Add basic content representation for thumbnail
                if (el.type === 'text' || el.type === 'heading') {
                    div.textContent = el.content.substring(0, 20) + (el.content.length > 20 ? '...' : '');
                    div.style.padding = '5px'; // Add some padding for text visibility
                    div.style.display = 'flex';
                    div.style.alignItems = 'center';
                    div.style.justifyContent = 'center';
                    div.style.fontSize = '12px';
                } else if (el.type === 'image') {
                    div.style.backgroundImage = `url(${el.content})`;
                    div.style.backgroundSize = 'cover';
                    div.style.backgroundPosition = 'center';
                } else if (el.type === 'profile') {
                    div.innerHTML = `<i class="fas fa-user" style="font-size: 30px; color: #ccc; margin: auto;"></i>`; // Placeholder icon
                } else if (el.type === 'link') {
                    div.textContent = 'Link';
                    div.style.display = 'flex';
                    div.style.alignItems = 'center';
                    div.style.justifyContent = 'center';
                    div.style.color = el.styles.color || '#000';
                    div.style.fontWeight = 'bold';
                } else if (el.type === 'divider') {
                    div.style.borderTop = `2px solid ${el.styles.color || '#ddd'}`;
                    div.style.height = '2px';
                }

                thumb.appendChild(div);
            });
        }
        
        // プレビュー
        function openPreview() {
            const modal = document.getElementById('previewModal');
            const frame = document.getElementById('previewFrame');
            
            frame.innerHTML = '';
            frame.style.background = document.getElementById('bgColor').value;
            frame.style.minHeight = '800px';
            frame.style.position = 'relative';
            
            elements.forEach(el => {
                const div = document.createElement('div');
                div.style.cssText = `
                    position: absolute;
                    left: ${el.x}px;
                    top: ${el.y}px;
                    width: ${el.width}px;
                    height: ${el.height}px;
                `;
                div.innerHTML = renderElementContent(el);
                frame.appendChild(div);
            });
            
            modal.classList.add('active');
        }
        
        function closePreview() {
            document.getElementById('previewModal').classList.remove('active');
        }
        
        // デバイス切り替え
        function setupDeviceButtons() {
            document.querySelectorAll('.preview-device-btn').forEach(btn => {
                btn.addEventListener('click', () => {
                    document.querySelectorAll('.preview-device-btn').forEach(b => b.classList.remove('active'));
                    btn.classList.add('active');
                    const frame = document.getElementById('previewFrame');
                    frame.className = 'preview-frame ' + btn.dataset.device;
                });
            });
        }
        
        // キャンバスクリックで選択解除
        canvas.addEventListener('click', (e) => {
            if (e.target === canvas) {
                document.querySelectorAll('.element').forEach(el => el.classList.remove('selected'));
                selectedElement = null;
                showProperties(null);
            }
        });

        // Initialize styles for elements based on creator's font family
        function applyCreatorFontFamily() {
            const fontFamily = document.getElementById('fontFamily').value;
            document.querySelectorAll('.element').forEach(el => {
                const elementData = elements.find(e => e.id === el.id);
                if (elementData) {
                    if (elementData.type === 'text' || elementData.type === 'heading') {
                        const contentDiv = el.querySelector('.element-content');
                        if (contentDiv) {
                            contentDiv.style.fontFamily = fontFamily;
                        }
                    }
                }
            });
            canvas.style.fontFamily = fontFamily;
        }

        // Event listener for font family change
        document.getElementById('fontFamily').addEventListener('change', () => {
            applyCreatorFontFamily();
            updateThumbnail(); // Update thumbnail as font change might affect layout
        });

        // Apply initial font family
        applyCreatorFontFamily();
        
        // Initial save of history
        saveHistory();
        init();
    </script>
</body>
</html>
