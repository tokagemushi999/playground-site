-- =============================================
-- 取引関連テーブルの構造修正マイグレーション
-- =============================================
-- このファイルを phpMyAdmin で実行してください
-- エラーが出た行はスキップしてOKです
-- =============================================

-- =============================================
-- 0. service_quotes テーブルの構造修正（既存テーブルがある場合）
-- =============================================
-- 古い構造の場合はDROPして再作成
-- 注意：既存データがある場合は事前にバックアップしてください
DROP TABLE IF EXISTS service_quotes;

-- =============================================
-- 1. service_attachments テーブル（再作成）
-- =============================================
DROP TABLE IF EXISTS service_attachments;

CREATE TABLE service_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    transaction_id INT NOT NULL,
    original_name VARCHAR(255) NOT NULL COMMENT '元ファイル名',
    stored_name VARCHAR(255) NOT NULL COMMENT '保存ファイル名',
    file_path VARCHAR(500) NOT NULL COMMENT 'ファイルパス',
    file_size INT NOT NULL DEFAULT 0 COMMENT 'ファイルサイズ',
    mime_type VARCHAR(100) NOT NULL DEFAULT '',
    file_type ENUM('image', 'document', 'video', 'audio', 'archive', 'other') DEFAULT 'other',
    is_deliverable TINYINT(1) DEFAULT 0 COMMENT '納品物フラグ',
    download_count INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_message (message_id),
    INDEX idx_transaction (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 2. service_messages テーブルのカラム追加
-- =============================================
-- 既に存在するカラムはエラーになるのでスキップ

ALTER TABLE service_messages ADD COLUMN visible_to_customer TINYINT(1) DEFAULT 1;
ALTER TABLE service_messages ADD COLUMN visible_to_creator TINYINT(1) DEFAULT 1;
ALTER TABLE service_messages ADD COLUMN visible_to_admin TINYINT(1) DEFAULT 1;
ALTER TABLE service_messages ADD COLUMN read_by_customer TINYINT(1) DEFAULT 0;
ALTER TABLE service_messages ADD COLUMN read_by_creator TINYINT(1) DEFAULT 0;
ALTER TABLE service_messages ADD COLUMN read_by_admin TINYINT(1) DEFAULT 0;
ALTER TABLE service_messages ADD COLUMN read_at_customer DATETIME DEFAULT NULL;
ALTER TABLE service_messages ADD COLUMN read_at_creator DATETIME DEFAULT NULL;
ALTER TABLE service_messages ADD COLUMN message_type ENUM('text', 'quote', 'file', 'system') DEFAULT 'text';
ALTER TABLE service_messages ADD COLUMN quote_id INT DEFAULT NULL;
ALTER TABLE service_messages ADD COLUMN sender_type ENUM('customer', 'creator', 'admin') DEFAULT 'customer';
ALTER TABLE service_messages ADD COLUMN sender_id INT DEFAULT NULL;
ALTER TABLE service_messages ADD COLUMN sender_name VARCHAR(100) DEFAULT NULL;

-- =============================================
-- 3. service_transactions テーブルのカラム追加
-- =============================================
ALTER TABLE service_transactions ADD COLUMN request_title VARCHAR(255) DEFAULT NULL;
ALTER TABLE service_transactions ADD COLUMN request_detail TEXT DEFAULT NULL;
ALTER TABLE service_transactions ADD COLUMN request_budget INT DEFAULT NULL;
ALTER TABLE service_transactions ADD COLUMN request_deadline DATE DEFAULT NULL;
ALTER TABLE service_transactions ADD COLUMN final_price INT DEFAULT NULL;
ALTER TABLE service_transactions ADD COLUMN tax_amount INT DEFAULT NULL;
ALTER TABLE service_transactions ADD COLUMN total_amount INT DEFAULT NULL;
ALTER TABLE service_transactions ADD COLUMN payment_method VARCHAR(50) DEFAULT NULL;
ALTER TABLE service_transactions ADD COLUMN payment_id VARCHAR(255) DEFAULT NULL;
ALTER TABLE service_transactions ADD COLUMN paid_at DATETIME DEFAULT NULL;
ALTER TABLE service_transactions ADD COLUMN delivered_at DATETIME DEFAULT NULL;
ALTER TABLE service_transactions ADD COLUMN completed_at DATETIME DEFAULT NULL;

-- =============================================
-- 4. service_quotes テーブル
-- =============================================
CREATE TABLE IF NOT EXISTS service_quotes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    version INT DEFAULT 1,
    base_price INT NOT NULL COMMENT '基本料金',
    options_price INT DEFAULT 0,
    subtotal INT NOT NULL,
    tax_rate DECIMAL(4,2) DEFAULT 10.00,
    tax_amount INT NOT NULL,
    total_amount INT NOT NULL,
    quote_items JSON DEFAULT NULL,
    estimated_delivery_days INT DEFAULT NULL,
    delivery_date DATE DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    status ENUM('draft', 'sent', 'accepted', 'rejected', 'expired') DEFAULT 'draft',
    sent_at DATETIME DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_transaction (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 5. service_notifications テーブル
-- =============================================
CREATE TABLE IF NOT EXISTS service_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    notification_type VARCHAR(50) NOT NULL,
    recipient_type ENUM('customer', 'creator', 'admin') NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    is_sent TINYINT(1) DEFAULT 0,
    sent_at DATETIME DEFAULT NULL,
    error_message TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_transaction (transaction_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- =============================================
-- 6. 既存データの初期化
-- =============================================
UPDATE service_messages SET 
    visible_to_customer = 1, 
    visible_to_creator = 1, 
    visible_to_admin = 1 
WHERE visible_to_customer IS NULL;

UPDATE service_messages SET 
    read_by_customer = 0, 
    read_by_creator = 0, 
    read_by_admin = 0 
WHERE read_by_customer IS NULL;

