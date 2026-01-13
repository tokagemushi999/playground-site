<?php
/**
 * 記事管理
 */

require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/image-helper.php';
require_once '../includes/site-settings.php';
requireAuth();

$db = getDB();
$message = '';

// --- Schema guard (auto add columns if missing) ---
// 既存環境でSQLを手動実行しなくても、必要カラムが揃うようにする。
function ensureArticleColumns(PDO $db) {
    try {
        $cols = $db->query("SHOW COLUMNS FROM articles")->fetchAll(PDO::FETCH_COLUMN, 0);
        $cols = is_array($cols) ? $cols : [];

        $alterStatements = [];

        // 注目記事の固定順
        if (!in_array('featured_order', $cols, true)) {
            $alterStatements[] = "ALTER TABLE articles ADD COLUMN featured_order INT NULL DEFAULT NULL AFTER is_featured";
        }
        // HOME(トップ)に表示するフラグと固定順
        if (!in_array('is_home', $cols, true)) {
            $alterStatements[] = "ALTER TABLE articles ADD COLUMN is_home TINYINT(1) NOT NULL DEFAULT 0 AFTER featured_order";
        }
        if (!in_array('home_order', $cols, true)) {
            $alterStatements[] = "ALTER TABLE articles ADD COLUMN home_order INT NULL DEFAULT NULL AFTER is_home";
        }
        // 関連コレクション
        if (!in_array('related_collection_id', $cols, true)) {
            $alterStatements[] = "ALTER TABLE articles ADD COLUMN related_collection_id INT NULL DEFAULT NULL AFTER related_work_id";
        }
        // 関連の表示位置
        if (!in_array('related_position', $cols, true)) {
            $alterStatements[] = "ALTER TABLE articles ADD COLUMN related_position VARCHAR(10) DEFAULT 'top' AFTER related_collection_id";
        }

        foreach ($alterStatements as $sql) {
            $db->exec($sql);
        }
    } catch (Exception $e) {
        // 権限不足などの場合は黙って継続（表示機能のみ無効になる）
        error_log('ensureArticleColumns failed: ' . $e->getMessage());
    }
}

ensureArticleColumns($db);

// 追加カラムが使えるか（ALTER権限がない環境でも管理画面が落ちないように）
$articleCols = [];
try {
    $articleCols = $db->query("SHOW COLUMNS FROM articles")->fetchAll(PDO::FETCH_COLUMN, 0);
    $articleCols = is_array($articleCols) ? $articleCols : [];
} catch (Exception $e) {
    $articleCols = [];
}
$hasFeaturedOrder = in_array('featured_order', $articleCols, true);
$hasIsHome = in_array('is_home', $articleCols, true);
$hasHomeOrder = in_array('home_order', $articleCols, true);

$creators = $db->query("SELECT id, name, image FROM creators WHERE is_active = 1 ORDER BY name ASC")->fetchAll();

$allWorks = $db->query("SELECT w.id, w.title, c.name as creator_name FROM works w LEFT JOIN creators c ON w.creator_id = c.id ORDER BY w.created_at DESC")->fetchAll(PDO::FETCH_ASSOC);

// コレクション一覧を取得
$allCollections = $db->query("SELECT c.id, c.title, (SELECT COUNT(*) FROM works WHERE collection_id = c.id AND is_active = 1) as sticker_count FROM collections c WHERE c.is_active = 1 ORDER BY c.sort_order ASC, c.id DESC")->fetchAll(PDO::FETCH_ASSOC);

// 削除処理
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $stmt = $db->prepare("UPDATE articles SET is_active = 0 WHERE id = ?");
    $stmt->execute([$_GET['delete']]);
    $message = '記事を削除しました。';
}

// 保存処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id = $_POST['id'] ?? null;
    $title = $_POST['title'] ?? '';
    $category = $_POST['category'] ?? '';
    $article_type = $_POST['article_type'] ?? 'blog';
    $excerpt = $_POST['excerpt'] ?? '';
    $content = $_POST['content'] ?? '';
    $creator_id = !empty($_POST['creator_id']) ? (int)$_POST['creator_id'] : null;
    $author = $_POST['author'] ?? '';
    $related_work_id = !empty($_POST['related_work_id']) ? (int)$_POST['related_work_id'] : null;
    $related_collection_id = !empty($_POST['related_collection_id']) ? (int)$_POST['related_collection_id'] : null;
    $related_position = $_POST['related_position'] ?? 'top';
    $is_featured = isset($_POST['is_featured']) ? 1 : 0;
    $featured_order = (isset($_POST['featured_order']) && $_POST['featured_order'] !== '') ? (int)$_POST['featured_order'] : null;
    $is_home = isset($_POST['is_home']) ? 1 : 0;
    $home_order = (isset($_POST['home_order']) && $_POST['home_order'] !== '') ? (int)$_POST['home_order'] : null;
    $published_at = $_POST['published_at'] ?: date('Y-m-d');
    
    // スラッグ生成
    $slug = $_POST['slug'] ?? '';
    if (empty($slug)) {
        $slug = preg_replace('/[^a-zA-Z0-9\-]/', '', str_replace(' ', '-', strtolower($title)));
        $slug = $slug ?: 'article-' . time();
    }
    
    // 画像アップロード処理（WebP変換）
    $thumbnailPath = $_POST['current_image'] ?? '';
    if (!empty($_FILES['thumbnail']['name'])) {
        $uploadDir = '../uploads/articles/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0755, true);
        }
        $baseName = uniqid('article_');
        $result = ImageHelper::processUpload(
            $_FILES['thumbnail']['tmp_name'],
            $uploadDir,
            $baseName,
            ['maxWidth' => 1200, 'maxHeight' => 800]
        );
        if ($result && isset($result['path'])) {
            $thumbnailPath = 'uploads/articles/' . basename($result['path']);
        }
    }
    
    if ($id) {
        $setParts = [
            'title=?',
            'slug=?',
            'content=?',
            'excerpt=?',
            'category=?',
            'article_type=?',
            'image=?',
            'author=?',
            'creator_id=?',
            'related_work_id=?',
            'related_collection_id=?',
            'related_position=?',
            'is_featured=?',
        ];
        $params = [$title, $slug, $content, $excerpt, $category, $article_type, $thumbnailPath, $author, $creator_id, $related_work_id, $related_collection_id, $related_position, $is_featured];

        if ($hasFeaturedOrder) {
            $setParts[] = 'featured_order=?';
            $params[] = $featured_order;
        }
        if ($hasIsHome) {
            $setParts[] = 'is_home=?';
            $params[] = $is_home;
        }
        if ($hasHomeOrder) {
            $setParts[] = 'home_order=?';
            $params[] = $home_order;
        }

        $setParts[] = 'published_at=?';
        $params[] = $published_at;
        $params[] = $id;

        $stmt = $db->prepare("UPDATE articles SET " . implode(',', $setParts) . " WHERE id=?");
        $stmt->execute($params);
        $message = '記事を更新しました。';
    } else {
        $cols = ['title', 'slug', 'content', 'excerpt', 'category', 'article_type', 'image', 'author', 'creator_id', 'related_work_id', 'related_collection_id', 'related_position', 'is_featured'];
        $vals = [$title, $slug, $content, $excerpt, $category, $article_type, $thumbnailPath, $author, $creator_id, $related_work_id, $related_collection_id, $related_position, $is_featured];

        if ($hasFeaturedOrder) {
            $cols[] = 'featured_order';
            $vals[] = $featured_order;
        }
        if ($hasIsHome) {
            $cols[] = 'is_home';
            $vals[] = $is_home;
        }
        if ($hasHomeOrder) {
            $cols[] = 'home_order';
            $vals[] = $home_order;
        }

        $cols[] = 'published_at';
        $vals[] = $published_at;

        $placeholders = implode(',', array_fill(0, count($cols), '?'));
        $stmt = $db->prepare("INSERT INTO articles (" . implode(',', $cols) . ") VALUES (" . $placeholders . ")");
        $stmt->execute($vals);
        $message = '記事を追加しました。';
    }
    
    // 編集画面を続ける場合
    if (isset($_POST['save_and_continue']) && !$id) {
        $newId = $db->lastInsertId();
        header("Location: articles.php?edit=" . $newId . "&saved=1");
        exit;
    }
}

// 編集対象を取得
$editArticle = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $stmt = $db->prepare("SELECT * FROM articles WHERE id = ?");
    $stmt->execute([$_GET['edit']]);
    $editArticle = $stmt->fetch();
}

// フィルター
$typeFilter = $_GET['type'] ?? '';
$query = "SELECT * FROM articles WHERE is_active = 1";
if ($typeFilter) {
    $query .= " AND article_type = " . $db->quote($typeFilter);
}
$orderBy = "published_at DESC, id DESC";
if ($hasIsHome && $hasHomeOrder && $hasFeaturedOrder) {
    $orderBy = "is_home DESC, CASE WHEN home_order IS NULL THEN 999999 ELSE home_order END ASC, is_featured DESC, CASE WHEN featured_order IS NULL THEN 999999 ELSE featured_order END ASC, published_at DESC, id DESC";
} elseif ($hasIsHome && $hasHomeOrder) {
    $orderBy = "is_home DESC, CASE WHEN home_order IS NULL THEN 999999 ELSE home_order END ASC, is_featured DESC, published_at DESC, id DESC";
} elseif ($hasFeaturedOrder) {
    $orderBy = "is_featured DESC, CASE WHEN featured_order IS NULL THEN 999999 ELSE featured_order END ASC, published_at DESC, id DESC";
} elseif ($hasIsHome) {
    $orderBy = "is_home DESC, is_featured DESC, published_at DESC, id DESC";
}
$query .= " ORDER BY " . $orderBy;
$articles = $db->query($query)->fetchAll();

// 保存完了メッセージ
if (isset($_GET['saved'])) {
    $message = '記事を保存しました。';
}

$pageTitle = "記事管理";
$extraHead = '
<link href="https://cdn.quilljs.com/1.3.7/quill.snow.css" rel="stylesheet">
<script src="https://cdn.quilljs.com/1.3.7/quill.min.js"></script>
<style>
    #editor { min-height: 300px; background: white; font-size: 16px; }
    #editor p { font-size: 16px; line-height: 1.8; }
    .ql-toolbar { background: #f9fafb; border-radius: 8px 8px 0 0; }
    .ql-container { border-radius: 0 0 8px 8px; font-size: 16px; }
    .ql-editor { font-size: 16px; line-height: 1.8; }
</style>
';
include "includes/header.php";
?>
        <div class="flex justify-between items-center mb-8">
            <div>
                <h2 class="text-2xl font-bold text-gray-800">記事管理</h2>
                <p class="text-gray-500">ブログ・日記・インタビュー記事の管理</p>
            </div>
            <a href="articles.php" class="bg-yellow-400 hover:bg-yellow-500 text-gray-900 font-bold px-6 py-3 rounded-lg transition">
                <i class="fas fa-plus mr-2"></i>新規追加
            </a>
        </div>
        
        <?php if ($message): ?>
        <div class="bg-green-50 border border-green-200 text-green-600 px-4 py-3 rounded-lg mb-6">
            <?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
        
        <!-- カテゴリフィルター -->
        <div class="flex flex-wrap gap-2 mb-6">
            <a href="articles.php" class="px-4 py-2 rounded-lg text-sm font-bold transition <?= !$typeFilter ? 'bg-yellow-400 text-gray-900' : 'bg-white text-gray-600 hover:bg-gray-100' ?>">
                すべて
            </a>
            <a href="?type=blog" class="px-4 py-2 rounded-lg text-sm font-bold transition <?= $typeFilter === 'blog' ? 'bg-blue-500 text-white' : 'bg-white text-gray-600 hover:bg-gray-100' ?>">
                ブログ
            </a>
            <a href="?type=diary" class="px-4 py-2 rounded-lg text-sm font-bold transition <?= $typeFilter === 'diary' ? 'bg-pink-500 text-white' : 'bg-white text-gray-600 hover:bg-gray-100' ?>">
                日記
            </a>
            <a href="?type=interview" class="px-4 py-2 rounded-lg text-sm font-bold transition <?= $typeFilter === 'interview' ? 'bg-purple-500 text-white' : 'bg-white text-gray-600 hover:bg-gray-100' ?>">
                インタビュー
            </a>
            <a href="?type=news" class="px-4 py-2 rounded-lg text-sm font-bold transition <?= $typeFilter === 'news' ? 'bg-green-500 text-white' : 'bg-white text-gray-600 hover:bg-gray-100' ?>">
                ニュース
            </a>
            <a href="?type=feature" class="px-4 py-2 rounded-lg text-sm font-bold transition <?= $typeFilter === 'feature' ? 'bg-orange-500 text-white' : 'bg-white text-gray-600 hover:bg-gray-100' ?>">
                特集
            </a>
        </div>
        
        <div class="grid grid-cols-1 xl:grid-cols-3 gap-8">
            <!-- Form -->
            <div class="xl:col-span-2">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                    <h3 class="font-bold text-gray-800 mb-6">
                        <?= $editArticle ? '記事編集' : '新規記事' ?>
                    </h3>
                    
                    <form method="POST" enctype="multipart/form-data" class="space-y-6" id="article-form">
                        <?php if ($editArticle): ?>
                        <input type="hidden" name="id" value="<?= $editArticle['id'] ?>">
                        <input type="hidden" name="current_image" value="<?= htmlspecialchars($editArticle['image'] ?? '') ?>">
                        <?php endif; ?>
                        
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">記事タイプ *</label>
                                <select name="article_type" required
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                                    <option value="blog" <?= ($editArticle['article_type'] ?? '') == 'blog' ? 'selected' : '' ?>>ブログ</option>
                                    <option value="diary" <?= ($editArticle['article_type'] ?? '') == 'diary' ? 'selected' : '' ?>>日記</option>
                                    <option value="interview" <?= ($editArticle['article_type'] ?? '') == 'interview' ? 'selected' : '' ?>>インタビュー</option>
                                    <option value="news" <?= ($editArticle['article_type'] ?? '') == 'news' ? 'selected' : '' ?>>ニュース</option>
                                    <option value="feature" <?= ($editArticle['article_type'] ?? '') == 'feature' ? 'selected' : '' ?>>特集</option>
                                </select>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">カテゴリタグ</label>
                                <input type="text" name="category"
                                    value="<?= htmlspecialchars($editArticle['category'] ?? '') ?>"
                                    placeholder="例: お知らせ, 制作日記"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">タイトル *</label>
                            <input type="text" name="title" required
                                value="<?= htmlspecialchars($editArticle['title'] ?? '') ?>"
                                class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none text-lg">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">スラッグ（URL用）</label>
                            <div class="flex items-center gap-2">
                                <span class="text-gray-500 text-sm">/article/</span>
                                <input type="text" name="slug"
                                    value="<?= htmlspecialchars($editArticle['slug'] ?? '') ?>"
                                    placeholder="自動生成されます"
                                    class="flex-1 px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">サムネイル画像</label>
                            <div class="flex gap-4 items-start">
                                <?php if (!empty($editArticle['image'])): ?>
                                <img src="../<?= htmlspecialchars($editArticle['image']) ?>" class="w-32 h-24 object-cover rounded-lg">
                                <?php endif; ?>
                                <div class="flex-1">
                                    <input type="file" name="thumbnail" accept="image/*"
                                        class="w-full px-4 py-2 border border-gray-300 rounded-lg">
                                    <p class="text-xs text-gray-500 mt-1">推奨: <strong>1200×630px</strong>（16:9比率、SNSシェアにも最適）</p>
                                </div>
                            </div>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">概要（一覧表示用）</label>
                            <textarea name="excerpt" rows="2"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                placeholder="記事の簡単な説明..."><?= htmlspecialchars($editArticle['excerpt'] ?? '') ?></textarea>
                        </div>
                        
                        <div>
                            <div class="flex items-center justify-between mb-2">
                                <label class="block text-sm font-bold text-gray-700">本文</label>
                                <div class="flex items-center gap-2">
                                    <button type="button" id="show-editor-help"
                                        class="text-xs font-bold px-3 py-1.5 rounded-lg bg-blue-50 text-blue-600 hover:bg-blue-100 transition">
                                        <i class="fas fa-question-circle mr-1"></i>書き方ヘルプ
                                    </button>
                                    <button type="button" id="toggle-html" 
                                        class="text-xs font-bold px-3 py-1.5 rounded-lg bg-gray-100 text-gray-700 hover:bg-gray-200 transition">
                                        <i class="fas fa-code mr-1"></i>HTMLソース
                                    </button>
                                </div>
                            </div>
                            
                            <!-- エディタヘルプ（初期非表示） -->
                            <div id="editor-help" class="hidden mb-4 bg-blue-50 border border-blue-200 rounded-lg p-4 text-sm">
                                <div class="flex justify-between items-start mb-3">
                                    <h4 class="font-bold text-blue-800"><i class="fas fa-lightbulb mr-1"></i>記事を書くコツ</h4>
                                    <button type="button" onclick="document.getElementById('editor-help').classList.add('hidden')" class="text-blue-400 hover:text-blue-600">
                                        <i class="fas fa-times"></i>
                                    </button>
                                </div>
                                <div class="grid md:grid-cols-2 gap-4 text-gray-700">
                                    <div>
                                        <p class="font-bold text-blue-700 mb-1">📝 基本の構成</p>
                                        <ul class="text-xs space-y-1 ml-4 list-disc">
                                            <li>見出し（H2）で大きく区切る</li>
                                            <li>1段落は3行以内に</li>
                                            <li>画像を適度に挟む</li>
                                        </ul>
                                    </div>
                                    <div>
                                        <p class="font-bold text-blue-700 mb-1">⌨️ ショートカット</p>
                                        <ul class="text-xs space-y-1 ml-4 list-disc">
                                            <li><kbd class="bg-gray-200 px-1 rounded">Ctrl+B</kbd> 太字</li>
                                            <li><kbd class="bg-gray-200 px-1 rounded">Ctrl+I</kbd> 斜体</li>
                                            <li><kbd class="bg-gray-200 px-1 rounded">Ctrl+Z</kbd> 元に戻す</li>
                                        </ul>
                                    </div>
                                    <div>
                                        <p class="font-bold text-blue-700 mb-1">💡 読みやすくするポイント</p>
                                        <ul class="text-xs space-y-1 ml-4 list-disc">
                                            <li>太字は1段落に1〜2箇所</li>
                                            <li>引用ブロックで強調</li>
                                            <li>箇条書きを活用</li>
                                        </ul>
                                    </div>
                                    <div>
                                        <p class="font-bold text-blue-700 mb-1">🎨 HTMLソースで使える技</p>
                                        <ul class="text-xs space-y-1 ml-4 list-disc">
                                            <li>中央寄せ: style="text-align:center"</li>
                                            <li>注目ボックス: 背景色+padding</li>
                                            <li>2カラム: display:flex</li>
                                        </ul>
                                    </div>
                                </div>
                            </div>

                            <!-- リッチエディタ（Quill） -->
                            <div id="editor-wrapper">
                                <div id="editor"><?= $editArticle['content'] ?? '' ?></div>
                            </div>

                            <!-- HTMLソース編集（切り替え用） -->
                            <textarea id="html-editor" rows="14"
                                class="hidden w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none font-mono text-sm"
                                spellcheck="false"
                                placeholder="ここにHTMLソースが表示されます"></textarea>

                            <input type="hidden" name="content" id="content-input">
                            <p class="text-xs text-gray-500 mt-1">※「HTMLソース」に切り替えると、本文をHTMLとして直接編集できます。</p>
                        </div>
                        
                        <!-- 著者選択をセレクトボックスに変更 -->
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">著者（メンバーから選択）</label>
                                <select name="creator_id" id="creator_id"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none"
                                    onchange="updateAuthorPreview()">
                                    <option value="">-- 選択しない --</option>
                                    <?php foreach ($creators as $creator): ?>
                                    <option value="<?= $creator['id'] ?>" 
                                        data-image="<?= htmlspecialchars($creator['image'] ?? '') ?>"
                                        data-name="<?= htmlspecialchars($creator['name']) ?>"
                                        <?= ($editArticle['creator_id'] ?? '') == $creator['id'] ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($creator['name']) ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                                <!-- 選択した著者のプレビュー -->
                                <div id="author-preview" class="mt-2 hidden">
                                    <div class="flex items-center gap-2 bg-gray-50 p-2 rounded-lg">
                                        <img id="author-image" src="/placeholder.svg" class="w-8 h-8 rounded-full object-cover">
                                        <span id="author-name" class="text-sm font-bold text-gray-700"></span>
                                    </div>
                                </div>
                            </div>
                            
                            <div>
                                <label class="block text-sm font-bold text-gray-700 mb-2">著者名（手動入力）</label>
                                <input type="text" name="author" id="author-input"
                                    value="<?= htmlspecialchars($editArticle['author'] ?? '') ?>"
                                    placeholder="メンバー以外の場合"
                                    class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                                <p class="text-xs text-gray-500 mt-1">メンバーを選択すると自動で入力されます</p>
                            </div>
                        </div>
                        
                        <!-- 関連作品選択を追加 -->
                        <div>
                            <label class="block font-bold text-gray-700 mb-2">
                                <i class="fas fa-palette text-purple-500 mr-1"></i>関連作品
                            </label>
                            <select name="related_work_id" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                                <option value="">-- 作品を選択（任意） --</option>
                                <?php foreach ($allWorks as $work): ?>
                                <option value="<?= $work['id'] ?>" <?= ($editArticle['related_work_id'] ?? '') == $work['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($work['title']) ?> (<?= htmlspecialchars($work['creator_name'] ?? '不明') ?>)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">この記事に関連する作品を選択できます</p>
                        </div>
                        
                        <!-- 関連コレクション選択を追加 -->
                        <div>
                            <label class="block font-bold text-gray-700 mb-2">
                                <i class="fas fa-layer-group text-green-500 mr-1"></i>関連コレクション
                            </label>
                            <select name="related_collection_id" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-green-400 outline-none">
                                <option value="">-- コレクションを選択（任意） --</option>
                                <?php foreach ($allCollections as $collection): ?>
                                <option value="<?= $collection['id'] ?>" <?= ($editArticle['related_collection_id'] ?? '') == $collection['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($collection['title']) ?> (<?= $collection['sticker_count'] ?? 0 ?>枚)
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">この記事に関連するコレクション（LINEスタンプ等）を選択できます</p>
                        </div>
                        
                        <!-- 関連の表示位置 -->
                        <div>
                            <label class="block font-bold text-gray-700 mb-2">
                                <i class="fas fa-arrows-alt-v text-blue-500 mr-1"></i>関連の表示位置
                            </label>
                            <select name="related_position" class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-400 outline-none">
                                <option value="top" <?= ($editArticle['related_position'] ?? 'top') === 'top' ? 'selected' : '' ?>>本文の上</option>
                                <option value="bottom" <?= ($editArticle['related_position'] ?? 'top') === 'bottom' ? 'selected' : '' ?>>本文の下</option>
                            </select>
                            <p class="text-xs text-gray-500 mt-1">関連作品・コレクションを本文の上か下に表示</p>
                        </div>
                        
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">公開日</label>
                            <input type="date" name="published_at"
                                value="<?= htmlspecialchars($editArticle['published_at'] ?? date('Y-m-d')) ?>"
                                class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none">
                        </div>
                        
                        <!-- 表示設定（HOME/注目 + 固定順） -->
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <!-- 注目記事（MEDIAの注目枠 + HOMEにも表示） -->
                            <div class="p-4 bg-yellow-50 rounded-lg border border-yellow-200">
                                <div class="flex items-start gap-3">
                                    <input type="checkbox" name="is_featured" id="is_featured" 
                                        <?= ($editArticle['is_featured'] ?? false) ? 'checked' : '' ?>
                                        class="mt-1 w-5 h-5 text-yellow-500 rounded focus:ring-yellow-400">
                                    <div class="flex-1">
                                        <label for="is_featured" class="text-sm font-bold text-gray-800 cursor-pointer">注目記事として表示</label>
                                        <p class="text-xs text-gray-600">MEDIAの「注目記事」枠に表示。設定によりHOMEにも表示されます。</p>
                                        <div class="mt-3">
                                            <label class="block text-xs font-bold text-gray-700 mb-1">注目の表示順（小さいほど上）</label>
                                            <input type="number" name="featured_order" min="0" step="1"
                                                value="<?= htmlspecialchars($editArticle['featured_order'] ?? '') ?>"
                                                placeholder="例: 1"
                                                class="w-full px-3 py-2 border border-yellow-200 rounded-lg focus:ring-2 focus:ring-yellow-400 outline-none bg-white">
                                            <p class="text-[11px] text-gray-500 mt-1">未入力の場合は公開日順になります。</p>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- HOMEに掲載（注目じゃなくてもOK） -->
                            <div class="p-4 bg-blue-50 rounded-lg border border-blue-200">
                                <div class="flex items-start gap-3">
                                    <input type="checkbox" name="is_home" id="is_home"
                                        <?= ($editArticle['is_home'] ?? false) ? 'checked' : '' ?>
                                        class="mt-1 w-5 h-5 text-blue-500 rounded focus:ring-blue-400">
                                    <div class="flex-1">
                                        <label for="is_home" class="text-sm font-bold text-gray-800 cursor-pointer">HOMEに掲載（トップに固定）</label>
                                        <p class="text-xs text-gray-600">注目でなくても、HOMEの「PICK UP ARTICLES」に表示できます。</p>
                                        <div class="mt-3">
                                            <label class="block text-xs font-bold text-gray-700 mb-1">HOME掲載順（小さいほど上）</label>
                                            <input type="number" name="home_order" min="0" step="1"
                                                value="<?= htmlspecialchars($editArticle['home_order'] ?? '') ?>"
                                                placeholder="例: 1"
                                                class="w-full px-3 py-2 border border-blue-200 rounded-lg focus:ring-2 focus:ring-blue-400 outline-none bg-white">
                                            <p class="text-[11px] text-gray-500 mt-1">未入力の場合は公開日順になります。</p>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        
                        <div class="flex gap-4">
                            <button type="submit" 
                                class="flex-1 bg-yellow-400 hover:bg-yellow-500 text-gray-900 font-bold py-3 rounded-lg transition">
                                <i class="fas fa-save mr-2"></i><?= $editArticle ? '更新する' : '公開する' ?>
                            </button>
                            <?php if (!$editArticle): ?>
                            <button type="submit" name="save_and_continue" value="1"
                                class="px-6 bg-gray-200 hover:bg-gray-300 text-gray-700 font-bold py-3 rounded-lg transition">
                                保存して編集を続ける
                            </button>
                            <?php endif; ?>
                        </div>
                        
                        <?php if ($editArticle): ?>
                        <div class="flex justify-between items-center pt-4 border-t border-gray-100">
                            <a href="articles.php" class="text-gray-500 hover:text-gray-700 text-sm">
                                <i class="fas fa-arrow-left mr-1"></i>一覧に戻る
                            </a>
                            <a href="../article.php?id=<?= $editArticle['id'] ?>" target="_blank" class="text-blue-500 hover:text-blue-600 text-sm">
                                <i class="fas fa-external-link-alt mr-1"></i>プレビュー
                            </a>
                        </div>
                        <?php endif; ?>
                    </form>
                </div>
            </div>
            
            <!-- List -->
            <div class="xl:col-span-1">
                <div class="bg-white rounded-xl shadow-sm border border-gray-100">
                    <div class="px-6 py-4 border-b border-gray-100">
                        <h3 class="font-bold text-gray-800">記事一覧（<?= count($articles) ?>件）</h3>
                    </div>
                    
                    <?php if (empty($articles)): ?>
                    <div class="px-6 py-12 text-center text-gray-400">
                        <i class="fas fa-newspaper text-4xl mb-4"></i>
                        <p>記事がまだありません</p>
                    </div>
                    <?php else: ?>
                    <div class="divide-y divide-gray-100 max-h-[600px] overflow-y-auto">
                        <?php foreach ($articles as $article): ?>
                        <div class="px-4 py-3 hover:bg-gray-50">
                            <div class="flex gap-3">
                                <?php if (!empty($article['image'])): ?>
                                <img src="../<?= htmlspecialchars($article['image']) ?>" class="w-16 h-12 object-cover rounded">
                                <?php else: ?>
                                <div class="w-16 h-12 bg-gray-200 rounded flex items-center justify-center">
                                    <i class="fas fa-image text-gray-400"></i>
                                </div>
                                <?php endif; ?>
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 mb-1">
                                        <!-- CHANGE: バッジスタイルを統一 -->
                                        <?php if (!empty($article['is_home'])): ?>
                                        <span class="text-xs bg-blue-100 text-blue-700 px-2 py-0.5 rounded font-bold">
                                            <i class="fas fa-house mr-1"></i>HOME
                                            <?php if (isset($article['home_order']) && $article['home_order'] !== '' && $article['home_order'] !== null): ?>
                                                <span class="opacity-80">#<?= htmlspecialchars($article['home_order']) ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <?php endif; ?>

                                        <?php if (!empty($article['is_featured'])): ?>
                                        <span class="text-xs bg-yellow-100 text-yellow-700 px-2 py-0.5 rounded font-bold">
                                            <i class="fas fa-star mr-1"></i>注目
                                            <?php if (isset($article['featured_order']) && $article['featured_order'] !== '' && $article['featured_order'] !== null): ?>
                                                <span class="opacity-80">#<?= htmlspecialchars($article['featured_order']) ?></span>
                                            <?php endif; ?>
                                        </span>
                                        <?php endif; ?>
                                        <?php 
                                        $typeColors = [
                                            'blog' => 'bg-blue-100 text-blue-700',
                                            'diary' => 'bg-pink-100 text-pink-700',
                                            'interview' => 'bg-purple-100 text-purple-700',
                                            'news' => 'bg-green-100 text-green-700',
                                            'feature' => 'bg-orange-100 text-orange-700'
                                        ];
                                        $typeLabels = ['blog' => 'ブログ', 'diary' => '日記', 'interview' => 'インタビュー', 'news' => 'ニュース', 'feature' => '特集'];
                                        ?>
                                        <span class="text-xs px-2 py-0.5 rounded <?= $typeColors[$article['article_type']] ?? 'bg-gray-100 text-gray-700' ?>">
                                            <?= $typeLabels[$article['article_type']] ?? $article['article_type'] ?>
                                        </span>
                                    </div>
                                    <p class="font-bold text-gray-800 text-sm truncate"><?= htmlspecialchars($article['title']) ?></p>
                                    <p class="text-xs text-gray-500 mt-0.5">
                                        <?= date('Y/m/d', strtotime($article['published_at'])) ?>
                                        <?php if (!empty($article['author'])): ?>
                                        ・ <?= htmlspecialchars($article['author']) ?>
                                        <?php endif; ?>
                                    </p>
                                </div>
                            </div>
                            <div class="flex gap-1 mt-2">
                                <a href="?edit=<?= $article['id'] ?>" 
                                    class="flex-1 text-center px-2 py-1.5 bg-yellow-100 text-yellow-700 rounded text-xs font-bold hover:bg-yellow-200 transition">
                                    <i class="fas fa-edit mr-1"></i>編集
                                </a>
                                <a href="../article.php?id=<?= $article['id'] ?>" target="_blank"
                                    class="flex-1 text-center px-2 py-1.5 bg-green-100 text-green-600 rounded text-xs font-bold hover:bg-green-200 transition">
                                    <i class="fas fa-external-link-alt mr-1"></i>表示
                                </a>
                                <a href="?delete=<?= $article['id'] ?>" 
                                    onclick="return confirm('本当に削除しますか？')"
                                    class="px-2 py-1.5 bg-red-100 text-red-600 rounded text-xs font-bold hover:bg-red-200 transition">
                                    <i class="fas fa-trash"></i>
                                </a>
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
        // Quill エディタ
        const quill = new Quill('#editor', {
            theme: 'snow',
            modules: {
                toolbar: [
                    [{ 'header': [1, 2, 3, false] }],
                    ['bold', 'italic', 'underline', 'strike'],
                    [{ 'color': [] }, { 'background': [] }],
                    [{ 'list': 'ordered'}, { 'list': 'bullet' }],
                    ['blockquote', 'code-block'],
                    ['link', 'image'],
                    ['clean']
                ]
            }
        });
        
        // 書き方ヘルプの表示切り替え
        const showHelpBtn = document.getElementById('show-editor-help');
        const editorHelp = document.getElementById('editor-help');
        if (showHelpBtn && editorHelp) {
            showHelpBtn.addEventListener('click', () => {
                editorHelp.classList.toggle('hidden');
            });
        }
        
        // HTMLソース切り替え
        let isHtmlMode = false;
        const toggleHtmlBtn = document.getElementById('toggle-html');
        const htmlEditor = document.getElementById('html-editor');
        const editorWrapper = document.getElementById('editor-wrapper');

        function syncQuillToHtml() {
            if (!htmlEditor) return;
            htmlEditor.value = quill.root.innerHTML;
        }

        function syncHtmlToQuill() {
            if (!htmlEditor) return;
            // QuillにHTMLを反映（管理画面限定のため dangerouslyPasteHTML を使用）
            quill.clipboard.dangerouslyPasteHTML(htmlEditor.value || '');
        }

        if (toggleHtmlBtn && htmlEditor && editorWrapper) {
            toggleHtmlBtn.addEventListener('click', () => {
                if (!isHtmlMode) {
                    syncQuillToHtml();
                    editorWrapper.classList.add('hidden');
                    htmlEditor.classList.remove('hidden');
                    toggleHtmlBtn.innerHTML = '<i class="fas fa-pen-nib mr-1"></i>リッチエディタ';
                    isHtmlMode = true;
                } else {
                    syncHtmlToQuill();
                    htmlEditor.classList.add('hidden');
                    editorWrapper.classList.remove('hidden');
                    toggleHtmlBtn.innerHTML = '<i class="fas fa-code mr-1"></i>HTMLソース';
                    isHtmlMode = false;
                }
            });
        }

        // フォーム送信時にエディタの内容を取得
        document.getElementById('article-form').addEventListener('submit', function() {
            const contentInput = document.getElementById('content-input');
            if (!contentInput) return;

            if (isHtmlMode && htmlEditor) {
                contentInput.value = htmlEditor.value;
            } else {
                contentInput.value = quill.root.innerHTML;
            }
        });
        
        // 著者プレビュー更新
        function updateAuthorPreview() {
            const select = document.getElementById('creator_id');
            const preview = document.getElementById('author-preview');
            const authorInput = document.getElementById('author-input');
            const selectedOption = select.options[select.selectedIndex];
            
            if (select.value) {
                const image = selectedOption.dataset.image;
                const name = selectedOption.dataset.name;
                
                document.getElementById('author-image').src = image ? '../' + image : '/placeholder.svg';
                document.getElementById('author-name').textContent = name;
                preview.classList.remove('hidden');
                authorInput.value = name;
            } else {
                preview.classList.add('hidden');
            }
        }
        
        // 初期表示時にプレビュー更新
        document.addEventListener('DOMContentLoaded', function() {
            updateAuthorPreview();
        });
    </script>
</body>
</html>

<?php include "includes/footer.php"; ?>
