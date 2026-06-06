# WP MariaDB Vector Search

Replaces WordPress's default `LIKE`-based search with cosine similarity search powered by MariaDB's native `VECTOR` type.

## Requirements

| Dependency | Minimum version |
|------------|----------------|
| WordPress  | 7.0+           |
| PHP        | 8.2+           |
| MariaDB    | 11.7+          |

Embeddings are generated via the **WordPress 7.0 core AI Connector**. If no AI provider is configured, indexing is skipped and search falls back to the standard WordPress LIKE search.

## Installation

1. Place the plugin in `wp-content/plugins/wp-mariadb-vector-search/`.
2. Activate it from the WordPress admin. The `{prefix}mariadb_vector_embeddings` table is created automatically on activation.

## Usage

### Automatic indexing

Posts are indexed (or removed from the index) automatically whenever they are published, updated, or deleted.

### Admin page (Tools > Vector Search)

- Shows MariaDB VECTOR support status and the number of indexed posts.
- **Reindex all posts** schedules a background cron job to embed all existing posts.
- Check **Force reindex** to re-embed posts whose content has not changed.

### WP-CLI

```bash
# Reindex all posts
wp mariadb-vector reindex

# Limit to a specific post type
wp mariadb-vector reindex --post-type=post

# Re-embed even posts whose content has not changed
wp mariadb-vector reindex --force

# Set the batch size (default: 50)
wp mariadb-vector reindex --batch=100
```

## Configuration

Stored in the `wp_mariadb_vector_search_settings` option (array).

| Key                  | Default | Description |
|----------------------|---------|-------------|
| `dimensions`         | 1536    | Vector dimensions. Fixed at install time; changing the model requires dropping and re-creating the table. |
| `top_k`              | 20      | Number of posts returned by a vector search. |
| `chunk_overscan`     | 5       | Inner query `LIMIT` multiplier (`top_k × overscan`). |
| `chunk_size_chars`   | 2000    | Target chunk size in characters. |
| `chunk_overlap_chars`| 300     | Overlap between adjacent chunks in characters. |

## Filters

### `wp_mariadb_vector_search_embed`

Replace the embedding provider.

```php
add_filter(
    'wp_mariadb_vector_search_embed',
    function ( $default, array $texts ): array {
        // $texts   — string[] of texts to embed
        // return   — float[][] one vector per input text
        return my_embedding_provider( $texts );
    },
    10,
    2
);
```

### `wp_mariadb_vector_search_post_types`

Control which post types are indexed and searched.

```php
add_filter(
    'wp_mariadb_vector_search_post_types',
    function ( array $types ): array {
        return [ 'post', 'page', 'product' ];
    }
);
```

### `wp_mariadb_vector_search_max_distance`

Maximum cosine distance a post may have from the query to appear in results.
`0` = identical, smaller = more similar, `~1` = unrelated. Default `0.65`.
The optimal value depends on the embedding model — adjust as needed.

```php
add_filter(
    'wp_mariadb_vector_search_max_distance',
    function (): float {
        return 0.5; // stricter: only closely related posts
    }
);
```

### `wp_mariadb_vector_search_max_results`

Safety cap on the number of posts returned by a single search. Also controls the inner
`LIMIT` passed to the database query so the VECTOR INDEX is used. Default `200`.

```php
add_filter(
    'wp_mariadb_vector_search_max_results',
    function (): int {
        return 50;
    }
);
```

## Uninstall

Deleting the plugin runs `uninstall.php`, which drops the embeddings table and removes all related options.

## Development

### Running tests

```bash
npm install
composer install

# Start wp-env (MariaDB 11.7+) and run PHPUnit
npm run test:php
```

### File structure

```
includes/
  Admin.php            — Tools > Vector Search admin page
  Chunker.php          — HTML-strip + paragraph/sentence chunking with overlap
  CLI.php              — WP-CLI reindex command
  Content_Hash.php     — SHA-256 hash of post title + content
  Cron_Backfill.php    — Batched cron-driven backfill for existing posts
  Embedding_Client.php — Thin wrapper over the WP AI Connector
  Indexer.php          — chunk → embed → store pipeline
  Plugin.php           — Plugin lifecycle and hook registration
  Repository.php       — $wpdb wrapper: vector CRUD and KNN queries
  Schema.php           — DDL management (CREATE / DROP / version check)
  Search.php           — pre_get_posts hook: rewrites search to vector KNN
```
