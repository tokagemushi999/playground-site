<?php
/**
 * 読書進捗保存API
 * POST: work_id, page_number を受け取り、member_bookshelfに保存
 */
header('Content-Type: application/json');

session_start();
require_once '../includes/db.php';
require_once '../includes/member-auth.php';

// ログインチェック
if (!isLoggedIn() || !isset($_SESSION['member_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not logged in']);
    exit;
}

$memberId = $_SESSION['member_id'];
$db = getDB();

// GETの場合は進捗を取得
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $workId = isset($_GET['work_id']) ? (int)$_GET['work_id'] : 0;
    
    if (!$workId) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Missing work_id']);
        exit;
    }
    
    try {
        // 作品に紐づく商品を取得して、本棚から進捗を取得
        $stmt = $db->prepare("
            SELECT mb.last_read_page, mb.last_read_at
            FROM member_bookshelf mb
            JOIN products p ON mb.product_id = p.id
            WHERE mb.member_id = ? AND p.related_work_id = ?
        ");
        $stmt->execute([$memberId, $workId]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result && $result['last_read_page']) {
            echo json_encode([
                'success' => true,
                'page' => (int)$result['last_read_page'],
                'last_read_at' => $result['last_read_at']
            ]);
        } else {
            echo json_encode(['success' => true, 'page' => null]);
        }
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

// POSTの場合は進捗を保存
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
    $input = json_decode($rawInput, true);
    
    // sendBeaconはtext/plainで送られることがあるので対応
    if (!$input && $rawInput) {
        $input = json_decode($rawInput, true);
    }
    
    $workId = isset($input['work_id']) ? (int)$input['work_id'] : 0;
    $pageNumber = isset($input['page_number']) ? (int)$input['page_number'] : 0;
    
    if (!$workId || $pageNumber < 1) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid parameters']);
        exit;
    }
    
    try {
        // 作品に紐づく商品を取得
        $stmt = $db->prepare("SELECT id FROM products WHERE related_work_id = ? LIMIT 1");
        $stmt->execute([$workId]);
        $product = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$product) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Product not found']);
            exit;
        }
        
        // 本棚に存在するか確認
        $stmt = $db->prepare("SELECT id FROM member_bookshelf WHERE member_id = ? AND product_id = ?");
        $stmt->execute([$memberId, $product['id']]);
        $bookshelfItem = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$bookshelfItem) {
            // 本棚にない場合は保存しない（購入済み確認）
            http_response_code(403);
            echo json_encode(['success' => false, 'error' => 'Not purchased']);
            exit;
        }
        
        // 進捗を更新
        $stmt = $db->prepare("
            UPDATE member_bookshelf 
            SET last_read_page = ?, last_read_at = NOW()
            WHERE member_id = ? AND product_id = ?
        ");
        $stmt->execute([$pageNumber, $memberId, $product['id']]);
        
        echo json_encode(['success' => true, 'saved_page' => $pageNumber]);
    } catch (PDOException $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Database error']);
    }
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
