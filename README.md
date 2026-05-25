# WP MariaDB Vector Search

WordPress の標準 `LIKE` 検索を、MariaDB ネイティブの `VECTOR` 型によるコサイン類似度検索に置き換えるプラグインです。

## 必要環境

| 依存 | 最低バージョン |
|------|---------------|
| WordPress | 7.0+ |
| PHP | 8.2+ |
| MariaDB | 11.7+ (VECTOR サポートあり) |

Embedding 生成には **WordPress 7.0 コア AI Connector** を使用します。AI プロバイダーが設定されていない場合、インデックスはスキップされ、標準検索にフォールバックします。

## インストール

1. プラグインを `wp-content/plugins/wp-mariadb-vector-search/` に配置します。
2. WordPress 管理画面からプラグインを有効化します。  
   有効化時に `{prefix}mariadb_vector_embeddings` テーブルが自動作成されます。

## 使い方

### 自動インデックス

投稿を公開・更新・削除すると自動でインデックスが更新されます。

### 管理画面 (Tools > Vector Search)

- MariaDB の VECTOR サポート状況を確認できます。
- 現在インデックスされている投稿数を表示します。
- **Reindex all posts** ボタンで既存投稿を一括再インデックスできます。  
  `Force reindex` オプションをオンにすると、内容が変更されていない投稿も再 Embedding します。

### WP-CLI

```bash
# 全投稿を再インデックス
wp mariadb-vector reindex

# 特定の投稿タイプのみ
wp mariadb-vector reindex --post-type=post

# 内容が変わっていない投稿も強制再 Embedding
wp mariadb-vector reindex --force

# バッチサイズを指定 (デフォルト: 50)
wp mariadb-vector reindex --batch=100
```

## 設定

`wp_mariadb_vector_search_settings` オプション (配列) で以下を変更できます。

| キー | デフォルト | 説明 |
|-----|-----------|------|
| `dimensions` | 1536 | ベクトルの次元数。インストール時に固定。モデル変更時はテーブルを再作成してください。 |
| `top_k` | 20 | 検索で返す投稿数。 |
| `chunk_overscan` | 5 | 内部クエリの LIMIT 倍率 (`top_k × overscan`)。 |
| `chunk_size_chars` | 2000 | チャンクの目標文字数。 |
| `chunk_overlap_chars` | 300 | 隣接チャンクのオーバーラップ文字数。 |

## フィルター / アクション

### `wp_mariadb_vector_search_embed`

Embedding プロバイダーを差し替えます。

```php
add_filter(
    'wp_mariadb_vector_search_embed',
    function ( $default, array $texts ): array {
        // $texts: string[] — Embedding したいテキストの配列
        // 返り値: float[][] — 各テキストに対応するベクトルの配列
        return my_embedding_provider( $texts );
    },
    10,
    2
);
```

### `wp_mariadb_vector_search_post_types`

インデックス・検索対象の投稿タイプを制御します。

```php
add_filter(
    'wp_mariadb_vector_search_post_types',
    function ( array $types ): array {
        return [ 'post', 'page', 'product' ];
    }
);
```

## アンインストール

プラグインを削除すると `uninstall.php` が実行され、テーブルと関連オプションが削除されます。

## 開発

### テスト

```bash
# 依存インストール
npm install
composer install

# wp-env を使ってテスト実行 (MariaDB 11.7+ が起動します)
npm run test:php
```

### ファイル構成

```
includes/
  Admin.php            — 管理画面ページ
  Chunker.php          — テキストチャンキング
  CLI.php              — WP-CLI コマンド
  Content_Hash.php     — 投稿内容の SHA-256 ハッシュ
  Cron_Backfill.php    — Cron による一括再インデックス
  Embedding_Client.php — AI Connector ラッパー
  Indexer.php          — 投稿の Embedding → 保存パイプライン
  Repository.php       — $wpdb ラッパー (ベクトル CRUD + KNN)
  Schema.php           — テーブル DDL 管理
  Search.php           — pre_get_posts フック (ベクトル検索への書き換え)
  class-plugin.php     — プラグインライフサイクル・フック登録
```
