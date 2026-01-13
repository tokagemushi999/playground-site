-- ============================================
-- マイグレーション: 2026-01-06
-- 注文管理・クリエイター情報の拡張
-- ============================================

-- ============================================
-- ordersテーブルの拡張
-- ============================================

-- 配送業者
ALTER TABLE orders ADD COLUMN shipping_carrier VARCHAR(50) NULL AFTER tracking_number;

-- 発送日時
ALTER TABLE orders ADD COLUMN shipped_at DATETIME NULL;

-- 完了日時
ALTER TABLE orders ADD COLUMN completed_at DATETIME NULL;

-- ============================================
-- creatorsテーブルの拡張（連絡先）
-- ============================================

-- メールアドレス（支払通知書送信用）
ALTER TABLE creators ADD COLUMN email VARCHAR(255) NULL;

-- ============================================
-- creatorsテーブルの拡張（銀行口座情報）
-- ============================================

-- 銀行名
ALTER TABLE creators ADD COLUMN bank_name VARCHAR(100) NULL;

-- 支店名
ALTER TABLE creators ADD COLUMN bank_branch VARCHAR(100) NULL;

-- 口座種別（普通/当座）
ALTER TABLE creators ADD COLUMN bank_account_type VARCHAR(20) NULL;

-- 口座番号
ALTER TABLE creators ADD COLUMN bank_account_number VARCHAR(20) NULL;

-- 口座名義（カタカナ）
ALTER TABLE creators ADD COLUMN bank_account_name VARCHAR(100) NULL;

-- 販売手数料率（%）
ALTER TABLE creators ADD COLUMN commission_rate DECIMAL(5,2) DEFAULT 20.00;

-- 販売手数料単価（円/件）
ALTER TABLE creators ADD COLUMN commission_per_item INT DEFAULT 0;

-- ============================================
-- creatorsテーブルの拡張（源泉徴収対応）
-- ============================================

-- 事業者区分（individual:個人 / corporation:法人）
ALTER TABLE creators ADD COLUMN business_type VARCHAR(20) DEFAULT 'individual';

-- 源泉徴収対象（個人の場合デフォルトで対象）
ALTER TABLE creators ADD COLUMN withholding_tax_required TINYINT(1) DEFAULT 1;

-- ============================================
-- 支払履歴テーブル（新規作成）
-- ============================================

CREATE TABLE IF NOT EXISTS creator_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    creator_id INT NOT NULL,
    payment_date DATE NOT NULL COMMENT '支払日',
    target_year INT NOT NULL COMMENT '対象年',
    target_month INT NOT NULL COMMENT '対象月',
    gross_sales INT NOT NULL DEFAULT 0 COMMENT '売上総額',
    commission_amount INT NOT NULL DEFAULT 0 COMMENT '手数料',
    withholding_tax INT NOT NULL DEFAULT 0 COMMENT '源泉徴収税額',
    net_payment INT NOT NULL DEFAULT 0 COMMENT '支払額',
    order_count INT NOT NULL DEFAULT 0 COMMENT '注文件数',
    item_count INT NOT NULL DEFAULT 0 COMMENT '販売点数',
    status VARCHAR(20) DEFAULT 'pending' COMMENT 'pending/completed/cancelled',
    transfer_date DATE NULL COMMENT '振込実行日',
    notification_sent_at DATETIME NULL COMMENT '通知書送信日時',
    notes TEXT NULL COMMENT '備考',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_creator_id (creator_id),
    INDEX idx_target_period (target_year, target_month),
    INDEX idx_status (status),
    FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 源泉徴収納付管理テーブル（新規作成）
-- ============================================

CREATE TABLE IF NOT EXISTS withholding_tax_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    target_year INT NOT NULL COMMENT '対象年',
    target_month INT NOT NULL COMMENT '対象月',
    total_amount INT NOT NULL DEFAULT 0 COMMENT '納付額合計',
    due_date DATE NOT NULL COMMENT '納付期限',
    paid_date DATE NULL COMMENT '納付日',
    status VARCHAR(20) DEFAULT 'pending' COMMENT 'pending/paid',
    notes TEXT NULL COMMENT '備考',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_target_period (target_year, target_month),
    INDEX idx_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- クリエイター契約テーブル（新規作成）
-- ============================================

CREATE TABLE IF NOT EXISTS creator_contracts (
    id INT AUTO_INCREMENT PRIMARY KEY,
    creator_id INT NOT NULL,
    token VARCHAR(64) NOT NULL COMMENT '認証トークン',
    version INT DEFAULT 1 COMMENT '契約バージョン',
    status VARCHAR(20) DEFAULT 'pending' COMMENT 'pending/agreed/cancelled',
    update_reason TEXT NULL COMMENT '更新理由',
    previous_commission_rate DECIMAL(5,2) NULL COMMENT '変更前の手数料率',
    previous_commission_per_item INT NULL COMMENT '変更前の単価手数料',
    new_commission_rate DECIMAL(5,2) NULL COMMENT '変更後の手数料率',
    new_commission_per_item INT NULL COMMENT '変更後の単価手数料',
    sent_at DATETIME NULL COMMENT '送信日時',
    agreed_at DATETIME NULL COMMENT '同意日時',
    agreed_name VARCHAR(255) NULL COMMENT '同意時の署名',
    agreed_ip VARCHAR(45) NULL COMMENT '同意時のIPアドレス',
    agreed_user_agent TEXT NULL COMMENT '同意時のユーザーエージェント',
    gdrive_file_id VARCHAR(255) NULL COMMENT 'Google Drive ファイルID',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_token (token),
    INDEX idx_creator_id (creator_id),
    INDEX idx_status (status),
    INDEX idx_version (creator_id, version),
    FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 書類保存履歴テーブル（新規作成）
-- ============================================

CREATE TABLE IF NOT EXISTS document_archives (
    id INT AUTO_INCREMENT PRIMARY KEY,
    document_type VARCHAR(50) NOT NULL COMMENT 'receipt/payment_notice/invoice/contract/withholding',
    reference_id INT NULL COMMENT '参照先ID（注文ID、支払ID等）',
    reference_type VARCHAR(50) NULL COMMENT '参照先タイプ',
    filename VARCHAR(255) NOT NULL COMMENT 'ファイル名',
    gdrive_file_id VARCHAR(255) NULL COMMENT 'Google Drive ファイルID',
    gdrive_url VARCHAR(500) NULL COMMENT 'Google Drive URL',
    file_size INT NULL COMMENT 'ファイルサイズ（バイト）',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_document_type (document_type),
    INDEX idx_reference (reference_type, reference_id),
    INDEX idx_gdrive_file_id (gdrive_file_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 既存テーブルへのカラム追加（契約更新機能）
-- 既にテーブルが存在する場合に実行
-- ============================================

-- 契約バージョン
ALTER TABLE creator_contracts ADD COLUMN version INT DEFAULT 1 COMMENT '契約バージョン';

-- 更新理由
ALTER TABLE creator_contracts ADD COLUMN update_reason TEXT NULL COMMENT '更新理由';

-- 変更前の手数料率
ALTER TABLE creator_contracts ADD COLUMN previous_commission_rate DECIMAL(5,2) NULL COMMENT '変更前の手数料率';

-- 変更前の単価手数料
ALTER TABLE creator_contracts ADD COLUMN previous_commission_per_item INT NULL COMMENT '変更前の単価手数料';

-- 変更後の手数料率
ALTER TABLE creator_contracts ADD COLUMN new_commission_rate DECIMAL(5,2) NULL COMMENT '変更後の手数料率';

-- 変更後の単価手数料
ALTER TABLE creator_contracts ADD COLUMN new_commission_per_item INT NULL COMMENT '変更後の単価手数料';

-- インデックス追加
ALTER TABLE creator_contracts ADD INDEX idx_version (creator_id, version);
