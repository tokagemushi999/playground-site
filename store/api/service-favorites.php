<?php
/**
 * サービスお気に入りAPI
 */
session_start();
require_once '../../includes/db.php';
require_once '../../includes/member-auth.php';

header('Content-Type: application/json');

// ログインチェック
if (!isLoggedIn()) {
    echo json_encode(['success' => false, 'error' => 'ログインが必要です', 'login_required' => true]);
    exit;
}

$db = getDB();
$member = getCurrentMember();
$memberId = $member['id'];
$action = $_POST['action'] ?? $_GET['action'] ?? '';
$serviceId = (int)($_POST['service_id'] ?? $_GET['service_id'] ?? 0);

try {
    switch ($action) {
        case 'add':
            if ($serviceId <= 0) {
                echo json_encode(['success' => false, 'error' => 'サービスIDが無効です']);
                exit;
            }
            
            // 既にお気に入りか確認
            $stmt = $db->prepare("SELECT id FROM service_favorites WHERE member_id = ? AND service_id = ?");
            $stmt->execute([$memberId, $serviceId]);
            if ($stmt->fetch()) {
                echo json_encode(['success' => true, 'message' => '既にお気に入りに追加されています', 'is_favorite' => true]);
                exit;
            }
            
            $stmt = $db->prepare("INSERT INTO service_favorites (member_id, service_id) VALUES (?, ?)");
            $stmt->execute([$memberId, $serviceId]);
            
            echo json_encode(['success' => true, 'message' => 'お気に入りに追加しました', 'is_favorite' => true]);
            break;
            
        case 'remove':
            if ($serviceId <= 0) {
                echo json_encode(['success' => false, 'error' => 'サービスIDが無効です']);
                exit;
            }
            
            $stmt = $db->prepare("DELETE FROM service_favorites WHERE member_id = ? AND service_id = ?");
            $stmt->execute([$memberId, $serviceId]);
            
            echo json_encode(['success' => true, 'message' => 'お気に入りから削除しました', 'is_favorite' => false]);
            break;
            
        case 'toggle':
            if ($serviceId <= 0) {
                echo json_encode(['success' => false, 'error' => 'サービスIDが無効です']);
                exit;
            }
            
            // 現在の状態を確認
            $stmt = $db->prepare("SELECT id FROM service_favorites WHERE member_id = ? AND service_id = ?");
            $stmt->execute([$memberId, $serviceId]);
            
            if ($stmt->fetch()) {
                // 削除
                $stmt = $db->prepare("DELETE FROM service_favorites WHERE member_id = ? AND service_id = ?");
                $stmt->execute([$memberId, $serviceId]);
                echo json_encode(['success' => true, 'message' => 'お気に入りから削除しました', 'is_favorite' => false]);
            } else {
                // 追加
                $stmt = $db->prepare("INSERT INTO service_favorites (member_id, service_id) VALUES (?, ?)");
                $stmt->execute([$memberId, $serviceId]);
                echo json_encode(['success' => true, 'message' => 'お気に入りに追加しました', 'is_favorite' => true]);
            }
            break;
            
        case 'check':
            if ($serviceId <= 0) {
                echo json_encode(['success' => false, 'error' => 'サービスIDが無効です']);
                exit;
            }
            
            $stmt = $db->prepare("SELECT id FROM service_favorites WHERE member_id = ? AND service_id = ?");
            $stmt->execute([$memberId, $serviceId]);
            $isFavorite = (bool)$stmt->fetch();
            
            echo json_encode(['success' => true, 'is_favorite' => $isFavorite]);
            break;
            
        case 'list':
            $stmt = $db->prepare("
                SELECT s.*, c.name as creator_name, c.image as creator_image, c.slug as creator_slug,
                       sf.created_at as favorited_at
                FROM service_favorites sf
                JOIN services s ON sf.service_id = s.id
                LEFT JOIN creators c ON s.creator_id = c.id
                WHERE sf.member_id = ? AND s.status = 'active'
                ORDER BY sf.created_at DESC
            ");
            $stmt->execute([$memberId]);
            $favorites = $stmt->fetchAll(PDO::FETCH_ASSOC);
            
            echo json_encode(['success' => true, 'favorites' => $favorites, 'count' => count($favorites)]);
            break;
            
        default:
            echo json_encode(['success' => false, 'error' => '無効なアクションです']);
    }
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'error' => 'データベースエラー: ' . $e->getMessage()]);
}
