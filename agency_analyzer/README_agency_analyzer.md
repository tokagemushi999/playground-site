# Agency Analyzer Pro

タレント・アーティスト情報を一括調査するB2B営業支援ツール

## v2.1 コスト最適化版

**57%のAPI料金削減を実現！**

| バージョン | 検索回数/タレント | コスト | 削減率 |
|-----------|------------------|--------|--------|
| v1.0 | 12回 | $0.060 | - |
| v2.0 | 10回 | $0.042 | 30% |
| **v2.1** | **6回** | **$0.018** | **57%** |

## ファイル構成

### 分割版（推奨）
```
agency_analyzer_config.php  # APIキー設定（39行）
agency_analyzer_api.php     # バックエンドAPI（921行）
agency_analyzer_app.html    # フロントエンド（3094行）
```

### 単体版
```
agency_analyzer_pro.php     # 全て1ファイル（3967行）
```

## セットアップ

### 1. APIキーを設定

`agency_analyzer_config.php` を編集:

```php
$PERPLEXITY_API_KEY = "pplx-xxxxx";  // 必須
$OPENAI_API_KEY = "sk-xxxxx";         // 必須
$YOUTUBE_API_KEY = "AIza-xxxxx";      // 任意
$RAPIDAPI_KEY = "xxxxx";              // 任意（Twitter用）
$SPOTIFY_CLIENT_ID = "xxxxx";         // 任意
$SPOTIFY_CLIENT_SECRET = "xxxxx";     // 任意
```

### 2. ファイルを配置

すべてのファイルを同じディレクトリに配置:
```
/your-server/
  ├── agency_analyzer_config.php
  ├── agency_analyzer_api.php
  ├── agency_analyzer_app.html
  └── cache_talent/          # 自動作成される
```

### 3. アクセス

ブラウザで `agency_analyzer_app.html` を開く

## 機能

### 🔍 Web検索
事務所名を入力してタレント一覧を自動取得

### 📝 テキスト入力
タレント名リストを直接ペースト

### 📚 履歴（検索キャッシュ）
過去に調査したタレントを一覧表示・再利用

## v2.1 コスト最適化の仕組み

### 検索統合
関連する検索を1つにまとめて検索回数を削減:

| 統合前 | 統合後 | モデル |
|--------|--------|--------|
| profile | profile | sonar-pro |
| sns | sns | sonar-pro |
| live, release | **activity** | sonar-pro |
| goods, fanclub, online | **fanservice** | sonar |
| contact | contact | sonar |
| limista, news | **news_all** | sonar |

### モデル最適化
- **sonar-pro** ($5/1000回): 精度重要な検索（プロフィール、SNS、活動）
- **sonar** ($1/1000回): 補助的な検索（ファンサービス、連絡先、ニュース）

## キャッシュ機能

調査結果はサーバーにキャッシュされ、再検索時にAPI呼び出しを削減:

| 情報 | キャッシュ期間 |
|------|---------------|
| プロフィール | 30日 |
| SNS | 7日 |
| 活動情報 | 7日 |
| ファンサービス | 14日 |
| 連絡先 | 30日 |
| ニュース | 1日 |

### 実際のコスト例（10タレント調査）

| シナリオ | 検索回数 | コスト |
|----------|----------|--------|
| 初回調査 | 60回 | $0.18 |
| 7日以内の再調査 | 10-20回 | $0.03-0.06 |
| キャッシュ全ヒット | 0回 | $0.00 |

## トラブルシューティング

### CORS エラー
APIファイルとHTMLファイルが同じドメインにあることを確認

### キャッシュをクリア
入力画面の「📦 キャッシュ」ボタンからクリア可能

### 接続テスト
入力画面の「🔧 接続」「🔑 APIキー」ボタンで確認
