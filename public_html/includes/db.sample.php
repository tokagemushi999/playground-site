<?php
/**
 * データベース接続設定
 * Xサーバー用
 */

define('DB_HOST', 'localhost');
define('DB_NAME', 'YOUR_DB_NAME');
define('DB_USER', 'YOUR_DB_USER');
define('DB_PASS', 'YOUR_DB_PASSWORD');
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
    
    return attachWorkPages($works);
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
 * ステッカーグループ一覧を取得
 */
function getStickerGroups($creatorId = null) {
    $db = getDB();
    $query = "SELECT sg.*, c.name as creator_name, 
              (SELECT COUNT(*) FROM works WHERE sticker_group_id = sg.id AND is_active = 1) as sticker_count
              FROM sticker_groups sg 
              LEFT JOIN creators c ON sg.creator_id = c.id 
              WHERE sg.is_active = 1";
    
    if ($creatorId) {
        $query .= " AND sg.creator_id = ?";
        $stmt = $db->prepare($query . " ORDER BY sg.sort_order ASC, sg.id DESC");
        $stmt->execute([$creatorId]);
    } else {
        $stmt = $db->query($query . " ORDER BY sg.sort_order ASC, sg.id DESC");
    }
    return $stmt->fetchAll();
}

/**
 * ステッカーグループを1件取得
 */
function getStickerGroup($id) {
    $db = getDB();
    $stmt = $db->prepare("SELECT sg.*, c.name as creator_name FROM sticker_groups sg LEFT JOIN creators c ON sg.creator_id = c.id WHERE sg.id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

/**
 * グループに属するステッカーを取得
 */
function getStickersByGroup($groupId) {
    $db = getDB();
    $stmt = $db->prepare("SELECT w.*, c.name as creator_name FROM works w LEFT JOIN creators c ON w.creator_id = c.id WHERE w.sticker_group_id = ? AND w.is_active = 1 ORDER BY w.sticker_order ASC, w.id ASC");
    $stmt->execute([$groupId]);
    return $stmt->fetchAll();
}

/**
 * グループの代表画像を含む表示用データを取得
 */
function getStickerGroupWithImages($groupId) {
    $db = getDB();
    $group = getStickerGroup($groupId);
    if (!$group) return null;
    
    $stickers = getStickersByGroup($groupId);
    $group['stickers'] = $stickers;
    $group['sticker_count'] = count($stickers);
    
    // 代表画像
    if ($group['representative_work_id']) {
        $stmt = $db->prepare("SELECT image, back_image FROM works WHERE id = ?");
        $stmt->execute([$group['representative_work_id']]);
        $group['representative'] = $stmt->fetch();
    }
    
    // 重なり表示用に最大3枚取得
    $group['stack_images'] = array_slice($stickers, 0, 3);
    
    return $group;
}

/**
 * 全ステッカーグループを表示用データとして取得
 */
function getAllStickerGroupsWithImages($creatorId = null) {
    $groups = getStickerGroups($creatorId);
    $result = [];
    
    foreach ($groups as $group) {
        $groupData = getStickerGroupWithImages($group['id']);
        if ($groupData) {
            $result[] = $groupData;
        }
    }
    
    return $result;
}

/**
 * ステッカー一覧を取得（グループに属さない単独ステッカー）
 */
function getStickers($creatorId = null) {
    $db = getDB();
    if ($creatorId) {
        $stmt = $db->prepare("SELECT w.*, c.name as creator_name FROM works w LEFT JOIN creators c ON w.creator_id = c.id WHERE w.is_active = 1 AND w.is_omake_sticker = 1 AND w.sticker_group_id IS NULL AND w.creator_id = ? ORDER BY w.sort_order ASC, w.id DESC");
        $stmt->execute([$creatorId]);
    } else {
        $stmt = $db->query("SELECT w.*, c.name as creator_name FROM works w LEFT JOIN creators c ON w.creator_id = c.id WHERE w.is_active = 1 AND w.is_omake_sticker = 1 AND w.sticker_group_id IS NULL ORDER BY w.sort_order ASC, w.id DESC");
    }
    return $stmt->fetchAll();
}
?>
