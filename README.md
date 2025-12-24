# ぷれぐら！PLAYGROUND（public_html）

「ぷれぐら！」サイト一式（PHP + MySQL）です。  
作品（マンガ/画像）・クリエイター・記事の表示と、管理画面からの登録/編集を想定しています。

> ⚠️ **重要（秘密情報）**  
> `includes/db.php`（DB接続情報）と `includes/auth.php`（管理画面の認証設定）は **GitHubにコミットしないでください**。  
> このリポジトリでは `.gitignore` で除外しています。公開/非公開に関わらず必須です。

---

## 主な機能

### 公開側
- トップページ：`/`（`index.php`）
- クリエイター個別：`/creator/{slug}`（`creator.php`）
- 記事個別：`/article/{slug}`（`article.php`）
- 漫画ビューアー：`/manga/{id}`（`manga-viewer.php`）
- お問い合わせフォーム：`/contact.php`（DB保存 + メール送信）

### 管理画面（`/admin`）
- ログイン/ログアウト：`admin/login.php` / `admin/logout.php`
- 作品管理（画像/マンガ、PDF変換/ページ管理、埋め込み設定など）：`admin/works.php`
- クリエイター管理：`admin/creators.php`
- 記事管理：`admin/articles.php`
- お問い合わせ一覧：`admin/inquiries.php`
- サイト設定：`admin/settings.php`
- 画像最適化ツール（WebP変換/リサイズ等）：`admin/optimize-images.php` ほか
- ビューアー拡張のDBマイグレーション：`admin/migrate-viewer.php`

---

## ディレクトリ構成

```
.
├── index.php
├── article.php
├── creator.php
├── manga-viewer.php
├── contact.php
├── admin/
│   ├── index.php
│   ├── login.php
│   ├── works.php
│   ├── creators.php
│   ├── articles.php
│   ├── inquiries.php
│   └── ...
└── includes/
    ├── db.php              # ←秘密情報（コミット禁止）
    ├── auth.php            # ←秘密情報（コミット禁止）
    ├── db.sample.php       # サンプル（コミットOK）
    ├── auth.sample.php     # サンプル（コミットOK）
    ├── csrf.php
    ├── sanitize.php
    └── image-helper.php
```

アップロード画像などは `/uploads/` 配下に配置される想定です（後述）。

---

## 必要環境

- PHP 7.4+（推奨: 8.x）
  - PDO（`pdo_mysql`）
  - `mbstring`（メール送信用）
  - `gd`（画像最適化ツール用）
- MySQL / MariaDB
- Apache（推奨）  
  - `.htaccess` が使えること（`mod_rewrite` / `AllowOverride All`）
- （任意）PDF→画像のサーバー変換を使う場合：`pdftoppm`（Poppler）

---

## セットアップ

### 1) リポジトリを配置
このディレクトリ（`public_html` の中身）が **ドキュメントルート** になる想定です。

例）Apache:
- DocumentRoot: `.../public_html`

### 2) 設定ファイルを作成（コミット禁止）
`includes/` にサンプルがあるので、コピーして実ファイルを作ります。

```bash
cp includes/db.sample.php   includes/db.php
cp includes/auth.sample.php includes/auth.php
```

- `includes/db.php`：DB名/ユーザー/パスワードを設定
- `includes/auth.php`：管理画面ログイン用の設定（初期管理者の作り方など）を設定

> ✅ **安全のため**、`db.php` / `auth.php` は `.gitignore` で除外されています。  
> もし過去にコミットしてしまった場合は、GitHub上から削除するだけでなく **履歴から消す** & **パスワードを変更** してください。

### 3) データベースを用意
本プロジェクトは MySQL を前提にしています。最低限、以下のテーブルが必要になります（実運用ではカラム追加あり）：

- `admins`（管理画面ログイン）
- `creators`（クリエイター）
- `works`（作品）
- `work_pages`（マンガページ）
- `articles`（記事）
- `inquiries`（お問い合わせ）
- `site_settings`（サイト設定）
- `sticker_groups`（ステッカーグループ）
- （任意）`work_insert_pages`（ビューアーの挿入ページ機能）
- （任意）`creator_page_elements`（クリエイターページ編集）
- （任意）`lab_tools`（ラボ/ツール表示用）

> 🔧 `admin/migrate-viewer.php` は、ビューアー拡張用に `works` へのカラム追加や  
> `work_insert_pages` の作成を行います（既存DBがある場合に利用）。

### 4) アップロード先ディレクトリを作成 & 権限設定
画像アップロードに以下を使用します（なければ作成してください）。

例：
```bash
mkdir -p uploads/site uploads/creators uploads/articles uploads/works uploads/works/pages uploads/insert-pages
chmod -R 755 uploads
```

サーバー環境によっては `uploads/` 配下に書き込み権限が必要です。

---

## ローカル開発（簡易）

PHP内蔵サーバーで起動できます。

```bash
php -S localhost:8000 -t .
```

- 内蔵サーバーでは `.htaccess` のリライトルールが効かないため、
  - `article.php?slug=...`
  - `creator.php?slug=...`
  - `manga-viewer.php?id=...`
  のようにクエリ付きURLでアクセスしてください。

---

## URLルーティング（Apache / .htaccess）

`.htaccess` により、以下の形式でアクセスできます。

- `/article/{slug}` → `article.php?slug={slug}`
- `/creator/{slug}` → `creator.php?slug={slug}`
- `/manga/{id}` → `manga-viewer.php?id={id}`

---

## 管理画面について

- URL：`/admin/login.php`
- 初期管理者は **`admins` テーブルが空のとき**に作成される実装になっている場合があります（`includes/auth.php` 参照）。
  - 公開前に **初期パスワード・初期メールアドレスは必ず変更** してください
  - 可能であれば、管理画面は Basic認証 / IP制限 などの追加防御を推奨します

---

## 画像最適化/変換ツール

管理画面側に、画像のWebP変換やリサイズを行うツールがあります。

- `admin/optimize-images.php`：マンガ画像のリサイズ & WebP変換（GD使用）
- `admin/convert-images.php` / `admin/check-images.php` など：補助ツール

> サーバー負荷が高い処理のため、本番では時間帯や対象を絞って実行してください。

---

## セキュリティ注意（必読）

- `includes/db.php` / `includes/auth.php` は **絶対にGitHubへ上げない**
- もし一度でも公開リポジトリに秘密情報をpushした場合：
  - **DBパスワード等を即変更**
  - Git履歴からも除去（`git filter-repo` 等）
- 本番では `display_errors` は **Off推奨**（`.user.ini` を見直してください）

---

## ライセンス

未設定（必要であれば `LICENSE` を追加してください）。
