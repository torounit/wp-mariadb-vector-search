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
        Repository::knn(query_vec, k) → post__in (ordered)
```

### Design decisions

- **All AI calls go through one wrapper.** `Embedding_Client` is the only
  class that talks to the WP 7.0 AI Connector. Indexer and Search depend on
  this wrapper, not on the connector directly, so provider/API shifts are
  isolated. A filter `wp_mariadb_vector_search_embed` is exposed as an
  escape hatch for custom backends.
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
  model        VARCHAR(64)       NOT NULL,
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
Changing the model later requires dropping and re-creating the table; the model
name is stored per row so partial migrations are possible.

`content_hash` is `sha256(title + "\n\n" + raw_content)` — used by the indexer
to skip work when the post body has not changed.

## Components

| File                                          | Responsibility                                                                                     |
| --------------------------------------------- | -------------------------------------------------------------------------------------------------- |
| `wp-mariadb-vector-search.php`                | Bootstrap. Defines `WP_MARIADB_VECTOR_SEARCH_VERSION`, registers activation hooks.                 |
| `includes/class-plugin.php`                   | Composition root. Instantiates components and registers hooks.                                     |
| `includes/class-schema.php`                   | Install/upgrade table. Verify MariaDB version. Admin notice on incompatibility.                    |
| `includes/class-repository.php`               | `$wpdb` wrapper. `replace_post_chunks()`, `delete_post()`, `knn()`. Vector text serialization.     |
| `includes/class-embedding-client.php`         | Thin wrapper over the WP 7.0 AI Connector. Batched `texts[] → vectors[][]`. Detects provider availability. |
| `includes/class-chunker.php`                  | Block strip + paragraph/sentence/char splitting with overlap. Title prepended to each chunk.       |
| `includes/class-indexer.php`                  | `save_post`/`delete_post`/`trashed_post` hooks. Cron worker that calls embedding client and repository. |
| `includes/class-search.php`                   | `pre_get_posts` rewrite to `post__in` + `orderby=post__in`. Safe fallback to default search.       |
| `includes/class-cli.php`                      | `wp mariadb-vector reindex [--post-type=] [--force] [--batch=]`. Loaded only if `WP_CLI`.          |
| `includes/class-cron-backfill.php`            | Batched cron-driven reindex of all posts. Progress in transient.                                   |
| `includes/class-admin.php`                    | Tools page: status (MariaDB version, indexed count, last update, AI Connector availability), "Reindex all" button. |
| `uninstall.php`                               | `DROP TABLE`, delete all `wp_mariadb_vector_search_*` options.                                     |

## KNN query

Two-step query to keep `VECTOR INDEX` usable (a `GROUP BY` directly on the
distance expression defeats the index):

```sql
-- Inner: index-driven top-(k * overscan) chunks
SELECT post_id,
       VEC_DISTANCE_COSINE(embedding, VEC_FromText(%s)) AS d
FROM   {table}
WHERE  post_type IN (...)
ORDER  BY d ASC
LIMIT  %d;

-- Outer (PHP-side): group by post_id, keep min d, take top k
```

If overscan is insufficient (many chunks from few posts dominate), bump
`chunk_overscan`. The default of 5 covers typical content.

## Chunking

- Strip blocks (`excerpt_remove_blocks()`) and HTML (`wp_strip_all_tags()`).
- Split on paragraph → sentence → character boundaries.
- Default `chunk_size_chars` = 2000, `chunk_overlap_chars` = 300 (good for
  Japanese + English mixed text; ~1000 tokens per chunk).
- Prepend `"{title}\n\n"` to every chunk so isolated chunks retain topical
  context.
- Token-count approximation: `mb_strlen($text) / 2` (close enough for size
  budgeting — actual tokenization is the provider's job).

## Settings (`wp_mariadb_vector_search_settings`, single option, array)

| Key                   | Default | Purpose                                                |
| --------------------- | ------- | ------------------------------------------------------ |
| `model`               | (informational) | Display label, e.g. `text-embedding-3-small`   |
| `dimensions`          | 1536    | Fixed at install. Schema's `VECTOR(N)`.                |
| `top_k`               | 20      | Number of posts to return.                             |
| `chunk_overscan`      | 5       | Inner LIMIT multiplier (`top_k * overscan`).           |
| `chunk_size_chars`    | 2000    | Target chunk size.                                     |
| `chunk_overlap_chars` | 300     | Overlap between adjacent chunks.                       |
| `min_score`           | 0.6     | Max cosine distance to include in results.             |

Other options:

- `wp_mariadb_vector_search_db_version` — schema migration marker.

## Extension points (filters/actions)

- `wp_mariadb_vector_search_post_types` (`string[]`) — override indexed post types.
- `wp_mariadb_vector_search_indexable_text` (`string $text, WP_Post $post`) — adjust source text before chunking.
- `wp_mariadb_vector_search_chunks` (`string[] $chunks, WP_Post $post`) — replace chunk array entirely.
- `wp_mariadb_vector_search_chunk_size` (`int`, `int`) — tweak size / overlap.
- `wp_mariadb_vector_search_embed` (`float[][]|WP_Error, string[] $texts`) — provide an alternative embedding backend.
- `wp_mariadb_vector_search_query_args` (`array $args, WP_Query $q`) — adjust args before `pre_get_posts` rewrite.

## `$wpdb` notes

- `VECTOR` is a binary type and is unaffected by `utf8mb4`.
- Pass vectors as JSON-style text wrapped by `VEC_FromText(%s)`. **Do not** use
  `%f` for floats: locale-dependent and lossy. Format with
  `sprintf('%.7g', $f)` and a locale-stable join.
- `$wpdb` returns `VECTOR` columns as raw bytes. When reading vectors back in
  PHP, prefer `SELECT VEC_ToText(embedding)`.
- Cache KNN results by `hash(query_vec + model + filters)`; never by `s` alone.

## Development workflow — Test-Driven Development

This plugin is implemented **TDD-first**. Every component is built in tight
red → green → refactor cycles. No production code without a failing test that
demands it.

Rules:

1. **Red**: add or extend a test in `tests/phpunit/...` that fails for the
   right reason (run `composer test` to see the failure).
2. **Green**: write the minimum code in `includes/...` to make it pass — no
   more. Resist designing for hypothetical needs.
3. **Refactor**: clean up under the safety of green tests. Run `composer lint`.
4. Commit after each green-and-clean cycle.

Layering:

- **Unit tests** (`tests/phpunit/unit/`): pure-PHP logic, no WordPress.
  Examples: vector serialization, chunker, text normalization, hashing.
  These must run without `wp-env`.
- **Integration tests** (`tests/phpunit/integration/`): exercise `$wpdb`,
  hooks, and the AI Connector wrapper. Run inside `wp-env` against MariaDB
  11.7+. The AI Connector is **stubbed** by registering a fake provider via
  `wp_mariadb_vector_search_embed` filter so tests are deterministic and
  offline — never call a real LLM in CI.
- **Manual verification** stays as the smoke test list at the bottom of this
  document; not a substitute for automated tests.

Suggested test files (built incrementally, one per implementation step):

- `tests/phpunit/unit/test-vector-serialization.php` — locale independence, NaN/Inf rejection, fixed-dimension enforcement.
- `tests/phpunit/unit/test-chunker.php` — paragraph/sentence/char split, overlap, title prepend, empty/short/very-long boundaries, multibyte safety.
- `tests/phpunit/unit/test-content-hash.php` — hash stability across whitespace/block-comment changes.
- `tests/phpunit/integration/test-schema.php` — install creates the table with `VECTOR(N)` and the cosine `VECTOR INDEX`; reinstall is idempotent; uninstall drops it.
- `tests/phpunit/integration/test-repository-knn.php` — `replace_post_chunks` + `knn` against real MariaDB. Verifies post-level `MIN(distance)` aggregation and `post_type` filtering.
- `tests/phpunit/integration/test-embedding-client.php` — stubbed AI Connector returns expected vectors; provider-missing case yields `WP_Error`; the `wp_mariadb_vector_search_embed` escape hatch wins.
- `tests/phpunit/integration/test-indexer.php` — `save_post` schedules a job; running the cron job calls the embedding client and writes chunks; unchanged content (`content_hash` match) is skipped; `delete_post` clears rows.
- `tests/phpunit/integration/test-search.php` — `pre_get_posts` rewrite produces expected `post__in` ordering for a known stub; falls back to default search on embedding error; respects `post_type` filter.
- `tests/phpunit/integration/test-cli.php` — `wp mariadb-vector reindex` processes a fixture set and exits 0; `--force` re-embeds even when hash matches.

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
8. `composer test` and `composer lint`.
9. Delete the plugin → `SHOW TABLES LIKE 'wp_mariadb_vector_embeddings'` returns empty.
