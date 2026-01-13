<?php
/**
 * Google Drive OAuth コールバック
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/google-drive.php';
requireAuth();

$db = getDB();
$gdrive = getGoogleDrive($db);

$error = '';

if (isset($_GET['code'])) {
    if ($gdrive->handleCallback($_GET['code'])) {
        // フォルダ構造を自動作成
        try {
            $gdrive->setupFolderStructure();
        } catch (Exception $e) {
            // フォルダ作成失敗は後で手動で行える
        }
        
        header('Location: google-drive.php?connected=1');
        exit;
    } else {
        $error = '認証に失敗しました。もう一度お試しください。';
    }
} elseif (isset($_GET['error'])) {
    $error = 'アクセスが拒否されました: ' . ($_GET['error_description'] ?? $_GET['error']);
} else {
    $error = '不正なリクエストです。';
}

$pageTitle = "Google Drive連携 - エラー";
include "includes/header.php";
?>
        <div class="max-w-lg mx-auto mt-12">
            <div class="bg-red-50 border border-red-200 rounded-xl p-6 text-center">
                <i class="fas fa-exclamation-triangle text-red-500 text-4xl mb-4"></i>
                <h2 class="text-xl font-bold text-red-700 mb-2">接続エラー</h2>
                <p class="text-red-600 mb-6"><?= htmlspecialchars($error) ?></p>
                <a href="google-drive.php" class="inline-block px-6 py-2 bg-gray-500 text-white rounded-lg hover:bg-gray-600 transition">
                    <i class="fas fa-arrow-left mr-2"></i>設定画面に戻る
                </a>
            </div>
        </div>
    </main>
<?php include "includes/footer.php"; ?>
