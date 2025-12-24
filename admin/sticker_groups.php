<?php
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/site-settings.php';
requireAuth();

$db = getDB();
$message = '';
$error = '';

// 削除処理
if (isset($_POST['delete_id'])) {
    try {
        $stmt = $db->prepare("UPDATE sticker_groups SET is_active = 0 WHERE id = ?");
        $stmt->execute([$_POST['delete_id']]);
        $message = 'ステッカーグループを削除しました';
    } catch (PDOException $e) {
        $error = '削除に失敗しました: ' . $e->getMessage();
    }
}

// 作品並び替え保存（Ajax）
if (isset($_POST['save_order']) && isset($_POST['work_orders'])) {
    header('Content-Type: application/json');
    try {
        $orders = json_decode($_POST['work_orders'], true);
        $stmt = $db->prepare("UPDATE works SET sticker_order = ? WHERE id = ?");
        foreach ($orders as $order => $workId) {
            $stmt->execute([$order, $workId]);
        }
        echo json_encode(['success' => true]);
    } catch (PDOException $e) {
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// 保存処理
if (isset($_POST['save'])) {
    try {
        $id = $_POST['id'] ?? null;
        $title = $_POST['title'];
        $description = $_POST['description'] ?? '';
        $creator_id = $_POST['creator_id'] ?: null;
        $category = $_POST['category'] ?? 'illustration';
        $representative_work_id = $_POST['representative_work_id'] ?: null;
        $representative_side = $_POST['representative_side'] ?? 'front';
        if (isset($_POST['sort_order'])) {
            $sort_order = (int)$_POST['sort_order'];
        } elseif ($id) {
            $stmt = $db->prepare("SELECT sort_order FROM sticker_groups WHERE id = ?");
            $stmt->execute([$id]);
            $sort_order = (int)$stmt->fetchColumn();
        } else {
            $sort_order = 0;
        }
        
        if ($id) {
            $stmt = $db->prepare("UPDATE sticker_groups SET title = ?, description = ?, creator_id = ?, category = ?, representative_work_id = ?, representative_side = ?, sort_order = ? WHERE id = ?");
            $stmt->execute([$title, $description, $creator_id, $category, $representative_work_id, $representative_side, $sort_order, $id]);
            $message = 'ステッカーグループを更新しました';
        } else {
            $stmt = $db->prepare("INSERT INTO sticker_groups (title, description, creator_id, category, representative_work_id, representative_side, sort_order) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([$title, $description, $creator_id, $category, $representative_work_id, $representative_side, $sort_order]);
            $message = 'ステッカーグループを作成しました';
        }
        header("Location: sticker_groups.php?saved=1");
        exit;
    } catch (PDOException $e) {
        $error = '保存に失敗しました: ' . $e->getMessage();
    }
}

if (isset($_GET['saved'])) {
    $message = 'ステッカーグループを保存しました。';
}

// 編集対象を取得
$editGroup = null;
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM sticker_groups WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editGroup = $stmt->fetch();
}

// グループ一覧を取得
$groups = $db->query("SELECT sg.*, c.name as creator_name, (SELECT COUNT(*) FROM works WHERE sticker_group_id = sg.id AND is_active = 1) as sticker_count FROM sticker_groups sg LEFT JOIN creators c ON sg.creator_id = c.id WHERE sg.is_active = 1 ORDER BY sg.sort_order ASC, sg.id DESC")->fetchAll();

// クリエイター一覧
$creators = $db->query("SELECT id, name FROM creators WHERE is_active = 1 ORDER BY sort_order ASC")->fetchAll();

// このグループに属する作品リスト（編集時）
$groupWorks = [];
if ($editGroup) {
    $stmt = $db->prepare("SELECT id, title, image, back_image FROM works WHERE sticker_group_id = ? AND is_active = 1 ORDER BY sticker_order ASC, id DESC");
    $stmt->execute([$editGroup['id']]);
    $groupWorks = $stmt->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php $pwaThemeColor = getSiteSetting($db, 'pwa_theme_color', '#ffffff'); ?>
    <meta name="theme-color" content="<?= htmlspecialchars($pwaThemeColor) ?>">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="ぷれぐら！管理">
    <meta name="mobile-web-app-capable" content="yes">

    <title>グループ管理 | 管理画面</title>
    <link rel="manifest" href="/admin/manifest.json">
    <?php $backyardFavicon = getBackyardFaviconInfo($db); ?>
    <link rel="icon" href="<?= htmlspecialchars($backyardFavicon['path']) ?>" type="<?= htmlspecialchars($backyardFavicon['type']) ?>">
    <link rel="apple-touch-icon" href="<?= htmlspecialchars($backyardFavicon['path']) ?>">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@400;500;700&display=swap" rel="stylesheet">
    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.0/Sortable.min.js"></script>
    <style>
        body { font-family: 'Zen Maru Gothic', sans-serif; }
        .sortable-ghost { opacity: 0.4; background: #fef3c7; }
        .sortable-chosen { box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
        .sortable-drag { opacity: 1; }
        .work-item { touch-action: none; }
    </style>
</head>
<body class="bg-gray-50">
    <?php include 'includes/sidebar.php'; ?>
    
    <main class="lg:ml-64 p-4 md:p-8 pt-20 lg:pt-8">
        <div class="flex justify-between items-center mb-6 md:mb-8">
            <div>
                <h2 class="text-xl md:text-2xl font-bold text-gray-800">グループ管理</h2>
                <p class="text-sm text-gray-500">ステッカーをまとめるグループの設定</p>
            </div>
            <?php if (!$editGroup && !isset($_GET['new'])): ?>
            <a href="sticker_groups.php?new=1" class="bg-yellow-400 hover:bg-yellow-500 text-gray-900 font-bold px-4 md:px-6 py-2 md:py-3 rounded-lg transition text-sm md:text-base">
                <i class="fas fa-plus mr-1 md:mr-2"></i><span class="hidden sm:inline">新規追加</span><span class="sm:hidden">追加</span>
            </a>
            <?php endif; ?>
        </div>

        <?php if ($message): ?>
        <div class="bg-green-50 border border-green-200 text-green-600 px-4 py-3 rounded-lg mb-6">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-lg mb-6">
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if ($editGroup || isset($_GET['new'])): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 md:p-6">
            <div class="flex justify-between items-center mb-6">
                <h3 class="font-bold text-gray-800 text-lg">
                    <?= $editGroup ? 'グループ編集' : '新規グループ作成' ?>
                </h3>
                <a href="sticker_groups.php" class="text-gray-500 hover:text-gray-700">
                    <i class="fas fa-times text-xl"></i>
                </a>
            </div>
            
            <form method="POST" class="space-y-6">
                <?php if ($editGroup): ?>
                <input type="hidden" name="id" value="<?= $editGroup['id'] ?>">
                <?php endif; ?>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">グループ名 *</label>
                        <input type="text" name="title" required 
                            value="<?= htmlspecialchars($editGroup['title'] ?? '') ?>"
                            class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">クリエイター</label>
                        <select name="creator_id" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                            <option value="">選択してください</option>
                            <?php foreach ($creators as $creator): ?>
                            <option value="<?= $creator['id'] ?>" <?= ($editGroup['creator_id'] ?? '') == $creator['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($creator['name']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">説明</label>
                    <textarea name="description" rows="3"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"><?= htmlspecialchars($editGroup['description'] ?? '') ?></textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-4 md:gap-6">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">カテゴリー</label>
                        <select name="category" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                            <option value="illustration" <?= ($editGroup['category'] ?? 'illustration') == 'illustration' ? 'selected' : '' ?>>イラスト</option>
                            <option value="manga" <?= ($editGroup['category'] ?? '') == 'manga' ? 'selected' : '' ?>>マンガ</option>
                            <option value="other" <?= ($editGroup['category'] ?? '') == 'other' ? 'selected' : '' ?>>その他</option>
                        </select>
                    </div>

                </div>

                <?php if ($editGroup): ?>
                <!-- 代表サムネイル選択セクション -->
                <div class="p-4 bg-purple-50 rounded-lg border border-purple-200">
                    <label class="block text-sm font-bold text-purple-800 mb-3">
                        <i class="fas fa-image mr-1"></i>代表サムネイル（グループ表示用）
                    </label>
                    <?php if (empty($groupWorks)): ?>
                        <p class="text-xs text-purple-600">
                            現在このグループに紐付いている作品がありません。<br>
                            「作品管理」から作品の「ステッカーグループ」を設定してください。
                        </p>
                    <?php else: ?>
                        <p class="text-xs text-purple-600 mb-3">
                            サムネイルに使用する画像を選択してください。ステッカーの表面または裏面を選べます。
                        </p>
                        
                        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 gap-3">
                            <!-- 自動選択オプション -->
                            <label class="cursor-pointer">
                                <input type="radio" name="representative_work_id" value="" 
                                    <?= empty($editGroup['representative_work_id']) ? 'checked' : '' ?>
                                    onchange="updateSide('front')"
                                    class="hidden peer">
                                <div class="border-2 border-dashed border-purple-300 rounded-lg p-3 text-center peer-checked:border-purple-500 peer-checked:bg-purple-100 hover:bg-purple-50 transition aspect-square flex flex-col items-center justify-center">
                                    <i class="fas fa-magic text-2xl text-purple-400 mb-2"></i>
                                    <span class="text-xs text-purple-600 font-medium">自動</span>
                                </div>
                            </label>
                            
                            <?php foreach ($groupWorks as $work): ?>
                                <?php 
                                $isSelected = ($editGroup['representative_work_id'] ?? '') == $work['id'];
                                $selectedSide = $editGroup['representative_side'] ?? 'front';
                                ?>
                                <!-- 表面 -->
                                <?php if (!empty($work['image'])): ?>
                                <label class="cursor-pointer group">
                                    <input type="radio" name="representative_work_id" value="<?= $work['id'] ?>" 
                                        <?= ($isSelected && $selectedSide === 'front') ? 'checked' : '' ?>
                                        onchange="updateSide('front')"
                                        class="hidden peer">
                                    <div class="relative border-2 border-gray-200 rounded-lg overflow-hidden peer-checked:border-purple-500 peer-checked:ring-2 peer-checked:ring-purple-300 hover:border-purple-300 transition">
                                        <img src="../<?= htmlspecialchars($work['image']) ?>" 
                                            alt="<?= htmlspecialchars($work['title']) ?>" 
                                            class="w-full aspect-square object-cover">
                                        <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-black/70 to-transparent p-2">
                                            <span class="text-white text-xs font-medium">表</span>
                                        </div>
                                        <div class="absolute top-1 right-1 w-5 h-5 bg-white rounded-full border-2 border-gray-300 peer-checked:bg-purple-500 peer-checked:border-purple-500 flex items-center justify-center">
                                            <i class="fas fa-check text-white text-xs hidden peer-checked:block"></i>
                                        </div>
                                    </div>
                                    <p class="text-xs text-gray-600 mt-1 truncate text-center"><?= htmlspecialchars($work['title']) ?></p>
                                </label>
                                <?php endif; ?>
                                
                                <!-- 裏面（存在する場合のみ） -->
                                <?php if (!empty($work['back_image'])): ?>
                                <label class="cursor-pointer group">
                                    <input type="radio" name="representative_work_id" value="<?= $work['id'] ?>_back" 
                                        <?= ($isSelected && $selectedSide === 'back') ? 'checked' : '' ?>
                                        onchange="updateSide('back')"
                                        class="hidden peer">
                                    <div class="relative border-2 border-gray-200 rounded-lg overflow-hidden peer-checked:border-purple-500 peer-checked:ring-2 peer-checked:ring-purple-300 hover:border-purple-300 transition">
                                        <img src="../<?= htmlspecialchars($work['back_image']) ?>" 
                                            alt="<?= htmlspecialchars($work['title']) ?> (裏)" 
                                            class="w-full aspect-square object-cover">
                                        <div class="absolute bottom-0 left-0 right-0 bg-gradient-to-t from-purple-900/70 to-transparent p-2">
                                            <span class="text-white text-xs font-medium">裏</span>
                                        </div>
                                        <div class="absolute top-1 right-1 w-5 h-5 bg-white rounded-full border-2 border-gray-300 peer-checked:bg-purple-500 peer-checked:border-purple-500 flex items-center justify-center">
                                            <i class="fas fa-check text-white text-xs hidden peer-checked:block"></i>
                                        </div>
                                    </div>
                                    <p class="text-xs text-gray-600 mt-1 truncate text-center"><?= htmlspecialchars($work['title']) ?> <span class="text-purple-500">(裏)</span></p>
                                </label>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </div>
                        
                        <input type="hidden" name="representative_side" id="representative_side" value="<?= htmlspecialchars($editGroup['representative_side'] ?? 'front') ?>">
                    <?php endif; ?>
                </div>

                <!-- 作品並び替えセクション -->
                <?php if (!empty($groupWorks)): ?>
                <div class="p-4 bg-blue-50 rounded-lg border border-blue-200">
                    <div class="flex justify-between items-center mb-3">
                        <label class="block text-sm font-bold text-blue-800">
                            <i class="fas fa-sort mr-1"></i>作品の並び替え
                        </label>
                        <span class="text-xs text-blue-600">ドラッグして並び替え</span>
                    </div>
                    <div id="sortable-works" class="space-y-2">
                        <?php foreach ($groupWorks as $index => $work): ?>
                        <div class="work-item flex items-center gap-2 sm:gap-3 bg-white p-2 sm:p-3 rounded-lg border border-blue-200 cursor-move" data-id="<?= $work['id'] ?>">
                            <i class="fas fa-grip-vertical text-gray-400"></i>
                            <span class="text-sm font-medium text-blue-800 w-5 sm:w-6"><?= $index + 1 ?></span>
                            
                            <!-- 表面サムネイル -->
                            <?php if (!empty($work['image'])): ?>
                            <div class="relative flex-shrink-0">
                                <img src="../<?= htmlspecialchars($work['image']) ?>" alt="" class="w-8 h-8 sm:w-10 sm:h-10 object-cover rounded">
                                <div class="absolute -bottom-1 -right-1 bg-gray-700 text-white px-1 rounded text-[8px] sm:text-[10px]">表</div>
                            </div>
                            <?php endif; ?>
                            
                            <!-- 裏面サムネイル -->
                            <?php if (!empty($work['back_image'])): ?>
                            <div class="relative flex-shrink-0">
                                <img src="../<?= htmlspecialchars($work['back_image']) ?>" alt="" class="w-8 h-8 sm:w-10 sm:h-10 object-cover rounded border-2 border-purple-300">
                                <div class="absolute -bottom-1 -right-1 bg-purple-600 text-white px-1 rounded text-[8px] sm:text-[10px]">裏</div>
                            </div>
                            <?php endif; ?>
                            
                            <span class="text-xs sm:text-sm text-gray-700 flex-1 truncate"><?= htmlspecialchars($work['title']) ?></span>
                            
                            <a href="works.php?edit=<?= $work['id'] ?>" class="text-gray-400 hover:text-yellow-600 transition flex-shrink-0" title="作品を編集">
                                <i class="fas fa-external-link-alt text-xs"></i>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div id="order-status" class="mt-3 text-xs text-blue-600 hidden">
                        <i class="fas fa-check-circle mr-1"></i>並び順を保存しました
                    </div>
                </div>
                <?php endif; ?>
                <?php endif; ?>

                <div class="pt-6 border-t flex flex-wrap gap-3 md:gap-4">
                    <button type="submit" name="save" class="bg-yellow-400 hover:bg-yellow-500 text-gray-900 font-bold px-6 md:px-8 py-3 rounded-lg transition">
                        <i class="fas fa-save mr-2"></i>保存
                    </button>
                    <a href="sticker_groups.php" class="bg-gray-200 hover:bg-gray-300 text-gray-700 px-4 md:px-6 py-3 rounded-lg transition">キャンセル</a>
                </div>
            </form>
        </div>

        <?php else: ?>
        
        <!-- PC用テーブル表示 -->
        <div class="hidden md:block bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider w-20">ID</th>
                        <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">グループ名</th>
                        <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">クリエイター</th>
                        <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider">作品数</th>
                        <th class="px-6 py-4 text-left text-xs font-bold text-gray-500 uppercase tracking-wider w-20">順序</th>
                        <th class="px-6 py-4 text-center text-xs font-bold text-gray-500 uppercase tracking-wider w-32">操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($groups as $group): ?>
                    <tr class="hover:bg-gray-50 transition">
                        <td class="px-6 py-4 text-sm text-gray-500"><?= $group['id'] ?></td>
                        <td class="px-6 py-4">
                            <div class="font-bold text-gray-800"><?= htmlspecialchars($group['title']) ?></div>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-600">
                            <?= htmlspecialchars($group['creator_name'] ?? '-') ?>
                        </td>
                        <td class="px-6 py-4">
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                                <?= $group['sticker_count'] ?> 作品
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm text-gray-600"><?= $group['sort_order'] ?></td>
                        <td class="px-6 py-4 text-center">
                            <div class="flex justify-center gap-3">
                                <a href="?edit=<?= $group['id'] ?>" class="text-yellow-600 hover:text-yellow-700" title="編集">
                                    <i class="fas fa-edit"></i>
                                </a>
                                <form method="POST" class="inline" onsubmit="return confirm('本当に削除しますか？')">
                                    <input type="hidden" name="delete_id" value="<?= $group['id'] ?>">
                                    <button type="submit" class="text-gray-400 hover:text-red-500 transition">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($groups)): ?>
                    <tr>
                        <td colspan="6" class="px-6 py-12 text-center text-gray-500">
                            <i class="fas fa-layer-group text-4xl text-gray-200 mb-3"></i>
                            <p>グループが登録されていません</p>
                        </td>
                    </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- スマホ用カード表示 -->
        <div class="md:hidden space-y-3">
            <?php foreach ($groups as $group): ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4">
                <div class="flex justify-between items-start mb-2">
                    <div class="flex-1">
                        <div class="font-bold text-gray-800"><?= htmlspecialchars($group['title']) ?></div>
                        <div class="text-xs text-gray-500 mt-1">
                            <?= htmlspecialchars($group['creator_name'] ?? '未設定') ?>
                        </div>
                    </div>
                    <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium bg-purple-100 text-purple-800">
                        <?= $group['sticker_count'] ?> 作品
                    </span>
                </div>
                <div class="flex justify-between items-center mt-3 pt-3 border-t border-gray-100">
                    <div class="text-xs text-gray-400">
                        ID: <?= $group['id'] ?> / 順序: <?= $group['sort_order'] ?>
                    </div>
                    <div class="flex gap-4">
                        <a href="?edit=<?= $group['id'] ?>" class="flex items-center gap-1 text-yellow-600 hover:text-yellow-700 font-medium text-sm">
                            <i class="fas fa-edit"></i>
                            <span>編集</span>
                        </a>
                        <form method="POST" class="inline" onsubmit="return confirm('本当に削除しますか？')">
                            <input type="hidden" name="delete_id" value="<?= $group['id'] ?>">
                            <button type="submit" class="flex items-center gap-1 text-gray-400 hover:text-red-500 transition text-sm">
                                <i class="fas fa-trash"></i>
                                <span>削除</span>
                            </button>
                        </form>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            
            <?php if (empty($groups)): ?>
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-8 text-center text-gray-500">
                <i class="fas fa-layer-group text-4xl text-gray-200 mb-3"></i>
                <p>グループが登録されていません</p>
            </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </main>

    <script>
    function updateSide(side) {
        const sideInput = document.getElementById('representative_side');
        if (sideInput) sideInput.value = side;
    }
    </script>

    <?php if ($editGroup && !empty($groupWorks)): ?>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const sortableEl = document.getElementById('sortable-works');
        if (!sortableEl) return;

        new Sortable(sortableEl, {
            animation: 150,
            ghostClass: 'sortable-ghost',
            chosenClass: 'sortable-chosen',
            dragClass: 'sortable-drag',
            handle: '.work-item',
            onEnd: function() {
                const items = sortableEl.querySelectorAll('.work-item');
                items.forEach((item, index) => {
                    const numEl = item.querySelector('.text-blue-800');
                    if (numEl) numEl.textContent = index + 1;
                });

                const orders = Array.from(items).map(item => item.dataset.id);
                
                fetch('sticker_groups.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                    body: 'save_order=1&work_orders=' + encodeURIComponent(JSON.stringify(orders))
                })
                .then(r => r.json())
                .then(data => {
                    const status = document.getElementById('order-status');
                    status.className = 'mt-3 text-xs ' + (data.success ? 'text-blue-600' : 'text-red-600');
                    status.innerHTML = data.success 
                        ? '<i class="fas fa-check-circle mr-1"></i>並び順を保存しました'
                        : '<i class="fas fa-exclamation-circle mr-1"></i>保存に失敗しました';
                    status.classList.remove('hidden');
                    setTimeout(() => status.classList.add('hidden'), 3000);
                });
            }
        });
    });
    </script>
    <?php endif; ?>
</body>
</html>
