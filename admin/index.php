<?php
/**
 * 管理画面ダッシュボード
 */

require_once '../includes/db.php';
require_once '../includes/auth.php';
requireAuth();

$db = getDB();

// 統計情報を取得
$stats = [
    'creators' => $db->query("SELECT COUNT(*) FROM creators WHERE is_active = 1")->fetchColumn(),
    'works' => $db->query("SELECT COUNT(*) FROM works WHERE is_active = 1")->fetchColumn(),
    'articles' => $db->query("SELECT COUNT(*) FROM articles WHERE is_active = 1")->fetchColumn(),
    'inquiries_new' => $db->query("SELECT COUNT(*) FROM inquiries WHERE status = 'new'")->fetchColumn(),
];

// 最新の問い合わせ
$recentInquiries = $db->query("SELECT * FROM inquiries ORDER BY created_at DESC LIMIT 5")->fetchAll();
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ダッシュボード | 管理画面</title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Zen+Maru+Gothic:wght@400;500;700&display=swap" rel="stylesheet">
    <style>
        body { font-family: 'Zen Maru Gothic', sans-serif; }
    </style>
</head>
<body class="bg-gray-50">
    <?php include 'includes/sidebar.php'; ?>
    
    <!-- Main Content -->
    <main class="lg:ml-64 p-8 pt-20 lg:pt-8">
        <div class="mb-8">
            <h2 class="text-2xl font-bold text-gray-800">ダッシュボード</h2>
            <p class="text-gray-500">ようこそ、<?= htmlspecialchars($_SESSION['admin_name'] ?? '管理者') ?>さん</p>
        </div>
        
        <!-- Stats -->
        <div class="grid grid-cols-2 md:grid-cols-4 gap-4 md:gap-6 mb-10">
            <div class="bg-white rounded-xl p-4 md:p-6 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs md:text-sm">メンバー</p>
                        <p class="text-2xl md:text-3xl font-bold text-gray-800"><?= $stats['creators'] ?></p>
                    </div>
                    <div class="w-10 h-10 md:w-12 md:h-12 bg-blue-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-users text-blue-500"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl p-4 md:p-6 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs md:text-sm">作品数</p>
                        <p class="text-2xl md:text-3xl font-bold text-gray-800"><?= $stats['works'] ?></p>
                    </div>
                    <div class="w-10 h-10 md:w-12 md:h-12 bg-green-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-images text-green-500"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl p-4 md:p-6 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs md:text-sm">記事数</p>
                        <p class="text-2xl md:text-3xl font-bold text-gray-800"><?= $stats['articles'] ?></p>
                    </div>
                    <div class="w-10 h-10 md:w-12 md:h-12 bg-purple-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-newspaper text-purple-500"></i>
                    </div>
                </div>
            </div>
            
            <div class="bg-white rounded-xl p-4 md:p-6 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between">
                    <div>
                        <p class="text-gray-500 text-xs md:text-sm">新規問い合わせ</p>
                        <p class="text-2xl md:text-3xl font-bold text-gray-800"><?= $stats['inquiries_new'] ?></p>
                    </div>
                    <div class="w-10 h-10 md:w-12 md:h-12 bg-red-100 rounded-lg flex items-center justify-center">
                        <i class="fas fa-envelope text-red-500"></i>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Recent Inquiries -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100">
            <div class="px-6 py-4 border-b border-gray-100">
                <h3 class="font-bold text-gray-800">最新の問い合わせ</h3>
            </div>
            <div class="divide-y divide-gray-100">
                <?php if (empty($recentInquiries)): ?>
                <div class="px-6 py-8 text-center text-gray-400">
                    まだ問い合わせがありません
                </div>
                <?php else: ?>
                <?php foreach ($recentInquiries as $inquiry): ?>
                <div class="px-6 py-4 flex items-center justify-between hover:bg-gray-50">
                    <div>
                        <p class="font-bold text-gray-800"><?= htmlspecialchars($inquiry['name'] ?: '名前なし') ?></p>
                        <p class="text-sm text-gray-500"><?= htmlspecialchars($inquiry['genre']) ?> / <?= htmlspecialchars($inquiry['budget'] ?: '未定') ?></p>
                    </div>
                    <div class="text-right">
                        <span class="inline-block px-3 py-1 rounded-full text-xs font-bold 
                            <?= $inquiry['status'] === 'new' ? 'bg-red-100 text-red-600' : 
                               ($inquiry['status'] === 'in_progress' ? 'bg-yellow-100 text-yellow-600' : 'bg-green-100 text-green-600') ?>">
                            <?= $inquiry['status'] === 'new' ? '新規' : 
                               ($inquiry['status'] === 'in_progress' ? '対応中' : '完了') ?>
                        </span>
                        <p class="text-xs text-gray-400 mt-1"><?= date('Y/m/d H:i', strtotime($inquiry['created_at'])) ?></p>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div class="px-6 py-4 border-t border-gray-100">
                <a href="inquiries.php" class="text-yellow-600 hover:text-yellow-700 text-sm font-bold">すべて見る →</a>
            </div>
        </div>
    </main>
</body>
</html>