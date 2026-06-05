<?php
/**
 * Batched cron-driven backfill for existing posts.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

/**
 * Re-indexes posts that have not yet been embedded (or whose hash changed).
 *
 * Progress is stored in a transient so it survives across cron firings.
 */
class Cron_Backfill {

	const CRON_HOOK     = 'wp_mariadb_vector_search_backfill';
	const PROGRESS_KEY  = 'wp_mariadb_vector_search_backfill_progress';
	const DEFAULT_BATCH = 10;

	/**
	 * @param Indexer $indexer Embedding + storage pipeline.
	 * @param int     $batch   Posts to process per cron firing.
	 */
	public function __construct(
		private Indexer $indexer,
		private int $batch = self::DEFAULT_BATCH,
	) {}

	/**
	 * Register the cron hook.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( self::CRON_HOOK, array( $this, 'run_batch' ) );
	}

	/**
	 * Schedule a full reindex starting from offset 0.
	 *
	 * Clears any in-progress backfill and schedules the first batch.
	 *
	 * @param bool $force Re-index even posts that have not changed.
	 * @return void
	 */
	public function schedule( bool $force = false ): void {
		set_transient(
			self::PROGRESS_KEY,
			array(
				'offset' => 0,
				'total'  => 0,
				'done'   => 0,
				'force'  => $force,
			),
			DAY_IN_SECONDS
		);

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_single_event( time(), self::CRON_HOOK );
		}
	}

	/**
	 * Process one batch of posts and schedule the next batch if any remain.
	 *
	 * @return void
	 */
	public function run_batch(): void {
		$progress = get_transient( self::PROGRESS_KEY );
		if ( ! is_array( $progress ) ) {
			return;
		}

		$offset     = (int) $progress['offset'];
		$force      = (bool) $progress['force'];
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

		$posts = get_posts(
			array(
				'post_type'      => $post_types,
				'post_status'    => 'publish',
				'posts_per_page' => $this->batch,
				'offset'         => $offset,
				'fields'         => 'ids',
				'no_found_rows'  => false,
			)
		);

		if ( 0 === $offset ) {
			$total             = $this->count_posts( $post_types );
			$progress['total'] = $total;
		}

		foreach ( $posts as $post_id ) {
			if ( $force ) {
				$this->indexer->delete_post( $post_id );
			}
			$this->indexer->index_post( $post_id );
			++$progress['done'];
		}

		if ( count( $posts ) < $this->batch ) {
			// All done.
			delete_transient( self::PROGRESS_KEY );
			return;
		}

		$progress['offset'] = $offset + $this->batch;
		set_transient( self::PROGRESS_KEY, $progress, DAY_IN_SECONDS );
		wp_schedule_single_event( time() + 1, self::CRON_HOOK );
	}

	/**
	 * Return the current backfill progress, or null if none is running.
	 *
	 * @return array{offset:int,total:int,done:int,force:bool}|null
	 */
	public function get_progress(): ?array {
		$progress = get_transient( self::PROGRESS_KEY );
		return is_array( $progress ) ? $progress : null;
	}

	/**
	 * Count all publishable posts across the given post types.
	 *
	 * @param string[] $post_types Post type slugs.
	 * @return int
	 */
	private function count_posts( array $post_types ): int {
		$counts = wp_count_posts( reset( $post_types ) );
		$total  = 0;
		foreach ( $post_types as $type ) {
			$c      = wp_count_posts( $type );
			$total += isset( $c->publish ) ? (int) $c->publish : 0;
		}
		return $total;
	}
}
