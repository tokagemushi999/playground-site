<?php
/**
 * 共通コンテンツ管理コンポーネント
 * 運営側・クリエイター側で同じUIを提供
 * 
 * 使用方法:
 * 1. CURRENT_ROLE を定義 ('admin' or 'creator')
 * 2. $creatorId を設定（クリエイターの場合）
 * 3. このファイルをrequire
 */

require_once __DIR__ . '/admin-ui.php';
require_once __DIR__ . '/csrf.php';

/**
 * コンテンツ一覧処理の共通ハンドラ
 */
class ContentManager {
    protected $db;
    protected $table;
    protected $creatorId;
    protected $isAdmin;
    protected $message = '';
    protected $error = '';
    protected $baseUrl;
    protected $csrfToken;
    
    // テーブル設定
    protected $activeField = 'is_active';
    protected $archiveValue = 0;
    protected $activeValue = 1;
    
    public function __construct($db, $table, $creatorId = null) {
        $this->db = $db;
        $this->table = $table;
        $this->creatorId = $creatorId;
        $this->isAdmin = isAdmin();
        $this->csrfToken = generateCsrfToken();
    }
    
    public function setBaseUrl($url) {
        $this->baseUrl = $url;
    }
    
    public function setActiveField($field, $activeVal = 1, $archiveVal = 0) {
        $this->activeField = $field;
        $this->activeValue = $activeVal;
        $this->archiveValue = $archiveVal;
    }
    
    public function getMessage() { return $this->message; }
    public function getError() { return $this->error; }
    public function getCsrfToken() { return $this->csrfToken; }
    
    /**
     * アーカイブ処理
     */
    public function handleArchive($id) {
        $sql = "UPDATE {$this->table} SET {$this->activeField} = ?";
        $params = [$this->archiveValue];
        
        if (!$this->isAdmin && $this->creatorId) {
            $sql .= " WHERE id = ? AND creator_id = ?";
            $params[] = (int)$id;
            $params[] = $this->creatorId;
        } else {
            $sql .= " WHERE id = ?";
            $params[] = (int)$id;
        }
        
        $this->db->prepare($sql)->execute($params);
        return true;
    }
    
    /**
     * 復元処理
     */
    public function handleRestore($id) {
        $sql = "UPDATE {$this->table} SET {$this->activeField} = ?";
        $params = [$this->activeValue];
        
        if (!$this->isAdmin && $this->creatorId) {
            $sql .= " WHERE id = ? AND creator_id = ? AND {$this->activeField} = ?";
            $params[] = (int)$id;
            $params[] = $this->creatorId;
            $params[] = $this->archiveValue;
        } else {
            $sql .= " WHERE id = ? AND {$this->activeField} = ?";
            $params[] = (int)$id;
            $params[] = $this->archiveValue;
        }
        
        $this->db->prepare($sql)->execute($params);
        return true;
    }
    
    /**
     * 完全削除処理（運営のみ）
     */
    public function handleDelete($id) {
        if (!$this->isAdmin) return false;
        
        $stmt = $this->db->prepare("DELETE FROM {$this->table} WHERE id = ? AND {$this->activeField} = ?");
        $stmt->execute([(int)$id, $this->archiveValue]);
        return true;
    }
    
    /**
     * 一括アーカイブ
     */
    public function handleBulkArchive($ids) {
        if (empty($ids)) return false;
        
        $ids = array_map('intval', $ids);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        
        $sql = "UPDATE {$this->table} SET {$this->activeField} = ? WHERE id IN ($ph)";
        $params = array_merge([$this->archiveValue], $ids);
        
        if (!$this->isAdmin && $this->creatorId) {
            $sql .= " AND creator_id = ?";
            $params[] = $this->creatorId;
        }
        
        $this->db->prepare($sql)->execute($params);
        $this->message = count($ids) . '件をアーカイブしました。';
        return true;
    }
    
    /**
     * 一括復元
     */
    public function handleBulkRestore($ids) {
        if (empty($ids)) return false;
        
        $ids = array_map('intval', $ids);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        
        $sql = "UPDATE {$this->table} SET {$this->activeField} = ? WHERE id IN ($ph) AND {$this->activeField} = ?";
        $params = array_merge([$this->activeValue], $ids, [$this->archiveValue]);
        
        if (!$this->isAdmin && $this->creatorId) {
            $sql .= " AND creator_id = ?";
            $params[] = $this->creatorId;
        }
        
        $this->db->prepare($sql)->execute($params);
        $this->message = count($ids) . '件を復元しました。';
        return true;
    }
    
    /**
     * 一括削除（運営のみ）
     */
    public function handleBulkDelete($ids) {
        if (!$this->isAdmin || empty($ids)) return false;
        
        $ids = array_map('intval', $ids);
        $ph = implode(',', array_fill(0, count($ids), '?'));
        
        $sql = "DELETE FROM {$this->table} WHERE id IN ($ph) AND {$this->activeField} = ?";
        $params = array_merge($ids, [$this->archiveValue]);
        
        $this->db->prepare($sql)->execute($params);
        $this->message = count($ids) . '件を完全に削除しました。';
        return true;
    }
    
    /**
     * 審査申請（クリエイターのみ）
     */
    public function handleSubmitForApproval($id) {
        $sql = "UPDATE {$this->table} SET approval_status = 'pending', submitted_at = NOW() WHERE id = ? AND approval_status IN ('draft', 'rejected')";
        $params = [(int)$id];
        
        if (!$this->isAdmin && $this->creatorId) {
            $sql = "UPDATE {$this->table} SET approval_status = 'pending', submitted_at = NOW() WHERE id = ? AND creator_id = ? AND approval_status IN ('draft', 'rejected')";
            $params[] = $this->creatorId;
        }
        
        $this->db->prepare($sql)->execute($params);
        $this->message = '審査を申請しました。';
        return true;
    }
    
    /**
     * 審査処理（運営のみ）
     */
    public function handleApproval($id, $action, $note = '') {
        if (!$this->isAdmin) return false;
        
        if ($action === 'approve') {
            $stmt = $this->db->prepare("UPDATE {$this->table} SET approval_status = 'approved', approved_at = NOW(), approval_note = NULL WHERE id = ?");
            $stmt->execute([(int)$id]);
            $this->message = '承認しました。';
        } elseif ($action === 'reject') {
            if (empty($note)) {
                $this->error = '差し戻し理由を入力してください。';
                return false;
            }
            $stmt = $this->db->prepare("UPDATE {$this->table} SET approval_status = 'rejected', approval_note = ? WHERE id = ?");
            $stmt->execute([$note, (int)$id]);
            $this->message = '差し戻しました。';
        }
        return true;
    }
    
    /**
     * 共通のリクエスト処理
     */
    public function processRequest() {
        // GET処理
        if (isset($_GET['archive'])) {
            $this->handleArchive($_GET['archive']);
            header("Location: {$this->baseUrl}");
            exit;
        }
        if (isset($_GET['restore'])) {
            $this->handleRestore($_GET['restore']);
            header("Location: {$this->baseUrl}?archived=1");
            exit;
        }
        if (isset($_GET['delete']) && $this->isAdmin) {
            if (validateCsrfToken($_GET['csrf_token'] ?? '')) {
                $this->handleDelete($_GET['delete']);
            }
            header("Location: {$this->baseUrl}?archived=1");
            exit;
        }
        
        // POST処理
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            if (!validateCsrfToken($_POST['csrf_token'] ?? '')) {
                $this->error = '不正なリクエストです。';
                return;
            }
            
            $ids = $_POST['selected_items'] ?? [];
            
            if (isset($_POST['bulk_archive'])) {
                $this->handleBulkArchive($ids);
            } elseif (isset($_POST['bulk_restore'])) {
                $this->handleBulkRestore($ids);
            } elseif (isset($_POST['bulk_delete']) && $this->isAdmin) {
                $this->handleBulkDelete($ids);
            } elseif (isset($_POST['submit_for_approval'])) {
                $this->handleSubmitForApproval($_POST['item_id']);
            } elseif (isset($_POST['approval_action']) && $this->isAdmin) {
                $this->handleApproval($_POST['item_id'], $_POST['approval_action'], $_POST['approval_note'] ?? '');
            }
        }
    }
}

/**
 * 一覧テーブルのレンダリング
 */
function renderContentTable($items, $columns, $options = []) {
    $showCheckbox = $options['showCheckbox'] ?? true;
    $showArchived = $options['showArchived'] ?? false;
    $emptyMessage = $options['emptyMessage'] ?? 'データがありません';
    $csrfToken = $options['csrfToken'] ?? '';
    $baseUrl = $options['baseUrl'] ?? '';
    $renderRow = $options['renderRow'] ?? null;
    
    ob_start();
    ?>
    <form method="POST" id="bulkForm">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken) ?>">
        
        <!-- 一括操作ボタン -->
        <div id="bulkActions" style="display:none;" class="flex gap-2 mb-4">
            <?php if ($showArchived): ?>
                <button type="submit" name="bulk_restore" value="1" onclick="return confirm('選択した項目を復元しますか？')" class="px-3 py-1 bg-blue-500 text-white rounded text-sm font-bold hover:bg-blue-600">
                    <i class="fas fa-undo mr-1"></i>一括復元
                </button>
                <?php if (isAdmin()): ?>
                <button type="submit" name="bulk_delete" value="1" onclick="return confirm('完全に削除しますか？この操作は取り消せません。')" class="px-3 py-1 bg-red-500 text-white rounded text-sm font-bold hover:bg-red-600">
                    <i class="fas fa-trash mr-1"></i>一括削除
                </button>
                <?php endif; ?>
            <?php else: ?>
                <button type="submit" name="bulk_archive" value="1" onclick="return confirm('選択した項目をアーカイブしますか？')" class="px-3 py-1 bg-orange-500 text-white rounded text-sm font-bold hover:bg-orange-600">
                    <i class="fas fa-archive mr-1"></i>一括アーカイブ
                </button>
            <?php endif; ?>
        </div>
        
        <div class="bg-white rounded-xl shadow-sm border border-gray-100 overflow-hidden">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <?php if ($showCheckbox): ?>
                        <th class="px-4 py-3 w-8"><input type="checkbox" class="select-all-checkbox rounded"></th>
                        <?php endif; ?>
                        <?php foreach ($columns as $col): ?>
                        <th class="px-4 py-3 text-<?= $col['align'] ?? 'left' ?> text-sm font-bold text-gray-600 <?= $col['class'] ?? '' ?>"><?= $col['label'] ?></th>
                        <?php endforeach; ?>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if (empty($items)): ?>
                    <tr><td colspan="<?= count($columns) + ($showCheckbox ? 1 : 0) ?>" class="px-4 py-8 text-center text-gray-500"><?= htmlspecialchars($emptyMessage) ?></td></tr>
                    <?php else: ?>
                    <?php foreach ($items as $item): ?>
                    <?php if ($renderRow) echo $renderRow($item, $showArchived, $csrfToken); ?>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </form>
    <?php
    return ob_get_clean();
}

/**
 * アクションボタンのレンダリング（権限対応）
 */
function renderItemActions($item, $showArchived, $csrfToken, $options = []) {
    $baseUrl = $options['baseUrl'] ?? '';
    $editUrl = $options['editUrl'] ?? "?edit={$item['id']}";
    $hasApproval = $options['hasApproval'] ?? true;
    $hasToggle = $options['hasToggle'] ?? true;
    $toggleField = $options['toggleField'] ?? 'is_active';
    $toggleAction = $options['toggleAction'] ?? 'toggle_active';
    
    ob_start();
    
    if ($showArchived) {
        // アーカイブ表示時
        echo "<a href=\"?restore={$item['id']}\" class=\"text-blue-500 hover:text-blue-700 mx-1\" title=\"復元\"><i class=\"fas fa-undo\"></i></a>";
        if (isAdmin()) {
            echo "<a href=\"?delete={$item['id']}&csrf_token=" . htmlspecialchars($csrfToken) . "\" onclick=\"return confirm('完全に削除しますか？')\" class=\"text-red-500 hover:text-red-700 mx-1\" title=\"削除\"><i class=\"fas fa-trash\"></i></a>";
        }
    } else {
        // 通常表示時
        echo "<a href=\"{$editUrl}\" class=\"text-blue-500 hover:text-blue-700 mx-1\" title=\"編集\"><i class=\"fas fa-edit\"></i></a>";
        
        // 運営の場合：審査ボタン
        if (isAdmin() && $hasApproval && ($item['approval_status'] ?? '') === 'pending') {
            $title = htmlspecialchars(addslashes($item['title'] ?? $item['name'] ?? ''), ENT_QUOTES);
            echo "<button onclick=\"showApprovalModal({$item['id']}, '{$title}')\" class=\"text-yellow-500 hover:text-yellow-700 mx-1\" title=\"審査\"><i class=\"fas fa-check-circle\"></i></button>";
        }
        
        // 承認済みの場合：公開/停止トグル
        if ($hasToggle && isApproved($item)) {
            $isActive = $item[$toggleField] ?? 1;
            $newValue = $isActive ? 0 : 1;
            $icon = $isActive ? 'fa-pause' : 'fa-play';
            $color = $isActive ? 'yellow' : 'green';
            $title = $isActive ? '停止' : '公開';
            
            echo "<form method=\"POST\" class=\"inline\">
                <input type=\"hidden\" name=\"csrf_token\" value=\"" . htmlspecialchars($csrfToken) . "\">
                <input type=\"hidden\" name=\"item_id\" value=\"{$item['id']}\">
                <input type=\"hidden\" name=\"new_value\" value=\"{$newValue}\">
                <button type=\"submit\" name=\"{$toggleAction}\" value=\"1\" class=\"text-{$color}-500 hover:text-{$color}-700 mx-1\" title=\"{$title}\">
                    <i class=\"fas {$icon}\"></i>
                </button>
            </form>";
        }
        
        // クリエイターの場合：審査申請ボタン
        if (!isAdmin() && $hasApproval && canSubmitForApproval($item)) {
            echo "<form method=\"POST\" class=\"inline\">
                <input type=\"hidden\" name=\"csrf_token\" value=\"" . htmlspecialchars($csrfToken) . "\">
                <input type=\"hidden\" name=\"item_id\" value=\"{$item['id']}\">
                <button type=\"submit\" name=\"submit_for_approval\" value=\"1\" class=\"text-purple-500 hover:text-purple-700 mx-1\" title=\"審査申請\">
                    <i class=\"fas fa-paper-plane\"></i>
                </button>
            </form>";
        }
        
        // アーカイブボタン
        echo "<a href=\"?archive={$item['id']}\" onclick=\"return confirm('アーカイブしますか？')\" class=\"text-orange-500 hover:text-orange-700 mx-1\" title=\"アーカイブ\"><i class=\"fas fa-archive\"></i></a>";
    }
    
    return ob_get_clean();
}

// renderContentHeader と renderBulkScript は admin-ui.php で定義済み
