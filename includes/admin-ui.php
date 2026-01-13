<?php
/**
 * 管理画面共通UIヘルパー
 * 運営側・クリエイター側で共通のUI部品を提供
 */

require_once __DIR__ . '/formatting.php';

// 権限定数
define('ROLE_ADMIN', 'admin');
define('ROLE_CREATOR', 'creator');

// 現在の権限を取得
function getCurrentRole() {
    return defined('CURRENT_ROLE') ? CURRENT_ROLE : ROLE_ADMIN;
}

function isAdmin() {
    return getCurrentRole() === ROLE_ADMIN;
}

function isCreator() {
    return getCurrentRole() === ROLE_CREATOR;
}

// ステータスラベル定義
function getApprovalLabels() {
    return [
        'draft' => ['label' => '下書き', 'class' => 'bg-gray-100 text-gray-600', 'icon' => 'fa-edit'],
        'pending' => ['label' => '審査中', 'class' => 'bg-yellow-100 text-yellow-700', 'icon' => 'fa-clock'],
        'approved' => ['label' => '承認済', 'class' => 'bg-green-100 text-green-700', 'icon' => 'fa-check'],
        'rejected' => ['label' => '要修正', 'class' => 'bg-red-100 text-red-700', 'icon' => 'fa-times'],
    ];
}

function getPublishLabels() {
    return [
        'active' => ['label' => '公開中', 'class' => 'bg-green-100 text-green-700'],
        'paused' => ['label' => '一時停止', 'class' => 'bg-yellow-100 text-yellow-700'],
        'draft' => ['label' => '下書き', 'class' => 'bg-gray-100 text-gray-600'],
        'closed' => ['label' => '受付終了', 'class' => 'bg-red-100 text-red-700'],
        'archived' => ['label' => 'アーカイブ', 'class' => 'bg-gray-200 text-gray-500'],
    ];
}

// 審査ステータス取得
function getApprovalStatus($item) {
    $labels = getApprovalLabels();
    $status = $item['approval_status'] ?? 'approved';
    return $labels[$status] ?? $labels['approved'];
}

// 公開ステータス取得
function getPublishStatus($item, $activeField = 'is_active') {
    if (isset($item['status'])) {
        $labels = getPublishLabels();
        return $labels[$item['status']] ?? ['label' => $item['status'], 'class' => 'bg-gray-100 text-gray-600'];
    }
    $isActive = $item[$activeField] ?? 1;
    return $isActive ? 
        ['label' => '公開', 'class' => 'bg-green-100 text-green-700'] : 
        ['label' => '非公開', 'class' => 'bg-gray-100 text-gray-600'];
}

// バッジHTML生成
function renderBadge($label, $class, $icon = null) {
    $iconHtml = $icon ? "<i class=\"fas {$icon} mr-1\"></i>" : '';
    return "<span class=\"px-2 py-1 rounded text-xs font-bold {$class}\">{$iconHtml}{$label}</span>";
}

// 審査バッジ
function renderApprovalBadge($item) {
    $status = getApprovalStatus($item);
    return renderBadge($status['label'], $status['class'], $status['icon'] ?? null);
}

// 公開バッジ
function renderPublishBadge($item, $activeField = 'is_active') {
    $status = getPublishStatus($item, $activeField);
    return renderBadge($status['label'], $status['class']);
}

// 承認済みか
function isApproved($item) {
    return ($item['approval_status'] ?? 'approved') === 'approved';
}

// 審査申請可能か
function canSubmitForApproval($item) {
    $status = $item['approval_status'] ?? 'approved';
    return in_array($status, ['draft', 'rejected']);
}

// 日付フォーマット
function formatDate($datetime, $format = 'Y/n/j') {
    if (empty($datetime)) return '-';
    return date($format, strtotime($datetime));
}

function formatDateTime($datetime) {
    return formatDate($datetime, 'Y/n/j H:i');
}

function formatDateShort($datetime) {
    return formatDate($datetime, 'n/j');
}

// テーブルヘッダー
function renderTableHeader($columns, $showCheckbox = false) {
    $html = '<thead class="bg-gray-50"><tr>';
    if ($showCheckbox) {
        $html .= '<th class="px-4 py-3 w-8"><input type="checkbox" class="select-all-checkbox rounded"></th>';
    }
    foreach ($columns as $col) {
        $align = $col['align'] ?? 'left';
        $class = $col['class'] ?? '';
        $html .= "<th class=\"px-4 py-3 text-{$align} text-sm font-bold text-gray-600 {$class}\">{$col['label']}</th>";
    }
    $html .= '</tr></thead>';
    return $html;
}

// 空行
function renderEmptyRow($colspan, $message = 'データがありません', $showCheckbox = false) {
    $totalCols = $showCheckbox ? $colspan + 1 : $colspan;
    return "<tr><td colspan=\"{$totalCols}\" class=\"px-4 py-8 text-center text-gray-500\">{$message}</td></tr>";
}

// サムネイル画像
function renderThumbnail($imagePath, $size = 'w-10 h-10', $fallbackIcon = 'fa-image') {
    if (!empty($imagePath)) {
        return "<img src=\"/" . htmlspecialchars($imagePath) . "\" class=\"{$size} rounded object-cover\">";
    }
    return "<div class=\"{$size} bg-gray-100 rounded flex items-center justify-center\"><i class=\"fas {$fallbackIcon} text-gray-300\"></i></div>";
}

// メッセージ表示
function renderMessage($message, $type = 'success') {
    if (empty($message)) return '';
    $colors = [
        'success' => 'bg-green-50 border-green-200 text-green-700',
        'error' => 'bg-red-50 border-red-200 text-red-700',
        'warning' => 'bg-yellow-50 border-yellow-200 text-yellow-700',
        'info' => 'bg-blue-50 border-blue-200 text-blue-700',
    ];
    $color = $colors[$type] ?? $colors['info'];
    return "<div class=\"{$color} border p-4 rounded-lg mb-6\">" . htmlspecialchars($message) . "</div>";
}

// アーカイブ切替タブ
function renderArchiveTabs($showArchived, $baseUrl) {
    $activeClass = $showArchived ? 'bg-gray-100 text-gray-600 hover:bg-gray-200' : 'bg-green-500 text-white';
    $archivedClass = $showArchived ? 'bg-orange-500 text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200';
    
    return "
    <div class=\"flex gap-2 mb-4\">
        <a href=\"{$baseUrl}\" class=\"px-4 py-2 rounded-lg text-sm font-bold {$activeClass}\">公開中</a>
        <a href=\"{$baseUrl}?archived=1\" class=\"px-4 py-2 rounded-lg text-sm font-bold {$archivedClass}\">
            <i class=\"fas fa-archive mr-1\"></i>アーカイブ
        </a>
    </div>";
}

// 一括操作スクリプト
function renderBulkScript() {
    return <<<JS
<script>
document.addEventListener('DOMContentLoaded', function() {
    const selectAll = document.querySelector('.select-all-checkbox');
    const checkboxes = document.querySelectorAll('.item-checkbox');
    const bulkActions = document.getElementById('bulkActions');
    
    function updateBulkActions() {
        const checked = document.querySelectorAll('.item-checkbox:checked').length;
        if (bulkActions) bulkActions.style.display = checked > 0 ? 'flex' : 'none';
    }
    
    if (selectAll) {
        selectAll.addEventListener('change', function() {
            checkboxes.forEach(cb => cb.checked = this.checked);
            updateBulkActions();
        });
    }
    checkboxes.forEach(cb => cb.addEventListener('change', updateBulkActions));
});
</script>
JS;
}

// 審査モーダル
function renderApprovalModal($csrfToken) {
    return <<<HTML
<div id="approvalModal" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl max-w-md w-full">
        <div class="p-6">
            <h3 class="text-lg font-bold text-gray-800 mb-4">
                <i class="fas fa-check-circle text-yellow-500 mr-2"></i>審査
            </h3>
            <p class="text-gray-600 mb-4"><span id="approvalItemTitle" class="font-bold"></span> を審査します。</p>
            
            <form method="POST" id="approvalForm">
                <input type="hidden" name="csrf_token" value="{$csrfToken}">
                <input type="hidden" name="item_id" id="approvalItemId">
                
                <div class="mb-4">
                    <label class="block text-sm font-bold text-gray-700 mb-2">差し戻しの場合は理由を入力</label>
                    <textarea name="approval_note" id="approvalNote" rows="3" 
                              class="w-full px-4 py-2 border border-gray-300 rounded-lg focus:ring-2 focus:ring-blue-400 outline-none"
                              placeholder="修正が必要な点を具体的に記載してください"></textarea>
                </div>
                
                <div class="flex gap-3">
                    <button type="submit" name="approval_action" value="approve"
                            class="flex-1 px-4 py-2 bg-green-500 text-white rounded-lg font-bold hover:bg-green-600">
                        <i class="fas fa-check mr-2"></i>承認
                    </button>
                    <button type="submit" name="approval_action" value="reject"
                            class="flex-1 px-4 py-2 bg-red-500 text-white rounded-lg font-bold hover:bg-red-600">
                        <i class="fas fa-times mr-2"></i>差し戻し
                    </button>
                    <button type="button" onclick="closeApprovalModal()"
                            class="px-4 py-2 bg-gray-200 text-gray-700 rounded-lg font-bold hover:bg-gray-300">
                        キャンセル
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
<script>
function showApprovalModal(id, title) {
    document.getElementById('approvalItemId').value = id;
    document.getElementById('approvalItemTitle').textContent = title;
    document.getElementById('approvalNote').value = '';
    document.getElementById('approvalModal').classList.remove('hidden');
}
function closeApprovalModal() {
    document.getElementById('approvalModal').classList.add('hidden');
}
</script>
HTML;
}

// 新規作成ボタン
function renderCreateButton($onclick, $label = '新規作成', $color = 'green') {
    return "<button onclick=\"{$onclick}\" class=\"px-4 py-2 bg-{$color}-500 text-white rounded-lg font-bold hover:bg-{$color}-600 transition\"><i class=\"fas fa-plus mr-2\"></i>{$label}</button>";
}

// ページヘッダー
function renderPageHeader($title, $subtitle = '', $buttons = '') {
    $html = '<div class="mb-6 flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4">';
    $html .= '<div>';
    $html .= '<h1 class="text-2xl font-bold text-gray-800">' . htmlspecialchars($title) . '</h1>';
    if ($subtitle) $html .= '<p class="text-gray-500 text-sm">' . htmlspecialchars($subtitle) . '</p>';
    $html .= '</div>';
    if ($buttons) $html .= '<div class="flex gap-2">' . $buttons . '</div>';
    $html .= '</div>';
    return $html;
}

// コンテンツ管理ヘッダー（アーカイブタブ付き）
function renderContentHeader($title, $subtitle = '', $buttons = '', $showArchived = false, $baseUrl = '') {
    $html = renderPageHeader($title, $subtitle, $showArchived ? '' : $buttons);
    if ($baseUrl) {
        $html .= renderArchiveTabs($showArchived, $baseUrl);
    }
    return $html;
}

// 取引ステータスラベル
function getTransactionStatusLabels() {
    return [
        'inquiry' => ['label' => '問い合わせ', 'class' => 'bg-yellow-100 text-yellow-700', 'icon' => 'fa-question-circle'],
        'quote_revision' => ['label' => '見積もり修正待ち', 'class' => 'bg-yellow-100 text-yellow-700', 'icon' => 'fa-edit'],
        'quote_sent' => ['label' => '見積もり送信済', 'class' => 'bg-blue-100 text-blue-700', 'icon' => 'fa-file-invoice'],
        'paid' => ['label' => '支払い済', 'class' => 'bg-green-100 text-green-700', 'icon' => 'fa-check-circle'],
        'in_progress' => ['label' => '制作中', 'class' => 'bg-purple-100 text-purple-700', 'icon' => 'fa-paint-brush'],
        'revision_requested' => ['label' => '修正依頼中', 'class' => 'bg-orange-100 text-orange-700', 'icon' => 'fa-sync-alt'],
        'delivered' => ['label' => '納品済', 'class' => 'bg-teal-100 text-teal-700', 'icon' => 'fa-truck'],
        'completed' => ['label' => '完了', 'class' => 'bg-gray-100 text-gray-600', 'icon' => 'fa-check'],
        'cancelled' => ['label' => 'キャンセル', 'class' => 'bg-red-100 text-red-700', 'icon' => 'fa-times-circle'],
        'refunded' => ['label' => '返金済', 'class' => 'bg-red-100 text-red-700', 'icon' => 'fa-undo'],
    ];
}

// 取引ステータスバッジ（統一版）
function renderTransactionStatusBadge($status) {
    $labels = getTransactionStatusLabels();
    $info = $labels[$status] ?? ['label' => $status, 'class' => 'bg-gray-100 text-gray-600', 'icon' => ''];
    return renderBadge($info['label'], $info['class']);
}

// テーブル開始
function renderTableStart($columns, $showCheckbox = false) {
    $html = '<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">';
    $html .= '<div class="hidden md:block"><table class="w-full">';
    $html .= renderTableHeader($columns, $showCheckbox);
    $html .= '<tbody class="divide-y divide-gray-100">';
    return $html;
}

// テーブル終了
function renderTableEnd() {
    return '</tbody></table></div>';
}

// 取引一覧のテーブル行を生成
function renderTransactionRow($t, $isAdmin = false, $csrfToken = '') {
    $html = '<tr class="hover:bg-gray-50 cursor-pointer" onclick="location.href=\'' . ($isAdmin ? 'transactions.php?id=' : 'transaction-detail.php?id=') . $t['id'] . '\'">';
    
    // 取引コード
    $html .= '<td class="px-4 py-3"><div class="flex items-center gap-2">';
    $html .= '<span class="font-mono text-sm">' . htmlspecialchars($t['transaction_code']) . '</span>';
    if (($t['unread_count'] ?? 0) > 0) {
        $html .= '<span class="bg-red-500 text-white text-xs px-1.5 py-0.5 rounded-full">' . $t['unread_count'] . '</span>';
    }
    $html .= '</div></td>';
    
    // サービス名
    $html .= '<td class="px-4 py-3 text-sm hidden md:table-cell"><div class="truncate max-w-[150px]">' . htmlspecialchars($t['service_title'] ?? '-') . '</div></td>';
    
    // 顧客
    $html .= '<td class="px-4 py-3 text-sm">' . htmlspecialchars($t['customer_name'] ?? 'ゲスト') . '</td>';
    
    // クリエイター（管理者のみ）
    if ($isAdmin) {
        $html .= '<td class="px-4 py-3 text-sm hidden lg:table-cell">' . htmlspecialchars($t['creator_name'] ?? '-') . '</td>';
    }
    
    // ステータス
    $html .= '<td class="px-4 py-3">' . renderTransactionStatusBadge($t['status']) . '</td>';
    
    // 金額
    $html .= '<td class="px-4 py-3 text-right text-sm hidden sm:table-cell">';
    if (!empty($t['total_amount'])) {
        $html .= '<span class="text-green-600 font-bold">' . formatPrice($t['total_amount']) . '</span>';
    } else {
        $html .= '<span class="text-gray-400">-</span>';
    }
    $html .= '</td>';
    
    // 開始日
    $startDate = $t['started_at'] ?? $t['paid_at'] ?? null;
    $html .= '<td class="px-4 py-3 text-sm text-gray-500 hidden lg:table-cell">' . formatDate($startDate) . '</td>';
    
    // 納品予定日
    $html .= '<td class="px-4 py-3 text-sm hidden lg:table-cell">';
    if (!empty($t['delivery_deadline'])) {
        $deadline = strtotime($t['delivery_deadline']);
        $isOverdue = $deadline < time() && !in_array($t['status'], ['completed', 'cancelled', 'refunded']);
        $html .= '<span class="' . ($isOverdue ? 'text-red-600 font-bold' : 'text-gray-500') . '">';
        $html .= formatDate($t['delivery_deadline']);
        if ($isOverdue) $html .= ' <i class="fas fa-exclamation-triangle"></i>';
        $html .= '</span>';
    } else {
        $html .= '<span class="text-gray-400">-</span>';
    }
    $html .= '</td>';
    
    // 更新日
    $html .= '<td class="px-4 py-3 text-sm text-gray-500 hidden md:table-cell">' . formatDateShort($t['updated_at']) . '</td>';
    
    $html .= '</tr>';
    return $html;
}

// 統計カード
function renderStatCard($value, $label, $borderColor = 'gray', $textColor = 'gray') {
    return '<div class="bg-white rounded-xl shadow-sm border border-' . $borderColor . '-200 p-3 text-center">
        <div class="text-2xl font-bold text-' . $textColor . '-600">' . $value . '</div>
        <div class="text-xs text-gray-500">' . $label . '</div>
    </div>';
}

// 取引統計
function renderTransactionStats($stats) {
    $html = '<div class="grid grid-cols-2 md:grid-cols-6 gap-3 mb-6">';
    $html .= renderStatCard($stats['total'] ?? 0, '全取引', 'gray', 'gray');
    $html .= renderStatCard($stats['pending'] ?? 0, '対応待ち', 'yellow', 'yellow');
    $html .= renderStatCard($stats['quote_sent'] ?? 0, '見積もり中', 'blue', 'blue');
    $html .= renderStatCard($stats['in_progress'] ?? 0, '制作中', 'green', 'green');
    $html .= renderStatCard($stats['delivered'] ?? 0, '納品済', 'purple', 'purple');
    $html .= renderStatCard($stats['completed'] ?? 0, '完了', 'gray', 'gray');
    $html .= '</div>';
    return $html;
}

// フィルタータブ
function renderFilterTabs($filters, $currentFilter, $baseParam = 'status') {
    $html = '<div class="flex gap-2 mb-4 overflow-x-auto pb-2">';
    foreach ($filters as $key => $f) {
        $active = ($currentFilter === $key) || ($key === '' && empty($currentFilter));
        $activeClass = $active ? "bg-{$f['color']}-500 text-white" : 'bg-gray-100 text-gray-600 hover:bg-gray-200';
        $count = isset($f['count']) && $f['count'] > 0 ? " ({$f['count']})" : '';
        $href = $key ? "?{$baseParam}={$key}" : basename($_SERVER['PHP_SELF']);
        $html .= '<a href="' . $href . '" class="px-4 py-2 rounded-full text-sm font-bold whitespace-nowrap ' . $activeClass . '">';
        $html .= $f['label'] . $count;
        $html .= '</a>';
    }
    $html .= '</div>';
    return $html;
}

// 一覧テーブルのコンテナ（レスポンシブ対応）
function renderListContainer($tableContent, $mobileContent = '') {
    $html = '<div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">';
    $html .= '<div class="hidden md:block">' . $tableContent . '</div>';
    if ($mobileContent) {
        $html .= '<div class="md:hidden divide-y divide-gray-100">' . $mobileContent . '</div>';
    }
    $html .= '</div>';
    return $html;
}

// 編集フォームヘッダー
function renderEditFormHeader($title, $cancelUrl, $item = null) {
    $html = '<div class="bg-white rounded-xl shadow-sm border border-gray-100 p-6 mb-6">';
    $html .= '<div class="flex items-center justify-between mb-4">';
    $html .= '<h2 class="text-lg font-bold text-gray-800"><i class="fas fa-edit text-blue-500 mr-2"></i>' . htmlspecialchars($title) . '</h2>';
    $html .= '<a href="' . htmlspecialchars($cancelUrl) . '" class="text-gray-500 hover:text-gray-700"><i class="fas fa-times text-xl"></i></a>';
    $html .= '</div>';
    
    // 差し戻しメッセージ
    if ($item && ($item['approval_status'] ?? '') === 'rejected' && !empty($item['approval_note'])) {
        $html .= '<div class="bg-red-50 border border-red-200 rounded-lg p-4 mb-4">';
        $html .= '<p class="font-bold text-red-700"><i class="fas fa-exclamation-triangle mr-1"></i>修正が必要です</p>';
        $html .= '<p class="text-red-600 text-sm">' . nl2br(htmlspecialchars($item['approval_note'])) . '</p>';
        $html .= '</div>';
    }
    return $html;
}

function renderEditFormFooter() {
    return '</div>';
}

// モーダル開始
function renderModalStart($id, $title, $icon = 'fa-plus', $color = 'blue') {
    return <<<HTML
<div id="{$id}" class="hidden fixed inset-0 bg-black bg-opacity-50 z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-xl max-w-lg w-full max-h-[90vh] overflow-y-auto p-6">
        <div class="flex justify-between items-center mb-4">
            <h3 class="text-lg font-bold"><i class="fas {$icon} text-{$color}-500 mr-2"></i>{$title}</h3>
            <button onclick="document.getElementById('{$id}').classList.add('hidden')" class="text-gray-400"><i class="fas fa-times"></i></button>
        </div>
HTML;
}

function renderModalEnd() {
    return '</div></div>';
}

// 汎用アクションボタン（クリエイター用）
function renderCreatorItemActions($item, $showArchived, $csrfToken, $options = []) {
    $editParam = $options['editParam'] ?? 'edit';
    $idField = $options['idField'] ?? 'item_id';
    $toggleField = $options['toggleField'] ?? 'is_active';
    $toggleAction = $options['toggleAction'] ?? 'toggle_active';
    $statusField = $options['statusField'] ?? null; // サービス用: 'status'
    
    $html = '';
    
    if ($showArchived) {
        $html .= '<a href="?restore=' . $item['id'] . '" class="text-blue-500 hover:text-blue-700 mx-1" title="復元"><i class="fas fa-undo"></i></a>';
    } else {
        $html .= '<a href="?' . $editParam . '=' . $item['id'] . '" class="text-blue-500 hover:text-blue-700 mx-1" title="編集"><i class="fas fa-edit"></i></a>';
        
        if (isApproved($item)) {
            // 公開/非公開トグル
            if ($statusField) {
                // サービス用（status フィールド）
                $isActive = ($item[$statusField] ?? 'draft') === 'active';
                $newStatus = $isActive ? 'paused' : 'active';
                $html .= '<form method="POST" class="inline"><input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">';
                $html .= '<input type="hidden" name="' . $idField . '" value="' . $item['id'] . '">';
                $html .= '<input type="hidden" name="new_status" value="' . $newStatus . '">';
                $html .= '<button type="submit" name="toggle_status" value="1" class="' . ($isActive ? 'text-yellow-500' : 'text-green-500') . ' hover:opacity-70 mx-1" title="' . ($isActive ? '停止' : '公開') . '">';
                $html .= '<i class="fas ' . ($isActive ? 'fa-pause' : 'fa-play') . '"></i></button></form>';
            } else {
                // 通常（is_active フィールド）
                $isActive = $item[$toggleField] ?? 1;
                $html .= '<form method="POST" class="inline"><input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">';
                $html .= '<input type="hidden" name="' . $idField . '" value="' . $item['id'] . '">';
                $html .= '<input type="hidden" name="new_value" value="' . ($isActive ? 0 : 1) . '">';
                $html .= '<button type="submit" name="' . $toggleAction . '" value="1" class="' . ($isActive ? 'text-yellow-500' : 'text-green-500') . ' hover:opacity-70 mx-1" title="' . ($isActive ? '非公開' : '公開') . '">';
                $html .= '<i class="fas ' . ($isActive ? 'fa-pause' : 'fa-play') . '"></i></button></form>';
            }
        } elseif (canSubmitForApproval($item)) {
            // 審査申請ボタン
            $html .= '<form method="POST" class="inline"><input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">';
            $html .= '<input type="hidden" name="' . $idField . '" value="' . $item['id'] . '">';
            $html .= '<button type="submit" name="submit_for_approval" value="1" class="text-purple-500 hover:text-purple-700 mx-1" title="審査申請"><i class="fas fa-paper-plane"></i></button></form>';
        }
        
        $html .= '<a href="?archive=' . $item['id'] . '" onclick="return confirm(\'アーカイブしますか？\')" class="text-orange-500 hover:text-orange-700 mx-1" title="アーカイブ"><i class="fas fa-archive"></i></a>';
    }
    
    return $html;
}

// 一括操作フォーム開始
function renderBulkFormStart($csrfToken) {
    return '<form method="POST" id="bulkForm"><input type="hidden" name="csrf_token" value="' . htmlspecialchars($csrfToken) . '">';
}

// 一括操作ボタン
function renderBulkButtons($showArchived) {
    $html = '<div id="bulkActions" style="display:none;" class="flex gap-2 mb-4">';
    if ($showArchived) {
        $html .= '<button type="submit" name="bulk_restore" value="1" class="px-3 py-1 bg-blue-500 text-white rounded text-sm font-bold"><i class="fas fa-undo mr-1"></i>一括復元</button>';
    } else {
        $html .= '<button type="submit" name="bulk_archive" value="1" class="px-3 py-1 bg-orange-500 text-white rounded text-sm font-bold"><i class="fas fa-archive mr-1"></i>一括アーカイブ</button>';
    }
    $html .= '</div>';
    return $html;
}

// フォーム入力フィールド
function renderFormField($name, $label, $type = 'text', $value = '', $options = []) {
    $required = $options['required'] ?? false;
    $placeholder = $options['placeholder'] ?? '';
    $rows = $options['rows'] ?? 3;
    $min = $options['min'] ?? null;
    $items = $options['items'] ?? []; // select用
    $accept = $options['accept'] ?? 'image/*';
    
    $html = '<div>';
    $html .= '<label class="block text-sm font-bold text-gray-700 mb-2">' . htmlspecialchars($label);
    if ($required) $html .= ' <span class="text-red-500">*</span>';
    $html .= '</label>';
    
    switch ($type) {
        case 'textarea':
            $html .= '<textarea name="' . $name . '" rows="' . $rows . '" class="w-full px-4 py-2 border border-gray-300 rounded-lg"' . ($placeholder ? ' placeholder="' . htmlspecialchars($placeholder) . '"' : '') . '>' . htmlspecialchars($value) . '</textarea>';
            break;
        case 'select':
            $html .= '<select name="' . $name . '" class="w-full px-4 py-2 border border-gray-300 rounded-lg">';
            $html .= '<option value="">選択してください</option>';
            foreach ($items as $item) {
                $selected = ($value == ($item['id'] ?? $item['value'] ?? '')) ? ' selected' : '';
                $html .= '<option value="' . htmlspecialchars($item['id'] ?? $item['value'] ?? '') . '"' . $selected . '>' . htmlspecialchars($item['name'] ?? $item['title'] ?? $item['label'] ?? '') . '</option>';
            }
            $html .= '</select>';
            break;
        case 'file':
            $html .= '<input type="file" name="' . $name . '" accept="' . $accept . '" class="w-full text-sm">';
            break;
        case 'number':
            $html .= '<input type="number" name="' . $name . '" value="' . htmlspecialchars($value) . '"' . ($min !== null ? ' min="' . $min . '"' : '') . ($required ? ' required' : '') . ' class="w-full px-4 py-2 border border-gray-300 rounded-lg">';
            break;
        default:
            $html .= '<input type="' . $type . '" name="' . $name . '" value="' . htmlspecialchars($value) . '"' . ($required ? ' required' : '') . ($placeholder ? ' placeholder="' . htmlspecialchars($placeholder) . '"' : '') . ' class="w-full px-4 py-2 border border-gray-300 rounded-lg">';
    }
    $html .= '</div>';
    return $html;
}

// フォームボタン
function renderFormButtons($submitName, $submitLabel = '保存', $cancelUrl = '', $color = 'blue') {
    $html = '<div class="flex gap-3 pt-4">';
    $html .= '<button type="submit" name="' . $submitName . '" value="1" class="flex-1 px-6 py-2 bg-' . $color . '-500 text-white rounded-lg font-bold hover:bg-' . $color . '-600">' . htmlspecialchars($submitLabel) . '</button>';
    if ($cancelUrl) {
        $html .= '<a href="' . htmlspecialchars($cancelUrl) . '" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg font-bold text-center">キャンセル</a>';
    } else {
        $html .= '<button type="button" onclick="this.closest(\'.fixed\').classList.add(\'hidden\')" class="px-6 py-2 bg-gray-200 text-gray-700 rounded-lg font-bold">キャンセル</button>';
    }
    $html .= '</div>';
    return $html;
}
