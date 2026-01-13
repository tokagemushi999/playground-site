-- サービス機能拡張マイグレーション
-- ココナラ風のサービス詳細情報を追加

-- サービステーブルの拡張
ALTER TABLE services 
ADD COLUMN IF NOT EXISTS thumbnail_image VARCHAR(500) DEFAULT NULL COMMENT 'メインサムネイル画像',
ADD COLUMN IF NOT EXISTS short_description TEXT DEFAULT NULL COMMENT '短い説明（一覧用）',
ADD COLUMN IF NOT EXISTS provision_format VARCHAR(100) DEFAULT NULL COMMENT '提供形式',
ADD COLUMN IF NOT EXISTS commercial_use TINYINT(1) DEFAULT 1 COMMENT '商用利用可',
ADD COLUMN IF NOT EXISTS secondary_use TINYINT(1) DEFAULT 1 COMMENT '二次利用可',
ADD COLUMN IF NOT EXISTS planning_included TINYINT(1) DEFAULT 0 COMMENT '企画・構成含む',
ADD COLUMN IF NOT EXISTS bgm_included TINYINT(1) DEFAULT 0 COMMENT 'BGM・音声含む',
ADD COLUMN IF NOT EXISTS photography_included TINYINT(1) DEFAULT 0 COMMENT '撮影含む',
ADD COLUMN IF NOT EXISTS free_revisions INT DEFAULT 3 COMMENT '無料修正回数',
ADD COLUMN IF NOT EXISTS draft_proposals INT DEFAULT 1 COMMENT 'ラフ提案数',
ADD COLUMN IF NOT EXISTS style VARCHAR(100) DEFAULT NULL COMMENT 'スタイル（2D/3Dなど）',
ADD COLUMN IF NOT EXISTS usage_tags TEXT DEFAULT NULL COMMENT '用途タグ（JSON配列）',
ADD COLUMN IF NOT EXISTS genre_tags TEXT DEFAULT NULL COMMENT 'ジャンルタグ（JSON配列）',
ADD COLUMN IF NOT EXISTS file_formats VARCHAR(255) DEFAULT NULL COMMENT 'ファイル形式',
ADD COLUMN IF NOT EXISTS purchase_notes TEXT DEFAULT NULL COMMENT '購入にあたってのお願い',
ADD COLUMN IF NOT EXISTS workflow TEXT DEFAULT NULL COMMENT '制作の流れ';

-- サービス画像テーブル（複数画像対応）
CREATE TABLE IF NOT EXISTS service_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    image_path VARCHAR(500) NOT NULL,
    sort_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_service (service_id),
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- サービスオプションテーブル（有料オプション）
CREATE TABLE IF NOT EXISTS service_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    name VARCHAR(255) NOT NULL COMMENT 'オプション名',
    description TEXT DEFAULT NULL COMMENT '説明',
    price INT NOT NULL DEFAULT 0 COMMENT '追加料金',
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_service (service_id),
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- サービスレビューテーブル
CREATE TABLE IF NOT EXISTS service_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    member_id INT DEFAULT NULL COMMENT '会員ID（ゲストの場合NULL）',
    reviewer_name VARCHAR(100) DEFAULT NULL COMMENT 'レビュアー名',
    rating INT NOT NULL DEFAULT 5 COMMENT '評価（1-5）',
    comment TEXT DEFAULT NULL COMMENT 'コメント',
    is_published TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_service (service_id),
    INDEX idx_member (member_id),
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- service_worksテーブル（まだない場合）
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

-- service_collectionsテーブル（サービスとコレクションの紐づけ）
CREATE TABLE IF NOT EXISTS service_collections (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    collection_id INT NOT NULL,
    sort_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_service_collection (service_id, collection_id),
    INDEX idx_service (service_id),
    INDEX idx_collection (collection_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
