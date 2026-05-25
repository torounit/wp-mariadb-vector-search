<?php
/**
 * Integration tests for Cron_Backfill.
 *
 * Requires MariaDB 11.7+ with VECTOR support.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Integration;

use WP_MariaDB_Vector_Search\Cron_Backfill;
use WP_MariaDB_Vector_Search\Embedding_Client;
use WP_MariaDB_Vector_Search\Indexer;
use WP_MariaDB_Vector_Search\Repository;
use WP_MariaDB_Vector_Search\Schema;

/**
 * Class Cron_Backfill_Test
 */
class Cron_Backfill_Test extends \WP_UnitTestCase {

	private Cron_Backfill $backfill;
	private Repository    $repository;
	private const DIMS    = 4;

	public function set_up(): void {
		parent::set_up();

		if ( ! Schema::is_vector_supported() ) {
			$this->markTestSkipped( 'MariaDB 11.7+ with VECTOR support is required.' );
		}

		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Schema::drop();
		delete_option( 'wp_mariadb_vector_search_db_version' );
		Schema::install( self::DIMS );

		$this->repository = new Repository();

		add_filter(
			'wp_mariadb_vector_search_embed',
			static function ( $result, array $texts ) {
				return array_map( static fn() => [ 0.5, 0.5, 0.5, 0.5 ], $texts );
			},
			10,
			2
		);

		$indexer        = new Indexer( new Embedding_Client(), $this->repository );
		$this->backfill = new Cron_Backfill( $indexer, 2 );
	}

	public function tear_down(): void {
		remove_all_filters( 'wp_mariadb_vector_search_embed' );
		delete_transient( Cron_Backfill::PROGRESS_KEY );
		Schema::drop();
		delete_option( 'wp_mariadb_vector_search_db_version' );
		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		parent::tear_down();
	}

	public function test_schedule_sets_progress_transient(): void {
		$this->backfill->schedule();

		$progress = get_transient( Cron_Backfill::PROGRESS_KEY );
		$this->assertIsArray( $progress );
		$this->assertSame( 0, $progress['offset'] );
	}

	public function test_run_batch_indexes_posts(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mariadb_vector_embeddings';

		$post_id = $this->factory->post->create(
			array( 'post_title' => 'B', 'post_content' => 'Body.', 'post_status' => 'publish' )
		);

		$this->backfill->schedule();
		$this->backfill->run_batch();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE post_id = %d", $post_id )
		);
		$this->assertGreaterThan( 0, $count );
	}

	public function test_run_batch_with_force_reindexes(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mariadb_vector_embeddings';

		$post_id = $this->factory->post->create(
			array( 'post_title' => 'B', 'post_content' => 'Body.', 'post_status' => 'publish' )
		);

		// First index.
		$this->backfill->schedule();
		$this->backfill->run_batch();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hash1 = $wpdb->get_var(
			$wpdb->prepare( "SELECT content_hash FROM `{$table}` WHERE post_id = %d LIMIT 1", $post_id )
		);

		// Force reindex clears old rows and re-embeds.
		$this->backfill->schedule( true );
		$this->backfill->run_batch();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$hash2 = $wpdb->get_var(
			$wpdb->prepare( "SELECT content_hash FROM `{$table}` WHERE post_id = %d LIMIT 1", $post_id )
		);

		// Content didn't change so hash is same, but it was re-embedded.
		$this->assertSame( $hash1, $hash2 );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE post_id = %d", $post_id )
		);
		$this->assertGreaterThan( 0, $count );
	}

	public function test_get_progress_returns_null_when_idle(): void {
		$this->assertNull( $this->backfill->get_progress() );
	}

	public function test_get_progress_returns_array_when_running(): void {
		$this->backfill->schedule();
		$progress = $this->backfill->get_progress();

		$this->assertIsArray( $progress );
		$this->assertArrayHasKey( 'offset', $progress );
		$this->assertArrayHasKey( 'done', $progress );
	}
}
