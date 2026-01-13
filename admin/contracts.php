<?php
/**
 * クリエイター契約書管理
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/site-settings.php';
require_once '../includes/mail.php';
require_once '../includes/google-drive.php';
requireAuth();

$db = getDB();
$gdrive = getGoogleDrive($db);
$message = '';
$error = '';
$generatedLink = '';

// サイト設定
$settings = getSiteSettings();
$siteName = $settings['site_name'] ?? 'ショップ';
$shopName = getSiteSetting($db, 'store_business_name', $siteName);
$baseUrl = getBaseUrl();

// 契約書テンプレート保存
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_template'])) {
    $templateTitle = trim($_POST['template_title'] ?? '');
    $templateContent = $_POST['template_content'] ?? '';
    
    $stmt = $db->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
    $stmt->execute(['contract_template_title', $templateTitle]);
    $stmt->execute(['contract_template_content', $templateContent]);
    
    $message = '契約書テンプレートを保存しました。';
}

// リンク生成（メール送信なし）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_link'])) {
    $creatorId = (int)$_POST['creator_id'];
    $updateReason = trim($_POST['update_reason'] ?? '');
    $isUpdate = isset($_POST['is_update']) && $_POST['is_update'] === '1';
    
    try {
        $stmt = $db->prepare("SELECT * FROM creators WHERE id = ?");
        $stmt->execute([$creatorId]);
        $creator = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$creator) {
            throw new Exception('クリエイターが見つかりません');
        }
        
        // 現在のバージョンを取得
        $stmt = $db->prepare("SELECT MAX(version) as max_version FROM creator_contracts WHERE creator_id = ?");
        $stmt->execute([$creatorId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $newVersion = ($result['max_version'] ?? 0) + 1;
        
        // 前回の契約情報を取得（更新の場合）
        $previousRate = null;
        $previousPerItem = null;
        if ($isUpdate) {
            $stmt = $db->prepare("SELECT new_commission_rate, new_commission_per_item FROM creator_contracts WHERE creator_id = ? AND status = 'agreed' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$creatorId]);
            $prevContract = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($prevContract) {
                $previousRate = $prevContract['new_commission_rate'];
                $previousPerItem = $prevContract['new_commission_per_item'];
            } else {
                // 初回契約の場合はクリエイターの現在の設定を使用
                $previousRate = $creator['commission_rate'];
                $previousPerItem = $creator['commission_per_item'];
            }
        }
        
        // 契約トークンを生成
        $token = bin2hex(random_bytes(32));
        
        // 契約レコードを作成
        $stmt = $db->prepare("
            INSERT INTO creator_contracts (
                creator_id, token, version, status, update_reason,
                previous_commission_rate, previous_commission_per_item,
                new_commission_rate, new_commission_per_item, created_at
            ) VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $creatorId, 
            $token, 
            $newVersion, 
            $updateReason ?: null,
            $previousRate,
            $previousPerItem,
            $creator['commission_rate'],
            $creator['commission_per_item']
        ]);
        
        $generatedLink = $baseUrl . '/contract-agree.php?token=' . $token;
        $versionLabel = $newVersion > 1 ? "（v{$newVersion}）" : '';
        $message = "{$creator['name']}さんの契約書リンク{$versionLabel}を生成しました。";
        
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// 契約書送信（メール）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_contract'])) {
    $creatorId = (int)$_POST['creator_id'];
    $updateReason = trim($_POST['update_reason'] ?? '');
    $isUpdate = isset($_POST['is_update']) && $_POST['is_update'] === '1';
    
    try {
        $stmt = $db->prepare("SELECT * FROM creators WHERE id = ?");
        $stmt->execute([$creatorId]);
        $creator = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$creator) {
            throw new Exception('クリエイターが見つかりません');
        }
        
        if (empty($creator['email'])) {
            throw new Exception('クリエイターのメールアドレスが登録されていません');
        }
        
        // 現在のバージョンを取得
        $stmt = $db->prepare("SELECT MAX(version) as max_version FROM creator_contracts WHERE creator_id = ?");
        $stmt->execute([$creatorId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        $newVersion = ($result['max_version'] ?? 0) + 1;
        
        // 前回の契約情報を取得（更新の場合）
        $previousRate = null;
        $previousPerItem = null;
        if ($isUpdate) {
            $stmt = $db->prepare("SELECT new_commission_rate, new_commission_per_item FROM creator_contracts WHERE creator_id = ? AND status = 'agreed' ORDER BY id DESC LIMIT 1");
            $stmt->execute([$creatorId]);
            $prevContract = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($prevContract) {
                $previousRate = $prevContract['new_commission_rate'];
                $previousPerItem = $prevContract['new_commission_per_item'];
            } else {
                $previousRate = $creator['commission_rate'];
                $previousPerItem = $creator['commission_per_item'];
            }
        }
        
        // 契約トークンを生成
        $token = bin2hex(random_bytes(32));
        
        // 契約レコードを作成
        $stmt = $db->prepare("
            INSERT INTO creator_contracts (
                creator_id, token, version, status, update_reason,
                previous_commission_rate, previous_commission_per_item,
                new_commission_rate, new_commission_per_item, created_at
            ) VALUES (?, ?, ?, 'pending', ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            $creatorId, 
            $token, 
            $newVersion, 
            $updateReason ?: null,
            $previousRate,
            $previousPerItem,
            $creator['commission_rate'],
            $creator['commission_per_item']
        ]);
        $contractId = $db->lastInsertId();
        
        // メール送信
        $agreementUrl = $baseUrl . '/contract-agree.php?token=' . $token;
        $versionLabel = $newVersion > 1 ? "（更新版 v{$newVersion}）" : '';
        
        $subject = "【{$shopName}】販売委託契約書のご確認{$versionLabel}";
        
        $body = "{$creator['name']} 様\n\n";
        $body .= "{$shopName}です。\n\n";
        
        if ($isUpdate && $updateReason) {
            $body .= "契約内容の更新についてご連絡いたします。\n\n";
            $body .= "【更新理由】\n{$updateReason}\n\n";
        }
        
        $body .= "販売委託契約書をお送りいたします。\n";
        $body .= "下記URLより内容をご確認の上、同意をお願いいたします。\n\n";
        $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $body .= "契約書確認・同意ページ:\n";
        $body .= "{$agreementUrl}\n";
        $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $body .= "ご不明な点がございましたら、お気軽にお問い合わせください。\n\n";
        $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $body .= "{$shopName}\n";
        $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        
        if (sendMail($creator['email'], $subject, $body)) {
            $stmt = $db->prepare("UPDATE creator_contracts SET sent_at = NOW() WHERE id = ?");
            $stmt->execute([$contractId]);
            $message = "{$creator['name']}さんに契約書{$versionLabel}をメール送信しました。";
        } else {
            throw new Exception('メール送信に失敗しました');
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// 契約履歴取得API（AJAX）
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['get_history'])) {
    $creatorId = (int)$_POST['creator_id'];
    
    $stmt = $db->prepare("
        SELECT cc.*, c.name as creator_name
        FROM creator_contracts cc
        INNER JOIN creators c ON cc.creator_id = c.id
        WHERE cc.creator_id = ?
        ORDER BY cc.version DESC
    ");
    $stmt->execute([$creatorId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: application/json');
    echo json_encode(['success' => true, 'history' => $history]);
    exit;
}

// 現在のテンプレート
$templateTitle = getSiteSetting($db, 'contract_template_title', '販売委託契約書');
$templateContent = getSiteSetting($db, 'contract_template_content', getDefaultContractTemplate());

// クリエイター一覧と契約状況（agreed を優先して取得）
$creators = $db->query("
    SELECT c.*, 
           cc.id as contract_id, cc.status as contract_status, cc.version as contract_version,
           cc.sent_at, cc.agreed_at, cc.token as contract_token,
           (SELECT COUNT(*) FROM creator_contracts WHERE creator_id = c.id) as contract_count
    FROM creators c
    LEFT JOIN creator_contracts cc ON c.id = cc.creator_id 
        AND cc.id = (
            SELECT id FROM creator_contracts 
            WHERE creator_id = c.id 
            ORDER BY 
                CASE WHEN status = 'agreed' THEN 0 ELSE 1 END,
                id DESC 
            LIMIT 1
        )
    WHERE c.is_active = 1
    ORDER BY c.sort_order ASC, c.id ASC
")->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "契約書管理";
include "includes/header.php";

function getDefaultContractTemplate() {
    return <<<EOT
# 販売委託契約書

{shop_name}（以下「甲」という）と{creator_name}（以下「乙」という）は、以下のとおり販売委託契約を締結する。

## 第1条（目的）
本契約は、乙が創作した商品を甲のオンラインショップにおいて販売することに関する基本的な事項を定めることを目的とする。

## 第2条（委託内容）
1. 乙は甲に対し、乙が創作した商品（以下「本商品」という）の販売を委託する。
2. 甲は乙の代理として本商品を販売し、代金の回収を行う。

## 第3条（販売手数料）
1. 甲は、本商品の販売代金から販売手数料として{commission_rate}%を差し引いた金額を乙に支払う。
2. 前項に加え、注文1件あたり{commission_per_item}円の手数料を差し引くものとする。

## 第4条（支払方法）
1. 甲は毎月末日を締め日とし、翌月末日までに乙の指定口座へ振り込む方法により支払う。
2. 振込手数料は甲の負担とする。

## 第5条（源泉徴収）
乙が個人の場合、甲は支払金額から所得税及び復興特別所得税を源泉徴収し、税務署に納付する。

## 第6条（著作権）
本商品に関する著作権は乙に帰属し、甲は販売に必要な範囲で本商品の画像等を使用できるものとする。

## 第7条（契約期間）
1. 本契約の有効期間は、契約締結日から1年間とする。
2. 期間満了の1ヶ月前までにいずれかの当事者から書面による解約の申し出がない限り、同一条件で1年間自動更新されるものとする。

## 第8条（契約解除）
甲または乙は、相手方が本契約に違反した場合、書面により催告の上、本契約を解除することができる。

## 第9条（協議事項）
本契約に定めのない事項については、甲乙協議の上、誠意をもって解決するものとする。

以上の契約内容に同意する場合は、下記の「同意する」ボタンをクリックしてください。
同意した日時、IPアドレス等が記録され、電子契約として成立します。
EOT;
}
?>
        <div class="flex justify-between items-center mb-8">
            <div>
                <h2 class="text-2xl font-bold text-gray-800">契約書管理</h2>
                <p class="text-gray-500">クリエイターとの販売委託契約</p>
            </div>
        </div>
        
        <?php if ($message): ?>
        <div class="bg-green-50 border border-green-200 text-green-600 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
        
        <?php if ($generatedLink): ?>
        <div class="bg-blue-50 border border-blue-200 text-blue-800 px-4 py-3 rounded-lg mb-6">
            <p class="font-bold mb-2"><i class="fas fa-link mr-2"></i>契約書リンクが生成されました</p>
            <div class="flex items-center gap-2">
                <input type="text" value="<?= htmlspecialchars($generatedLink) ?>" 
                       id="generatedLink" readonly
                       class="flex-1 px-3 py-2 bg-white border border-blue-300 rounded text-sm font-mono">
                <button onclick="copyLink()" class="px-4 py-2 bg-blue-500 text-white rounded hover:bg-blue-600 transition">
                    <i class="fas fa-copy mr-1"></i>コピー
                </button>
            </div>
            <p class="text-xs text-blue-600 mt-2">このリンクをLINE、DM、その他の方法でクリエイターに送信してください。</p>
        </div>
        <script>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>
        
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
            <!-- 契約書テンプレート -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="font-bold text-gray-800 mb-4">
                    <i class="fas fa-file-contract text-orange-500 mr-2"></i>契約書テンプレート
                </h3>
                
                <form method="POST">
                    <div class="space-y-4">
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">タイトル</label>
                            <input type="text" name="template_title" value="<?= htmlspecialchars($templateTitle) ?>"
                                   class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-400 outline-none">
                        </div>
                        
                        <div>
                            <label class="block text-sm font-bold text-gray-700 mb-2">本文（Markdown形式）</label>
                            <textarea name="template_content" rows="20"
                                      class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-orange-400 outline-none font-mono text-sm"><?= htmlspecialchars($templateContent) ?></textarea>
                            <p class="text-xs text-gray-500 mt-1">
                                利用可能な変数: {shop_name}, {creator_name}, {commission_rate}, {commission_per_item}, 
                                {service_commission_rate}, {service_commission_per_item}, {contract_date}, {version}
                            </p>
                        </div>
                        
                        <button type="submit" name="save_template" value="1"
                                class="px-6 py-2 bg-orange-500 text-white rounded-lg font-bold hover:bg-orange-600 transition">
                            <i class="fas fa-save mr-2"></i>テンプレート保存
                        </button>
                    </div>
                </form>
            </div>
            
            <!-- クリエイター契約状況 -->
            <div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6">
                <h3 class="font-bold text-gray-800 mb-4">
                    <i class="fas fa-users text-blue-500 mr-2"></i>クリエイター契約状況
                </h3>
                
                <div class="space-y-3">
                    <?php foreach ($creators as $creator): ?>
                    <div class="flex items-center justify-between p-3 bg-gray-50 rounded-lg">
                        <div class="flex items-center gap-3">
                            <?php if ($creator['image']): ?>
                            <img src="../<?= htmlspecialchars($creator['image']) ?>" class="w-10 h-10 rounded-full object-cover">
                            <?php else: ?>
                            <div class="w-10 h-10 bg-gray-200 rounded-full flex items-center justify-center">
                                <i class="fas fa-user text-gray-400"></i>
                            </div>
                            <?php endif; ?>
                            <div>
                                <p class="font-bold text-gray-800"><?= htmlspecialchars($creator['name']) ?></p>
                                <p class="text-xs text-gray-500">
                                    <?= $creator['email'] ? htmlspecialchars($creator['email']) : 'メール未登録' ?>
                                </p>
                            </div>
                        </div>
                        
                        <div class="flex items-center gap-2">
                            <?php if ($creator['contract_status'] === 'agreed'): ?>
                            <span class="px-3 py-1 bg-green-100 text-green-700 rounded-full text-xs font-bold">
                                <i class="fas fa-check mr-1"></i>契約済<?= $creator['contract_version'] > 1 ? " v{$creator['contract_version']}" : '' ?>
                            </span>
                            <span class="text-xs text-gray-500">
                                <?= date('Y/m/d', strtotime($creator['agreed_at'])) ?>
                            </span>
                            <?php elseif ($creator['contract_status'] === 'pending'): ?>
                            <span class="px-3 py-1 bg-yellow-100 text-yellow-700 rounded-full text-xs font-bold">
                                <i class="fas fa-clock mr-1"></i>送信済
                            </span>
                            <!-- 既存リンクをコピー -->
                            <?php if ($creator['contract_token']): ?>
                            <button onclick="copyExistingLink('<?= $baseUrl ?>/contract-agree.php?token=<?= $creator['contract_token'] ?>')"
                                    class="px-2 py-1 bg-gray-200 text-gray-600 rounded text-xs hover:bg-gray-300"
                                    title="既存のリンクをコピー">
                                <i class="fas fa-copy"></i>
                            </button>
                            <?php endif; ?>
                            <?php else: ?>
                            <span class="px-3 py-1 bg-gray-100 text-gray-600 rounded-full text-xs font-bold">
                                未送信
                            </span>
                            <?php endif; ?>
                            
                            <!-- 契約履歴ボタン（契約がある場合のみ） -->
                            <?php if ($creator['contract_count'] > 0): ?>
                            <button onclick="showHistory(<?= $creator['id'] ?>, '<?= htmlspecialchars($creator['name'], ENT_QUOTES) ?>')"
                                    class="px-2 py-1 bg-gray-500 text-white rounded text-xs hover:bg-gray-600"
                                    title="契約履歴">
                                <i class="fas fa-history"></i>
                            </button>
                            <?php endif; ?>
                            
                            <?php if ($creator['contract_status'] !== 'agreed'): ?>
                            <!-- リンク生成ボタン -->
                            <button onclick="showContractModal(<?= $creator['id'] ?>, '<?= htmlspecialchars($creator['name'], ENT_QUOTES) ?>', 'link', false)"
                                    class="px-3 py-1 bg-purple-500 text-white rounded text-xs font-bold hover:bg-purple-600"
                                    title="契約書リンクを生成">
                                <i class="fas fa-link"></i>
                            </button>
                            
                            <?php if ($creator['email']): ?>
                            <!-- メール送信ボタン -->
                            <button onclick="showContractModal(<?= $creator['id'] ?>, '<?= htmlspecialchars($creator['name'], ENT_QUOTES) ?>', 'email', false)"
                                    class="px-3 py-1 bg-blue-500 text-white rounded text-xs font-bold hover:bg-blue-600"
                                    title="メールで送信">
                                <i class="fas fa-envelope"></i>
                            </button>
                            <?php endif; ?>
                            
                            <?php else: ?>
                            <!-- 契約更新ボタン（契約済みの場合） -->
                            <button onclick="showContractModal(<?= $creator['id'] ?>, '<?= htmlspecialchars($creator['name'], ENT_QUOTES) ?>', 'link', true)"
                                    class="px-2 py-1 bg-orange-500 text-white rounded text-xs font-bold hover:bg-orange-600"
                                    title="契約を更新（リンク）">
                                <i class="fas fa-sync-alt"></i>
                            </button>
                            <?php if ($creator['email']): ?>
                            <button onclick="showContractModal(<?= $creator['id'] ?>, '<?= htmlspecialchars($creator['name'], ENT_QUOTES) ?>', 'email', true)"
                                    class="px-2 py-1 bg-red-500 text-white rounded text-xs font-bold hover:bg-red-600"
                                    title="契約を更新（メール）">
                                <i class="fas fa-envelope"></i>
                            </button>
                            <?php endif; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    
                    <?php if (empty($creators)): ?>
                    <p class="text-center text-gray-500 py-8">クリエイターが登録されていません</p>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- 契約送信/更新モーダル -->
        <div id="contractModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center">
            <div class="bg-white rounded-xl p-6 max-w-md w-full mx-4">
                <h3 id="modalTitle" class="text-lg font-bold text-gray-800 mb-4"></h3>
                
                <form id="contractForm" method="POST">
                    <input type="hidden" name="creator_id" id="modalCreatorId">
                    <input type="hidden" name="is_update" id="modalIsUpdate" value="0">
                    
                    <div id="updateReasonSection" class="mb-4 hidden">
                        <label class="block text-sm font-bold text-gray-700 mb-2">
                            <i class="fas fa-info-circle text-orange-500 mr-1"></i>更新理由
                        </label>
                        <select id="updateReasonSelect" onchange="updateReasonChanged()" class="w-full px-3 py-2 border rounded-lg mb-2">
                            <option value="">選択してください</option>
                            <option value="手数料率の変更">手数料率の変更</option>
                            <option value="単価手数料の変更">単価手数料の変更</option>
                            <option value="契約条項の変更">契約条項の変更</option>
                            <option value="支払条件の変更">支払条件の変更</option>
                            <option value="法改正への対応">法改正への対応</option>
                            <option value="その他">その他（自由入力）</option>
                        </select>
                        <textarea name="update_reason" id="updateReasonText" rows="3" 
                                  class="w-full px-3 py-2 border rounded-lg hidden"
                                  placeholder="更新理由を入力してください"></textarea>
                        <p class="text-xs text-gray-500 mt-1">※ 更新理由はクリエイターへのメールにも記載されます</p>
                    </div>
                    
                    <div id="newContractSection" class="mb-4 hidden">
                        <p class="text-sm text-gray-600">
                            <i class="fas fa-file-contract text-blue-500 mr-1"></i>
                            <span id="modalCreatorName"></span>さんに新しい契約書を送信します。
                        </p>
                    </div>
                    
                    <div class="flex gap-3 justify-end">
                        <button type="button" onclick="closeContractModal()" 
                                class="px-4 py-2 bg-gray-300 text-gray-700 rounded-lg hover:bg-gray-400">
                            キャンセル
                        </button>
                        <button type="submit" id="modalSubmitBtn" name="" value="1"
                                class="px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600">
                            送信
                        </button>
                    </div>
                </form>
            </div>
        </div>
        
        <!-- 契約履歴モーダル -->
        <div id="historyModal" class="fixed inset-0 bg-black bg-opacity-50 z-50 hidden flex items-center justify-center">
            <div class="bg-white rounded-xl p-6 max-w-2xl w-full mx-4 max-h-[80vh] overflow-auto">
                <div class="flex justify-between items-center mb-4">
                    <h3 id="historyTitle" class="text-lg font-bold text-gray-800"></h3>
                    <button onclick="closeHistoryModal()" class="text-gray-400 hover:text-gray-600">
                        <i class="fas fa-times text-xl"></i>
                    </button>
                </div>
                
                <div id="historyContent" class="space-y-3">
                    <!-- 履歴がここに表示される -->
                </div>
            </div>
        </div>
        
        <script>
        function copyLink() {
            const input = document.getElementById('generatedLink');
            input.select();
            document.execCommand('copy');
            alert('リンクをコピーしました');
        }
        
        function copyExistingLink(link) {
            navigator.clipboard.writeText(link).then(() => {
                alert('既存のリンクをコピーしました');
            }).catch(() => {
                prompt('リンクをコピーしてください:', link);
            });
        }
        
        // 契約送信/更新モーダル
        function showContractModal(creatorId, creatorName, type, isUpdate) {
            document.getElementById('modalCreatorId').value = creatorId;
            document.getElementById('modalIsUpdate').value = isUpdate ? '1' : '0';
            document.getElementById('modalCreatorName').textContent = creatorName;
            
            const submitBtn = document.getElementById('modalSubmitBtn');
            const updateSection = document.getElementById('updateReasonSection');
            const newSection = document.getElementById('newContractSection');
            
            if (isUpdate) {
                document.getElementById('modalTitle').textContent = `${creatorName}さんの契約を更新`;
                updateSection.classList.remove('hidden');
                newSection.classList.add('hidden');
                submitBtn.textContent = type === 'email' ? '更新メール送信' : '更新リンク生成';
                submitBtn.className = 'px-4 py-2 bg-orange-500 text-white rounded-lg hover:bg-orange-600';
            } else {
                document.getElementById('modalTitle').textContent = `${creatorName}さんに契約書を送信`;
                updateSection.classList.add('hidden');
                newSection.classList.remove('hidden');
                submitBtn.textContent = type === 'email' ? 'メール送信' : 'リンク生成';
                submitBtn.className = 'px-4 py-2 bg-blue-500 text-white rounded-lg hover:bg-blue-600';
            }
            
            submitBtn.name = type === 'email' ? 'send_contract' : 'generate_link';
            
            document.getElementById('contractModal').classList.remove('hidden');
        }
        
        function closeContractModal() {
            document.getElementById('contractModal').classList.add('hidden');
            document.getElementById('updateReasonSelect').value = '';
            document.getElementById('updateReasonText').value = '';
            document.getElementById('updateReasonText').classList.add('hidden');
        }
        
        function updateReasonChanged() {
            const select = document.getElementById('updateReasonSelect');
            const textarea = document.getElementById('updateReasonText');
            
            if (select.value === 'その他') {
                textarea.classList.remove('hidden');
                textarea.required = true;
            } else {
                textarea.classList.add('hidden');
                textarea.required = false;
                textarea.value = select.value;
            }
        }
        
        // 契約履歴モーダル
        function showHistory(creatorId, creatorName) {
            document.getElementById('historyTitle').textContent = `${creatorName}さんの契約履歴`;
            document.getElementById('historyContent').innerHTML = '<p class="text-center text-gray-500">読み込み中...</p>';
            document.getElementById('historyModal').classList.remove('hidden');
            
            // AJAX で履歴を取得
            fetch('contracts.php', {
                method: 'POST',
                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                body: `get_history=1&creator_id=${creatorId}`
            })
            .then(res => res.json())
            .then(data => {
                if (data.success && data.history.length > 0) {
                    let html = '';
                    data.history.forEach(contract => {
                        const statusClass = contract.status === 'agreed' ? 'bg-green-100 text-green-700' 
                                          : contract.status === 'pending' ? 'bg-yellow-100 text-yellow-700'
                                          : 'bg-gray-100 text-gray-600';
                        const statusText = contract.status === 'agreed' ? '契約済' 
                                         : contract.status === 'pending' ? '送信済' 
                                         : '未送信';
                        
                        const date = contract.agreed_at ? new Date(contract.agreed_at).toLocaleDateString('ja-JP')
                                   : contract.sent_at ? new Date(contract.sent_at).toLocaleDateString('ja-JP') + '(送信)'
                                   : new Date(contract.created_at).toLocaleDateString('ja-JP') + '(作成)';
                        
                        let rateInfo = '';
                        if (contract.new_commission_rate !== null) {
                            rateInfo = `<span class="text-xs text-gray-500">手数料: ${contract.new_commission_rate}%</span>`;
                        }
                        
                        let reasonInfo = '';
                        if (contract.update_reason) {
                            reasonInfo = `<p class="text-xs text-orange-600 mt-1"><i class="fas fa-info-circle mr-1"></i>${contract.update_reason}</p>`;
                        }
                        
                        html += `
                            <div class="p-3 bg-gray-50 rounded-lg">
                                <div class="flex justify-between items-start">
                                    <div>
                                        <span class="font-bold text-gray-800">v${contract.version || 1}</span>
                                        <span class="px-2 py-1 ${statusClass} rounded text-xs font-bold ml-2">${statusText}</span>
                                        ${rateInfo}
                                    </div>
                                    <span class="text-xs text-gray-500">${date}</span>
                                </div>
                                ${contract.agreed_name ? `<p class="text-xs text-gray-600 mt-1">署名: ${contract.agreed_name}</p>` : ''}
                                ${reasonInfo}
                            </div>
                        `;
                    });
                    document.getElementById('historyContent').innerHTML = html;
                } else {
                    document.getElementById('historyContent').innerHTML = '<p class="text-center text-gray-500">契約履歴がありません</p>';
                }
            })
            .catch(err => {
                document.getElementById('historyContent').innerHTML = '<p class="text-center text-red-500">読み込みに失敗しました</p>';
            });
        }
        
        function closeHistoryModal() {
            document.getElementById('historyModal').classList.add('hidden');
        }
        
        // モーダル外クリックで閉じる
        document.getElementById('contractModal').addEventListener('click', function(e) {
            if (e.target === this) closeContractModal();
        });
        document.getElementById('historyModal').addEventListener('click', function(e) {
            if (e.target === this) closeHistoryModal();
        });
        </script>
    </main>
<?php include "includes/footer.php"; ?>
