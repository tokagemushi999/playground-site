-- ============================================
-- サービス・商品テンプレート機能
-- ============================================

-- サービステンプレートテーブル
CREATE TABLE IF NOT EXISTS service_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL COMMENT 'テンプレート名',
    description TEXT COMMENT 'テンプレート説明',
    
    -- サービス内容
    title VARCHAR(255) DEFAULT NULL COMMENT 'サービス名',
    category VARCHAR(100) DEFAULT NULL,
    category_id INT DEFAULT NULL,
    service_description TEXT COMMENT '概要',
    description_detail TEXT COMMENT 'サービス内容',
    base_price INT DEFAULT NULL,
    min_price INT DEFAULT NULL,
    max_price INT DEFAULT NULL,
    delivery_days INT DEFAULT NULL,
    revision_limit INT DEFAULT NULL,
    
    -- 拡張項目
    provision_format VARCHAR(50) DEFAULT NULL,
    commercial_use TINYINT(1) DEFAULT NULL,
    secondary_use TINYINT(1) DEFAULT NULL,
    planning_included TINYINT(1) DEFAULT NULL,
    bgm_included TINYINT(1) DEFAULT NULL,
    free_revisions INT DEFAULT NULL,
    draft_proposals INT DEFAULT NULL,
    style VARCHAR(255) DEFAULT NULL,
    usage_tags VARCHAR(500) DEFAULT NULL,
    genre_tags VARCHAR(500) DEFAULT NULL,
    file_formats VARCHAR(255) DEFAULT NULL,
    purchase_notes TEXT,
    workflow TEXT,
    
    -- 表示設定
    show_in_gallery TINYINT(1) DEFAULT 0,
    show_in_creator_page TINYINT(1) DEFAULT 1,
    show_in_top TINYINT(1) DEFAULT 0,
    show_in_store TINYINT(1) DEFAULT 1,
    
    -- オプション（JSON形式）
    options_json TEXT COMMENT '有料オプションのJSON',
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 商品テンプレートテーブル
CREATE TABLE IF NOT EXISTS product_templates (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL COMMENT 'テンプレート名',
    description TEXT COMMENT 'テンプレート説明',
    
    -- 商品内容
    title VARCHAR(255) DEFAULT NULL COMMENT '商品名',
    product_description TEXT COMMENT '商品説明',
    category_id INT DEFAULT NULL,
    price INT DEFAULT NULL,
    
    -- 詳細設定
    stock INT DEFAULT NULL,
    sku VARCHAR(100) DEFAULT NULL,
    weight INT DEFAULT NULL,
    
    -- 配送設定
    shipping_method VARCHAR(50) DEFAULT NULL,
    shipping_fee INT DEFAULT NULL,
    
    -- 表示設定
    show_in_gallery TINYINT(1) DEFAULT 0,
    show_in_creator_page TINYINT(1) DEFAULT 1,
    show_in_top TINYINT(1) DEFAULT 0,
    
    -- バリエーション（JSON形式）
    variations_json TEXT COMMENT 'バリエーションのJSON',
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    INDEX idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- サンプルテンプレート挿入（サービス）
INSERT INTO service_templates (name, description, title, service_description, base_price, delivery_days, revision_limit, purchase_notes, workflow) VALUES
('イラスト制作（基本）', 'イラスト制作の基本テンプレート', 'オリジナルイラスト制作します', '■ 対応内容\n・キャラクターイラスト\n・背景込み\n・商用利用可', 30000, 14, 2, '■ ご依頼前にご確認ください\n・参考資料があるとスムーズです\n・修正回数は2回までとなります', '1. ヒアリング\n2. ラフ提出\n3. 線画\n4. 着色\n5. 仕上げ・納品'),
('動画制作（基本）', '動画制作の基本テンプレート', 'ショート動画・アニメーション制作', '■ 対応内容\n・15秒〜60秒の短尺動画\n・SNS用縦型対応\n・BGM込み', 50000, 21, 1, '■ ご依頼前にご確認ください\n・素材（画像・テキスト）のご準備をお願いします', '1. 企画・構成確認\n2. 絵コンテ\n3. 制作\n4. 修正\n5. 納品'),
('ロゴデザイン', 'ロゴデザインのテンプレート', 'オリジナルロゴデザイン制作', '■ 納品物\n・AIデータ\n・PNGデータ（背景透過）\n・使用ガイドライン', 80000, 14, 3, '■ 事前にお知らせください\n・会社名/ブランド名\n・業種\n・イメージカラー', '1. ヒアリング\n2. コンセプト提案\n3. デザイン案（3案）\n4. 修正\n5. 納品');

-- サンプルテンプレート挿入（商品）
INSERT INTO product_templates (name, description, title, product_description, price, stock) VALUES
('物販（基本）', '物販商品の基本テンプレート', '', '■ 商品説明\n\n■ サイズ\n\n■ 素材\n\n■ 注意事項', 0, 100),
('デジタル商品', 'ダウンロード販売用テンプレート', '', '■ 商品内容\n・ファイル形式：PDF/PNG\n・即時ダウンロード\n\n■ 利用規約\n・個人利用のみ可\n・再配布禁止', 0, 999);
