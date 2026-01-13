-- サービスお気に入りテーブル
CREATE TABLE IF NOT EXISTS service_favorites (
    id INT AUTO_INCREMENT PRIMARY KEY,
    member_id INT NOT NULL COMMENT '会員ID',
    service_id INT NOT NULL COMMENT 'サービスID',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_favorite (member_id, service_id),
    KEY idx_member (member_id),
    KEY idx_service (service_id),
    FOREIGN KEY (member_id) REFERENCES members(id) ON DELETE CASCADE,
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='サービスお気に入り';

-- サービスカテゴリテーブル（存在しない場合）
CREATE TABLE IF NOT EXISTS service_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL COMMENT 'カテゴリ名',
    slug VARCHAR(100) DEFAULT NULL COMMENT 'URL用スラッグ',
    icon VARCHAR(50) DEFAULT NULL COMMENT 'FontAwesomeアイコン',
    description TEXT DEFAULT NULL COMMENT '説明',
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY unique_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='サービスカテゴリ';

-- 初期カテゴリデータ（必要に応じて）
INSERT IGNORE INTO service_categories (name, slug, icon, sort_order) VALUES
('イラスト', 'illustration', 'fa-paint-brush', 1),
('動画・アニメーション', 'animation', 'fa-film', 2),
('マンガ', 'manga', 'fa-book-open', 3),
('デザイン', 'design', 'fa-palette', 4),
('ライティング', 'writing', 'fa-pen', 5),
('その他', 'other', 'fa-ellipsis-h', 99);
