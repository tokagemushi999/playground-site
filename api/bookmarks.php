<?php
/**
 * しおり管理API
 * GET: work_id を受け取り、しおり一覧を返す
 * POST: work_id, page_number, title を受け取り、しおりを追加
 * DELETE: work_id, page_number を受け取り、しおりを削除
 */
header('Content-Type: application/json');

session_start();
require_once '../includes/db.php';
require_once '../includes/member-auth.php';
require_once '../includes/defaults.php';

// ログインチェック
if (!isLoggedIn() || !isset($_SESSION['member_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$memberId = $_SESSION['member_id'];
$db = getDB();

// テーブル存在確認
if (!tableExists($db, 'reading_bookmarks')) {
    // テーブル作成
    $db->exec("
        CREATE TABLE IF NOT EXISTS reading_bookmarks (
            id INT AUTO_INCREMENT PRIMARY KEY,
            member_id INT NOT NULL COMMENT '会員ID',
            work_id INT NOT NULL COMMENT '作品ID',
            page_number INT NOT NULL COMMENT 'ページ番号',
            title VARCHAR(100) DEFAULT NULL COMMENT 'しおりタイトル',
            note TEXT DEFAULT NULL COMMENT 'メモ',
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY unique_bookmark (member_id, work_id, page_number),
            INDEX idx_member (member_id),
            INDEX idx_work (work_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

// GETの場合はしおり一覧を取得
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $workId = isset($_GET['work_id']) ? (int)$_GET['work_id'] : 0;
    
    if (!$workId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing work_id']);
        exit;
    }
    
    try {
        $stmt = $db->prepare("
            SELECT page_number, title, note, created_at
            FROM reading_bookmarks
            WHERE member_id = ? AND work_id = ?
            ORDER BY page_number ASC
        ");
        $stmt->execute([$memberId, $workId]);
        $bookmarks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        echo json_encode([
            'success' => true,
            'bookmarks' => $bookmarks
        ]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

// POSTの場合はしおりを追加
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $workId = isset($input['work_id']) ? (int)$input['work_id'] : 0;
    $pageNumber = isset($input['page_number']) ? (int)$input['page_number'] : 0;
    $title = trim($input['title'] ?? '');
    $note = trim($input['note'] ?? '');
    
    if (!$workId || $pageNumber < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
        exit;
    }
    
    // デフォルトタイトル
    if (!$title) {
        $title = $pageNumber . 'ページ';
    }
    
    try {
        // しおりの上限チェック（1作品につき20個まで）
        $stmt = $db->prepare("SELECT COUNT(*) FROM reading_bookmarks WHERE member_id = ? AND work_id = ?");
        $stmt->execute([$memberId, $workId]);
        $count = $stmt->fetchColumn();
        
        if ($count >= 20) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'しおりは1作品につき20個までです']);
            exit;
        }
        
        // 同じページにしおりがあれば更新、なければ追加
        $stmt = $db->prepare("
            INSERT INTO reading_bookmarks (member_id, work_id, page_number, title, note)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE title = VALUES(title), note = VALUES(note)
        ");
        $stmt->execute([$memberId, $workId, $pageNumber, $title, $note]);
        
        echo json_encode([
            'success' => true,
            'message' => 'しおりを追加しました',
            'bookmark' => [
                'page_number' => $pageNumber,
                'title' => $title,
                'note' => $note
            ]
        ]);
    } catch (PDOException $e) {
        error_log("Bookmark error: " . $e->getMessage());
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

// DELETEの場合はしおりを削除
if ($_SERVER['REQUEST_METHOD'] === 'DELETE') {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $workId = isset($input['work_id']) ? (int)$input['work_id'] : 0;
    $pageNumber = isset($input['page_number']) ? (int)$input['page_number'] : 0;
    
    if (!$workId || $pageNumber < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
        exit;
    }
    
    try {
        $stmt = $db->prepare("
            DELETE FROM reading_bookmarks 
            WHERE member_id = ? AND work_id = ? AND page_number = ?
        ");
        $stmt->execute([$memberId, $workId, $pageNumber]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'しおりを削除しました']);
        } else {
            echo json_encode(['success' => false, 'error' => 'しおりが見つかりません']);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
