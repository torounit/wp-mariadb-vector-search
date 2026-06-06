<?php
/**
 * Integration tests for the Repository class.
 *
 * Requires MariaDB 11.7+ with VECTOR support.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Integration;

use WP_MariaDB_Vector_Search\Repository;
use WP_MariaDB_Vector_Search\Schema;

/**
 * Class Repository_Test
 */
class Repository_Test extends \WP_UnitTestCase {

	/**
	 * Repository instance under test.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Number of vector dimensions used in this test suite.
	 */
	private const DIMS = 4;

	/**
	 * Set up test fixtures.
	 */
	public function set_up(): void {
		parent::set_up();

		if ( ! Schema::is_vector_supported() ) {
			$this->markTestSkipped( 'MariaDB 11.7+ with VECTOR support is required.' );
		}

		// Real tables required (VECTOR INDEX cannot be TEMPORARY).
		remove_filter( 'query', array( $this, '_create_temporary_tables' ) );
		remove_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		Schema::drop();
		delete_option( 'wp_mariadb_vector_search_db_version' );
		Schema::install( self::DIMS );

		$this->repository = new Repository();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		Schema::drop();
		delete_option( 'wp_mariadb_vector_search_db_version' );

		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		parent::tear_down();
	}

	// -----------------------------------------------------------------------
	// replace_post_chunks
	// -----------------------------------------------------------------------

	/** Chunks are stored after replace_post_chunks(). */
	public function test_replace_post_chunks_inserts_rows(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mariadb_vector_embeddings';

		$this->repository->replace_post_chunks(
			1,
			'post',
			'hash1',
			array(
				array(
					'chunk_index' => 0,
					'chunk_text'  => 'Hello',
					'vector'      => array( 1.0, 0.0, 0.0, 0.0 ),
				),
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE post_id = 1" );
		$this->assertSame( 1, $count );
	}

	/** Second call replaces existing rows for the same post. */
	public function test_replace_post_chunks_replaces_existing(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mariadb_vector_embeddings';

		$chunk = array(
			'chunk_index' => 0,
			'chunk_text'  => 'Hello',
			'vector'      => array( 1.0, 0.0, 0.0, 0.0 ),
		);
		$this->repository->replace_post_chunks( 1, 'post', 'hash1', array( $chunk ) );
		$this->repository->replace_post_chunks( 1, 'post', 'hash2', array( $chunk ) );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE post_id = 1" );
		$this->assertSame( 1, $count );
	}

	/** Content hash is stored alongside the chunk. */
	public function test_replace_post_chunks_stores_content_hash(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mariadb_vector_embeddings';

		$this->repository->replace_post_chunks(
			42,
			'post',
			'abc123',
			array(
				array(
					'chunk_index' => 0,
					'chunk_text'  => 'Text',
					'vector'      => array( 0.5, 0.5, 0.5, 0.5 ),
				),
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$hash = $wpdb->get_var( "SELECT content_hash FROM `{$table}` WHERE post_id = 42" );
		$this->assertSame( 'abc123', $hash );
	}

	// -----------------------------------------------------------------------
	// delete_post
	// -----------------------------------------------------------------------

	/** All chunks are removed by delete_post(). */
	public function test_delete_post_removes_all_chunks(): void {
		global $wpdb;
		$table = $wpdb->prefix . 'mariadb_vector_embeddings';

		$this->repository->replace_post_chunks(
			5,
			'post',
			'h',
			array(
				array(
					'chunk_index' => 0,
					'chunk_text'  => 'A',
					'vector'      => array( 1.0, 0.0, 0.0, 0.0 ),
				),
				array(
					'chunk_index' => 1,
					'chunk_text'  => 'B',
					'vector'      => array( 0.0, 1.0, 0.0, 0.0 ),
				),
			)
		);

		$this->repository->delete_post( 5 );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var( "SELECT COUNT(*) FROM `{$table}` WHERE post_id = 5" );
		$this->assertSame( 0, $count );
	}

	// -----------------------------------------------------------------------
	// get_content_hash
	// -----------------------------------------------------------------------

	/** Stored hash is returned for an indexed post. */
	public function test_get_content_hash_returns_stored_hash(): void {
		$this->repository->replace_post_chunks(
			7,
			'post',
			'myhash',
			array(
				array(
					'chunk_index' => 0,
					'chunk_text'  => 'T',
					'vector'      => array( 1.0, 0.0, 0.0, 0.0 ),
				),
			)
		);

		$this->assertSame( 'myhash', $this->repository->get_content_hash( 7 ) );
	}

	/** Null is returned for a post that has not been indexed. */
	public function test_get_content_hash_returns_null_when_not_indexed(): void {
		$this->assertNull( $this->repository->get_content_hash( 999 ) );
	}

	// -----------------------------------------------------------------------
	// knn
	// -----------------------------------------------------------------------

	/** Posts are ordered by ascending cosine distance. */
	public function test_knn_returns_posts_ordered_by_distance(): void {
		// Post 1: close to query [1,0,0,0].
		$this->repository->replace_post_chunks(
			1,
			'post',
			'h1',
			array(
				array(
					'chunk_index' => 0,
					'chunk_text'  => 'A',
					'vector'      => array( 1.0, 0.0, 0.0, 0.0 ),
				),
			)
		);
		// Post 2: far from query.
		$this->repository->replace_post_chunks(
			2,
			'post',
			'h2',
			array(
				array(
					'chunk_index' => 0,
					'chunk_text'  => 'B',
					'vector'      => array( 0.0, 1.0, 0.0, 0.0 ),
				),
			)
		);

		$ids = $this->repository->knn( array( 1.0, 0.0, 0.0, 0.0 ), 5, array( 'post' ) );

		$this->assertSame( array( 1, 2 ), $ids );
	}

	/** At most k results are returned. */
	public function test_knn_respects_top_k(): void {
		for ( $i = 1; $i <= 5; $i++ ) {
			$vec    = array_fill( 0, self::DIMS, 0.0 );
			$vec[0] = (float) $i / 5;
			$this->repository->replace_post_chunks(
				$i,
				'post',
				"h{$i}",
				array(
					array(
						'chunk_index' => 0,
						'chunk_text'  => "T{$i}",
						'vector'      => $vec,
					),
				)
			);
		}

		$ids = $this->repository->knn( array( 1.0, 0.0, 0.0, 0.0 ), 2, array( 'post' ) );
		$this->assertCount( 2, $ids );
	}

	/** Only posts of the requested post type are returned. */
	public function test_knn_filters_by_post_type(): void {
		$this->repository->replace_post_chunks(
			10,
			'post',
			'h10',
			array(
				array(
					'chunk_index' => 0,
					'chunk_text'  => 'X',
					'vector'      => array( 1.0, 0.0, 0.0, 0.0 ),
				),
			)
		);
		$this->repository->replace_post_chunks(
			11,
			'page',
			'h11',
			array(
				array(
					'chunk_index' => 0,
					'chunk_text'  => 'Y',
					'vector'      => array( 1.0, 0.0, 0.0, 0.0 ),
				),
			)
		);

		$ids = $this->repository->knn( array( 1.0, 0.0, 0.0, 0.0 ), 10, array( 'post' ) );
		$this->assertContains( 10, $ids );
		$this->assertNotContains( 11, $ids );
	}

	/** The closest chunk wins when a post has multiple chunks. */
	public function test_knn_aggregates_multiple_chunks_per_post(): void {
		// Post 1 has two chunks: one far, one close to query.
		$this->repository->replace_post_chunks(
			1,
			'post',
			'h1',
			array(
				array(
					'chunk_index' => 0,
					'chunk_text'  => 'A',
					'vector'      => array( 0.0, 1.0, 0.0, 0.0 ),
				),
				array(
					'chunk_index' => 1,
					'chunk_text'  => 'B',
					'vector'      => array( 1.0, 0.0, 0.0, 0.0 ),
				),
			)
		);
		// Post 2 has one chunk, slightly less close to query.
		$this->repository->replace_post_chunks(
			2,
			'post',
			'h2',
			array(
				array(
					'chunk_index' => 0,
					'chunk_text'  => 'C',
					'vector'      => array( 0.9, 0.1, 0.0, 0.0 ),
				),
			)
		);

		// Query is [1,0,0,0]: post 1 chunk 1 is the exact match, so post 1 should rank first.
		$ids = $this->repository->knn( array( 1.0, 0.0, 0.0, 0.0 ), 5, array( 'post' ) );
		$this->assertSame( 1, $ids[0] );
	}
}
