<?php
/**
 * 書類HTML生成ヘルパー
 * Google Driveに保存するきれいなHTML書類を生成
 */
require_once __DIR__ . '/formatting.php';

/**
 * 基本HTMLテンプレートのヘッダー
 */
function getDocumentHeader($title, $shopName = '') {
    return <<<HTML
<!DOCTYPE html>
<html lang="ja">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>{$title}</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: "Hiragino Kaku Gothic ProN", "Hiragino Sans", "Yu Gothic", "Meiryo", sans-serif;
            font-size: 14px;
            line-height: 1.8;
            color: #333;
            background: #fff;
            padding: 40px;
        }
        .document {
            max-width: 800px;
            margin: 0 auto;
            border: 1px solid #ddd;
            padding: 40px;
            background: #fff;
        }
        .header {
            text-align: center;
            margin-bottom: 30px;
            padding-bottom: 20px;
            border-bottom: 2px solid #333;
        }
        .header h1 {
            font-size: 24px;
            font-weight: bold;
            margin-bottom: 10px;
        }
        .header .shop-name {
            font-size: 14px;
            color: #666;
        }
        .meta-info {
            display: flex;
            justify-content: space-between;
            margin-bottom: 30px;
            padding: 15px;
            background: #f9f9f9;
            border-radius: 5px;
        }
        .meta-info .left, .meta-info .right {
            font-size: 13px;
        }
        .meta-info .label {
            color: #666;
            margin-right: 10px;
        }
        .section {
            margin-bottom: 25px;
        }
        .section-title {
            font-size: 16px;
            font-weight: bold;
            padding: 8px 15px;
            background: #f0f0f0;
            border-left: 4px solid #333;
            margin-bottom: 15px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 20px;
        }
        th, td {
            border: 1px solid #ddd;
            padding: 10px 12px;
            text-align: left;
        }
        th {
            background: #f5f5f5;
            font-weight: bold;
            white-space: nowrap;
        }
        .text-right { text-align: right; }
        .text-center { text-align: center; }
        .amount { font-family: monospace; font-size: 15px; }
        .total-row {
            background: #f9f9f9;
            font-weight: bold;
        }
        .total-row .amount {
            font-size: 18px;
            color: #c00;
        }
        .signature-box {
            margin-top: 40px;
            padding: 20px;
            border: 1px solid #ddd;
            background: #fafafa;
        }
        .signature-box h3 {
            font-size: 14px;
            margin-bottom: 10px;
            color: #666;
        }
        .signature-box .info {
            font-size: 12px;
            color: #888;
        }
        .footer {
            margin-top: 40px;
            padding-top: 20px;
            border-top: 1px solid #ddd;
            text-align: center;
            font-size: 12px;
            color: #888;
        }
        .contract-content {
            padding: 20px;
            background: #fafafa;
            border: 1px solid #eee;
            border-radius: 5px;
        }
        .contract-content h2 {
            font-size: 18px;
            margin: 20px 0 10px;
            padding-bottom: 5px;
            border-bottom: 1px solid #ddd;
        }
        .contract-content h2:first-child {
            margin-top: 0;
        }
        .contract-content h3 {
            font-size: 15px;
            margin: 15px 0 8px;
        }
        .contract-content p {
            margin-bottom: 10px;
        }
        .contract-content ol, .contract-content ul {
            margin-left: 25px;
            margin-bottom: 10px;
        }
        .stamp {
            display: inline-block;
            padding: 5px 15px;
            border: 2px solid #c00;
            color: #c00;
            font-weight: bold;
            border-radius: 3px;
            transform: rotate(-5deg);
        }
        @media print {
            body { padding: 0; }
            .document { border: none; padding: 20px; }
        }
    </style>
</head>
<body>
<div class="document">
HTML;
}

/**
 * 基本HTMLテンプレートのフッター
 */
function getDocumentFooter($shopName = '', $date = null) {
    $date = $date ?: date('Y年n月j日');
    return <<<HTML
    <div class="footer">
        <p>{$shopName}</p>
        <p>発行日: {$date}</p>
    </div>
</div>
</body>
</html>
HTML;
}

/**
 * Markdownを簡易HTML変換
 */
function markdownToHtml($text) {
    // 見出し
    $text = preg_replace('/^### (.+)$/m', '<h3>$1</h3>', $text);
    $text = preg_replace('/^## (.+)$/m', '<h2>$1</h2>', $text);
    $text = preg_replace('/^# (.+)$/m', '<h1>$1</h1>', $text);
    
    // リスト項目
    $text = preg_replace('/^\d+\. (.+)$/m', '<li>$1</li>', $text);
    
    // 段落
    $paragraphs = explode("\n\n", $text);
    $result = '';
    foreach ($paragraphs as $p) {
        $p = trim($p);
        if (empty($p)) continue;
        if (strpos($p, '<h') === 0 || strpos($p, '<li>') === 0) {
            $result .= $p . "\n";
        } else {
            $result .= '<p>' . nl2br($p) . '</p>' . "\n";
        }
    }
    
    return $result;
}

/**
 * 契約書HTMLを生成
 */
function generateContractHtml($contract, $templateContent, $shopName, $agreedName) {
    $title = '販売委託契約書';
    $creatorName = $contract['creator_name'] ?? '';
    $contractId = str_pad($contract['id'], 6, '0', STR_PAD_LEFT);
    $agreedAt = date('Y年n月j日 H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    
    $html = getDocumentHeader($title, $shopName);
    
    $html .= <<<HTML
    <div class="header">
        <h1>{$title}</h1>
        <p class="shop-name">{$shopName}</p>
    </div>
    
    <div class="meta-info">
        <div class="left">
            <p><span class="label">契約番号:</span>#{$contractId}</p>
            <p><span class="label">契約者:</span>{$creatorName}</p>
        </div>
        <div class="right">
            <p><span class="label">同意日時:</span>{$agreedAt}</p>
            <p><span class="label">署名:</span>{$agreedName}</p>
        </div>
    </div>
    
    <div class="section">
        <div class="contract-content">
HTML;
    
    // テンプレート内容をHTML変換
    $html .= markdownToHtml(htmlspecialchars_decode($templateContent));
    
    $html .= <<<HTML
        </div>
    </div>
    
    <div class="signature-box">
        <h3>電子署名情報</h3>
        <table>
            <tr><th width="150">署名者名</th><td>{$agreedName}</td></tr>
            <tr><th>同意日時</th><td>{$agreedAt}</td></tr>
            <tr><th>IPアドレス</th><td>{$ip}</td></tr>
            <tr><th>契約番号</th><td>#{$contractId}</td></tr>
        </table>
        <p class="info">※ この契約書は電子的に締結されました。上記の情報により同意が証明されます。</p>
    </div>
HTML;
    
    $html .= getDocumentFooter($shopName);
    
    return $html;
}

/**
 * 領収書HTMLを生成
 */
function generateReceiptHtml($order, $orderItems, $shopName, $shopInvoiceNumber = '') {
    $title = '領収書';
    $orderNumber = $order['order_number'];
    $paidAt = $order['paid_at'] ? date('Y年n月j日', strtotime($order['paid_at'])) : date('Y年n月j日');
    $total = formatPrice($order['total'] ?? 0);
    $subtotal = formatPrice($order['subtotal'] ?? 0);
    $shippingFee = formatPrice($order['shipping_fee'] ?? 0);
    
    $html = getDocumentHeader($title, $shopName);
    
    $html .= <<<HTML
    <div class="header">
        <h1>{$title}</h1>
        <p class="shop-name">{$shopName}</p>
    </div>
    
    <div class="meta-info">
        <div class="left">
            <p><span class="label">注文番号:</span>{$orderNumber}</p>
            <p><span class="label">発行日:</span>{$paidAt}</p>
        </div>
        <div class="right">
HTML;

    if ($shopInvoiceNumber) {
        $html .= "<p><span class=\"label\">登録番号:</span>{$shopInvoiceNumber}</p>";
    }
    
    $html .= <<<HTML
            <p><span class="stamp">領収済</span></p>
        </div>
    </div>
    
    <div class="section">
        <div class="section-title">ご購入明細</div>
        <table>
            <thead>
                <tr>
                    <th>商品名</th>
                    <th class="text-center" width="80">数量</th>
                    <th class="text-right" width="120">単価</th>
                    <th class="text-right" width="120">小計</th>
                </tr>
            </thead>
            <tbody>
HTML;
    
    foreach ($orderItems as $item) {
        $itemName = htmlspecialchars($item['product_name']);
        $quantity = formatNumber($item['quantity'] ?? 0, '0');
        $price = formatPrice($item['price'] ?? 0);
        $itemSubtotal = formatPrice($item['subtotal'] ?? 0);
        
        $html .= <<<HTML
                <tr>
                    <td>{$itemName}</td>
                    <td class="text-center">{$quantity}</td>
                    <td class="text-right amount">{$price}</td>
                    <td class="text-right amount">{$itemSubtotal}</td>
                </tr>
HTML;
    }
    
    $html .= <<<HTML
            </tbody>
            <tfoot>
                <tr>
                    <td colspan="3" class="text-right">小計</td>
                    <td class="text-right amount">{$subtotal}</td>
                </tr>
                <tr>
                    <td colspan="3" class="text-right">送料</td>
                    <td class="text-right amount">{$shippingFee}</td>
                </tr>
                <tr class="total-row">
                    <td colspan="3" class="text-right">合計（税込）</td>
                    <td class="text-right amount">{$total}</td>
                </tr>
            </tfoot>
        </table>
    </div>
    
    <div class="section">
        <p style="text-align: center; font-size: 13px; color: #666;">
            上記の金額を正に領収いたしました。
        </p>
    </div>
HTML;
    
    $html .= getDocumentFooter($shopName, $paidAt);
    
    return $html;
}

/**
 * 支払通知書HTMLを生成
 */
function generatePaymentNoticeHtml($creator, $salesData, $shopName, $targetYear, $targetMonth) {
    $title = '支払通知書';
    $creatorName = $creator['name'] ?? '';
    $displayMonth = "{$targetYear}年{$targetMonth}月";
    
    $grossSales = formatPrice($salesData['gross_sales'] ?? 0);
    $commission = formatPrice($salesData['commission'] ?? 0);
    $withholdingTax = formatPrice($salesData['withholding_tax'] ?? 0);
    $netPayment = formatPrice($salesData['net_payment'] ?? 0);
    $orderCount = formatNumber($salesData['order_count'] ?? 0, '0');
    $itemCount = formatNumber($salesData['item_count'] ?? 0, '0');
    
    $commissionRate = $creator['commission_rate'] ?? 20;
    $commissionPerItem = $creator['commission_per_item'] ?? 0;
    $businessType = ($creator['business_type'] ?? 'individual') === 'individual' ? '個人' : '法人';
    
    $html = getDocumentHeader($title, $shopName);
    
    $html .= <<<HTML
    <div class="header">
        <h1>{$title}</h1>
        <p class="shop-name">{$shopName}</p>
    </div>
    
    <div class="meta-info">
        <div class="left">
            <p><span class="label">対象期間:</span>{$displayMonth}</p>
            <p><span class="label">クリエイター:</span>{$creatorName} 様</p>
        </div>
        <div class="right">
            <p><span class="label">発行日:</span>{$targetYear}年{$targetMonth}月末日</p>
            <p><span class="label">事業者区分:</span>{$businessType}</p>
        </div>
    </div>
    
    <div class="section">
        <div class="section-title">売上概要</div>
        <table>
            <tr>
                <th width="200">注文件数</th>
                <td class="text-right amount">{$orderCount} 件</td>
            </tr>
            <tr>
                <th>販売点数</th>
                <td class="text-right amount">{$itemCount} 点</td>
            </tr>
        </table>
    </div>
    
    <div class="section">
        <div class="section-title">金額明細</div>
        <table>
            <tr>
                <th width="200">売上総額</th>
                <td class="text-right amount">{$grossSales}</td>
            </tr>
            <tr>
                <th>販売手数料（{$commissionRate}%）</th>
                <td class="text-right amount">- {$commission}</td>
            </tr>
HTML;

    if ($salesData['withholding_tax'] > 0) {
        $html .= <<<HTML
            <tr>
                <th>源泉徴収税（10.21%）</th>
                <td class="text-right amount">- {$withholdingTax}</td>
            </tr>
HTML;
    }

    $html .= <<<HTML
            <tr class="total-row">
                <th>お支払金額</th>
                <td class="text-right amount">{$netPayment}</td>
            </tr>
        </table>
    </div>
    
    <div class="section">
        <div class="section-title">お支払について</div>
        <p style="padding: 15px; background: #f9f9f9; border-radius: 5px;">
            上記金額を、翌月末日までにご登録の銀行口座へお振込みいたします。<br>
            振込手数料は当店負担となります。
        </p>
    </div>
HTML;
    
    $html .= getDocumentFooter($shopName);
    
    return $html;
}
