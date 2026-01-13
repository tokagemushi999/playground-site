<?php
/**
 * クリエイター管理
 */

require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/image-helper.php';
require_once '../includes/site-settings.php';
require_once '../includes/creator-auth.php';
require_once '../includes/mail.php';
requireAuth();

$db = getDB();
$message = '';
$error = '';

// 招待リンク発行処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_invite'])) {
    $creatorId = (int)$_POST['creator_id'];
    
    $stmt = $db->prepare("SELECT * FROM creators WHERE id = ?");
    $stmt->execute([$creatorId]);
    $inviteCreator = $stmt->fetch();
    
    if ($inviteCreator && !empty($inviteCreator['email'])) {
        $token = generatePasswordSetToken($db, $creatorId);
        $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') 
                 . '://' . $_SERVER['HTTP_HOST'];
        $inviteUrl = $baseUrl . '/creator-dashboard/set-password.php?token=' . $token;
        
        // メール送信
        $settings = getSiteSettings();
        $siteName = $settings['site_name'] ?? 'ぷれぐら！PLAYGROUND';
        
        $subject = "【{$siteName}】クリエイターダッシュボードへの招待";
        $body = "{$inviteCreator['name']} 様\n\n";
        $body .= "{$siteName}のクリエイターダッシュボードへご招待いたします。\n\n";
        $body .= "以下のリンクからパスワードを設定してください：\n";
        $body .= $inviteUrl . "\n\n";
        $body .= "※このリンクは7日間有効です。\n\n";
        $body .= "━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $body .= $siteName . "\n";
        $body .= $baseUrl . "\n";
        
        if (sendMail($inviteCreator['email'], $subject, $body)) {
            $message = "{$inviteCreator['name']}さんに招待メールを送信しました。";
        } else {
            $error = 'メール送信に失敗しました。';
        }
    } else {
        $error = 'メールアドレスが登録されていません。';
    }
}

// ログイン許可切り替え
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_login'])) {
    $creatorId = (int)$_POST['creator_id'];
    $enabled = (int)$_POST['login_enabled'];
    
    toggleCreatorLoginEnabled($db, $creatorId, $enabled);
    $message = $enabled ? 'ログインを許可しました。' : 'ログインを禁止しました。';
}

// 削除処理
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $stmt = $db->prepare("UPDATE creators SET is_active = 0 WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    $message = 'クリエイターを削除しました。';
}

// 保存処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['name'])) {
    $id = $_POST['id'] ?? null;
    $name = $_POST['name'] ?? '';
    $slug = $_POST['slug'] ?? '';
    $role = $_POST['role'] ?? '';
    $description = $_POST['description'] ?? '';
    $bio_long = $_POST['bio_long'] ?? '';
    
    // SNS
    $twitter = $_POST['twitter'] ?? '';
    $instagram = $_POST['instagram'] ?? '';
    $pixiv = $_POST['pixiv'] ?? '';
    $youtube = $_POST['youtube'] ?? '';
    $tiktok = $_POST['tiktok'] ?? '';
    $booth = $_POST['booth'] ?? '';
    $skeb = $_POST['skeb'] ?? '';
    $litlink = $_POST['litlink'] ?? '';
    $website = $_POST['website'] ?? '';
    $discord = $_POST['discord'] ?? '';
    
    // 連絡先
    $email = trim($_POST['email'] ?? '');
    
    // パスワード（新規設定または変更時のみ）
    $password = trim($_POST['creator_password'] ?? '');
    $hashedPassword = null;
    if (!empty($password)) {
        $hashedPassword = hashCreatorPassword($password);
    }
    
    // 銀行口座情報
    $bank_name = trim($_POST['bank_name'] ?? '');
    $bank_branch = trim($_POST['bank_branch'] ?? '');
    $bank_account_type = trim($_POST['bank_account_type'] ?? '');
    $bank_account_number = trim($_POST['bank_account_number'] ?? '');
    $bank_account_name = trim($_POST['bank_account_name'] ?? '');
    $commission_rate = isset($_POST['commission_rate']) ? (float)$_POST['commission_rate'] : 20.00;
    $commission_per_item = isset($_POST['commission_per_item']) ? (int)$_POST['commission_per_item'] : 0;
    $service_commission_rate = isset($_POST['service_commission_rate']) ? (float)$_POST['service_commission_rate'] : 15.00;
    $service_commission_per_item = isset($_POST['service_commission_per_item']) ? (int)$_POST['service_commission_per_item'] : 0;
    $business_type = $_POST['business_type'] ?? 'individual';
    $withholding_tax_required = isset($_POST['withholding_tax_required']) ? 1 : 0;
    $login_enabled = isset($_POST['login_enabled']) ? 1 : 0;
    
    // ページ設定（デフォルト値を維持）
    $page_layout = 'default';
    $theme_color = '#FFD93D';
    $gallery_style = 'grid';
    $show_works = 1;
    $show_articles = 1;
    $custom_css = '';
    
    if (isset($_POST['sort_order'])) {
        $sort_order = (int)$_POST['sort_order'];
    } elseif ($id) {
        $stmt = $db->prepare("SELECT sort_order FROM creators WHERE id = ?");
        $stmt->execute([$id]);
        $sort_order = (int)$stmt->fetchColumn();
    } else {
        $sort_order = 0;
    }
    
    // プロフィール画像アップロード（WebP変換）
    $image = $_POST['current_image'] ?? '';
    if (!empty($_FILES['image']['name'])) {
        $uploadDir = '../uploads/creators/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $baseName = uniqid('creator_');
        $result = ImageHelper::processUpload(
            $_FILES['image']['tmp_name'],
            $uploadDir,
            $baseName,
            ['maxWidth' => 800, 'maxHeight' => 800]
        );
        if ($result && isset($result['path'])) {
            $image = 'uploads/creators/' . basename($result['path']);
        }
    }
    
    // ヘッダー画像アップロード（WebP変換）
    $header_image = $_POST['current_header_image'] ?? '';
    if (!empty($_FILES['header_image']['name'])) {
        $uploadDir = '../uploads/creators/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $baseName = uniqid('header_');
        $result = ImageHelper::processUpload(
            $_FILES['header_image']['tmp_name'],
            $uploadDir,
            $baseName,
            ['maxWidth' => 1920, 'maxHeight' => 600]
        );
        if ($result && isset($result['path'])) {
            $header_image = 'uploads/creators/' . basename($result['path']);
        }
    }
    
    if (empty($slug)) {
        if (!empty($name)) {
            $baseSlug = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $name));
            $baseSlug = trim($baseSlug, '-');
        } else {
            // 名前も空の場合はユニークIDを使用
            $baseSlug = 'creator';
        }
        
        // 空になってしまった場合の対策
        if (empty($baseSlug)) {
            $baseSlug = 'creator-' . uniqid();
        }
        
        // 重複チェック
        $slug = $baseSlug;
        $counter = 1;
        while (true) {
            $checkStmt = $db->prepare("SELECT id FROM creators WHERE slug = ? AND is_active = 1" . ($id ? " AND id != ?" : ""));
            if ($id) {
                $checkStmt->execute([$slug, $id]);
            } else {
                $checkStmt->execute([$slug]);
            }
            if (!$checkStmt->fetch()) {
                break; // 重複なし
            }
            $slug = $baseSlug . '-' . $counter;
            $counter++;
        }
    } else {
        // 手動入力されたスラッグの重複チェック
        $checkStmt = $db->prepare("SELECT id FROM creators WHERE slug = ? AND is_active = 1" . ($id ? " AND id != ?" : ""));
        if ($id) {
            $checkStmt->execute([$slug, $id]);
        } else {
            $checkStmt->execute([$slug]);
        }
        if ($checkStmt->fetch()) {
            $error = 'このスラッグは既に使用されています。別のスラッグを指定してください。';
        }
    }
    
    if (empty($error)) {
        if ($id) {
            // 更新
            $stmt = $db->prepare("UPDATE creators SET 
                name=?, slug=?, role=?, description=?, bio_long=?, image=?, header_image=?,
                twitter=?, instagram=?, pixiv=?, youtube=?, tiktok=?, booth=?, skeb=?, litlink=?, website=?, discord=?,
                page_layout=?, theme_color=?, gallery_style=?, show_works=?, show_articles=?, custom_css=?,
                email=?, bank_name=?, bank_branch=?, bank_account_type=?, bank_account_number=?, bank_account_name=?, 
                commission_rate=?, commission_per_item=?, service_commission_rate=?, service_commission_per_item=?,
                business_type=?, withholding_tax_required=?,
                sort_order=? WHERE id=?");
            $stmt->execute([
                $name, $slug, $role, $description, $bio_long, $image, $header_image,
                $twitter, $instagram, $pixiv, $youtube, $tiktok, $booth, $skeb, $litlink, $website, $discord,
                $page_layout, $theme_color, $gallery_style, $show_works, $show_articles, $custom_css,
                $email, $bank_name, $bank_branch, $bank_account_type, $bank_account_number, $bank_account_name, 
                $commission_rate, $commission_per_item, $service_commission_rate, $service_commission_per_item,
                $business_type, $withholding_tax_required,
                $sort_order, $id
            ]);
            
            // パスワード更新（入力がある場合のみ）
            if ($hashedPassword) {
                try {
                    $stmt = $db->prepare("UPDATE creators SET password = ? WHERE id = ?");
                    $stmt->execute([$hashedPassword, $id]);
                } catch (PDOException $e) {
                    // passwordカラムがない場合は無視
                }
            }
            
            // ログイン許可設定更新
            try {
                $stmt = $db->prepare("UPDATE creators SET login_enabled = ? WHERE id = ?");
                $stmt->execute([$login_enabled, $id]);
            } catch (PDOException $e) {
                // login_enabledカラムがない場合は無視
            }
            
            $message = 'クリエイター情報を更新しました。';
        } else {
            // 新規作成
            $stmt = $db->prepare("INSERT INTO creators 
                (name, slug, role, description, bio_long, image, header_image,
                twitter, instagram, pixiv, youtube, tiktok, booth, skeb, litlink, website, discord,
                page_layout, theme_color, gallery_style, show_works, show_articles, custom_css,
                email, bank_name, bank_branch, bank_account_type, bank_account_number, bank_account_name, 
                commission_rate, commission_per_item, service_commission_rate, service_commission_per_item,
                business_type, withholding_tax_required,
                sort_order) 
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
            $stmt->execute([
                $name, $slug, $role, $description, $bio_long, $image, $header_image,
                $twitter, $instagram, $pixiv, $youtube, $tiktok, $booth, $skeb, $litlink, $website, $discord,
                $page_layout, $theme_color, $gallery_style, $show_works, $show_articles, $custom_css,
                $email, $bank_name, $bank_branch, $bank_account_type, $bank_account_number, $bank_account_name, 
                $commission_rate, $commission_per_item, $service_commission_rate, $service_commission_per_item,
                $business_type, $withholding_tax_required,
                $sort_order
            ]);
            
            // 新規作成後にログイン許可設定
            $newId = $db->lastInsertId();
            try {
                $stmt = $db->prepare("UPDATE creators SET login_enabled = ? WHERE id = ?");
                $stmt->execute([$login_enabled, $newId]);
            } catch (PDOException $e) {}
            
            $message = 'クリエイターを追加しました。';
        }
    }
}

// 編集対象を取得
$editCreator = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM creators WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editCreator = $stmt->fetch();
}

// アーカイブ復元
if (isset($_GET['restore']) && is_numeric($_GET['restore'])) {
    $stmt = $db->prepare("UPDATE creators SET is_active = 1 WHERE id = ?");
    $stmt->execute([$_GET['restore']]);
    $message = 'クリエイターを復元しました。';
}

// 表示モード
$showArchived = isset($_GET['archived']);

// 一覧を取得
if ($showArchived) {
    $creators = $db->query("SELECT * FROM creators WHERE is_active = 0 ORDER BY id DESC")->fetchAll();
} else {
    $creators = $db->query("SELECT * FROM creators WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll();
}

// アーカイブ数を取得
$archivedCount = $db->query("SELECT COUNT(*) FROM creators WHERE is_active = 0")->fetchColumn();


$pageTitle = "クリエイター管理";
include "includes/header.php";
?>
        <!-- Updated header and removed old sidebar -->
        <div class="flex flex-wrap justify-between items-center gap-4 mb-8">
            <div>
                <h2 class="text-2xl font-bold text-gray-800">メンバー管理</h2>
                <p class="text-gray-500">メンバーの追加・編集</p>
            </div>
            <div class="flex gap-2">
                <?php if ($showArchived): ?>
                <a href="creators.php" class="bg-gray-200 hover:bg-gray-300 text-gray-700 font-bold px-4 py-2 rounded-lg transition">
                    <i class="fas fa-list mr-1"></i>公開中
                </a>
                <?php else: ?>
                <?php if ($archivedCount > 0): ?>
                <a href="creators.php?archived=1" class="bg-gray-200 hover:bg-gray-300 text-gray-700 font-bold px-4 py-2 rounded-lg transition">
                    <i class="fas fa-archive mr-1"></i>アーカイブ (<?= $archivedCount ?>)
                </a>
                <?php endif; ?>
                <a href="creators.php" class="bg-yellow-400 hover:bg-yellow-500 text-gray-900 font-bold px-6 py-3 rounded-lg transition">
                    <i class="fas fa-plus mr-2"></i>新規追加
                </a>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($showArchived): ?>
        <div class="bg-gray-100 border border-gray-200 text-gray-600 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-archive mr-2"></i>アーカイブ済みクリエイター一覧（非公開状態）
        </div>
        <?php endif; ?>
        
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
        
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-8">
            <!-- Form -->
            <div class="xl:col-span-2">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="font-bold text-gray-800 mb-6">
                        <?= $editCreator ? 'クリエイター編集' : '新規クリエイター' ?>
                    </h3>
                    
                    <!-- タブナビゲーション -->
                    <div class="flex gap-2 mb-6 border-b border-gray-200 pb-4">
                        <button type="button" onclick="switchTab('basic')" class="tab-btn active px-4 py-2 rounded-lg font-bold text-sm transition">
                            <i class="fas fa-user mr-1"></i>基本情報
                        </button>
                        <button type="button" onclick="switchTab('sns')" class="tab-btn px-4 py-2 rounded-lg font-bold text-sm bg-gray-100 hover:bg-gray-200 transition">
                            <i class="fas fa-share-alt mr-1"></i>SNS
                        </button>
                    </div>
                    
                    <form method="POST" enctype="multipart/form-data">
                        <?php if ($editCreator): ?>
                        <input type="hidden" name="id" value="<?= $editCreator['id'] ?>">
                        <input type="hidden" name="current_image" value="<?= htmlspecialchars($editCreator['image'] ?? '') ?>">
                        <input type="hidden" name="current_header_image" value="<?= htmlspecialchars($editCreator['header_image'] ?? '') ?>">
                        <?php endif; ?>
                        
                        <!-- 基本情報タブ -->
                        <div id="tab-basic" class="tab-content active space-y-4">
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">名前 *</label>
                                    <input type="text" name="name" required
                                        value="<?= htmlspecialchars($editCreator['name'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">スラッグ（URL用）</label>
                                    <input type="text" name="slug"
                                        value="<?= htmlspecialchars($editCreator['slug'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                        placeholder="例: yamada-taro">
                                    <p class="text-xs text-gray-400 mt-1">空欄の場合は自動生成されます</p>
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">役職・肩書き</label>
                                <input type="text" name="role"
                                    value="<?= htmlspecialchars($editCreator['role'] ?? '') ?>"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                    placeholder="イラストレーター">
                            </div>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">プロフィール画像</label>
                                    <?php if (!empty($editCreator['image'])): ?>
                                    <div class="mb-2">
                                        <img src="../<?= htmlspecialchars($editCreator['image']) ?>" class="w-20 h-20 object-cover rounded-lg">
                                    </div>
                                    <?php endif; ?>
                                    <input type="file" name="image" accept="image/*"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg">
                                    <p class="text-xs text-gray-500 mt-1">推奨: <strong>400×400px</strong>（正方形）</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">ヘッダー画像（任意）</label>
                                    <?php if (!empty($editCreator['header_image'])): ?>
                                    <div class="mb-2">
                                        <img src="../<?= htmlspecialchars($editCreator['header_image']) ?>" class="w-32 h-16 object-cover rounded-lg">
                                    </div>
                                    <?php endif; ?>
                                    <input type="file" name="header_image" accept="image/*"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg">
                                    <p class="text-xs text-gray-500 mt-1">推奨: <strong>1200×400px</strong>（横長）</p>
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">自己紹介（短文）</label>
                                <textarea name="description" rows="2"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                    placeholder="トップページに表示される短い紹介文"><?= htmlspecialchars($editCreator['description'] ?? '') ?></textarea>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">詳細プロフィール</label>
                                <textarea name="bio_long" rows="5"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                    placeholder="個別ページに表示される詳しい自己紹介"><?= htmlspecialchars($editCreator['bio_long'] ?? '') ?></textarea>
                            </div>
                            
                        </div>
                        
                        <!-- SNSタブ -->
                        <div id="tab-sns" class="tab-content space-y-4">
                            <p class="text-sm text-gray-500 mb-4">各SNSのIDまたはURLを入力してください</p>
                            
                            <div class="grid grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">
                                        <i class="fa-brands fa-x-twitter mr-1"></i>X (Twitter)
                                    </label>
                                    <input type="text" name="twitter"
                                        value="<?= htmlspecialchars($editCreator['twitter'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                        placeholder="@username">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">
                                        <i class="fa-brands fa-instagram mr-1" style="color:#E4405F"></i>Instagram
                                    </label>
                                    <input type="text" name="instagram"
                                        value="<?= htmlspecialchars($editCreator['instagram'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                        placeholder="username">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">
                                        <i class="fas fa-paintbrush mr-1" style="color:#0096FA"></i>Pixiv
                                    </label>
                                    <input type="text" name="pixiv"
                                        value="<?= htmlspecialchars($editCreator['pixiv'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                        placeholder="12345678">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">
                                        <i class="fa-brands fa-youtube mr-1" style="color:#FF0000"></i>YouTube
                                    </label>
                                    <input type="text" name="youtube"
                                        value="<?= htmlspecialchars($editCreator['youtube'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                        placeholder="https://youtube.com/@channel">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">
                                        <i class="fa-brands fa-tiktok mr-1"></i>TikTok
                                    </label>
                                    <input type="text" name="tiktok"
                                        value="<?= htmlspecialchars($editCreator['tiktok'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                        placeholder="@username">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">
                                        <i class="fas fa-store mr-1" style="color:#FC4D50"></i>BOOTH
                                    </label>
                                    <input type="text" name="booth"
                                        value="<?= htmlspecialchars($editCreator['booth'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                        placeholder="https://xxx.booth.pm/">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">
                                        <i class="fas fa-palette mr-1" style="color:#29B6F6"></i>Skeb
                                    </label>
                                    <input type="text" name="skeb"
                                        value="<?= htmlspecialchars($editCreator['skeb'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                        placeholder="username">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">
                                        <i class="fas fa-link mr-1" style="color:#28C8D2"></i>lit.link
                                    </label>
                                    <input type="text" name="litlink"
                                        value="<?= htmlspecialchars($editCreator['litlink'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                        placeholder="username">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">
                                        <i class="fab fa-discord mr-1" style="color:#5865F2"></i>Discord
                                    </label>
                                    <input type="text" name="discord"
                                        value="<?= htmlspecialchars($editCreator['discord'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                        placeholder="https://discord.gg/invite または ユーザー名">
                                </div>
                                <div class="col-span-2">
                                    <label class="block text-sm font-bold text-gray-700 mb-2">
                                        <i class="fas fa-globe mr-1"></i>Webサイト
                                    </label>
                                    <input type="url" name="website"
                                        value="<?= htmlspecialchars($editCreator['website'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                        placeholder="https://example.com">
                                </div>
                            </div>
                        </div>
                        
                        <!-- 連絡先セクション -->
                        <div class="bg-purple-50 rounded-lg p-4 mt-6">
                            <h4 class="font-bold text-gray-800 mb-3">
                                <i class="fas fa-envelope text-purple-600 mr-2"></i>連絡先（支払通知書送信用）
                            </h4>
                            <p class="text-xs text-gray-500 mb-4">支払通知書をメールで送信する際に使用します。</p>
                            
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">メールアドレス</label>
                                <input type="email" name="email"
                                    value="<?= htmlspecialchars($editCreator['email'] ?? '') ?>"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-purple-400 outline-none"
                                    placeholder="creator@example.com">
                            </div>
                        </div>
                        
                        <!-- クリエイターダッシュボードログイン設定 -->
                        <div class="bg-green-50 rounded-lg p-4 mt-6">
                            <h4 class="font-bold text-gray-800 mb-3">
                                <i class="fas fa-key text-green-600 mr-2"></i>クリエイターダッシュボード ログイン設定
                            </h4>
                            <p class="text-xs text-gray-500 mb-4">クリエイターが自分のダッシュボードにログインするための設定です。</p>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">パスワード</label>
                                    <input type="password" name="creator_password"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-400 outline-none"
                                        placeholder="<?= $editCreator ? '変更する場合のみ入力' : '新規パスワード' ?>">
                                    <p class="text-xs text-gray-500 mt-1">
                                        <?php if ($editCreator && !empty($editCreator['password'])): ?>
                                        <span class="text-green-600"><i class="fas fa-check-circle mr-1"></i>パスワード設定済み</span>
                                        <?php else: ?>
                                        <span class="text-yellow-600"><i class="fas fa-exclamation-circle mr-1"></i>パスワード未設定</span>
                                        <?php endif; ?>
                                    </p>
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">ログイン許可</label>
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" name="login_enabled" value="1"
                                            <?= (isset($editCreator['login_enabled']) ? $editCreator['login_enabled'] : (!empty($editCreator['password']))) ? 'checked' : '' ?>
                                            class="w-5 h-5 rounded text-green-500">
                                        <span class="text-sm text-gray-600">ダッシュボードへのログインを許可</span>
                                    </label>
                                    <p class="text-xs text-gray-500 mt-1">オフにすると一時的にログインを禁止できます</p>
                                </div>
                            </div>
                            
                            <?php if ($editCreator && !empty($editCreator['last_login'])): ?>
                            <div class="mt-3 text-xs text-gray-500">
                                <i class="fas fa-clock mr-1"></i>最終ログイン: <?= date('Y/m/d H:i', strtotime($editCreator['last_login'])) ?>
                            </div>
                            <?php endif; ?>
                            
                            <div class="mt-3 text-xs text-gray-500">
                                ログインURL: <a href="/creator-dashboard/login.php" target="_blank" class="text-green-600 underline">/creator-dashboard/</a>
                            </div>
                        </div>
                        
                        <!-- 銀行口座情報セクション -->
                        <div class="bg-blue-50 rounded-lg p-4 mt-6">
                            <h4 class="font-bold text-gray-800 mb-3">
                                <i class="fas fa-university text-blue-600 mr-2"></i>振込先口座情報（売上支払い用）
                            </h4>
                            <p class="text-xs text-gray-500 mb-4">クリエイターへの売上振込に使用します。この情報は支払通知書にも記載されます。</p>
                            
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">銀行名</label>
                                    <input type="text" name="bank_name"
                                        value="<?= htmlspecialchars($editCreator['bank_name'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-400 outline-none"
                                        placeholder="〇〇銀行">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">支店名</label>
                                    <input type="text" name="bank_branch"
                                        value="<?= htmlspecialchars($editCreator['bank_branch'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-400 outline-none"
                                        placeholder="〇〇支店">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">口座種別</label>
                                    <select name="bank_account_type"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-400 outline-none">
                                        <option value="">選択してください</option>
                                        <option value="普通" <?= ($editCreator['bank_account_type'] ?? '') === '普通' ? 'selected' : '' ?>>普通</option>
                                        <option value="当座" <?= ($editCreator['bank_account_type'] ?? '') === '当座' ? 'selected' : '' ?>>当座</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">口座番号</label>
                                    <input type="text" name="bank_account_number"
                                        value="<?= htmlspecialchars($editCreator['bank_account_number'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-400 outline-none"
                                        placeholder="1234567">
                                </div>
                                <div class="md:col-span-2">
                                    <label class="block text-sm font-bold text-gray-700 mb-2">口座名義（カタカナ）</label>
                                    <input type="text" name="bank_account_name"
                                        value="<?= htmlspecialchars($editCreator['bank_account_name'] ?? '') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-400 outline-none"
                                        placeholder="ヤマダ タロウ">
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">商品販売手数料率（%）</label>
                                    <input type="number" name="commission_rate" step="0.01" min="0" max="100"
                                        value="<?= htmlspecialchars($editCreator['commission_rate'] ?? '20.00') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-400 outline-none">
                                    <p class="text-xs text-gray-500 mt-1">グッズ売上に対する手数料</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">商品販売手数料単価（円/件）</label>
                                    <input type="number" name="commission_per_item" step="1" min="0"
                                        value="<?= htmlspecialchars($editCreator['commission_per_item'] ?? '0') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-400 outline-none">
                                    <p class="text-xs text-gray-500 mt-1">注文1件あたりの固定手数料</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">サービス販売手数料率（%）</label>
                                    <input type="number" name="service_commission_rate" step="0.01" min="0" max="100"
                                        value="<?= htmlspecialchars($editCreator['service_commission_rate'] ?? '15.00') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-400 outline-none">
                                    <p class="text-xs text-gray-500 mt-1">スキル販売売上に対する手数料</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">サービス販売手数料単価（円/件）</label>
                                    <input type="number" name="service_commission_per_item" step="1" min="0"
                                        value="<?= htmlspecialchars($editCreator['service_commission_per_item'] ?? '0') ?>"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-400 outline-none">
                                    <p class="text-xs text-gray-500 mt-1">取引1件あたりの固定手数料</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">事業者区分</label>
                                    <select name="business_type" class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-400 outline-none">
                                        <option value="individual" <?= ($editCreator['business_type'] ?? 'individual') === 'individual' ? 'selected' : '' ?>>個人</option>
                                        <option value="corporation" <?= ($editCreator['business_type'] ?? '') === 'corporation' ? 'selected' : '' ?>>法人</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-bold text-gray-700 mb-2">源泉徴収</label>
                                    <label class="flex items-center gap-2 cursor-pointer">
                                        <input type="checkbox" name="withholding_tax_required" value="1"
                                            <?= ($editCreator['withholding_tax_required'] ?? 1) ? 'checked' : '' ?>
                                            class="w-5 h-5 rounded text-blue-500">
                                        <span class="text-sm text-gray-600">源泉徴収を行う</span>
                                    </label>
                                    <p class="text-xs text-gray-500 mt-1">個人の場合は原則として源泉徴収が必要です</p>
                                </div>
                                <div class="md:col-span-2 bg-blue-50 rounded-lg p-3">
                                    <p class="text-xs text-gray-600">
                                        <i class="fas fa-box mr-1 text-blue-500"></i><strong>商品販売（グッズ）:</strong> 
                                        手数料率 <?= htmlspecialchars($editCreator['commission_rate'] ?? '20.00') ?>% + 単価 <?= htmlspecialchars($editCreator['commission_per_item'] ?? '0') ?>円/件
                                    </p>
                                </div>
                                <div class="md:col-span-2 bg-purple-50 rounded-lg p-3">
                                    <p class="text-xs text-gray-600">
                                        <i class="fas fa-paint-brush mr-1 text-purple-500"></i><strong>サービス販売（スキル）:</strong> 
                                        手数料率 <?= htmlspecialchars($editCreator['service_commission_rate'] ?? '15.00') ?>% + 単価 <?= htmlspecialchars($editCreator['service_commission_per_item'] ?? '0') ?>円/件
                                    </p>
                                </div>
                            </div>
                        </div>
                        
                        <div class="mt-6 pt-6 border-t border-gray-200">
                            <button type="submit" 
                                class="w-full bg-yellow-400 hover:bg-yellow-500 text-gray-900 font-bold py-3 rounded-lg transition">
                                <?= $editCreator ? '更新する' : '追加する' ?>
                            </button>
                            
                            <?php if ($editCreator): ?>
                            <a href="creators.php" class="block text-center text-gray-500 hover:text-gray-700 text-sm mt-3">
                                キャンセル
                            </a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
            
            <!-- List -->
            <div class="xl:col-span-1">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h3 class="font-bold text-gray-800">クリエイター一覧（<?= count($creators) ?>名）</h3>
                    </div>
                    
                    <?php if (empty($creators)): ?>
                    <div class="px-6 py-12 text-center text-gray-400">
                        <i class="fas fa-users text-4xl mb-4"></i>
                        <p>クリエイターがまだ登録されていません</p>
                    </div>
                    <?php else: ?>
                    <div class="divide-y divide-gray-100 max-h-[600px] overflow-y-auto">
                        <?php foreach ($creators as $creator): ?>
                        <div class="px-4 py-3 hover:bg-gray-50">
                            <div class="flex items-center gap-3">
                                <?php if (!empty($creator['image'])): ?>
                                <img src="../<?= htmlspecialchars($creator['image']) ?>" class="w-10 h-10 object-cover rounded-full">
                                <?php else: ?>
                                <div class="w-10 h-10 bg-gray-200 rounded-full flex items-center justify-center">
                                    <i class="fas fa-user text-gray-400"></i>
                                </div>
                                <?php endif; ?>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2">
                                        <p class="font-bold text-gray-800 text-sm truncate"><?= htmlspecialchars($creator['name']) ?></p>
                                        <?php if (!empty($creator['role'])): ?>
                                        <span class="text-xs bg-blue-100 text-blue-600 px-2 py-0.5 rounded font-bold"><?= htmlspecialchars($creator['role']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="flex items-center gap-2 mt-0.5">
                                        <?php if (!empty($creator['slug'])): ?>
                                        <span class="text-xs text-gray-400">@<?= htmlspecialchars($creator['slug']) ?></span>
                                        <?php endif; ?>
                                        <!-- ログイン状況 -->
                                        <?php if (!empty($creator['login_enabled'])): ?>
                                        <span class="text-xs bg-green-100 text-green-600 px-1.5 py-0.5 rounded">
                                            <i class="fas fa-check-circle mr-0.5"></i>ログイン可
                                        </span>
                                        <?php elseif (!empty($creator['password'])): ?>
                                        <span class="text-xs bg-yellow-100 text-yellow-600 px-1.5 py-0.5 rounded">
                                            <i class="fas fa-pause-circle mr-0.5"></i>停止中
                                        </span>
                                        <?php else: ?>
                                        <span class="text-xs bg-gray-100 text-gray-500 px-1.5 py-0.5 rounded">
                                            <i class="fas fa-minus-circle mr-0.5"></i>未招待
                                        </span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            </div>
                            <div class="flex gap-1 mt-2">
                                <a href="?edit=<?= $creator['id'] ?>" 
                                    class="flex-1 text-center px-2 py-1.5 bg-yellow-100 text-yellow-700 rounded text-xs font-bold hover:bg-yellow-200 transition">
                                    <i class="fas fa-edit mr-1"></i>編集
                                </a>
                                <!-- 招待/ログイン管理 -->
                                <?php if (empty($creator['email'])): ?>
                                <span class="px-2 py-1.5 bg-gray-100 text-gray-400 rounded text-xs" title="メール未登録">
                                    <i class="fas fa-envelope-slash"></i>
                                </span>
                                <?php elseif (empty($creator['password'])): ?>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="creator_id" value="<?= $creator['id'] ?>">
                                    <button type="submit" name="send_invite" value="1"
                                        class="px-2 py-1.5 bg-purple-100 text-purple-600 rounded text-xs font-bold hover:bg-purple-200 transition"
                                        title="招待メール送信">
                                        <i class="fas fa-paper-plane mr-1"></i>招待
                                    </button>
                                </form>
                                <?php else: ?>
                                <form method="POST" class="inline">
                                    <input type="hidden" name="creator_id" value="<?= $creator['id'] ?>">
                                    <input type="hidden" name="login_enabled" value="<?= empty($creator['login_enabled']) ? 1 : 0 ?>">
                                    <button type="submit" name="toggle_login" value="1"
                                        class="px-2 py-1.5 <?= empty($creator['login_enabled']) ? 'bg-green-100 text-green-600 hover:bg-green-200' : 'bg-red-100 text-red-600 hover:bg-red-200' ?> rounded text-xs font-bold transition"
                                        title="<?= empty($creator['login_enabled']) ? 'ログインを許可' : 'ログインを禁止' ?>">
                                        <i class="fas <?= empty($creator['login_enabled']) ? 'fa-unlock' : 'fa-lock' ?>"></i>
                                    </button>
                                </form>
                                <?php endif; ?>
                                <a href="creator-payment.php?creator_id=<?= $creator['id'] ?>" 
                                    class="px-2 py-1.5 bg-blue-100 text-blue-600 rounded text-xs font-bold hover:bg-blue-200 transition">
                                    <i class="fas fa-file-invoice-dollar"></i>
                                </a>
                                <?php if (!empty($creator['slug'])): ?>
                                <a href="../creator.php?slug=<?= htmlspecialchars($creator['slug']) ?>" target="_blank"
                                    class="px-2 py-1.5 bg-green-100 text-green-600 rounded text-xs font-bold hover:bg-green-200 transition">
                                    <i class="fas fa-external-link-alt"></i>
                                </a>
                                <?php endif; ?>
                                <?php if ($showArchived): ?>
                                <a href="?restore=<?= $creator['id'] ?>" 
                                    onclick="return confirm('このクリエイターを復元しますか？')"
                                    class="px-2 py-1.5 bg-blue-100 text-blue-600 rounded text-xs font-bold hover:bg-blue-200 transition">
                                    <i class="fas fa-undo"></i>
                                </a>
                                <?php else: ?>
                                <a href="?delete=<?= $creator['id'] ?>" 
                                    onclick="return confirm('本当にアーカイブしますか？')"
                                    class="px-2 py-1.5 bg-red-100 text-red-600 rounded text-xs font-bold hover:bg-red-200 transition">
                                    <i class="fas fa-archive"></i>
                                </a>
                                <?php endif; ?>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>
    
    <script>
        function switchTab(tab) {
            // タブコンテンツを切り替え
            document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
            document.getElementById('tab-' + tab).classList.add('active');
            
            // タブボタンのスタイルを切り替え
            document.querySelectorAll('.tab-btn').forEach(el => {
                el.classList.remove('active');
                el.classList.add('bg-gray-100', 'hover:bg-gray-200');
            });
            event.target.classList.add('active');
            event.target.classList.remove('bg-gray-100', 'hover:bg-gray-200');
        }
    </script>
</body>
</html>

<?php include "includes/footer.php"; ?>
