<?php
/**
 * 漫画ビューアー拡張機能 - DBマイグレーション
 * - 広告/挿入ページ機能
 * - 埋め込み設定
 */

session_start();
require_once '../includes/auth.php';
require_once '../includes/db.php';
requireAuth();

$db = getDB();
$messages = [];
$errors = [];

// マイグレーション実行
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['migrate'])) {
    try {
        // 1. work_insert_pages テーブル作成（広告・挿入ページ用）
        $db->exec("
            CREATE TABLE IF NOT EXISTS work_insert_pages (
                id INT AUTO_INCREMENT PRIMARY KEY,
                work_id INT NOT NULL,
                insert_after INT NOT NULL DEFAULT 0 COMMENT '何ページ目の後に挿入するか（0=最初、-1=最後）',
                page_type ENUM('image', 'html') NOT NULL DEFAULT 'image',
                image VARCHAR(500) DEFAULT NULL COMMENT '画像パス',
                html_content TEXT DEFAULT NULL COMMENT 'HTMLコンテンツ',
                link_url VARCHAR(500) DEFAULT NULL COMMENT 'クリック時のリンク先',
                link_target ENUM('_self', '_blank') DEFAULT '_blank',
                background_color VARCHAR(20) DEFAULT '#000000',
                is_active TINYINT(1) DEFAULT 1,
                sort_order INT DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                FOREIGN KEY (work_id) REFERENCES works(id) ON DELETE CASCADE,
                INDEX idx_work_active (work_id, is_active),
                INDEX idx_insert_after (insert_after)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");
        $messages[] = "work_insert_pages テーブルを作成しました";

        // 2. works テーブルに埋め込み設定カラムを追加
        $stmt = $db->query("SHOW COLUMNS FROM works LIKE 'allow_embed'");
        if ($stmt->rowCount() === 0) {
            $db->exec("ALTER TABLE works ADD COLUMN allow_embed TINYINT(1) DEFAULT 1 COMMENT '埋め込み許可' AFTER view_mode");
            $messages[] = "works テーブルに allow_embed カラムを追加しました";
        } else {
            $messages[] = "allow_embed カラムは既に存在します";
        }

        // 3. works テーブルに埋め込みヘッダー表示設定を追加
        $stmt = $db->query("SHOW COLUMNS FROM works LIKE 'embed_show_header'");
        if ($stmt->rowCount() === 0) {
            $db->exec("ALTER TABLE works ADD COLUMN embed_show_header TINYINT(1) DEFAULT 0 COMMENT '埋め込み時ヘッダー表示' AFTER allow_embed");
            $messages[] = "works テーブルに embed_show_header カラムを追加しました";
        } else {
            $messages[] = "embed_show_header カラムは既に存在します";
        }

        // 4. works テーブルに埋め込みフッター表示設定を追加
        $stmt = $db->query("SHOW COLUMNS FROM works LIKE 'embed_show_footer'");
        if ($stmt->rowCount() === 0) {
            $db->exec("ALTER TABLE works ADD COLUMN embed_show_footer TINYINT(1) DEFAULT 1 COMMENT '埋め込み時フッター表示' AFTER embed_show_header");
            $messages[] = "works テーブルに embed_show_footer カラムを追加しました";
        } else {
            $messages[] = "embed_show_footer カラムは既に存在します";
        }

    } catch (PDOException $e) {
        $errors[] = "エラー: " . $e->getMessage();
    }
}

// 現在の状態を確認
$tableExists = false;
$columnsExist = [];
try {
    $stmt = $db->query("SHOW TABLES LIKE 'work_insert_pages'");
    $tableExists = $stmt->rowCount() > 0;
    
    foreach (['allow_embed', 'embed_show_header', 'embed_show_footer'] as $col) {
        $stmt = $db->query("SHOW COLUMNS FROM works LIKE '$col'");
        $columnsExist[$col] = $stmt->rowCount() > 0;
    }
} catch (Exception $e) {
    $errors[] = "状態確認エラー: " . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>漫画ビューアー拡張 - マイグレーション</title>
    <?php include 'includes/site-head.php'; ?>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="max-w-2xl mx-auto py-12 px-4">
        <div class="bg-white rounded-xl shadow-lg p-8">
            <h1 class="text-2xl font-bold mb-6 flex items-center gap-3">
                <i class="fas fa-database text-purple-500"></i>
                漫画ビューアー拡張 - DBマイグレーション
            </h1>

            <?php foreach ($messages as $msg): ?>
            <div class="bg-green-100 text-green-700 p-4 rounded-lg mb-4 flex items-center gap-2">
                <i class="fas fa-check-circle"></i>
                <?= htmlspecialchars($msg) ?>
            </div>
            <?php endforeach; ?>

            <?php foreach ($errors as $err): ?>
            <div class="bg-red-100 text-red-700 p-4 rounded-lg mb-4 flex items-center gap-2">
                <i class="fas fa-exclamation-circle"></i>
                <?= htmlspecialchars($err) ?>
            </div>
            <?php endforeach; ?>

            <div class="mb-6">
                <h2 class="font-bold text-lg mb-4">現在の状態</h2>
                <table class="w-full text-sm">
                    <tr class="border-b">
                        <td class="py-2">work_insert_pages テーブル</td>
                        <td class="py-2 text-right">
                            <?php if ($tableExists): ?>
                            <span class="text-green-600"><i class="fas fa-check"></i> 存在</span>
                            <?php else: ?>
                            <span class="text-gray-400"><i class="fas fa-times"></i> 未作成</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2">allow_embed カラム</td>
                        <td class="py-2 text-right">
                            <?php if ($columnsExist['allow_embed'] ?? false): ?>
                            <span class="text-green-600"><i class="fas fa-check"></i> 存在</span>
                            <?php else: ?>
                            <span class="text-gray-400"><i class="fas fa-times"></i> 未追加</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2">embed_show_header カラム</td>
                        <td class="py-2 text-right">
                            <?php if ($columnsExist['embed_show_header'] ?? false): ?>
                            <span class="text-green-600"><i class="fas fa-check"></i> 存在</span>
                            <?php else: ?>
                            <span class="text-gray-400"><i class="fas fa-times"></i> 未追加</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr class="border-b">
                        <td class="py-2">embed_show_footer カラム</td>
                        <td class="py-2 text-right">
                            <?php if ($columnsExist['embed_show_footer'] ?? false): ?>
                            <span class="text-green-600"><i class="fas fa-check"></i> 存在</span>
                            <?php else: ?>
                            <span class="text-gray-400"><i class="fas fa-times"></i> 未追加</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                </table>
            </div>

            <?php if (!$tableExists || !($columnsExist['allow_embed'] ?? false)): ?>
            <form method="POST">
                <button type="submit" name="migrate" value="1"
                    class="w-full bg-purple-500 hover:bg-purple-600 text-white font-bold py-3 px-6 rounded-lg transition">
                    <i class="fas fa-play mr-2"></i>
                    マイグレーション実行
                </button>
            </form>
            <?php else: ?>
            <div class="bg-blue-50 text-blue-700 p-4 rounded-lg">
                <i class="fas fa-info-circle mr-2"></i>
                マイグレーションは完了しています
            </div>
            
            <div class="mt-6 space-y-3">
                <h3 class="font-bold">次のステップ</h3>
                <a href="works.php" class="block bg-gray-100 hover:bg-gray-200 p-4 rounded-lg transition">
                    <i class="fas fa-images mr-2 text-purple-500"></i>
                    作品管理で挿入ページを設定
                </a>
            </div>
            <?php endif; ?>

            <div class="mt-8 pt-6 border-t">
                <h3 class="font-bold mb-3">追加される機能</h3>
                <ul class="text-sm text-gray-600 space-y-2">
                    <li><i class="fas fa-ad text-orange-500 mr-2"></i><strong>挿入ページ</strong>: 漫画の任意の位置に広告や告知を挿入</li>
                    <li><i class="fas fa-code text-blue-500 mr-2"></i><strong>埋め込み機能</strong>: 他サイトにビューアーをiframeで埋め込み</li>
                    <li><i class="fas fa-expand text-green-500 mr-2"></i><strong>全画面表示</strong>: 埋め込み時のフルスクリーンボタン</li>
                </ul>
            </div>

            <div class="mt-6">
                <a href="index.php" class="text-gray-500 hover:text-gray-700 text-sm">
                    <i class="fas fa-arrow-left mr-1"></i>
                    管理画面に戻る
                </a>
            </div>
        </div>
    </div>
</body>
</html>
