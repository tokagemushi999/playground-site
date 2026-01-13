-- =============================================
-- クリエイター認証・ダッシュボード拡張マイグレーション
-- =============================================

-- 1. クリエイターテーブルに認証関連カラムを追加
ALTER TABLE creators ADD COLUMN login_enabled TINYINT(1) DEFAULT 0 COMMENT 'ログイン許可';
ALTER TABLE creators ADD COLUMN password_set_token VARCHAR(64) DEFAULT NULL COMMENT 'パスワード設定トークン';
ALTER TABLE creators ADD COLUMN password_set_expires DATETIME DEFAULT NULL COMMENT 'トークン有効期限';
ALTER TABLE creators ADD COLUMN password_reset_token VARCHAR(64) DEFAULT NULL COMMENT 'パスワードリセットトークン';
ALTER TABLE creators ADD COLUMN password_reset_expires DATETIME DEFAULT NULL COMMENT 'リセットトークン有効期限';
ALTER TABLE creators ADD COLUMN last_login_at DATETIME DEFAULT NULL COMMENT '最終ログイン日時';
ALTER TABLE creators ADD COLUMN login_attempts INT DEFAULT 0 COMMENT 'ログイン試行回数';
ALTER TABLE creators ADD COLUMN locked_until DATETIME DEFAULT NULL COMMENT 'ロック解除日時';

-- 2. パスワードカラムがなければ追加（既にある場合はスキップ）
-- ALTER TABLE creators ADD COLUMN password VARCHAR(255) DEFAULT NULL;

-- 3. 既存クリエイターはログイン無効で初期化
UPDATE creators SET login_enabled = 0 WHERE login_enabled IS NULL;

-- 4. インデックス追加
CREATE INDEX idx_creators_login_enabled ON creators(login_enabled);
CREATE INDEX idx_creators_password_set_token ON creators(password_set_token);
CREATE INDEX idx_creators_password_reset_token ON creators(password_reset_token);
