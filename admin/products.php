<?php
/**
 * 商品管理ページ
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/site-settings.php';
require_once '../includes/csrf.php';
require_once '../includes/admin-ui.php';
requireAuth();
$isAdmin = true;

$db = getDB();
$message = '';
$error = '';

// productsテーブルが存在するか確認
$tableExists = true;
try {
    $db->query("SELECT 1 FROM products LIMIT 1");
} catch (PDOException $e) {
    $tableExists = false;
    $error = 'productsテーブルが存在しません。sql/ec_tables.sqlを実行してください。';
}

// テンプレート一覧
$productTemplates = [];
try {
    $productTemplates = $db->query("SELECT id, name, description FROM product_templates ORDER BY name")->fetchAll();
} catch (PDOException $e) {
    $productTemplates = [];
}

// テンプレート取得（AJAX）
if (isset($_GET['get_template'])) {
    header('Content-Type: application/json');
    $templateId = (int)$_GET['get_template'];
    try {
        $stmt = $db->prepare("SELECT * FROM product_templates WHERE id = ?");
        $stmt->execute([$templateId]);
        $template = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($template) {
            echo json_encode(['success' => true, 'template' => $template]);
        } else {
            echo json_encode(['success' => false, 'error' => 'テンプレートが見つかりません']);
        }
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => 'テンプレートテーブルがありません']);
    }
    exit;
}

// テンプレート保存
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_template'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = '不正なリクエストです。';
    } else {
        $templateName = trim($_POST['template_name'] ?? '');
        if (empty($templateName)) {
            $error = 'テンプレート名を入力してください。';
        } else {
            try {
                $stmt = $db->prepare("
                    INSERT INTO product_templates (name, description, title, product_description, category_id, price, stock, shipping_fee)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $templateName,
                    $_POST['template_description'] ?? '',
                    $_POST['name'] ?? '',
                    $_POST['description'] ?? '',
                    !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null,
                    (int)($_POST['price'] ?? 0),
                    (int)($_POST['stock_quantity'] ?? 0),
                    (int)($_POST['shipping_fee'] ?? 0)
                ]);
                $message = 'テンプレート「' . htmlspecialchars($templateName) . '」を保存しました。';
                $productTemplates = $db->query("SELECT id, name, description FROM product_templates ORDER BY name")->fetchAll();
            } catch (PDOException $e) {
                $error = 'テンプレートの保存に失敗しました: ' . $e->getMessage();
            }
        }
    }
}

// テンプレート削除
if (isset($_GET['delete_template']) && isset($_GET['csrf_token'])) {
    if (validateCsrfToken($_GET['csrf_token'])) {
        $templateId = (int)$_GET['delete_template'];
        try {
            $stmt = $db->prepare("DELETE FROM product_templates WHERE id = ?");
            $stmt->execute([$templateId]);
            $message = 'テンプレートを削除しました。';
            $productTemplates = $db->query("SELECT id, name, description FROM product_templates ORDER BY name")->fetchAll();
        } catch (PDOException $e) {}
    }
}

if ($tableExists) {
    // 商品のアーカイブ
    if (isset($_GET['archive']) && is_numeric($_GET['archive'])) {
        $archiveId = (int)$_GET['archive'];
        try {
            $stmt = $db->prepare("UPDATE products SET is_published = 0 WHERE id = ?");
            $stmt->execute([$archiveId]);
            $message = '商品をアーカイブしました';
        } catch (PDOException $e) {
            $error = 'アーカイブに失敗しました';
        }
    }
    
    // 商品の復元
    if (isset($_GET['restore']) && is_numeric($_GET['restore'])) {
        $restoreId = (int)$_GET['restore'];
        try {
            $stmt = $db->prepare("UPDATE products SET is_published = 1 WHERE id = ?");
            $stmt->execute([$restoreId]);
            $message = '商品を復元しました';
        } catch (PDOException $e) {
            $error = '復元に失敗しました';
        }
    }
    
    // 商品の完全削除（アーカイブからのみ）
    if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
        $deleteId = (int)$_GET['delete'];
        try {
            $stmt = $db->prepare("DELETE FROM products WHERE id = ? AND is_published = 0");
            $stmt->execute([$deleteId]);
            $message = '商品を完全に削除しました';
        } catch (PDOException $e) {
            $error = '削除に失敗しました';
        }
    }

    // 審査処理
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approval_action'])) {
        if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
            $error = '不正なリクエストです。';
        } else {
            $productId = (int)$_POST['product_id'];
            $action = $_POST['approval_action'];
            $note = trim($_POST['approval_note'] ?? '');
            
            if ($action === 'approve') {
                $stmt = $db->prepare("UPDATE products SET approval_status = 'approved', approved_at = NOW(), approval_note = NULL WHERE id = ?");
                $stmt->execute([$productId]);
                $message = '商品を承認しました。';
            } elseif ($action === 'reject') {
                if (empty($note)) {
                    $error = '却下理由を入力してください。';
                } else {
                    $stmt = $db->prepare("UPDATE products SET approval_status = 'rejected', approval_note = ? WHERE id = ?");
                    $stmt->execute([$note, $productId]);
                    $message = '商品を差し戻しました。';
                }
            }
        }
    }

    // 商品の保存
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_product'])) {
        try {
            $id = $_POST['id'] ?? null;
            $name = trim($_POST['name']);
            $slug = trim($_POST['slug']) ?: null;
            $description = $_POST['description'] ?? '';
            $short_description = trim($_POST['short_description']) ?: null;
            $price = (int)$_POST['price'];
            $compare_price = $_POST['compare_price'] ? (int)$_POST['compare_price'] : null;
            $product_type = $_POST['product_type'];
            $stock_quantity = $_POST['stock_quantity'] !== '' ? (int)$_POST['stock_quantity'] : null;
            $stock_status = $_POST['stock_status'];
            $category = $_POST['category'] ?? null;
            $creator_id = $_POST['creator_id'] ?: null;
            $related_work_id = $_POST['related_work_id'] ?: null;
            $preview_pages = (int)($_POST['preview_pages'] ?? 3);
            $is_published = isset($_POST['is_published']) ? 1 : 0;
            $is_featured = isset($_POST['is_featured']) ? 1 : 0;
            
            // 送料設定
            $shipping_fee = $_POST['shipping_fee'] !== '' ? (int)$_POST['shipping_fee'] : null;
            $is_free_shipping = isset($_POST['is_free_shipping']) ? 1 : 0;
            
            // 表示場所設定
            $show_in_gallery = isset($_POST['show_in_gallery']) ? 1 : 0;
            $show_in_creator_page = isset($_POST['show_in_creator_page']) ? 1 : 0;
            $show_in_top = isset($_POST['show_in_top']) ? 1 : 0;
            
            // 画像アップロード
            $image = null;
            if (isset($_FILES['image']) && $_FILES['image']['error'] === UPLOAD_ERR_OK) {
                $uploadDir = '../uploads/products/';
                if (!is_dir($uploadDir)) {
                    mkdir($uploadDir, 0755, true);
                }
                $ext = strtolower(pathinfo($_FILES['image']['name'], PATHINFO_EXTENSION));
                $newFileName = uniqid('product_') . '.' . $ext;
                if (move_uploaded_file($_FILES['image']['tmp_name'], $uploadDir . $newFileName)) {
                    $image = 'uploads/products/' . $newFileName;
                }
            }
            
            if ($id) {
                $sql = "UPDATE products SET 
                    name = ?, slug = ?, description = ?, short_description = ?,
                    price = ?, compare_price = ?, product_type = ?,
                    stock_quantity = ?, stock_status = ?,
                    category = ?, creator_id = ?, related_work_id = ?, preview_pages = ?,
                    is_published = ?, is_featured = ?, shipping_fee = ?, is_free_shipping = ?";
                $params = [$name, $slug, $description, $short_description, $price, $compare_price, $product_type,
                           $stock_quantity, $stock_status, $category, $creator_id, $related_work_id, $preview_pages,
                           $is_published, $is_featured, $shipping_fee, $is_free_shipping];
                
                if ($image) {
                    $sql .= ", image = ?";
                    $params[] = $image;
                }
                $sql .= " WHERE id = ?";
                $params[] = $id;
                
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $message = '商品を更新しました';
            } else {
                $stmt = $db->prepare("
                    INSERT INTO products (name, slug, description, short_description, price, compare_price, 
                        product_type, stock_quantity, stock_status, category, creator_id, related_work_id, 
                        preview_pages, image, is_published, is_featured, shipping_fee, is_free_shipping)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([$name, $slug, $description, $short_description, $price, $compare_price,
                               $product_type, $stock_quantity, $stock_status, $category, $creator_id, $related_work_id,
                               $preview_pages, $image, $is_published, $is_featured, $shipping_fee, $is_free_shipping]);
                $message = '商品を作成しました';
                $id = $db->lastInsertId();
            }
            
            // 表示場所設定を更新（カラムが存在する場合のみ）
            try {
                $productColumns = $db->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
                if (in_array('show_in_gallery', $productColumns)) {
                    $stmt = $db->prepare("UPDATE products SET show_in_gallery = ?, show_in_creator_page = ?, show_in_top = ? WHERE id = ?");
                    $stmt->execute([$show_in_gallery, $show_in_creator_page, $show_in_top, $id]);
                }
            } catch (PDOException $e) {
                // カラムがない場合は無視
            }
            
            header("Location: products.php?saved=1");
            exit;
        } catch (PDOException $e) {
            $error = '保存に失敗しました: ' . $e->getMessage();
        }
    }
}

// 編集対象の取得
$editProduct = null;
if ($tableExists && isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM products WHERE id = ?");
    $stmt->execute([(int)$_GET['edit']]);
    $editProduct = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 表示モード
$showArchived = isset($_GET['archived']);

// 商品一覧を取得
$products = [];
if ($tableExists) {
    if ($showArchived) {
        $stmt = $db->query("SELECT p.*, c.name as creator_name FROM products p LEFT JOIN creators c ON p.creator_id = c.id WHERE p.is_published = 0 ORDER BY p.id DESC");
    } else {
        $stmt = $db->query("SELECT p.*, c.name as creator_name FROM products p LEFT JOIN creators c ON p.creator_id = c.id WHERE p.is_published = 1 ORDER BY p.sort_order, p.id DESC");
    }
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// アーカイブ数を取得
$archivedCount = 0;
if ($tableExists) {
    $archivedCount = $db->query("SELECT COUNT(*) FROM products WHERE is_published = 0")->fetchColumn();
}

// クリエイター一覧
$creators = $db->query("SELECT id, name FROM creators ORDER BY sort_order, name")->fetchAll(PDO::FETCH_ASSOC);

// 作品一覧（漫画ビューアー連携用、preview_pages付き）
$works = $db->query("SELECT id, title, preview_pages FROM works WHERE category LIKE '%manga%' ORDER BY id DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);

// 商品カテゴリ一覧
$productCategories = [];
try {
    $productCategories = $db->query("SELECT * FROM product_categories WHERE is_active = 1 ORDER BY sort_order")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    // テーブルがない場合は空
}

// 作品のpreview_pagesをJSONで出力するための準備
$worksPreviewPages = [];
foreach ($works as $w) {
    $worksPreviewPages[$w['id']] = $w['preview_pages'] ?? 3;
}

if (isset($_GET['saved'])) $message = '保存しました';

$settings = getSiteSettings();
$siteName = $settings['site_name'] ?? 'CREATORS PLAYGROUND';

$productTypes = [
    'digital' => 'デジタル',
    'physical' => '物販',
    'bundle' => 'セット'
];

$stockStatuses = [
    'in_stock' => '在庫あり',
    'out_of_stock' => '在庫切れ',
    'preorder' => '予約受付中'
];

$pwaThemeColor = getSiteSetting($db, 'pwa_theme_color', '#ffffff'); 
$backyardFavicon = getBackyardFaviconInfo($db);

// 審査待ち件数を取得
$pendingApprovalCount = 0;
try {
    $pendingApprovalCount = $db->query("SELECT COUNT(*) FROM products WHERE approval_status = 'pending'")->fetchColumn();
} catch (PDOException $e) {}

// 審査待ちフィルター
$filterPending = isset($_GET['filter']) && $_GET['filter'] === 'pending';

$pageTitle = "商品管理";
include "includes/header.php";
?>
        <!-- ヘッダー -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4 mb-6">
            <h1 class="text-2xl font-bold text-gray-800">
                <i class="fas fa-store text-green-500 mr-2"></i>商品管理
            </h1>
            <?php if (!isset($_GET['edit']) && !isset($_GET['add'])): ?>
            <div class="flex gap-2 flex-wrap">
                <?php if ($pendingApprovalCount > 0): ?>
                <a href="products.php?filter=pending" class="bg-yellow-500 hover:bg-yellow-600 text-white px-4 py-2 rounded-lg font-bold transition">
                    <i class="fas fa-clock mr-1"></i>審査待ち (<?= $pendingApprovalCount ?>)
                </a>
                <?php endif; ?>
                <?php if ($showArchived): ?>
                <a href="products.php" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg font-bold transition">
                    <i class="fas fa-list mr-1"></i>公開中
                </a>
                <?php else: ?>
                <?php if ($archivedCount > 0): ?>
                <a href="products.php?archived=1" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 py-2 rounded-lg font-bold transition">
                    <i class="fas fa-archive mr-1"></i>アーカイブ (<?= $archivedCount ?>)
                </a>
                <?php endif; ?>
                <a href="?add=1" class="bg-yellow-400 hover:bg-yellow-500 text-gray-900 px-4 py-2 rounded-lg font-bold transition">
                    <i class="fas fa-plus mr-2"></i>商品を追加
                </a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
        
        <?php if ($filterPending): ?>
        <div class="bg-yellow-100 border border-yellow-200 text-yellow-700 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-clock mr-2"></i>審査待ち商品一覧 - 確認後に承認または差し戻ししてください
            <a href="products.php" class="ml-4 text-yellow-800 underline">全て表示</a>
        </div>
        <?php endif; ?>
        
        <?php if ($showArchived): ?>
        <div class="bg-gray-100 border border-gray-200 text-gray-600 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-archive mr-2"></i>アーカイブ済み商品一覧（非公開状態）
        </div>
        <?php endif; ?>
        
        <?php if ($message): ?>
        <div class="bg-green-100 border border-green-300 text-green-700 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="bg-red-100 border border-red-300 text-red-700 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>
        
        <?php if (isset($_GET['add']) || $editProduct): ?>
        <!-- 商品編集フォーム -->
        <div class="bg-white rounded-xl shadow-sm p-4 sm:p-6 mb-6">
            <h2 class="text-lg font-bold text-gray-800 mb-4">
                <?= $editProduct ? '商品を編集' : '新規商品' ?>
            </h2>
            
            <!-- テンプレート選択（新規時のみ） -->
            <?php if (isset($_GET['add']) && !empty($productTemplates)): ?>
            <div class="bg-gradient-to-r from-yellow-50 to-orange-50 rounded-lg border border-yellow-200 p-4 mb-6">
                <h3 class="font-bold text-gray-700 mb-3 flex items-center">
                    <i class="fas fa-file-alt text-yellow-500 mr-2"></i>
                    テンプレートを使用
                </h3>
                <div class="flex flex-wrap gap-2">
                    <?php foreach ($productTemplates as $tpl): ?>
                    <button type="button" onclick="loadTemplate(<?= $tpl['id'] ?>)"
                            class="px-3 py-2 bg-white border border-yellow-300 text-yellow-700 rounded-lg hover:bg-yellow-100 transition text-sm flex items-center gap-1">
                        <i class="fas fa-file-import"></i>
                        <?= htmlspecialchars($tpl['name']) ?>
                    </button>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>
            
            <form method="POST" enctype="multipart/form-data" class="space-y-6" id="productForm">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <?php if ($editProduct): ?>
                <input type="hidden" name="id" value="<?= $editProduct['id'] ?>">
                <?php endif; ?>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div class="md:col-span-2">
                        <label class="block text-sm font-bold text-gray-700 mb-2">商品名 <span class="text-red-500">*</span></label>
                        <input type="text" name="name" required value="<?= htmlspecialchars($editProduct['name'] ?? '') ?>"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">スラッグ（URL）</label>
                        <input type="text" name="slug" value="<?= htmlspecialchars($editProduct['slug'] ?? '') ?>" placeholder="product-name"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">商品タイプ <span class="text-red-500">*</span></label>
                        <select name="product_type" required class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                            <?php foreach ($productTypes as $key => $label): ?>
                            <option value="<?= $key ?>" <?= ($editProduct['product_type'] ?? 'digital') === $key ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">価格（税込） <span class="text-red-500">*</span></label>
                        <input type="number" name="price" required min="0" value="<?= htmlspecialchars($editProduct['price'] ?? '') ?>"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">比較価格（定価）</label>
                        <input type="number" name="compare_price" min="0" value="<?= htmlspecialchars($editProduct['compare_price'] ?? '') ?>" placeholder="セール表示用"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">クリエイター</label>
                        <select name="creator_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                            <option value="">選択してください</option>
                            <?php foreach ($creators as $creator): ?>
                            <option value="<?= $creator['id'] ?>" <?= ($editProduct['creator_id'] ?? '') == $creator['id'] ? 'selected' : '' ?>><?= htmlspecialchars($creator['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">カテゴリ</label>
                        <select name="category" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                            <option value="">選択してください</option>
                            <?php foreach ($productCategories as $cat): ?>
                            <option value="<?= htmlspecialchars($cat['slug']) ?>" <?= ($editProduct['category'] ?? '') === $cat['slug'] ? 'selected' : '' ?>><?= htmlspecialchars($cat['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <p class="text-xs text-gray-500 mt-1">
                            <a href="store-categories.php" class="text-blue-500 hover:underline">カテゴリ管理</a>で追加できます
                        </p>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">在庫状態</label>
                        <select name="stock_status" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                            <?php foreach ($stockStatuses as $key => $label): ?>
                            <option value="<?= $key ?>" <?= ($editProduct['stock_status'] ?? 'in_stock') === $key ? 'selected' : '' ?>><?= $label ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">在庫数</label>
                        <input type="number" name="stock_quantity" min="0" value="<?= htmlspecialchars($editProduct['stock_quantity'] ?? '') ?>" placeholder="空欄=無制限"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                        <p class="text-xs text-gray-500 mt-1">物販商品の場合に設定</p>
                    </div>
                    
                    <!-- 送料設定 -->
                    <div class="md:col-span-2 p-4 bg-blue-50 rounded-lg border border-blue-200">
                        <h3 class="font-bold text-gray-700 mb-3"><i class="fas fa-truck text-blue-500 mr-2"></i>送料設定（物販商品用）</h3>
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">個別送料（円）</label>
                                <input type="number" name="shipping_fee" min="0" value="<?= htmlspecialchars($editProduct['shipping_fee'] ?? '') ?>" placeholder="空欄=共通設定を使用"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none bg-white">
                                <p class="text-xs text-gray-500 mt-1">この商品専用の送料。空欄ならストア設定の送料を使用</p>
                            </div>
                            <div class="flex items-center">
                                <label class="flex items-center gap-2 cursor-pointer">
                                    <input type="checkbox" name="is_free_shipping" value="1" <?= ($editProduct['is_free_shipping'] ?? 0) ? 'checked' : '' ?> class="w-5 h-5 rounded text-blue-500">
                                    <span class="font-bold text-gray-700">この商品は送料無料</span>
                                </label>
                            </div>
                        </div>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">関連作品（漫画連携）</label>
                        <select name="related_work_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                            <option value="">選択してください</option>
                            <?php foreach ($works as $work): ?>
                            <option value="<?= $work['id'] ?>" <?= ($editProduct['related_work_id'] ?? '') == $work['id'] ? 'selected' : '' ?>><?= htmlspecialchars($work['title']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">試し読みページ数</label>
                        <input type="number" name="preview_pages" min="0" value="<?= htmlspecialchars($editProduct['preview_pages'] ?? '3') ?>"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                    </div>
                    
                    <div class="md:col-span-2">
                        <label class="block text-sm font-bold text-gray-700 mb-2">短い説明</label>
                        <input type="text" name="short_description" value="<?= htmlspecialchars($editProduct['short_description'] ?? '') ?>" maxlength="200"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                    </div>
                    
                    <div class="md:col-span-2">
                        <label class="block text-sm font-bold text-gray-700 mb-2">商品説明</label>
                        <textarea name="description" rows="4" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"><?= htmlspecialchars($editProduct['description'] ?? '') ?></textarea>
                    </div>
                    
                    <div class="md:col-span-2">
                        <label class="block text-sm font-bold text-gray-700 mb-2">商品画像</label>
                        <?php if ($editProduct && $editProduct['image']): ?>
                        <div class="mb-3">
                            <img src="/<?= htmlspecialchars($editProduct['image']) ?>" alt="" class="w-24 h-24 object-cover rounded-lg">
                        </div>
                        <?php endif; ?>
                        <input type="file" name="image" accept="image/*" class="w-full px-4 py-2 border border-gray-300 rounded-lg">
                        <p class="text-xs text-gray-500 mt-1">推奨: <strong>1000×1000px</strong>（正方形で表示されます）</p>
                    </div>
                    
                    <div class="md:col-span-2 flex flex-wrap items-center gap-6">
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="is_published" value="1" <?= ($editProduct['is_published'] ?? 0) ? 'checked' : '' ?> class="w-5 h-5 rounded">
                            <span class="font-bold text-gray-700">公開する</span>
                        </label>
                        <label class="flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="is_featured" value="1" <?= ($editProduct['is_featured'] ?? 0) ? 'checked' : '' ?> class="w-5 h-5 rounded">
                            <span class="font-bold text-gray-700">おすすめに表示</span>
                        </label>
                    </div>
                    
                    <!-- 表示場所設定 -->
                    <?php
                    // show_in_* カラムが存在するか確認
                    $hasProductDisplaySettings = false;
                    try {
                        $productColumns = $db->query("SHOW COLUMNS FROM products")->fetchAll(PDO::FETCH_COLUMN);
                        $hasProductDisplaySettings = in_array('show_in_gallery', $productColumns);
                    } catch (PDOException $e) {
                        $hasProductDisplaySettings = false;
                    }
                    ?>
                    <?php if ($hasProductDisplaySettings): ?>
                    <div class="md:col-span-2 p-4 bg-purple-50 rounded-lg">
                        <label class="block text-sm font-bold text-gray-700 mb-3">
                            <i class="fas fa-eye text-purple-500 mr-1"></i>表示場所設定
                        </label>
                        <div class="flex flex-wrap gap-6">
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="show_in_gallery" value="1" 
                                       <?= ($editProduct['show_in_gallery'] ?? 0) ? 'checked' : '' ?>
                                       class="w-4 h-4 text-purple-500 rounded">
                                <span class="text-sm text-gray-700">ギャラリー</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="show_in_creator_page" value="1" 
                                       <?= ($editProduct['show_in_creator_page'] ?? 1) ? 'checked' : '' ?>
                                       class="w-4 h-4 text-purple-500 rounded">
                                <span class="text-sm text-gray-700">クリエイターページ</span>
                            </label>
                            <label class="flex items-center gap-2 cursor-pointer">
                                <input type="checkbox" name="show_in_top" value="1" 
                                       <?= ($editProduct['show_in_top'] ?? 0) ? 'checked' : '' ?>
                                       class="w-4 h-4 text-purple-500 rounded">
                                <span class="text-sm text-gray-700">トップページ</span>
                            </label>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="flex flex-col sm:flex-row items-center gap-4 pt-4 border-t">
                    <button type="submit" name="save_product" class="w-full sm:w-auto bg-yellow-400 hover:bg-yellow-500 text-gray-900 px-6 py-2 rounded-lg font-bold">
                        <i class="fas fa-save mr-2"></i>保存
                    </button>
                    <a href="products.php" class="text-gray-500 hover:text-gray-700">キャンセル</a>
                    <button type="button" onclick="showSaveTemplateModal()" class="w-full sm:w-auto bg-orange-500 hover:bg-orange-600 text-white px-4 py-2 rounded-lg font-bold text-sm">
                        <i class="fas fa-file-export mr-1"></i>テンプレートとして保存
                    </button>
                </div>
            </form>
            
            <!-- テンプレート保存モーダル -->
            <div id="saveTemplateModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center">
                <div class="bg-white rounded-xl p-6 max-w-md w-full mx-4">
                    <h3 class="text-lg font-bold mb-4">
                        <i class="fas fa-file-export text-orange-500 mr-2"></i>テンプレートとして保存
                    </h3>
                    <form method="POST" id="saveTemplateForm">
                        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                        <input type="hidden" name="save_template" value="1">
                        <div id="templateFormFields"></div>
                        
                        <div class="mb-4">
                            <label class="block text-sm font-bold text-gray-700 mb-1">テンプレート名 <span class="text-red-500">*</span></label>
                            <input type="text" name="template_name" required placeholder="例: 物販（基本）"
                                   class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-orange-400 outline-none">
                        </div>
                        <div class="mb-4">
                            <label class="block text-sm font-bold text-gray-700 mb-1">説明</label>
                            <input type="text" name="template_description" placeholder="テンプレートの説明"
                                   class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-orange-400 outline-none">
                        </div>
                        <div class="flex gap-3 justify-end">
                            <button type="button" onclick="closeSaveTemplateModal()" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400">
                                キャンセル
                            </button>
                            <button type="submit" class="px-4 py-2 bg-orange-500 text-white rounded-lg hover:bg-orange-600">
                                <i class="fas fa-save mr-1"></i>保存
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            
            <script>
            // テンプレート読み込み
            function loadTemplate(templateId) {
                if (!confirm('テンプレートの内容をフォームに適用しますか？')) {
                    return;
                }
                
                fetch('?get_template=' + templateId)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success && data.template) {
                            const t = data.template;
                            const form = document.getElementById('productForm');
                            
                            if (t.title) form.querySelector('[name="name"]').value = t.title;
                            if (t.product_description) form.querySelector('[name="description"]').value = t.product_description;
                            if (t.price) form.querySelector('[name="price"]').value = t.price;
                            if (t.stock) form.querySelector('[name="stock_quantity"]').value = t.stock;
                            if (t.shipping_fee) {
                                const el = form.querySelector('[name="shipping_fee"]');
                                if (el) el.value = t.shipping_fee;
                            }
                            
                            alert('テンプレートを適用しました');
                        } else {
                            alert('テンプレートの読み込みに失敗しました');
                        }
                    })
                    .catch(err => alert('エラー: ' + err));
            }
            
            // テンプレート保存モーダル
            function showSaveTemplateModal() {
                const modal = document.getElementById('saveTemplateModal');
                const form = document.getElementById('productForm');
                const fieldsContainer = document.getElementById('templateFormFields');
                
                fieldsContainer.innerHTML = '';
                const formData = new FormData(form);
                for (let [key, value] of formData.entries()) {
                    if (key !== 'csrf_token' && key !== 'id' && !key.startsWith('image')) {
                        const input = document.createElement('input');
                        input.type = 'hidden';
                        input.name = key;
                        input.value = value;
                        fieldsContainer.appendChild(input);
                    }
                }
                
                modal.classList.remove('hidden');
                modal.classList.add('flex');
            }
            
            function closeSaveTemplateModal() {
                const modal = document.getElementById('saveTemplateModal');
                modal.classList.add('hidden');
                modal.classList.remove('flex');
            }
            </script>
        </div>
        <?php endif; ?>
        
        <!-- 商品一覧 -->
        <div class="bg-white rounded-xl shadow-sm overflow-hidden">
            <!-- PC用テーブル -->
            <div class="hidden md:block overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">商品</th>
                            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">タイプ</th>
                            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">価格</th>
                            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">在庫</th>
                            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">状態</th>
                            <th class="px-4 py-3 text-center text-sm font-bold text-gray-600">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200">
                        <?php if (empty($products)): ?>
                        <tr><td colspan="6" class="px-4 py-8 text-center text-gray-500">商品がありません</td></tr>
                        <?php else: ?>
                        <?php foreach ($products as $product): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <?php if ($product['image']): ?>
                                    <img src="/<?= htmlspecialchars($product['image']) ?>" alt="" class="w-12 h-12 object-cover rounded">
                                    <?php else: ?>
                                    <div class="w-12 h-12 bg-gray-200 rounded flex items-center justify-center"><i class="fas fa-image text-gray-400"></i></div>
                                    <?php endif; ?>
                                    <div>
                                        <p class="font-bold text-gray-800"><?= htmlspecialchars($product['name']) ?></p>
                                        <?php if ($product['creator_name']): ?><p class="text-xs text-gray-500"><?= htmlspecialchars($product['creator_name']) ?></p><?php endif; ?>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-block px-2 py-1 text-xs rounded <?= $product['product_type'] === 'digital' ? 'bg-blue-100 text-blue-700' : ($product['product_type'] === 'physical' ? 'bg-orange-100 text-orange-700' : 'bg-purple-100 text-purple-700') ?>">
                                    <?= $productTypes[$product['product_type']] ?? $product['product_type'] ?>
                                </span>
                            </td>
                            <td class="px-4 py-3 font-bold">¥<?= number_format($product['price']) ?></td>
                            <td class="px-4 py-3">
                                <?php if ($product['product_type'] === 'physical' && $product['stock_quantity'] !== null): ?>
                                <span class="<?= $product['stock_quantity'] > 0 ? 'text-green-600' : 'text-red-600' ?>"><?= $product['stock_quantity'] ?>点</span>
                                <?php else: ?><span class="text-gray-400">-</span><?php endif; ?>
                            </td>
                            <td class="px-4 py-3">
                                <?php if ($product['is_published']): ?>
                                <span class="text-green-600 text-sm"><i class="fas fa-circle text-xs mr-1"></i>公開</span>
                                <?php else: ?>
                                <span class="text-gray-400 text-sm"><i class="fas fa-circle text-xs mr-1"></i>非公開</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?php if (!$showArchived): ?>
                                <a href="?edit=<?= $product['id'] ?>" class="text-blue-500 hover:text-blue-700 mr-2" title="編集"><i class="fas fa-edit"></i></a>
                                <a href="?archive=<?= $product['id'] ?>" onclick="return confirm('アーカイブしますか？')" class="text-orange-500 hover:text-orange-700" title="アーカイブ"><i class="fas fa-archive"></i></a>
                                <?php else: ?>
                                <a href="?restore=<?= $product['id'] ?>" onclick="return confirm('復元しますか？')" class="text-blue-500 hover:text-blue-700 mr-2" title="復元"><i class="fas fa-undo"></i></a>
                                <a href="?delete=<?= $product['id'] ?>" onclick="return confirm('完全に削除しますか？この操作は取り消せません。')" class="text-red-500 hover:text-red-700" title="完全削除"><i class="fas fa-trash"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- モバイル用カード -->
            <div class="md:hidden divide-y divide-gray-200">
                <?php if (empty($products)): ?>
                <div class="px-4 py-8 text-center text-gray-500">商品がありません</div>
                <?php else: ?>
                <?php foreach ($products as $product): ?>
                <div class="p-4">
                    <div class="flex items-start gap-3">
                        <?php if ($product['image']): ?>
                        <img src="/<?= htmlspecialchars($product['image']) ?>" alt="" class="w-16 h-16 object-cover rounded">
                        <?php else: ?>
                        <div class="w-16 h-16 bg-gray-200 rounded flex items-center justify-center"><i class="fas fa-image text-gray-400"></i></div>
                        <?php endif; ?>
                        <div class="flex-1">
                            <p class="font-bold text-gray-800"><?= htmlspecialchars($product['name']) ?></p>
                            <p class="text-lg font-bold text-green-600">¥<?= number_format($product['price']) ?></p>
                            <div class="flex items-center gap-2 mt-1">
                                <span class="inline-block px-2 py-1 text-xs rounded <?= $product['product_type'] === 'digital' ? 'bg-blue-100 text-blue-700' : 'bg-orange-100 text-orange-700' ?>">
                                    <?= $productTypes[$product['product_type']] ?? $product['product_type'] ?>
                                </span>
                                <?php if ($product['is_published']): ?>
                                <span class="text-green-600 text-xs"><i class="fas fa-circle text-xs mr-1"></i>公開</span>
                                <?php else: ?>
                                <span class="text-gray-400 text-xs"><i class="fas fa-circle text-xs mr-1"></i>非公開</span>
                                <?php endif; ?>
                            </div>
                        </div>
                        <div class="flex flex-col gap-2">
                            <?php if (!$showArchived): ?>
                            <a href="?edit=<?= $product['id'] ?>" class="text-blue-500 p-2"><i class="fas fa-edit"></i></a>
                            <a href="?archive=<?= $product['id'] ?>" onclick="return confirm('アーカイブしますか？')" class="text-orange-500 p-2"><i class="fas fa-archive"></i></a>
                            <?php else: ?>
                            <a href="?restore=<?= $product['id'] ?>" onclick="return confirm('復元しますか？')" class="text-blue-500 p-2"><i class="fas fa-undo"></i></a>
                            <a href="?delete=<?= $product['id'] ?>" onclick="return confirm('完全に削除しますか？')" class="text-red-500 p-2"><i class="fas fa-trash"></i></a>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>

<?php include "includes/footer.php"; ?>
