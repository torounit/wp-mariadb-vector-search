<?php
/**
 * Integration tests for the Indexer class.
 *
 * Requires MariaDB 11.7+ with VECTOR support.
 * The Embedding_Client is stubbed via wp_mariadb_vector_search_embed.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Integration;

use WP_MariaDB_Vector_Search\Embedding_Client;
use WP_MariaDB_Vector_Search\Indexer;
use WP_MariaDB_Vector_Search\Repository;
use WP_MariaDB_Vector_Search\Schema;

/**
 * Class Indexer_Test
 */
class Indexer_Test extends \WP_UnitTestCase {

	private Indexer $indexer;
	private Repository $repository;
	private const DIMS = 4;

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
		$client           = new Embedding_Client();
		$this->indexer    = new Indexer( $client, $this->repository );

		// Register stub provider: returns fixed 4-dim vectors.
		add_filter(
			'wp_mariadb_vector_search_embed',
			static function ( $result, array $texts ) {
				return array_map( static fn() => array( 0.5, 0.5, 0.5, 0.5 ), $texts );
			},
			10,
			2
		);
	}

	public function tear_down(): void {
		remove_all_filters( 'wp_mariadb_vector_search_embed' );
		Schema::drop();
		delete_option( 'wp_mariadb_vector_search_db_version' );
		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );
		parent::tear_down();
	}

	public function test_index_post_stores_chunks(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mariadb_vector_embeddings';

		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Hello',
				'post_content' => 'World content here.',
				'post_status'  => 'publish',
			)
		);

		$this->indexer->index_post( $post_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE post_id = %d", $post_id )
		);
		$this->assertGreaterThan( 0, $count );
	}

	public function test_index_post_skips_unchanged_content(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mariadb_vector_embeddings';

		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Stable',
				'post_content' => 'Stable content.',
				'post_status'  => 'publish',
			)
		);

		$this->indexer->index_post( $post_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$first_updated = $wpdb->get_var(
			$wpdb->prepare( "SELECT updated_at FROM `{$table}` WHERE post_id = %d LIMIT 1", $post_id )
		);

		// A tiny sleep ensures the timestamp would differ if re-indexed.
		sleep( 1 );
		$this->indexer->index_post( $post_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$second_updated = $wpdb->get_var(
			$wpdb->prepare( "SELECT updated_at FROM `{$table}` WHERE post_id = %d LIMIT 1", $post_id )
		);

		$this->assertSame( $first_updated, $second_updated );
	}

	public function test_index_post_reindexes_changed_content(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mariadb_vector_embeddings';

		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Draft',
				'post_content' => 'Old content.',
				'post_status'  => 'publish',
			)
		);

		$this->indexer->index_post( $post_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$old_hash = $wpdb->get_var(
			$wpdb->prepare( "SELECT content_hash FROM `{$table}` WHERE post_id = %d LIMIT 1", $post_id )
		);

		wp_update_post(
			array(
				'ID'           => $post_id,
				'post_content' => 'New content that has changed.',
			)
		);

		$this->indexer->index_post( $post_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$new_hash = $wpdb->get_var(
			$wpdb->prepare( "SELECT content_hash FROM `{$table}` WHERE post_id = %d LIMIT 1", $post_id )
		);

		$this->assertNotSame( $old_hash, $new_hash );
	}

	public function test_delete_post_removes_rows(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mariadb_vector_embeddings';

		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'To Delete',
				'post_content' => 'Some content.',
				'post_status'  => 'publish',
			)
		);

		$this->indexer->index_post( $post_id );
		$this->indexer->delete_post( $post_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE post_id = %d", $post_id )
		);
		$this->assertSame( 0, $count );
	}

	public function test_index_post_skips_non_published(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mariadb_vector_embeddings';

		$post_id = $this->factory->post->create(
			array(
				'post_title'   => 'Draft post',
				'post_content' => 'Draft content.',
				'post_status'  => 'draft',
			)
		);

		$this->indexer->index_post( $post_id );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$count = (int) $wpdb->get_var(
			$wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE post_id = %d", $post_id )
		);
		$this->assertSame( 0, $count );
	}
}
