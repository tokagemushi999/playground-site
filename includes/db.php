<?php
/**
 * データベース接続設定
 * Xサーバー用
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'tokage01_uramiti');
define('DB_USER', 'tokage01_wp1');
define('DB_PASS', 'GlL9}+V~R@.e');
define('DB_CHARSET', 'utf8mb4');

/**
 * PDO接続を取得
 */
function getDB() {
    static $pdo = null;
    
    if ($pdo === null) {
        try {
            $dsn = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET;
            $options = [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];
            $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
        } catch (PDOException $e) {
            error_log("Database connection failed: " . $e->getMessage());
            die("データベース接続エラーが発生しました。");
        }
    }
    
    return $pdo;
}

/**
 * サイト設定を取得
 */
function getSiteSettings() {
    $db = getDB();
    try {
        $stmt = $db->query("SELECT * FROM site_settings LIMIT 1");
        return $stmt->fetch() ?: [];
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * アクティブなクリエイター一覧を取得
 */
function getCreators() {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM creators WHERE is_active = 1 ORDER BY sort_order ASC, id ASC");
    return $stmt->fetchAll();
}

/**
 * クリエイターを1件取得
 */
function getCreator($id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM creators WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

function attachWorkPages($works) {
    if (empty($works)) return $works;
    
    $db = getDB();
    $workIds = array_column($works, 'id');
    
    if (empty($workIds)) return $works;
    
    $placeholders = implode(',', array_fill(0, count($workIds), '?'));
    $stmt = $db->prepare("SELECT work_id, image as image_path, page_number FROM work_pages WHERE work_id IN ($placeholders) ORDER BY page_number ASC");
    $stmt->execute($workIds);
    $pages = $stmt->fetchAll();
    
    // work_idでグループ化
    $pagesByWork = [];
    foreach ($pages as $page) {
        $pagesByWork[$page['work_id']][] = $page;
    }
    
    foreach ($works as &$work) {
        $work['manga_pages'] = $pagesByWork[$work['id']] ?? [];
    }
    
    return $works;
}

/**
 * アクティブな作品一覧を取得（漫画ページ含む）
 */
function getWorks($creatorId = null) {
    $db = getDB();
    if ($creatorId) {
        $stmt = $db->prepare("SELECT w.*, c.name as creator_name FROM works w LEFT JOIN creators c ON w.creator_id = c.id WHERE w.is_active = 1 AND w.creator_id = ? ORDER BY w.sort_order ASC, w.id DESC");
        $stmt->execute([$creatorId]);
    } else {
        $stmt = $db->query("SELECT w.*, c.name as creator_name FROM works w LEFT JOIN creators c ON w.creator_id = c.id WHERE w.is_active = 1 ORDER BY w.sort_order ASC, w.id DESC");
    }
    $works = $stmt->fetchAll();
    
    // 商品情報を取得
    $works = attachProductInfo($works);
    
    return attachWorkPages($works);
}

/**
 * 作品に商品情報を付与（productsテーブルのrelated_work_idから逆引き）
 */
function attachProductInfo($works) {
    if (empty($works)) return $works;
    
    $db = getDB();
    
    // productsテーブルが存在するか確認
    try {
        $db->query("SELECT 1 FROM products LIMIT 1");
    } catch (PDOException $e) {
        // productsテーブルがない場合はそのまま返す
        return $works;
    }
    
    // 作品IDを収集
    $workIds = [];
    foreach ($works as $work) {
        $workIds[] = $work['id'];
    }
    
    if (empty($workIds)) return $works;
    
    // related_work_idで商品を検索（preview_pagesも取得）
    $placeholders = implode(',', array_fill(0, count($workIds), '?'));
    $stmt = $db->prepare("SELECT id, name, price, product_type, related_work_id, preview_pages FROM products WHERE related_work_id IN ($placeholders) AND is_published = 1");
    $stmt->execute($workIds);
    $products = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // related_work_idをキーとした配列に変換
    $productMap = [];
    foreach ($products as $product) {
        $productMap[$product['related_work_id']] = $product;
    }
    
    // 作品に商品情報を付与
    foreach ($works as &$work) {
        if (isset($productMap[$work['id']])) {
            $work['product'] = $productMap[$work['id']];
            $work['product_id'] = $productMap[$work['id']]['id'];
        } else {
            $work['product'] = null;
            $work['product_id'] = null;
        }
    }
    
    return $works;
}

/**
 * 注目作品を取得（漫画ページ含む）
 */
function getFeaturedWorks() {
    $db = getDB();
    $stmt = $db->query("SELECT w.*, c.name as creator_name FROM works w LEFT JOIN creators c ON w.creator_id = c.id WHERE w.is_active = 1 AND w.is_featured = 1 ORDER BY w.sort_order ASC LIMIT 12");
    $works = $stmt->fetchAll();
    
    return attachWorkPages($works);
}

/**
 * アクティブな記事一覧を取得
 */
function getArticles() {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM articles WHERE is_active = 1 ORDER BY published_at DESC, id DESC");
    return $stmt->fetchAll();
}

/**
 * LABツール一覧を取得
 */
function getLabTools() {
    $db = getDB();
    $stmt = $db->query("SELECT * FROM lab_tools WHERE is_active = 1 ORDER BY sort_order ASC");
    return $stmt->fetchAll();
}

/**
 * コレクション一覧を取得
 */
function getCollections($creatorId = null) {
    $db = getDB();
    $query = "SELECT c.*, cr.name as creator_name, 
              (SELECT COUNT(*) FROM works WHERE collection_id = c.id AND is_active = 1) as sticker_count
              FROM collections c 
              LEFT JOIN creators cr ON c.creator_id = cr.id 
              WHERE c.is_active = 1";
    
    if ($creatorId) {
        $query .= " AND c.creator_id = ?";
        $stmt = $db->prepare($query . " ORDER BY c.sort_order ASC, c.id DESC");
        $stmt->execute([$creatorId]);
    } else {
        $stmt = $db->query($query . " ORDER BY c.sort_order ASC, c.id DESC");
    }
    return $stmt->fetchAll();
}

/**
 * コレクションを1件取得
 */
function getCollection($id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT c.*, cr.name as creator_name FROM collections c LEFT JOIN creators cr ON c.creator_id = cr.id WHERE c.id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * コレクションに属するステッカーを取得
 */
function getStickersByCollection($collectionId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT w.*, c.name as creator_name FROM works w LEFT JOIN creators c ON w.creator_id = c.id WHERE w.collection_id = ? AND w.is_active = 1 ORDER BY w.collection_order ASC, w.id ASC");
    $stmt->execute([$collectionId]);
    return $stmt->fetchAll();
}

/**
 * コレクションの代表画像を含む表示用データを取得
 */
function getCollectionWithImages($collectionId) {
    $db = getDB();
    $collection = getCollection($collectionId);
    if (!$collection) return null;
    
    $stickers = getStickersByCollection($collectionId);
    $collection['stickers'] = $stickers;
    $collection['sticker_count'] = count($stickers);
    
    // 代表画像
    if ($collection['representative_work_id']) {
        $stmt = $db->prepare("SELECT image, back_image FROM works WHERE id = ?");
        $stmt->execute([$collection['representative_work_id']]);
        $collection['representative'] = $stmt->fetch();
    }
    
    // 重なり表示用に最大4枚取得
    $collection['stack_images'] = array_slice($stickers, 0, 4);
    
    return $collection;
}

/**
 * 全コレクションを表示用データとして取得
 */
function getAllCollectionsWithImages($creatorId = null) {
    $collections = getCollections($creatorId);
    $result = [];
    
    foreach ($collections as $collection) {
        $collectionData = getCollectionWithImages($collection['id']);
        if ($collectionData) {
            $result[] = $collectionData;
        }
    }
    
    return $result;
}

/**
 * ステッカー一覧を取得（コレクションに属さない単独ステッカー）
 */
function getStickers($creatorId = null) {
    $db = getDB();
    if ($creatorId) {
        $stmt = $db->prepare("SELECT w.*, c.name as creator_name FROM works w LEFT JOIN creators c ON w.creator_id = c.id WHERE w.is_active = 1 AND w.is_omake_sticker = 1 AND w.collection_id IS NULL AND w.creator_id = ? ORDER BY w.sort_order ASC, w.id DESC");
        $stmt->execute([$creatorId]);
    } else {
        $stmt = $db->query("SELECT w.*, c.name as creator_name FROM works w LEFT JOIN creators c ON w.creator_id = c.id WHERE w.is_active = 1 AND w.is_omake_sticker = 1 AND w.collection_id IS NULL ORDER BY w.sort_order ASC, w.id DESC");
    }
    return $stmt->fetchAll();
}

// 後方互換性のためのエイリアス関数
function getStickerGroups($creatorId = null) { return getCollections($creatorId); }
function getStickerGroup($id) { return getCollection($id); }
function getStickersByGroup($groupId) { return getStickersByCollection($groupId); }
function getStickerGroupWithImages($groupId) { return getCollectionWithImages($groupId); }
function getAllStickerGroupsWithImages($creatorId = null) { return getAllCollectionsWithImages($creatorId); }

// ============================================
// サービス関連関数
// ============================================

/**
 * サービスカテゴリ一覧を取得
 */
function getServiceCategories() {
    $db = getDB();
    try {
        $stmt = $db->query("SELECT * FROM service_categories WHERE is_active = 1 ORDER BY sort_order ASC");
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * サービス一覧を取得
 * @param int|null $creatorId クリエイターID
 * @param string $status ステータス
 * @param int|null $categoryId カテゴリID
 * @param string $context 表示コンテキスト（'store', 'creator_page', 'gallery', 'top'）
 */
function getServices($creatorId = null, $status = 'active', $categoryId = null, $context = 'store') {
    $db = getDB();
    try {
        // show_in_* カラムが存在するか確認
        $hasDisplaySettings = false;
        try {
            $columns = $db->query("SHOW COLUMNS FROM services")->fetchAll(PDO::FETCH_COLUMN);
            $hasDisplaySettings = in_array('show_in_store', $columns);
        } catch (PDOException $e) {
            $hasDisplaySettings = false;
        }
        
        $sql = "SELECT s.*, c.name as creator_name, c.image as creator_image, c.slug as creator_slug,
                       s.category as category_name,
                       s.rating_avg as avg_rating, s.rating_count as review_count
                FROM services s
                LEFT JOIN creators c ON s.creator_id = c.id
                WHERE 1=1";
        
        $params = [];
        
        if ($status) {
            $sql .= " AND s.status = ?";
            $params[] = $status;
        }
        
        if ($creatorId) {
            $sql .= " AND s.creator_id = ?";
            $params[] = $creatorId;
        }
        
        if ($categoryId) {
            $sql .= " AND s.category_id = ?";
            $params[] = $categoryId;
        }
        
        // 表示場所フィルタ
        if ($hasDisplaySettings && $status === 'active') {
            switch ($context) {
                case 'store':
                    $sql .= " AND (s.show_in_store = 1 OR s.show_in_store IS NULL)";
                    break;
                case 'creator_page':
                    $sql .= " AND (s.show_in_creator_page = 1 OR s.show_in_creator_page IS NULL)";
                    break;
                case 'gallery':
                    $sql .= " AND s.show_in_gallery = 1";
                    break;
                case 'top':
                    $sql .= " AND s.show_in_top = 1";
                    break;
            }
        }
        
        $sql .= " ORDER BY s.is_featured DESC, s.sort_order ASC, s.id DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * サービスを1件取得（IDで）
 */
function getService($id) {
    $db = getDB();
    try {
        $stmt = $db->prepare("
            SELECT s.*, c.name as creator_name, c.image as creator_image, c.slug as creator_slug, c.email as creator_email,
                   s.category as category_name,
                   s.rating_avg as avg_rating, s.rating_count as review_count
            FROM services s
            LEFT JOIN creators c ON s.creator_id = c.id
            WHERE s.id = ?
        ");
        $stmt->execute([$id]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * サービスを1件取得（スラッグで）
 */
function getServiceBySlug($slug) {
    $db = getDB();
    try {
        $stmt = $db->prepare("
            SELECT s.*, c.name as creator_name, c.image as creator_image, c.slug as creator_slug, c.email as creator_email,
                   s.category as category_name,
                   s.rating_avg as avg_rating, s.rating_count as review_count
            FROM services s
            LEFT JOIN creators c ON s.creator_id = c.id
            WHERE s.slug = ?
        ");
        $stmt->execute([$slug]);
        return $stmt->fetch();
    } catch (PDOException $e) {
        return null;
    }
}

/**
 * サービスのプラン一覧を取得
 */
function getServicePlans($serviceId) {
    $db = getDB();
    try {
        $stmt = $db->prepare("SELECT * FROM service_plans WHERE service_id = ? AND is_active = 1 ORDER BY sort_order ASC, price ASC");
        $stmt->execute([$serviceId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * サービスのオプション一覧を取得
 */
function getServiceOptions($serviceId) {
    $db = getDB();
    try {
        $stmt = $db->prepare("SELECT * FROM service_options WHERE service_id = ? AND is_active = 1 ORDER BY sort_order ASC");
        $stmt->execute([$serviceId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * サービスの画像一覧を取得
 */
function getServiceImages($serviceId) {
    $db = getDB();
    try {
        $stmt = $db->prepare("SELECT * FROM service_images WHERE service_id = ? ORDER BY sort_order ASC");
        $stmt->execute([$serviceId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * サービスのレビュー一覧を取得
 */
function getServiceReviews($serviceId, $limit = 10) {
    $db = getDB();
    try {
        $stmt = $db->prepare("
            SELECT r.*, m.name as member_name
            FROM service_reviews r
            LEFT JOIN store_members m ON r.member_id = m.id
            WHERE r.service_id = ? AND r.is_published = 1
            ORDER BY r.created_at DESC
            LIMIT ?
        ");
        $stmt->execute([$serviceId, $limit]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * クリエイターのサービス一覧を取得
 * @param int $creatorId クリエイターID
 * @param bool $includeInactive 非公開も含めるか
 * @param string $context 表示コンテキスト（'store', 'creator_page', 'gallery', 'top'）
 */
function getCreatorServices($creatorId, $includeInactive = false, $context = 'creator_page') {
    $db = getDB();
    try {
        // show_in_* カラムが存在するか確認
        $hasDisplaySettings = false;
        try {
            $columns = $db->query("SHOW COLUMNS FROM services")->fetchAll(PDO::FETCH_COLUMN);
            $hasDisplaySettings = in_array('show_in_creator_page', $columns);
        } catch (PDOException $e) {
            $hasDisplaySettings = false;
        }
        
        $sql = "SELECT s.*, s.category as category_name,
                       s.rating_avg as avg_rating, s.rating_count as review_count,
                       (SELECT MIN(price) FROM service_plans WHERE service_id = s.id AND is_active = 1) as min_price
                FROM services s
                WHERE s.creator_id = ?";
        
        if (!$includeInactive) {
            $sql .= " AND s.status = 'active'";
        }
        
        // 表示場所フィルタ
        if ($hasDisplaySettings && !$includeInactive) {
            switch ($context) {
                case 'store':
                    $sql .= " AND (s.show_in_store = 1 OR s.show_in_store IS NULL)";
                    break;
                case 'creator_page':
                    $sql .= " AND (s.show_in_creator_page = 1 OR s.show_in_creator_page IS NULL)";
                    break;
                case 'gallery':
                    $sql .= " AND s.show_in_gallery = 1";
                    break;
                case 'top':
                    $sql .= " AND s.show_in_top = 1";
                    break;
            }
        }
        
        $sql .= " ORDER BY s.sort_order ASC, s.id DESC";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$creatorId]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * カテゴリ別サービス一覧を取得
 * @param string $category カテゴリ
 * @param int|null $limit 取得件数
 * @param string $context 表示コンテキスト（'store', 'creator_page', 'gallery', 'top'）
 */
function getServicesByCategory($category, $limit = null, $context = 'store') {
    $db = getDB();
    try {
        // show_in_* カラムが存在するか確認
        $hasDisplaySettings = false;
        try {
            $columns = $db->query("SHOW COLUMNS FROM services")->fetchAll(PDO::FETCH_COLUMN);
            $hasDisplaySettings = in_array('show_in_store', $columns);
        } catch (PDOException $e) {
            $hasDisplaySettings = false;
        }
        
        $sql = "SELECT s.*, c.name as creator_name, c.image as creator_image, c.slug as creator_slug,
                       s.category as category_name,
                       s.rating_avg as avg_rating, s.rating_count as review_count,
                       (SELECT MIN(price) FROM service_plans WHERE service_id = s.id AND is_active = 1) as min_price
                FROM services s
                LEFT JOIN creators c ON s.creator_id = c.id
                WHERE s.status = 'active' AND s.category = ?";
        
        // 表示場所フィルタ
        if ($hasDisplaySettings) {
            switch ($context) {
                case 'store':
                    $sql .= " AND (s.show_in_store = 1 OR s.show_in_store IS NULL)";
                    break;
                case 'gallery':
                    $sql .= " AND s.show_in_gallery = 1";
                    break;
                case 'top':
                    $sql .= " AND s.show_in_top = 1";
                    break;
            }
        }
        
        $sql .= " ORDER BY s.is_featured DESC, s.order_count DESC";
        
        if ($limit) {
            $sql .= " LIMIT " . (int)$limit;
        }
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$category]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * おすすめサービスを取得
 * @param int $limit 取得件数
 * @param string $context 表示コンテキスト（'store', 'top'）
 */
function getFeaturedServices($limit = 8, $context = 'store') {
    $db = getDB();
    try {
        // show_in_* カラムが存在するか確認
        $hasDisplaySettings = false;
        try {
            $columns = $db->query("SHOW COLUMNS FROM services")->fetchAll(PDO::FETCH_COLUMN);
            $hasDisplaySettings = in_array('show_in_store', $columns);
        } catch (PDOException $e) {
            $hasDisplaySettings = false;
        }
        
        $sql = "SELECT s.*, c.name as creator_name, c.image as creator_image, c.slug as creator_slug,
                   s.category as category_name,
                   s.rating_avg as avg_rating, s.rating_count as review_count,
                   (SELECT MIN(price) FROM service_plans WHERE service_id = s.id AND is_active = 1) as min_price
            FROM services s
            LEFT JOIN creators c ON s.creator_id = c.id
            WHERE s.status = 'active' AND s.is_featured = 1";
        
        // 表示場所フィルタ
        if ($hasDisplaySettings) {
            switch ($context) {
                case 'store':
                    $sql .= " AND (s.show_in_store = 1 OR s.show_in_store IS NULL)";
                    break;
                case 'top':
                    $sql .= " AND s.show_in_top = 1";
                    break;
            }
        }
        
        $sql .= " ORDER BY s.sort_order ASC, s.order_count DESC LIMIT ?";
        
        $stmt = $db->prepare($sql);
        $stmt->execute([$limit]);
        return $stmt->fetchAll();
    } catch (PDOException $e) {
        return [];
    }
}

/**
 * サービスの閲覧数をインクリメント
 */
function incrementServiceViewCount($serviceId) {
    $db = getDB();
    try {
        $stmt = $db->prepare("UPDATE services SET view_count = view_count + 1 WHERE id = ?");
        $stmt->execute([$serviceId]);
    } catch (PDOException $e) {
        // エラーは無視
    }
}

/**
 * サービス注文を作成
 */
function createServiceOrder($data) {
    $db = getDB();
    
    // 注文番号生成
    $orderNumber = 'SV' . date('Ymd') . strtoupper(substr(uniqid(), -6));
    
    $stmt = $db->prepare("
        INSERT INTO service_orders (
            order_number, service_id, plan_id, member_id, creator_id,
            buyer_name, buyer_email, base_price, options_price, total_price,
            platform_fee, creator_earning, delivery_days, expected_delivery,
            requirements, status, payment_status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending', 'pending')
    ");
    
    $stmt->execute([
        $orderNumber,
        $data['service_id'],
        $data['plan_id'] ?? null,
        $data['member_id'] ?? null,
        $data['creator_id'],
        $data['buyer_name'],
        $data['buyer_email'],
        $data['base_price'],
        $data['options_price'] ?? 0,
        $data['total_price'],
        $data['platform_fee'] ?? 0,
        $data['creator_earning'] ?? $data['total_price'],
        $data['delivery_days'],
        $data['expected_delivery'] ?? null,
        $data['requirements'] ?? null
    ]);
    
    return [
        'id' => $db->lastInsertId(),
        'order_number' => $orderNumber
    ];
}

/**
 * 審査待ちコンテンツ件数を取得
 */
function getPendingApprovalCounts() {
    $db = getDB();
    $counts = [
        'services' => 0,
        'products' => 0,
        'works' => 0,
        'total' => 0,
    ];
    
    try {
        $counts['services'] = $db->query("SELECT COUNT(*) FROM services WHERE approval_status = 'pending'")->fetchColumn();
    } catch (PDOException $e) {}
    
    try {
        $counts['products'] = $db->query("SELECT COUNT(*) FROM products WHERE approval_status = 'pending'")->fetchColumn();
    } catch (PDOException $e) {}
    
    try {
        $counts['works'] = $db->query("SELECT COUNT(*) FROM works WHERE approval_status = 'pending'")->fetchColumn();
    } catch (PDOException $e) {}
    
    $counts['total'] = $counts['services'] + $counts['products'] + $counts['works'];
    
    return $counts;
}

/**
 * 審査ステータスラベルを取得
 */
function getApprovalStatusLabels() {
    return [
        'draft' => ['label' => '下書き', 'class' => 'bg-gray-100 text-gray-600', 'icon' => 'fa-edit'],
        'pending' => ['label' => '審査中', 'class' => 'bg-yellow-100 text-yellow-700', 'icon' => 'fa-clock'],
        'approved' => ['label' => '承認済', 'class' => 'bg-green-100 text-green-700', 'icon' => 'fa-check'],
        'rejected' => ['label' => '要修正', 'class' => 'bg-red-100 text-red-700', 'icon' => 'fa-times'],
    ];
}
?>
