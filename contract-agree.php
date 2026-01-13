<?php
/**
 * 契約書同意ページ（クリエイター向け）
 */
require_once 'includes/db.php';
require_once 'includes/site-settings.php';
require_once 'includes/google-drive.php';
require_once 'includes/document-template.php';

$db = getDB();
$gdrive = getGoogleDrive($db);
$error = '';
$success = false;
$contract = null;
$creator = null;

// サイト設定
$settings = getSiteSettings();
$siteName = $settings['site_name'] ?? 'ショップ';
$shopName = getSiteSetting($db, 'store_business_name', $siteName);

// トークン確認
$token = $_GET['token'] ?? '';

if (empty($token)) {
    $error = '無効なリンクです。';
} else {
    // 契約情報を取得
    $stmt = $db->prepare("
        SELECT cc.*, c.name as creator_name, c.email as creator_email,
               c.commission_rate, c.commission_per_item,
               c.service_commission_rate, c.service_commission_per_item
        FROM creator_contracts cc
        INNER JOIN creators c ON cc.creator_id = c.id
        WHERE cc.token = ?
    ");
    $stmt->execute([$token]);
    $contract = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$contract) {
        $error = '契約書が見つかりません。リンクの有効期限が切れている可能性があります。';
    } elseif ($contract['status'] === 'agreed') {
        $success = true;
    }
}

// 同意処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['agree']) && $contract && $contract['status'] !== 'agreed') {
    $agreedName = trim($_POST['agreed_name'] ?? '');
    
    if (empty($agreedName)) {
        $error = 'お名前を入力してください。';
    } else {
        try {
            // 同意を記録
            $stmt = $db->prepare("
                UPDATE creator_contracts SET 
                    status = 'agreed',
                    agreed_at = NOW(),
                    agreed_name = ?,
                    agreed_ip = ?,
                    agreed_user_agent = ?
                WHERE id = ?
            ");
            $result = $stmt->execute([
                $agreedName,
                $_SERVER['REMOTE_ADDR'] ?? '',
                $_SERVER['HTTP_USER_AGENT'] ?? '',
                $contract['id']
            ]);
            
            if (!$result || $stmt->rowCount() === 0) {
                throw new Exception('契約ステータスの更新に失敗しました。');
            }
            
            $success = true;
            
            // 契約情報を再取得（creator_nameも含めて）
            $stmt = $db->prepare("
                SELECT cc.*, c.name as creator_name, c.email as creator_email,
                       c.commission_rate, c.commission_per_item,
                       c.service_commission_rate, c.service_commission_per_item
                FROM creator_contracts cc
                INNER JOIN creators c ON cc.creator_id = c.id
                WHERE cc.id = ?
            ");
            $stmt->execute([$contract['id']]);
            $contract = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // 契約完了メールを送信
            if (!empty($contract['creator_email'])) {
                require_once 'includes/mail.php';
                $contractNumber = str_pad($contract['id'], 6, '0', STR_PAD_LEFT);
                
                $mailBody = "{$contract['creator_name']} 様\n\n";
                $mailBody .= "契約書への同意が完了しました。\n\n";
                $mailBody .= "【契約情報】\n";
                $mailBody .= "契約番号: #{$contractNumber}\n";
                $mailBody .= "同意日時: " . date('Y年n月j日 H:i') . "\n";
                $mailBody .= "署名: {$agreedName}\n\n";
                $mailBody .= "今後ともよろしくお願いいたします。\n\n";
                $mailBody .= "--------------------\n";
                $mailBody .= "{$shopName}\n";
                
                sendMail(
                    $contract['creator_email'],
                    "【{$shopName}】契約締結完了のお知らせ",
                    $mailBody
                );
            }
            
            // 契約書テンプレートを取得（Google Drive保存用）
            $templateContentForSave = getSiteSetting($db, 'contract_template_content', '');
            $templateContentForSave = str_replace(
                ['{shop_name}', '{creator_name}', '{commission_rate}', '{commission_per_item}', '{date}'],
                [
                    $shopName,
                    $contract['creator_name'] ?? '',
                    $contract['commission_rate'] ?? '20',
                    $contract['commission_per_item'] ?? '0',
                    date('Y年n月j日')
                ],
                $templateContentForSave
            );
            
            // Google Driveに契約書を自動保存
            if ($gdrive->isConnected()) {
                try {
                    $folderId = $gdrive->getMonthlyFolder('contracts', (int)date('Y'), (int)date('n'));
                    if ($folderId) {
                        $filename = sprintf('契約書_%s_%s.html', 
                            $contract['creator_name'] ?? 'unknown',
                            date('Y-m-d'));
                        
                        // きれいな契約書HTMLを生成
                        $contractHtml = generateContractHtml($contract, $templateContentForSave, $shopName, $agreedName);
                        
                        $uploadResult = $gdrive->uploadPdfContent($contractHtml, $filename, $folderId);
                        
                        if ($uploadResult) {
                            // Google DriveファイルIDを保存
                            $stmt = $db->prepare("UPDATE creator_contracts SET gdrive_file_id = ? WHERE id = ?");
                            $stmt->execute([$uploadResult['id'], $contract['id']]);
                            
                            // 保存履歴を記録
                            $stmt = $db->prepare("INSERT INTO document_archives 
                                (document_type, reference_id, reference_type, filename, gdrive_file_id)
                                VALUES ('contract', ?, 'creator_contract', ?, ?)");
                            $stmt->execute([$contract['id'], $filename, $uploadResult['id']]);
                        }
                    }
                } catch (Exception $e) {
                    // Google Drive保存エラーは無視（自動保存の失敗で処理を止めない）
                    error_log('Google Drive save error: ' . $e->getMessage());
                }
            }
        } catch (Exception $e) {
            $error = 'エラーが発生しました: ' . $e->getMessage();
            $success = false;
        }
    }
}

// 契約書テンプレートを取得
$templateTitle = getSiteSetting($db, 'contract_template_title', '販売委託契約書');
$templateContent = getSiteSetting($db, 'contract_template_content', '');

// 変数置換
if ($contract) {
    $templateContent = str_replace(
        [
            '{shop_name}', 
            '{creator_name}', 
            '{commission_rate}', 
            '{commission_per_item}',
            '{service_commission_rate}',
            '{service_commission_per_item}',
            '{contract_date}',
            '{version}',
            '{date}'
        ],
        [
            $shopName,
            $contract['creator_name'] ?? '',
            $contract['commission_rate'] ?? '20',
            $contract['commission_per_item'] ?? '0',
            $contract['service_commission_rate'] ?? '15',
            $contract['service_commission_per_item'] ?? '0',
            date('Y年n月j日'),
            $contract['version'] ?? '1',
            date('Y年n月j日')
        ],
        $templateContent
    );
}

// Markdownを簡易HTML変換
function simpleMarkdown($text) {
    // 見出し
    $text = preg_replace('/^### (.+)$/m', '<h4 class="text-lg font-bold mt-6 mb-2">$1</h4>', $text);
    $text = preg_replace('/^## (.+)$/m', '<h3 class="text-xl font-bold mt-8 mb-3 border-b pb-2">$1</h3>', $text);
    $text = preg_replace('/^# (.+)$/m', '<h2 class="text-2xl font-bold mb-4">$1</h2>', $text);
    
    // リスト
    $text = preg_replace('/^\d+\. (.+)$/m', '<li class="ml-6 mb-1">$1</li>', $text);
    
    // 段落
    $text = preg_replace('/\n\n/', '</p><p class="mb-4">', $text);
    
    return '<p class="mb-4">' . $text . '</p>';
}
?>
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($templateTitle) ?> - <?= htmlspecialchars($shopName) ?></title>
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="max-w-3xl mx-auto py-8 px-4">
        <!-- ヘッダー -->
        <div class="text-center mb-8">
            <h1 class="text-2xl font-bold text-gray-800"><?= htmlspecialchars($shopName) ?></h1>
            <p class="text-gray-500"><?= htmlspecialchars($templateTitle) ?></p>
        </div>
        
        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 rounded-xl p-6 text-center">
            <i class="fas fa-exclamation-triangle text-red-500 text-4xl mb-4"></i>
            <p class="text-red-700"><?= htmlspecialchars($error) ?></p>
        </div>
        
        <?php elseif ($success): ?>
        <div class="bg-green-50 border border-green-200 rounded-xl p-8 text-center">
            <i class="fas fa-check-circle text-green-500 text-5xl mb-4"></i>
            <h2 class="text-2xl font-bold text-green-700 mb-2">契約が完了しました</h2>
            <p class="text-green-600 mb-4">
                ご同意いただきありがとうございます。<br>
                この契約書の内容は両者で保管されます。
            </p>
            <div class="bg-white rounded-lg p-4 text-left text-sm text-gray-600 max-w-md mx-auto">
                <p><strong>契約者:</strong> <?= htmlspecialchars($contract['agreed_name'] ?? $contract['creator_name']) ?></p>
                <p><strong>同意日時:</strong> <?= date('Y年n月j日 H:i', strtotime($contract['agreed_at'])) ?></p>
                <p><strong>契約番号:</strong> #<?= str_pad($contract['id'], 6, '0', STR_PAD_LEFT) ?></p>
            </div>
        </div>
        
        <?php else: ?>
        <!-- 契約書本文 -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-200 p-8 mb-6">
            <div class="prose max-w-none">
                <?= simpleMarkdown(htmlspecialchars($templateContent)) ?>
            </div>
        </div>
        
        <!-- 同意フォーム -->
        <div class="bg-orange-50 border border-orange-200 rounded-xl p-6">
            <h3 class="font-bold text-gray-800 mb-4">
                <i class="fas fa-signature text-orange-500 mr-2"></i>契約への同意
            </h3>
            
            <form method="POST">
                <div class="mb-4">
                    <label class="block text-sm font-bold text-gray-700 mb-2">
                        お名前（署名として記録されます）
                    </label>
                    <input type="text" name="agreed_name" required
                           value="<?= htmlspecialchars($contract['creator_name'] ?? '') ?>"
                           class="w-full px-4 py-3 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-400 outline-none text-lg">
                </div>
                
                <div class="mb-6 text-sm text-gray-600">
                    <p class="flex items-start gap-2">
                        <i class="fas fa-info-circle text-blue-500 mt-1"></i>
                        <span>
                            「同意する」をクリックすると、上記契約内容に同意したことになります。
                            同意日時、IPアドレス、ブラウザ情報が記録されます。
                        </span>
                    </p>
                </div>
                
                <button type="submit" name="agree" value="1"
                        onclick="return confirm('契約内容に同意しますか？この操作は取り消せません。')"
                        class="w-full px-6 py-4 bg-orange-500 text-white rounded-lg font-bold text-lg hover:bg-orange-600 transition">
                    <i class="fas fa-check mr-2"></i>上記内容に同意する
                </button>
            </form>
        </div>
        <?php endif; ?>
        
        <!-- フッター -->
        <div class="text-center mt-8 text-sm text-gray-500">
            <p>&copy; <?= date('Y') ?> <?= htmlspecialchars($shopName) ?></p>
        </div>
    </div>
</body>
</html>
