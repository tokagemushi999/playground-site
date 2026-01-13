<?php
/**
 * クリエイターダッシュボード - 契約書
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/creator-auth.php';
require_once '../includes/site-settings.php';

$creator = requireCreatorAuth();
$db = getDB();

// サイト設定
$settings = getSiteSettings();
$siteName = $settings['site_name'] ?? 'ショップ';
$shopName = getSiteSetting($db, 'store_business_name', $siteName);

// 契約履歴を取得
$contracts = [];
try {
    $stmt = $db->prepare("
        SELECT * FROM creator_contracts 
        WHERE creator_id = ? 
        ORDER BY created_at DESC
    ");
    $stmt->execute([$creator['id']]);
    $contracts = $stmt->fetchAll();
} catch (PDOException $e) {}

// 現在有効な契約を取得
$currentContract = null;
foreach ($contracts as $contract) {
    if ($contract['status'] === 'agreed') {
        $currentContract = $contract;
        break;
    }
}

// 手数料情報
$productCommission = [
    'rate' => $creator['commission_rate'] ?? 20,
    'per_item' => $creator['commission_per_item'] ?? 0
];
$serviceCommission = [
    'rate' => $creator['service_commission_rate'] ?? 15,
    'per_item' => $creator['service_commission_per_item'] ?? 0
];

$pageTitle = '契約書';
require_once 'includes/header.php';
?>

<div class="mb-6">
    <h1 class="text-2xl font-bold text-gray-800">契約書</h1>
    <p class="text-gray-500 text-sm">契約内容と履歴の確認</p>
</div>

<!-- 現在の契約状況 -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">
    <h2 class="font-bold text-gray-800 mb-4">
        <i class="fas fa-file-contract text-green-500 mr-2"></i>現在の契約状況
    </h2>
    
    <?php if ($currentContract): ?>
    <div class="flex items-center gap-3 mb-4">
        <span class="px-3 py-1 bg-green-100 text-green-700 rounded-full font-bold">
            <i class="fas fa-check-circle mr-1"></i>契約済み
        </span>
        <span class="text-gray-500">
            バージョン <?= $currentContract['version'] ?> 
            （<?= date('Y年n月j日', strtotime($currentContract['agreed_at'])) ?>締結）
        </span>
    </div>
    <?php else: ?>
    <div class="flex items-center gap-3 mb-4">
        <span class="px-3 py-1 bg-yellow-100 text-yellow-700 rounded-full font-bold">
            <i class="fas fa-exclamation-circle mr-1"></i>未締結
        </span>
        <span class="text-gray-500">運営から契約書が送付されるまでお待ちください</span>
    </div>
    <?php endif; ?>
    
    <!-- 手数料率 -->
    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-6">
        <div class="bg-blue-50 rounded-lg p-4">
            <h3 class="font-bold text-gray-800 mb-2">
                <i class="fas fa-box text-blue-500 mr-2"></i>商品販売手数料
            </h3>
            <div class="space-y-1">
                <div class="flex justify-between">
                    <span class="text-gray-600">手数料率</span>
                    <span class="font-bold text-gray-800"><?= $productCommission['rate'] ?>%</span>
                </div>
                <?php if ($productCommission['per_item'] > 0): ?>
                <div class="flex justify-between">
                    <span class="text-gray-600">1件あたり</span>
                    <span class="font-bold text-gray-800">¥<?= number_format($productCommission['per_item']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="bg-purple-50 rounded-lg p-4">
            <h3 class="font-bold text-gray-800 mb-2">
                <i class="fas fa-paint-brush text-purple-500 mr-2"></i>サービス販売手数料
            </h3>
            <div class="space-y-1">
                <div class="flex justify-between">
                    <span class="text-gray-600">手数料率</span>
                    <span class="font-bold text-gray-800"><?= $serviceCommission['rate'] ?>%</span>
                </div>
                <?php if ($serviceCommission['per_item'] > 0): ?>
                <div class="flex justify-between">
                    <span class="text-gray-600">1件あたり</span>
                    <span class="font-bold text-gray-800">¥<?= number_format($serviceCommission['per_item']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <!-- 源泉徴収 -->
    <div class="mt-4 p-4 bg-gray-50 rounded-lg">
        <div class="flex items-center gap-3">
            <span class="text-gray-600">源泉徴収</span>
            <?php if ($creator['withholding_tax_required'] ?? 1): ?>
            <span class="px-2 py-1 bg-orange-100 text-orange-700 rounded text-sm font-bold">対象</span>
            <span class="text-sm text-gray-500">（個人事業主のため10.21%が控除されます）</span>
            <?php else: ?>
            <span class="px-2 py-1 bg-gray-100 text-gray-600 rounded text-sm font-bold">対象外</span>
            <?php endif; ?>
        </div>
    </div>
</div>

<!-- 契約履歴 -->
<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
    <div class="p-4 border-b">
        <h2 class="font-bold text-gray-800">
            <i class="fas fa-history text-gray-500 mr-2"></i>契約履歴
        </h2>
    </div>
    
    <?php if (empty($contracts)): ?>
    <div class="p-8 text-center text-gray-400">
        <i class="fas fa-file-alt text-4xl mb-4"></i>
        <p>契約履歴はありません</p>
    </div>
    <?php else: ?>
    <div class="divide-y divide-gray-100">
        <?php foreach ($contracts as $contract): ?>
        <div class="p-4 hover:bg-gray-50">
            <div class="flex flex-col md:flex-row md:items-center justify-between gap-2">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <span class="font-bold text-gray-800">バージョン <?= $contract['version'] ?></span>
                        <?php if ($contract['status'] === 'agreed'): ?>
                        <span class="px-2 py-0.5 bg-green-100 text-green-700 rounded text-xs font-bold">締結済</span>
                        <?php elseif ($contract['status'] === 'pending'): ?>
                        <span class="px-2 py-0.5 bg-yellow-100 text-yellow-700 rounded text-xs font-bold">確認待ち</span>
                        <?php else: ?>
                        <span class="px-2 py-0.5 bg-gray-100 text-gray-600 rounded text-xs font-bold"><?= $contract['status'] ?></span>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($contract['update_reason']): ?>
                    <p class="text-sm text-gray-500">
                        <i class="fas fa-info-circle mr-1"></i><?= htmlspecialchars($contract['update_reason']) ?>
                    </p>
                    <?php endif; ?>
                </div>
                
                <div class="text-sm text-gray-500">
                    <?php if ($contract['status'] === 'agreed'): ?>
                    <div>締結日: <?= date('Y/m/d H:i', strtotime($contract['agreed_at'])) ?></div>
                    <?php if ($contract['agreed_name']): ?>
                    <div>署名: <?= htmlspecialchars($contract['agreed_name']) ?></div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div>作成日: <?= date('Y/m/d H:i', strtotime($contract['created_at'])) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if ($contract['status'] === 'pending' && $contract['token']): ?>
            <div class="mt-3 pt-3 border-t">
                <a href="/contract-agree.php?token=<?= htmlspecialchars($contract['token']) ?>" 
                   class="inline-flex items-center px-4 py-2 bg-green-500 text-white rounded-lg hover:bg-green-600 transition">
                    <i class="fas fa-file-signature mr-2"></i>契約書を確認・同意する
                </a>
            </div>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- 注意事項 -->
<div class="bg-yellow-50 rounded-lg p-4 mt-6">
    <p class="text-sm text-yellow-800">
        <i class="fas fa-info-circle mr-1"></i>
        <strong>契約内容について:</strong>
        手数料率の変更や契約の更新については、運営から別途ご連絡いたします。
        ご不明点がありましたらお問い合わせください。
    </p>
</div>

<?php require_once 'includes/footer.php'; ?>
