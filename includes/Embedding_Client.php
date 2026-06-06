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
 *
 * Third parties that also override the vector result can declare which model was used
 * via the wp_mariadb_vector_search_embed_model filter:
 *
 *   apply_filters( 'wp_mariadb_vector_search_embed_model', string|null $model, string[] $texts )
 */
class Embedding_Client {

	/**
	 * Generate embeddings for a batch of texts.
	 *
	 * @param string[]    $texts Non-empty array of strings to embed.
	 * @param string|null $model Out-parameter: receives the resolved model ID, or null on error.
	 *                           Callers that need the model id should declare a variable and pass it by reference,
	 *                           e.g. $model = null; $client->embed( $texts, $model ).
	 * @return float[][]|\WP_Error Parallel array of float vectors, or WP_Error on failure.
	 */
	public function embed( array $texts, ?string &$model = null ): array|\WP_Error {
		$result = wp_mariadb_vector_search_prompt()
			->generate_embeddings_result( $texts );

		if ( is_wp_error( $result ) ) {
			$default = $result;
		} else {
			$default = $result->getAdditionalData()['embeddings'] ?? array();
			$model   = $result->getModelMetadata()->getId();
		}

		/**
		 * Filter the resolved embedding model identifier.
		 *
		 * Called after SDK model resolution so third-party code that overrides
		 * the embedding vectors (via wp_mariadb_vector_search_embed) can also
		 * declare which model was used.
		 *
		 * @param string|null $model Resolved model id, or null when the SDK returned WP_Error.
		 * @param string[]    $texts The texts that were embedded.
		 */
		$model = apply_filters( 'wp_mariadb_vector_search_embed_model', $model, $texts );

		$filtered = apply_filters( 'wp_mariadb_vector_search_embed', $default, $texts );

		return is_wp_error( $filtered ) ? $filtered : (array) $filtered;
	}
}
