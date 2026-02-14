<?php
/**
 * ギャラリー表示用の共通レンダリング関数
 * index.phpとcreator.phpで共通化
 */

require_once __DIR__ . '/image-helper.php';

/**
 * カテゴリ順序を取得
 */
function getGalleryCategoryOrder($db = null) {
    if (!$db) $db = getDB();
    
    $defaultOrder = [
        ['id' => 'illustration', 'name' => 'イラスト', 'icon' => 'fa-palette', 'color' => 'pop-pink'],
        ['id' => 'manga', 'name' => 'マンガ', 'icon' => 'fa-book-open', 'color' => 'pop-purple'],
        ['id' => 'video', 'name' => '動画', 'icon' => 'fa-video', 'color' => 'red-500'],
        ['id' => 'animation', 'name' => 'アニメーション', 'icon' => 'fa-film', 'color' => 'pop-yellow'],
        ['id' => '3d', 'name' => '3D', 'icon' => 'fa-cube', 'color' => 'pop-blue'],
        ['id' => 'other', 'name' => 'その他', 'icon' => 'fa-shapes', 'color' => 'gray-500']
    ];
    
    $savedOrder = getSiteSetting($db, 'gallery_category_order', '');
    if ($savedOrder) {
        $savedIds = json_decode($savedOrder, true);
        if (is_array($savedIds)) {
            $orderedCategories = [];
            foreach ($savedIds as $catId) {
                foreach ($defaultOrder as $cat) {
                    if ($cat['id'] === $catId) {
                        $orderedCategories[] = $cat;
                        break;
                    }
                }
            }
            // 新しいカテゴリがあれば末尾に追加
            foreach ($defaultOrder as $cat) {
                $found = false;
                foreach ($orderedCategories as $oc) {
                    if ($oc['id'] === $cat['id']) { $found = true; break; }
                }
                if (!$found) $orderedCategories[] = $cat;
            }
            return $orderedCategories;
        }
    }
    
    return $defaultOrder;
}

/**
 * ギャラリー用の作品を取得（カテゴリ別）
 * @param int|null $creatorId 特定クリエイターに絞る場合
 * @param PDO|null $db データベース接続
 * @param string $context 表示コンテキスト（'gallery', 'creator_page', 'top'）
 */
function getGalleryWorksByCategory($creatorId = null, $db = null, $context = 'gallery') {
    if (!$db) $db = getDB();
    
    $categories = getGalleryCategoryOrder($db);
    $worksByCategory = [];
    
    // show_in_* カラムが存在するか確認
    $hasDisplaySettings = false;
    try {
        $columns = $db->query("SHOW COLUMNS FROM works")->fetchAll(PDO::FETCH_COLUMN);
        $hasDisplaySettings = in_array('show_in_gallery', $columns);
    } catch (PDOException $e) {
        $hasDisplaySettings = false;
    }
    
    foreach ($categories as $cat) {
        $catId = $cat['id'];
        
        $baseWhere = "w.is_active = 1 
            AND (w.collection_id IS NULL OR w.collection_id = 0)
            AND (w.work_type IS NULL OR w.work_type != 'line_stamp')
            AND (w.is_omake_sticker IS NULL OR w.is_omake_sticker = 0)";
        
        // 表示場所フィルタ
        if ($hasDisplaySettings) {
            switch ($context) {
                case 'gallery':
                    $baseWhere .= " AND (w.show_in_gallery = 1 OR w.show_in_gallery IS NULL)";
                    break;
                case 'creator_page':
                    $baseWhere .= " AND (w.show_in_creator_page = 1 OR w.show_in_creator_page IS NULL)";
                    break;
                case 'top':
                    $baseWhere .= " AND (w.show_in_top = 1 OR w.show_in_top IS NULL)";
                    break;
            }
        }
        
        if ($creatorId) {
            $baseWhere .= " AND w.creator_id = " . intval($creatorId);
        }
        
        if ($catId === 'other') {
            $sql = "SELECT w.*, c.name as creator_name 
                    FROM works w 
                    LEFT JOIN creators c ON w.creator_id = c.id 
                    WHERE {$baseWhere}
                      AND (w.category IN ('music', 'live2d', 'logo', 'web', 'other') 
                           OR w.category IS NULL OR w.category = '')
                    ORDER BY w.sort_order ASC, w.id DESC";
            $stmt = $db->query($sql);
        } else {
            $sql = "SELECT w.*, c.name as creator_name 
                    FROM works w 
                    LEFT JOIN creators c ON w.creator_id = c.id 
                    WHERE {$baseWhere} AND w.category = ?
                    ORDER BY w.sort_order ASC, w.id DESC";
            $stmt = $db->prepare($sql);
            $stmt->execute([$catId]);
        }
        
        $worksByCategory[$catId] = $stmt->fetchAll();
    }
    
    return $worksByCategory;
}

/**
 * ギャラリー用のコレクションを取得
 * @param int|null $creatorId 特定クリエイターに絞る場合
 */
function getGalleryCollections($creatorId = null, $db = null) {
    if (!$db) $db = getDB();
    
    $sql = "SELECT col.*, c.name as creator_name,
                   (SELECT COUNT(*) FROM works WHERE collection_id = col.id AND is_active = 1) as sticker_count
            FROM collections col 
            LEFT JOIN creators c ON col.creator_id = c.id 
            WHERE col.is_active = 1";
    
    if ($creatorId) {
        $sql .= " AND col.creator_id = " . intval($creatorId);
    }
    
    $sql .= " ORDER BY col.sort_order ASC, col.id DESC";
    
    $collections = $db->query($sql)->fetchAll();
    
    // 各コレクションに詳細情報を追加
    foreach ($collections as &$col) {
        $colData = getCollectionWithImages($col['id']);
        if ($colData) {
            $col = array_merge($col, $colData);
        }
    }
    
    return $collections;
}

/**
 * 画像パスを正規化
 */
function normalizeGalleryImagePath($img) {
    if (empty($img)) return '';
    if (strpos($img, 'http') === 0) return $img;
    if (strpos($img, '/') === 0) return $img;
    return '/' . $img;
}

/**
 * カテゴリセクションをレンダリング
 * @param array $cat カテゴリ情報
 * @param array $works 作品リスト
 * @param array $collections コレクションリスト（イラストカテゴリ用）
 * @param array $creators クリエイター一覧（名前表示用）
 * @param bool $showCreatorName クリエイター名を表示するか
 */
function renderGalleryCategorySection($cat, $works, $collections = [], $creators = [], $showCreatorName = true) {
    $catId = $cat['id'];
    $isIllustration = ($catId === 'illustration');
    
    // イラストカテゴリの場合のみコレクションを含める
    $catCollections = $isIllustration ? $collections : [];
    
    $totalCount = count($works);
    foreach ($catCollections as $col) {
        $totalCount += ($col['sticker_count'] ?? 0);
    }
    
    if ($totalCount === 0) return '';
    
    // グリッドとアスペクト比の設定
    if ($catId === 'manga') {
        $gridClass = 'grid grid-cols-3 sm:grid-cols-4 md:grid-cols-5 lg:grid-cols-6 gap-3 md:gap-4';
        $aspectClass = 'aspect-[3/4]';
        $itemClass = 'rounded-lg';
    } elseif ($catId === 'video' || $catId === 'animation') {
        $gridClass = 'grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 md:gap-6';
        $aspectClass = 'aspect-video';
        $itemClass = 'rounded-xl';
    } else {
        $gridClass = 'grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-4 md:gap-6';
        $aspectClass = 'aspect-square';
        $itemClass = 'rounded-2xl';
    }
    
    ob_start();
    ?>
    <div class="gallery-category mb-16" data-category="<?= $catId ?>">
        <div class="flex items-center justify-between mb-6">
            <div class="flex items-center gap-3">
                <div class="w-10 h-10 rounded-full bg-<?= $cat['color'] ?> flex items-center justify-center">
                    <i class="fas <?= $cat['icon'] ?> text-white"></i>
                </div>
                <h3 class="font-display text-2xl md:text-3xl text-gray-800"><?= htmlspecialchars($cat['name']) ?></h3>
                <span class="text-sm text-gray-400 font-normal">(<?= $totalCount ?>)</span>
            </div>
        </div>
        <div class="<?= $gridClass ?>">
            <?php 
            // 作品とコレクションを統合してsort_order順に並べる
            $allItems = [];
            foreach ($works as $work) {
                $allItems[] = ['type' => 'work', 'data' => $work, 'sort_order' => $work['sort_order'] ?? 999];
            }
            foreach ($catCollections as $col) {
                $allItems[] = ['type' => 'collection', 'data' => $col, 'sort_order' => $col['sort_order'] ?? 999];
            }
            usort($allItems, function($a, $b) {
                return ($a['sort_order'] ?? 999) - ($b['sort_order'] ?? 999);
            });
            
            foreach ($allItems as $item):
                if ($item['type'] === 'work'):
                    $work = $item['data'];
                    echo renderGalleryWorkItem($work, $aspectClass, $itemClass, $creators, $showCreatorName);
                else:
                    $col = $item['data'];
                    echo renderGalleryCollectionItem($col, $aspectClass, $itemClass);
                endif;
            endforeach;
            ?>
        </div>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * 作品アイテムをレンダリング
 */
function renderGalleryWorkItem($work, $aspectClass, $itemClass, $creators = [], $showCreatorName = true) {
    $imgSrc = normalizeGalleryImagePath($work['image'] ?? '');
    $cropPosition = $work['crop_position'] ?? 'center';
    $title = htmlspecialchars($work['title'] ?? '');
    
    // クリエイター名
    $creatorName = '';
    if ($showCreatorName) {
        if (!empty($work['creator_name'])) {
            $creatorName = $work['creator_name'];
        } elseif (!empty($creators)) {
            foreach ($creators as $c) {
                if ($c['id'] == $work['creator_id']) {
                    $creatorName = $c['name'];
                    break;
                }
            }
        }
    }
    
    // バッジ
    $badge = '';
    if (!empty($work['is_manga']) && !empty($work['pages']) && count($work['pages']) > 0) {
        $badge = '<div class="absolute top-2 left-2 bg-pop-purple text-white text-xs px-2 py-1 rounded font-bold">' . count($work['pages']) . 'P</div>';
    } elseif (!empty($work['youtube_url'])) {
        $badge = '<div class="absolute top-2 right-2 bg-red-500 text-white text-xs px-2 py-1 rounded"><i class="fab fa-youtube"></i></div>';
    }
    
    ob_start();
    ?>
    <div class="<?= $itemClass ?> overflow-hidden relative group cursor-pointer shadow-card hover:-translate-y-1 transition-all" onclick="openWorkModal(<?= $work['id'] ?>)">
        <div class="<?= $aspectClass ?>">
            <?php if ($imgSrc): ?>
            <?= renderMediaTag($imgSrc, 'w-full h-full object-cover', $title, 'object-position: ' . htmlspecialchars($cropPosition)) ?>
            <?php else: ?>
            <div class="w-full h-full bg-gray-200 flex items-center justify-center"><i class="fas fa-image text-gray-400 text-3xl"></i></div>
            <?php endif; ?>
        </div>
        <div class="absolute inset-0 bg-gradient-to-t from-black/70 via-black/20 to-transparent opacity-0 group-hover:opacity-100 transition-opacity flex flex-col justify-end p-3 md:p-4">
            <span class="text-white font-bold text-xs md:text-sm mb-1 truncate"><?= $title ?></span>
            <?php if ($showCreatorName && $creatorName): ?>
            <span class="text-white/80 text-xs truncate"><i class="fas fa-user mr-1"></i><?= htmlspecialchars($creatorName) ?></span>
            <?php endif; ?>
        </div>
        <?= $badge ?>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * コレクションアイテムをレンダリング
 */
function renderGalleryCollectionItem($col, $aspectClass, $itemClass) {
    // スタック用画像を取得
    $stackImages = [];
    $rawStackImages = $col['stack_images'] ?? [];
    if (!is_array($rawStackImages)) $rawStackImages = [];
    foreach ($rawStackImages as $s) {
        if (!empty($s['image'])) {
            $stackImages[] = $s;
            if (count($stackImages) >= 4) break;
        }
    }
    
    // 代表画像
    $representativeImage = '';
    if (!empty($col['representative']['image'])) {
        $representativeImage = $col['representative']['image'];
    } elseif (!empty($stackImages[0]['image'])) {
        $representativeImage = $stackImages[0]['image'];
    }
    
    $stickerCount = $col['sticker_count'] ?? 0;
    $displayStyle = $col['display_style'] ?? 'stack';
    $title = htmlspecialchars($col['title'] ?? 'コレクション');
    
    // LINEスタンプチェック
    $hasLineStamp = false;
    $stickers = $col['stickers'] ?? [];
    if (!is_array($stickers)) $stickers = [];
    foreach ($stickers as $s) {
        if (($s['work_type'] ?? '') === 'line_stamp') {
            $hasLineStamp = true;
            break;
        }
    }
    
    $badgeClass = $hasLineStamp ? 'bg-[#06C755]' : 'bg-[#0ea5e9]';
    $badgeIcon = $hasLineStamp ? '<i class="fab fa-line"></i>' : '<i class="fas fa-layer-group"></i>';
    
    ob_start();
    ?>
    <div class="<?= $itemClass ?> relative group cursor-pointer collection-card-wrapper" onclick="openStickerGroupModalWithAnimation(this, <?= $col['id'] ?>)">
        <div class="<?= $aspectClass ?> relative collection-card">
            <?php if ($displayStyle === 'grid'): ?>
            <?php 
            $gridImages = array_slice($stackImages, 0, 4);
            while (count($gridImages) < 4 && $representativeImage) {
                $gridImages[] = ['image' => $representativeImage];
            }
            ?>
            <div class="collection-grid w-full h-full">
                <?php foreach ($gridImages as $s): ?>
                <div class="grid-item">
                    <?= renderMediaTag(normalizeGalleryImagePath($s['image']), '', '') ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php elseif ($displayStyle === 'album'): ?>
            <?php 
            $papers = array_slice($stackImages, 0, 3);
            while (count($papers) < 3 && $representativeImage) {
                $papers[] = ['image' => $representativeImage];
            }
            ?>
            <div class="collection-album w-full h-full flex items-center justify-center">
                <div class="album-folder w-[85%]">
                    <div class="album-papers">
                        <?php foreach ($papers as $s): ?>
                        <div class="paper">
                            <?= renderMediaTag(normalizeGalleryImagePath($s['image']), '', '') ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            <?php else: ?>
            <?php 
            // スタック型
            $layers = [];
            if (!empty($stackImages[3])) $layers[] = ['img' => $stackImages[3]['image'], 'layer' => 4];
            if (!empty($stackImages[2])) $layers[] = ['img' => $stackImages[2]['image'], 'layer' => 3];
            if (!empty($stackImages[1])) $layers[] = ['img' => $stackImages[1]['image'], 'layer' => 2];
            $layers[] = ['img' => $representativeImage, 'layer' => 1];
            $layerCount = count($layers);
            $stackClass = $hasLineStamp ? 'sticker-stack line-stamp-stack' : 'sticker-stack';
            ?>
            <div class="<?= $stackClass ?> absolute inset-0" data-count="<?= $layerCount ?>">
                <?php foreach ($layers as $idx => $l): ?>
                <div class="sticker-layer absolute" style="z-index: <?= $idx + 1 ?>;" data-layer="<?= $l['layer'] ?>">
                    <?= renderMediaTag(normalizeGalleryImagePath($l['img']), 'w-full h-full object-contain', '') ?>
                </div>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
            <div class="absolute top-2 right-2 <?= $badgeClass ?> text-white text-xs px-2 py-1 rounded-full font-bold flex items-center gap-1 z-20 shadow-lg">
                <?= $badgeIcon ?>
                <span><?= $stickerCount ?>枚</span>
            </div>
        </div>
        <p class="mt-2 font-bold text-gray-700 text-xs md:text-sm truncate"><?= $title ?></p>
    </div>
    <?php
    return ob_get_clean();
}

/**
 * ギャラリー全体をレンダリング
 * @param int|null $creatorId 特定クリエイターに絞る場合
 * @param bool $showCreatorName クリエイター名を表示するか
 * @param bool $showCategoryHeader カテゴリヘッダーを表示するか
 * @param PDO|null $db データベース接続
 * @param string $context 表示コンテキスト（'gallery', 'creator_page', 'top'）
 */
function renderGallery($creatorId = null, $showCreatorName = true, $showCategoryHeader = true, $db = null, $context = 'gallery') {
    if (!$db) $db = getDB();
    
    // クリエイターページの場合は自動判定
    if ($creatorId && $context === 'gallery') {
        $context = 'creator_page';
    }
    
    $categories = getGalleryCategoryOrder($db);
    $worksByCategory = getGalleryWorksByCategory($creatorId, $db, $context);
    $collections = getGalleryCollections($creatorId, $db);
    $creators = $showCreatorName ? getCreators() : [];
    
    $html = '';
    foreach ($categories as $cat) {
        $catId = $cat['id'];
        $works = $worksByCategory[$catId] ?? [];
        $catCollections = ($catId === 'illustration') ? $collections : [];
        
        $html .= renderGalleryCategorySection($cat, $works, $catCollections, $creators, $showCreatorName);
    }
    
    return $html;
}
