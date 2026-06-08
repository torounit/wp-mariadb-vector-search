<?php
/**
 * Database repository for vector embeddings.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

/**
 * Wraps $wpdb operations for the embeddings table and provides
 * low-level vector serialization helpers.
 */
class Repository {

	/**
	 * Whether get_column_dimensions() has already queried the database.
	 *
	 * Separating the "cached" flag from the value allows caching a null result
	 * (column not found / table not yet installed) without re-querying on every
	 * call.
	 *
	 * @var bool
	 */
	private bool $dims_cached = false;

	/**
	 * Cached result of get_column_dimensions(), or null if the column was not found.
	 *
	 * @var int|null
	 */
	private ?int $dims_cache = null;

	/**
	 * Serialize a float array to the text format expected by VEC_FromText().
	 *
	 * Uses number_format with an explicit '.' decimal separator so the output
	 * is locale-independent regardless of the LC_NUMERIC setting on the host.
	 *
	 * @param float[] $vector Non-empty array of finite floats.
	 * @return string e.g. "[0.1,0.2,0.3]"
	 * @throws \InvalidArgumentException On empty array or non-finite values.
	 */
	public static function format_vector_literal( array $vector ): string {
		if ( empty( $vector ) ) {
			throw new \InvalidArgumentException( 'Vector must not be empty.' );
		}

		$parts = array();
		foreach ( $vector as $value ) {
			$f = (float) $value;
			if ( is_nan( $f ) || is_infinite( $f ) ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- exception messages are not HTML output.
				throw new \InvalidArgumentException( 'Vector components must be finite numbers; got ' . (string) $f . '.' );
			}
			// number_format always uses '.' as the decimal separator (locale-safe).
			// 10 decimal places covers float32 precision for values in [-1, 1].
			$str     = number_format( $f, 10, '.', '' );
			$parts[] = rtrim( rtrim( $str, '0' ), '.' );
		}

		return '[' . implode( ',', $parts ) . ']';
	}

	// -----------------------------------------------------------------------
	// Write operations
	// -----------------------------------------------------------------------

	/**
	 * Return the declared dimension of the embedding VECTOR column, or null on error.
	 *
	 * The result is cached in instance properties rather than a static variable.
	 * A static cache would persist across PHPUnit test cases (same PHP process),
	 * causing stale dimension values after Schema::drop()/install() cycles.
	 * In production, Plugin wires a single Repository instance for the full
	 * request, so the per-instance cache is equally efficient there.
	 *
	 * @return int|null
	 */
	public function get_column_dimensions(): ?int {
		if ( $this->dims_cached ) {
			return $this->dims_cache;
		}

		global $wpdb;
		$table = $wpdb->prefix . 'mariadb_vector_embeddings';

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$col_type = $wpdb->get_var(
			$wpdb->prepare(
				'SELECT COLUMN_TYPE FROM information_schema.COLUMNS
				WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = %s AND COLUMN_NAME = %s',
				$table,
				'embedding'
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$dims = null;
		if ( is_string( $col_type ) && preg_match( '/vector\((\d+)\)/i', $col_type, $m ) ) {
			$dims = (int) $m[1];
		}

		$this->dims_cached = true;
		$this->dims_cache  = $dims;
		return $dims;
	}

	/**
	 * Insert or replace all chunks for a post atomically.
	 *
	 * Deletes any existing rows for $post_id first so that stale chunks from
	 * a prior version of the post are never left behind.
	 *
	 * @param int    $post_id   Post ID.
	 * @param string $post_type Post type slug.
	 * @param string $hash      Content hash (sha256 of title+body).
	 * @param array  $chunks    Array of chunk data. Each element must have:
	 *                          'chunk_index' (int), 'chunk_text' (string),
	 *                          'vector' (float[]).
	 * @return void
	 */
	public function replace_post_chunks(
		int $post_id,
		string $post_type,
		string $hash,
		array $chunks
	): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mariadb_vector_embeddings';
		$now   = current_time( 'mysql', true );
		$dims  = count( $chunks[0]['vector'] );

		$declared = $this->get_column_dimensions();
		if ( null !== $declared && $declared !== $dims ) {
			wp_mariadb_vector_search_log(
				sprintf(
					'replace_post_chunks(%d): dimension mismatch — embedding is %d-dim but table column is VECTOR(%d). ' .
					'Set dimensions to %d in Settings and reinstall the schema (delete the wp_mariadb_vector_search_db_version option).',
					$post_id,
					$dims,
					$declared,
					$dims
				)
			);
			return;
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$del = $wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE post_id = %d", $post_id ) );
		if ( false === $del ) {
			wp_mariadb_vector_search_log(
				sprintf( 'replace_post_chunks(%d): DELETE failed: %s', $post_id, $wpdb->last_error )
			);
		}

		foreach ( $chunks as $chunk ) {
			$vec_literal = self::format_vector_literal( $chunk['vector'] );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			$ok = $wpdb->query(
				$wpdb->prepare(
					"INSERT INTO `{$table}`
					(post_id, chunk_index, post_type, dimensions, embedding, chunk_text, content_hash, updated_at)
					VALUES (%d, %d, %s, %d, VEC_FromText(%s), %s, %s, %s)",
					$post_id,
					$chunk['chunk_index'],
					$post_type,
					$dims,
					$vec_literal,
					$chunk['chunk_text'],
					$hash,
					$now
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
			if ( false === $ok ) {
				wp_mariadb_vector_search_log(
					sprintf(
						'replace_post_chunks(%d): INSERT chunk %d failed (dims=%d): %s',
						$post_id,
						$chunk['chunk_index'],
						$dims,
						$wpdb->last_error
					)
				);
			}
		}
	}

	/**
	 * Remove all embedding rows for a post.
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public function delete_post( int $post_id ): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mariadb_vector_embeddings';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$table}` WHERE post_id = %d", $post_id ) );
	}

	/**
	 * Return the stored content hash for a post, or null if not indexed.
	 *
	 * @param int $post_id Post ID.
	 * @return string|null
	 */
	public function get_content_hash( int $post_id ): ?string {
		global $wpdb;
		$table = $wpdb->prefix . 'mariadb_vector_embeddings';
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var(
			$wpdb->prepare( "SELECT content_hash FROM `{$table}` WHERE post_id = %d LIMIT 1", $post_id )
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return is_string( $result ) ? $result : null;
	}

	// -----------------------------------------------------------------------
	// Read operations
	// -----------------------------------------------------------------------

	/**
	 * Return the number of distinct posts that have at least one indexed chunk.
	 *
	 * @return int
	 */
	public function count_indexed(): int {
		global $wpdb;
		$table = $wpdb->prefix . 'mariadb_vector_embeddings';
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM `{$table}`" );
	}

	/**
	 * Return post IDs whose embeddings are within a cosine distance threshold.
	 *
	 * Uses a two-step approach so the VECTOR INDEX is used by the inner query
	 * (ORDER BY distance LIMIT overscan), then PHP aggregates per post and
	 * applies the distance threshold filter.
	 *
	 * @param float[]  $query_vector Embedding of the search query.
	 * @param string[] $post_types   Post types to include.
	 * @param float    $max_distance Maximum cosine distance to include (0=identical, ~1=unrelated).
	 *                               Default INF means no threshold filtering.
	 * @param int      $max_results  Safety cap on the number of posts returned and the basis for
	 *                               the inner LIMIT (ensures the VECTOR INDEX is used). Default 200.
	 * @param int      $overscan     Inner LIMIT multiplier (default 5).
	 * @return int[]  Post IDs ordered by ascending cosine distance.
	 */
	public function search_similar(
		array $query_vector,
		array $post_types,
		float $max_distance = INF,
		int $max_results = 200,
		int $overscan = 5
	): array {
		global $wpdb;
		$table = $wpdb->prefix . 'mariadb_vector_embeddings';

		$vec_literal = self::format_vector_literal( $query_vector );
		$inner_limit = $max_results * $overscan;
		$type_list   = implode( ',', array_map( static fn( $t ) => "'" . esc_sql( $t ) . "'", $post_types ) );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, VEC_DISTANCE_COSINE(embedding, VEC_FromText(%s)) AS d
				FROM `{$table}`
				WHERE post_type IN ({$type_list})
				ORDER BY d ASC
				LIMIT %d",
				$vec_literal,
				$inner_limit
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		if ( empty( $rows ) ) {
			return array();
		}

		// Aggregate by post_id keeping the minimum distance (best chunk wins).
		$min_by_post = array();
		foreach ( $rows as $row ) {
			$pid = (int) $row->post_id;
			$d   = (float) $row->d;
			if ( ! isset( $min_by_post[ $pid ] ) || $d < $min_by_post[ $pid ] ) {
				$min_by_post[ $pid ] = $d;
			}
		}

		asort( $min_by_post );

		// Apply distance threshold: exclude posts that are too dissimilar.
		if ( is_finite( $max_distance ) ) {
			$min_by_post = array_filter(
				$min_by_post,
				static fn( float $d ): bool => $d <= $max_distance
			);
		}

		$ids = array_keys( array_slice( $min_by_post, 0, $max_results, true ) );
		return array_map( 'intval', $ids );
	}
}
