<?php
/**
 * 配送先住所管理ページ
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/member-auth.php';
require_once '../includes/site-settings.php';

requireMemberAuth();

$member = getCurrentMember();
$db = getDB();
$message = '';
$error = '';
$editAddress = null;
$redirect = $_GET['redirect'] ?? '';

// 住所追加・編集処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['save_address'])) {
        $id = isset($_POST['address_id']) ? (int)$_POST['address_id'] : 0;
        $name = trim($_POST['name'] ?? '');
        $postalCode = trim($_POST['postal_code'] ?? '');
        $prefecture = trim($_POST['prefecture'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $address1 = trim($_POST['address1'] ?? '');
        $address2 = trim($_POST['address2'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $isDefault = isset($_POST['is_default']) ? 1 : 0;
        
        // バリデーション
        if (empty($name) || empty($postalCode) || empty($prefecture) || empty($city) || empty($address1) || empty($phone)) {
            $error = '必須項目を入力してください';
        } else {
            try {
                // デフォルト設定時は他のデフォルトを解除
                if ($isDefault) {
                    $stmt = $db->prepare("UPDATE member_addresses SET is_default = 0 WHERE member_id = ?");
                    $stmt->execute([$member['id']]);
                }
                
                if ($id) {
                    // 更新
                    $stmt = $db->prepare("UPDATE member_addresses SET name=?, postal_code=?, prefecture=?, city=?, address1=?, address2=?, phone=?, is_default=? WHERE id=? AND member_id=?");
                    $stmt->execute([$name, $postalCode, $prefecture, $city, $address1, $address2, $phone, $isDefault, $id, $member['id']]);
                    $message = '住所を更新しました';
                } else {
                    // 新規登録
                    // 最初の住所はデフォルトに
                    $stmt = $db->prepare("SELECT COUNT(*) FROM member_addresses WHERE member_id = ?");
                    $stmt->execute([$member['id']]);
                    if ($stmt->fetchColumn() == 0) {
                        $isDefault = 1;
                    }
                    
                    $stmt = $db->prepare("INSERT INTO member_addresses (member_id, name, postal_code, prefecture, city, address1, address2, phone, is_default) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
                    $stmt->execute([$member['id'], $name, $postalCode, $prefecture, $city, $address1, $address2, $phone, $isDefault]);
                    $message = '住所を追加しました';
                }
                
                // リダイレクト先がある場合
                if ($redirect === 'checkout') {
                    $_SESSION['selected_address_id'] = $id ?: $db->lastInsertId();
                    header('Location: /store/checkout.php');
                    exit;
                }
            } catch (PDOException $e) {
                $error = '保存に失敗しました';
            }
        }
    }
    
    if (isset($_POST['delete_address'])) {
        $id = (int)$_POST['address_id'];
        $stmt = $db->prepare("DELETE FROM member_addresses WHERE id = ? AND member_id = ?");
        $stmt->execute([$id, $member['id']]);
        $message = '住所を削除しました';
    }
    
    if (isset($_POST['set_default'])) {
        $id = (int)$_POST['address_id'];
        $db->prepare("UPDATE member_addresses SET is_default = 0 WHERE member_id = ?")->execute([$member['id']]);
        $db->prepare("UPDATE member_addresses SET is_default = 1 WHERE id = ? AND member_id = ?")->execute([$id, $member['id']]);
        $message = 'デフォルトに設定しました';
    }
}

// 編集対象を取得
if (isset($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM member_addresses WHERE id = ? AND member_id = ?");
    $stmt->execute([(int)$_GET['edit'], $member['id']]);
    $editAddress = $stmt->fetch(PDO::FETCH_ASSOC);
}

// 住所一覧を取得
$stmt = $db->prepare("SELECT * FROM member_addresses WHERE member_id = ? ORDER BY is_default DESC, id DESC");
$stmt->execute([$member['id']]);
$addresses = $stmt->fetchAll(PDO::FETCH_ASSOC);

$settings = getSiteSettings();
$siteName = $settings['site_name'] ?? 'CREATORS PLAYGROUND';

$prefectures = [
    '北海道', '青森県', '岩手県', '宮城県', '秋田県', '山形県', '福島県',
    '茨城県', '栃木県', '群馬県', '埼玉県', '千葉県', '東京都', '神奈川県',
    '新潟県', '富山県', '石川県', '福井県', '山梨県', '長野県', '岐阜県',
    '静岡県', '愛知県', '三重県', '滋賀県', '京都府', '大阪府', '兵庫県',
    '奈良県', '和歌山県', '鳥取県', '島根県', '岡山県', '広島県', '山口県',
    '徳島県', '香川県', '愛媛県', '高知県', '福岡県', '佐賀県', '長崎県',
    '熊本県', '大分県', '宮崎県', '鹿児島県', '沖縄県'
];
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <?php include 'includes/pwa-meta.php'; ?>
    <title>配送先住所 - <?= htmlspecialchars($siteName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <script src="https://yubinbango.github.io/yubinbango/yubinbango.js" charset="UTF-8"></script>
    <script>
        tailwind.config = {
            theme: {
                extend: {
                    colors: {
                        'pop-blue': '#4A90D9',
                        'pop-pink': '#FF6B9D',
                        'pop-yellow': '#FFD93D',
                        'pop-purple': '#9B6DD8',
                    }
                }
            }
        }
    </script>
</head>
<body class="bg-gray-50 min-h-screen">
    <header class="bg-white shadow-sm sticky top-0 z-50">
        <div class="max-w-4xl mx-auto px-4 py-4 flex items-center justify-between">
            <a href="/" class="text-xl font-bold text-gray-800"><?= htmlspecialchars($siteName) ?></a>
            <a href="/store/mypage.php" class="text-gray-600 hover:text-pop-blue"><i class="fas fa-user"></i></a>
        </div>
    </header>

    <main class="max-w-2xl mx-auto px-4 py-8">
        <nav class="text-sm text-gray-500 mb-6">
            <a href="/" class="hover:text-pop-blue">ホーム</a>
            <span class="mx-2">/</span>
            <a href="/store/mypage.php" class="hover:text-pop-blue">マイページ</a>
            <span class="mx-2">/</span>
            <span class="text-gray-800">配送先住所</span>
        </nav>
        
        <h1 class="text-2xl font-bold text-gray-800 mb-8">
            <i class="fas fa-map-marker-alt text-pop-blue mr-2"></i>配送先住所
        </h1>
        
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
        
        <!-- 住所追加/編集フォーム -->
        <?php if (isset($_GET['new']) || $editAddress): ?>
        <div class="bg-white rounded-xl shadow-sm p-6 mb-8">
            <h2 class="text-lg font-bold text-gray-800 mb-4">
                <?= $editAddress ? '住所を編集' : '新しい住所を追加' ?>
            </h2>
            <form method="POST" class="h-adr space-y-4">
                <span class="p-country-name" style="display:none;">Japan</span>
                <?php if ($editAddress): ?>
                <input type="hidden" name="address_id" value="<?= $editAddress['id'] ?>">
                <?php endif; ?>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">お名前 <span class="text-red-500">*</span></label>
                    <input type="text" name="name" value="<?= htmlspecialchars($editAddress['name'] ?? '') ?>" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pop-blue outline-none">
                </div>
                
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">郵便番号 <span class="text-red-500">*</span></label>
                        <input type="text" name="postal_code" value="<?= htmlspecialchars($editAddress['postal_code'] ?? '') ?>" 
                            placeholder="1234567" required
                            class="p-postal-code w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pop-blue outline-none">
                        <p class="text-xs text-gray-500 mt-1">ハイフンなしで入力</p>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-1">都道府県 <span class="text-red-500">*</span></label>
                        <select name="prefecture" required
                            class="p-region w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pop-blue outline-none">
                            <option value="">選択してください</option>
                            <?php foreach ($prefectures as $pref): ?>
                            <option value="<?= $pref ?>" <?= ($editAddress['prefecture'] ?? '') === $pref ? 'selected' : '' ?>><?= $pref ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">市区町村 <span class="text-red-500">*</span></label>
                    <input type="text" name="city" value="<?= htmlspecialchars($editAddress['city'] ?? '') ?>" required
                        class="p-locality w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pop-blue outline-none">
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">番地 <span class="text-red-500">*</span></label>
                    <input type="text" name="address1" value="<?= htmlspecialchars($editAddress['address1'] ?? '') ?>" required
                        class="p-street-address p-extended-address w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pop-blue outline-none">
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">建物名・部屋番号</label>
                    <input type="text" name="address2" value="<?= htmlspecialchars($editAddress['address2'] ?? '') ?>"
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pop-blue outline-none">
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">電話番号 <span class="text-red-500">*</span></label>
                    <input type="tel" name="phone" value="<?= htmlspecialchars($editAddress['phone'] ?? '') ?>" 
                        placeholder="09012345678" required
                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-pop-blue outline-none">
                </div>
                
                <div>
                    <label class="flex items-center gap-2 cursor-pointer">
                        <input type="checkbox" name="is_default" value="1" 
                            <?= ($editAddress['is_default'] ?? 0) ? 'checked' : '' ?>
                            class="w-5 h-5 rounded border-gray-300 text-pop-blue focus:ring-pop-blue">
                        <span class="text-sm text-gray-700">デフォルトの配送先に設定</span>
                    </label>
                </div>
                
                <div class="flex gap-3 pt-4">
                    <button type="submit" name="save_address" class="flex-1 bg-pop-blue hover:bg-blue-600 text-white py-3 rounded-lg font-bold transition-colors">
                        <i class="fas fa-save mr-2"></i>保存
                    </button>
                    <a href="/store/address.php<?= $redirect ? '?redirect=' . $redirect : '' ?>" class="px-6 py-3 bg-gray-200 hover:bg-gray-300 text-gray-700 rounded-lg font-bold transition-colors">
                        キャンセル
                    </a>
                </div>
            </form>
        </div>
        <?php else: ?>
        
        <!-- 住所一覧 -->
        <?php if (empty($addresses)): ?>
        <div class="bg-white rounded-xl shadow-sm p-12 text-center">
            <div class="w-16 h-16 bg-gray-100 rounded-full flex items-center justify-center mx-auto mb-4">
                <i class="fas fa-map-marker-alt text-3xl text-gray-300"></i>
            </div>
            <h2 class="text-lg font-bold text-gray-800 mb-2">配送先が登録されていません</h2>
            <p class="text-gray-500 mb-6">配送先住所を追加してください</p>
            <a href="?new=1<?= $redirect ? '&redirect=' . $redirect : '' ?>" class="inline-block px-6 py-3 bg-pop-blue text-white rounded-lg font-bold hover:bg-blue-600 transition-colors">
                <i class="fas fa-plus mr-2"></i>住所を追加
            </a>
        </div>
        <?php else: ?>
        <div class="space-y-4 mb-6">
            <?php foreach ($addresses as $addr): ?>
            <div class="bg-white rounded-xl shadow-sm p-6 <?= $addr['is_default'] ? 'ring-2 ring-pop-blue' : '' ?>">
                <div class="flex items-start justify-between mb-3">
                    <div class="flex items-center gap-2">
                        <span class="font-bold text-gray-800"><?= htmlspecialchars($addr['name']) ?></span>
                        <?php if ($addr['is_default']): ?>
                        <span class="text-xs bg-pop-blue text-white px-2 py-0.5 rounded">デフォルト</span>
                        <?php endif; ?>
                    </div>
                    <div class="flex items-center gap-2">
                        <a href="?edit=<?= $addr['id'] ?><?= $redirect ? '&redirect=' . $redirect : '' ?>" class="text-pop-blue hover:text-blue-700 text-sm">
                            <i class="fas fa-edit"></i> 編集
                        </a>
                    </div>
                </div>
                
                <div class="text-gray-600 text-sm space-y-1">
                    <p>〒<?= htmlspecialchars($addr['postal_code']) ?></p>
                    <p><?= htmlspecialchars($addr['prefecture'] . $addr['city'] . $addr['address1']) ?></p>
                    <?php if ($addr['address2']): ?>
                    <p><?= htmlspecialchars($addr['address2']) ?></p>
                    <?php endif; ?>
                    <p>TEL: <?= htmlspecialchars($addr['phone']) ?></p>
                </div>
                
                <div class="flex items-center gap-4 mt-4 pt-4 border-t border-gray-100">
                    <?php if (!$addr['is_default']): ?>
                    <form method="POST" class="inline">
                        <input type="hidden" name="address_id" value="<?= $addr['id'] ?>">
                        <button type="submit" name="set_default" class="text-sm text-pop-blue hover:underline">
                            デフォルトに設定
                        </button>
                    </form>
                    <?php endif; ?>
                    <form method="POST" class="inline" onsubmit="return confirm('この住所を削除しますか？')">
                        <input type="hidden" name="address_id" value="<?= $addr['id'] ?>">
                        <button type="submit" name="delete_address" class="text-sm text-red-500 hover:underline">
                            削除
                        </button>
                    </form>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        
        <a href="?new=1<?= $redirect ? '&redirect=' . $redirect : '' ?>" class="block w-full text-center py-4 border-2 border-dashed border-gray-300 rounded-xl text-gray-500 hover:border-pop-blue hover:text-pop-blue transition-colors">
            <i class="fas fa-plus mr-2"></i>新しい住所を追加
        </a>
        <?php endif; ?>
        <?php endif; ?>
        
        <?php if ($redirect === 'checkout'): ?>
        <div class="mt-6 text-center">
            <a href="/store/checkout.php" class="text-pop-blue hover:underline">
                <i class="fas fa-arrow-left mr-1"></i>購入手続きに戻る
            </a>
        </div>
        <?php endif; ?>
    </main>
</body>
</html>
