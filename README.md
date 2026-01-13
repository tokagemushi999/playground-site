# ぷれぐら！PLAYGROUND

クリエイター向けポートフォリオ・作品展示プラットフォーム

## 概要

「ぷれぐら！PLAYGROUND」は、イラストレーター、漫画家などのクリエイターが作品を展示し、ファンと交流できるWebプラットフォームです。作品展示、漫画ビューア、ECストア機能を備えています。

## 技術スタック

- **バックエンド**: PHP 8.x
- **データベース**: MySQL / MariaDB
- **フロントエンド**: TailwindCSS (CDN)、Vanilla JavaScript
- **決済**: Stripe
- **認証**: セッションベース、OAuth（Google/LINE/Amazon）

## ディレクトリ構成

```
public_html/
├── index.php              # メインページ（タブ切り替え型SPA風）
├── article.php            # 記事個別ページ
├── creator.php            # クリエイター詳細ページ
├── creators/              # クリエイター一覧ページ
│   └── index.php
├── manga-viewer.php       # 漫画ビューア（RTL/LTR対応）
├── contact.php            # お問い合わせフォーム
├── contract-agree.php     # 契約書同意ページ（クリエイター用）
├── manifest.json          # PWA設定
├── robots.txt             # 検索エンジン用クロール指示
├── sitemap.php            # 動的サイトマップ生成（sitemap.xmlとしてアクセス可能）
├── .htaccess              # リライトルール
├── CLAUDE.md              # Claude用プロジェクト概要
│
├── admin/                 # 管理画面
│   ├── index.php          # ダッシュボード
│   ├── login.php          # 管理者ログイン
│   ├── logout.php         # ログアウト
│   ├── creators.php       # クリエイター管理
│   ├── creator-sales.php  # 売上・支払管理
│   ├── creator-payment.php # 支払通知書生成
│   ├── contracts.php      # 契約書管理
│   ├── google-drive.php   # Google Drive連携設定
│   ├── google-drive-callback.php # Google DriveOAuthコールバック
│   ├── works.php          # 作品管理（イラスト/漫画/動画/LINEスタンプ）
│   ├── work-insert-pages.php # 漫画ページ一括挿入
│   ├── collections.php    # コレクション管理
│   ├── articles.php       # 記事管理
│   ├── products.php       # 商品管理
│   ├── orders.php         # 注文管理
│   ├── members.php        # 会員管理
│   ├── inquiries.php      # お問い合わせ管理
│   ├── settings.php       # サイト設定（GA/SC含む）
│   ├── sort-order.php     # 並び順管理
│   ├── page-editor.php    # 固定ページ編集
│   ├── security.php       # セキュリティ設定
│   ├── seo-checker.php    # SEO診断ツール
│   ├── mail-templates.php # メールテンプレート管理
│   ├── store-settings.php # ストア設定（Stripe/OAuth含む）
│   ├── store-categories.php # 商品カテゴリ管理
│   ├── store-faq.php      # FAQ管理
│   ├── store-announcements.php # お知らせ管理
│   ├── check-images.php   # 画像サイズ確認ツール
│   ├── optimize-images.php # 画像最適化ツール
│   ├── convert-images.php # 画像変換ツール
│   ├── generate-ogp-images.php # OGP用JPG画像生成ツール
│   ├── gif-to-webm.php    # GIF→WebM変換ツール（FFmpeg不要）
│   ├── fix-gif.php        # GIF修正ツール
│   ├── migrate-viewer.php # ビューア移行ツール
│   ├── manifest.json      # 管理画面PWA設定
│   └── includes/          # 管理画面共通パーツ
│       ├── header.php
│       ├── footer.php
│       └── sidebar.php
│
├── creator-dashboard/     # クリエイターダッシュボード
│   ├── index.php          # ダッシュボード
│   ├── works.php          # 作品管理
│   ├── products.php       # 商品管理
│   ├── services.php       # サービス管理
│   ├── transactions.php   # 取引管理
│   ├── transaction-detail.php # 取引詳細
│   ├── earnings.php       # 売上・支払情報
│   ├── contracts.php      # 契約書確認
│   ├── profile.php        # プロフィール設定
│   ├── settings.php       # 各種設定
│   └── includes/          # 共通パーツ
│       ├── header.php
│       └── footer.php
│
├── store/                 # ECストア
│   ├── index.php          # 商品一覧
│   ├── product.php        # 商品詳細
│   ├── cart.php           # カート
│   ├── checkout.php       # チェックアウト
│   ├── complete.php       # 購入完了
│   ├── login.php          # 会員ログイン
│   ├── logout.php         # ログアウト
│   ├── register.php       # 会員登録
│   ├── mypage.php         # マイページ
│   ├── bookshelf.php      # 購入済み作品（本棚）
│   ├── orders.php         # 注文履歴
│   ├── order.php          # 注文詳細
│   ├── invoice.php        # 領収書表示・印刷
│   ├── profile.php        # プロフィール編集
│   ├── address.php        # 配送先管理
│   ├── favorites.php      # お気に入り
│   ├── contact.php        # ストアお問い合わせ
│   ├── faq.php            # FAQ
│   ├── terms.php          # 利用規約
│   ├── privacy.php        # プライバシーポリシー
│   ├── tokushoho.php      # 特定商取引法
│   ├── forgot-password.php # パスワード再設定
│   ├── reset-password.php # パスワードリセット
│   ├── webhook.php        # Stripe Webhook
│   ├── oauth-start.php    # OAuth認証開始
│   ├── oauth-callback.php # OAuthコールバック
│   ├── services/          # サービス一覧・詳細
│   │   ├── index.php
│   │   └── detail.php
│   ├── transactions/      # 取引/見積もり関連
│   │   ├── index.php
│   │   ├── request.php
│   │   ├── checkout.php
│   │   ├── guest.php
│   │   └── payment-complete.php
│   ├── manifest.json      # ストアPWA設定
│   └── includes/          # ストア共通パーツ
│       ├── header.php     # ヘッダー（SEOタグ含む）
│       ├── footer.php
│       └── pwa-meta.php   # PWAメタタグ
│
├── api/                   # API
│   ├── bookmarks.php      # ブックマーク保存
│   └── save-reading-progress.php # 読書位置保存
│
├── includes/              # 共通ライブラリ
│   ├── db.php             # DB接続・共通関数
│   ├── auth.php           # 管理者認証
│   ├── member-auth.php    # 会員認証
│   ├── site-settings.php  # サイト設定取得・画像パス正規化
│   ├── image-helper.php   # 画像処理（リサイズ・WebP変換）
│   ├── formatting.php     # 表示用フォーマット共通化
│   ├── gallery-render.php # ギャラリー描画共通関数
│   ├── shipping.php       # 配送業者名・追跡URL共通化
│   ├── cart.php           # カート操作
│   ├── stripe-config.php  # Stripe設定（DB連動）
│   ├── stripe-php/        # Stripe PHPライブラリ
│   ├── oauth.php          # OAuth設定
│   ├── mail.php           # メール送信
│   ├── transactions.php   # サービス取引共通処理
│   ├── document-template.php # 領収書/通知書テンプレート
│   ├── google-drive.php   # Google Drive連携
│   ├── csrf.php           # CSRF対策
│   ├── sanitize.php       # サニタイズ処理
│   ├── defaults.php       # デフォルト値定義
│   ├── modals.php         # 共通モーダルコンポーネント
│   └── seo-tags.php       # SEOタグ出力（GA/SC）
│
├── assets/
│   ├── css/
│   │   └── modals.css     # モーダル共通スタイル
│   ├── js/
│   │   └── modals.js      # モーダル共通スクリプト
│   └── images/            # 静的画像
│       └── amazon-logo.png
│
├── sql/                   # SQLスキーマ（別途管理）
│   ├── ec_tables.sql      # EC関連テーブル
│   ├── mail_templates.sql # メールテンプレート
│   ├── security_tables.sql # セキュリティ関連
│   ├── store_extensions.sql # ストア拡張
│   └── migration_2026-01-06.sql # マイグレーション（注文・クリエイター拡張）
│
└── docs/                  # ドキュメント（別途管理）
    ├── EC_CHECKLIST.md    # ECリリースチェックリスト
    ├── SHARED_COMPONENTS.md # 共通コンポーネント仕様
    └── CODE_ANALYSIS.md   # コード分析
```

## 主要機能

### 1. フロントエンド（公開側）

#### トップページ（index.php）
- **HOME**: 最新の作品・記事を表示
- **MEMBER**: クリエイター一覧
- **GALLERY**: 作品アーカイブ（カテゴリフィルター付き）
- **MEDIA**: ブログ・日記・インタビュー記事
- **THE LAB**: 実験的ツール紹介
- **REQUEST**: リクエストフォーム

#### 漫画ビューア（manga-viewer.php）
- RTL（右から左）/ LTR（左から右）読み方向対応
- 見開きモード / 単ページモード
- ピンチズーム / ダブルタップズーム
- スワイプナビゲーション
- 背景色カスタマイズ
- 読書位置の自動保存
- ブックマーク機能

#### クリエイターページ（creator.php）
- プロフィール表示
- 作品一覧
- LINEスタンプコレクション
- 関連記事
- SNSリンク

### 2. ECストア機能

#### 商品タイプ
- **デジタル**: 漫画・イラスト集（購入後すぐに閲覧可能）
- **物販**: 実物商品（在庫管理・配送対応）
- **バンドル**: セット商品

#### 決済・注文
- Stripe決済（クレジットカード）
- 注文管理（管理画面から発送処理）
- 配送業者・追跡番号管理
- 追跡番号入力で自動発送ステータス更新
- 発送通知メール（追跡リンク付き）

#### 領収書・インボイス
- 独自領収書発行（印刷・PDF保存対応）
- 適格請求書（インボイス）形式対応
- クリエイターごとの領収書分離（委託販売対応）
- Stripe領収書へのリンク

#### 会員機能
- 会員登録 / ログイン
- ソーシャルログイン（Google / LINE / Amazon）
- 本棚（購入済みデジタル商品の閲覧）
- 注文履歴・領収書表示
- お気に入り
- 配送先住所管理

### 3. 管理画面

#### コンテンツ管理
- クリエイター管理（プロフィール・SNS・画像）
- 作品管理（イラスト/漫画/動画/アニメーション/3D/LINEスタンプ）
- コレクション管理（作品のグループ化）
- 記事管理（ブログ/日記/インタビュー/お知らせ）
- 固定ページ編集

#### ストア管理
- 商品管理
- 注文管理・発送処理（配送業者・追跡番号）
- Stripe決済情報表示
- 会員管理
- カテゴリ管理
- FAQ管理
- お知らせ管理
- ストア設定（送料・特商法・Stripe APIキー・OAuth）

#### サイト設定
- 基本設定（サイト名・URL・ロゴ・ファビコン）
- OGP設定
- PWA設定
- AdSense設定
- Google Analytics / Search Console設定
- メールテンプレート
- OAuth設定（Google/LINE/Amazon）
- セキュリティ設定

#### SEO/ツール
- SEOチェッカー（サイト全体のSEO診断）
- 画像サイズ確認
- 画像最適化（WebP変換）
- OGP画像生成（JPG版自動生成）
- GIF→WebM変換（FFmpeg不要、ブラウザ内変換）

### 4. SEO機能

#### 自動生成ファイル
- **robots.txt**: 検索エンジン用クロール指示（管理画面・会員専用ページはクロール禁止）
- **sitemap.xml**: データベースから動的生成（記事・作品・クリエイター・商品を自動収集）

#### Google連携
- **Google Analytics**: 測定ID設定でアクセス解析
- **Search Console**: 認証タグ設定で検索パフォーマンス確認

※ sitemap.phpはデータベースから動的に生成するため、コンテンツを追加・削除しても変更不要です。

## データベース

### 主要テーブル

#### コンテンツ系
- `creators` - クリエイター（インボイス情報・銀行口座情報含む）
- `works` - 作品
- `work_pages` - 漫画ページ
- `collections` - コレクション（LINEスタンプグループなど）
- `collection_stickers` - コレクション内スタンプ
- `articles` - 記事

#### EC系
- `members` - 会員
- `member_addresses` - 配送先住所
- `member_bookshelf` - 購入済み商品
- `member_favorites` - お気に入り
- `products` - 商品
- `product_categories` - 商品カテゴリ
- `orders` - 注文（配送業者・追跡番号・Stripe情報含む）
- `order_items` - 注文明細
- `carts` - カート
- `cart_items` - カート明細

#### システム系
- `site_settings` - サイト設定（GA/SC設定含む）
- `inquiries` - お問い合わせ
- `mail_templates` - メールテンプレート
- `store_faq` - FAQ
- `store_announcements` - お知らせ

## セットアップ

### 1. 必要要件

- PHP 8.0以上
- MySQL 5.7以上 / MariaDB 10.3以上
- SSL証明書（HTTPS必須）

### 2. インストール

```bash
# 1. ファイルを配置
# public_htmlディレクトリにファイルをアップロード

# 2. データベース作成
mysql -u root -p
CREATE DATABASE playground CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

# 3. テーブル作成
# 管理画面に初回アクセス時に自動作成されます
# または sql/ 内のSQLファイルを実行

# 4. 設定ファイル編集
# includes/db.php のデータベース接続情報を設定
```

### 3. 初期設定

1. `/admin/login.php` にアクセス
2. 初回はデフォルト認証情報でログイン
3. 管理画面 > 設定 でサイト情報を設定
4. 管理画面 > セキュリティ でパスワード変更

### 4. Stripe設定（EC機能を使う場合）

1. [Stripeアカウント](https://dashboard.stripe.com/register)を作成
2. [APIキー](https://dashboard.stripe.com/apikeys)を取得
3. 管理画面 > ストア設定 > Stripe決済設定 でAPIキーを入力
   - テスト環境: pk_test_..., sk_test_...
   - 本番環境: pk_live_..., sk_live_...
4. Webhookエンドポイントを追加
   - URL: `https://yourdomain.com/store/webhook.php`
   - イベント: `checkout.session.completed`
5. Webhook署名シークレット（whsec_...）を取得して入力
6. テスト完了後「本番モードを有効にする」をチェック

**テスト用カード番号:**
- 成功: `4242 4242 4242 4242`
- 失敗: `4000 0000 0000 0002`

### 5. Google Analytics / Search Console設定

1. 管理画面 > サイト設定 > SEO / アクセス解析
2. Google Analytics測定ID（G-XXXXXXXXXX）を入力
3. Search Console認証タグのcontent値を入力
4. 保存後、全ページに自動でタグが挿入される

### 6. サイトマップ登録

1. Search Console > サイトマップ
2. `https://yourdomain.com/sitemap.xml` を送信

## ローカル開発

```bash
# PHPの組み込みサーバーで起動（.htaccessは反映されないためURLは.phpでアクセス）
php -S 0.0.0.0:8000
```

- `includes/db.php` の接続情報をローカル環境のDBに合わせてください。
- `.htaccess` のリライトルールを使う場合はApache/Nginx側の設定が必要です。

## 動作チェック / テスト

```bash
# 変更ファイルの構文チェック
php -l includes/admin-ui.php
php -l includes/formatting.php
php -l includes/stripe-config.php
php -l creator-dashboard/services.php
```

## OGP設定

各ページは以下のOGP画像を使用します：

| ページ | OGP画像 |
|--------|---------|
| トップページ | サイト設定のOGP画像 |
| 記事ページ | 記事のサムネイル画像 |
| クリエイターページ | クリエイターのプロフィール画像 |
| 漫画ビューア | 作品のサムネイル画像 |
| 商品ページ | 商品画像 |

## 共通関数

### includes/site-settings.php

```php
// 画像パスを正規化（先頭に/を付ける）
normalizeImagePath($path)

// 単一のサイト設定を取得
getSiteSetting($db, $key, $default = '')

// ファビコン情報を取得
getFaviconInfo($db, $default = '/favicon.png')
```

### includes/db.php

```php
// DB接続を取得
getDB()

// 全サイト設定を取得
getSiteSettings()
```

### includes/seo-tags.php

```php
// Google Analytics / Search ConsoleのタグをHTML出力
outputSeoTags($db)
```

### includes/formatting.php

```php
// 金額の表示フォーマット
formatPrice($price)

// 数値の表示フォーマット（小数点も指定可能）
formatNumber($value, $default = '-', $decimals = 0)
```

### includes/gallery-render.php

```php
// ギャラリーアイテムを描画
renderGalleryItem($item, $options = [])

// ギャラリーグリッドを描画
renderGalleryGrid($items, $options = [])
```

### includes/image-helper.php

```php
// GIF/WebMの存在チェック
checkWebmExists($path, $basePath = null)

// 画像/動画タグ生成（簡易版）
renderMediaTag($path, $class = '', $alt = '', $style = '')
```

### includes/shipping.php

```php
// 配送業者名を取得
getShippingCarrierName($carrierCode, $fallback = '未設定')

// 追跡URLを取得
getTrackingUrl($carrierCode, $trackingNumber)
```

## セキュリティ

- CSRF対策（トークン検証）
- XSS対策（出力エスケープ）
- SQLインジェクション対策（プリペアドステートメント）
- セッション管理（タイムアウト・再生成）
- パスワードハッシュ化（bcrypt）
- 管理画面IPホワイトリスト（オプション）

## PWA対応

- マニフェストファイル（manifest.json）
- テーマカラー設定
- アイコン設定
- スタンドアロンモード対応
- インストールバナー（iOS/Android対応）

## 認証の種類

### 管理者認証（includes/auth.php）
- `isLoggedIn()` - 管理者ログイン確認
- 管理画面（/admin/）で使用
- セッションタイムアウト: 8時間

### 会員認証（includes/member-auth.php）
- `isLoggedIn()` - 会員ログイン確認
- ストア（/store/）および漫画ビューアで使用
- OAuth連携対応

※ 同じ関数名ですが、用途に応じて異なるファイルをインクルードして使い分けます。

### クリエイター認証（includes/creator-auth.php）
- `getCurrentCreator()` - ログイン中のクリエイター取得
- `requireCreatorAuth()` - クリエイターダッシュボード用ログイン必須チェック
- `loginCreator()` / `logoutCreator()` - ログイン/ログアウト処理
- パスワード設定/リセット用トークン生成・検証をサポート

## README更新ルール

**このREADMEは、プロジェクトに変更があった際に必ず更新してください。**

更新が必要な場合：
- 新しいファイルを追加したとき
- ファイルを削除したとき
- 機能を追加・変更したとき
- ディレクトリ構造を変更したとき

更新内容：
1. 「ディレクトリ構成」セクションにファイルを追加/削除
2. 「主要機能」セクションに機能説明を追加
3. 「更新履歴」セクションに変更内容を記載

## ライセンス

プライベートプロジェクト

## 更新履歴

### 2026-01-12
- クリエイター認証ヘルパーの共通読み込みを整理（includes/creator-auth.php）
- READMEにクリエイター認証ヘルパーの説明を追加

### 2026-01-11
- WebM存在チェックを共通ヘルパーへ統一（includes/image-helper.php）
- クリエイターページ/取引詳細で金額表示を共通フォーマット関数に統一

### 2026-01-10
- ストア/取引画面の金額・数値表示を共通フォーマット関数へ統一
- READMEのディレクトリ構成と共通関数説明を更新

### 2026-01-09
- 書類・メールで金額/数値フォーマットを共通ヘルパーに統一

### 2026-01-08
- 価格表示フォーマットを共通ヘルパーに統一
- クリエイターダッシュボードのUIヘルパー読み込みを整理
- READMEにローカル開発・テスト手順を追加

### 2026-01-07
- 配送業者名・追跡URL生成を共通ヘルパーへ整理（includes/shipping.php）
- ストア注文詳細・発送完了メールで共通関数を利用

### 2026-01-06（追加更新）
- **Google Drive連携機能**
  - 書類の自動バックアップ
  - フォルダ構造自動作成（領収書/支払通知書/請求書/契約書/源泉徴収/注文データ）
  - 管理画面からワンクリック保存
  - OAuth認証による安全な接続

- **契約書管理システム（admin/contracts.php）**
  - 販売委託契約書テンプレート管理（Markdown対応）
  - クリエイターへの電子契約書送信
  - オンライン同意機能（contract-agree.php）
  - 同意日時・IP・ブラウザ情報の記録
  - 契約ステータス管理（未送信/送信済/契約済）

- **クリエイター連絡先機能**
  - メールアドレス登録機能追加
  - 支払通知書のメール送信機能
  - 契約書のメール送信機能

- **源泉徴収納付管理**
  - 月次源泉徴収合計の表示
  - 納付期限（翌月10日）の表示
  - 納付完了記録機能

- **楽天銀行CSV対応**
  - 総合振込用CSVエクスポート機能
  - 汎用CSV形式も選択可能

- **新規データベーステーブル**
  - `creator_contracts` - クリエイター契約書
  - `withholding_tax_payments` - 源泉徴収納付管理
  - `document_archives` - 書類保存履歴
  - `creators.email` - メールアドレス列追加
  - `creator_payments.notification_sent_at` - 通知書送信日時列追加

### 2026-01-06
- **注文管理機能強化**
  - 配送業者・追跡番号の管理機能追加
  - 追跡番号入力で自動的に「発送済み」ステータスに変更
  - 主要配送業者の追跡URL自動生成（ヤマト、佐川、日本郵便）
  - 発送完了メールに追跡リンク自動挿入
  
- **Stripe決済情報の表示**
  - 注文詳細画面でStripe決済情報を表示
  - カードブランド・下4桁・有効期限表示
  - リスク評価情報表示
  - Stripeダッシュボード・領収書へのリンク

- **領収書・インボイス機能**
  - 独自領収書ページ作成（store/invoice.php）
  - クリエイターごとの領収書分離（委託販売対応）
  - 適格請求書（インボイス）形式対応
  - 印刷・PDF保存対応
  - 購入完了メールに領収書リンク追加

- **クリエイター情報拡張**
  - インボイス情報（販売者名、登録番号T+13桁、住所）
  - 銀行口座情報（銀行名、支店、口座種別、口座番号、名義）
  - 販売手数料設定（率% + 単価/件）
  - 事業者区分（個人/法人）
  - 源泉徴収対応（個人の場合10.21%自動計算）

- **売上・支払管理機能（admin/creator-sales.php）**
  - クリエイター別売上レポート
  - 月次集計（売上・手数料・源泉徴収・支払額）
  - 支払確定・完了処理
  - 支払履歴管理
  - 振込用CSVエクスポート（銀行振込対応）
  
- **支払通知書（admin/creator-payment.php）**
  - クリエイター向け支払明細書
  - 源泉徴収税額の自動計算・表示
  - 印刷/PDF保存対応

- **Stripe設定の管理画面化**
  - ストア設定画面からStripe APIキーを設定可能に
  - 本番/テストモードの切り替え
  - stripe-config.phpはDB連動に変更

- **デバッグファイル削除**
  - store/oauth-debug.php 削除
  - store/stripe-debug.php 削除

- **マイグレーションSQL追加**
  - sql/migration_2026-01-06.sql を実行してください

### 2025-01-06
- GIF→WebM変換ツール追加（admin/gif-to-webm.php）
  - FFmpeg不要、Canvas Recording APIを使用したブラウザ内変換
  - URL読み込み対応（CORS制限あり）
  - サイズ比較表示機能
- コレクションカードにタップアニメーション追加
  - タップ時のスプリングエフェクト（300ms）
  - アニメーション中のデータプリロード

### 2025-01-05
- SEOチェッカー機能追加（admin/seo-checker.php）
- robots.txt作成（検索エンジン用クロール指示）
- sitemap.php作成（動的サイトマップ生成）
- .htaccessにsitemap.xmlリライトルール追加
- Google Analytics / Search Console設定機能追加（settings.php）
- SEOタグ出力ヘルパー追加（includes/seo-tags.php）
- 全ページにGA/SCタグ自動挿入機能追加
- README更新ルールを追記

### 2025-01-04
- ギャラリー描画関数の共通化（includes/gallery-render.php）
- index.phpとcreator.phpでギャラリー描画を統一
- クリエイターページのコンテナ幅拡張（max-w-4xl → max-w-7xl）
- カテゴリ別グリッドレイアウト対応
- 表示順管理機能の強化（カテゴリ別ソート）

### 2025-01-03
- OGP画像パス生成の修正
- タブタイトルのスタイル調整（サイズ・色）
- `normalizeImagePath`関数を`site-settings.php`に統合（重複削除）
- OGP用JPG画像生成ツール追加（admin/generate-ogp-images.php）
- PWAインストールバナー追加

### 2025-01-02
- EC機能の拡張（FAQ、お知らせ、カテゴリ管理）
- メールテンプレート機能追加
- 共通モーダルコンポーネント導入
- 商品削除保護機能（注文履歴がある商品は削除不可）

### 2025-01-01
- ECストア機能実装
- Stripe決済連携
- OAuth認証（Google/LINE/Amazon）

### 2024-12-31
- コレクション機能（LINEスタンプ対応）
- 漫画ビューア機能強化
