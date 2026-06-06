<?php
/**
 * Integration tests for the Search class.
 *
 * Requires MariaDB 11.7+ with VECTOR support.
 * Embedding_Client is stubbed via anonymous subclass (no real HTTP calls).
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search\Tests\Integration;

use WP_MariaDB_Vector_Search\Embedding_Client;
use WP_MariaDB_Vector_Search\Indexer;
use WP_MariaDB_Vector_Search\Repository;
use WP_MariaDB_Vector_Search\Schema;
use WP_MariaDB_Vector_Search\Search;

/**
 * Class Search_Test
 */
class Search_Test extends \WP_UnitTestCase {

	/**
	 * Repository instance.
	 *
	 * @var Repository
	 */
	private Repository $repository;

	/**
	 * Search instance under test.
	 *
	 * @var Search
	 */
	private Search $search;

	/**
	 * ID of the first test post.
	 *
	 * @var int
	 */
	private int $post_a;

	/**
	 * ID of the second test post.
	 *
	 * @var int
	 */
	private int $post_b;

	/**
	 * Number of vector dimensions used in this test suite.
	 */
	private const DIMS = 4;

	/**
	 * Saved main WP_Query reference for restoration.
	 *
	 * @var \WP_Query|null
	 */
	private $original_main_query;

	/**
	 * Build a stub Embedding_Client that returns fixed 4-dim vectors without HTTP calls.
	 *
	 * @return Embedding_Client
	 */
	private function make_stub_client(): Embedding_Client {
		return new class() extends Embedding_Client {
			/**
			 * Return fixed 4-dimensional vectors.
			 *
			 * @param string[] $texts Texts to embed.
			 * @return float[][]|\WP_Error
			 */
			public function embed( array $texts ): array|\WP_Error {
				return array_map( static fn() => array( 1.0, 0.0, 0.0, 0.0 ), $texts );
			}
		};
	}

	/**
	 * Build an Embedding_Client stub that always returns WP_Error.
	 *
	 * @return Embedding_Client
	 */
	private function make_error_client(): Embedding_Client {
		return new class() extends Embedding_Client {
			/**
			 * Always return WP_Error.
			 *
			 * @param string[] $texts Texts to embed.
			 * @return float[][]|\WP_Error
			 */
			public function embed( array $texts ): array|\WP_Error {
				return new \WP_Error( 'api_error', 'Offline.' );
			}
		};
	}

	/**
	 * Set up test fixtures.
	 */
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

		// Create and index two posts with the stub client.
		$stub_client = $this->make_stub_client();
		$indexer     = new Indexer( $stub_client, $this->repository );

		$this->post_a = $this->factory->post->create(
			array(
				'post_title'   => 'Cats',
				'post_content' => 'About cats.',
				'post_status'  => 'publish',
			)
		);
		$this->post_b = $this->factory->post->create(
			array(
				'post_title'   => 'Dogs',
				'post_content' => 'About dogs.',
				'post_status'  => 'publish',
			)
		);

		$indexer->index_post( $this->post_a );
		$indexer->index_post( $this->post_b );

		$this->search = new Search( $this->make_stub_client(), $this->repository );
		$this->search->register_hooks();
	}

	/**
	 * Tear down test fixtures.
	 */
	public function tear_down(): void {
		remove_all_filters( 'pre_get_posts' );
		remove_all_filters( 'posts_search' );
		remove_all_actions( 'the_posts' );

		$this->restore_main_query();

		// Delete posts before dropping schema so delete_post hooks don't error.
		wp_delete_post( $this->post_a, true );
		wp_delete_post( $this->post_b, true );

		Schema::drop();
		delete_option( 'wp_mariadb_vector_search_db_version' );

		add_filter( 'query', array( $this, '_create_temporary_tables' ) );
		add_filter( 'query', array( $this, '_drop_temporary_tables' ) );

		parent::tear_down();
	}

	// -----------------------------------------------------------------------
	// Helpers
	// -----------------------------------------------------------------------

	/**
	 * Make $query act as the WordPress main query so is_main_query() returns true.
	 *
	 * WP_Query::is_main_query() checks $wp_the_query === $this, so we replace
	 * the global temporarily.
	 *
	 * @param \WP_Query $query Query to promote to main query.
	 * @return \WP_Query The promoted query (for chaining).
	 */
	private function as_main_query( \WP_Query $query ): \WP_Query {
		global $wp_the_query;
		$this->original_main_query = $wp_the_query;
		$wp_the_query              = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
		return $query;
	}

	/**
	 * Restore the original main query.
	 *
	 * @return void
	 */
	private function restore_main_query(): void {
		if ( isset( $this->original_main_query ) ) {
			global $wp_the_query;
			$wp_the_query              = $this->original_main_query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited
			$this->original_main_query = null;
		}
	}

	// -----------------------------------------------------------------------
	// Tests
	// -----------------------------------------------------------------------

	/** Vector search returns at least one post. */
	public function test_search_returns_indexed_posts(): void {
		$query = new \WP_Query(
			array(
				's'              => 'cats',
				'post_type'      => 'post',
				'posts_per_page' => 10,
				'fields'         => 'ids',
			)
		);

		$this->as_main_query( $query );
		$query->get_posts();
		$this->restore_main_query();

		$this->assertNotEmpty( $query->posts );
	}

	/** Both indexed posts appear when all stub vectors are identical. */
	public function test_search_result_contains_both_posts(): void {
		// Both posts have identical stub vectors, so both appear in results.
		$query = new \WP_Query(
			array(
				's'              => 'animals',
				'post_type'      => 'post',
				'posts_per_page' => 10,
				'fields'         => 'ids',
			)
		);

		$this->as_main_query( $query );
		$query->get_posts();
		$this->restore_main_query();

		// posts contain raw IDs when fields='ids'.
		$found_ids = array_map( 'intval', $query->posts );
		$this->assertContains( $this->post_a, $found_ids );
		$this->assertContains( $this->post_b, $found_ids );
	}

	/** Search falls back gracefully when the embedding provider returns an error. */
	public function test_search_falls_back_on_provider_error(): void {
		// Replace the registered search hooks with an error-returning client.
		remove_all_filters( 'pre_get_posts' );
		remove_all_filters( 'posts_search' );
		remove_all_actions( 'the_posts' );

		$error_search = new Search( $this->make_error_client(), $this->repository );
		$error_search->register_hooks();

		$query = new \WP_Query(
			array(
				's'              => 'cats',
				'post_type'      => 'post',
				'posts_per_page' => 10,
				'fields'         => 'ids',
			)
		);

		$this->as_main_query( $query );
		$query->get_posts();
		$this->restore_main_query();

		// Should not throw. Posts may or may not appear (LIKE fallback).
		$this->assertIsArray( $query->posts );
	}

	/** A non-search query is not modified by the Search class. */
	public function test_non_search_query_is_not_modified(): void {
		$query = new \WP_Query(
			array(
				'post_type'      => 'post',
				'posts_per_page' => 10,
			)
		);

		$this->as_main_query( $query );
		$query->get_posts();
		$this->restore_main_query();

		// post__in should NOT be set by the Search class (no 's' param).
		$this->assertEmpty( $query->get( 'post__in' ) );
	}
}
