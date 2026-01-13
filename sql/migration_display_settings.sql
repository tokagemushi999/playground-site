-- ============================================
-- マイグレーション: 表示設定と参考作品紐づけ
-- ============================================

-- ============================================
-- サービスと作品の紐づけテーブル
-- ============================================
CREATE TABLE IF NOT EXISTS service_works (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    work_id INT NOT NULL,
    sort_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_service_work (service_id, work_id),
    INDEX idx_service (service_id),
    INDEX idx_work (work_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- worksテーブルに表示場所フラグを追加
-- ============================================
ALTER TABLE works ADD COLUMN show_in_gallery TINYINT(1) DEFAULT 1 COMMENT 'ギャラリーに表示';
ALTER TABLE works ADD COLUMN show_in_creator_page TINYINT(1) DEFAULT 1 COMMENT 'クリエイターページに表示';
ALTER TABLE works ADD COLUMN show_in_top TINYINT(1) DEFAULT 1 COMMENT 'トップページに表示';

-- ============================================
-- servicesテーブルに表示場所フラグを追加
-- ============================================
ALTER TABLE services ADD COLUMN show_in_gallery TINYINT(1) DEFAULT 0 COMMENT 'ギャラリーに表示';
ALTER TABLE services ADD COLUMN show_in_creator_page TINYINT(1) DEFAULT 1 COMMENT 'クリエイターページに表示';
ALTER TABLE services ADD COLUMN show_in_top TINYINT(1) DEFAULT 0 COMMENT 'トップページに表示';
ALTER TABLE services ADD COLUMN show_in_store TINYINT(1) DEFAULT 1 COMMENT 'ストアに表示';

-- ============================================
-- productsテーブルに表示場所フラグを追加
-- ============================================
ALTER TABLE products ADD COLUMN show_in_gallery TINYINT(1) DEFAULT 0 COMMENT 'ギャラリーに表示';
ALTER TABLE products ADD COLUMN show_in_creator_page TINYINT(1) DEFAULT 1 COMMENT 'クリエイターページに表示';
ALTER TABLE products ADD COLUMN show_in_top TINYINT(1) DEFAULT 0 COMMENT 'トップページに表示';
