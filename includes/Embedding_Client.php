<?php
/**
 * Embedding client — policy layer over the WordPress AI Connector.
 *
 * @package WP_MariaDB_Vector_Search
 */

declare(strict_types=1);

namespace WP_MariaDB_Vector_Search;

/**
 * Policy layer for obtaining text embeddings.
 *
 * Delegates to wp_mariadb_vector_search_prompt()->generate_embeddings_result(),
 * which iterates the preferred models list and falls back automatically when a
 * provider has no API key configured.
 *
 * Third parties may override the result via the wp_mariadb_vector_search_embed filter:
 *
 *   apply_filters( 'wp_mariadb_vector_search_embed', float[][]|\WP_Error $result, string[] $texts )
 */
class Embedding_Client {

	/**
	 * Generate embeddings for a batch of texts.
	 *
	 * @param string[] $texts Non-empty array of strings to embed.
	 * @return float[][]|\WP_Error Parallel array of float vectors, or WP_Error on failure.
	 */
	public function embed( array $texts ): array|\WP_Error {
		$result = wp_mariadb_vector_search_prompt()
			->generate_embeddings_result( $texts );

		if ( is_wp_error( $result ) ) {
			$default = $result;
		} else {
			$default = $result->getAdditionalData()['embeddings'] ?? array();
		}

		$filtered = apply_filters( 'wp_mariadb_vector_search_embed', $default, $texts );

		return is_wp_error( $filtered ) ? $filtered : (array) $filtered;
	}
}
