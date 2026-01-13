<?php
/**
 * サイト設定管理
 */

require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/site-settings.php';
requireAuth();

$db = getDB();
$message = '';
$error = '';

// アップロードディレクトリ
$uploadDir = '../uploads/site/';
if (!file_exists($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

function createGrayscaleIcon($sourcePath, $destPath) {
    if (!file_exists($sourcePath)) {
        return false;
    }

    $destDir = dirname($destPath);
    if (!is_dir($destDir)) {
        mkdir($destDir, 0755, true);
    }

    $imageInfo = @getimagesize($sourcePath);
    if ($imageInfo === false || !function_exists('imagefilter')) {
        return copy($sourcePath, $destPath);
    }

    $mimeType = $imageInfo['mime'];
    $srcImage = match ($mimeType) {
        'image/png' => imagecreatefrompng($sourcePath),
        'image/jpeg' => imagecreatefromjpeg($sourcePath),
        'image/gif' => imagecreatefromgif($sourcePath),
        'image/webp' => function_exists('imagecreatefromwebp') ? imagecreatefromwebp($sourcePath) : false,
        default => false,
    };

    if (!$srcImage) {
        return copy($sourcePath, $destPath);
    }

    imagealphablending($srcImage, false);
    imagesavealpha($srcImage, true);
    imagefilter($srcImage, IMG_FILTER_GRAYSCALE);

    $saved = match ($mimeType) {
        'image/png' => imagepng($srcImage, $destPath),
        'image/jpeg' => imagejpeg($srcImage, $destPath, 90),
        'image/gif' => imagegif($srcImage, $destPath),
        'image/webp' => function_exists('imagewebp') ? imagewebp($srcImage, $destPath, 90) : false,
        default => false,
    };

    imagedestroy($srcImage);

    if ($saved) {
        return true;
    }

    return copy($sourcePath, $destPath);
}

// 設定を保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $favicon = null;
        $og_image = null;
        
        // ファビコンアップロード
        if (isset($_FILES['favicon']) && $_FILES['favicon']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['favicon']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['ico', 'png', 'svg'])) {
                $filename = 'favicon.' . $ext;
                move_uploaded_file($_FILES['favicon']['tmp_name'], $uploadDir . $filename);
                $favicon = 'uploads/site/' . $filename;
            }
        }
        
        // OG画像アップロード
        if (isset($_FILES['og_image']) && $_FILES['og_image']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['og_image']['name'], PATHINFO_EXTENSION));
            if (in_array($ext, ['jpg', 'jpeg', 'png', 'webp'])) {
                $filename = 'og_image_' . time() . '.' . $ext;
                move_uploaded_file($_FILES['og_image']['tmp_name'], $uploadDir . $filename);
                $og_image = 'uploads/site/' . $filename;
            }
        }
        
        // 表示件数などの数値設定
        $homeArticlesLimit = isset($_POST['home_articles_limit']) ? (int)$_POST['home_articles_limit'] : 3;
        $featuredArticlesLimit = isset($_POST['featured_articles_limit']) ? (int)$_POST['featured_articles_limit'] : 3;
        $homeArticlesLimit = max(0, min(12, $homeArticlesLimit));
        $featuredArticlesLimit = max(0, min(12, $featuredArticlesLimit));

        $settings = [
            'site_name' => $_POST['site_name'] ?? '',
            'site_subtitle' => $_POST['site_subtitle'] ?? '',
            'site_description' => $_POST['site_description'] ?? '',
            'site_url' => $_POST['site_url'] ?? '',
            'catchcopy_line1' => $_POST['catchcopy_line1'] ?? '',
            'catchcopy_line2' => $_POST['catchcopy_line2'] ?? '',
            'catchcopy_line3' => $_POST['catchcopy_line3'] ?? '',
            'catchcopy_line4' => $_POST['catchcopy_line4'] ?? '',
            'footer_copyright' => $_POST['footer_copyright'] ?? '',
            'sns_x' => $_POST['sns_x'] ?? '',
            'sns_instagram' => $_POST['sns_instagram'] ?? '',
            'sns_youtube' => $_POST['sns_youtube'] ?? '',
            'sns_tiktok' => $_POST['sns_tiktok'] ?? '',
            'sns_discord' => $_POST['sns_discord'] ?? '',
            'contact_email' => $_POST['contact_email'] ?? '',

            // 記事表示設定
            'home_articles_limit' => (string)$homeArticlesLimit,
            'featured_articles_limit' => (string)$featuredArticlesLimit,
            'home_include_featured' => isset($_POST['home_include_featured']) ? '1' : '0',

            // ナビゲーション表示設定
            'nav_show_lab' => isset($_POST['nav_show_lab']) ? '1' : '0',
            'nav_show_store' => isset($_POST['nav_show_store']) ? '1' : '0',

            // PWA設定 (新規追加)
            'pwa_short_name' => $_POST['pwa_short_name'] ?? '',
            'pwa_theme_color' => $_POST['pwa_theme_color'] ?? '#ffffff',
            
            // SEO/アクセス解析設定
            'google_analytics_id' => trim($_POST['google_analytics_id'] ?? ''),
            'google_search_console' => trim($_POST['google_search_console'] ?? ''),
        ];
        
        // 既存の画像パスを維持するか、新しいパスで上書きするか
        // DBから現在の値を取得する必要があるため、ここで一度取得するか、hiddenで回すのが一般的ですが、
        // 今回は既存関数 getSetting を使えない（トランザクション内ではないが順序的に）ため、
        // 画像がアップロードされていない場合は更新対象から外すロジックにします。
        // ただし、配列ループで回しているので、$settings配列に入れる必要があります。
        
        // 簡易的な既存値取得
        $currentFavicon = $db->query("SELECT setting_value FROM site_settings WHERE setting_key = 'favicon'")->fetchColumn();
        $currentOg = $db->query("SELECT setting_value FROM site_settings WHERE setting_key = 'og_image'")->fetchColumn();

        if ($favicon) {
            $settings['favicon'] = $favicon;
        } else {
            $settings['favicon'] = $currentFavicon ?: '';
        }

        if ($og_image) {
            $settings['og_image'] = $og_image;
        } else {
            $settings['og_image'] = $currentOg ?: '';
        }
        
        $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = ?");
        
        foreach ($settings as $key => $value) {
            $stmt->execute([$key, $value, $value]);
        }

        // ---------------------------------------------------------
        // manifest.json の自動生成 (ここが追加機能の核です)
        // ---------------------------------------------------------
        $pwaShortName = !empty($settings['pwa_short_name']) ? $settings['pwa_short_name'] : $settings['site_name'];
        $pwaIconPath = !empty($settings['favicon']) ? $settings['favicon'] : ''; // uploads/site/favicon.png
        $backyardIconPath = '';

        if ($pwaIconPath) {
            $iconInfo = pathinfo($pwaIconPath);
            $iconDir = $iconInfo['dirname'] ?? 'uploads/site';
            $iconBase = $iconInfo['filename'] ?? 'favicon';
            $iconExt = $iconInfo['extension'] ?? '';

            if ($iconExt !== '') {
                $backyardIconPath = $iconDir . '/' . $iconBase . '-backyard.' . $iconExt;
                $sourceFile = dirname(__DIR__) . '/' . $pwaIconPath;
                $destFile = dirname(__DIR__) . '/' . $backyardIconPath;
                createGrayscaleIcon($sourceFile, $destFile);
            }
        }

        $manifestData = [
            "name" => $settings['site_name'],
            "short_name" => $pwaShortName,
            "start_url" => "/", // サイトのルート
            "display" => "standalone", // ブラウザUIを消してアプリっぽく表示
            "background_color" => "#ffffff",
            "theme_color" => $settings['pwa_theme_color'],
            "icons" => []
        ];

        if ($pwaIconPath) {
            // 注意: manifestはサイトルートに配置するため、パスはルート相対で記述
            // アップロードされたファビコンの拡張子判定
            $iconExt = pathinfo($pwaIconPath, PATHINFO_EXTENSION);
            $mimeType = match($iconExt) {
                'svg' => 'image/svg+xml',
                'ico' => 'image/x-icon',
                default => 'image/png'
            };

            $manifestData['icons'][] = [
                "src" => "/" . $pwaIconPath, // ex: /uploads/site/favicon.png
                "sizes" => "192x192 512x512", // 実際のサイズに関わらず宣言（ブラウザがリサイズ対応する場合が多い）
                "type" => $mimeType
            ];
        }

        // adminの一つ上の階層（サイトルート）に manifest.json を書き出し
        // ※権限エラーが出る場合はディレクトリのパーミッションを確認してください
        file_put_contents('../manifest.json', json_encode($manifestData, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        // バックヤード用 manifest.json を生成
        $backyardManifest = [
            "name" => $settings['site_name'] . " BACKYARD",
            "short_name" => "BACKYARD",
            "start_url" => "/admin/login.php",
            "scope" => "/admin/",
            "display" => "standalone",
            "background_color" => "#ffffff",
            "theme_color" => $settings['pwa_theme_color'],
            "icons" => []
        ];

        if (!empty($backyardIconPath)) {
            $backyardExt = pathinfo($backyardIconPath, PATHINFO_EXTENSION);
            $backyardMimeType = match($backyardExt) {
                'svg' => 'image/svg+xml',
                'ico' => 'image/x-icon',
                default => 'image/png'
            };

            $backyardManifest['icons'][] = [
                "src" => "/" . $backyardIconPath,
                "sizes" => "192x192 512x512",
                "type" => $backyardMimeType
            ];
        }

        file_put_contents(__DIR__ . '/manifest.json', json_encode($backyardManifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        
        $message = '設定を保存し、manifest.jsonを更新しました';

    } catch (Exception $e) {
        $error = '保存に失敗しました: ' . $e->getMessage();
    }
}

// 現在の設定を取得
function getSetting($db, $key, $default = '') {
    try {
        $stmt = $db->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetchColumn();
        return $result !== false ? $result : $default;
    } catch (Exception $e) {
        return $default;
    }
}

$settings = [
    'site_name' => getSetting($db, 'site_name', 'ぷれぐら！'),
    'site_subtitle' => getSetting($db, 'site_subtitle', 'CREATORS PLAYGROUND'),
    'site_description' => getSetting($db, 'site_description', ''),
    'site_url' => getSetting($db, 'site_url', 'https://tokagemushi.jp'),
    'catchcopy_line1' => getSetting($db, 'catchcopy_line1', '描く'),
    'catchcopy_line2' => getSetting($db, 'catchcopy_line2', 'ことは、'),
    'catchcopy_line3' => getSetting($db, 'catchcopy_line3', '遊び'),
    'catchcopy_line4' => getSetting($db, 'catchcopy_line4', 'だ。'),
    'footer_copyright' => getSetting($db, 'footer_copyright', 'ぷれぐら！PLAYGROUND'),
    'sns_x' => getSetting($db, 'sns_x', ''),
    'sns_instagram' => getSetting($db, 'sns_instagram', ''),
    'sns_youtube' => getSetting($db, 'sns_youtube', ''),
    'sns_tiktok' => getSetting($db, 'sns_tiktok', ''),
    'sns_discord' => getSetting($db, 'sns_discord', ''),
    'contact_email' => getSetting($db, 'contact_email', 'info@tokagemushi.jp'),
    'home_articles_limit' => getSetting($db, 'home_articles_limit', '3'),
    'featured_articles_limit' => getSetting($db, 'featured_articles_limit', '3'),
    'home_include_featured' => getSetting($db, 'home_include_featured', '1'),
    'nav_show_lab' => getSetting($db, 'nav_show_lab', '1'),
    'nav_show_store' => getSetting($db, 'nav_show_store', '1'),
    'favicon' => getSetting($db, 'favicon', ''),
    'og_image' => getSetting($db, 'og_image', ''),
    
    // PWA設定取得
    'pwa_short_name' => getSetting($db, 'pwa_short_name', ''),
    'pwa_theme_color' => getSetting($db, 'pwa_theme_color', '#ffffff'),
    
    // SEO/アクセス解析設定取得
    'google_analytics_id' => getSetting($db, 'google_analytics_id', ''),
    'google_search_console' => getSetting($db, 'google_search_console', ''),
];

$pageTitle = "サイト設定";
include "includes/header.php";
?>
        <div class="mb-8">
            <h2 class="text-2xl font-bold text-gray-800">サイト設定</h2>
            <p class="text-gray-500">サイト全体の設定を管理します</p>
        </div>
        
        <?php if ($message): ?>
        <div class="bg-green-100 border border-green-400 text-green-700 px-4 py-3 rounded-lg mb-6">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="bg-red-100 border border-red-400 text-red-700 px-4 py-3 rounded-lg mb-6">
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data" class="space-y-8">
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-globe text-blue-500"></i> 基本設定
                </h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">サイト名（SNS共有時に表示）</label>
                        <input type="text" name="site_name" value="<?= htmlspecialchars($settings['site_name']) ?>"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">サブタイトル</label>
                        <input type="text" name="site_subtitle" value="<?= htmlspecialchars($settings['site_subtitle']) ?>"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">サイトURL</label>
                        <input type="url" name="site_url" value="<?= htmlspecialchars($settings['site_url']) ?>" placeholder="https://tokagemushi.jp"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">問い合わせメールアドレス</label>
                        <input type="email" name="contact_email" value="<?= htmlspecialchars($settings['contact_email']) ?>" placeholder="info@example.com"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent">
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-bold text-gray-700 mb-2">サイト説明（SEO・SNS共有時に表示）</label>
                        <textarea name="site_description" rows="2"
                                  class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent"><?= htmlspecialchars($settings['site_description']) ?></textarea>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-mobile-alt text-pink-500"></i> モバイルホーム画面設定 (PWA)
                </h3>
                <p class="text-sm text-gray-500 mb-4">スマホで「ホーム画面に追加」した際の表示設定です。アイコンは「画像設定」のファビコンが使用されます。</p>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">ホーム画面でのアプリ名</label>
                        <input type="text" name="pwa_short_name" value="<?= htmlspecialchars($settings['pwa_short_name']) ?>"
                               placeholder="（空欄の場合はサイト名が使われます）"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent">
                        <p class="text-xs text-gray-500 mt-1">アイコンの下に表示される短い名前（全角6文字以内推奨）</p>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">テーマカラー</label>
                        <div class="flex items-center gap-3">
                            <input type="color" name="pwa_theme_color" value="<?= htmlspecialchars($settings['pwa_theme_color']) ?>"
                                   class="h-12 w-12 border border-gray-300 rounded cursor-pointer">
                            <input type="text" value="<?= htmlspecialchars($settings['pwa_theme_color']) ?>" readonly
                                   class="px-4 py-3 border border-gray-300 rounded-lg bg-gray-50 text-gray-500 flex-1">
                        </div>
                        <p class="text-xs text-gray-500 mt-1">アプリ起動時やブラウザ上部のバーの色です。</p>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-image text-indigo-500"></i> 画像設定
                </h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">ファビコン（ホーム画面アイコン兼用）</label>
                        <?php if (!empty($settings['favicon'])): ?>
                        <div class="mb-3 flex items-center gap-3">
                            <img src="../<?= htmlspecialchars($settings['favicon']) ?>" alt="現在のファビコン" class="w-8 h-8 object-contain border rounded">
                            <span class="text-sm text-gray-500">現在設定中</span>
                        </div>
                        <?php endif; ?>
                        <input type="file" name="favicon" accept=".ico,.png,.svg"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent">
                        <p class="text-xs text-gray-500 mt-1">推奨: <strong>512×512px</strong> PNG形式（PWA対応）</p>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">OGP画像</label>
                        <?php if (!empty($settings['og_image'])): ?>
                        <div class="mb-3">
                            <img src="../<?= htmlspecialchars($settings['og_image']) ?>" alt="現在のOG画像" class="w-full max-w-xs h-auto object-cover border rounded">
                            <span class="text-sm text-gray-500">現在設定中</span>
                        </div>
                        <?php endif; ?>
                        <input type="file" name="og_image" accept=".jpg,.jpeg,.png,.webp"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent">
                        <p class="text-xs text-gray-500 mt-1">推奨: <strong>1200×630px</strong>（SNSシェア時に表示）</p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-chart-line text-green-500"></i> SEO / アクセス解析
                </h3>
                <p class="text-sm text-gray-500 mb-4">Google Analytics と Search Console を連携して、アクセス状況や検索パフォーマンスを確認できます。</p>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">Google Analytics 測定ID</label>
                        <input type="text" name="google_analytics_id" value="<?= htmlspecialchars($settings['google_analytics_id']) ?>"
                               placeholder="G-XXXXXXXXXX"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent font-mono">
                        <p class="text-xs text-gray-500 mt-1">
                            <a href="https://analytics.google.com/" target="_blank" class="text-blue-500 hover:underline">Google Analytics</a> で発行される G- から始まるID
                        </p>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">Search Console 認証メタタグ</label>
                        <input type="text" name="google_search_console" value="<?= htmlspecialchars($settings['google_search_console']) ?>"
                               placeholder="XXXXXXXXXXXXXXXX（content属性の値のみ）"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent font-mono">
                        <p class="text-xs text-gray-500 mt-1">
                            <a href="https://search.google.com/search-console" target="_blank" class="text-blue-500 hover:underline">Search Console</a> の「HTMLタグ」認証で表示される content="xxx" の xxx 部分
                        </p>
                    </div>
                </div>
                
                <div class="mt-4 p-4 bg-gray-50 rounded-lg">
                    <p class="text-sm font-bold text-gray-700 mb-2"><i class="fas fa-info-circle text-blue-500 mr-1"></i> 設定手順</p>
                    <ol class="text-xs text-gray-600 space-y-1 list-decimal list-inside">
                        <li><strong>Google Analytics:</strong> <a href="https://analytics.google.com/" target="_blank" class="text-blue-500 hover:underline">GA</a> でプロパティを作成 → 管理 → データストリーム → 測定ID をコピー</li>
                        <li><strong>Search Console:</strong> <a href="https://search.google.com/search-console" target="_blank" class="text-blue-500 hover:underline">SC</a> でプロパティを追加 → HTMLタグ認証を選択 → content属性の値をコピー</li>
                    </ol>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-quote-left text-purple-500"></i> キャッチコピー設定
                </h3>
                
                <div class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">パート1（強調）</label>
                        <input type="text" name="catchcopy_line1" value="<?= htmlspecialchars($settings['catchcopy_line1']) ?>"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">パート2</label>
                        <input type="text" name="catchcopy_line2" value="<?= htmlspecialchars($settings['catchcopy_line2']) ?>"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">パート3（強調）</label>
                        <input type="text" name="catchcopy_line3" value="<?= htmlspecialchars($settings['catchcopy_line3']) ?>"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">パート4</label>
                        <input type="text" name="catchcopy_line4" value="<?= htmlspecialchars($settings['catchcopy_line4']) ?>"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent">
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-newspaper text-orange-500"></i> 記事表示設定
                </h3>

                <div class="grid grid-cols-1 md:grid-cols-3 gap-6">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">HOMEの表示件数</label>
                        <input type="number" name="home_articles_limit" min="0" max="12" step="1"
                               value="<?= htmlspecialchars($settings['home_articles_limit']) ?>"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">MEDIAの「注目記事」表示件数</label>
                        <input type="number" name="featured_articles_limit" min="0" max="12" step="1"
                               value="<?= htmlspecialchars($settings['featured_articles_limit']) ?>"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent">
                    </div>
                    <div class="md:col-span-1">
                        <label class="block text-sm font-bold text-gray-700 mb-2">HOMEに注目記事を含める</label>
                        <label class="flex items-center gap-3 bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 cursor-pointer">
                            <input type="checkbox" name="home_include_featured" value="1"
                                   <?= ($settings['home_include_featured'] ?? '1') === '1' ? 'checked' : '' ?>
                                   class="w-5 h-5 text-yellow-500 rounded focus:ring-yellow-400">
                            <span class="text-sm font-bold text-gray-700">含める</span>
                        </label>
                    </div>
                </div>
            </div>

            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-compass text-blue-500"></i> ナビゲーション設定
                </h3>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">THE LAB を表示</label>
                        <label class="flex items-center gap-3 bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 cursor-pointer">
                            <input type="checkbox" name="nav_show_lab" value="1"
                                   <?= ($settings['nav_show_lab'] ?? '1') === '1' ? 'checked' : '' ?>
                                   class="w-5 h-5 text-yellow-500 rounded focus:ring-yellow-400">
                            <span class="text-sm font-bold text-gray-700">表示する</span>
                        </label>
                        <p class="text-xs text-gray-500 mt-1">デジタルツール・実験コンテンツのセクション</p>
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">STORE を表示</label>
                        <label class="flex items-center gap-3 bg-gray-50 border border-gray-200 rounded-lg px-4 py-3 cursor-pointer">
                            <input type="checkbox" name="nav_show_store" value="1"
                                   <?= ($settings['nav_show_store'] ?? '1') === '1' ? 'checked' : '' ?>
                                   class="w-5 h-5 text-yellow-500 rounded focus:ring-yellow-400">
                            <span class="text-sm font-bold text-gray-700">表示する</span>
                        </label>
                        <p class="text-xs text-gray-500 mt-1">ECストアへのリンク</p>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-share-alt text-green-500"></i> SNS設定
                </h3>
                
                <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">
                            <svg class="inline w-4 h-4 mr-1" viewBox="0 0 24 24" fill="currentColor"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24H16.17l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231zm-1.161 17.52h1.833L7.084 4.126H5.117z"/></svg>
                            X (Twitter)
                        </label>
                        <input type="url" name="sns_x" value="<?= htmlspecialchars($settings['sns_x']) ?>" placeholder="https://x.com/username"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">
                            <i class="fab fa-instagram mr-1"></i> Instagram
                        </label>
                        <input type="url" name="sns_instagram" value="<?= htmlspecialchars($settings['sns_instagram']) ?>" placeholder="https://instagram.com/username"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">
                            <i class="fab fa-youtube mr-1"></i> YouTube
                        </label>
                        <input type="url" name="sns_youtube" value="<?= htmlspecialchars($settings['sns_youtube']) ?>" placeholder="https://youtube.com/@channel"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">
                            <i class="fab fa-tiktok mr-1"></i> TikTok
                        </label>
                        <input type="url" name="sns_tiktok" value="<?= htmlspecialchars($settings['sns_tiktok']) ?>" placeholder="https://tiktok.com/@username"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent">
                    </div>
                    <div>
                        <label class="block text-sm font-bold text-gray-700 mb-2">
                            <i class="fab fa-discord mr-1"></i> Discord
                        </label>
                        <input type="url" name="sns_discord" value="<?= htmlspecialchars($settings['sns_discord']) ?>" placeholder="https://discord.gg/invite"
                               class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent">
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="text-lg font-bold text-gray-800 mb-4 flex items-center gap-2">
                    <i class="fas fa-copyright text-gray-500"></i> フッター設定
                </h3>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-2">コピーライト表記</label>
                    <input type="text" name="footer_copyright" value="<?= htmlspecialchars($settings['footer_copyright']) ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 focus:border-transparent">
                </div>
            </div>
            
            <div class="flex justify-end">
                <button type="submit" class="bg-yellow-400 hover:bg-yellow-500 text-gray-900 px-8 py-3 rounded-lg font-bold transition">
                    <i class="fas fa-save mr-2"></i>設定を保存
                </button>
            </div>
        </form>

<?php include "includes/footer.php"; ?>
