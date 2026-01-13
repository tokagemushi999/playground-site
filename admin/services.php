<?php
/**
 * サービス管理画面（ココナラ風拡張版）
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/csrf.php';
require_once '../includes/site-settings.php';
require_once '../includes/admin-ui.php';
requireAuth();
$isAdmin = true;

$db = getDB();
$message = '';
$error = '';

// service_worksテーブルが存在するか確認
$hasServiceWorks = false;
try {
    $db->query("SELECT 1 FROM service_works LIMIT 1");
    $hasServiceWorks = true;
} catch (PDOException $e) {
    $hasServiceWorks = false;
}

// service_collectionsテーブルが存在するか確認
$hasServiceCollections = false;
try {
    $db->query("SELECT 1 FROM service_collections LIMIT 1");
    $hasServiceCollections = true;
} catch (PDOException $e) {
    $hasServiceCollections = false;
}

// 拡張カラムが存在するか確認
$hasExtendedColumns = false;
try {
    $columns = $db->query("SHOW COLUMNS FROM services")->fetchAll(PDO::FETCH_COLUMN);
    $hasExtendedColumns = in_array('thumbnail_image', $columns);
} catch (PDOException $e) {
    $hasExtendedColumns = false;
}

// service_optionsテーブルが存在するか確認
$hasServiceOptions = false;
try {
    $db->query("SELECT 1 FROM service_options LIMIT 1");
    $hasServiceOptions = true;
} catch (PDOException $e) {
    $hasServiceOptions = false;
}

// show_in_* カラムが存在するか確認
$hasDisplaySettings = false;
try {
    $columns = $db->query("SHOW COLUMNS FROM services")->fetchAll(PDO::FETCH_COLUMN);
    $hasDisplaySettings = in_array('show_in_gallery', $columns);
} catch (PDOException $e) {
    $hasDisplaySettings = false;
}

// カテゴリ一覧
$categories = [];
try {
    $categories = getServiceCategories();
} catch (Exception $e) {
    $categories = [];
}

// クリエイター一覧
$creators = $db->query("SELECT id, name FROM creators WHERE is_active = 1 ORDER BY name")->fetchAll();

// テンプレート一覧
$serviceTemplates = [];
try {
    $serviceTemplates = $db->query("SELECT id, name, description FROM service_templates ORDER BY name")->fetchAll();
} catch (PDOException $e) {
    $serviceTemplates = [];
}

// 作品一覧（参考作品用）- クリエイターIDも含める
$allWorks = $db->query("
    SELECT w.id, w.title, w.image, w.creator_id, c.name as creator_name 
    FROM works w 
    LEFT JOIN creators c ON w.creator_id = c.id 
    WHERE w.is_active = 1 
    ORDER BY c.name, w.title
")->fetchAll();

// コレクション一覧
$allCollections = [];
try {
    $allCollections = $db->query("
        SELECT c.id, c.title, 
               (SELECT COUNT(*) FROM collection_works WHERE collection_id = c.id) as work_count
        FROM collections c 
        WHERE c.is_active = 1 
        ORDER BY c.sort_order, c.title
    ")->fetchAll();
} catch (PDOException $e) {
    $allCollections = [];
}

// 削除処理
if (isset($_GET['delete']) && isset($_GET['csrf_token'])) {
    if (validateCsrfToken($_GET['csrf_token'])) {
        $id = (int)$_GET['delete'];
        $stmt = $db->prepare("DELETE FROM services WHERE id = ?");
        $stmt->execute([$id]);
        $message = 'サービスを削除しました。';
    }
}

// 審査処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['approval_action'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = '不正なリクエストです。';
    } else {
        $serviceId = (int)$_POST['service_id'];
        $action = $_POST['approval_action'];
        $note = trim($_POST['approval_note'] ?? '');
        
        if ($action === 'approve') {
            $stmt = $db->prepare("UPDATE services SET approval_status = 'approved', approved_at = NOW(), approval_note = NULL WHERE id = ?");
            $stmt->execute([$serviceId]);
            $message = 'サービスを承認しました。';
        } elseif ($action === 'reject') {
            if (empty($note)) {
                $error = '却下理由を入力してください。';
            } else {
                $stmt = $db->prepare("UPDATE services SET approval_status = 'rejected', approval_note = ? WHERE id = ?");
                $stmt->execute([$note, $serviceId]);
                $message = 'サービスを差し戻しました。';
            }
        }
    }
}

// オプション削除
if (isset($_GET['delete_option']) && isset($_GET['csrf_token']) && $hasServiceOptions) {
    if (validateCsrfToken($_GET['csrf_token'])) {
        $optionId = (int)$_GET['delete_option'];
        $stmt = $db->prepare("DELETE FROM service_options WHERE id = ?");
        $stmt->execute([$optionId]);
        $message = 'オプションを削除しました。';
    }
}

// テンプレート取得（AJAX）
if (isset($_GET['get_template'])) {
    header('Content-Type: application/json');
    $templateId = (int)$_GET['get_template'];
    try {
        $stmt = $db->prepare("SELECT * FROM service_templates WHERE id = ?");
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
                    INSERT INTO service_templates (
                        name, description, title, category, category_id, service_description, description_detail,
                        base_price, min_price, max_price, delivery_days, revision_limit,
                        provision_format, commercial_use, secondary_use, planning_included, bgm_included,
                        free_revisions, draft_proposals, style, usage_tags, genre_tags, file_formats,
                        purchase_notes, workflow, show_in_gallery, show_in_creator_page, show_in_top, show_in_store
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $templateName,
                    $_POST['template_description'] ?? '',
                    $_POST['title'] ?? '',
                    $_POST['category'] ?? '',
                    !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null,
                    $_POST['description'] ?? '',
                    $_POST['description_detail'] ?? '',
                    (int)($_POST['base_price'] ?? 0),
                    (int)($_POST['min_price'] ?? 0),
                    (int)($_POST['max_price'] ?? 0),
                    (int)($_POST['delivery_days'] ?? 7),
                    (int)($_POST['revision_limit'] ?? 3),
                    $_POST['provision_format'] ?? null,
                    isset($_POST['commercial_use']) ? 1 : 0,
                    isset($_POST['secondary_use']) ? 1 : 0,
                    isset($_POST['planning_included']) ? 1 : 0,
                    isset($_POST['bgm_included']) ? 1 : 0,
                    (int)($_POST['free_revisions'] ?? 0),
                    (int)($_POST['draft_proposals'] ?? 0),
                    $_POST['style'] ?? null,
                    $_POST['usage_tags'] ?? null,
                    $_POST['genre_tags'] ?? null,
                    $_POST['file_formats'] ?? null,
                    $_POST['purchase_notes'] ?? '',
                    $_POST['workflow'] ?? '',
                    isset($_POST['show_in_gallery']) ? 1 : 0,
                    isset($_POST['show_in_creator_page']) ? 1 : 0,
                    isset($_POST['show_in_top']) ? 1 : 0,
                    isset($_POST['show_in_store']) ? 1 : 0
                ]);
                $message = 'テンプレート「' . htmlspecialchars($templateName) . '」を保存しました。';
                // テンプレート一覧を再取得
                $serviceTemplates = $db->query("SELECT id, name, description FROM service_templates ORDER BY name")->fetchAll();
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
            $stmt = $db->prepare("DELETE FROM service_templates WHERE id = ?");
            $stmt->execute([$templateId]);
            $message = 'テンプレートを削除しました。';
            $serviceTemplates = $db->query("SELECT id, name, description FROM service_templates ORDER BY name")->fetchAll();
        } catch (PDOException $e) {}
    }
}

// 保存処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !isset($_POST['save_plan']) && !isset($_POST['save_option'])) {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = '不正なリクエストです。';
    } else {
        $id = (int)($_POST['id'] ?? 0);
        $creator_id = (int)$_POST['creator_id'];
        $category_id = !empty($_POST['category_id']) ? (int)$_POST['category_id'] : null;
        $category = trim($_POST['category'] ?? '');
        $title = trim($_POST['title']);
        $slug = trim($_POST['slug']) ?: null;
        $description = trim($_POST['description'] ?? '');
        $description_detail = $_POST['description_detail'] ?? '';
        $base_price = (int)$_POST['base_price'];
        $delivery_days = (int)$_POST['delivery_days'];
        $revision_limit = (int)$_POST['revision_limit'];
        $status = $_POST['status'];
        $is_featured = isset($_POST['is_featured']) ? 1 : 0;
        $sort_order = (int)($_POST['sort_order'] ?? 0);
        
        // 拡張フィールド
        $provision_format = trim($_POST['provision_format'] ?? '');
        $commercial_use = isset($_POST['commercial_use']) ? 1 : 0;
        $secondary_use = isset($_POST['secondary_use']) ? 1 : 0;
        $planning_included = isset($_POST['planning_included']) ? 1 : 0;
        $bgm_included = isset($_POST['bgm_included']) ? 1 : 0;
        $free_revisions = (int)($_POST['free_revisions'] ?? 3);
        $draft_proposals = (int)($_POST['draft_proposals'] ?? 1);
        $style = trim($_POST['style'] ?? '');
        $usage_tags = trim($_POST['usage_tags'] ?? '');
        $genre_tags = trim($_POST['genre_tags'] ?? '');
        $file_formats = trim($_POST['file_formats'] ?? '');
        $purchase_notes = trim($_POST['purchase_notes'] ?? '');
        $workflow = trim($_POST['workflow'] ?? '');
        
        // 表示場所設定
        $show_in_gallery = isset($_POST['show_in_gallery']) ? 1 : 0;
        $show_in_creator_page = isset($_POST['show_in_creator_page']) ? 1 : 0;
        $show_in_top = isset($_POST['show_in_top']) ? 1 : 0;
        $show_in_store = isset($_POST['show_in_store']) ? 1 : 0;
        
        // スラッグ生成
        if (empty($slug)) {
            $slug = preg_replace('/[^a-z0-9\-]/', '', strtolower(str_replace(' ', '-', $title)));
            $slug = $slug . '-' . uniqid();
        }
        
        // サムネイル画像アップロード
        $thumbnail_image = null;
        if ($id > 0) {
            $stmt = $db->prepare("SELECT thumbnail_image FROM services WHERE id = ?");
            $stmt->execute([$id]);
            $existing = $stmt->fetch();
            $thumbnail_image = $existing['thumbnail_image'] ?? null;
        }
        
        if (!empty($_FILES['thumbnail_image']['name'])) {
            $uploadDir = '../uploads/services/';
            if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
            
            $ext = strtolower(pathinfo($_FILES['thumbnail_image']['name'], PATHINFO_EXTENSION));
            $filename = 'service_' . time() . '_' . uniqid() . '.' . $ext;
            $uploadPath = $uploadDir . $filename;
            
            if (move_uploaded_file($_FILES['thumbnail_image']['tmp_name'], $uploadPath)) {
                $thumbnail_image = 'uploads/services/' . $filename;
            }
        }
        
        try {
            if ($id > 0) {
                // 更新
                $sql = "UPDATE services SET
                    creator_id = ?, category_id = ?, category = ?, title = ?, slug = ?,
                    description = ?, description_detail = ?, base_price = ?,
                    delivery_days = ?, revision_limit = ?, status = ?,
                    is_featured = ?, sort_order = ?, updated_at = NOW()";
                $params = [
                    $creator_id, $category_id, $category, $title, $slug,
                    $description, $description_detail, $base_price,
                    $delivery_days, $revision_limit, $status,
                    $is_featured, $sort_order
                ];
                
                if ($hasDisplaySettings) {
                    $sql .= ", show_in_gallery = ?, show_in_creator_page = ?, show_in_top = ?, show_in_store = ?";
                    $params[] = $show_in_gallery;
                    $params[] = $show_in_creator_page;
                    $params[] = $show_in_top;
                    $params[] = $show_in_store;
                }
                
                if ($hasExtendedColumns) {
                    $sql .= ", thumbnail_image = ?, provision_format = ?, commercial_use = ?, secondary_use = ?,
                              planning_included = ?, bgm_included = ?, free_revisions = ?, draft_proposals = ?,
                              style = ?, usage_tags = ?, genre_tags = ?, file_formats = ?,
                              purchase_notes = ?, workflow = ?";
                    $params[] = $thumbnail_image;
                    $params[] = $provision_format;
                    $params[] = $commercial_use;
                    $params[] = $secondary_use;
                    $params[] = $planning_included;
                    $params[] = $bgm_included;
                    $params[] = $free_revisions;
                    $params[] = $draft_proposals;
                    $params[] = $style;
                    $params[] = $usage_tags;
                    $params[] = $genre_tags;
                    $params[] = $file_formats;
                    $params[] = $purchase_notes;
                    $params[] = $workflow;
                }
                
                $sql .= " WHERE id = ?";
                $params[] = $id;
                
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $message = 'サービスを更新しました。';
            } else {
                // 新規作成
                $sql = "INSERT INTO services (
                    creator_id, category_id, category, title, slug, description, description_detail,
                    base_price, delivery_days, revision_limit, status, is_featured, sort_order";
                $placeholders = "?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?";
                $params = [
                    $creator_id, $category_id, $category, $title, $slug, $description, $description_detail,
                    $base_price, $delivery_days, $revision_limit, $status, $is_featured, $sort_order
                ];
                
                if ($hasDisplaySettings) {
                    $sql .= ", show_in_gallery, show_in_creator_page, show_in_top, show_in_store";
                    $placeholders .= ", ?, ?, ?, ?";
                    $params[] = $show_in_gallery;
                    $params[] = $show_in_creator_page;
                    $params[] = $show_in_top;
                    $params[] = $show_in_store;
                }
                
                if ($hasExtendedColumns) {
                    $sql .= ", thumbnail_image, provision_format, commercial_use, secondary_use,
                              planning_included, bgm_included, free_revisions, draft_proposals,
                              style, usage_tags, genre_tags, file_formats, purchase_notes, workflow";
                    $placeholders .= ", ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?";
                    $params[] = $thumbnail_image;
                    $params[] = $provision_format;
                    $params[] = $commercial_use;
                    $params[] = $secondary_use;
                    $params[] = $planning_included;
                    $params[] = $bgm_included;
                    $params[] = $free_revisions;
                    $params[] = $draft_proposals;
                    $params[] = $style;
                    $params[] = $usage_tags;
                    $params[] = $genre_tags;
                    $params[] = $file_formats;
                    $params[] = $purchase_notes;
                    $params[] = $workflow;
                }
                
                $sql .= ") VALUES ($placeholders)";
                $stmt = $db->prepare($sql);
                $stmt->execute($params);
                $id = $db->lastInsertId();
                $message = 'サービスを登録しました。';
            }
            
            // クリエイターをseller有効化
            try {
                $stmt = $db->prepare("UPDATE creators SET is_seller = 1, seller_status = 'active' WHERE id = ?");
                $stmt->execute([$creator_id]);
            } catch (PDOException $e) {}
            
            // 参考作品の保存
            if ($hasServiceWorks && $id > 0) {
                try {
                    $stmt = $db->prepare("DELETE FROM service_works WHERE service_id = ?");
                    $stmt->execute([$id]);
                    
                    $workIds = $_POST['work_ids'] ?? [];
                    if (!empty($workIds)) {
                        $stmt = $db->prepare("INSERT INTO service_works (service_id, work_id, sort_order) VALUES (?, ?, ?)");
                        foreach ($workIds as $order => $workId) {
                            $stmt->execute([$id, (int)$workId, $order]);
                        }
                    }
                } catch (PDOException $e) {}
            }
            
            // 関連コレクションの保存
            if ($hasServiceCollections && $id > 0) {
                try {
                    $stmt = $db->prepare("DELETE FROM service_collections WHERE service_id = ?");
                    $stmt->execute([$id]);
                    
                    $collectionIds = $_POST['collection_ids'] ?? [];
                    if (!empty($collectionIds)) {
                        $stmt = $db->prepare("INSERT INTO service_collections (service_id, collection_id, sort_order) VALUES (?, ?, ?)");
                        foreach ($collectionIds as $order => $colId) {
                            $stmt->execute([$id, (int)$colId, $order]);
                        }
                    }
                } catch (PDOException $e) {}
            }
            
            // 編集画面にリダイレクト
            if (!$error) {
                header("Location: services.php?edit=$id&saved=1");
                exit;
            }
            
        } catch (PDOException $e) {
            $error = 'エラー: ' . $e->getMessage();
        }
    }
}

// プラン保存
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_plan'])) {
    if (validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $serviceId = (int)$_POST['service_id'];
        $planId = (int)($_POST['plan_id'] ?? 0);
        $planName = trim($_POST['plan_name']);
        $planDescription = trim($_POST['plan_description'] ?? '');
        $planPrice = (int)$_POST['plan_price'];
        $planDays = (int)$_POST['plan_delivery_days'];
        $planRevisions = (int)$_POST['plan_revision_count'];
        $planOrder = (int)($_POST['plan_sort_order'] ?? 0);
        
        try {
            if ($planId > 0) {
                $stmt = $db->prepare("UPDATE service_plans SET name = ?, description = ?, price = ?, delivery_days = ?, revision_count = ?, sort_order = ? WHERE id = ?");
                $stmt->execute([$planName, $planDescription, $planPrice, $planDays, $planRevisions, $planOrder, $planId]);
            } else {
                $stmt = $db->prepare("INSERT INTO service_plans (service_id, name, description, price, delivery_days, revision_count, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $stmt->execute([$serviceId, $planName, $planDescription, $planPrice, $planDays, $planRevisions, $planOrder]);
            }
            $message = 'プランを保存しました。';
        } catch (PDOException $e) {
            $error = 'プラン保存エラー: ' . $e->getMessage();
        }
    }
}

// オプション保存
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_option']) && $hasServiceOptions) {
    if (validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $serviceId = (int)$_POST['service_id'];
        $optionName = trim($_POST['option_name']);
        $optionDescription = trim($_POST['option_description'] ?? '');
        $optionPrice = (int)$_POST['option_price'];
        $optionOrder = (int)($_POST['option_sort_order'] ?? 0);
        
        try {
            $stmt = $db->prepare("INSERT INTO service_options (service_id, name, description, price, sort_order) VALUES (?, ?, ?, ?, ?)");
            $stmt->execute([$serviceId, $optionName, $optionDescription, $optionPrice, $optionOrder]);
            $message = 'オプションを追加しました。';
        } catch (PDOException $e) {
            $error = 'オプション保存エラー: ' . $e->getMessage();
        }
    }
}

// プラン削除
if (isset($_GET['delete_plan']) && isset($_GET['csrf_token'])) {
    if (validateCsrfToken($_GET['csrf_token'])) {
        $planId = (int)$_GET['delete_plan'];
        $stmt = $db->prepare("DELETE FROM service_plans WHERE id = ?");
        $stmt->execute([$planId]);
        $message = 'プランを削除しました。';
    }
}

// 保存完了メッセージ
if (isset($_GET['saved'])) {
    $message = 'サービスを保存しました。';
}

// 編集対象取得
$editService = null;
$editPlans = [];
$editOptions = [];
$linkedWorks = [];
$linkedCollections = [];

if (isset($_GET['edit'])) {
    $editId = (int)$_GET['edit'];
    $stmt = $db->prepare("SELECT * FROM services WHERE id = ?");
    $stmt->execute([$editId]);
    $editService = $stmt->fetch();
    
    // プラン取得
    try {
        $stmt = $db->prepare("SELECT * FROM service_plans WHERE service_id = ? ORDER BY sort_order, price");
        $stmt->execute([$editId]);
        $editPlans = $stmt->fetchAll();
    } catch (PDOException $e) {
        $editPlans = [];
    }
    
    // オプション取得
    if ($hasServiceOptions) {
        try {
            $stmt = $db->prepare("SELECT * FROM service_options WHERE service_id = ? ORDER BY sort_order, id");
            $stmt->execute([$editId]);
            $editOptions = $stmt->fetchAll();
        } catch (PDOException $e) {
            $editOptions = [];
        }
    }
    
    // 紐づけ作品取得
    if ($hasServiceWorks) {
        try {
            $stmt = $db->prepare("
                SELECT sw.work_id, w.title, w.image, c.name as creator_name
                FROM service_works sw 
                JOIN works w ON sw.work_id = w.id 
                LEFT JOIN creators c ON w.creator_id = c.id
                WHERE sw.service_id = ? 
                ORDER BY sw.sort_order
            ");
            $stmt->execute([$editId]);
            $linkedWorks = $stmt->fetchAll();
        } catch (PDOException $e) {
            $linkedWorks = [];
        }
    }
    
    // 紐づけコレクション取得
    if ($hasServiceCollections) {
        try {
            $stmt = $db->prepare("
                SELECT sc.collection_id, c.title
                FROM service_collections sc 
                JOIN collections c ON sc.collection_id = c.id 
                WHERE sc.service_id = ? 
                ORDER BY sc.sort_order
            ");
            $stmt->execute([$editId]);
            $linkedCollections = $stmt->fetchAll();
        } catch (PDOException $e) {
            $linkedCollections = [];
        }
    }
}

// アーカイブ切り替え
if (isset($_GET['archive']) && is_numeric($_GET['archive'])) {
    $stmt = $db->prepare("UPDATE services SET status = 'archived' WHERE id = ?");
    $stmt->execute([$_GET['archive']]);
    $message = 'サービスをアーカイブしました。';
}

if (isset($_GET['restore']) && is_numeric($_GET['restore'])) {
    $stmt = $db->prepare("UPDATE services SET status = 'draft' WHERE id = ?");
    $stmt->execute([$_GET['restore']]);
    $message = 'サービスを復元しました（下書き状態）。';
}

// 表示モード
$showArchived = isset($_GET['archived']);
$filterPending = isset($_GET['filter']) && $_GET['filter'] === 'pending';

// サービス一覧取得
if ($showArchived) {
    $services = $db->query("
        SELECT s.*, c.name as creator_name
        FROM services s
        LEFT JOIN creators c ON s.creator_id = c.id
        WHERE s.status = 'archived'
        ORDER BY s.id DESC
    ")->fetchAll();
} elseif ($filterPending) {
    $services = $db->query("
        SELECT s.*, c.name as creator_name
        FROM services s
        LEFT JOIN creators c ON s.creator_id = c.id
        WHERE s.approval_status = 'pending'
        ORDER BY s.submitted_at ASC
    ")->fetchAll();
} else {
    $services = $db->query("
        SELECT s.*, c.name as creator_name
        FROM services s
        LEFT JOIN creators c ON s.creator_id = c.id
        WHERE s.status != 'archived' OR s.status IS NULL
        ORDER BY s.sort_order, s.id DESC
    ")->fetchAll();
}

// アーカイブ数を取得
$archivedCount = $db->query("SELECT COUNT(*) FROM services WHERE status = 'archived'")->fetchColumn();

// 審査待ち数を取得
$pendingApprovalCount = 0;
try {
    $pendingApprovalCount = $db->query("SELECT COUNT(*) FROM services WHERE approval_status = 'pending'")->fetchColumn();
} catch (PDOException $e) {}

$pageTitle = 'サービス管理';
include 'includes/header.php';
?>

<style>
.work-card {
    transition: all 0.2s;
}
.work-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}
</style>

<div class="mb-6 flex flex-wrap justify-between items-center gap-4">
    <div>
        <h2 class="text-2xl font-bold text-gray-800">サービス管理</h2>
        <p class="text-gray-500">スキル販売サービスを管理します</p>
    </div>
    <div class="flex gap-2">
        <?php if ($pendingApprovalCount > 0): ?>
        <a href="services.php?filter=pending" class="px-4 py-2 bg-yellow-500 text-white rounded-lg font-bold hover:bg-yellow-600 transition">
            <i class="fas fa-clock mr-1"></i>審査待ち (<?= $pendingApprovalCount ?>)
        </a>
        <?php endif; ?>
        <?php if ($showArchived): ?>
        <a href="services.php" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg font-bold hover:bg-gray-300 transition">
            <i class="fas fa-list mr-1"></i>公開中
        </a>
        <?php else: ?>
        <?php if ($archivedCount > 0): ?>
        <a href="services.php?archived=1" class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg font-bold hover:bg-gray-300 transition">
            <i class="fas fa-archive mr-1"></i>アーカイブ (<?= $archivedCount ?>)
        </a>
        <?php endif; ?>
        <a href="?new=1" class="px-4 py-2 bg-green-500 text-white rounded-lg font-bold hover:bg-green-600 transition">
            <i class="fas fa-plus mr-2"></i>新規登録
        </a>
        <?php endif; ?>
    </div>
</div>

<?php if (isset($_GET['filter']) && $_GET['filter'] === 'pending'): ?>
<div class="bg-yellow-100 border border-yellow-200 text-yellow-700 px-4 py-3 rounded-lg mb-6">
    <i class="fas fa-clock mr-2"></i>審査待ちサービス一覧 - 確認後に承認または差し戻ししてください
</div>
<?php endif; ?>

<?php if ($showArchived): ?>
<div class="bg-gray-100 border border-gray-200 text-gray-600 px-4 py-3 rounded-lg mb-6">
    <i class="fas fa-archive mr-2"></i>アーカイブ済みサービス一覧
</div>
<?php endif; ?>

<?php if ($message): ?>
<div class="bg-green-50 border border-green-200 text-green-700 px-4 py-3 rounded-lg mb-6">
    <?= htmlspecialchars($message) ?>
</div>
<?php endif; ?>

<?php if ($error): ?>
<div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-lg mb-6">
    <?= htmlspecialchars($error) ?>
</div>
<?php endif; ?>

<?php if (isset($_GET['new']) || $editService): ?>
<!-- 登録・編集フォーム -->
<form method="POST" enctype="multipart/form-data" id="serviceForm">
    <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
    <input type="hidden" name="id" value="<?= $editService['id'] ?? 0 ?>">
    
    <!-- テンプレート選択（新規時のみ） -->
    <?php if (isset($_GET['new']) && !empty($serviceTemplates)): ?>
    <div class="bg-purple-50 rounded-xl border border-purple-200 p-4 mb-6">
        <div class="flex flex-wrap items-center gap-2">
            <span class="text-sm font-bold text-purple-700"><i class="fas fa-file-alt mr-1"></i>テンプレート:</span>
            <?php foreach ($serviceTemplates as $tpl): ?>
            <button type="button" onclick="loadTemplate(<?= $tpl['id'] ?>)"
                    class="px-3 py-1 bg-white border border-purple-300 text-purple-700 rounded-lg hover:bg-purple-100 transition text-sm">
                <?= htmlspecialchars($tpl['name']) ?>
            </button>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- 基本情報 -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
        <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
            <i class="fas fa-info-circle text-blue-500 mr-2"></i>
            基本情報
        </h3>
        
        <div class="grid md:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">クリエイター <span class="text-red-500">*</span></label>
                <select name="creator_id" required class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-400 outline-none">
                    <option value="">選択してください</option>
                    <?php foreach ($creators as $c): ?>
                    <option value="<?= $c['id'] ?>" <?= ($editService['creator_id'] ?? '') == $c['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($c['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">カテゴリ</label>
                <?php if (!empty($categories)): ?>
                <select name="category_id" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-400 outline-none">
                    <option value="">未分類</option>
                    <?php foreach ($categories as $cat): ?>
                    <option value="<?= $cat['id'] ?>" <?= ($editService['category_id'] ?? '') == $cat['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($cat['name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <?php else: ?>
                <input type="text" name="category" value="<?= htmlspecialchars($editService['category'] ?? '') ?>"
                       placeholder="例: イラスト制作"
                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-400 outline-none">
                <?php endif; ?>
            </div>
        </div>
        
        <div class="mb-4">
            <label class="block text-sm font-bold text-gray-700 mb-1">サービス名 <span class="text-red-500">*</span></label>
            <input type="text" name="title" required value="<?= htmlspecialchars($editService['title'] ?? '') ?>"
                   placeholder="例: オリジナル手書きアニメーション制作します"
                   class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-400 outline-none">
        </div>
        
        <?php if ($hasExtendedColumns): ?>
        <div class="mb-4">
            <label class="block text-sm font-bold text-gray-700 mb-1">サムネイル画像</label>
            <?php if (!empty($editService['thumbnail_image'])): ?>
            <div class="mb-2">
                <img src="../<?= htmlspecialchars($editService['thumbnail_image']) ?>" class="w-48 h-auto rounded-lg">
            </div>
            <?php endif; ?>
            <input type="file" name="thumbnail_image" accept="image/*"
                   class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-400 outline-none">
        </div>
        <?php endif; ?>
        
        <div class="mb-4">
            <label class="block text-sm font-bold text-gray-700 mb-1">概要（一覧表示用）</label>
            <textarea name="description" rows="2"
                      placeholder="絵コンテ〜納品まで一貫対応【縦横対応】！"
                      class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-400 outline-none"><?= htmlspecialchars($editService['description'] ?? '') ?></textarea>
        </div>
        
        <div class="mb-4">
            <label class="block text-sm font-bold text-gray-700 mb-1">サービス内容（詳細説明）</label>
            <textarea name="description_detail" rows="10"
                      placeholder="■ パッケージ（目安・税込）&#10;5〜15秒／ループアニメ・テロップ　¥50,000〜"
                      class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-400 outline-none font-mono text-sm"><?= htmlspecialchars($editService['description_detail'] ?? '') ?></textarea>
        </div>
        
        <div class="grid md:grid-cols-4 gap-4 mb-4">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">基本価格</label>
                <input type="number" name="base_price" value="<?= $editService['base_price'] ?? 50000 ?>" min="0"
                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-400 outline-none">
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">お届け日数</label>
                <input type="number" name="delivery_days" value="<?= $editService['delivery_days'] ?? 7 ?>" min="1"
                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-400 outline-none">
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">無料修正回数</label>
                <input type="number" name="revision_limit" value="<?= $editService['revision_limit'] ?? 3 ?>" min="0"
                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-400 outline-none">
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">ラフ提案数</label>
                <input type="number" name="draft_proposals" value="<?= $editService['draft_proposals'] ?? 1 ?>" min="1"
                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-400 outline-none">
            </div>
        </div>
        
        <div class="grid md:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">ステータス</label>
                <select name="status" class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-400 outline-none">
                    <option value="draft" <?= ($editService['status'] ?? 'draft') === 'draft' ? 'selected' : '' ?>>下書き</option>
                    <option value="active" <?= ($editService['status'] ?? '') === 'active' ? 'selected' : '' ?>>公開中</option>
                    <option value="paused" <?= ($editService['status'] ?? '') === 'paused' ? 'selected' : '' ?>>受付停止中</option>
                    <option value="archived" <?= ($editService['status'] ?? '') === 'archived' ? 'selected' : '' ?>>アーカイブ</option>
                </select>
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">表示順</label>
                <input type="number" name="sort_order" value="<?= $editService['sort_order'] ?? 0 ?>"
                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-400 outline-none">
            </div>
        </div>
    </div>

    <?php if ($hasExtendedColumns): ?>
    <!-- 提供詳細 -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
        <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
            <i class="fas fa-list-check text-green-500 mr-2"></i>
            提供詳細
        </h3>
        
        <div class="grid md:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">提供形式</label>
                <input type="text" name="provision_format" value="<?= htmlspecialchars($editService['provision_format'] ?? '') ?>"
                       placeholder="例: ビデオチャット打ち合わせ可能"
                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-400 outline-none">
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">スタイル</label>
                <input type="text" name="style" value="<?= htmlspecialchars($editService['style'] ?? '') ?>"
                       placeholder="例: 2D / 3D / 手描き"
                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-400 outline-none">
            </div>
        </div>
        
        <div class="grid md:grid-cols-2 gap-4 mb-4">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">ファイル形式</label>
                <input type="text" name="file_formats" value="<?= htmlspecialchars($editService['file_formats'] ?? '') ?>"
                       placeholder="例: MP4 / MOV / GIF"
                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-400 outline-none">
            </div>
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">用途タグ（カンマ区切り）</label>
                <input type="text" name="usage_tags" value="<?= htmlspecialchars($editService['usage_tags'] ?? '') ?>"
                       placeholder="例: CM, SNS, 各種メディア"
                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-400 outline-none">
            </div>
        </div>
        
        <div class="mb-4">
            <label class="block text-sm font-bold text-gray-700 mb-1">ジャンルタグ（カンマ区切り）</label>
            <input type="text" name="genre_tags" value="<?= htmlspecialchars($editService['genre_tags'] ?? '') ?>"
                   placeholder="例: 商品・サービスPR, 企業PR, 個人PR"
                   class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-400 outline-none">
        </div>
        
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 mb-4">
            <label class="flex items-center gap-2 p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100">
                <input type="checkbox" name="commercial_use" value="1" 
                       <?= ($editService['commercial_use'] ?? 1) ? 'checked' : '' ?>
                       class="w-4 h-4 text-green-500 rounded">
                <span class="text-sm text-gray-700">商用利用</span>
            </label>
            <label class="flex items-center gap-2 p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100">
                <input type="checkbox" name="secondary_use" value="1" 
                       <?= ($editService['secondary_use'] ?? 1) ? 'checked' : '' ?>
                       class="w-4 h-4 text-green-500 rounded">
                <span class="text-sm text-gray-700">二次利用</span>
            </label>
            <label class="flex items-center gap-2 p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100">
                <input type="checkbox" name="planning_included" value="1" 
                       <?= ($editService['planning_included'] ?? 0) ? 'checked' : '' ?>
                       class="w-4 h-4 text-green-500 rounded">
                <span class="text-sm text-gray-700">企画・構成</span>
            </label>
            <label class="flex items-center gap-2 p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100">
                <input type="checkbox" name="bgm_included" value="1" 
                       <?= ($editService['bgm_included'] ?? 0) ? 'checked' : '' ?>
                       class="w-4 h-4 text-green-500 rounded">
                <span class="text-sm text-gray-700">BGM・音声</span>
            </label>
        </div>
        
        <div class="mb-4">
            <label class="block text-sm font-bold text-gray-700 mb-1">制作の流れ</label>
            <textarea name="workflow" rows="6"
                      class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-400 outline-none font-mono text-sm"><?= htmlspecialchars($editService['workflow'] ?? '') ?></textarea>
        </div>
        
        <div>
            <label class="block text-sm font-bold text-gray-700 mb-1">購入にあたってのお願い</label>
            <textarea name="purchase_notes" rows="6"
                      class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-400 outline-none font-mono text-sm"><?= htmlspecialchars($editService['purchase_notes'] ?? '') ?></textarea>
        </div>
    </div>
    <?php endif; ?>

    <?php if ($hasServiceWorks): ?>
    <!-- 参考作品 -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
        <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
            <i class="fas fa-images text-purple-500 mr-2"></i>
            参考作品（ポートフォリオ）
        </h3>
        <p class="text-sm text-gray-500 mb-4">このサービスに関連する作品やコレクションを選択してください。</p>
        
        <!-- 現在紐づけられている作品・コレクション -->
        <div id="linkedWorksList" class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-3 mb-4 min-h-[100px] p-4 bg-gray-50 rounded-lg border-2 border-dashed border-gray-300">
            <?php if (empty($linkedWorks) && empty($linkedCollections)): ?>
            <p class="text-gray-400 text-sm col-span-full text-center py-8" id="noWorksText">
                <i class="fas fa-image text-gray-300 text-3xl mb-2 block"></i>
                作品・コレクションが選択されていません
            </p>
            <?php endif; ?>
            <?php foreach ($linkedWorks as $lw): ?>
            <div class="linked-work work-card relative bg-white border rounded-lg overflow-hidden shadow-sm" data-work-id="<?= $lw['work_id'] ?>">
                <div class="aspect-square bg-gray-100">
                    <?php if (!empty($lw['image'])): ?>
                    <img src="../<?= htmlspecialchars($lw['image']) ?>" class="w-full h-full object-cover">
                    <?php else: ?>
                    <div class="w-full h-full flex items-center justify-center bg-gray-200">
                        <i class="fas fa-image text-gray-400"></i>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="p-2">
                    <p class="text-xs font-medium text-gray-700 truncate"><?= htmlspecialchars($lw['title']) ?></p>
                    <p class="text-[10px] text-gray-400"><?= htmlspecialchars($lw['creator_name'] ?? '') ?></p>
                </div>
                <button type="button" onclick="removeLinkedWork(<?= $lw['work_id'] ?>)" 
                        class="absolute top-1 right-1 w-6 h-6 bg-red-500 text-white rounded-full text-xs hover:bg-red-600 shadow">
                    <i class="fas fa-times"></i>
                </button>
                <input type="hidden" name="work_ids[]" value="<?= $lw['work_id'] ?>">
            </div>
            <?php endforeach; ?>
            <?php if ($hasServiceCollections): ?>
            <?php foreach ($linkedCollections as $lc): ?>
            <div class="linked-collection work-card relative bg-white border-2 border-blue-300 rounded-lg overflow-hidden shadow-sm" data-collection-id="<?= $lc['collection_id'] ?>">
                <div class="aspect-square bg-blue-50 flex items-center justify-center">
                    <i class="fas fa-folder text-blue-400 text-3xl"></i>
                </div>
                <div class="p-2 bg-blue-50">
                    <p class="text-xs font-medium text-blue-700 truncate"><?= htmlspecialchars($lc['title']) ?></p>
                    <p class="text-[10px] text-blue-500">コレクション</p>
                </div>
                <button type="button" onclick="removeLinkedCollection(<?= $lc['collection_id'] ?>)" 
                        class="absolute top-1 right-1 w-6 h-6 bg-red-500 text-white rounded-full text-xs hover:bg-red-600 shadow">
                    <i class="fas fa-times"></i>
                </button>
                <input type="hidden" name="collection_ids[]" value="<?= $lc['collection_id'] ?>">
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
        
        <!-- タブ切り替え -->
        <div class="flex gap-2 mb-4">
            <button type="button" onclick="switchWorkTab('works')" id="tabWorks" class="px-4 py-2 rounded-lg font-bold text-sm bg-purple-500 text-white">
                <i class="fas fa-image mr-1"></i>作品
            </button>
            <?php if (!empty($allCollections)): ?>
            <button type="button" onclick="switchWorkTab('collections')" id="tabCollections" class="px-4 py-2 rounded-lg font-bold text-sm bg-gray-200 text-gray-600 hover:bg-gray-300">
                <i class="fas fa-folder mr-1"></i>コレクション
            </button>
            <?php endif; ?>
        </div>
        
        <!-- 作品選択タブ -->
        <div id="panelWorks">
            <!-- 検索フィルター -->
            <div class="flex gap-2 mb-3">
                <input type="text" id="workSearchQuery" placeholder="作品名で検索..."
                       onkeyup="filterWorks()"
                       class="flex-1 px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-purple-400 outline-none">
                <select id="workCreatorFilter" onchange="filterWorks()" 
                        class="px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-purple-400 outline-none">
                    <option value="">全クリエイター</option>
                    <?php foreach ($creators as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="max-h-64 overflow-y-auto border rounded-lg p-3 bg-white" id="worksGrid">
                <div class="grid grid-cols-3 sm:grid-cols-4 md:grid-cols-6 lg:grid-cols-8 gap-2">
                    <?php foreach ($allWorks as $w): ?>
                    <div class="work-selector cursor-pointer border-2 border-transparent rounded-lg overflow-hidden hover:border-purple-400 transition"
                         onclick="addLinkedWorkFromCard(<?= $w['id'] ?>, '<?= htmlspecialchars(addslashes($w['title']), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($w['image'] ?? ''), ENT_QUOTES) ?>', '<?= htmlspecialchars(addslashes($w['creator_name'] ?? ''), ENT_QUOTES) ?>')"
                         data-work-id="<?= $w['id'] ?>"
                         data-title="<?= htmlspecialchars(strtolower($w['title'])) ?>"
                         data-creator-id="<?= $w['creator_id'] ?? '' ?>"
                         data-creator-name="<?= htmlspecialchars(strtolower($w['creator_name'] ?? '')) ?>">
                        <div class="aspect-square bg-gray-100">
                            <?php if (!empty($w['image'])): ?>
                            <img src="../<?= htmlspecialchars($w['image']) ?>" class="w-full h-full object-cover" loading="lazy">
                            <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center bg-gray-200">
                                <i class="fas fa-image text-gray-400 text-xs"></i>
                            </div>
                            <?php endif; ?>
                        </div>
                        <p class="text-[10px] p-1 truncate text-gray-600"><?= htmlspecialchars($w['title']) ?></p>
                        <p class="text-[9px] px-1 pb-1 truncate text-gray-400"><?= htmlspecialchars($w['creator_name'] ?? '') ?></p>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        
        <!-- コレクション選択タブ -->
        <?php if (!empty($allCollections)): ?>
        <div id="panelCollections" class="hidden">
            <div class="max-h-64 overflow-y-auto border rounded-lg p-3 bg-white">
                <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-3">
                    <?php foreach ($allCollections as $col): ?>
                    <div class="collection-selector cursor-pointer border-2 border-transparent rounded-lg overflow-hidden hover:border-blue-400 transition bg-blue-50"
                         onclick="addLinkedCollection(<?= $col['id'] ?>, '<?= htmlspecialchars(addslashes($col['title']), ENT_QUOTES) ?>')"
                         data-collection-id="<?= $col['id'] ?>">
                        <div class="p-4 text-center">
                            <i class="fas fa-folder text-blue-400 text-3xl mb-2"></i>
                            <p class="text-sm font-medium text-blue-700 truncate"><?= htmlspecialchars($col['title']) ?></p>
                            <p class="text-xs text-blue-500"><?= $col['work_count'] ?? 0 ?>作品</p>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    
    <!-- 表示設定 -->
    <?php if ($hasDisplaySettings): ?>
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
        <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center">
            <i class="fas fa-eye text-blue-500 mr-2"></i>
            表示設定
        </h3>
        
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
            <label class="flex items-center gap-2 p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100">
                <input type="checkbox" name="show_in_store" value="1" 
                       <?= ($editService['show_in_store'] ?? 1) ? 'checked' : '' ?>
                       class="w-4 h-4 text-blue-500 rounded">
                <span class="text-sm text-gray-700">ストア</span>
            </label>
            <label class="flex items-center gap-2 p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100">
                <input type="checkbox" name="show_in_creator_page" value="1" 
                       <?= ($editService['show_in_creator_page'] ?? 1) ? 'checked' : '' ?>
                       class="w-4 h-4 text-blue-500 rounded">
                <span class="text-sm text-gray-700">クリエイターページ</span>
            </label>
            <label class="flex items-center gap-2 p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100">
                <input type="checkbox" name="show_in_gallery" value="1" 
                       <?= ($editService['show_in_gallery'] ?? 0) ? 'checked' : '' ?>
                       class="w-4 h-4 text-blue-500 rounded">
                <span class="text-sm text-gray-700">ギャラリー</span>
            </label>
            <label class="flex items-center gap-2 p-3 bg-gray-50 rounded-lg cursor-pointer hover:bg-gray-100">
                <input type="checkbox" name="show_in_top" value="1" 
                       <?= ($editService['show_in_top'] ?? 0) ? 'checked' : '' ?>
                       class="w-4 h-4 text-blue-500 rounded">
                <span class="text-sm text-gray-700">トップページ</span>
            </label>
        </div>
        
        <div class="mt-4">
            <label class="flex items-center gap-2">
                <input type="checkbox" name="is_featured" value="1" <?= ($editService['is_featured'] ?? 0) ? 'checked' : '' ?>
                       class="w-4 h-4 text-yellow-500 rounded">
                <span class="text-sm font-bold text-gray-700">⭐ おすすめに表示</span>
            </label>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- 保存ボタン -->
    <div class="flex flex-wrap gap-3 mb-6">
        <button type="submit" class="px-8 py-3 bg-green-500 text-white rounded-lg font-bold hover:bg-green-600 transition text-lg">
            <i class="fas fa-save mr-2"></i>保存
        </button>
        <a href="services.php" class="px-6 py-3 bg-gray-300 text-gray-700 rounded-lg font-bold hover:bg-gray-400 transition">
            キャンセル
        </a>
        <button type="button" onclick="showSaveTemplateModal()" class="px-6 py-3 bg-purple-500 text-white rounded-lg font-bold hover:bg-purple-600 transition">
            <i class="fas fa-file-export mr-2"></i>テンプレートとして保存
        </button>
    </div>
</form>

<!-- テンプレート保存モーダル -->
<div id="saveTemplateModal" class="fixed inset-0 bg-black/50 z-50 hidden items-center justify-center">
    <div class="bg-white rounded-xl p-6 max-w-md w-full mx-4">
        <h3 class="text-lg font-bold mb-4">
            <i class="fas fa-file-export text-purple-500 mr-2"></i>テンプレートとして保存
        </h3>
        <form method="POST" id="saveTemplateForm">
            <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
            <input type="hidden" name="save_template" value="1">
            <!-- フォームの値をコピーするhidden fields -->
            <div id="templateFormFields"></div>
            
            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-1">テンプレート名 <span class="text-red-500">*</span></label>
                <input type="text" name="template_name" required placeholder="例: イラスト制作（基本）"
                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-purple-400 outline-none">
            </div>
            <div class="mb-4">
                <label class="block text-sm font-bold text-gray-700 mb-1">説明</label>
                <input type="text" name="template_description" placeholder="テンプレートの説明"
                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-purple-400 outline-none">
            </div>
            <div class="flex gap-3 justify-end">
                <button type="button" onclick="closeSaveTemplateModal()" class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400">
                    キャンセル
                </button>
                <button type="submit" class="px-4 py-2 bg-purple-500 text-white rounded-lg hover:bg-purple-600">
                    <i class="fas fa-save mr-1"></i>保存
                </button>
            </div>
        </form>
    </div>
</div>

<script>
// サービスフォームのタブ切り替え
function switchServiceTab(tabName) {
    // すべてのタブボタンを非アクティブに
    document.querySelectorAll('.service-tab').forEach(btn => {
        btn.classList.remove('border-green-500', 'text-green-600', 'bg-green-50');
        btn.classList.add('border-transparent', 'text-gray-500');
    });
    
    // すべてのパネルを非表示に
    document.querySelectorAll('.service-panel').forEach(panel => {
        panel.classList.add('hidden');
    });
    
    // クリックされたタブをアクティブに
    const activeTab = document.getElementById('tab-' + tabName);
    if (activeTab) {
        activeTab.classList.add('border-green-500', 'text-green-600', 'bg-green-50');
        activeTab.classList.remove('border-transparent', 'text-gray-500');
    }
    
    // 対応するパネルを表示
    const activePanel = document.getElementById('panel-' + tabName);
    if (activePanel) {
        activePanel.classList.remove('hidden');
    }
}

// テンプレート読み込み
function loadTemplate(templateId) {
    if (!confirm('テンプレートの内容をフォームに適用しますか？\n現在の入力内容は上書きされます。')) {
        return;
    }
    
    fetch('?get_template=' + templateId)
        .then(response => response.json())
        .then(data => {
            if (data.success && data.template) {
                const t = data.template;
                const form = document.getElementById('serviceForm');
                
                // テキストフィールド
                if (t.title) form.querySelector('[name="title"]').value = t.title;
                if (t.service_description) form.querySelector('[name="description"]').value = t.service_description;
                if (t.description_detail) form.querySelector('[name="description_detail"]').value = t.description_detail;
                if (t.purchase_notes) {
                    const el = form.querySelector('[name="purchase_notes"]');
                    if (el) el.value = t.purchase_notes;
                }
                if (t.workflow) {
                    const el = form.querySelector('[name="workflow"]');
                    if (el) el.value = t.workflow;
                }
                
                // 数値フィールド
                if (t.base_price) form.querySelector('[name="base_price"]').value = t.base_price;
                if (t.delivery_days) form.querySelector('[name="delivery_days"]').value = t.delivery_days;
                if (t.revision_limit) form.querySelector('[name="revision_limit"]').value = t.revision_limit;
                
                // 拡張フィールド
                const numFields = ['min_price', 'max_price', 'free_revisions', 'draft_proposals'];
                numFields.forEach(field => {
                    const el = form.querySelector('[name="' + field + '"]');
                    if (el && t[field]) el.value = t[field];
                });
                
                const textFields = ['provision_format', 'style', 'usage_tags', 'genre_tags', 'file_formats'];
                textFields.forEach(field => {
                    const el = form.querySelector('[name="' + field + '"]');
                    if (el && t[field]) el.value = t[field];
                });
                
                // チェックボックス
                const checkFields = ['commercial_use', 'secondary_use', 'planning_included', 'bgm_included',
                                    'show_in_gallery', 'show_in_creator_page', 'show_in_top', 'show_in_store'];
                checkFields.forEach(field => {
                    const el = form.querySelector('[name="' + field + '"]');
                    if (el) el.checked = (t[field] == 1);
                });
                
                alert('テンプレートを適用しました');
            } else {
                alert('テンプレートの読み込みに失敗しました');
            }
        })
        .catch(err => {
            alert('エラーが発生しました: ' + err);
        });
}

// テンプレート保存モーダル表示
function showSaveTemplateModal() {
    const modal = document.getElementById('saveTemplateModal');
    const form = document.getElementById('serviceForm');
    const fieldsContainer = document.getElementById('templateFormFields');
    
    // フォームの値をコピー
    fieldsContainer.innerHTML = '';
    const formData = new FormData(form);
    for (let [key, value] of formData.entries()) {
        if (key !== 'csrf_token' && key !== 'id' && key !== 'thumbnail_image') {
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

// タブ切り替え
function switchWorkTab(tab) {
    const panelWorks = document.getElementById('panelWorks');
    const panelCollections = document.getElementById('panelCollections');
    const tabWorks = document.getElementById('tabWorks');
    const tabCollections = document.getElementById('tabCollections');
    
    if (tab === 'works') {
        panelWorks.classList.remove('hidden');
        if (panelCollections) panelCollections.classList.add('hidden');
        tabWorks.className = 'px-4 py-2 rounded-lg font-bold text-sm bg-purple-500 text-white';
        if (tabCollections) tabCollections.className = 'px-4 py-2 rounded-lg font-bold text-sm bg-gray-200 text-gray-600 hover:bg-gray-300';
    } else {
        panelWorks.classList.add('hidden');
        if (panelCollections) panelCollections.classList.remove('hidden');
        tabWorks.className = 'px-4 py-2 rounded-lg font-bold text-sm bg-gray-200 text-gray-600 hover:bg-gray-300';
        if (tabCollections) tabCollections.className = 'px-4 py-2 rounded-lg font-bold text-sm bg-blue-500 text-white';
    }
}

// 作品検索フィルター
function filterWorks() {
    const query = document.getElementById('workSearchQuery').value.toLowerCase();
    const creatorId = document.getElementById('workCreatorFilter').value;
    const selectors = document.querySelectorAll('.work-selector');
    
    selectors.forEach(selector => {
        const title = selector.dataset.title || '';
        const creatorName = selector.dataset.creatorName || '';
        const workCreatorId = selector.dataset.creatorId || '';
        
        const matchQuery = !query || title.includes(query) || creatorName.includes(query);
        const matchCreator = !creatorId || workCreatorId === creatorId;
        
        selector.style.display = (matchQuery && matchCreator) ? '' : 'none';
    });
}

// 作品追加
function addLinkedWorkFromCard(workId, title, image, creatorName) {
    if (document.querySelector('.linked-work[data-work-id="' + workId + '"]')) {
        alert('この作品は既に追加されています');
        return;
    }
    
    const noWorksText = document.getElementById('noWorksText');
    if (noWorksText) noWorksText.remove();
    
    const list = document.getElementById('linkedWorksList');
    const div = document.createElement('div');
    div.className = 'linked-work work-card relative bg-white border rounded-lg overflow-hidden shadow-sm';
    div.dataset.workId = workId;
    
    const imgHtml = image 
        ? '<img src="../' + image + '" class="w-full h-full object-cover">'
        : '<div class="w-full h-full flex items-center justify-center bg-gray-200"><i class="fas fa-image text-gray-400"></i></div>';
    
    div.innerHTML = 
        '<div class="aspect-square bg-gray-100">' + imgHtml + '</div>' +
        '<div class="p-2">' +
            '<p class="text-xs font-medium text-gray-700 truncate">' + title + '</p>' +
            '<p class="text-[10px] text-gray-400">' + (creatorName || '') + '</p>' +
        '</div>' +
        '<button type="button" onclick="removeLinkedWork(' + workId + ')" class="absolute top-1 right-1 w-6 h-6 bg-red-500 text-white rounded-full text-xs hover:bg-red-600 shadow"><i class="fas fa-times"></i></button>' +
        '<input type="hidden" name="work_ids[]" value="' + workId + '">';
    list.appendChild(div);
}

// 作品削除
function removeLinkedWork(workId) {
    const item = document.querySelector('.linked-work[data-work-id="' + workId + '"]');
    if (item) {
        item.remove();
        checkEmptyList();
    }
}

// コレクション追加
function addLinkedCollection(collectionId, title) {
    if (document.querySelector('.linked-collection[data-collection-id="' + collectionId + '"]')) {
        alert('このコレクションは既に追加されています');
        return;
    }
    
    const noWorksText = document.getElementById('noWorksText');
    if (noWorksText) noWorksText.remove();
    
    const list = document.getElementById('linkedWorksList');
    const div = document.createElement('div');
    div.className = 'linked-collection work-card relative bg-white border-2 border-blue-300 rounded-lg overflow-hidden shadow-sm';
    div.dataset.collectionId = collectionId;
    
    div.innerHTML = 
        '<div class="aspect-square bg-blue-50 flex items-center justify-center"><i class="fas fa-folder text-blue-400 text-3xl"></i></div>' +
        '<div class="p-2 bg-blue-50">' +
            '<p class="text-xs font-medium text-blue-700 truncate">' + title + '</p>' +
            '<p class="text-[10px] text-blue-500">コレクション</p>' +
        '</div>' +
        '<button type="button" onclick="removeLinkedCollection(' + collectionId + ')" class="absolute top-1 right-1 w-6 h-6 bg-red-500 text-white rounded-full text-xs hover:bg-red-600 shadow"><i class="fas fa-times"></i></button>' +
        '<input type="hidden" name="collection_ids[]" value="' + collectionId + '">';
    list.appendChild(div);
}

// コレクション削除
function removeLinkedCollection(collectionId) {
    const item = document.querySelector('.linked-collection[data-collection-id="' + collectionId + '"]');
    if (item) {
        item.remove();
        checkEmptyList();
    }
}

// リストが空かチェック
function checkEmptyList() {
    const list = document.getElementById('linkedWorksList');
    if (!list.querySelector('.linked-work') && !list.querySelector('.linked-collection')) {
        const p = document.createElement('p');
        p.className = 'text-gray-400 text-sm col-span-full text-center py-8';
        p.id = 'noWorksText';
        p.innerHTML = '<i class="fas fa-image text-gray-300 text-3xl mb-2 block"></i>作品・コレクションが選択されていません';
        list.appendChild(p);
    }
}
</script>

<?php endif; // isset($_GET['new']) || $editService ?>

<!-- サービス一覧 -->
<?php if (!isset($_GET['new']) && !$editService): ?>
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <table class="w-full">
        <thead class="bg-gray-50">
            <tr>
                <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">サービス名</th>
                <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">クリエイター</th>
                <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">価格</th>
                <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">ステータス</th>
                <th class="px-4 py-3 text-center text-sm font-bold text-gray-600">操作</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100">
            <?php if (empty($services)): ?>
            <tr>
                <td colspan="5" class="px-4 py-8 text-center text-gray-500">
                    サービスがまだ登録されていません
                </td>
            </tr>
            <?php endif; ?>
            <?php foreach ($services as $service): ?>
            <tr class="hover:bg-gray-50">
                <td class="px-4 py-3">
                    <div class="font-bold text-gray-800"><?= htmlspecialchars($service['title']) ?></div>
                    <?php if (!empty($service['category'])): ?>
                    <span class="text-xs text-gray-500"><?= htmlspecialchars($service['category']) ?></span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($service['creator_name'] ?? '-') ?></td>
                <td class="px-4 py-3 text-green-600 font-bold">¥<?= number_format($service['base_price']) ?>〜</td>
                <td class="px-4 py-3">
                    <?php
                    $statusColors = [
                        'active' => 'bg-green-100 text-green-700',
                        'draft' => 'bg-gray-100 text-gray-700',
                        'paused' => 'bg-yellow-100 text-yellow-700',
                        'archived' => 'bg-red-100 text-red-700'
                    ];
                    $statusLabels = [
                        'active' => '公開中',
                        'draft' => '下書き',
                        'paused' => '停止中',
                        'archived' => 'アーカイブ'
                    ];
                    $statusClass = $statusColors[$service['status']] ?? 'bg-gray-100 text-gray-700';
                    $statusLabel = $statusLabels[$service['status']] ?? $service['status'];
                    ?>
                    <span class="px-2 py-1 rounded text-xs font-bold <?= $statusClass ?>"><?= $statusLabel ?></span>
                    
                    <?php 
                    // 審査ステータス
                    $approvalStatus = $service['approval_status'] ?? 'approved';
                    $approvalColors = [
                        'draft' => 'bg-gray-100 text-gray-600',
                        'pending' => 'bg-yellow-100 text-yellow-700',
                        'approved' => 'bg-green-100 text-green-700',
                        'rejected' => 'bg-red-100 text-red-700'
                    ];
                    $approvalLabels = [
                        'draft' => '下書き',
                        'pending' => '審査待ち',
                        'approved' => '承認済',
                        'rejected' => '要修正'
                    ];
                    if ($approvalStatus !== 'approved'):
                    ?>
                    <span class="px-2 py-1 rounded text-xs font-bold <?= $approvalColors[$approvalStatus] ?? '' ?> ml-1">
                        <?= $approvalLabels[$approvalStatus] ?? '' ?>
                    </span>
                    <?php endif; ?>
                </td>
                <td class="px-4 py-3 text-center">
                    <?php if (!$showArchived): ?>
                    
                    <?php if (($service['approval_status'] ?? 'approved') === 'pending'): ?>
                    <!-- 審査ボタン -->
                    <button onclick="showApprovalModal(<?= $service['id'] ?>, '<?= htmlspecialchars(addslashes($service['title'])) ?>')"
                            class="px-2 py-1 bg-yellow-500 text-white rounded text-xs font-bold hover:bg-yellow-600 mr-2">
                        <i class="fas fa-check mr-1"></i>審査
                    </button>
                    <?php endif; ?>
                    
                    <a href="?edit=<?= $service['id'] ?>" class="text-blue-500 hover:text-blue-700 mx-1" title="編集">
                        <i class="fas fa-edit"></i>
                    </a>
                    <a href="../store/services/detail.php?id=<?= $service['id'] ?>" target="_blank" class="text-gray-500 hover:text-gray-700 mx-1" title="プレビュー">
                        <i class="fas fa-external-link-alt"></i>
                    </a>
                    <a href="?archive=<?= $service['id'] ?>" 
                       onclick="return confirm('このサービスをアーカイブしますか？')"
                       class="text-orange-500 hover:text-orange-700 mx-1" title="アーカイブ">
                        <i class="fas fa-archive"></i>
                    </a>
                    <?php else: ?>
                    <a href="?restore=<?= $service['id'] ?>" 
                       onclick="return confirm('このサービスを復元しますか？')"
                       class="text-blue-500 hover:text-blue-700 mx-1" title="復元">
                        <i class="fas fa-undo mr-1"></i>復元
                    </a>
                    <a href="?delete=<?= $service['id'] ?>&csrf_token=<?= generateCsrfToken() ?>" 
                       onclick="return confirm('このサービスを完全に削除しますか？この操作は取り消せません。')"
                       class="text-red-500 hover:text-red-700 mx-1" title="完全削除">
                        <i class="fas fa-trash"></i>
                    </a>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- 審査モーダル -->
<div id="approvalModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl max-w-md w-full">
        <div class="p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4">
                <i class="fas fa-check-circle text-yellow-500 mr-2"></i>サービス審査
            </h3>
            <p class="text-gray-600 mb-4">
                <span id="approvalServiceTitle" class="font-bold"></span> を審査します。
            </p>
            
            <form method="POST" id="approvalForm">
                <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
                <input type="hidden" name="service_id" id="approvalServiceId">
                
                <div class="mb-4">
                    <label class="block text-sm font-bold text-gray-700 mb-2">差し戻しの場合は理由を入力</label>
                    <textarea name="approval_note" id="approvalNote" rows="3" 
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-400 outline-none"
                              placeholder="修正が必要な点を具体的に記載してください"></textarea>
                </div>
                
                <div class="flex gap-3">
                    <button type="submit" name="approval_action" value="approve"
                            class="flex-1 px-4 py-2 bg-green-500 text-white rounded-lg font-bold hover:bg-green-600 transition">
                        <i class="fas fa-check mr-2"></i>承認
                    </button>
                    <button type="submit" name="approval_action" value="reject"
                            class="flex-1 px-4 py-2 bg-red-500 text-white rounded-lg font-bold hover:bg-red-600 transition">
                        <i class="fas fa-times mr-2"></i>差し戻し
                    </button>
                    <button type="button" onclick="closeApprovalModal()"
                            class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg font-bold hover:bg-gray-300 transition">
                        キャンセル
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
function showApprovalModal(serviceId, title) {
    document.getElementById('approvalServiceId').value = serviceId;
    document.getElementById('approvalServiceTitle').textContent = title;
    document.getElementById('approvalNote').value = '';
    document.getElementById('approvalModal').classList.remove('hidden');
}

function closeApprovalModal() {
    document.getElementById('approvalModal').classList.add('hidden');
}
</script>

<?php include 'includes/footer.php'; ?>
