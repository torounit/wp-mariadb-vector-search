<?php
/**
 * WP-CLI command for manual reindexing.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

/**
 * WP-CLI commands for WP MariaDB Vector Search.
 *
 * @package WP_MariaDB_Vector_Search
 */
class CLI {

	/**
	 * Constructor.
	 *
	 * @param Indexer $indexer Embedding + storage pipeline.
	 */
	public function __construct( private Indexer $indexer ) {}

	/**
	 * Reindex all (or specific) posts.
	 *
	 * ## OPTIONS
	 *
	 * [--post-type=<type>]
	 * : Limit to a specific post type. Defaults to all public searchable types.
	 *
	 * [--force]
	 * : Re-embed even posts whose content has not changed.
	 *
	 * [--batch=<n>]
	 * : Number of posts to process at once (default: 50).
	 *
	 * ## EXAMPLES
	 *
	 *     wp mariadb-vector reindex
	 *     wp mariadb-vector reindex --post-type=post --force
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @return void
	 */
	public function reindex( array $args, array $assoc_args ): void {
		$force     = \WP_CLI\Utils\get_flag_value( $assoc_args, 'force', false );
		$batch     = (int) \WP_CLI\Utils\get_flag_value( $assoc_args, 'batch', 50 );
		$post_type = \WP_CLI\Utils\get_flag_value( $assoc_args, 'post-type', null );

		if ( $post_type ) {
			$post_types = array( $post_type );
		} else {
			$post_types = apply_filters(
				'wp_mariadb_vector_search_post_types',
				array_keys(
					get_post_types(
						array(
							'public'              => true,
							'exclude_from_search' => false,
						)
					)
				)
			);
		}

		$count  = 0;
		$offset = 0;
		$total  = $this->count_posts( $post_types );

		\WP_CLI::log( sprintf( 'Reindexing %d posts…', $total ) );
		$progress = \WP_CLI\Utils\make_progress_bar( 'Indexing', $total );

		do {
			$ids = get_posts(
				array(
					'post_type'      => $post_types,
					'post_status'    => 'publish',
					'posts_per_page' => $batch,
					'offset'         => $offset,
					'fields'         => 'ids',
					'no_found_rows'  => true,
				)
			);

			$fetched = count( $ids );

			foreach ( $ids as $post_id ) {
				if ( $force ) {
					$this->indexer->delete_post( $post_id );
				}
				$this->indexer->index_post( $post_id );
				$progress->tick();
				++$count;
			}

			$offset += $batch;
		} while ( $batch === $fetched );

		$progress->finish();
		\WP_CLI::success( sprintf( 'Done. Processed %d posts.', $count ) );
	}

	/**
	 * Count published posts across the given post types.
	 *
	 * @param string[] $post_types Post type slugs.
	 * @return int
	 */
	private function count_posts( array $post_types ): int {
		$total = 0;
		foreach ( $post_types as $type ) {
			$c      = wp_count_posts( $type );
			$total += isset( $c->publish ) ? (int) $c->publish : 0;
		}
		return $total;
	}
}
