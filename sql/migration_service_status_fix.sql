-- サービスステータスの修正
-- 既存の'draft'サービスを確認できるようにするための更新

-- もし'published'を使用する場合はこちら
-- UPDATE services SET status = 'published' WHERE status = 'draft';

-- 'active'を使用する場合（現在のコードに合わせる）
UPDATE services SET status = 'active' WHERE status = 'draft' OR status = 'published';

-- ステータスの選択肢確認用コメント
-- 管理画面: draft, active, paused, archived
-- フロントエンド: status = 'active' のみ表示
