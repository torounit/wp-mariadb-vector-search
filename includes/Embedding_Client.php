<?php
/**
 * Embedding client — thin wrapper over the WP AI Connector.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

/**
 * Generates text embeddings through the WordPress AI Connector (WP 7.0+).
 *
 * All AI calls in this plugin go through this class. The filter
 * `wp_mariadb_vector_search_embed` allows third parties (or tests) to
 * substitute a different backend.
 *
 * Filter signature:
 *   apply_filters( 'wp_mariadb_vector_search_embed', float[][]|\WP_Error $result, string[] $texts )
 *
 * A registered callback receives ($result, $texts) where $result is
 * initialized to a \WP_Error('no_provider'). Return a float[][] on success
 * or a \WP_Error on failure.
 */
class Embedding_Client {

	/**
	 * Generate embeddings for a batch of texts.
	 *
	 * @param string[] $texts Non-empty array of strings to embed.
	 * @return float[][]|\WP_Error Parallel array of float vectors, or WP_Error on failure.
	 */
	public function embed( array $texts ): array|\WP_Error {
		$default = new \WP_Error(
			'no_provider',
			__( 'No embedding provider is configured. Please set up the WordPress AI Connector.', 'wp-mariadb-vector-search' )
		);

		$result = apply_filters( 'wp_mariadb_vector_search_embed', $default, $texts );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return (array) $result;
	}
}
