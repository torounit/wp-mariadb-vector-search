<?php
/**
 * Search hook — replaces the default WP search with vector similarity.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

/**
 * Hooks into pre_get_posts to rewrite the main search query with vector KNN.
 *
 * Only fires when:
 * - is_main_query() is true, AND
 * - is_search() is true, AND
 * - the 's' parameter is non-empty.
 *
 * On embedding or KNN failure the query falls through to default WP search.
 */
class Search {

	/**
	 * Constructor.
	 *
	 * @param Embedding_Client $client     Embedding provider wrapper.
	 * @param Repository       $repository Embeddings table wrapper.
	 * @param int              $top_k      Number of posts to return.
	 */
	public function __construct(
		private Embedding_Client $client,
		private Repository $repository,
		private int $top_k = 20,
	) {}

	/**
	 * Attach the pre_get_posts hook.
	 *
	 * @return void
	 */
	public function register_hooks(): void {
		add_action( 'pre_get_posts', array( $this, 'rewrite_search_query' ) );
	}

	/**
	 * Rewrite a search WP_Query to use vector KNN results.
	 *
	 * @param \WP_Query $query The query object, modified in place.
	 * @return void
	 */
	public function rewrite_search_query( \WP_Query $query ): void {
		if ( ! $query->is_main_query() || ! $query->is_search() ) {
			return;
		}

		$search_term = $query->get( 's' );
		if ( empty( $search_term ) ) {
			return;
		}

		$post_types = $this->get_post_types( $query );
		$result     = $this->client->embed( array( $search_term ) );

		if ( is_wp_error( $result ) || empty( $result[0] ) ) {
			return;
		}

		$ids = $this->repository->knn( $result[0], $this->top_k, $post_types );

		if ( empty( $ids ) ) {
			return;
		}

		$query->set( 'post__in', $ids );
		$query->set( 'orderby', 'post__in' );

		// Suppress the default LIKE-based search clause.
		add_filter( 'posts_search', '__return_empty_string', 99 );
		add_action( 'the_posts', array( $this, 'remove_search_override' ) );
	}

	/**
	 * Clean up the one-shot posts_search suppression after the query runs.
	 *
	 * @param array $posts Posts returned by the query.
	 * @return array Unchanged posts.
	 */
	public function remove_search_override( array $posts ): array {
		remove_filter( 'posts_search', '__return_empty_string', 99 );
		remove_action( 'the_posts', array( $this, 'remove_search_override' ) );
		return $posts;
	}

	/**
	 * Determine the post types to search.
	 *
	 * Uses the query's post_type parameter when set; otherwise falls back to
	 * all public, searchable post types filtered by
	 * wp_mariadb_vector_search_post_types.
	 *
	 * @param \WP_Query $query The query.
	 * @return string[] Post type slugs.
	 */
	private function get_post_types( \WP_Query $query ): array {
		$from_query = $query->get( 'post_type' );

		if ( ! empty( $from_query ) && 'any' !== $from_query ) {
			return (array) $from_query;
		}

		$types = array_keys(
			get_post_types(
				array(
					'public'              => true,
					'exclude_from_search' => false,
				)
			)
		);

		$types = apply_filters( 'wp_mariadb_vector_search_post_types', $types );
		return $types;
	}
}
