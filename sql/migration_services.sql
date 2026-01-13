-- ============================================
-- マイグレーション: サービス（スキル販売）機能
-- ============================================

-- ============================================
-- サービスカテゴリマスタ
-- ============================================
CREATE TABLE IF NOT EXISTS service_categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL COMMENT 'カテゴリ名',
    slug VARCHAR(100) NOT NULL COMMENT 'スラッグ',
    icon VARCHAR(50) NULL COMMENT 'FontAwesomeアイコン',
    description TEXT NULL COMMENT '説明',
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    UNIQUE KEY uk_slug (slug),
    INDEX idx_sort (sort_order)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- サービス本体
-- ============================================
CREATE TABLE IF NOT EXISTS services (
    id INT AUTO_INCREMENT PRIMARY KEY,
    creator_id INT NOT NULL COMMENT 'クリエイターID',
    category_id INT NULL COMMENT 'カテゴリID',
    title VARCHAR(255) NOT NULL COMMENT 'サービス名',
    slug VARCHAR(255) NULL COMMENT 'URLスラッグ',
    description TEXT NULL COMMENT '概要',
    content LONGTEXT NULL COMMENT '詳細説明（HTML/Markdown）',
    thumbnail VARCHAR(500) NULL COMMENT 'サムネイル画像',
    base_price INT DEFAULT 0 COMMENT '基本価格',
    delivery_days INT DEFAULT 7 COMMENT '納品日数',
    revision_count INT DEFAULT 1 COMMENT '修正回数',
    status VARCHAR(20) DEFAULT 'draft' COMMENT 'draft/active/paused/closed',
    is_featured TINYINT(1) DEFAULT 0 COMMENT 'おすすめ',
    view_count INT DEFAULT 0 COMMENT '閲覧数',
    order_count INT DEFAULT 0 COMMENT '受注数',
    sort_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY uk_slug (slug),
    INDEX idx_creator (creator_id),
    INDEX idx_category (category_id),
    INDEX idx_status (status),
    INDEX idx_featured (is_featured),
    FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE CASCADE,
    FOREIGN KEY (category_id) REFERENCES service_categories(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 料金プラン
-- ============================================
CREATE TABLE IF NOT EXISTS service_plans (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    name VARCHAR(100) NOT NULL COMMENT 'プラン名（ベーシック/スタンダード/プレミアム等）',
    description TEXT NULL COMMENT 'プラン説明',
    price INT NOT NULL COMMENT '価格',
    delivery_days INT NOT NULL COMMENT '納品日数',
    revision_count INT DEFAULT 1 COMMENT '修正回数',
    features TEXT NULL COMMENT '含まれるもの（JSON）',
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_service (service_id),
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 追加オプション
-- ============================================
CREATE TABLE IF NOT EXISTS service_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    name VARCHAR(255) NOT NULL COMMENT 'オプション名',
    description TEXT NULL COMMENT '説明',
    price INT NOT NULL COMMENT '追加料金',
    additional_days INT DEFAULT 0 COMMENT '追加日数',
    sort_order INT DEFAULT 0,
    is_active TINYINT(1) DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_service (service_id),
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- サービス画像
-- ============================================
CREATE TABLE IF NOT EXISTS service_images (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    image_path VARCHAR(500) NOT NULL COMMENT '画像パス',
    caption VARCHAR(255) NULL COMMENT 'キャプション',
    is_thumbnail TINYINT(1) DEFAULT 0 COMMENT 'サムネイル',
    sort_order INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_service (service_id),
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- サービスレビュー
-- ============================================
CREATE TABLE IF NOT EXISTS service_reviews (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    order_id INT NULL COMMENT '注文ID',
    member_id INT NULL COMMENT '会員ID',
    rating INT NOT NULL COMMENT '評価（1-5）',
    comment TEXT NULL COMMENT 'コメント',
    is_anonymous TINYINT(1) DEFAULT 0 COMMENT '匿名',
    is_published TINYINT(1) DEFAULT 1 COMMENT '公開',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_service (service_id),
    INDEX idx_member (member_id),
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 見積もり依頼
-- ============================================
CREATE TABLE IF NOT EXISTS service_quotes (
    id INT AUTO_INCREMENT PRIMARY KEY,
    service_id INT NOT NULL,
    member_id INT NULL COMMENT '会員ID（ログイン時）',
    name VARCHAR(100) NOT NULL COMMENT '依頼者名',
    email VARCHAR(255) NOT NULL COMMENT 'メールアドレス',
    requirements TEXT NOT NULL COMMENT '依頼内容',
    budget_min INT NULL COMMENT '予算（下限）',
    budget_max INT NULL COMMENT '予算（上限）',
    deadline DATE NULL COMMENT '希望納期',
    status VARCHAR(20) DEFAULT 'pending' COMMENT 'pending/replied/accepted/declined',
    quoted_price INT NULL COMMENT '見積もり金額',
    quoted_days INT NULL COMMENT '見積もり日数',
    creator_message TEXT NULL COMMENT 'クリエイターからのメッセージ',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    INDEX idx_service (service_id),
    INDEX idx_member (member_id),
    INDEX idx_status (status),
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- サービス注文
-- ============================================
CREATE TABLE IF NOT EXISTS service_orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_number VARCHAR(20) NOT NULL COMMENT '注文番号',
    service_id INT NOT NULL,
    plan_id INT NULL COMMENT '選択プラン',
    member_id INT NULL COMMENT '購入者',
    creator_id INT NOT NULL COMMENT '出品者',
    
    -- 購入者情報
    buyer_name VARCHAR(100) NOT NULL,
    buyer_email VARCHAR(255) NOT NULL,
    
    -- 金額
    base_price INT NOT NULL COMMENT '基本価格',
    options_price INT DEFAULT 0 COMMENT 'オプション合計',
    total_price INT NOT NULL COMMENT '合計金額',
    platform_fee INT DEFAULT 0 COMMENT 'プラットフォーム手数料',
    creator_earning INT DEFAULT 0 COMMENT 'クリエイター報酬',
    
    -- 納品
    delivery_days INT NOT NULL COMMENT '納品日数',
    expected_delivery DATE NULL COMMENT '納品予定日',
    delivered_at DATETIME NULL COMMENT '納品日時',
    
    -- ステータス
    status VARCHAR(20) DEFAULT 'pending' COMMENT 'pending/paid/in_progress/delivered/revision/completed/cancelled',
    payment_status VARCHAR(20) DEFAULT 'pending' COMMENT 'pending/paid/refunded',
    
    -- 決済
    stripe_payment_intent VARCHAR(255) NULL,
    
    -- その他
    requirements TEXT NULL COMMENT '依頼内容',
    notes TEXT NULL COMMENT '備考',
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY uk_order_number (order_number),
    INDEX idx_service (service_id),
    INDEX idx_member (member_id),
    INDEX idx_creator (creator_id),
    INDEX idx_status (status),
    FOREIGN KEY (service_id) REFERENCES services(id) ON DELETE RESTRICT,
    FOREIGN KEY (creator_id) REFERENCES creators(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 注文オプション（選択されたオプション）
-- ============================================
CREATE TABLE IF NOT EXISTS service_order_options (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    option_id INT NOT NULL,
    option_name VARCHAR(255) NOT NULL,
    price INT NOT NULL,
    additional_days INT DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order (order_id),
    FOREIGN KEY (order_id) REFERENCES service_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 取引メッセージ
-- ============================================
CREATE TABLE IF NOT EXISTS service_messages (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    sender_type VARCHAR(20) NOT NULL COMMENT 'buyer/creator/system',
    sender_id INT NULL COMMENT '送信者ID',
    message TEXT NOT NULL,
    is_read TINYINT(1) DEFAULT 0,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order (order_id),
    INDEX idx_unread (order_id, is_read),
    FOREIGN KEY (order_id) REFERENCES service_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- 納品ファイル
-- ============================================
CREATE TABLE IF NOT EXISTS service_deliveries (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    file_name VARCHAR(255) NOT NULL COMMENT '元ファイル名',
    file_path VARCHAR(500) NOT NULL COMMENT '保存パス',
    file_size INT NULL COMMENT 'ファイルサイズ',
    file_type VARCHAR(100) NULL COMMENT 'MIMEタイプ',
    description TEXT NULL COMMENT '説明',
    is_final TINYINT(1) DEFAULT 0 COMMENT '最終納品',
    download_count INT DEFAULT 0 COMMENT 'ダウンロード回数',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_order (order_id),
    FOREIGN KEY (order_id) REFERENCES service_orders(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================
-- creatorsテーブルへのカラム追加（存在しない場合のみ）
-- ============================================
-- 以下は個別に実行し、エラーが出たらスキップしてください
ALTER TABLE creators ADD COLUMN is_seller TINYINT(1) DEFAULT 0 COMMENT 'サービス出品者';
ALTER TABLE creators ADD COLUMN seller_status VARCHAR(20) DEFAULT 'inactive' COMMENT 'inactive/active/suspended';
ALTER TABLE creators ADD COLUMN seller_description TEXT NULL COMMENT '出品者紹介';

-- ============================================
-- inquiriesテーブルへのカラム追加（存在しない場合のみ）
-- ============================================
-- ALTER TABLE inquiries ADD COLUMN service_id INT NULL COMMENT '関連サービス';
-- ↑ すでに存在する場合はスキップ

-- ============================================
-- 初期カテゴリデータ
-- ============================================
INSERT INTO service_categories (name, slug, icon, description, sort_order) VALUES
('イラスト制作', 'illustration', 'fa-palette', 'キャラクターイラスト、背景、アイコンなど', 1),
('ロゴ・グラフィック', 'logo-design', 'fa-pen-nib', 'ロゴ、バナー、名刺デザインなど', 2),
('漫画・コミック', 'manga', 'fa-book-open', '漫画制作、ネーム、作画など', 3),
('Live2D', 'live2d', 'fa-user-astronaut', 'Live2Dモデル制作、リギングなど', 4),
('3Dモデル', '3d-model', 'fa-cube', 'VRChat向け、ゲーム用3Dモデルなど', 5),
('動画・アニメーション', 'video', 'fa-video', 'MV、PV、アニメーション制作など', 6),
('音楽・サウンド', 'music', 'fa-music', 'BGM、SE、歌ってみたMIXなど', 7),
('その他', 'other', 'fa-ellipsis-h', 'その他のサービス', 99);
