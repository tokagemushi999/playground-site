-- ============================================================
-- サービス取引・メッセージシステム マイグレーション
-- ============================================================

-- 1. サービス取引テーブル（見積もり〜完了まで管理）
CREATE TABLE IF NOT EXISTS service_transactions (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_code VARCHAR(50) UNIQUE NOT NULL COMMENT '取引コード（ST-YYYYMMDD-XXXX）',
    service_id INT NOT NULL COMMENT 'サービスID',
    creator_id INT NOT NULL COMMENT 'クリエイターID',
    member_id INT DEFAULT NULL COMMENT '会員ID（ログインユーザー）',
    guest_email VARCHAR(255) DEFAULT NULL COMMENT 'ゲストメール',
    guest_name VARCHAR(100) DEFAULT NULL COMMENT 'ゲスト名',
    
    -- ステータス
    status ENUM(
        'inquiry',           -- 問い合わせ中
        'quote_pending',     -- 見積もり待ち
        'quote_sent',        -- 見積もり送信済み
        'quote_revision',    -- 見積もり修正依頼
        'quote_accepted',    -- 見積もり承諾
        'payment_pending',   -- 決済待ち
        'paid',              -- 決済完了
        'in_progress',       -- 制作中
        'delivered',         -- 納品済み
        'revision_requested',-- 修正依頼
        'completed',         -- 完了
        'cancelled',         -- キャンセル
        'refunded'           -- 返金済み
    ) DEFAULT 'inquiry',
    
    -- 依頼内容
    request_title VARCHAR(255) DEFAULT NULL COMMENT '依頼タイトル',
    request_detail TEXT DEFAULT NULL COMMENT '依頼詳細',
    request_budget INT DEFAULT NULL COMMENT '希望予算',
    request_deadline DATE DEFAULT NULL COMMENT '希望納期',
    
    -- 決定金額
    final_price INT DEFAULT NULL COMMENT '最終価格',
    tax_amount INT DEFAULT NULL COMMENT '税額',
    total_amount INT DEFAULT NULL COMMENT '合計金額',
    
    -- 決済情報
    payment_method VARCHAR(50) DEFAULT NULL,
    payment_id VARCHAR(255) DEFAULT NULL COMMENT 'Stripe等のID',
    paid_at DATETIME DEFAULT NULL,
    
    -- 納品情報
    delivered_at DATETIME DEFAULT NULL,
    completed_at DATETIME DEFAULT NULL,
    
    -- メタ
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_service (service_id),
    INDEX idx_creator (creator_id),
    INDEX idx_member (member_id),
    INDEX idx_status (status),
    INDEX idx_code (transaction_code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 2. 見積もりテーブル
CREATE TABLE IF NOT EXISTS service_quotes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    version INT DEFAULT 1 COMMENT '見積もりバージョン',
    
    -- 見積もり内容
    quote_items JSON DEFAULT NULL COMMENT '明細（JSON配列）',
    subtotal INT NOT NULL DEFAULT 0 COMMENT '小計',
    tax_rate DECIMAL(5,2) DEFAULT 10.00 COMMENT '税率',
    tax_amount INT DEFAULT 0 COMMENT '税額',
    total_amount INT NOT NULL DEFAULT 0 COMMENT '合計',
    
    -- 納期
    estimated_days INT DEFAULT NULL COMMENT '見積もり日数',
    estimated_deadline DATE DEFAULT NULL COMMENT '納品予定日',
    
    -- 備考
    notes TEXT DEFAULT NULL COMMENT '備考',
    
    -- ステータス
    status ENUM('draft', 'sent', 'accepted', 'rejected', 'expired') DEFAULT 'draft',
    sent_at DATETIME DEFAULT NULL,
    accepted_at DATETIME DEFAULT NULL,
    expires_at DATETIME DEFAULT NULL COMMENT '有効期限',
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_transaction (transaction_id),
    FOREIGN KEY (transaction_id) REFERENCES service_transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 3. メッセージテーブル
CREATE TABLE IF NOT EXISTS service_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    
    -- 送信者
    sender_type ENUM('customer', 'creator', 'admin') NOT NULL,
    sender_id INT DEFAULT NULL COMMENT 'member_id or creator_id or admin user',
    sender_name VARCHAR(100) DEFAULT NULL,
    
    -- メッセージ内容
    message TEXT NOT NULL,
    message_type ENUM('text', 'quote', 'file', 'system') DEFAULT 'text',
    
    -- 関連データ
    quote_id INT DEFAULT NULL COMMENT '見積もりID（見積もり送信時）',
    
    -- 既読管理
    read_by_customer TINYINT(1) DEFAULT 0,
    read_by_creator TINYINT(1) DEFAULT 0,
    read_by_admin TINYINT(1) DEFAULT 0,
    read_at_customer DATETIME DEFAULT NULL,
    read_at_creator DATETIME DEFAULT NULL,
    
    -- 公開範囲
    visible_to_customer TINYINT(1) DEFAULT 1,
    visible_to_creator TINYINT(1) DEFAULT 1,
    visible_to_admin TINYINT(1) DEFAULT 1,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_transaction (transaction_id),
    INDEX idx_sender (sender_type, sender_id),
    INDEX idx_created (created_at),
    FOREIGN KEY (transaction_id) REFERENCES service_transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. 添付ファイルテーブル
CREATE TABLE IF NOT EXISTS service_attachments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    message_id INT NOT NULL,
    transaction_id INT NOT NULL,
    
    -- ファイル情報
    original_name VARCHAR(255) NOT NULL COMMENT '元ファイル名',
    stored_name VARCHAR(255) NOT NULL COMMENT '保存ファイル名',
    file_path VARCHAR(500) NOT NULL COMMENT 'ファイルパス',
    file_size INT NOT NULL COMMENT 'ファイルサイズ（bytes）',
    mime_type VARCHAR(100) NOT NULL,
    file_type ENUM('image', 'document', 'video', 'audio', 'archive', 'other') DEFAULT 'other',
    
    -- アクセス制御
    is_deliverable TINYINT(1) DEFAULT 0 COMMENT '納品物フラグ',
    download_count INT DEFAULT 0,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_message (message_id),
    INDEX idx_transaction (transaction_id),
    FOREIGN KEY (message_id) REFERENCES service_messages(id) ON DELETE CASCADE,
    FOREIGN KEY (transaction_id) REFERENCES service_transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. 取引通知テーブル（メール送信ログ）
CREATE TABLE IF NOT EXISTS service_notifications (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    
    notification_type ENUM(
        'inquiry_received',      -- 問い合わせ受信
        'quote_sent',            -- 見積もり送信
        'quote_accepted',        -- 見積もり承諾
        'payment_completed',     -- 決済完了
        'message_received',      -- メッセージ受信
        'file_uploaded',         -- ファイルアップロード
        'delivery_completed',    -- 納品完了
        'revision_requested',    -- 修正依頼
        'transaction_completed', -- 取引完了
        'reminder'               -- リマインダー
    ) NOT NULL,
    
    recipient_type ENUM('customer', 'creator', 'admin') NOT NULL,
    recipient_email VARCHAR(255) NOT NULL,
    
    subject VARCHAR(255) NOT NULL,
    body TEXT NOT NULL,
    
    sent_at DATETIME DEFAULT NULL,
    is_sent TINYINT(1) DEFAULT 0,
    error_message TEXT DEFAULT NULL,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    
    INDEX idx_transaction (transaction_id),
    INDEX idx_sent (is_sent),
    FOREIGN KEY (transaction_id) REFERENCES service_transactions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 6. クリエイターのリクエスト受付設定（creatorsテーブルに追加）
ALTER TABLE creators
ADD COLUMN IF NOT EXISTS accepts_requests TINYINT(1) DEFAULT 1 COMMENT 'リクエスト受付中',
ADD COLUMN IF NOT EXISTS request_response_time VARCHAR(100) DEFAULT NULL COMMENT '返信目安時間',
ADD COLUMN IF NOT EXISTS request_min_budget INT DEFAULT NULL COMMENT '最低予算',
ADD COLUMN IF NOT EXISTS request_notes TEXT DEFAULT NULL COMMENT 'リクエスト時の注意事項';

-- 7. クリエイターログイン用（creatorsテーブルに追加）
ALTER TABLE creators
ADD COLUMN IF NOT EXISTS password VARCHAR(255) DEFAULT NULL COMMENT 'ログインパスワード',
ADD COLUMN IF NOT EXISTS email VARCHAR(255) DEFAULT NULL COMMENT 'メールアドレス',
ADD COLUMN IF NOT EXISTS last_login DATETIME DEFAULT NULL COMMENT '最終ログイン';

-- 8. キャンセル・返金・ゲストアクセス用カラム追加
ALTER TABLE service_transactions
ADD COLUMN IF NOT EXISTS cancelled_by VARCHAR(50) DEFAULT NULL COMMENT 'キャンセル者 (customer/creator/admin)',
ADD COLUMN IF NOT EXISTS cancel_reason TEXT DEFAULT NULL COMMENT 'キャンセル理由',
ADD COLUMN IF NOT EXISTS cancelled_at DATETIME DEFAULT NULL COMMENT 'キャンセル日時',
ADD COLUMN IF NOT EXISTS refund_amount INT DEFAULT NULL COMMENT '返金額',
ADD COLUMN IF NOT EXISTS refund_id VARCHAR(255) DEFAULT NULL COMMENT 'Stripe返金ID',
ADD COLUMN IF NOT EXISTS refunded_at DATETIME DEFAULT NULL COMMENT '返金日時',
ADD COLUMN IF NOT EXISTS guest_access_token VARCHAR(255) DEFAULT NULL COMMENT 'ゲストアクセストークン',
ADD COLUMN IF NOT EXISTS guest_token_expires DATETIME DEFAULT NULL COMMENT 'トークン有効期限';

-- 9. レビューテーブル
CREATE TABLE IF NOT EXISTS service_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    transaction_id INT NOT NULL,
    service_id INT NOT NULL,
    creator_id INT NOT NULL,
    member_id INT DEFAULT NULL,
    guest_name VARCHAR(255) DEFAULT NULL,
    rating TINYINT NOT NULL COMMENT '1-5の評価',
    title VARCHAR(255) DEFAULT NULL,
    comment TEXT DEFAULT NULL,
    creator_reply TEXT DEFAULT NULL COMMENT 'クリエイターの返信',
    creator_replied_at DATETIME DEFAULT NULL,
    is_public TINYINT(1) DEFAULT 1 COMMENT '公開フラグ',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_transaction (transaction_id),
    INDEX idx_service (service_id),
    INDEX idx_creator (creator_id),
    INDEX idx_rating (rating)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 7. インデックス追加
ALTER TABLE service_transactions ADD INDEX IF NOT EXISTS idx_guest_email (guest_email);
ALTER TABLE service_messages ADD INDEX IF NOT EXISTS idx_unread_customer (transaction_id, read_by_customer);
ALTER TABLE service_messages ADD INDEX IF NOT EXISTS idx_unread_creator (transaction_id, read_by_creator);
