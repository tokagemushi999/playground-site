-- =============================================
-- 商品・サービス審査機能マイグレーション
-- =============================================

-- 1. 商品テーブルに審査カラムを追加
ALTER TABLE products ADD COLUMN approval_status ENUM('draft', 'pending', 'approved', 'rejected') DEFAULT 'approved' COMMENT '審査ステータス';
ALTER TABLE products ADD COLUMN approval_note TEXT DEFAULT NULL COMMENT '審査コメント（却下理由など）';
ALTER TABLE products ADD COLUMN submitted_at DATETIME DEFAULT NULL COMMENT '審査申請日時';
ALTER TABLE products ADD COLUMN approved_at DATETIME DEFAULT NULL COMMENT '承認日時';
ALTER TABLE products ADD COLUMN approved_by INT DEFAULT NULL COMMENT '承認者（管理者ID）';

-- 2. サービステーブルに審査カラムを追加
ALTER TABLE services ADD COLUMN approval_status ENUM('draft', 'pending', 'approved', 'rejected') DEFAULT 'approved' COMMENT '審査ステータス';
ALTER TABLE services ADD COLUMN approval_note TEXT DEFAULT NULL COMMENT '審査コメント（却下理由など）';
ALTER TABLE services ADD COLUMN submitted_at DATETIME DEFAULT NULL COMMENT '審査申請日時';
ALTER TABLE services ADD COLUMN approved_at DATETIME DEFAULT NULL COMMENT '承認日時';
ALTER TABLE services ADD COLUMN approved_by INT DEFAULT NULL COMMENT '承認者（管理者ID）';

-- 3. 作品テーブルに審査カラムを追加
ALTER TABLE works ADD COLUMN approval_status ENUM('draft', 'pending', 'approved', 'rejected') DEFAULT 'approved' COMMENT '審査ステータス';
ALTER TABLE works ADD COLUMN approval_note TEXT DEFAULT NULL COMMENT '審査コメント（却下理由など）';
ALTER TABLE works ADD COLUMN submitted_at DATETIME DEFAULT NULL COMMENT '審査申請日時';
ALTER TABLE works ADD COLUMN approved_at DATETIME DEFAULT NULL COMMENT '承認日時';
ALTER TABLE works ADD COLUMN approved_by INT DEFAULT NULL COMMENT '承認者（管理者ID）';

-- 4. インデックス追加
ALTER TABLE products ADD INDEX idx_approval_status (approval_status);
ALTER TABLE services ADD INDEX idx_approval_status (approval_status);
ALTER TABLE works ADD INDEX idx_approval_status (approval_status);

-- 5. 既存データは全て承認済みに
UPDATE products SET approval_status = 'approved' WHERE approval_status IS NULL;
UPDATE services SET approval_status = 'approved' WHERE approval_status IS NULL;
UPDATE works SET approval_status = 'approved' WHERE approval_status IS NULL;

-- 6. クリエイター編集権限フラグ（オプション）
ALTER TABLE creators ADD COLUMN can_create_products TINYINT(1) DEFAULT 1 COMMENT '商品作成権限';
ALTER TABLE creators ADD COLUMN can_create_services TINYINT(1) DEFAULT 1 COMMENT 'サービス作成権限';
ALTER TABLE creators ADD COLUMN can_create_works TINYINT(1) DEFAULT 1 COMMENT '作品作成権限';

-- 7. 審査履歴テーブル
CREATE TABLE IF NOT EXISTS approval_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    item_type ENUM('product', 'service', 'work') NOT NULL,
    item_id INT NOT NULL,
    creator_id INT NOT NULL,
    old_status VARCHAR(20) DEFAULT NULL,
    new_status VARCHAR(20) NOT NULL,
    note TEXT DEFAULT NULL,
    admin_id INT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_item (item_type, item_id),
    INDEX idx_creator (creator_id),
    INDEX idx_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
