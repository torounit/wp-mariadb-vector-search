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
        Repository::search_similar(query_vec, post_types, max_distance, max_relative_distance, max_results) ─┐
                                                                                                                │
        WP_Query (LIKE, relevance) ────────────────────────────────────────────────────────────────────────────┤
                                                                                                                ▼
                                                                                                  Rank_Fusion::fuse (RRF) → post__in (ordered)
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
- **Hybrid by default.** Vector results and WordPress's default LIKE search
  results (via a sub `WP_Query`, `orderby=relevance`) are merged with
  Reciprocal Rank Fusion (`Rank_Fusion::fuse()`). This catches lexical-only
  matches (e.g. product codes, names) that embeddings rank poorly. The
  `max_distance` filter still applies to the vector side, so unrelated
  generic content is not reintroduced via the union. Opt out with
  `wp_mariadb_vector_search_hybrid`.

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
| `wp-mariadb-vector-search.php`    | Bootstrap; activation hooks.                                                                       |
| `includes/Plugin.php`             | Composition root. Instantiates components and registers hooks.                                     |
| `includes/Schema.php`             | Install/upgrade table (see Schema). Verify MariaDB version; admin notice on incompatibility.       |
| `includes/Repository.php`         | `$wpdb` wrapper for the embeddings table; vector text serialization.                                |
| `includes/Embedding_Client.php`   | Thin wrapper over the WP 7.0 AI Connector. Delegates to `Embedding_Prompt_Builder`. Batched `texts[] → vectors[][]`. |
| `includes/Embedding_Prompt_Builder.php` | Resolves provider/model from settings, builds HTTP requests, applies timeout filter.         |
| `includes/Model_Catalog.php`      | Enumerates available embedding models (auto-detect from registered providers + known list). Filterable. |
| `includes/Content_Hash.php`       | Content hash helper (see Schema for `content_hash`).                                               |
| `includes/Chunker.php`            | Splits post content into chunks (see Chunking).                                                    |
| `includes/Indexer.php`            | `save_post`/`delete_post`/`trashed_post` hooks. Cron worker that calls embedding client and repository. |
| `includes/Search.php`             | `pre_get_posts` rewrite; hybrid fusion (see Architecture).                                          |
| `includes/Rank_Fusion.php`        | Pure RRF (Reciprocal Rank Fusion) of ranked ID lists. WordPress-independent.                       |
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

The default `overscan` is 5. `max_distance`, `max_relative_distance`, and
`max_results` (see Extension points below) control which posts survive this
query and how many.

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

| Filter | Type | Default | Notes |
| --- | --- | --- | --- |
| `wp_mariadb_vector_search_post_types` | `string[]` | public searchable post types | Override indexed post types. |
| `wp_mariadb_vector_search_max_distance` | `float` | `0.65` | Max cosine distance to include (`0`=identical). |
| `wp_mariadb_vector_search_max_relative_distance` | `float` | `0.25` | Max distance gap from the best match; excludes posts that fall further behind (e.g. the default "Hello world!" post). `INF` disables. |
| `wp_mariadb_vector_search_max_results` | `int` | `200` | Cap on returned posts; basis for the inner `LIMIT`. |
| `wp_mariadb_vector_search_embedding_timeout` | `float` | `60.0` | HTTP timeout (seconds) for embedding requests. Increase for local models. |
| `wp_mariadb_vector_search_known_embedding_models` | `array` | — | Extend/replace the known embedding models list. |
| `wp_mariadb_vector_search_hybrid` | `bool` | `true` | Fuse vector results with LIKE results via RRF. `false` = vector-only. |
| `wp_mariadb_vector_search_rrf_k` | `int` | `60` | RRF constant; higher flattens the influence of top ranks. |
| `wp_mariadb_vector_search_like_results` | `int` | = `max_results` | Results fetched from the LIKE sub-query before fusion. |

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
