-- =============================================
-- クリエイターログイン権限管理マイグレーション
-- =============================================

-- 1. クリエイターテーブルにログイン関連カラムを追加
ALTER TABLE creators ADD COLUMN login_enabled TINYINT(1) DEFAULT 0 COMMENT 'ログイン許可フラグ';
ALTER TABLE creators ADD COLUMN last_login DATETIME DEFAULT NULL COMMENT '最終ログイン日時';
ALTER TABLE creators ADD COLUMN password_reset_token VARCHAR(64) DEFAULT NULL COMMENT 'パスワードリセットトークン';
ALTER TABLE creators ADD COLUMN password_reset_expires DATETIME DEFAULT NULL COMMENT 'リセットトークン有効期限';
ALTER TABLE creators ADD COLUMN password_set_token VARCHAR(64) DEFAULT NULL COMMENT '初回パスワード設定トークン';
ALTER TABLE creators ADD COLUMN password_set_expires DATETIME DEFAULT NULL COMMENT '初回設定トークン有効期限';
ALTER TABLE creators ADD COLUMN login_attempts INT DEFAULT 0 COMMENT 'ログイン試行回数';
ALTER TABLE creators ADD COLUMN locked_until DATETIME DEFAULT NULL COMMENT 'アカウントロック期限';

-- 2. インデックス追加
ALTER TABLE creators ADD INDEX idx_login_enabled (login_enabled);
ALTER TABLE creators ADD INDEX idx_password_reset_token (password_reset_token);
ALTER TABLE creators ADD INDEX idx_password_set_token (password_set_token);

-- 3. 既存のクリエイターでパスワードが設定されているものはログイン許可
UPDATE creators SET login_enabled = 1 WHERE password IS NOT NULL AND password != '';

-- 4. クリエイターログイン履歴テーブル
CREATE TABLE IF NOT EXISTS creator_login_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    creator_id INT NOT NULL,
    login_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    ip_address VARCHAR(45) DEFAULT NULL,
    user_agent TEXT DEFAULT NULL,
    status ENUM('success', 'failed', 'locked') DEFAULT 'success',
    
    INDEX idx_creator_id (creator_id),
    INDEX idx_login_at (login_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 5. クリエイター通知設定テーブル
CREATE TABLE IF NOT EXISTS creator_notification_settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    creator_id INT NOT NULL UNIQUE,
    
    -- メール通知設定
    notify_new_inquiry TINYINT(1) DEFAULT 1 COMMENT '新規問い合わせ',
    notify_quote_accepted TINYINT(1) DEFAULT 1 COMMENT '見積もり承諾',
    notify_payment_received TINYINT(1) DEFAULT 1 COMMENT '決済完了',
    notify_new_message TINYINT(1) DEFAULT 1 COMMENT '新規メッセージ',
    notify_review_received TINYINT(1) DEFAULT 1 COMMENT 'レビュー受信',
    notify_monthly_report TINYINT(1) DEFAULT 1 COMMENT '月次レポート',
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
