# AGENTS.md — WP MariaDB Vector Search

Specification for AI agents (and humans) implementing this plugin.

## Goal

Replace WordPress's default `LIKE`-based search with **semantic similarity search**
backed by MariaDB's native `VECTOR` column type. Embeddings are produced through
the **WordPress core AI Connector (WP 7.0+)**, which standardizes access to
embedding / completion providers without locking the plugin to a single vendor.

## Environment

| Requirement       | Version                                                    |
| ----------------- | ---------------------------------------------------------- |
| WordPress         | **7.0+** (required — core AI Connector API)                |
| PHP               | 8.2+                                                       |
| MariaDB           | **11.7+ (11.8 LTS recommended)** — required for `VECTOR`   |
| MySQL             | Not supported (no `VECTOR` type). Fail fast with admin notice. |

A provider must be configured for the AI Connector (e.g. an OpenAI- or
Google-backed provider). Without an embedding-capable provider the plugin
gracefully falls through to the default WordPress search.

`dbDelta()` does **not** understand `VECTOR(N)` / `VECTOR INDEX`. Use raw
`$wpdb->query()` for DDL, gated by a versioned option
`wp_mariadb_vector_search_db_version`.

## Architecture

```
save_post ──► Indexer::enqueue (wp_schedule_single_event)
                │
                ▼
        Indexer::run_job
                │  Chunker → string[]
                ▼
        Embedding_Client::embed (batched)  ──► WP AI Connector
                │  float[][]
                ▼
        Repository::replace_post_chunks(post_id, chunks)

is_search() ──► Search::pre_get_posts
                │  query string → Embedding_Client::embed (1 chunk)
                ▼
        Repository::search_similar(query_vec, post_types, max_distance, max_results) → post__in (ordered)
```

### Design decisions

- **All AI calls go through one wrapper.** `Embedding_Client` is a thin wrapper;
  it delegates to `Embedding_Prompt_Builder` for provider/model resolution and
  HTTP requests. Indexer and Search depend on this wrapper, not on the connector
  directly, so provider/API shifts are isolated.
- **Chunking, one post → N vectors.** Long posts lose information when
  truncated to a model's token limit, so we split them. Storage is per-chunk;
  search aggregates back to posts via `MIN(distance)`.
- **Separate table.** Embeddings live in `{$prefix}mariadb_vector_embeddings`,
  not in `wp_posts` or `wp_postmeta`. Avoids autoloading, simplifies uninstall.
- **Async indexing.** `save_post` only schedules; embedding happens on cron
  to keep editor save fast and to survive AI provider failures.
- **Replace, don't upsert.** When a post is reindexed, all its rows are
  DELETEd then re-INSERTed inside a transaction. Avoids stale chunks.
- **Search override is opt-out-safe.** Filter `pre_get_posts` only when
  `is_main_query() && is_search() && s != ''`. On embedding error fall through
  to default WordPress search rather than returning nothing.

## Schema

```sql
CREATE TABLE {$prefix}mariadb_vector_embeddings (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  post_id      BIGINT UNSIGNED NOT NULL,
  chunk_index  SMALLINT UNSIGNED NOT NULL,
  post_type    VARCHAR(20)       NOT NULL,
  dimensions   SMALLINT UNSIGNED NOT NULL,
  embedding    VECTOR(:N)        NOT NULL,
  chunk_text   TEXT              NOT NULL,
  content_hash CHAR(64)          NOT NULL,
  updated_at   DATETIME          NOT NULL,
  UNIQUE KEY post_chunk (post_id, chunk_index),
  KEY post_type_idx (post_type),
  VECTOR INDEX (embedding) DISTANCE=cosine
) ENGINE=InnoDB;
```

`:N` is fixed at install time from the chosen model
(e.g. OpenAI `text-embedding-3-small` = 1536, Google `text-embedding-004` = 768).
Changing the model to one with a different dimension requires dropping and
re-creating the table; the Admin page handles this automatically.

`content_hash` is `sha256(title + "\n\n" + raw_content)` — used by the indexer
to skip work when the post body has not changed.

## Components

| File                              | Responsibility                                                                                     |
| --------------------------------- | -------------------------------------------------------------------------------------------------- |
| `wp-mariadb-vector-search.php`    | Bootstrap. Defines `WP_MARIADB_VECTOR_SEARCH_VERSION`, registers activation hooks.                 |
| `includes/Plugin.php`             | Composition root. Instantiates components and registers hooks.                                     |
| `includes/Schema.php`             | Install/upgrade table. Verify MariaDB version. Admin notice on incompatibility.                    |
| `includes/Repository.php`         | `$wpdb` wrapper. `replace_post_chunks()`, `delete_post()`, `search_similar()`. Vector text serialization. |
| `includes/Embedding_Client.php`   | Thin wrapper over the WP 7.0 AI Connector. Delegates to `Embedding_Prompt_Builder`. Batched `texts[] → vectors[][]`. |
| `includes/Embedding_Prompt_Builder.php` | Resolves provider/model from settings, builds HTTP requests, applies timeout filter.         |
| `includes/Model_Catalog.php`      | Enumerates available embedding models (auto-detect from registered providers + known list). Filterable. |
| `includes/Content_Hash.php`       | SHA-256 hash of post title + content.                                                              |
| `includes/Chunker.php`            | Block strip + paragraph/sentence/char splitting with overlap. Title prepended to each chunk.       |
| `includes/Indexer.php`            | `save_post`/`delete_post`/`trashed_post` hooks. Cron worker that calls embedding client and repository. |
| `includes/Search.php`             | `pre_get_posts` rewrite to `post__in` + `orderby=post__in`. Safe fallback to default search.       |
| `includes/CLI.php`                | `wp mariadb-vector reindex [--post-type=] [--force] [--batch=]`. Loaded only if `WP_CLI`.          |
| `includes/Cron_Backfill.php`      | Batched cron-driven reindex of all posts. Progress in transient.                                   |
| `includes/Admin.php`              | Tools page: status, model selector, unified Reindex button (auto-detects dimension diff).          |
| `uninstall.php`                   | `DROP TABLE`, delete all `wp_mariadb_vector_search_*` options.                                     |

## Similarity search query

Two-step query to keep `VECTOR INDEX` usable (a `GROUP BY` directly on the
distance expression defeats the index):

```sql
-- Inner: index-driven top-(max_results * overscan) chunks
SELECT post_id,
       VEC_DISTANCE_COSINE(embedding, VEC_FromText(%s)) AS d
FROM   {table}
WHERE  post_type IN (...)
ORDER  BY d ASC
LIMIT  %d;

-- Outer (PHP-side): group by post_id, keep MIN(d),
--   filter d <= max_distance, slice to max_results
```

The default `overscan` of 5 covers typical content. `max_distance` (default
0.65) excludes posts that are too dissimilar; `max_results` (default 200) caps
the result set and ensures the VECTOR INDEX is engaged.

## Chunking

- Strip blocks (`excerpt_remove_blocks()`) and HTML (`wp_strip_all_tags()`).
- Split on paragraph → sentence → character boundaries.
- Default `chunk_size_chars` = 2000, `chunk_overlap_chars` = 300 (good for
  Japanese + English mixed text; ~1000 tokens per chunk). These are code-level
  defaults in `Chunker.php`, not stored in the settings option.
- Prepend `"{title}\n\n"` to every chunk so isolated chunks retain topical
  context.
- Token-count approximation: `mb_strlen($text) / 2` (close enough for size
  budgeting — actual tokenization is the provider's job).

## Settings (`wp_mariadb_vector_search_settings`, single option, array)

| Key          | Default | Purpose                                                     |
| ------------ | ------- | ----------------------------------------------------------- |
| `provider`   | —       | AI provider id (e.g. `openai`, `lmstudio`).                 |
| `model`      | —       | Embedding model id (e.g. `text-embedding-3-small`).         |
| `dimensions` | 1536    | Fixed at install. Schema's `VECTOR(N)`. Changing to a different dimension requires Reindex. |

Other options:

- `wp_mariadb_vector_search_db_version` — schema migration marker.

## Extension points (filters/actions)

- `wp_mariadb_vector_search_post_types` (`string[]`) — override indexed post types.
- `wp_mariadb_vector_search_max_distance` (`float`, default `0.65`) — maximum cosine distance to include in results. `0` = identical, smaller = more similar. Optimal value is model-dependent.
- `wp_mariadb_vector_search_max_results` (`int`, default `200`) — safety cap on returned posts; also the basis for the inner `LIMIT` so the VECTOR INDEX is used.
- `wp_mariadb_vector_search_embedding_timeout` (`float`, default `60.0`) — HTTP timeout in seconds for embedding API requests. Increase for local models (e.g. LM Studio).
- `wp_mariadb_vector_search_known_embedding_models` (`array`) — extend or replace the built-in list of known embedding models shown in the model selector.

## `$wpdb` notes

- `VECTOR` is a binary type and is unaffected by `utf8mb4`.
- Pass vectors as JSON-style text wrapped by `VEC_FromText(%s)`. **Do not** use
  `%f` for floats: locale-dependent and lossy. Format with
  `number_format($f, 10, '.', '')` and a locale-stable join.
- `$wpdb` returns `VECTOR` columns as raw bytes. When reading vectors back in
  PHP, prefer `SELECT VEC_ToText(embedding)`.

## Development workflow — Test-Driven Development

This plugin is implemented **TDD-first**. Every component is built in tight
red → green → refactor cycles. No production code without a failing test that
demands it.

Rules:

1. **Red**: add or extend a test in `tests/phpunit/...` that fails for the
   right reason.
2. **Green**: write the minimum code in `includes/...` to make it pass — no
   more. Resist designing for hypothetical needs.
3. **Refactor**: clean up under the safety of green tests. Run `composer lint`.
4. Commit after each green-and-clean cycle.

Test commands:

```bash
npm run test:php:base  # fast (recommended; requires wp-env running)
npm run test:php       # full suite
composer lint          # PHPCS
```

Layering:

- **Unit tests** (`tests/phpunit/unit/`): pure-PHP logic, no WordPress.
  Examples: vector serialization, chunker, text normalization, hashing, model catalog.
  These must run without `wp-env`.
- **Integration tests** (`tests/phpunit/integration/`): exercise `$wpdb`,
  hooks, and the AI Connector wrapper. Run inside `wp-env` against MariaDB
  11.7+. The AI Connector is **stubbed** via dependency injection — an anonymous
  class implementing the `Embedding_Client` interface is passed directly to
  `Indexer`/`Search`, returning deterministic vectors without any network calls.
  Never call a real LLM in CI.

Test file naming convention: `*_Test.php`.

## Manual verification

1. `wp-env start` (ensure the image is MariaDB 11.7+; check with
   `wp-env run cli wp db query "SELECT VERSION();"`).
2. Configure an embedding-capable provider for the WP 7.0 AI Connector
   (admin UI or `wp option update`).
3. Activate this plugin; check `SHOW CREATE TABLE wp_mariadb_vector_embeddings`.
4. Create posts → `wp cron event run --due-now` → inspect:
   `wp db query "SELECT post_id, chunk_index, VEC_ToText(embedding) FROM wp_mariadb_vector_embeddings WHERE post_id=N;"`
5. `wp mariadb-vector reindex` — confirm progress bar and final count.
6. Search with semantically related but lexically distinct terms
   (e.g. "猫" matching posts that only say "ねこ" / "feline");
   compare ordering vs. default search.
7. Disable the provider → search → confirm fallback to default behavior + WP_Error in log.
8. `npm run test:php:base` and `composer lint`.
9. Delete the plugin → `SHOW TABLES LIKE 'wp_mariadb_vector_embeddings'` returns empty.
