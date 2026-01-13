<?php
/**
 * 見積もり依頼フォーム（ログイン必須）
 */
session_start();
require_once '../../includes/db.php';
require_once '../../includes/site-settings.php';
require_once '../../includes/member-auth.php';
require_once '../../includes/transactions.php';
require_once '../../includes/csrf.php';

$db = getDB();

// サービスID取得
$serviceId = (int)($_GET['service'] ?? $_POST['service_id'] ?? 0);
if (!$serviceId) {
    header('Location: /store/services/');
    exit;
}

// ログイン必須
if (!isLoggedIn()) {
    $_SESSION['redirect_after_login'] = '/store/transactions/request.php?service=' . $serviceId;
    header('Location: /store/login.php?message=見積もり依頼にはログインが必要です');
    exit;
}

// サービス情報取得
$service = getService($serviceId);
if (!$service || $service['status'] !== 'active') {
    header('Location: /store/services/');
    exit;
}

// クリエイター情報
$stmt = $db->prepare("SELECT * FROM creators WHERE id = ?");
$stmt->execute([$service['creator_id']]);
$creator = $stmt->fetch();

// ログインユーザー（必須なので必ず存在）
$member = getCurrentMember();

$error = '';
$success = false;

// フォーム送信処理
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
        $error = '不正なリクエストです。';
    } else {
        $requestTitle = trim($_POST['request_title'] ?? '');
        $requestDetail = trim($_POST['request_detail'] ?? '');
        $requestBudget = !empty($_POST['request_budget']) ? (int)$_POST['request_budget'] : null;
        $requestDeadline = !empty($_POST['request_deadline']) ? $_POST['request_deadline'] : null;
        
        // バリデーション
        if (empty($requestTitle)) {
            $error = '依頼タイトルを入力してください。';
        } elseif (empty($requestDetail)) {
            $error = '依頼内容を入力してください。';
        } elseif (strlen($requestDetail) < 20) {
            $error = '依頼内容は20文字以上で入力してください。';
        } else {
            try {
                // 取引作成（ログインユーザーのみ）
                $result = createTransaction([
                    'service_id' => $serviceId,
                    'creator_id' => $service['creator_id'],
                    'member_id' => $member['id'],
                    'guest_email' => null,
                    'guest_name' => null,
                    'request_title' => $requestTitle,
                    'request_detail' => $requestDetail,
                    'request_budget' => $requestBudget,
                    'request_deadline' => $requestDeadline
                ]);
                
                // 初回メッセージとして依頼内容を追加
                $messageContent = "【見積もり依頼】\n\n";
                $messageContent .= "■ 依頼タイトル: {$requestTitle}\n\n";
                $messageContent .= "■ 依頼内容:\n{$requestDetail}\n\n";
                if ($requestBudget) {
                    $messageContent .= "■ 希望予算: ¥" . number_format($requestBudget) . "\n";
                }
                if ($requestDeadline) {
                    $messageContent .= "■ 希望納期: {$requestDeadline}\n";
                }
                
                addTransactionMessage(
                    $result['id'],
                    'customer',
                    $member['id'],
                    $member['name'],
                    $messageContent
                );
                
                // ファイルアップロード処理
                if (!empty($_FILES['attachments']['name'][0])) {
                    $uploadDir = '../../uploads/transactions/' . $result['id'] . '/';
                    if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);
                    
                    // 最初のメッセージIDを取得
                    $stmt = $db->prepare("SELECT id FROM service_messages WHERE transaction_id = ? ORDER BY id DESC LIMIT 1");
                    $stmt->execute([$result['id']]);
                    $messageId = $stmt->fetchColumn();
                    
                    foreach ($_FILES['attachments']['name'] as $i => $name) {
                        if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
                            $tmpName = $_FILES['attachments']['tmp_name'][$i];
                            $originalName = basename($name);
                            $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
                            $storedName = time() . '_' . uniqid() . '.' . $ext;
                            $filePath = $uploadDir . $storedName;
                            
                            if (move_uploaded_file($tmpName, $filePath)) {
                                $mimeType = $_FILES['attachments']['type'][$i];
                                $fileSize = $_FILES['attachments']['size'][$i];
                                
                                addTransactionAttachment($messageId, $result['id'], [
                                    'original_name' => $originalName,
                                    'stored_name' => $storedName,
                                    'file_path' => 'uploads/transactions/' . $result['id'] . '/' . $storedName,
                                    'file_size' => $fileSize,
                                    'mime_type' => $mimeType,
                                    'file_type' => detectFileType($mimeType)
                                ]);
                            }
                        }
                    }
                }
                
                // クリエイターにメール通知
                sendTransactionEmail($result['id'], 'inquiry_received', 'creator');
                
                // 運営にも通知
                sendTransactionEmail($result['id'], 'inquiry_received', 'admin');
                
                // 成功ページにリダイレクト
                header('Location: /store/transactions/request-complete.php?code=' . $result['code']);
                exit;
                
            } catch (PDOException $e) {
                $error = 'エラーが発生しました。しばらく経ってから再度お試しください。';
            }
        }
    }
}

$pageTitle = '見積もり依頼 - ' . $service['title'];
require_once '../includes/header.php';
?>

<div class="max-w-3xl mx-auto px-4 py-8">
    <!-- パンくず -->
    <nav class="text-sm mb-6">
        <ol class="flex items-center gap-2 text-gray-500">
            <li><a href="/store/services/" class="hover:text-green-600">サービス</a></li>
            <li>&gt;</li>
            <li><a href="/store/services/<?= $service['id'] ?>" class="hover:text-green-600"><?= htmlspecialchars(mb_substr($service['title'], 0, 20)) ?>...</a></li>
            <li>&gt;</li>
            <li class="text-gray-800">見積もり依頼</li>
        </ol>
    </nav>
    
    <h1 class="text-2xl font-bold text-gray-800 mb-6">
        <i class="fas fa-file-invoice text-green-500 mr-2"></i>見積もり依頼
    </h1>
    
    <?php if ($error): ?>
    <div class="bg-red-50 border border-red-200 text-red-600 p-4 rounded-lg mb-6">
        <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
    </div>
    <?php endif; ?>
    
    <!-- サービス情報 -->
    <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-4 mb-6">
        <div class="flex items-center gap-4">
            <div class="w-20 h-20 bg-gray-100 rounded-lg overflow-hidden flex-shrink-0">
                <?php if (!empty($service['thumbnail_image'])): ?>
                <img src="/<?= htmlspecialchars($service['thumbnail_image']) ?>" class="w-full h-full object-cover">
                <?php else: ?>
                <div class="w-full h-full flex items-center justify-center">
                    <i class="fas fa-paint-brush text-gray-300 text-2xl"></i>
                </div>
                <?php endif; ?>
            </div>
            <div>
                <h2 class="font-bold text-gray-800"><?= htmlspecialchars($service['title']) ?></h2>
                <p class="text-sm text-gray-500"><?= htmlspecialchars($creator['name'] ?? '') ?></p>
                <p class="text-green-600 font-bold mt-1">¥<?= number_format($service['base_price']) ?>〜</p>
            </div>
        </div>
    </div>
    
    <!-- 依頼フォーム -->
    <form method="POST" enctype="multipart/form-data" class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
        <input type="hidden" name="csrf_token" value="<?= generateCsrfToken() ?>">
        <input type="hidden" name="service_id" value="<?= $serviceId ?>">
        
        <!-- ログインユーザー情報 -->
        <div class="bg-green-50 border border-green-200 rounded-lg p-4 mb-6">
            <p class="text-sm text-green-700">
                <i class="fas fa-user-check mr-1"></i>
                <strong><?= htmlspecialchars($member['name']) ?></strong> としてログイン中
            </p>
        </div>
        
        <div class="space-y-6">
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">依頼タイトル <span class="text-red-500">*</span></label>
                <input type="text" name="request_title" required
                       value="<?= htmlspecialchars($_POST['request_title'] ?? '') ?>"
                       placeholder="例: YouTube動画のオープニングアニメーション制作"
                       class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-400 outline-none">
            </div>
            
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">依頼内容 <span class="text-red-500">*</span></label>
                <textarea name="request_detail" rows="8" required
                          placeholder="制作してほしい内容を詳しく教えてください。&#10;&#10;例:&#10;・用途（YouTube、SNS、イベントなど）&#10;・尺の長さ&#10;・イメージ（参考動画URLなど）&#10;・使用する素材の有無&#10;・その他ご要望"
                          class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-400 outline-none"><?= htmlspecialchars($_POST['request_detail'] ?? '') ?></textarea>
            </div>
            
            <div class="grid md:grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">希望予算（任意）</label>
                    <div class="relative">
                        <span class="absolute left-3 top-1/2 -translate-y-1/2 text-gray-500">¥</span>
                        <input type="number" name="request_budget" min="0" step="1000"
                               value="<?= htmlspecialchars($_POST['request_budget'] ?? '') ?>"
                               placeholder="50000"
                               class="w-full pl-8 pr-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-400 outline-none">
                    </div>
                    <p class="text-xs text-gray-500 mt-1">目安としてお伝えください</p>
                </div>
                
                <div>
                    <label class="block text-sm font-bold text-gray-700 mb-1">希望納期（任意）</label>
                    <input type="date" name="request_deadline"
                           value="<?= htmlspecialchars($_POST['request_deadline'] ?? '') ?>"
                           min="<?= date('Y-m-d', strtotime('+7 days')) ?>"
                           class="w-full px-4 py-2 border rounded-lg focus:ring-2 focus:ring-green-400 outline-none">
                </div>
            </div>
            
            <div>
                <label class="block text-sm font-bold text-gray-700 mb-1">参考資料（任意）</label>
                <div class="border-2 border-dashed border-gray-300 rounded-lg p-6 text-center hover:border-green-400 transition">
                    <input type="file" name="attachments[]" multiple id="fileInput" class="hidden"
                           accept="image/*,.pdf,.doc,.docx,.zip,.psd,.ai">
                    <label for="fileInput" class="cursor-pointer">
                        <i class="fas fa-cloud-upload-alt text-4xl text-gray-400 mb-2"></i>
                        <p class="text-gray-600">クリックしてファイルを選択</p>
                        <p class="text-xs text-gray-500 mt-1">画像、PDF、ドキュメント、PSD、AIファイルなど（最大10MB）</p>
                    </label>
                </div>
                <div id="fileList" class="mt-2 space-y-1"></div>
            </div>
        </div>
        
        <!-- 注意事項 -->
        <div class="mt-6 p-4 bg-gray-50 rounded-lg">
            <h4 class="font-bold text-gray-700 mb-2"><i class="fas fa-info-circle text-blue-500 mr-1"></i>ご利用の流れ</h4>
            <ol class="text-sm text-gray-600 space-y-1 list-decimal list-inside">
                <li>見積もり依頼を送信</li>
                <li>クリエイターから見積もりが届きます</li>
                <li>内容を確認し、承諾または修正依頼</li>
                <li>見積もり承諾後、決済を行います</li>
                <li>決済完了後、制作開始</li>
                <li>メッセージでやり取りしながら完成へ</li>
            </ol>
        </div>
        
        <div class="mt-6">
            <button type="submit" class="w-full py-4 bg-green-500 text-white text-lg font-bold rounded-lg hover:bg-green-600 transition">
                <i class="fas fa-paper-plane mr-2"></i>見積もり依頼を送信
            </button>
        </div>
    </form>
</div>

<script>
document.getElementById('fileInput').addEventListener('change', function() {
    const list = document.getElementById('fileList');
    list.innerHTML = '';
    
    for (const file of this.files) {
        const div = document.createElement('div');
        div.className = 'flex items-center gap-2 text-sm text-gray-600 bg-white p-2 rounded border';
        div.innerHTML = `
            <i class="fas fa-file"></i>
            <span class="truncate">${file.name}</span>
            <span class="text-gray-400">(${(file.size / 1024 / 1024).toFixed(2)}MB)</span>
        `;
        list.appendChild(div);
    }
});
</script>

<?php require_once '../includes/footer.php'; ?>
