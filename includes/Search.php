<?php
/**
 * Search hook — replaces the default WP search with vector similarity.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

/**
 * Hooks into pre_get_posts to rewrite the main search query with vector similarity.
 *
 * Only fires when:
 * - is_main_query() is true, AND
 * - is_search() is true, AND
 * - the 's' parameter is non-empty.
 *
 * On embedding or similarity search failure the query falls through to default WP search.
 * Results are filtered by cosine distance threshold (wp_mariadb_vector_search_max_distance,
 * default 0.65) and by relative distance from the best match
 * (wp_mariadb_vector_search_max_relative_distance, default 0.25) — posts that are too
 * dissimilar to the query, or too far behind the best match, are excluded.
 */
class Search {

	/**
	 * Constructor.
	 *
	 * @param Embedding_Client $client     Embedding provider wrapper.
	 * @param Repository       $repository Embeddings table wrapper.
	 */
	public function __construct(
		private Embedding_Client $client,
		private Repository $repository,
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
	 * Rewrite a search WP_Query to use vector similarity results.
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

		$max_distance = (float) apply_filters( 'wp_mariadb_vector_search_max_distance', 0.65 );
		$max_results  = (int) apply_filters( 'wp_mariadb_vector_search_max_results', 200 );
		$max_relative = (float) apply_filters( 'wp_mariadb_vector_search_max_relative_distance', 0.25 );
		$vector_ids   = $this->repository->search_similar( $result[0], $post_types, $max_distance, $max_results, $max_relative );

		$ids = $vector_ids;

		if ( apply_filters( 'wp_mariadb_vector_search_hybrid', true ) ) {
			$like_limit = (int) apply_filters( 'wp_mariadb_vector_search_like_results', $max_results );
			$like_ids   = $this->get_like_results( $search_term, $post_types, $like_limit );
			$rrf_k      = (int) apply_filters( 'wp_mariadb_vector_search_rrf_k', 60 );
			$ids        = Rank_Fusion::fuse( array( $vector_ids, $like_ids ), $rrf_k );
		}

		if ( empty( $ids ) ) {
			// No matches from vector (and, if enabled, fused LIKE) search.
			// Use a non-existent post ID rather than returning early, so the
			// query does not fall through to WordPress's default LIKE search.
			$ids = array( 0 );
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
	 * Run WordPress's default LIKE-based search and return matching post IDs.
	 *
	 * The resulting WP_Query is not the main query, so this does not
	 * re-trigger {@see rewrite_search_query()}.
	 *
	 * @param string   $search_term Raw search term.
	 * @param string[] $post_types  Post types to include.
	 * @param int      $limit       Maximum number of results.
	 * @return int[] Post IDs ordered by WordPress's default search relevance.
	 */
	private function get_like_results( string $search_term, array $post_types, int $limit ): array {
		$like_query = new \WP_Query(
			array(
				's'              => $search_term,
				'post_type'      => $post_types,
				'posts_per_page' => $limit,
				'fields'         => 'ids',
				'orderby'        => 'relevance',
				'no_found_rows'  => true,
			)
		);

		return array_map( 'intval', $like_query->posts );
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
