<?php
/**
 * クリエイター売上・支払管理
 */
session_start();
require_once '../includes/db.php';
require_once '../includes/auth.php';
require_once '../includes/site-settings.php';
require_once '../includes/mail.php';
require_once '../includes/google-drive.php';
require_once '../includes/document-template.php';
requireAuth();

$db = getDB();
$gdrive = getGoogleDrive($db);
$message = '';
$error = '';

// 対象年月
$targetYear = isset($_GET['year']) ? (int)$_GET['year'] : (int)date('Y');
$targetMonth = isset($_GET['month']) ? (int)$_GET['month'] : (int)date('m');

// 期間計算
$startDate = sprintf('%04d-%02d-01 00:00:00', $targetYear, $targetMonth);
$endDate = date('Y-m-t 23:59:59', strtotime($startDate));
$displayMonth = sprintf('%d年%d月', $targetYear, $targetMonth);

// 納付期限計算（翌月10日）
$dueDate = date('Y-m-10', strtotime('+1 month', strtotime($startDate)));

// サイト設定
$settings = getSiteSettings();
$siteName = $settings['site_name'] ?? 'ショップ';
$shopName = getSiteSetting($db, 'store_business_name', $siteName);

// Google Drive自動保存用ヘルパー関数
function savePaymentNoticeToDrive($db, $gdrive, $creatorId, $targetYear, $targetMonth, $salesData, $shopName) {
    if (!$gdrive->isConnected()) {
        return false;
    }
    
    try {
        // クリエイター情報を取得
        $stmt = $db->prepare("SELECT * FROM creators WHERE id = ?");
        $stmt->execute([$creatorId]);
        $creator = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$creator) return false;
        
        // フォルダを取得/作成
        $folderId = $gdrive->getMonthlyFolder('payment_notices', $targetYear, $targetMonth);
        if (!$folderId) return false;
        
        // ファイル名
        $filename = sprintf('支払通知書_%s_%d年%02d月.html', 
            $creator['name'], $targetYear, $targetMonth);
        
        // きれいな支払通知書HTMLを生成
        $htmlContent = generatePaymentNoticeHtml($creator, $salesData, $shopName, $targetYear, $targetMonth);
        
        // アップロード
        $result = $gdrive->uploadPdfContent($htmlContent, $filename, $folderId);
        
        if ($result) {
            // 保存履歴を記録
            $stmt = $db->prepare("INSERT INTO document_archives 
                (document_type, reference_id, reference_type, filename, gdrive_file_id)
                VALUES ('payment_notice', ?, 'creator_payment', ?, ?)");
            $stmt->execute([$creatorId, $filename, $result['id']]);
            return true;
        }
    } catch (Exception $e) {
        // エラーは無視（自動保存の失敗で処理を止めない）
    }
    
    return false;
}

// 支払確定処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_payment'])) {
    $creatorId = (int)$_POST['creator_id'];
    $grossSales = (int)$_POST['gross_sales'];
    $commission = (int)$_POST['commission'];
    $withholdingTax = (int)$_POST['withholding_tax'];
    $netPayment = (int)$_POST['net_payment'];
    $orderCount = (int)$_POST['order_count'];
    $itemCount = (int)$_POST['item_count'];
    
    try {
        $stmt = $db->prepare("SELECT id FROM creator_payments WHERE creator_id = ? AND target_year = ? AND target_month = ?");
        $stmt->execute([$creatorId, $targetYear, $targetMonth]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            $stmt = $db->prepare("UPDATE creator_payments SET 
                gross_sales = ?, commission_amount = ?, withholding_tax = ?, net_payment = ?,
                order_count = ?, item_count = ?, status = 'pending', updated_at = NOW()
                WHERE id = ?");
            $stmt->execute([$grossSales, $commission, $withholdingTax, $netPayment, $orderCount, $itemCount, $existing['id']]);
        } else {
            $stmt = $db->prepare("INSERT INTO creator_payments 
                (creator_id, payment_date, target_year, target_month, gross_sales, commission_amount, withholding_tax, net_payment, order_count, item_count, status)
                VALUES (?, CURDATE(), ?, ?, ?, ?, ?, ?, ?, ?, 'pending')");
            $stmt->execute([$creatorId, $targetYear, $targetMonth, $grossSales, $commission, $withholdingTax, $netPayment, $orderCount, $itemCount]);
        }
        
        // Google Driveに自動保存
        $salesData = [
            'gross_sales' => $grossSales,
            'commission' => $commission,
            'withholding_tax' => $withholdingTax,
            'net_payment' => $netPayment,
            'order_count' => $orderCount,
            'item_count' => $itemCount
        ];
        $driveSaved = savePaymentNoticeToDrive($db, $gdrive, $creatorId, $targetYear, $targetMonth, $salesData, $shopName);
        
        $message = '支払を確定しました。';
        if ($driveSaved) {
            $message .= '（Google Driveに保存しました）';
        }
    } catch (PDOException $e) {
        $error = 'エラー: ' . $e->getMessage();
    }
}

// 支払完了処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_payment'])) {
    $paymentId = (int)$_POST['payment_id'];
    try {
        $stmt = $db->prepare("UPDATE creator_payments SET status = 'completed', transfer_date = CURDATE() WHERE id = ?");
        $stmt->execute([$paymentId]);
        $message = '支払を完了にしました。';
    } catch (PDOException $e) {
        $error = 'エラー: ' . $e->getMessage();
    }
}

// 支払通知書メール送信
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['send_notification'])) {
    $paymentId = (int)$_POST['payment_id'];
    
    try {
        // 支払情報を取得
        $stmt = $db->prepare("
            SELECT cp.*, c.name as creator_name, c.email as creator_email
            FROM creator_payments cp
            INNER JOIN creators c ON cp.creator_id = c.id
            WHERE cp.id = ?
        ");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$payment) {
            throw new Exception('支払情報が見つかりません');
        }
        
        if (empty($payment['creator_email'])) {
            throw new Exception('クリエイターのメールアドレスが登録されていません');
        }
        
        // メール送信
        $subject = "【{$shopName}】{$payment['target_year']}年{$payment['target_month']}月分 支払通知書";
        
        $body = "{$payment['creator_name']} 様\n\n";
        $body .= "いつもお世話になっております。{$shopName}です。\n\n";
        $body .= "{$payment['target_year']}年{$payment['target_month']}月分の支払通知書をお送りいたします。\n\n";
        $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $body .= "■ 支払明細\n";
        $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $body .= "対象期間: {$payment['target_year']}年{$payment['target_month']}月\n";
        $body .= "売上合計: ¥" . number_format($payment['gross_sales']) . "\n";
        $body .= "手数料: -¥" . number_format($payment['commission_amount']) . "\n";
        if ($payment['withholding_tax'] > 0) {
            $body .= "源泉徴収税: -¥" . number_format($payment['withholding_tax']) . "\n";
        }
        $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $body .= "お支払金額: ¥" . number_format($payment['net_payment']) . "\n";
        $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";
        $body .= "上記金額を翌月末日までにご登録の口座へお振込みいたします。\n\n";
        $body .= "詳細な明細は管理画面よりご確認ください。\n\n";
        $body .= "ご不明な点がございましたら、お気軽にお問い合わせください。\n\n";
        $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        $body .= "{$shopName}\n";
        $body .= "━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
        
        if (sendMail($payment['creator_email'], $subject, $body)) {
            // 送信日時を記録
            $stmt = $db->prepare("UPDATE creator_payments SET notification_sent_at = NOW() WHERE id = ?");
            $stmt->execute([$paymentId]);
            $message = "{$payment['creator_name']}さんに支払通知書を送信しました。";
        } else {
            throw new Exception('メール送信に失敗しました');
        }
    } catch (Exception $e) {
        $error = $e->getMessage();
    }
}

// 源泉徴収納付完了処理
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['complete_withholding'])) {
    try {
        $stmt = $db->prepare("
            INSERT INTO withholding_tax_payments (target_year, target_month, total_amount, due_date, paid_date, status)
            VALUES (?, ?, ?, ?, CURDATE(), 'paid')
            ON DUPLICATE KEY UPDATE paid_date = CURDATE(), status = 'paid'
        ");
        $stmt->execute([$targetYear, $targetMonth, (int)$_POST['total_withholding'], $dueDate]);
        $message = '源泉徴収税の納付を記録しました。';
    } catch (PDOException $e) {
        $error = 'エラー: ' . $e->getMessage();
    }
}

// 楽天銀行CSV形式エクスポート
if (isset($_GET['export']) && $_GET['export'] === 'rakuten') {
    $stmt = $db->prepare("
        SELECT cp.*, c.name as creator_name,
               c.bank_name, c.bank_branch, c.bank_account_type, c.bank_account_number, c.bank_account_name
        FROM creator_payments cp
        INNER JOIN creators c ON cp.creator_id = c.id
        WHERE cp.target_year = ? AND cp.target_month = ? AND cp.status = 'pending'
        ORDER BY c.name ASC
    ");
    $stmt->execute([$targetYear, $targetMonth]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/csv; charset=Shift_JIS');
    header('Content-Disposition: attachment; filename="rakuten_transfer_' . $targetYear . sprintf('%02d', $targetMonth) . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    // 楽天銀行の総合振込CSVフォーマット
    // 1:振込先銀行コード, 2:振込先銀行名, 3:振込先支店コード, 4:振込先支店名,
    // 5:預金種目(1:普通,2:当座), 6:口座番号, 7:受取人名, 8:振込金額, 9:EDI情報(任意), 10:振込指定区分(7:電信)
    
    foreach ($payments as $p) {
        $accountType = ($p['bank_account_type'] === '普通' || $p['bank_account_type'] === '1') ? '1' : '2';
        
        // 銀行コードと支店コードは手動で設定が必要（または別テーブルで管理）
        // ここでは空欄にして銀行名・支店名で対応
        $row = [
            '',  // 銀行コード（空欄可）
            $p['bank_name'],
            '',  // 支店コード（空欄可）
            $p['bank_branch'],
            $accountType,
            $p['bank_account_number'],
            $p['bank_account_name'],
            $p['net_payment'],
            $p['target_year'] . '/' . $p['target_month'] . '分',  // EDI情報（摘要）
            '7'  // 電信振込
        ];
        
        fputcsv($output, array_map(function($v) {
            return mb_convert_encoding($v, 'SJIS', 'UTF-8');
        }, $row));
    }
    
    fclose($output);
    exit;
}

// 汎用CSVエクスポート
if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $stmt = $db->prepare("
        SELECT cp.*, c.name as creator_name,
               c.bank_name, c.bank_branch, c.bank_account_type, c.bank_account_number, c.bank_account_name
        FROM creator_payments cp
        INNER JOIN creators c ON cp.creator_id = c.id
        WHERE cp.target_year = ? AND cp.target_month = ? AND cp.status = 'pending'
        ORDER BY c.name ASC
    ");
    $stmt->execute([$targetYear, $targetMonth]);
    $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    header('Content-Type: text/csv; charset=Shift_JIS');
    header('Content-Disposition: attachment; filename="payment_' . $targetYear . sprintf('%02d', $targetMonth) . '.csv"');
    
    $output = fopen('php://output', 'w');
    
    fputcsv($output, array_map(function($v) {
        return mb_convert_encoding($v, 'SJIS', 'UTF-8');
    }, ['クリエイター名', '銀行名', '支店名', '口座種別', '口座番号', '口座名義', '振込金額', '売上', '手数料', '源泉徴収']));
    
    foreach ($payments as $p) {
        fputcsv($output, array_map(function($v) {
            return mb_convert_encoding($v, 'SJIS', 'UTF-8');
        }, [
            $p['creator_name'],
            $p['bank_name'],
            $p['bank_branch'],
            $p['bank_account_type'],
            $p['bank_account_number'],
            $p['bank_account_name'],
            $p['net_payment'],
            $p['gross_sales'],
            $p['commission_amount'],
            $p['withholding_tax']
        ]));
    }
    
    fclose($output);
    exit;
}

// クリエイター一覧と売上を取得
$creators = $db->query("SELECT * FROM creators WHERE is_active = 1 ORDER BY sort_order ASC, id ASC")->fetchAll(PDO::FETCH_ASSOC);

// 各クリエイターの売上を計算
$creatorSales = [];
foreach ($creators as $creator) {
    $stmt = $db->prepare("
        SELECT 
            COALESCE(SUM(oi.subtotal), 0) as total_sales,
            COALESCE(SUM(oi.quantity), 0) as total_quantity,
            COUNT(DISTINCT o.id) as order_count
        FROM orders o
        INNER JOIN order_items oi ON o.id = oi.order_id
        INNER JOIN products p ON oi.product_id = p.id
        WHERE p.creator_id = ?
          AND o.payment_status = 'paid'
          AND o.paid_at >= ?
          AND o.paid_at <= ?
    ");
    $stmt->execute([$creator['id'], $startDate, $endDate]);
    $sales = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $totalSales = (int)$sales['total_sales'];
    $orderCount = (int)$sales['order_count'];
    $commissionRate = (float)($creator['commission_rate'] ?? 20);
    $commissionPerItem = (int)($creator['commission_per_item'] ?? 0);
    
    $commissionByRate = floor($totalSales * $commissionRate / 100);
    $commissionByItem = $commissionPerItem * $orderCount;
    $totalCommission = $commissionByRate + $commissionByItem;
    
    $businessType = $creator['business_type'] ?? 'individual';
    $withholdingRequired = (int)($creator['withholding_tax_required'] ?? 1);
    $withholdingTax = 0;
    
    if ($businessType === 'individual' && $withholdingRequired && $totalSales > 0) {
        $taxableAmount = $totalSales - $totalCommission;
        if ($taxableAmount <= 1000000) {
            $withholdingTax = floor($taxableAmount * 0.1021);
        } else {
            $withholdingTax = floor(($taxableAmount - 1000000) * 0.2042) + 102100;
        }
    }
    
    $netPayment = $totalSales - $totalCommission - $withholdingTax;
    
    $stmt = $db->prepare("SELECT * FROM creator_payments WHERE creator_id = ? AND target_year = ? AND target_month = ?");
    $stmt->execute([$creator['id'], $targetYear, $targetMonth]);
    $paymentRecord = $stmt->fetch(PDO::FETCH_ASSOC);
    
    $creatorSales[$creator['id']] = [
        'creator' => $creator,
        'total_sales' => $totalSales,
        'total_quantity' => (int)$sales['total_quantity'],
        'order_count' => $orderCount,
        'commission' => $totalCommission,
        'withholding_tax' => $withholdingTax,
        'net_payment' => $netPayment,
        'payment_record' => $paymentRecord,
    ];
}

// 全体集計
$totalGrossSales = array_sum(array_column($creatorSales, 'total_sales'));
$totalCommission = array_sum(array_column($creatorSales, 'commission'));
$totalWithholding = array_sum(array_column($creatorSales, 'withholding_tax'));
$totalNetPayment = array_sum(array_column($creatorSales, 'net_payment'));

// 源泉徴収納付状況を取得
$stmt = $db->prepare("SELECT * FROM withholding_tax_payments WHERE target_year = ? AND target_month = ?");
$stmt->execute([$targetYear, $targetMonth]);
$withholdingPayment = $stmt->fetch(PDO::FETCH_ASSOC);

// 支払履歴
$stmt = $db->prepare("
    SELECT cp.*, c.name as creator_name, c.email as creator_email
    FROM creator_payments cp
    INNER JOIN creators c ON cp.creator_id = c.id
    WHERE cp.target_year = ? AND cp.target_month = ?
    ORDER BY cp.status ASC, c.name ASC
");
$stmt->execute([$targetYear, $targetMonth]);
$paymentRecords = $stmt->fetchAll(PDO::FETCH_ASSOC);

$pageTitle = "売上・支払管理";
include "includes/header.php";
?>
        <div class="flex justify-between items-center mb-8">
            <div>
                <h2 class="text-2xl font-bold text-gray-800">売上・支払管理</h2>
                <p class="text-gray-500">クリエイター別の売上集計と支払処理</p>
            </div>
            <div class="flex items-center gap-2">
                <form method="GET" class="flex items-center gap-2">
                    <select name="year" class="px-3 py-2 border border-gray-300 rounded-lg" onchange="this.form.submit()">
                        <?php for ($y = date('Y'); $y >= date('Y') - 3; $y--): ?>
                        <option value="<?= $y ?>" <?= $y === $targetYear ? 'selected' : '' ?>><?= $y ?>年</option>
                        <?php endfor; ?>
                    </select>
                    <select name="month" class="px-3 py-2 border border-gray-300 rounded-lg" onchange="this.form.submit()">
                        <?php for ($m = 1; $m <= 12; $m++): ?>
                        <option value="<?= $m ?>" <?= $m === $targetMonth ? 'selected' : '' ?>><?= $m ?>月</option>
                        <?php endfor; ?>
                    </select>
                </form>
                
                <div class="relative" x-data="{ open: false }">
                    <button @click="open = !open" class="bg-green-500 hover:bg-green-600 text-white font-bold px-4 py-2 rounded-lg transition">
                        <i class="fas fa-download mr-2"></i>CSV <i class="fas fa-caret-down ml-1"></i>
                    </button>
                    <div x-show="open" @click.away="open = false" 
                         class="absolute right-0 mt-2 w-48 bg-white rounded-lg shadow-lg border z-10">
                        <a href="?year=<?= $targetYear ?>&month=<?= $targetMonth ?>&export=rakuten" 
                           class="block px-4 py-2 hover:bg-gray-100 text-sm">
                            <i class="fas fa-university mr-2 text-red-500"></i>楽天銀行形式
                        </a>
                        <a href="?year=<?= $targetYear ?>&month=<?= $targetMonth ?>&export=csv" 
                           class="block px-4 py-2 hover:bg-gray-100 text-sm">
                            <i class="fas fa-file-csv mr-2 text-green-500"></i>汎用CSV
                        </a>
                    </div>
                </div>
            </div>
        </div>
        
        <?php if ($message): ?>
        <div class="bg-green-50 border border-green-200 text-green-600 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-check-circle mr-2"></i><?= htmlspecialchars($message) ?>
        </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
        <div class="bg-red-50 border border-red-200 text-red-600 px-4 py-3 rounded-lg mb-6">
            <i class="fas fa-exclamation-circle mr-2"></i><?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>
        
        <!-- サマリー -->
        <div class="grid grid-cols-1 md:grid-cols-4 gap-4 mb-8">
            <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
                <p class="text-gray-500 text-sm mb-1">売上合計</p>
                <p class="text-2xl font-bold text-gray-800">¥<?= number_format($totalGrossSales) ?></p>
            </div>
            <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
                <p class="text-gray-500 text-sm mb-1">手数料収入</p>
                <p class="text-2xl font-bold text-green-600">¥<?= number_format($totalCommission) ?></p>
            </div>
            <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
                <p class="text-gray-500 text-sm mb-1">源泉徴収合計</p>
                <p class="text-2xl font-bold text-orange-600">¥<?= number_format($totalWithholding) ?></p>
            </div>
            <div class="bg-white rounded-xl shadow-sm p-6 border border-gray-100">
                <p class="text-gray-500 text-sm mb-1">クリエイター支払</p>
                <p class="text-2xl font-bold text-blue-600">¥<?= number_format($totalNetPayment) ?></p>
            </div>
        </div>
        
        <!-- 源泉徴収納付管理 -->
        <?php if ($totalWithholding > 0): ?>
        <div class="bg-orange-50 rounded-xl shadow-sm border border-orange-200 p-6 mb-8">
            <div class="flex items-center justify-between">
                <div>
                    <h3 class="font-bold text-gray-800 flex items-center">
                        <i class="fas fa-landmark text-orange-500 mr-2"></i>
                        源泉徴収税の納付
                    </h3>
                    <p class="text-sm text-gray-600 mt-1">
                        納付期限: <strong><?= date('Y年n月j日', strtotime($dueDate)) ?></strong>
                        （<?= $displayMonth ?>分の支払いに対する源泉徴収税）
                    </p>
                </div>
                <div class="text-right">
                    <p class="text-sm text-gray-600">納付額</p>
                    <p class="text-2xl font-bold text-orange-600">¥<?= number_format($totalWithholding) ?></p>
                </div>
                <div>
                    <?php if ($withholdingPayment && $withholdingPayment['status'] === 'paid'): ?>
                    <span class="inline-flex items-center px-4 py-2 rounded-lg text-sm font-bold bg-green-100 text-green-700">
                        <i class="fas fa-check mr-2"></i>
                        納付済（<?= date('n/j', strtotime($withholdingPayment['paid_date'])) ?>）
                    </span>
                    <?php else: ?>
                    <form method="POST" class="inline">
                        <input type="hidden" name="total_withholding" value="<?= $totalWithholding ?>">
                        <button type="submit" name="complete_withholding" value="1"
                                onclick="return confirm('源泉徴収税の納付を完了として記録しますか？')"
                                class="px-4 py-2 bg-orange-500 text-white rounded-lg font-bold hover:bg-orange-600 transition">
                            <i class="fas fa-check mr-2"></i>納付完了を記録
                        </button>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <!-- クリエイター別売上 -->
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 mb-8">
            <div class="px-6 py-4 border-b border-gray-100">
                <h3 class="font-bold text-gray-800"><?= $displayMonth ?>分 クリエイター別売上</h3>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">クリエイター</th>
                            <th class="px-4 py-3 text-right text-sm font-bold text-gray-600">売上</th>
                            <th class="px-4 py-3 text-right text-sm font-bold text-gray-600">手数料</th>
                            <th class="px-4 py-3 text-right text-sm font-bold text-gray-600">源泉</th>
                            <th class="px-4 py-3 text-right text-sm font-bold text-gray-600">支払額</th>
                            <th class="px-4 py-3 text-center text-sm font-bold text-gray-600">状態</th>
                            <th class="px-4 py-3 text-center text-sm font-bold text-gray-600">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($creatorSales as $data): ?>
                        <?php if ($data['total_sales'] > 0): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3">
                                <div class="flex items-center gap-3">
                                    <?php if ($data['creator']['image']): ?>
                                    <img src="../<?= htmlspecialchars($data['creator']['image']) ?>" class="w-8 h-8 rounded-full object-cover">
                                    <?php else: ?>
                                    <div class="w-8 h-8 bg-gray-200 rounded-full flex items-center justify-center">
                                        <i class="fas fa-user text-gray-400 text-xs"></i>
                                    </div>
                                    <?php endif; ?>
                                    <div>
                                        <p class="font-bold text-gray-800"><?= htmlspecialchars($data['creator']['name']) ?></p>
                                        <p class="text-xs text-gray-500">
                                            <?= $data['creator']['business_type'] === 'corporation' ? '法人' : '個人' ?>
                                            <?php if (!empty($data['creator']['email'])): ?>
                                            <i class="fas fa-envelope ml-1 text-green-500" title="メール登録済"></i>
                                            <?php endif; ?>
                                        </p>
                                    </div>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-right font-bold">¥<?= number_format($data['total_sales']) ?></td>
                            <td class="px-4 py-3 text-right text-red-600">-¥<?= number_format($data['commission']) ?></td>
                            <td class="px-4 py-3 text-right text-orange-600">
                                <?= $data['withholding_tax'] > 0 ? '-¥' . number_format($data['withholding_tax']) : '-' ?>
                            </td>
                            <td class="px-4 py-3 text-right font-bold text-blue-600">¥<?= number_format($data['net_payment']) ?></td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($data['payment_record']): ?>
                                    <?php if ($data['payment_record']['status'] === 'completed'): ?>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-bold bg-green-100 text-green-700">
                                        <i class="fas fa-check mr-1"></i>済
                                    </span>
                                    <?php else: ?>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-bold bg-yellow-100 text-yellow-700">
                                        <i class="fas fa-clock mr-1"></i>確定
                                    </span>
                                    <?php endif; ?>
                                    <?php if ($data['payment_record']['notification_sent_at']): ?>
                                    <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-bold bg-blue-100 text-blue-700 ml-1" 
                                          title="<?= date('Y/m/d H:i', strtotime($data['payment_record']['notification_sent_at'])) ?>">
                                        <i class="fas fa-envelope"></i>
                                    </span>
                                    <?php endif; ?>
                                <?php else: ?>
                                <span class="text-xs text-gray-400">-</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <div class="flex justify-center gap-1 flex-wrap">
                                    <?php if (!$data['payment_record'] || $data['payment_record']['status'] !== 'completed'): ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="creator_id" value="<?= $data['creator']['id'] ?>">
                                        <input type="hidden" name="gross_sales" value="<?= $data['total_sales'] ?>">
                                        <input type="hidden" name="commission" value="<?= $data['commission'] ?>">
                                        <input type="hidden" name="withholding_tax" value="<?= $data['withholding_tax'] ?>">
                                        <input type="hidden" name="net_payment" value="<?= $data['net_payment'] ?>">
                                        <input type="hidden" name="order_count" value="<?= $data['order_count'] ?>">
                                        <input type="hidden" name="item_count" value="<?= $data['total_quantity'] ?>">
                                        <button type="submit" name="confirm_payment" value="1"
                                            class="px-2 py-1 bg-yellow-100 text-yellow-700 rounded text-xs font-bold hover:bg-yellow-200">
                                            確定
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    
                                    <?php if ($data['payment_record'] && $data['payment_record']['status'] === 'pending'): ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="payment_id" value="<?= $data['payment_record']['id'] ?>">
                                        <button type="submit" name="complete_payment" value="1"
                                            onclick="return confirm('支払完了にしますか？')"
                                            class="px-2 py-1 bg-green-100 text-green-700 rounded text-xs font-bold hover:bg-green-200">
                                            完了
                                        </button>
                                    </form>
                                    
                                    <?php if (!empty($data['creator']['email']) && !$data['payment_record']['notification_sent_at']): ?>
                                    <form method="POST" class="inline">
                                        <input type="hidden" name="payment_id" value="<?= $data['payment_record']['id'] ?>">
                                        <button type="submit" name="send_notification" value="1"
                                            onclick="return confirm('支払通知書をメール送信しますか？')"
                                            class="px-2 py-1 bg-blue-100 text-blue-700 rounded text-xs font-bold hover:bg-blue-200">
                                            <i class="fas fa-envelope"></i>
                                        </button>
                                    </form>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                    
                                    <a href="creator-payment.php?creator_id=<?= $data['creator']['id'] ?>&month=<?= $targetYear ?>-<?= sprintf('%02d', $targetMonth) ?>"
                                       class="px-2 py-1 bg-gray-100 text-gray-700 rounded text-xs font-bold hover:bg-gray-200"
                                       target="_blank">
                                        <i class="fas fa-file-alt"></i>
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endif; ?>
                        <?php endforeach; ?>
                        
                        <?php if ($totalGrossSales === 0): ?>
                        <tr>
                            <td colspan="7" class="px-4 py-8 text-center text-gray-500">
                                この期間の売上はありません
                            </td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- 支払履歴 -->
        <?php if (!empty($paymentRecords)): ?>
        <div class="bg-white rounded-xl shadow-sm border border-gray-100">
            <div class="px-6 py-4 border-b border-gray-100">
                <h3 class="font-bold text-gray-800"><?= $displayMonth ?>分 支払履歴</h3>
            </div>
            
            <div class="overflow-x-auto">
                <table class="w-full">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-4 py-3 text-left text-sm font-bold text-gray-600">クリエイター</th>
                            <th class="px-4 py-3 text-right text-sm font-bold text-gray-600">支払額</th>
                            <th class="px-4 py-3 text-center text-sm font-bold text-gray-600">確定日</th>
                            <th class="px-4 py-3 text-center text-sm font-bold text-gray-600">振込日</th>
                            <th class="px-4 py-3 text-center text-sm font-bold text-gray-600">通知送信</th>
                            <th class="px-4 py-3 text-center text-sm font-bold text-gray-600">状態</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php foreach ($paymentRecords as $record): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-4 py-3 font-bold"><?= htmlspecialchars($record['creator_name']) ?></td>
                            <td class="px-4 py-3 text-right font-bold text-blue-600">¥<?= number_format($record['net_payment']) ?></td>
                            <td class="px-4 py-3 text-center text-sm"><?= date('Y/m/d', strtotime($record['payment_date'])) ?></td>
                            <td class="px-4 py-3 text-center text-sm">
                                <?= $record['transfer_date'] ? date('Y/m/d', strtotime($record['transfer_date'])) : '-' ?>
                            </td>
                            <td class="px-4 py-3 text-center text-sm">
                                <?= $record['notification_sent_at'] ? date('Y/m/d', strtotime($record['notification_sent_at'])) : '-' ?>
                            </td>
                            <td class="px-4 py-3 text-center">
                                <?php if ($record['status'] === 'completed'): ?>
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-bold bg-green-100 text-green-700">
                                    支払済
                                </span>
                                <?php else: ?>
                                <span class="inline-flex items-center px-2 py-1 rounded-full text-xs font-bold bg-yellow-100 text-yellow-700">
                                    未払い
                                </span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </main>
    
    <script src="https://unpkg.com/alpinejs@3.x.x/dist/cdn.min.js" defer></script>
<?php include "includes/footer.php"; ?>
