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

Displays MariaDB VECTOR support status, indexed post count, table dimensions, and backfill progress.

**Embedding Model** — Select the AI provider and model to use for embedding generation. Saving a model probes the API to detect the native vector dimension and stores it in settings. The embeddings table is not changed at this point.

**Reindex** — Schedules a background cron job to embed all existing posts. Behaviour depends on the current state:

- **Dimensions match** — Non-destructive. Each post's rows are replaced individually; existing vectors for other posts are untouched. Optionally check *Force reindex* to re-embed posts whose content has not changed.
- **Dimensions differ** — The embeddings table must be recreated. A warning is shown and a confirmation checkbox is required. On confirmation the table is dropped and recreated at the new dimension, then a full reindex is scheduled.
- **Table not yet installed** — Creates the table at the saved model's dimensions, then schedules a full reindex. No confirmation needed.

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

Stored in the `wp_mariadb_vector_search_settings` option (array). Values are written automatically by the **Save model** action in the admin UI.

| Key          | Description |
|--------------|-------------|
| `provider`   | AI provider id (e.g. `openai`, `lmstudio`). |
| `model`      | Embedding model id (e.g. `text-embedding-3-small`). |
| `dimensions` | Vector dimensions — auto-detected by probing the API when a model is saved. Fixed at table creation; changing to a different dimension requires a Reindex (which recreates the table). |

Chunk settings are code-level defaults in `Chunker.php` and are not stored in the option:

| Setting              | Default | Description |
|----------------------|---------|-------------|
| `chunk_size_chars`   | 2000    | Target chunk size in characters. |
| `chunk_overlap_chars`| 300     | Overlap between adjacent chunks in characters. |

## Filters

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

### `wp_mariadb_vector_search_embedding_timeout`

HTTP timeout in seconds for embedding API requests. Increase for local models
(e.g. LM Studio) that need time to load. Default `60.0`.

```php
add_filter(
    'wp_mariadb_vector_search_embedding_timeout',
    function (): float {
        return 120.0;
    }
);
```

### `wp_mariadb_vector_search_known_embedding_models`

Extend or replace the built-in list of known embedding models shown in the
model selector. Entries are only displayed when the provider is registered
**and** configured in the AI Connector settings.

```php
add_filter(
    'wp_mariadb_vector_search_known_embedding_models',
    function ( array $models ): array {
        $models[] = [
            'provider' => 'my-provider',
            'model'    => 'my-embed-model',
        ];
        return $models;
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
  Admin.php                  — Tools > Vector Search admin page
  Chunker.php                — HTML-strip + paragraph/sentence chunking with overlap
  CLI.php                    — WP-CLI reindex command
  Content_Hash.php           — SHA-256 hash of post title + content
  Cron_Backfill.php          — Batched cron-driven backfill for existing posts
  Embedding_Client.php       — Thin wrapper: delegates to Embedding_Prompt_Builder
  Embedding_Prompt_Builder.php — Resolves provider/model from settings, builds HTTP requests
  Indexer.php                — chunk → embed → store pipeline
  Model_Catalog.php          — Enumerates available embedding models (auto-detect + known list)
  Plugin.php                 — Plugin lifecycle and hook registration
  Repository.php             — $wpdb wrapper: vector CRUD and similarity search
  Schema.php                 — DDL management (CREATE / DROP / version check)
  Search.php                 — pre_get_posts hook: rewrites search to vector similarity
```
