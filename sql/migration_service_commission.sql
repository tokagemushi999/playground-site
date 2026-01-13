-- =============================================
-- サービス手数料・契約書拡張マイグレーション
-- =============================================

-- 1. クリエイターテーブルにサービス手数料カラムを追加
ALTER TABLE creators ADD COLUMN service_commission_rate DECIMAL(5,2) DEFAULT 15.00 COMMENT 'サービス販売手数料率（%）';
ALTER TABLE creators ADD COLUMN service_commission_per_item INT DEFAULT 0 COMMENT 'サービス1件あたりの固定手数料';

-- 2. 契約書テーブルにサービス手数料カラムを追加
ALTER TABLE creator_contracts ADD COLUMN contract_type ENUM('goods', 'service', 'both') DEFAULT 'goods' COMMENT '契約種別';
ALTER TABLE creator_contracts ADD COLUMN previous_service_commission_rate DECIMAL(5,2) DEFAULT NULL;
ALTER TABLE creator_contracts ADD COLUMN previous_service_commission_per_item INT DEFAULT NULL;
ALTER TABLE creator_contracts ADD COLUMN new_service_commission_rate DECIMAL(5,2) DEFAULT NULL;
ALTER TABLE creator_contracts ADD COLUMN new_service_commission_per_item INT DEFAULT NULL;

-- 3. サービス売上集計テーブル
CREATE TABLE IF NOT EXISTS creator_service_payments (
    id INT AUTO_INCREMENT PRIMARY KEY,
    creator_id INT NOT NULL,
    payment_date DATE DEFAULT NULL COMMENT '支払日',
    target_year INT NOT NULL,
    target_month INT NOT NULL,
    
    -- 売上情報
    gross_sales INT DEFAULT 0 COMMENT '売上総額',
    commission_amount INT DEFAULT 0 COMMENT '手数料額',
    withholding_tax INT DEFAULT 0 COMMENT '源泉徴収税額',
    net_payment INT DEFAULT 0 COMMENT '支払額',
    
    -- 件数
    transaction_count INT DEFAULT 0 COMMENT '取引件数',
    
    -- ステータス
    status ENUM('pending', 'confirmed', 'paid') DEFAULT 'pending',
    confirmed_at DATETIME DEFAULT NULL,
    paid_at DATETIME DEFAULT NULL,
    notes TEXT DEFAULT NULL,
    
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    
    UNIQUE KEY unique_creator_month (creator_id, target_year, target_month),
    INDEX idx_status (status),
    INDEX idx_payment_date (payment_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- 4. サービス契約書テンプレート用の設定
INSERT IGNORE INTO site_settings (setting_key, setting_value) VALUES 
('service_contract_template_title', 'サービス販売委託契約書'),
('service_contract_template_content', '【サービス販売委託契約書】

本契約は、{shop_name}（以下「甲」という）と{creator_name}（以下「乙」という）との間で、以下のとおり締結する。

第1条（目的）
本契約は、乙が甲のプラットフォームを通じて提供するスキル・サービス（イラスト制作、動画制作、デザイン等）の販売委託に関する事項を定めることを目的とする。

第2条（委託内容）
1. 乙は、甲のプラットフォームを通じて、自らのスキル・サービスを販売することができる。
2. 甲は、乙のサービスの掲載、顧客との決済代行、およびメッセージング機能の提供を行う。

第3条（サービス内容）
1. 乙は、サービスの内容、価格、納期等を正確に記載する義務を負う。
2. 乙は、顧客からの依頼に対し、誠実に対応し、合意した内容に従ってサービスを提供する。
3. 納品物の品質については、乙が全責任を負う。

第4条（販売手数料）
1. 甲は、乙のサービス売上に対し、以下の手数料を徴収する。
   ・売上に対する手数料: {service_commission_rate}%
   ・取引1件あたりの手数料: {service_commission_per_item}円
2. 手数料は、顧客からの決済完了時に売上から控除される。

第5条（支払い）
1. 甲は、毎月末日に当月の売上を締め、翌月末日までに乙に支払う。
2. 支払いは乙が登録した銀行口座への振込により行う。
3. 振込手数料は甲の負担とする。

第6条（源泉徴収）
乙が個人の場合、甲は支払額に対して所得税法に定める源泉徴収を行う。

第7条（禁止事項）
1. 乙は、プラットフォーム外での直接取引を行ってはならない。
2. 乙は、虚偽の情報を記載してはならない。
3. 乙は、法令に違反するサービスを提供してはならない。

第8条（キャンセル・返金）
1. 制作開始前のキャンセルについては、全額返金とする。
2. 制作開始後のキャンセルについては、進捗に応じた精算を行う。
3. 乙の責めに帰すべき事由による返金は、乙の負担とする。

第9条（知的財産権）
1. 納品物の著作権は、特段の合意がない限り、顧客への納品と同時に顧客に移転する。
2. 乙は、ポートフォリオとしての使用権を留保できる。

第10条（契約期間）
本契約は、締結日から1年間有効とし、いずれからも書面による解約の申し出がない限り、同条件で自動更新される。

第11条（契約解除）
甲または乙は、相手方が本契約に違反した場合、催告の上、本契約を解除できる。

第12条（合意管轄）
本契約に関する紛争は、甲の所在地を管轄する裁判所を第一審の専属的合意管轄裁判所とする。

以上、本契約の成立を証するため、電子的に同意を行う。

契約日: {contract_date}
契約バージョン: {version}');

-- 5. 既存クリエイターのサービス手数料を初期設定
UPDATE creators SET 
    service_commission_rate = 15.00,
    service_commission_per_item = 0
WHERE service_commission_rate IS NULL;
